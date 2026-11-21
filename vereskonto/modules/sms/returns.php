<?php
/**
 * Return-request SMS listeners — notify the shop admin of a new
 * request, notify the customer once it has been decided.
 *
 * @package Vereskonto\Modules\Sms
 * @since   2.9.20
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Returns
 */
final class VKL_Sms_Returns
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
        vkl_listen('return.requested', [$this, 'on_requested']);
        vkl_listen('return.approved', [$this, 'on_decided']);
        vkl_listen('return.rejected', [$this, 'on_decided']);
    }

    /**
     * Admin notification: a customer asked to return items.
     *
     * @param array<string, mixed> $payload {return_id, order_id, user_id}
     */
    public function on_requested(array $payload): void
    {
        $pattern = $this->config->get_string('returns_pattern_admin');
        $admin   = $this->config->get_string('admin_mobile');

        if (!$this->config->get_bool('returns_sms_enabled', false) || '' === $pattern || '' === $admin) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order((int) ($payload['order_id'] ?? 0)) : false;

        $this->sender->send(
            $admin,
            'return.admin_alert',
            $pattern,
            ['order' => $order instanceof WC_Order ? (string) $order->get_order_number() : (string) ($payload['order_id'] ?? '')]
        );
    }

    /**
     * Customer notification: the request was approved or rejected.
     *
     * @param array<string, mixed> $payload {return_id, order_id, user_id, mobile, note}
     * @param string                $event   'return.approved' or 'return.rejected'.
     */
    public function on_decided(array $payload, string $event = ''): void
    {
        $pattern = $this->config->get_string('returns_pattern_decided');
        $mobile  = (string) ($payload['mobile'] ?? '');

        if (!$this->config->get_bool('returns_sms_enabled', false) || '' === $pattern || '' === $mobile) {
            return;
        }

        $order  = function_exists('wc_get_order') ? wc_get_order((int) ($payload['order_id'] ?? 0)) : false;
        $status = 'return.approved' === $event
            ? 'تأیید شد'
            : 'رد شد';

        $this->sender->send(
            $mobile,
            'return.decided',
            $pattern,
            [
                'name'   => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'order'  => $order instanceof WC_Order ? (string) $order->get_order_number() : (string) ($payload['order_id'] ?? ''),
                'status' => $status,
            ]
        );
    }
}
