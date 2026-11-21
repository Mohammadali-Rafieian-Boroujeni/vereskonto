<?php
/**
 * Wallet My Account tab — balance and recent transactions.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Account
 */
final class VKL_Wallet_Account
{
    public const ENDPOINT = 'wallet';

    /**
     * Slug used before 1.6.0; kept registered so old links redirect
     * instead of 404ing.
     */
    public const LEGACY_ENDPOINT = 'vkl-wallet';

    /**
     * Separate account tab for purchase credit & installments.
     */
    public const CREDIT_ENDPOINT = 'credit';

    private VKL_Wallet_Service $wallet;

    private VKL_Wallet_Repository $repository;

    public function __construct(VKL_Wallet_Service $wallet, VKL_Wallet_Repository $repository)
    {
        $this->wallet     = $wallet;
        $this->repository = $repository;
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
            add_rewrite_endpoint(self::LEGACY_ENDPOINT, EP_ROOT | EP_PAGES);
            add_rewrite_endpoint(self::CREDIT_ENDPOINT, EP_ROOT | EP_PAGES);

            // The new endpoint needs the rewrite rules refreshed once.
            if ('1' !== get_option('vkl_flush_127')) {
                update_option('vkl_flush_127', '1', false);
                flush_rewrite_rules(false);
            }
        });
        add_filter('woocommerce_account_menu_items', [$this, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render']);
        add_action('woocommerce_account_' . self::CREDIT_ENDPOINT . '_endpoint', [$this, 'render_credit']);
        add_filter('woocommerce_get_query_vars', [$this, 'query_var']);
        add_action('template_redirect', [$this, 'redirect_legacy']);
    }

    /** Permanent redirect from the pre-1.6.0 slug to the clean one. */
    public function redirect_legacy(): void
    {
        global $wp_query;

        if (isset($wp_query->query_vars[self::LEGACY_ENDPOINT])) {
            wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT), 301);
            exit;
        }
    }

    /** @param array<string, string> $vars */
    public function query_var(array $vars): array
    {
        $vars[self::ENDPOINT]        = self::ENDPOINT;
        $vars[self::LEGACY_ENDPOINT] = self::LEGACY_ENDPOINT;
        $vars[self::CREDIT_ENDPOINT] = self::CREDIT_ENDPOINT;

        return $vars;
    }

    /** @param array<string, string> $items */
    public function menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT]        = __('Wallet', 'vereskala-login');
        $items[self::CREDIT_ENDPOINT] = __('Purchase credit & installments', 'vereskala-login');

        if (null !== $logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /** Reason labels for the transaction list. */
    private function reason_label(string $reason): string
    {
        $map = [
            'order_payment'      => __('Order payment', 'vereskala-login'),
            'order_refund'       => __('Order refund', 'vereskala-login'),
            'admin_credit'       => __('Credited by shop', 'vereskala-login'),
            'admin_debit'        => __('Adjusted by shop', 'vereskala-login'),
            'loyalty_conversion' => __('Loyalty points conversion', 'vereskala-login'),
            'topup'              => __('Wallet top-up', 'vereskala-login'),
            'topup_reversal'     => __('Top-up reversal', 'vereskala-login'),
        ];

        return $map[$reason] ?? $reason;
    }

    /**
     * Purchase credit & installments tab.
     */
    public function render_credit(): void
    {
        $user_id = get_current_user_id();
        ?>
        <h3 style="margin-top:0">🛒 <?php esc_html_e('Purchase credit & installments', 'vereskala-login'); ?></h3>
        <?php
        /**
         * Fires on the credit account tab.
         *
         * @param int $user_id Current user.
         */
        do_action('vkl_wallet_account_render_credit', $user_id);
    }

    public function render(): void
    {
        $user_id = get_current_user_id();

        $promo_balance = vkl('wallet.promos')->active_balance($user_id);
        $promo_expiry  = $promo_balance > 0 ? vkl('wallet.promos')->next_expiry($user_id) : '';
        ?>

        <div class="vkl-hero" style="background:linear-gradient(135deg,#157f3a,#0e5f29);color:#fff;border-radius:14px;padding:20px 24px;margin-bottom:16px;box-shadow:0 4px 16px rgba(30,111,92,.25)">
            <div style="opacity:.85;font-size:.9em">💳 <?php esc_html_e('Wallet', 'vereskala-login'); ?> · <?php esc_html_e('Current balance:', 'vereskala-login'); ?></div>
            <div style="font-size:1.9em;font-weight:800;margin-top:4px"><?php echo wp_kses_post(wc_price($this->wallet->balance($user_id))); ?></div>
            <?php if ($promo_balance > 0) : ?>
                <div style="margin-top:8px;background:rgba(255,255,255,.14);border-radius:10px;padding:6px 12px;font-size:.88em">
                    🎁 <?php esc_html_e('Festival credit:', 'vereskala-login'); ?>
                    <strong><?php echo wp_kses_post(wc_price($promo_balance)); ?></strong>
                    <?php
                    if ('' !== $promo_expiry && class_exists('VKL_Wc_Account')) {
                        [$jy, $jm, $jd] = VKL_Wc_Account::g2j((int) gmdate('Y', strtotime($promo_expiry)), (int) gmdate('m', strtotime($promo_expiry)), (int) gmdate('d', strtotime($promo_expiry)));

                        printf(
                            /* translators: %s: Jalali date. */
                            ' · ' . esc_html__('valid until %s — spent automatically before your balance at checkout', 'vereskala-login'),
                            esc_html(sprintf('%04d/%02d/%02d', $jy, $jm, $jd))
                        );
                    }
                    $vkl_min_cond = vkl('wallet.promos')->min_condition($user_id);

                    if ($vkl_min_cond > 0) {
                        printf(
                            /* translators: %s: minimum order amount. */
                            ' · ' . esc_html__('usable on orders from %s', 'vereskala-login'),
                            wp_kses_post(wc_price($vkl_min_cond))
                        );
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <?php
        /**
         * Fires on the wallet account tab between the balance and the
         * transaction list. Used by the top-up form.
         *
         * @param int $user_id Current user.
         */
        do_action('vkl_wallet_account_render', $user_id);
        ?>

        <h3>🧾 <?php esc_html_e('Recent transactions', 'vereskala-login'); ?></h3>
        <table class="woocommerce-table shop_table" style="border-radius:12px;overflow:hidden">
            <thead><tr>
                <th><?php esc_html_e('Date', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Description', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php $vkl_transactions = $this->repository->latest_for_user($user_id); ?>
            <?php if ([] === $vkl_transactions) : ?>
                <tr><td colspan="3" style="text-align:center;color:#666;padding:18px">
                    <?php esc_html_e('No transactions yet — top up your wallet or shop to see them here.', 'vereskala-login'); ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($vkl_transactions as $row) : ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $row['created_at']))); ?></td>
                    <td><?php echo esc_html($this->reason_label((string) $row['reason'])); ?></td>
                    <td><?php echo wp_kses_post(wc_price((float) $row['amount'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
