<?php
/**
 * Deal service: validation and every state change of a sales
 * opportunity — create, edit, move between stages, win, lose, link an
 * order — with the timeline entry and contact-stage update that go with
 * each. Screens, AJAX and (later) the REST API all go through here.
 *
 * Status follows the stage: a deal in a "won" stage is won, in a "lost"
 * stage lost, anywhere else open. Moving a closed deal back to an open
 * stage reopens it.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.17.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Deal_Service
 */
final class VKL_Crm_Deal_Service
{
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Pipelines $pipelines;
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Contact_Service $contact_service;

    public function __construct(
        VKL_Crm_Deals $deals,
        VKL_Crm_Pipelines $pipelines,
        VKL_Crm_Contacts $contacts,
        VKL_Crm_Activities $activities,
        VKL_Crm_Contact_Service $contact_service
    ) {
        $this->deals           = $deals;
        $this->pipelines       = $pipelines;
        $this->contacts        = $contacts;
        $this->activities      = $activities;
        $this->contact_service = $contact_service;
    }

    /**
     * Validate deal input (keys present only). Stage must belong to the
     * pipeline; an order must belong to the deal's contact.
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing Current deal when editing.
     * @return array<string, mixed>|WP_Error
     */
    public function sanitize(array $input, ?array $existing = null)
    {
        $out = [];

        if (array_key_exists('title', $input)) {
            $out['title'] = mb_substr(trim(sanitize_text_field((string) $input['title'])), 0, 190);

            if ('' === $out['title']) {
                return $this->error('vkl_crm_title', __('Give the deal a title.', 'vereskala-login'), 'title');
            }
        }

        if (array_key_exists('contact_id', $input)) {
            $contact = $this->contacts->find((int) $input['contact_id']);

            if (null === $contact) {
                return $this->error('vkl_crm_contact', __('Choose a contact for the deal.', 'vereskala-login'), 'contact_id');
            }

            $out['contact_id'] = (int) $contact['id'];
        }

        if (array_key_exists('value', $input)) {
            $raw = str_replace([',', '٬', '،', ' '], '', vkl_latin_digits((string) $input['value']));

            if ('' !== $raw && (!is_numeric($raw) || (float) $raw < 0)) {
                return $this->error('vkl_crm_value', __('The deal value must be a positive number.', 'vereskala-login'), 'value');
            }

            $out['value'] = round((float) $raw, 4);
        }

        if (array_key_exists('owner_id', $input)) {
            $out['owner_id'] = max(0, (int) $input['owner_id']);
        }

        if (array_key_exists('source', $input)) {
            $out['source'] = substr(sanitize_key((string) $input['source']), 0, 40);
        }

        if (array_key_exists('lost_reason', $input)) {
            $out['lost_reason'] = mb_substr(trim(sanitize_text_field((string) $input['lost_reason'])), 0, 190);
        }

        if (array_key_exists('expected_close', $input)) {
            $date = trim(vkl_latin_digits((string) $input['expected_close']));

            if ('' === $date) {
                $out['expected_close'] = null;
            } else {
                $g = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && (int) substr($date, 0, 4) > 1700 ? $date : VKL_Jalali::parse($date);

                if ('' === $g) {
                    return $this->error('vkl_crm_close', __('The expected close date is not valid.', 'vereskala-login'), 'expected_close');
                }

                $out['expected_close'] = $g;
            }
        }

        // Pipeline and stage, checked together.
        $pipeline_id = (int) ($input['pipeline_id'] ?? $existing['pipeline_id'] ?? 0);

        if (array_key_exists('pipeline_id', $input) || array_key_exists('stage_id', $input)) {
            if (null === $this->pipelines->find($pipeline_id)) {
                $default     = $this->pipelines->default_pipeline();
                $pipeline_id = (int) ($default['id'] ?? 0);
            }

            $stage = isset($input['stage_id']) && (int) $input['stage_id'] > 0 ? $this->pipelines->stage((int) $input['stage_id']) : null;

            if (null !== $stage && (int) $stage['pipeline_id'] !== $pipeline_id) {
                return $this->error('vkl_crm_stage', __('That stage belongs to another pipeline.', 'vereskala-login'), 'stage_id');
            }

            $stage ??= $this->pipelines->first_stage($pipeline_id);

            if (null === $stage) {
                return $this->error('vkl_crm_stage', __('This pipeline has no open stage yet. Add one in CRM settings.', 'vereskala-login'), 'stage_id');
            }

            $out['pipeline_id'] = $pipeline_id;
            $out['stage_id']    = (int) $stage['id'];
        }

        if (array_key_exists('order', $input)) {
            $contact_id = (int) ($out['contact_id'] ?? $existing['contact_id'] ?? 0);
            $order_id   = $this->resolve_order((string) $input['order'], $contact_id);

            if (is_wp_error($order_id)) {
                return $order_id;
            }

            $out['order_id'] = $order_id;
        }

        return $out;
    }

