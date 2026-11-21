<?php
/**
 * Wallet top-up via the shop's bank gateways.
 *
 * Flow: the customer enters an amount on the My Account wallet page →
 * a hidden, virtual "Wallet top-up" product is added to the cart with
 * that amount as its price → the customer pays through any WooCommerce
 * payment gateway → when the order is paid (processing/completed) the
 * wallet is credited once, idempotently (reason 'topup', reference
 * 'order:{id}'). Cancelled/refunded top-up orders are debited back once
 * (reason 'topup_reversal') as far as the remaining balance allows.
 *
 * Safety rails:
 * - the carrier product is created on demand, virtual, hidden from the
 *   catalog, sold individually, and excluded from search;
 * - a cart containing a top-up cannot itself be paid with the wallet
 *   (VKL_Wallet_Checkout skips the fee) — no self-charging loops;
 * - top-up orders never earn loyalty points (order meta flag checked by
 *   the loyalty module);
 * - amounts are clamped to the admin-defined min/max.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Topup
 */
final class VKL_Wallet_Topup
{
    /**
     * Option holding the carrier product id.
     */
    public const PRODUCT_OPTION = 'vkl_wallet_topup_product_id';

    /**
     * Order meta flag: total top-up amount inside the order.
     */
    public const ORDER_META = '_vkl_wallet_topup';

    /**
     * Cart item key carrying the chosen amount.
     */
    private const ITEM_KEY = 'vkl_topup_amount';

    /**
     * Front-end form nonce.
     */
    private const NONCE = 'vkl_wallet_topup';

    /**
     * Wallet service.
     */
    private VKL_Wallet_Service $wallet;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Logger.
     */
    private VKL_Logger $logger;

