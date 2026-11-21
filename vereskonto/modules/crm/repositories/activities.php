<?php
/**
 * The contact timeline: notes, calls, meetings, messages and system
 * events in one table.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Activities
 */
final class VKL_Crm_Activities extends VKL_Crm_Repository
{
    /** Written by people. */
    public const MANUAL_TYPES = ['note', 'call', 'meeting', 'email', 'sms'];

    /** Written by the system from events elsewhere in the plugin. */
    public const SYSTEM_TYPES = ['order', 'ticket', 'return', 'wallet', 'installment', 'loyalty', 'stage', 'task', 'system'];

    public const DIRECTIONS = ['', 'in', 'out'];

    protected function table_name(): string
    {
        return 'crm_activities';
    }

    protected function columns(): array
    {
        return [
            'contact_id'   => '%d',
            'deal_id'      => '%d',
            'type'         => '%s',
            'direction'    => '%s',
            'subject'      => '%s',
            'body'         => '%s',
            'outcome'      => '%s',
            'duration'     => '%d',
            'scheduled_at' => '%s',
            'done_at'      => '%s',
            'author_id'    => '%d',
            'ref_type'     => '%s',
            'ref_id'       => '%d',
            'dedupe_key'   => '%s',
        ];
    }

    /**
     * Log an activity. With a dedupe_key the same event is stored once:
     * a second call returns the existing row's id instead of inserting.
     *
     * @param array<string, mixed> $data
     * @return int Activity id (new or existing), 0 on failure.
     */
    public function log(array $data): int
    {
        $type = (string) ($data['type'] ?? '');

        if ((int) ($data['contact_id'] ?? 0) <= 0 || !in_array($type, array_merge(self::MANUAL_TYPES, self::SYSTEM_TYPES), true)) {
            return 0;
        }

        $key = isset($data['dedupe_key']) ? substr((string) $data['dedupe_key'], 0, 64) : '';

        if ('' !== $key) {
            $existing = $this->db->get_var('SELECT id FROM {crm_activities} WHERE dedupe_key = %s', $key);

            if (null !== $existing) {
                return (int) $existing;
            }

            $data['dedupe_key'] = $key;
        } else {
            unset($data['dedupe_key']); // NULL, so the UNIQUE key ignores it.
        }

        $id = $this->create($data);

        // Lost a race with a parallel request logging the same event.
        if (0 === $id && '' !== $key) {
            return (int) $this->db->get_var('SELECT id FROM {crm_activities} WHERE dedupe_key = %s', $key);
        }

        if ($id > 0) {
            $this->db->update(
                'crm_contacts',
                ['last_activity_at' => $this->now()],
                ['id' => (int) $data['contact_id']],
                ['%s'],
                ['%d']
            );
        }

        return $id;
    }

    /**
     * Newest-first timeline with keyset paging (pass the last id seen).
     *
     * @param string[] $types Limit to these types (empty = all).
     * @return array<int, array<string, mixed>>
     */
    public function timeline(int $contact_id, int $limit = 30, int $before_id = 0, array $types = []): array
    {
        $where = ['contact_id = %d', 'deleted_at IS NULL'];
        $args  = [$contact_id];

        if ($before_id > 0) {
            $where[] = 'id < %d';
            $args[]  = $before_id;
        }

        $types = array_values(array_intersect($types, array_merge(self::MANUAL_TYPES, self::SYSTEM_TYPES)));

        if ([] !== $types) {
            $where[] = 'type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
            $args    = array_merge($args, $types);
        }

        $args[] = max(1, min(self::MAX_PER_PAGE, $limit));

        return array_map([$this, 'hydrate'], $this->db->get_results(
            'SELECT * FROM {crm_activities} WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d',
            ...$args
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function for_deal(int $deal_id, int $limit = 50): array
    {
        return array_map([$this, 'hydrate'], $this->db->get_results(
            'SELECT * FROM {crm_activities} WHERE deal_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d',
            $deal_id,
            max(1, min(self::MAX_PER_PAGE, $limit))
        ));
    }

    /**
     * Activity counts per type and author in a date range (reports).
     *
     * @return array<int, array{type: string, author_id: int, n: int}>
     */
    public function counts(string $from_utc, string $to_utc): array
    {
        return array_map(static fn(array $r): array => [
            'type'      => (string) $r['type'],
            'author_id' => (int) $r['author_id'],
            'n'         => (int) $r['n'],
        ], $this->db->get_results(
            'SELECT type, author_id, COUNT(*) AS n FROM {crm_activities}
             WHERE deleted_at IS NULL AND created_at BETWEEN %s AND %s
             GROUP BY type, author_id',
            $from_utc,
            $to_utc
        ));
    }
}
