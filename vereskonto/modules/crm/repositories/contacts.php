<?php
/**
 * CRM contacts: leads, prospects and customers, with or without a
 * WordPress account.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Contacts
 */
final class VKL_Crm_Contacts extends VKL_Crm_Repository
{
    public const LIFECYCLES = ['lead', 'prospect', 'customer', 'inactive', 'lost'];

    private const SORTABLE = ['id', 'created_at', 'updated_at', 'last_activity_at', 'last_order_at', 'total_spent', 'orders_count', 'score', 'last_name'];

    protected function table_name(): string
    {
        return 'crm_contacts';
    }

    protected function columns(): array
    {
        return [
            'user_id'          => '%d',
            'first_name'       => '%s',
            'last_name'        => '%s',
            'company'          => '%s',
            'mobile'           => '%s',
            'email'            => '%s',
            'lifecycle'        => '%s',
            'source'           => '%s',
            'owner_id'         => '%d',
            'score'            => '%d',
            'city'             => '%s',
            'birthday'         => '%s',
            'orders_count'     => '%d',
            'total_spent'      => '%f',
            'last_order_at'    => '%s',
            'last_activity_at' => '%s',
        ];
    }

    /**
     * Includes soft-deleted rows: a deleted contact still owns its mobile
     * and user (UNIQUE keys), so creation must find and restore it.
     *
     * @return array<string, mixed>|null
     */
    public function find_by_user(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        $row = $this->db->get_row('SELECT * FROM {crm_contacts} WHERE user_id = %d', $user_id);

        return null === $row ? null : $this->hydrate($row);
    }

    /**
     * @return array<string, mixed>|null Includes soft-deleted rows.
     */
    public function find_by_mobile(string $mobile): ?array
    {
        if ('' === $mobile) {
            return null;
        }

        $row = $this->db->get_row('SELECT * FROM {crm_contacts} WHERE mobile = %s', $mobile);

        return null === $row ? null : $this->hydrate($row);
    }

