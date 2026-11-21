<?php
/**
 * Wallet at checkout — opt-in checkbox applies the balance as a negative
 * fee. Debit happens once, when the order is paid (processing/completed),
 * and is credited back once if the order is later cancelled or refunded.
 * Both directions are idempotent via ledger reason+reference.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Checkout
 */
final class VKL_Wallet_Checkout
{
    private const SESSION_KEY = 'vkl_use_wallet';
    private const META_AMOUNT = '_vkl_wallet_amount';

    private VKL_Wallet_Service $wallet;

    private VKL_Config $config;

    public function __construct(VKL_Wallet_Service $wallet, VKL_Config $config)
    {
        $this->wallet = $wallet;
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('woocommerce_review_order_before_payment', [$this, 'render_checkbox']);
        add_action('woocommerce_review_order_before_payment', [$this, 'render_installment_hint'], 20);
        add_action('woocommerce_checkout_update_order_review', [$this, 'capture_choice']);
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_fee'], 20);
        add_action('woocommerce_checkout_create_order', [$this, 'store_meta'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'settle'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'settle'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'refund'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'refund'], 10, 1);
        add_action('wp_footer', [$this, 'inline_js']);
    }

    /**
     * The cart figure the minimum-order condition is tested against.
     *
     * Deliberately NOT get_total(): inside woocommerce_cart_calculate_fees
     * the totals are mid-calculation, so get_total() returns the PREVIOUS
     * value (which is why the promo did not react to cart changes), and it
     * already contains our own negative wallet fee, which would make the
     * test circular. Line items + shipping is stable and discount-free.
     */
    private function cart_basis(?WC_Cart $cart = null): float
    {
        $cart = $cart instanceof WC_Cart ? $cart : (function_exists('WC') && WC()->cart ? WC()->cart : null);

        if (!$cart instanceof WC_Cart) {
            return 0.0;
        }

        return (float) $cart->get_cart_contents_total() + (float) $cart->get_shipping_total();
    }