    /**
     * Create a deal. Defaults: default pipeline, its first open stage,
     * the contact's owner (else the creator). A lead or prospect with a
     * new open deal becomes a prospect.
     *
     * @param array<string, mixed> $input
     * @return int|WP_Error
     */
    public function create(array $input, int $author_id = 0)
    {
        $input += ['pipeline_id' => 0, 'stage_id' => 0];
        $data   = $this->sanitize($input);

        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data['title']) || empty($data['contact_id'])) {
            return $this->error('vkl_crm_required', __('A deal needs a title and a contact.', 'vereskala-login'), 'title');
        }

        $contact = $this->contacts->find((int) $data['contact_id']);
        $stage   = $this->pipelines->stage((int) $data['stage_id']);

        if (!array_key_exists('owner_id', $data)) {
            $data['owner_id'] = (int) ($contact['owner_id'] ?? 0) ?: max(0, $author_id);
        }

        $data['status']    = $stage['kind'];
        $data['closed_at'] = 'open' === $stage['kind'] ? null : gmdate('Y-m-d H:i:s');
        $data['position']  = $this->top_position((int) $data['stage_id']);

        $id = $this->deals->create($data);

        if ($id <= 0) {
            return $this->error('vkl_crm_db', __('The deal could not be saved.', 'vereskala-login'), '', 500);
        }

        $this->log($id, (int) $data['contact_id'], sprintf(
            /* translators: 1: deal title, 2: stage name */
            __('New deal "%1$s" in %2$s', 'vereskala-login'),
            $data['title'],
            $stage['name']
        ), $author_id);

        $this->promote_contact((int) $data['contact_id'], (string) $stage['kind']);

        return $id;
    }

    /**
     * Edit fields. A stage change in $input is applied through move(), so
     * it gets the same status rules and timeline entry.
     *
     * @param array<string, mixed> $input
     * @return true|WP_Error
     */
    public function update(int $id, array $input, int $author_id = 0)
    {
        $deal = $this->deals->find($id);

        if (null === $deal) {
            return $this->error('vkl_crm_missing', __('That deal no longer exists.', 'vereskala-login'), '', 404);
        }

        $data = $this->sanitize($input, $deal);

        if (is_wp_error($data)) {
            return $data;
        }

        $new_stage = (int) ($data['stage_id'] ?? $deal['stage_id']);
        unset($data['stage_id'], $data['pipeline_id']);

        if ([] !== $data && !$this->deals->update($id, $data)) {
            return $this->error('vkl_crm_db', __('The deal could not be saved.', 'vereskala-login'), '', 500);
        }

        if ($new_stage !== (int) $deal['stage_id']) {
            $moved = $this->move($id, $new_stage, (string) ($data['lost_reason'] ?? ''), $author_id);

            if (is_wp_error($moved)) {
                return $moved;
            }
        }

        return true;
    }

    /**
     * Move a deal to another stage of its pipeline.
     *
     * @return true|WP_Error
     */
    public function move(int $id, int $stage_id, string $lost_reason = '', int $author_id = 0)
    {
        $deal  = $this->deals->find($id);
        $stage = $this->pipelines->stage($stage_id);

        if (null === $deal || null === $stage || (int) $stage['pipeline_id'] !== (int) $deal['pipeline_id']) {
            return $this->error('vkl_crm_stage', __('That stage is not part of this deal\'s pipeline.', 'vereskala-login'), 'stage_id');
        }

        if ((int) $deal['stage_id'] === $stage_id) {
            return true;
        }

        $from = $this->pipelines->stage((int) $deal['stage_id']);
        $data = [
            'stage_id' => $stage_id,
            'status'   => $stage['kind'],
            'position' => $this->top_position($stage_id),
        ];

        if ('open' === $stage['kind']) {
            $data['closed_at']   = null;
            $data['lost_reason'] = '';
        } elseif ('open' === $deal['status'] || $deal['status'] !== $stage['kind']) {
            $data['closed_at'] = gmdate('Y-m-d H:i:s');
        }

        if ('lost' === $stage['kind']) {
            $data['lost_reason'] = mb_substr(trim(sanitize_text_field($lost_reason)), 0, 190);
        }

        $this->deals->update($id, $data);

        $text = sprintf(
            /* translators: 1: deal title, 2: old stage, 3: new stage */
            __('"%1$s": %2$s → %3$s', 'vereskala-login'),
            $deal['title'],
            (string) ($from['name'] ?? '—'),
            $stage['name']
        );

        if ('lost' === $stage['kind'] && '' !== ($data['lost_reason'] ?? '')) {
            $text .= ' — ' . $data['lost_reason'];
        }

        $this->log($id, (int) $deal['contact_id'], $text, $author_id);
        $this->promote_contact((int) $deal['contact_id'], (string) $stage['kind']);

        /**
         * Fires after a deal changes stage.
         *
         * @param int    $id     Deal id.
         * @param string $status open|won|lost.
         */
        vkl_dispatch('crm.deal_moved', ['deal_id' => $id, 'stage_id' => $stage_id, 'status' => $stage['kind']]);

        return true;
    }

    /**
     * Shortcuts: the pipeline's first won / lost stage.
     *
     * @return true|WP_Error
     */
    public function close(int $id, bool $won, string $lost_reason = '', int $author_id = 0)
    {
        $deal = $this->deals->find($id);

        if (null === $deal) {
            return $this->error('vkl_crm_missing', __('That deal no longer exists.', 'vereskala-login'), '', 404);
        }

        foreach ($this->pipelines->stages((int) $deal['pipeline_id']) as $stage) {
            if (($won ? 'won' : 'lost') === $stage['kind']) {
                return $this->move($id, (int) $stage['id'], $lost_reason, $author_id);
            }
        }

        return $this->error('vkl_crm_stage', $won
            ? __('This pipeline has no "won" stage. Add one in CRM settings.', 'vereskala-login')
            : __('This pipeline has no "lost" stage. Add one in CRM settings.', 'vereskala-login'), 'stage_id');
    }

    /* ------------------------------------------------------------------ *
     *  Automation
     * ------------------------------------------------------------------ */

    public function register_sync(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status'], 30, 3);

        vkl_listen('credit_request.created', function (array $p): void {
            $this->safely(fn() => $this->on_credit_request($p));
        }, 60);
    }

    /**
     * A linked order that gets paid wins its open deals.
     */
    public function on_order_status(int $order_id, string $from, string $to): void
    {
        if (!in_array($to, VKL_Crm_Contact_Service::paid_statuses(), true)) {
            return;
        }

        $this->safely(function () use ($order_id): void {
            foreach ($this->deals->search(['order_id' => $order_id, 'status' => 'open'], ['1=1', []], 1, 20)['rows'] as $deal) {
                $this->close((int) $deal['id'], true);
            }
        });
    }

    /**
     * Optionally open a deal for each purchase-credit request.
     *
     * @param array<string, mixed> $p
     */
    private function on_credit_request(array $p): void
    {
        $user_id = (int) ($p['user_id'] ?? 0);

        if ($user_id <= 0 || !vkl('config')->get_bool('crm_deal_credit', false)) {
            return;
        }

        $contact_id = $this->contact_service->for_user($user_id);

        if ($contact_id > 0) {
            $this->create([
                'contact_id' => $contact_id,
                'title'      => __('Installment purchase (credit request)', 'vereskala-login'),
                'value'      => (string) (float) ($p['amount'] ?? 0),
                'source'     => 'credit',
            ]);
        }
    }

    /* ------------------------------------------------------------------ */

    /**
     * Order number or id → order id, checked against the contact. '' → 0.
     *
     * @return int|WP_Error
     */
    private function resolve_order(string $raw, int $contact_id)
    {
        $raw = ltrim(trim(vkl_latin_digits($raw)), '#');

        if ('' === $raw || '0' === $raw) {
            return 0;
        }

        // Order numbers are order ids on this shop (no sequential-number
        // plugin). wc_get_orders has no "order number" query, and an
        // unknown query key is silently ignored — it would return the
        // newest order — so only an exact id is accepted.
        $order = function_exists('wc_get_order') && ctype_digit($raw) ? wc_get_order((int) $raw) : false;

        if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
            return $this->error('vkl_crm_order', __('No order with that number.', 'vereskala-login'), 'order');
        }

        $contact = $this->contacts->find($contact_id);
        $ids     = null === $contact ? [] : array_map(static fn(WC_Order $o): int => $o->get_id(), $this->contact_service->orders($contact));

        if (!in_array($order->get_id(), $ids, true)) {
            return $this->error('vkl_crm_order', __('That order belongs to a different customer.', 'vereskala-login'), 'order');
        }

        return $order->get_id();
    }

    /**
     * A new card goes to the top of its column.
     */
    private function top_position(int $stage_id): int
    {
        $rows = $this->deals->search(['stage_id' => $stage_id], ['1=1', []], 1, 1, 'position', 'ASC')['rows'];

        return isset($rows[0]) ? (int) $rows[0]['position'] - 1 : 0;
    }

    /**
     * Never downgrades: open deal → lead becomes prospect; won deal →
     * lead/prospect becomes customer.
     */
    private function promote_contact(int $contact_id, string $kind): void
    {
        $contact = $this->contacts->find($contact_id);

        if (null === $contact) {
            return;
        }

        $to = null;

        if ('won' === $kind && in_array($contact['lifecycle'], ['lead', 'prospect'], true)) {
            $to = 'customer';
        } elseif ('open' === $kind && 'lead' === $contact['lifecycle']) {
            $to = 'prospect';
        }

        if (null !== $to) {
            $this->contacts->update($contact_id, ['lifecycle' => $to]);
        }
    }

    private function log(int $deal_id, int $contact_id, string $text, int $author_id): void
    {
        $this->activities->log([
            'contact_id' => $contact_id,
            'deal_id'    => $deal_id,
            'type'       => 'stage',
            'subject'    => mb_substr($text, 0, 190),
            'author_id'  => $author_id > 0 ? $author_id : get_current_user_id(),
            'done_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function error(string $code, string $message, string $field = '', int $status = 422): WP_Error
    {
        return new WP_Error($code, $message, ['status' => $status, 'field' => $field]);
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            vkl('logger')->error('CRM deal automation failed.', ['error' => $e->getMessage()]);
        }
    }
}
