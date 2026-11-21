<?php
/**
 * Ticket SMS listeners — notify the customer when support replies,
 * notify the shop admin when a ticket is created or answered.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Ticket
 */
final class VKL_Sms_Ticket
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
        vkl_listen('ticket.admin_replied', [$this, 'on_admin_replied']);
        vkl_listen('ticket.created', [$this, 'on_created']);
        vkl_listen('ticket.customer_replied', [$this, 'on_created']); // Same admin alert.
    }

    /**
     * Customer notification: support answered your ticket.
     *
     * @param array<string, mixed> $payload {ticket_id, user_id, mobile, subject}
     */
    public function on_admin_replied(array $payload): void
    {
        $pattern = $this->config->get_string('ticket_pattern_replied');

        if (!$this->config->get_bool('ticket_sms_enabled', false) || '' === $pattern) {
            return;
        }

        $this->sender->send(
            (string) ($payload['mobile'] ?? ''),
            'ticket.replied',
            $pattern,
            [
                'name'   => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'ticket' => (string) ($payload['ticket_id'] ?? ''),
            ]
        );
    }

    /**
     * Admin notification: new ticket / new customer reply.
     *
     * @param array<string, mixed> $payload {ticket_id, ...}
     */
    public function on_created(array $payload): void
    {
        $pattern = $this->config->get_string('ticket_pattern_admin');
        $admin   = $this->config->get_string('admin_mobile');

        if (!$this->config->get_bool('ticket_sms_enabled', false) || '' === $pattern || '' === $admin) {
            return;
        }

        $this->sender->send(
            $admin,
            'ticket.admin_alert',
            $pattern,
            ['ticket' => (string) ($payload['ticket_id'] ?? '')]
        );
    }
}