    /**
     * Explain why instalment purchase is missing, when the customer has a
     * plan or credit but the option is not offered.
     *
     * Silence here is the worst outcome: the customer knows they have
     * credit, sees no option, and assumes the shop is broken.
     */
    public function render_installment_hint(): void
    {
        if (!is_user_logged_in() || !class_exists('WC_Payment_Gateways')) {
            return;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $gateway  = $gateways['vkl_installment'] ?? null;

        if (!is_object($gateway) || !method_exists($gateway, 'unavailable_reason')) {
            return;
        }

        $reason = (string) $gateway->unavailable_reason();

        // Available, or switched off shop-wide: nothing worth saying.
        if ('' === $reason || !$gateway->is_available() && 'yes' !== $gateway->enabled) {
            return;
        }

        try {
            $installments = vkl('wallet.installments');
            $has_history  = $installments->available_credit(get_current_user_id()) > 0
                || (method_exists($installments, 'has_overdue') && $installments->has_overdue(get_current_user_id()));
        } catch (Throwable $e) {
            return;
        }

        // Only speak to customers this actually concerns.
        if (!$has_history) {
            return;
        }
        ?>
        <div style="margin:0 0 12px;padding:10px 14px;background:#fff8e6;border:1px solid #f0dca8;border-radius:8px;color:#7a5b12;font-size:13.5px">
            ℹ️ <?php echo esc_html($reason); ?>
        </div>
        <?php
    }

    /** Wallet box above payment methods. */
    public function render_checkbox(): void
    {
        if (!$this->config->get_bool('wallet_checkout_enabled', true) || !is_user_logged_in()) {
            return;
        }

        // A wallet top-up cannot be paid with the wallet itself.
        // Repaying an instalment with wallet or festival credit would only
        // shuffle the debt around instead of clearing it.
        if (class_exists('VKL_Wallet_Installments') && VKL_Wallet_Installments::cart_is_installment()) {
            return;
        }

        if (class_exists('VKL_Wallet_Topup') && VKL_Wallet_Topup::cart_has_topup(WC()->cart)) {
            return;
        }

        $user_id = get_current_user_id();
        $promos  = vkl('wallet.promos');
        $basis   = $this->cart_basis();

        $cash    = (float) $this->wallet->balance($user_id);
        $usable  = (float) $promos->active_balance($user_id, $basis);
        $locked  = $promos->next_unlock($user_id, $basis);
        $balance = $cash + $usable;
        $checked = (bool) WC()->session->get(self::SESSION_KEY, false);
        ?>
        <div class="vkl-wallet-checkout" style="margin-bottom:1em;padding:12px 16px;border:1px solid #d5d5d5;border-radius:4px">
            <strong style="display:block;margin-bottom:6px"><?php esc_html_e('Wallet', 'vereskala-login'); ?></strong>
            <span style="display:block;margin-bottom:6px">
                <?php esc_html_e('Current balance:', 'vereskala-login'); ?>
                <strong><?php echo wp_kses_post(wc_price($balance)); ?></strong>
            </span>

            <?php if ($usable > 0) : ?>
                <span style="display:block;margin-bottom:6px;color:#157f3a;font-size:13px">
                    🎁
                    <?php
                    printf(
                        /* translators: %s: formatted festival credit. */
                        esc_html__('Festival credit applied: %s', 'vereskala-login'),
                        wp_kses_post(wc_price($usable))
                    );
                    ?>
                </span>
            <?php endif; ?>

            <?php if ($locked['amount'] > 0 && $locked['min_order'] > 0) : ?>
                <span style="display:block;margin-bottom:6px;padding:6px 10px;background:#fff8e6;border:1px solid #f0dca8;border-radius:6px;color:#7a5b12;font-size:13px">
                    🔒
                    <?php
                    printf(
                        /* translators: 1: locked credit, 2: required cart total. */
                        esc_html__('You still have %1$s festival credit — it unlocks on a cart of %2$s or more.', 'vereskala-login'),
                        wp_kses_post(wc_price($locked['amount'])),
                        wp_kses_post(wc_price($locked['min_order']))
                    );
                    ?>
                    <br>
                    <small>
                        <?php
                        printf(
                            /* translators: %s: current qualifying cart amount. */
                            esc_html__('Your cart currently counts as %s.', 'vereskala-login'),
                            wp_kses_post(wc_price($basis))
                        );
                        ?>
                    </small>
                </span>
            <?php endif; ?>

            <?php if ($balance > 0) : ?>
                <label>
                    <input type="checkbox" id="vkl_use_wallet" name="vkl_use_wallet" value="1" <?php checked($checked); ?>>
                    <?php
                    printf(
                        /* translators: %s: formatted wallet balance. */
                        esc_html__('Use my wallet balance (%s)', 'vereskala-login'),
                        wp_kses_post(wc_price($balance))
                    );
                    ?>
                </label>
            <?php else : ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url(VKL_Wallet_Account::ENDPOINT)); ?>">
                    <?php esc_html_e('Charge wallet', 'vereskala-login'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Persist the checkbox state during order review AJAX refreshes. */
    public function capture_choice(mixed $posted_data): void
    {
        parse_str((string) $posted_data, $data);

        WC()->session->set(self::SESSION_KEY, !empty($data['vkl_use_wallet']));
    }

    /** Apply the balance as a negative fee. */
    public function apply_fee(WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!is_user_logged_in() || !WC()->session || !WC()->session->get(self::SESSION_KEY, false)) {
            return;
        }

        // A wallet top-up cannot be paid with the wallet itself.
        if (class_exists('VKL_Wallet_Installments') && VKL_Wallet_Installments::cart_is_installment($cart)) {
            return;
        }

        if (class_exists('VKL_Wallet_Topup') && VKL_Wallet_Topup::cart_has_topup($cart)) {
            return;
        }

        $payable = $this->cart_basis($cart);

        $balance = $this->wallet->balance(get_current_user_id())
            + vkl('wallet.promos')->active_balance(get_current_user_id(), $payable);

        if ($balance <= 0) {
            return;
        }

        $use = min($balance, max(0.0, $payable));

        if ($use > 0) {
            $cart->add_fee(__('Wallet', 'vereskala-login'), -$use, false);
        }
    }

    /** Remember the applied amount on the order. */
    public function store_meta(WC_Order $order): void
    {
        foreach ($order->get_fees() as $fee) {
            if ((float) $fee->get_total() < 0 && __('Wallet', 'vereskala-login') === $fee->get_name()) {
                $order->update_meta_data(self::META_AMOUNT, abs((float) $fee->get_total()));
            }
        }

        WC()->session->set(self::SESSION_KEY, false);
    }

    /** Debit the wallet once, when the order is paid. */
    public function settle(mixed $order_id): void
    {
        $order  = wc_get_order((int) $order_id);
        $amount = $order ? (float) $order->get_meta(self::META_AMOUNT) : 0.0;

        if (!$order || $amount <= 0) {
            return;
        }

        $reference = 'order:' . $order->get_id();

        if ($this->wallet->already_processed('order_payment', $reference)) {
            return;
        }

        $promo_used = vkl('wallet.promos')->consume(
            (int) $order->get_customer_id(),
            $amount,
            (float) $order->get_total() + $amount // Pre-wallet order total decides the minimum-purchase condition.
        );

        if ($amount - $promo_used > 0.009) {
            $this->wallet->debit((int) $order->get_customer_id(), $amount - $promo_used, 'order_payment', $reference);
        }

        if ($promo_used > 0) {
            $order->add_order_note(sprintf('Vereskonto: %s festival credit consumed.', wp_strip_all_tags(wc_price($promo_used))));
        }
    }

    /** Credit the wallet back once, if a settled order is reversed. */
    public function refund(mixed $order_id): void
    {
        $order  = wc_get_order((int) $order_id);
        $amount = $order ? (float) $order->get_meta(self::META_AMOUNT) : 0.0;

        if (!$order || $amount <= 0) {
            return;
        }

        $reference = 'order:' . $order->get_id();

        if (!$this->wallet->already_processed('order_payment', $reference)
            || $this->wallet->already_processed('order_refund', $reference)) {
            return;
        }

        $this->wallet->credit((int) $order->get_customer_id(), $amount, 'order_refund', $reference);
    }

    /** Trigger checkout refresh when the checkbox toggles. */
    public function inline_js(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        ?>
        <script>
        jQuery(function ($) {
            $(document.body).on('change', '#vkl_use_wallet', function () {
                $(document.body).trigger('update_checkout');
            });

            // React quickly when the cart changes on the checkout page
            // (themes that render quantity +/- there), so the festival
            // credit and its unlock condition re-evaluate right away.
            var t = null;
            function refresh() {
                clearTimeout(t);
                t = setTimeout(function () {
                    $(document.body).trigger('update_checkout');
                }, 350);
            }

            $(document.body).on(
                'change input',
                '.woocommerce-checkout-review-order input.qty, .woocommerce-checkout input.qty',
                refresh
            );
            $(document.body).on(
                'click',
                '.woocommerce-checkout-review-order .plus, .woocommerce-checkout-review-order .minus, .woocommerce-checkout .plus, .woocommerce-checkout .minus',
                refresh
            );
            $(document.body).on('wc_fragments_refreshed added_to_cart removed_from_cart', refresh);
        });
        </script>
        <?php
    }
}
