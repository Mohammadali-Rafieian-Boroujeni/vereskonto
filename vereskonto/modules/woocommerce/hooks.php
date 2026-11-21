<?php
/**
 * WooCommerce order hooks — translates order lifecycle hooks into
 * platform events. No SMS knowledge here.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Hooks
 */
final class VKL_Wc_Hooks
{
    private VKL_Validator $validator;

    public function __construct(VKL_Validator $validator)
    {
        $this->validator = $validator;
    }

    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 3);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_order_created'], 20, 1);
    }

    /**
     * A customer just placed an order at checkout (any initial status —
     * fires even for gateways whose orders never reach 'processing',
     * e.g. cash on delivery staying 'on-hold').
     *
     * @param mixed $order_id Order id.
     */
    public function on_order_created(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order) {
            return;
        }

        vkl_dispatch('order.created', $this->payload($order, [
            'status' => $order->get_status(),
        ]));
    }

    /**
     * @param mixed $order_id Order id.
     * @param mixed $old      Old status.
     * @param mixed $new      New status.
     */
    public function on_status_changed(mixed $order_id, mixed $old, mixed $new): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order) {
            return;
        }

        vkl_dispatch('order.status_changed', $this->payload($order, [
            'old_status' => (string) $old,
            'new_status' => (string) $new,
        ]));
    }

    /**
     * Common order payload.
     *
     * @param WC_Order             $order Order.
     * @param array<string, mixed> $extra Extra keys.
     *
     * @return array<string, mixed>
     */
    public function payload(WC_Order $order, array $extra = []): array
    {
        $mobile = $this->validator->normalize_mobile((string) $order->get_billing_phone());

        return array_merge([
            'order_id'     => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'user_id'      => (int) $order->get_customer_id(),
            'mobile'       => $this->validator->is_mobile($mobile) ? $mobile : '',
            'total'        => (float) $order->get_total(),
        ], $extra);
    }
}
