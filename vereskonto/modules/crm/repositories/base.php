<?php
/**
 * Shared base for CRM repositories.
 *
 * Each repository declares its columns and their wpdb formats once;
 * insert/update/find/soft-delete/paging are implemented here, so no CRM
 * table has its own copy of that logic. Only whitelisted columns ever
 * reach SQL, and every value goes through $wpdb->prepare / insert.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Repository
 */
abstract class VKL_Crm_Repository extends VKL_Repository
{
    /** Hard ceiling for any page size. */
    public const MAX_PER_PAGE = 200;

    /**
     * Writable columns => wpdb format (%s, %d, %f). id/created_at/
     * updated_at/deleted_at are managed here and must not be listed.
     *
     * @return array<string, string>
     */
    abstract protected function columns(): array;

    /** Whether the table has created_at/updated_at/deleted_at. */
    protected function has_timestamps(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $with_deleted = false): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT * FROM {{$this->table_name()}} WHERE id = %d" . ($with_deleted || !$this->has_timestamps() ? '' : ' AND deleted_at IS NULL'),
            $id
        );

        return null === $row ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return int New id, 0 on failure.
     */
    public function create(array $data): int
    {
        $row = $this->only_columns($data);

        if ($this->has_timestamps()) {
            $now               = $this->now();
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        return $this->db->insert($this->table_name(), $row, $this->formats_for($row));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $row = $this->only_columns($data);

        if ([] === $row || $id <= 0) {
            return false;
        }

        if ($this->has_timestamps()) {
            $row['updated_at'] = $this->now();
        }

        return $this->db->update($this->table_name(), $row, ['id' => $id], $this->formats_for($row), ['%d']) >= 0;
    }

    public function soft_delete(int $id): bool
    {
        return $id > 0 && $this->db->update(
            $this->table_name(),
            ['deleted_at' => $this->now(), 'updated_at' => $this->now()],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        ) > 0;
    }

    public function restore(int $id): bool
    {
        return $id > 0 && $this->db->update(
            $this->table_name(),
            ['deleted_at' => null, 'updated_at' => $this->now()],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        ) > 0;
    }

    /**
     * Run a filtered, paged SELECT.
     *
     * @param string[]           $where   SQL fragments (with placeholders), ANDed.
     * @param array<int, mixed>  $args    Placeholder values, in order.
     * @param string[]           $sortable Allowed ORDER BY columns.
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    protected function paginate(array $where, array $args, int $page, int $per_page, string $orderby, string $order, array $sortable, string $default_order = 'id', string $from = ''): array
    {
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        $page     = max(1, $page);
        $orderby  = in_array($orderby, $sortable, true) ? $orderby : $default_order;
        $order    = 'ASC' === strtoupper($order) ? 'ASC' : 'DESC';
        $from     = '' !== $from ? $from : "{{$this->table_name()}} t";
        $sql_w    = [] === $where ? '1=1' : implode(' AND ', $where);

        $total = (int) $this->db->get_var("SELECT COUNT(*) FROM {$from} WHERE {$sql_w}", ...$args);

        // Stable order: tie-break on id so pages never overlap.
        $rows = $this->db->get_results(
            "SELECT t.* FROM {$from} WHERE {$sql_w} ORDER BY t.{$orderby} {$order}, t.id {$order} LIMIT %d OFFSET %d",
            ...array_merge($args, [$per_page, ($page - 1) * $per_page])
        );

        return [
            'rows'     => array_map([$this, 'hydrate'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Cast numeric columns so callers (and the REST API) get real ints.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        $formats = $this->columns() + ['id' => '%d'];

        foreach ($row as $key => $value) {
            if (null === $value || !isset($formats[$key])) {
                continue;
            }

            if ('%d' === $formats[$key]) {
                $row[$key] = (int) $value;
            } elseif ('%f' === $formats[$key]) {
                $row[$key] = (float) $value;
            }
        }

        return $row;
    }

    /**
     * Escape a value for use inside LIKE '%...%'.
     */
    protected function like(string $value): string
    {
        global $wpdb;

        return '%' . $wpdb->esc_like($value) . '%';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function only_columns(array $data): array
    {
        return array_intersect_key($data, $this->columns());
    }

    /**
     * @param array<string, mixed> $row
     * @return string[]
     */
    private function formats_for(array $row): array
    {
        $formats = $this->columns() + ['created_at' => '%s', 'updated_at' => '%s'];

        return array_map(static fn(string $col): string => $formats[$col] ?? '%s', array_keys($row));
    }
}
