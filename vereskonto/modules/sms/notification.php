<?php
/**
 * Shop-admin notification listener — alerts the configured admin mobile
 * about important store events (new orders, recovered carts).
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Notification
 */
final class VKL_Sms_Notification
{
    private VKL_Sms_Sender $sender;

    private VKL_Config $config;

    public function __construct(VKL_Sms_Sender $sender, VKL_Config $config)
    {
        $this->sender = $sender;
        $this->config = $config;
    }

    public function register(): void
    {
        vkl_listen('order.status_changed', [$this, 'on_new_order']);
        vkl_listen('order.created', [$this, 'on_order_placed']);
    }

    /**
     * Alert admin the moment a customer places an order at checkout,
     * regardless of the initial status (e.g. cash-on-delivery orders
     * staying 'on-hold' still trigger this).
     *
     * @param array<string, mixed> $payload Order event payload.
     */
    public function on_order_placed(array $payload): void
    {
        // An instalment or a wallet top-up is not a new order for the
        // shop; it has its own message. This check was missing here,
        // which is why the shop was texted twice for one repayment.
        if (vkl_is_carrier_order((int) ($payload['order_id'] ?? 0))) {
            return;
        }

        $pattern = $this->config->get_string('admin_pattern_order_placed');
        $admin   = $this->config->get_string('admin_mobile');

        if ('' === $pattern || '' === $admin) {
            return;
        }

        $this->sender->send(
            $admin,
            'admin.order_placed',
            $pattern,
            ['order' => (string) ($payload['order_number'] ?? '')]
        );
    }

    /**
     * Alert admin when an order enters processing (i.e. is paid).
     *
     * @param array<string, mixed> $payload Order event payload.
     */
    public function on_new_order(array $payload): void
    {
        if ('processing' !== ($payload['new_status'] ?? '')) {
            return;
        }

        if (vkl_is_carrier_order((int) ($payload['order_id'] ?? 0))) {
            return;
        }

        $pattern = $this->config->get_string('admin_pattern_new_order');
        $admin   = $this->config->get_string('admin_mobile');

        if ('' === $pattern || '' === $admin) {
            return;
        }

        $this->sender->send(
            $admin,
            'admin.new_order',
            $pattern,
            ['order' => (string) ($payload['order_number'] ?? '')]
        );
    }
}
