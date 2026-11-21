<?php
/**
 * Event notifier — routes invoices and key order events through the
 * multi-channel dispatcher (Bale first, then WhatsApp/SMS by preference).
 *
 * Design note: the existing SMS listeners keep working untouched. This
 * class adds the RICHER deliveries that only a connected chat channel can
 * do — sending the invoice link (and, where available, the invoice file)
 * to the customer's preferred channel. To avoid double-charging for SMS,
 * it sends the free-text/document form only, which SMS/WhatsApp decline
 * (no pattern) — so those still flow through their own pattern listeners,
 * while Bale-connected customers get the rich message here.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.50.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Events
 */
final class VKL_Notify_Events
{
    private VKL_Notify_Dispatcher $dispatcher;

    private VKL_Config $config;

    public function __construct(VKL_Notify_Dispatcher $dispatcher, VKL_Config $config)
    {
        $this->dispatcher = $dispatcher;
        $this->config     = $config;
    }

    public function register(): void
    {
        // Send the invoice link when an order is paid/completed.
        add_action('woocommerce_order_status_completed', [$this, 'on_order_paid'], 30, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_paid'], 30, 1);

        // Rich wallet-credit notice for customers with a connected channel.
        vkl_listen('wallet.charged', [$this, 'on_wallet_charged']);

        // Browser notifications for the moments a customer actually wants
        // to hear about, whether or not they have the site open.
        add_action('woocommerce_order_status_changed', [$this, 'push_order_status'], 20, 4);
        vkl_listen('wallet.charged', [$this, 'push_wallet']);
        vkl_listen('installment.paid', [$this, 'push_installment']);
    }

    /**
     * Tell a connected customer their wallet was credited. Free-text only,
     * so it reaches Bale; SMS/WhatsApp keep their own pattern listeners.
     *
     * @param array<string, mixed> $payload {user_id, amount, balance, reason}
     */
    /**
     * Push helper — silent when the feature is off or unconfigured.
     */
    private function push(int $user_id, string $title, string $body, string $url = ''): void
    {
        if ($user_id <= 0 || !$this->config->get_bool('push_enabled', false)) {
            return;
        }

        try {
            vkl('notify.push')->notify($user_id, $title, $body, $url);
        } catch (Throwable $e) { // phpcs:ignore
            // A notification is never worth breaking the order flow for.
        }
    }

    /**
     * Order moved on: the single most useful thing to be told about.
     *
     * @param int    $order_id Order id.
     * @param string $from     Previous status.
     * @param string $to       New status.
     * @param mixed  $order    Order object.
     */
    public function push_order_status(int $order_id, string $from, string $to, $order = null): void
    {
        $wanted = ['processing', 'completed', 'cancelled', 'refunded'];

        if (!in_array($to, $wanted, true) || $this->is_carrier_order($order_id)) {
            return;
        }

        $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $number = (string) $order->get_order_number();

        $bodies = [
            'processing' => __('سپارش %s شما پرداخت شد و در دست آماده‌سازی است.', 'vereskala-login'),
            'completed'  => __('سپارش %s شما تحویل داده شد. سپاس از خرید شما 🌿', 'vereskala-login'),
            'cancelled'  => __('سپارش %s شما لغو شد.', 'vereskala-login'),
            'refunded'   => __('بازپرداخت سپارش %s شما انجام شد.', 'vereskala-login'),
        ];

        $this->push(
            (int) $order->get_customer_id(),
            __('وضعیت سپارش شما', 'vereskala-login'),
            sprintf($bodies[$to], $number),
            $order->get_view_order_url()
        );
    }

    /**
     * @param array<string, mixed> $payload {user_id, amount}
     */
    public function push_wallet(array $payload): void
    {
        $this->push(
            (int) ($payload['user_id'] ?? 0),
            __('کیف پول شما', 'vereskala-login'),
            sprintf(
                /* translators: %s: amount added to the wallet. */
                __('اعتبار %s به کیف پول شما افزوده شد.', 'vereskala-login'),
                wp_strip_all_tags(wc_price((float) ($payload['amount'] ?? 0)))
            ),
            wc_get_account_endpoint_url('wallet')
        );
    }

    /**
     * @param array<string, mixed> $payload {user_id, amount, settled}
     */
    public function push_installment(array $payload): void
    {
        $settled = !empty($payload['settled']);

        $this->push(
            (int) ($payload['user_id'] ?? 0),
            __('پرداخت قسط', 'vereskala-login'),
            $settled
                ? __('آخرین قسط شما پرداخت شد و طرح تسویه گردید. 🎉', 'vereskala-login')
                : sprintf(
                    /* translators: %s: instalment amount. */
                    __('قسط %s شما دریافت شد.', 'vereskala-login'),
                    wp_strip_all_tags(wc_price((float) ($payload['amount'] ?? 0)))
                ),
            wc_get_account_endpoint_url('credit')
        );
    }

    public function on_wallet_charged(array $payload): void
    {
        if (!$this->config->get_bool('notify_wallet_enabled', true)) {
            return;
        }

        $user_id = (int) ($payload['user_id'] ?? 0);

        if ($user_id <= 0 || !$this->dispatcher->can_reach_any($user_id)) {
            return;
        }

        $amount  = (float) ($payload['amount'] ?? 0);
        $balance = (float) ($payload['balance'] ?? 0);

        if ($amount <= 0) {
            return;
        }

        $fmt = static fn(float $n): string => function_exists('wc_price')
            ? wp_strip_all_tags(wc_price($n))
            : number_format_i18n($n);

        $message = sprintf(
            /* translators: 1: shop, 2: amount, 3: balance. */
            __("%1\$s
Your wallet was credited: %2\$s
New balance: %3\$s", 'vereskala-login'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $fmt($amount),
            $fmt($balance)
        );

        $this->dispatcher->notify($user_id, 'wallet.charged', $message, ['amount' => $amount]);
    }

    /**
     * Deliver the invoice link to the customer's preferred rich channel.
     * Guarded by a per-order meta flag so a processing→completed transition
     * does not send twice.
     */
    /**
     * Orders the plugin creates for itself — an instalment payment or a
     * wallet top-up — must not trigger purchase messaging. Otherwise a
     * customer repaying a loan gets an invoice for a product they never
     * bought, on top of the repayment confirmation.
     */
    private function is_carrier_order(int $order_id): bool
    {
        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return false;
        }

        return '' !== (string) $order->get_meta('_vkl_wallet_topup')
            || 0 !== (int) $order->get_meta('_vkl_installment_plan');
    }

    public function on_order_paid(int $order_id): void
    {
        if ($this->is_carrier_order($order_id)) {
            return;
        }

        if (!$this->config->get_bool('notify_invoice_enabled', true)) {
            return;
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $user_id = (int) $order->get_customer_id();

        if ($user_id <= 0) {
            return;
        }

        if ('yes' === $order->get_meta('_vkl_notify_invoice_sent')) {
            return;
        }

        // Only send if the customer actually has a rich channel connected
        // that supports free text (i.e. Bale). Otherwise stay silent here
        // and let the normal SMS order listener do its job.
        if (!$this->dispatcher->can_reach_any($user_id)) {
            return;
        }

        $invoice_url = add_query_arg(
            ['vkl_invoice' => $order_id, 'key' => $order->get_order_key()],
            home_url('/')
        );

        $message = sprintf(
            /* translators: 1: shop, 2: order number, 3: total, 4: url. */
            __("%1\$s\nYour invoice for order #%2\$s is ready.\nTotal: %3\$s\nView / print: %4\$s", 'vereskala-login'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            $order->get_order_number(),
            wp_strip_all_tags($order->get_formatted_order_total()),
            $invoice_url
        );

        $channel = $this->dispatcher->notify($user_id, 'invoice', $message, ['order_id' => $order_id]);

        if ('' !== $channel) {
            $order->update_meta_data('_vkl_notify_invoice_sent', 'yes');
            $order->update_meta_data('_vkl_notify_invoice_channel', $channel);
            $order->save();
        }
    }
}
