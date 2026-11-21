<?php
/**
 * Fills the contact timeline from events the rest of the plugin already
 * dispatches (orders, tickets, returns, wallet, instalments, credit
 * requests), and records the CRM's own changes (owner, stage, created).
 *
 * No other module is modified: everything arrives through the event bus.
 * Each system entry carries a dedupe key, so an event delivered twice
 * (retries, duplicate hooks) appears once.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Timeline
 */
final class VKL_Crm_Timeline
{
    /** Order statuses worth a timeline entry (others are noise). */
    private const ORDER_STATUSES = ['processing', 'completed', 'cancelled', 'refunded', 'failed', 'on-hold'];

    private VKL_Crm_Activities $activities;
    private VKL_Crm_Contact_Service $contacts;

    public function __construct(VKL_Crm_Activities $activities, VKL_Crm_Contact_Service $contacts)
    {
        $this->activities = $activities;
        $this->contacts   = $contacts;
    }

    public function register(): void
    {
        $map = [
            'order.status_changed'   => 'on_order_status',
            'ticket.created'         => 'on_ticket_created',
            'ticket.closed'          => 'on_ticket_closed',
            'return.requested'       => 'on_return_requested',
            'wallet.charged'         => 'on_wallet',
            'wallet.debited'         => 'on_wallet',
            'installment.paid'       => 'on_installment_paid',
            'credit_request.created' => 'on_credit_request',
            'credit_request.decided' => 'on_credit_decided',
        ];

        foreach ($map as $event => $method) {
            vkl_listen($event, function (array $payload, string $name) use ($method): void {
                $this->safely(fn() => $this->{$method}($payload, $name));
            }, 50);
        }
    }

    /**
     * Record a CRM-side change ("assigned to Sara", "stage → customer").
     */
    public function system(int $contact_id, string $text, int $author_id = 0): int
    {
        return $this->activities->log([
            'contact_id' => $contact_id,
            'type'       => 'system',
            'subject'    => mb_substr($text, 0, 190),
            'author_id'  => $author_id > 0 ? $author_id : get_current_user_id(),
            'done_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $p */
    private function on_order_status(array $p): void
    {
        $order_id = (int) ($p['order_id'] ?? 0);
        $status   = (string) ($p['new_status'] ?? '');

        if ($order_id <= 0 || !in_array($status, self::ORDER_STATUSES, true) || vkl_is_carrier_order($order_id)) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $contact_id = $this->contacts->for_order($order);

        if ($contact_id <= 0) {
            return;
        }

        $this->activities->log([
            'contact_id' => $contact_id,
            'type'       => 'order',
            'subject'    => sprintf(
                /* translators: 1: order number, 2: order status */
                __('Order #%1$s — %2$s', 'vereskala-login'),
                (string) ($p['order_number'] ?? $order_id),
                wc_get_order_status_name($status)
            ),
            'body'       => wp_strip_all_tags(html_entity_decode(wc_price((float) ($p['total'] ?? $order->get_total())), ENT_QUOTES)),
            'ref_type'   => 'order',
            'ref_id'     => $order_id,
            'done_at'    => gmdate('Y-m-d H:i:s'),
            'dedupe_key' => 'order:' . $order_id . ':' . $status,
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_ticket_created(array $p): void
    {
        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'       => 'ticket',
            'subject'    => sprintf(/* translators: %s: ticket subject */ __('Ticket opened: %s', 'vereskala-login'), (string) ($p['subject'] ?? '')),
            'ref_type'   => 'ticket',
            'ref_id'     => (int) ($p['ticket_id'] ?? 0),
            'dedupe_key' => 'ticket:' . (int) ($p['ticket_id'] ?? 0) . ':created',
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_ticket_closed(array $p): void
    {
        $ticket_id = (int) ($p['ticket_id'] ?? 0);

        if ($ticket_id <= 0 || !vkl()->has('ticket.repository')) {
            return;
        }

        $ticket = vkl('ticket.repository')->find($ticket_id);

        if (!is_array($ticket)) {
            return;
        }

        $this->log_for_user((int) ($ticket['user_id'] ?? 0), [
            'type'       => 'ticket',
            'subject'    => sprintf(/* translators: %s: ticket subject */ __('Ticket closed: %s', 'vereskala-login'), (string) ($ticket['subject'] ?? '')),
            'ref_type'   => 'ticket',
            'ref_id'     => $ticket_id,
            'dedupe_key' => 'ticket:' . $ticket_id . ':closed:' . gmdate('YmdHi'),
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_return_requested(array $p): void
    {
        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'       => 'return',
            'subject'    => sprintf(/* translators: %s: order number */ __('Return requested for order #%s', 'vereskala-login'), (string) ($p['order_id'] ?? '')),
            'ref_type'   => 'return',
            'ref_id'     => (int) ($p['return_id'] ?? 0),
            'dedupe_key' => 'return:' . (int) ($p['return_id'] ?? 0),
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_wallet(array $p, string $event): void
    {
        $amount = (float) ($p['amount'] ?? 0);

        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'    => 'wallet',
            'subject' => 'wallet.charged' === $event
                ? sprintf(/* translators: %s: amount */ __('Wallet credited %s', 'vereskala-login'), $this->plain_price($amount))
                : sprintf(/* translators: %s: amount */ __('Wallet debited %s', 'vereskala-login'), $this->plain_price($amount)),
            'body'    => (string) ($p['reason'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_installment_paid(array $p): void
    {
        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'       => 'installment',
            'subject'    => sprintf(/* translators: %s: amount */ __('Installment paid: %s', 'vereskala-login'), $this->plain_price((float) ($p['amount'] ?? 0))),
            'body'       => !empty($p['settled']) ? __('The plan is now fully paid.', 'vereskala-login') : '',
            'ref_type'   => 'installment',
            'ref_id'     => (int) ($p['plan_id'] ?? 0),
            'dedupe_key' => !empty($p['order_id']) ? 'installment:order:' . (int) $p['order_id'] : null,
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_credit_request(array $p): void
    {
        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'    => 'installment',
            'subject' => sprintf(/* translators: %s: amount */ __('Requested purchase credit of %s', 'vereskala-login'), $this->plain_price((float) ($p['amount'] ?? 0))),
        ]);
    }

    /** @param array<string, mixed> $p */
    private function on_credit_decided(array $p): void
    {
        $this->log_for_user((int) ($p['user_id'] ?? 0), [
            'type'    => 'installment',
            'subject' => !empty($p['approved'])
                ? sprintf(/* translators: %s: amount */ __('Credit request approved (%s)', 'vereskala-login'), $this->plain_price((float) ($p['amount'] ?? 0)))
                : __('Credit request declined', 'vereskala-login'),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    private function log_for_user(int $user_id, array $data): void
    {
        if ($user_id <= 0 || VKL_Crm_Contact_Service::is_staff($user_id)) {
            return;
        }

        $contact_id = $this->contacts->for_user($user_id);

        if ($contact_id > 0) {
            $data['subject'] = mb_substr((string) $data['subject'], 0, 190);
            $this->activities->log($data + ['contact_id' => $contact_id, 'done_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    private function plain_price(float $amount): string
    {
        return function_exists('wc_price')
            ? trim(wp_strip_all_tags(html_entity_decode(wc_price($amount), ENT_QUOTES)))
            : number_format_i18n($amount);
    }

    /**
     * Listeners run inside checkout, ticket and wallet flows; a timeline
     * hiccup must never break those.
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            vkl('logger')->error('CRM timeline listener failed.', ['error' => $e->getMessage()]);
        }
    }
}