    /**
     * @param VKL_Wallet_Service $wallet Wallet service.
     * @param VKL_Config         $config Config.
     * @param VKL_Logger         $logger Core logger.
     */
    public function __construct(VKL_Wallet_Service $wallet, VKL_Config $config, VKL_Logger $logger)
    {
        $this->wallet = $wallet;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('vkl_wallet_account_render', [$this, 'render_form']);
        add_action('template_redirect', [$this, 'handle_form']);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_price'], 20, 1);
        add_filter('woocommerce_add_cart_item_data', [$this, 'strip_foreign_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'flag_order'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'credit'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'credit'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'reverse'], 20, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'reverse'], 20, 1);
    }

    /**
     * Whether top-ups are enabled by the admin.
     */
    public function enabled(): bool
    {
        return $this->config->get_bool('wallet_topup_enabled', false);
    }

    /**
     * Whether the given cart contains a top-up item.
     */
    public static function cart_has_topup(?WC_Cart $cart): bool
    {
        if (null === $cart) {
            return false;
        }

        foreach ($cart->get_cart() as $item) {
            if (isset($item[self::ITEM_KEY])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Top-up form on the wallet account tab.
     */
    public function render_form(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $min = max(1, $this->config->get_int('wallet_topup_min', 1));
        $max = $this->config->get_int('wallet_topup_max', 0);
        ?>
        <h4><?php esc_html_e('Charge wallet', 'vereskala-login'); ?></h4>
        <form method="post" class="vkl-wallet-topup" style="margin-bottom:1.5em">
            <?php wp_nonce_field(self::NONCE, '_vkl_topup_nonce'); ?>
            <p class="form-row">
                <label for="vkl_topup_amount"><?php esc_html_e('Amount', 'vereskala-login'); ?></label>
                <input type="number" class="input-text" id="vkl_topup_amount" name="vkl_topup_amount"
                       min="<?php echo esc_attr((string) $min); ?>"
                       <?php echo $max > 0 ? 'max="' . esc_attr((string) $max) . '"' : ''; ?>
                       step="1" required>
                <span class="description">
                    <?php
                    if ($max > 0) {
                        printf(
                            /* translators: 1: minimum amount, 2: maximum amount. */
                            esc_html__('Between %1$s and %2$s.', 'vereskala-login'),
                            wp_kses_post(wc_price($min)),
                            wp_kses_post(wc_price($max))
                        );
                    } else {
                        printf(
                            /* translators: %s: minimum amount. */
                            esc_html__('Minimum %s.', 'vereskala-login'),
                            wp_kses_post(wc_price($min))
                        );
                    }
                    ?>
                </span>
            </p>
            <button type="submit" class="woocommerce-Button button" name="vkl_topup_submit" value="1">
                <?php esc_html_e('Pay with bank gateway', 'vereskala-login'); ?>
            </button>
        </form>
        <?php
    }

    /**
     * Handle the top-up form: put the carrier product in the cart and go
     * to checkout.
     */
    public function handle_form(): void
    {
        if (!isset($_POST['vkl_topup_submit'], $_POST['_vkl_topup_nonce'])) {
            return;
        }

        if (
            !$this->enabled()
            || !is_user_logged_in()
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_vkl_topup_nonce'])), self::NONCE)
        ) {
            return;
        }

        $amount = absint($_POST['vkl_topup_amount'] ?? 0);
        $min    = max(1, $this->config->get_int('wallet_topup_min', 1));
        $max    = $this->config->get_int('wallet_topup_max', 0);

        if ($amount < $min || ($max > 0 && $amount > $max)) {
            wc_add_notice(__('Please enter an amount within the allowed range.', 'vereskala-login'), 'error');

            return;
        }

        $product_id = $this->ensure_product();

        if ($product_id <= 0) {
            wc_add_notice(__('Wallet top-up is temporarily unavailable.', 'vereskala-login'), 'error');

            return;
        }

        // One top-up per cart: drop any previous top-up item first.
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (isset($item[self::ITEM_KEY])) {
                WC()->cart->remove_cart_item($key);
            }
        }

        $added = WC()->cart->add_to_cart($product_id, 1, 0, [], [self::ITEM_KEY => $amount]);

        if (false === $added || '' === $added) {
            wc_add_notice(__('Wallet top-up is temporarily unavailable.', 'vereskala-login'), 'error');

            return;
        }

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Price the carrier item with the chosen amount.
     *
     * @param WC_Cart $cart Cart being totalled.
     */
    public function apply_price(WC_Cart $cart): void
    {
        foreach ($cart->get_cart() as $item) {
            if (isset($item[self::ITEM_KEY], $item['data']) && $item['data'] instanceof WC_Product) {
                $item['data']->set_price((float) absint($item[self::ITEM_KEY]));
            }
        }
    }

    /**
     * Never accept the top-up amount from foreign add-to-cart requests:
     * only our own handler may set it.
     *
     * @param array<string, mixed> $item_data  Item data.
     * @param int                  $product_id Product being added.
     *
     * @return array<string, mixed>
     */
    public function strip_foreign_item_data(array $item_data, int $product_id): array
    {
        if ($product_id === (int) get_option(self::PRODUCT_OPTION, 0) && isset($item_data[self::ITEM_KEY])) {
            // Set by our handler in this same request? Then a nonce was
            // already verified. Direct shop requests never reach here
            // with the key because the product is hidden; strip anyway
            // unless the verified flag of this request is present.
            if (!isset($_POST['_vkl_topup_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only; the nonce itself was verified in handle_form().
                unset($item_data[self::ITEM_KEY]);
            }
        }

        return $item_data;
    }

    /**
     * Record the top-up total on the order so later status changes (and
     * the loyalty module) can recognise it without cart context.
     *
     * @param WC_Order $order Order being created.
     */
    public function flag_order(WC_Order $order): void
    {
        $total = 0;

        foreach (WC()->cart->get_cart() as $item) {
            if (isset($item[self::ITEM_KEY])) {
                $total += absint($item[self::ITEM_KEY]);
            }
        }

        if ($total > 0) {
            $order->update_meta_data(self::ORDER_META, (string) $total);
        }
    }

    /**
     * Credit the wallet once when a top-up order is paid.
     *
     * @param mixed $order_id Order id.
     */
    public function credit(mixed $order_id): void
    {
        $order  = wc_get_order((int) $order_id);
        $amount = $order ? (float) $order->get_meta(self::ORDER_META) : 0.0;

        if (!$order || $amount <= 0) {
            return;
        }

        $user_id = (int) $order->get_customer_id();

        if ($user_id <= 0) {
            $this->logger->error('Wallet top-up order without customer.', ['order' => (int) $order_id]);

            return;
        }

        $reference = 'order:' . (int) $order_id;

        if ($this->wallet->already_processed('topup', $reference)) {
            return;
        }

        $this->wallet->credit($user_id, $amount, 'topup', $reference);
    }

    /**
     * Debit the wallet back once when a paid top-up order is cancelled
     * or refunded — as far as the remaining balance allows.
     *
     * @param mixed $order_id Order id.
     */
    public function reverse(mixed $order_id): void
    {
        $order  = wc_get_order((int) $order_id);
        $amount = $order ? (float) $order->get_meta(self::ORDER_META) : 0.0;

        if (!$order || $amount <= 0) {
            return;
        }

        $reference = 'order:' . (int) $order_id;

        // Only reverse what was actually credited, exactly once.
        if (!$this->wallet->already_processed('topup', $reference) || $this->wallet->already_processed('topup_reversal', $reference)) {
            return;
        }

        $user_id = (int) $order->get_customer_id();

        if (!$this->wallet->debit($user_id, $amount, 'topup_reversal', $reference)) {
            $this->logger->error(
                'Wallet top-up reversal failed (insufficient balance) — resolve manually from the wallet admin.',
                ['order' => (int) $order_id, 'user' => $user_id]
            );
        }
    }

    /**
     * Get (or lazily create) the hidden virtual carrier product.
     */
    public function ensure_product(): int
    {
        $product_id = (int) get_option(self::PRODUCT_OPTION, 0);

        if ($product_id > 0) {
            $product = wc_get_product($product_id);

            if ($product && 'trash' !== $product->get_status()) {
                return $product_id;
            }
        }

        $product = new WC_Product_Simple();
        $product->set_name(__('Wallet top-up', 'vereskala-login'));
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);
        $product->set_sold_individually(true);
        $product->set_price('0');
        $product->set_regular_price('0');
        $product->set_reviews_allowed(false);

        $product_id = $product->save();

        if ($product_id > 0) {
            update_option(self::PRODUCT_OPTION, $product_id, false);
        }

        return $product_id;
    }
}
