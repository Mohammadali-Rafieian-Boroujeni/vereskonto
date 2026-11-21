<?php
/**
 * Order SMS listeners — order status changes and shipment tracking codes.
 * Pattern codes are configured per status; empty pattern = no message.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Order
 */
final class VKL_Sms_Order
{
    /**
     * Order statuses that can have a pattern. Config key: order_pattern_{status}.
     */
    public const STATUSES = ['processing', 'completed', 'cancelled', 'refunded', 'on-hold'];

    private VKL_Sms_Sender $sender;

    private VKL_Config $config;

    public function __construct(VKL_Sms_Sender $sender, VKL_Config $config)
    {
        $this->sender = $sender;
        $this->config = $config;
    }

    public function register(): void
    {
        vkl_listen('order.status_changed', [$this, 'on_status_changed']);
        vkl_listen('installment.paid', [$this, 'on_installment_paid']);
        vkl_listen('order.tracking_added', [$this, 'on_tracking_added']);
    }

    /**
     * @param array<string, mixed> $payload {order_id, old_status, new_status, mobile, user_id, order_number, total}
     */
    /**
     * Is this order really a shop purchase, or one of our own carriers?
     *
     * Paying an instalment and topping up the wallet both go through a
     * hidden WooCommerce product, so the order machinery fires for them
     * exactly as it would for a real purchase. Left alone, a customer
     * repaying a loan received the loan confirmation AND an "order
     * received" plus an "order completed" message for something they
     * never ordered — and the shop got the same duplicates.
     */
    private function is_carrier_order(int $order_id): bool
    {
        return vkl_is_carrier_order($order_id);
    }

    public function on_status_changed(array $payload): void
    {
        if (!$this->config->get_bool('order_sms_enabled', false)) {
            return;
        }

        // An instalment or top-up already has its own message.
        if ($this->is_carrier_order((int) ($payload['order_id'] ?? 0))) {
            return;
        }

        $status  = (string) ($payload['new_status'] ?? '');
        $pattern = $this->config->get_string('order_pattern_' . str_replace('-', '_', $status));

        if ('' === $pattern) {
            return;
        }

        $this->sender->send(
            (string) ($payload['mobile'] ?? ''),
            'order.' . $status,
            $pattern,
            [
                'name'  => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'order' => (string) ($payload['order_number'] ?? ''),
            ]
        );

        // Tell the shop too when an order is cancelled — that is the one
        // status where nobody is watching the customer's side.
        if ('cancelled' === $status) {
            $this->notify_admin_cancelled($payload);
        }
    }

    /**
     * Confirm a received instalment to the customer, and tell the shop.
     *
     * Until now the customer only got WooCommerce's generic purchase
     * confirmation, which reads like a shop order rather than a repayment
     * — and the shop was not told at all.
     *
     * @param array<string, mixed> $payload {plan_id, user_id, amount, order_id, settled}
     */
    public function on_installment_paid(array $payload): void
    {
        if (!$this->config->get_bool('installment_paid_sms_enabled', false)) {
            return;
        }

        $user_id = (int) ($payload['user_id'] ?? 0);
        $amount  = (string) (int) ($payload['amount'] ?? 0);
        $settled = !empty($payload['settled']);

        // A finished plan deserves its own wording when one is configured.
        $pattern = $settled
            ? $this->config->get_string('installment_settled_pattern')
            : '';

        if ('' === $pattern) {
            $pattern = $this->config->get_string('installment_paid_pattern');
        }

        if ('' === $pattern || $user_id <= 0) {
            return;
        }

        $mobile = (string) get_user_meta($user_id, 'billing_phone', true);

        if ('' !== $mobile) {
            $this->sender->send(
                $mobile,
                'installment.paid',
                $pattern,
                [
                    'name'   => $this->sender->resolve_name($user_id),
                    'amount' => $amount,
                ]
            );
        }

        // And the shop, so a repayment is not silently invisible.
        $admin       = $this->config->get_string('admin_mobile');
        $admin_patt  = $this->config->get_string('installment_paid_admin_pattern');

        if ('' !== $admin && '' !== $admin_patt) {
            $this->sender->send(
                $admin,
                'installment.paid.admin',
                $admin_patt,
                [
                    'name'   => $this->sender->resolve_name($user_id),
                    'amount' => $amount,
                ],
                0
            );
        }
    }

    /**
     * Text the shop's own number about a cancellation.
     *
     * @param array<string, mixed> $payload Status-change payload.
     */
    private function notify_admin_cancelled(array $payload): void
    {
        if (!$this->config->get_bool('order_admin_cancel_enabled', false)) {
            return;
        }

        $admin = $this->config->get_string('admin_mobile');

        if ('' === $admin) {
            return;
        }

        // Its own pattern, falling back to the customer cancellation one.
        $pattern = $this->config->get_string('order_pattern_admin_cancelled');

        if ('' === $pattern) {
            $pattern = $this->config->get_string('order_pattern_cancelled');
        }

        if ('' === $pattern) {
            return;
        }

        $this->sender->send(
            $admin,
            'order.cancelled.admin',
            $pattern,
            [
                'name'  => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'order' => (string) ($payload['order_number'] ?? ''),
            ],
            0
        );
    }

    /**
     * @param array<string, mixed> $payload {order_id, mobile, user_id, order_number, tracking_code}
     */
    public function on_tracking_added(array $payload): void
    {
        if (!$this->config->get_bool('order_sms_enabled', false)) {
            return;
        }

        if ($this->is_carrier_order((int) ($payload['order_id'] ?? 0))) {
            return;
        }

        $pattern = $this->config->get_string('order_pattern_tracking');
        $type    = 'order.tracking';
        $method  = (string) ($payload['shipping_method'] ?? '');

        // Courier dispatch (پیک) gets its own message when configured —
        // the wording differs (no postal tracking site, arrival same-day).
        $courier_pattern = $this->config->get_string('order_pattern_tracking_courier');

        if ('' !== $courier_pattern && '' !== $method && false !== mb_strpos($method, 'پیک')) {
            $pattern = $courier_pattern;
            $type    = 'order.tracking_courier';
        }

        if ('' === $pattern) {
            return;
        }

        $this->sender->send(
            (string) ($payload['mobile'] ?? ''),
            $type,
            $pattern,
            [
                'name'   => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'order'  => (string) ($payload['order_number'] ?? ''),
                'code'   => (string) ($payload['tracking_code'] ?? ''),
                'method' => (string) ($payload['shipping_method'] ?? ''),
            ]
        );
    }
}
