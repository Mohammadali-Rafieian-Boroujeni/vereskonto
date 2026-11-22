<?php
/**
 * Automatic leads from places the shop already collects mobile numbers:
 *
 *   - chatbot conversations where a guest gave their number;
 *   - abandoned carts with a mobile number.
 *
 * Both modules are left untouched: this job reads their tables on a
 * schedule (hourly WP-Cron, plus at most every ten minutes on an admin
 * page load when cron does not fire). Chat messages are followed with an
 * id cursor; each cart is handled once, remembered through the timeline
 * entry's dedupe key. The first run looks back 30 days, not further.
 *
 * Each source can be switched off in CRM settings. Optionally an
 * abandoned cart also opens a deal (once per contact while one is open).
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.17.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Lead_Capture
 */
final class VKL_Crm_Lead_Capture
{
    public const HOOK          = 'vkl_crm_lead_capture';
    public const CURSOR_OPTION = 'vkl_crm_lead_cursor';

    private const LOCK        = 'vkl_crm_lead_lock';
    private const ADMIN_EVERY = 600;
    private const LOOKBACK    = 30 * DAY_IN_SECONDS;

    private VKL_Database $db;
    private VKL_Crm_Contact_Service $contacts;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Deal_Service $deals;
    private VKL_Crm_Deals $deal_repo;
    private VKL_Config $config;