    /**
     * Filtered, paged list.
     *
     * Filters: q, lifecycle, owner_id (-1 = unassigned), source, city,
     * tag_id, min_spent, max_spent, min_orders, created_from, created_to,
     * active_from, active_to (Y-m-d, UTC), has_user (0/1), deleted (bool).
     *
     * @param array<string, mixed>                    $filters
     * @param array{0: string, 1: array<int, int>}    $scope   From VKL_Crm_Access::scope_sql('t.owner_id').
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(array $filters, array $scope, int $page = 1, int $per_page = 20, string $orderby = 'created_at', string $order = 'DESC'): array
    {
        [$where, $args] = $this->filter_sql($filters);

        $where[] = $scope[0];
        $args    = array_merge($args, $scope[1]);

        return $this->paginate($where, $args, $page, $per_page, $orderby, $order, self::SORTABLE, 'created_at');
    }

    /**
     * Ids only (for bulk actions / export), capped.
     *
     * @param array<string, mixed>                 $filters
     * @param array{0: string, 1: array<int, int>} $scope
     * @return int[]
     */
    public function search_ids(array $filters, array $scope, int $limit = 5000): array
    {
        [$where, $args] = $this->filter_sql($filters);

        $where[] = $scope[0];
        $args    = array_merge($args, $scope[1], [max(1, min(50000, $limit))]);

        $rows = $this->db->get_results('SELECT t.id FROM {crm_contacts} t WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id LIMIT %d', ...$args);

        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Number of contacts per lifecycle stage, within the user's scope.
     *
     * @param array{0: string, 1: array<int, int>} $scope
     * @return array<string, int>
     */
    public function count_by_lifecycle(array $scope): array
    {
        $rows = $this->db->get_results(
            'SELECT t.lifecycle, COUNT(*) AS n FROM {crm_contacts} t WHERE t.deleted_at IS NULL AND ' . $scope[0] . ' GROUP BY t.lifecycle',
            ...$scope[1]
        );

        $out = array_fill_keys(self::LIFECYCLES, 0);

        foreach ($rows as $r) {
            $out[(string) $r['lifecycle']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $f
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private function filter_sql(array $f): array
    {
        $where = [!empty($f['with_deleted']) ? '1=1' : (!empty($f['deleted']) ? 't.deleted_at IS NOT NULL' : 't.deleted_at IS NULL')];
        $args  = [];

        // Sync (mobile app): changed since a time, optionally with deletions.
        if (!empty($f['updated_since']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $f['updated_since'])) {
            $where[] = 't.updated_at >= %s';
            $args[]  = $f['updated_since'];
        }

        $q = trim((string) ($f['q'] ?? ''));

        if ('' !== $q) {
            $digits = preg_replace('/\D+/', '', function_exists('vkl_latin_digits') ? vkl_latin_digits($q) : $q);

            if (strlen((string) $digits) >= 4 && preg_match('/^[\d\s+()\-۰-۹٠-٩]+$/u', $q)) {
                // Phone number: stored as 09xxxxxxxxx; match the tail so
                // "912…", "0912…" and "+98912…" all work.
                $tail    = substr((string) $digits, -10);
                $where[] = 't.mobile LIKE %s';
                $args[]  = $this->like(ltrim($tail, '0'));
            } else {
                $like    = $this->like($q);
                $where[] = "(t.first_name LIKE %s OR t.last_name LIKE %s OR CONCAT(t.first_name, ' ', t.last_name) LIKE %s OR t.email LIKE %s OR t.company LIKE %s)";
                array_push($args, $like, $like, $like, $like, $like);
            }
        }

        if (!empty($f['lifecycle']) && in_array($f['lifecycle'], self::LIFECYCLES, true)) {
            $where[] = 't.lifecycle = %s';
            $args[]  = $f['lifecycle'];
        }

        if (isset($f['owner_id']) && '' !== (string) $f['owner_id']) {
            $where[] = 't.owner_id = %d';
            $args[]  = max(0, (int) $f['owner_id']); // -1 (unassigned) → 0
        }

        foreach (['source', 'city'] as $col) {
            if (!empty($f[$col])) {
                $where[] = "t.{$col} = %s";
                $args[]  = (string) $f[$col];
            }
        }

        if (!empty($f['tag_id'])) {
            $where[] = 't.id IN (SELECT ct.contact_id FROM {crm_contact_tag} ct WHERE ct.tag_id = %d)';
            $args[]  = (int) $f['tag_id'];
        }

        if (isset($f['min_spent']) && '' !== (string) $f['min_spent']) {
            $where[] = 't.total_spent >= %f';
            $args[]  = (float) $f['min_spent'];
        }

        if (isset($f['max_spent']) && '' !== (string) $f['max_spent']) {
            $where[] = 't.total_spent <= %f';
            $args[]  = (float) $f['max_spent'];
        }

        if (isset($f['min_orders']) && '' !== (string) $f['min_orders']) {
            $where[] = 't.orders_count >= %d';
            $args[]  = (int) $f['min_orders'];
        }

        if (isset($f['has_user']) && '' !== (string) $f['has_user']) {
            $where[] = (int) $f['has_user'] ? 't.user_id IS NOT NULL' : 't.user_id IS NULL';
        }

        $ranges = [
            'created_from' => ['t.created_at', '>=', ' 00:00:00'],
            'created_to'   => ['t.created_at', '<=', ' 23:59:59'],
            'active_from'  => ['t.last_activity_at', '>=', ' 00:00:00'],
            'active_to'    => ['t.last_activity_at', '<=', ' 23:59:59'],
        ];

        foreach ($ranges as $key => [$col, $op, $time]) {
            if (!empty($f[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f[$key])) {
                $where[] = "{$col} {$op} %s";
                $args[]  = $f[$key] . $time;
            }
        }

        return [$where, $args];
    }

    /* ---- meta ---------------------------------------------------------- */

    public function get_meta(int $contact_id, string $key): ?string
    {
        $value = $this->db->get_var(
            'SELECT meta_value FROM {crm_contact_meta} WHERE contact_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1',
            $contact_id,
            $key
        );

        return null === $value ? null : (string) $value;
    }

    /**
     * @return array<string, string>
     */
    public function all_meta(int $contact_id): array
    {
        $out = [];

        foreach ($this->db->get_results('SELECT meta_key, meta_value FROM {crm_contact_meta} WHERE contact_id = %d ORDER BY id', $contact_id) as $r) {
            $out[(string) $r['meta_key']] = (string) $r['meta_value'];
        }

        return $out;
    }

    public function set_meta(int $contact_id, string $key, string $value): void
    {
        $key = substr(sanitize_key($key), 0, 100);

        if ($contact_id <= 0 || '' === $key) {
            return;
        }

        $this->db->delete('crm_contact_meta', ['contact_id' => $contact_id, 'meta_key' => $key], ['%d', '%s']);
        $this->db->insert('crm_contact_meta', ['contact_id' => $contact_id, 'meta_key' => $key, 'meta_value' => $value], ['%d', '%s', '%s']);
    }

    public function delete_meta(int $contact_id, string $key): void
    {
        $this->db->delete('crm_contact_meta', ['contact_id' => $contact_id, 'meta_key' => $key], ['%d', '%s']);
    }
}
