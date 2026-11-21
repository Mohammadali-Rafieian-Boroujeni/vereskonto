<?php
/**
 * Carrier orders — instalment repayments and wallet top-ups — get their
 * own order status, so the shop's books stop counting them as sales.
 *
 * A repayment has to travel through a WooCommerce order: that is the only
 * road to the payment gateway. But nothing was sold. Left in `processing`
 * or `completed`, a credit customer's purchase is booked once as goods and
 * then again with every instalment, and the revenue figures drift further
 * apart with each month of repayments.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   2.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Carrier_Order
 */
final class VKL_Wallet_Carrier_Order
{
    /**
     * Status slug without the WooCommerce `wc-` prefix.
     */
    public const STATUS = 'vkl-repay';

    private VKL_Config $config;

    private VKL_Logger $logger;

    public function __construct(VKL_Config $config, VKL_Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_status']);
        add_filter('wc_order_statuses', [$this, 'add_to_status_list']);

        // Still counts as paid: the money really did arrive, and both the
        // gateway and our own booking code ask is_paid() about it.
        add_filter('woocommerce_order_is_paid_statuses', [$this, 'count_as_paid']);

        // Priority 90 — after settle() and credit() (both at 20) have
        // booked the money. Moving the status first would leave a
        // repayment unrecorded.
        add_action('woocommerce_order_status_processing', [$this, 'move'], 90, 1);
        add_action('woocommerce_order_status_completed', [$this, 'move'], 90, 1);

        // WooCommerce's own order mails say "your order is complete" for
        // something the customer never ordered. The plugin already sends
        // a repayment confirmation of its own.
        foreach ([
            'new_order',
            'customer_processing_order',
            'customer_completed_order',
            'customer_on_hold_order',
            'customer_invoice',
        ] as $mail) {
            add_filter('woocommerce_email_enabled_' . $mail, [$this, 'silence_mail'], 10, 2);
        }
    }

    /**
     * @param bool  $enabled Whether WooCommerce would send this mail.
     * @param mixed $order   Order the mail is about.
     */
    public function silence_mail(bool $enabled, mixed $order): bool
    {
        if (!$enabled || !$this->config->get_bool('carrier_order_silent', true)) {
            return $enabled;
        }

        return $order instanceof WC_Order && vkl_is_carrier_order($order->get_id()) ? false : $enabled;
    }

    /**
     * Is the separate status switched on?
     */
    public function enabled(): bool
    {
        return $this->config->get_bool('carrier_order_status', true);
    }

    /**
     * Human label, used in the admin list and the customer's order list.
     */
    public function label(): string
    {
        return __('Repayment / top-up', 'vereskala-login');
    }

    public function register_status(): void
    {
        register_post_status('wc-' . self::STATUS, [
            'label'                     => $this->label(),
            'public'                    => false,
            'internal'                  => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders. */
            'label_count'               => _n_noop(
                'Repayment / top-up <span class="count">(%s)</span>',
                'Repayment / top-up <span class="count">(%s)</span>',
                'vereskala-login'
            ),
        ]);
    }

    /**
     * @param array<string, string> $statuses Registered order statuses.
     *
     * @return array<string, string>
     */
    public function add_to_status_list(array $statuses): array
    {
        $statuses['wc-' . self::STATUS] = $this->label();

        return $statuses;
    }

    /**
     * @param array<int, string> $statuses Paid statuses.
     *
     * @return array<int, string>
     */
    public function count_as_paid(array $statuses): array
    {
        $statuses[] = self::STATUS;

        return $statuses;
    }

    /**
     * Move a paid carrier order out of the sales statuses.
     *
     * @param mixed $order_id Order id.
     */
    public function move(mixed $order_id): void
    {
        // Allow shop admin to manually override the status by adding a
        // transient flag before changing it in the WC admin.
        if (get_transient('vkl_skip_carrier_' . (int) $order_id)) {
            delete_transient('vkl_skip_carrier_' . (int) $order_id);
            return;
        }

        if (!$this->enabled() || !vkl_is_carrier_order((int) $order_id)) {
            return;
        }

        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order || self::STATUS === $order->get_status()) {
            return;
        }

        $order->update_status(
            self::STATUS,
            __('Booked as a repayment or wallet top-up — not a sale of goods.', 'vereskala-login')
        );
    }

    /**
     * Move carrier orders that were paid before this version existed.
     *
     * @param int $limit How many to convert in one run.
     *
     * @return int Number of orders moved.
     */
    public function convert_past(int $limit = 200): int
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $moved = 0;

        foreach ([VKL_Wallet_Installments::ORDER_META, VKL_Wallet_Topup::ORDER_META, '_vkl_installment_plans'] as $meta) {
            $orders = wc_get_orders([
                'limit'      => $limit,
                'status'     => ['processing', 'completed'],
                'meta_key'   => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'return'     => 'objects',
            ]);

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }

                // For an instalment plan, the meta alone does not say
                // whether this order is repaying an EXISTING plan (a
                // carrier order — nothing sold) or is the order that
                // consumed credit to CREATE the plan in the first place
                // (a genuine sale, paid for with credit). Only the plan's
                // own 'order_id' column, set once at creation and never
                // touched by a repayment, tells them apart. Skipping the
                // origin order here stops a bulk reclassify from hiding
                // real past revenue as a refund.
                if (VKL_Wallet_Installments::ORDER_META === $meta) {
                    $plan_id = (int) $order->get_meta($meta);
                    $plan    = $plan_id > 0 ? vkl('wallet.installments')->find($plan_id) : null;

                    if (null !== $plan && (int) $plan['order_id'] === $order->get_id()) {
                        continue;
                    }
                }

                $order->update_status(
                    self::STATUS,
                    __('Reclassified: repayment or wallet top-up, not a sale of goods.', 'vereskala-login')
                );

                ++$moved;
            }
        }

        $this->logger->info('Carrier orders reclassified', ['count' => $moved]);

        return $moved;
    }

    /**
     * Keep the new status out of the Analytics totals.
     *
     * WooCommerce includes every custom status in Analytics by default —
     * the opposite of the legacy reports, which only ever counted
     * processing and completed. Without this the separate status would
     * change nothing on the Analytics screens.
     */
    public static function exclude_from_analytics(): void
    {
        $excluded = get_option('woocommerce_excluded_report_order_statuses', []);

        if (!is_array($excluded)) {
            $excluded = [];
        }

        if (!in_array(self::STATUS, $excluded, true)) {
            $excluded[] = self::STATUS;
            update_option('woocommerce_excluded_report_order_statuses', $excluded);
        }
    }
}