    public function __construct(VKL_Database $db, VKL_Crm_Contact_Service $contacts, VKL_Crm_Activities $activities, VKL_Crm_Deal_Service $deals, VKL_Crm_Deals $deal_repo, VKL_Config $config)
    {
        $this->db         = $db;
        $this->contacts   = $contacts;
        $this->activities = $activities;
        $this->deals      = $deals;
        $this->deal_repo  = $deal_repo;
        $this->config     = $config;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action('admin_init', [$this, 'run_on_admin'], 25);

        // Not before init: scheduling reads every plugin's cron intervals,
        // and those labels are translated — too early triggers WordPress's
        // "translation loaded too early" notice.
        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 300, 'hourly', self::HOOK);
            }
        }, 20);
    }

    public function run_on_admin(): void
    {
        $state = $this->state();

        if (wp_doing_ajax() || time() - (int) $state['last_run'] < self::ADMIN_EVERY || !current_user_can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        $this->run();
    }

    /**
     * @return array{chat: int, last_run: int}
     */
    public function state(): array
    {
        $s = get_option(self::CURSOR_OPTION, []);

        return (is_array($s) ? $s : []) + ['chat' => -1, 'last_run' => 0];
    }

    /**
     * @return array{chat: int, cart: int} New leads per source.
     */
    public function run(): array
    {
        $made = ['chat' => 0, 'cart' => 0];

        if (get_transient(self::LOCK)) {
            return $made;
        }

        set_transient(self::LOCK, 1, 300);

        try {
            if ($this->config->get_bool('crm_lead_chatbot', true)) {
                $made['chat'] = $this->from_chat();
            }

            if ($this->config->get_bool('crm_lead_cart', true)) {
                $made['cart'] = $this->from_carts();
            }
        } catch (Throwable $e) {
            vkl('logger')->error('CRM lead capture failed.', ['error' => $e->getMessage()]);
        } finally {
            $this->save(['last_run' => time()]);
            delete_transient(self::LOCK);
        }

        return $made;
    }

    private function from_chat(): int
    {
        if (!$this->table_exists('chat_messages')) {
            return 0;
        }

        $state  = $this->state();
        $cursor = (int) $state['chat'];

        if ($cursor < 0) {
            // First run: start 30 days back.
            $cursor = (int) $this->db->get_var(
                'SELECT COALESCE(MIN(id), 0) - 1 FROM {chat_messages} WHERE created_at >= %s',
                gmdate('Y-m-d H:i:s', time() - self::LOOKBACK)
            );
            $cursor = max(0, $cursor);
        }

        // Never older than the look-back window, whatever the cursor says.
        $rows = $this->db->get_results(
            "SELECT id, session, user_id, phone, created_at FROM {chat_messages}
             WHERE id > %d AND phone <> '' AND created_at >= %s ORDER BY id ASC LIMIT 500",
            $cursor,
            gmdate('Y-m-d H:i:s', time() - self::LOOKBACK)
        );

        $made = 0;
        $seen = [];

        foreach ($rows as $r) {
            $cursor  = (int) $r['id'];
            $session = (string) $r['session'];

            if (isset($seen[$session]) || (int) $r['user_id'] > 0) {
                continue; // One entry per conversation; members are contacts already.
            }

            $seen[$session] = true;
            $contact_id     = $this->contacts->for_mobile((string) $r['phone'], ['source' => 'chatbot']);

            if ($contact_id > 0 && $this->log($contact_id, 'chat:' . $session, __('Talked to the shop chatbot', 'vereskala-login'), 'chat', 0)) {
                ++$made;
            }
        }

        // Also advance past rows without a phone.
        $max = (int) $this->db->get_var('SELECT COALESCE(MAX(id), 0) FROM {chat_messages}');
        $this->save(['chat' => [] === $rows ? max($cursor, $max) : $cursor]);

        return $made;
    }

    private function from_carts(): int
    {
        if (!$this->table_exists('cart')) {
            return 0;
        }

        $rows = $this->db->get_results(
            "SELECT c.id, c.user_id, c.mobile, c.total, c.item_count
             FROM {cart} c
             LEFT JOIN {crm_activities} a ON a.dedupe_key = CONCAT('cart:', c.id)
             WHERE c.status = 'abandoned' AND c.mobile <> '' AND c.item_count > 0
               AND c.updated_at >= %s AND a.id IS NULL
             ORDER BY c.id ASC LIMIT 200",
            gmdate('Y-m-d H:i:s', time() - self::LOOKBACK)
        );

        $made = 0;

        foreach ($rows as $r) {
            $user_id    = (int) $r['user_id'];
            $contact_id = $user_id > 0 && !VKL_Crm_Contact_Service::is_staff($user_id)
                ? $this->contacts->for_user($user_id)
                : $this->contacts->for_mobile((string) $r['mobile'], ['source' => 'cart']);

            if ($contact_id <= 0) {
                continue;
            }

            $total = (float) $r['total'];
            $text  = sprintf(
                /* translators: 1: cart total, 2: number of items */
                __('Left a cart of %1$s (%2$s items)', 'vereskala-login'),
                function_exists('wc_price') ? trim(wp_strip_all_tags(html_entity_decode(wc_price($total), ENT_QUOTES))) : number_format_i18n($total),
                number_format_i18n((int) $r['item_count'])
            );

            if ($this->log($contact_id, 'cart:' . (int) $r['id'], $text, 'cart', (int) $r['id'])) {
                ++$made;
                $this->maybe_cart_deal($contact_id, $total);
            }
        }

        return $made;
    }

    /**
     * Open a follow-up deal for an abandoned cart (setting), unless the
     * contact already has an open cart deal.
     */
    private function maybe_cart_deal(int $contact_id, float $total): void
    {
        if (!$this->config->get_bool('crm_deal_cart', false)) {
            return;
        }

        foreach ($this->deal_repo->search(['contact_id' => $contact_id, 'status' => 'open'], ['1=1', []], 1, 50)['rows'] as $d) {
            if ('cart' === $d['source']) {
                return;
            }
        }

        $this->deals->create([
            'contact_id' => $contact_id,
            'title'      => __('Abandoned cart follow-up', 'vereskala-login'),
            'value'      => (string) $total,
            'source'     => 'cart',
        ]);
    }

    /**
     * @return bool True when a new entry was written (false: seen before).
     */
    private function log(int $contact_id, string $key, string $text, string $ref_type, int $ref_id): bool
    {
        $key = substr($key, 0, 64);

        if (null !== $this->db->get_var('SELECT id FROM {crm_activities} WHERE dedupe_key = %s', $key)) {
            return false;
        }

        return $this->activities->log([
            'contact_id' => $contact_id,
            'type'       => 'system',
            'subject'    => mb_substr($text, 0, 190),
            'ref_type'   => $ref_type,
            'ref_id'     => $ref_id,
            'dedupe_key' => $key,
            'done_at'    => gmdate('Y-m-d H:i:s'),
        ]) > 0;
    }

    private function table_exists(string $table): bool
    {
        $name = $this->db->table($table);

        return $name === $this->db->get_var('SHOW TABLES LIKE %s', $name);
    }

    /**
     * @param array<string, int> $changes
     */
    private function save(array $changes): void
    {
        update_option(self::CURSOR_OPTION, array_merge($this->state(), $changes), false);
    }
}
