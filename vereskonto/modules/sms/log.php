<?php
/**
 * SMS log repository — owns wp_vkl_sms_log.
 * Promoted from modules/cart/logger.php in 1.5.0 exactly as documented
 * in the 1.1.0 upgrade path. Class name and schema unchanged.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.1.0 (as cart module), 1.5.0 (promoted here)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Log_Repository
 */
final class VKL_Sms_Log_Repository extends VKL_Repository
{
    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    protected function table_name(): string
    {
        return 'sms_log';
    }

    /**
     * Register schema migrations.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.1.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('sms_log') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    mobile VARCHAR(20) NOT NULL,
                    type VARCHAR(30) NOT NULL,
                    pattern_code VARCHAR(50) NOT NULL DEFAULT '',
                    payload LONGTEXT NULL,
                    status VARCHAR(10) NOT NULL,
                    response TEXT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY mobile (mobile),
                    KEY type_created (type, created_at),
                    KEY status (status),
                    KEY created_at (created_at)
                ) {$charset};"
            );
        });

        // 1.6.0: multi-channel support (sms | whatsapp). dbDelta adds the
        // new column on existing installs without touching data.
        $migrator->register('1.6.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('sms_log') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    mobile VARCHAR(20) NOT NULL,
                    channel VARCHAR(16) NOT NULL DEFAULT 'sms',
                    type VARCHAR(30) NOT NULL,
                    pattern_code VARCHAR(50) NOT NULL DEFAULT '',
                    payload LONGTEXT NULL,
                    status VARCHAR(10) NOT NULL,
                    response TEXT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY mobile (mobile),
                    KEY type_created (type, created_at),
                    KEY status (status),
                    KEY created_at (created_at)
                ) {$charset};"
            );
        });
    }

    /**
     * Record an SMS delivery attempt.
     *
     * @param array<string, mixed> $payload Pattern parameters sent.
     */
    public function record(
        string $mobile,
        string $type,
        string $pattern_code,
        array $payload,
        string $status,
        string $response = '',
        string $channel = 'sms'
    ): void {
        $this->insert_row(
            [
                'mobile'       => $mobile,
                'channel'      => $channel,
                'type'         => $type,
                'pattern_code' => $pattern_code,
                'payload'      => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
                'status'       => $status,
                'response'     => mb_substr($response, 0, 5000),
                'created_at'   => $this->now(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Messages of a type sent to a mobile within the look-back window.
     */
    public function count_recent(string $mobile, string $type, int $hours): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * HOUR_IN_SECONDS));

        return (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {sms_log}
             WHERE mobile = %s AND type = %s AND status = %s AND created_at > %s",
            $mobile,
            $type,
            self::STATUS_SENT,
            $cutoff
        );
    }

    /**
     * Latest log rows for the admin screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 50): array
    {
        return $this->db->get_results(
            'SELECT * FROM {sms_log} ORDER BY id DESC LIMIT %d',
            $limit
        );
    }

    /**
     * Paged, filterable history for the admin screen.
     *
     * @param int    $page        1-based page.
     * @param int    $per_page    Rows per page.
     * @param string $type_prefix Type prefix filter ('' = all), e.g. 'order.'.
     * @param string $channel     Channel filter ('' = all).
     * @param string $mobile      Mobile substring search ('' = none).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function paged(int $page, int $per_page = 30, string $type_prefix = '', string $channel = '', string $mobile = ''): array
    {
        $where = [];
        $args  = [];

        if ('' !== $type_prefix) {
            $where[] = 'type LIKE %s';
            $args[]  = $type_prefix . '%';
        }

        if ('' !== $channel) {
            $where[] = 'channel = %s';
            $args[]  = $channel;
        }

        if ('' !== $mobile) {
            $where[] = 'mobile LIKE %s';
            $args[]  = '%' . $mobile . '%';
        }

        $clause = [] !== $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $total = (int) $this->db->get_var('SELECT COUNT(*) FROM {sms_log}' . $clause, ...$args);

        $args[] = $per_page;
        $args[] = max(0, ($page - 1) * $per_page);

        $rows = $this->db->get_results(
            'SELECT * FROM {sms_log}' . $clause . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            ...$args
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Messages per status since a UTC time ('Y-m-d H:i:s').
     *
     * @return array<string, int> status => count
     */
    public function since_summary(string $since_utc): array
    {
        $out = [];

        foreach ($this->db->get_results('SELECT status, COUNT(*) AS n FROM {sms_log} WHERE created_at >= %s GROUP BY status', $since_utc) as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Sent / failed counts per pattern code over the last N days.
     *
     * @return array<string, array{sent:int, failed:int}> pattern code => counts
     */
    public function pattern_stats(int $days = 30): array
    {
        $out  = [];
        $rows = $this->db->get_results(
            'SELECT pattern_code, status, COUNT(*) AS n FROM {sms_log} WHERE created_at >= %s GROUP BY pattern_code, status',
            gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS)
        );

        foreach ($rows as $row) {
            $code = (string) $row['pattern_code'];
            $out[$code] = $out[$code] ?? ['sent' => 0, 'failed' => 0];
            $key  = self::STATUS_SENT === (string) $row['status'] ? 'sent' : 'failed';
            $out[$code][$key] += (int) $row['n'];
        }

        return $out;
    }

    /**
     * Delete rows older than N days (0 = delete everything).
     *
     * @return int Rows removed.
     */
    public function purge(int $days): int
    {
        if ($days <= 0) {
            return $this->db->query('DELETE FROM {sms_log}');
        }

        return $this->db->query(
            'DELETE FROM {sms_log} WHERE created_at < %s',
            gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)
        );
    }

    /**
     * Iterate all rows matching the filters (for CSV export), in id order.
     *
     * @return iterable<array<string, mixed>>
     */
    public function iterate(string $type_prefix = '', string $channel = '', string $mobile = ''): iterable
    {
        $cursor = 0;

        do {
            $where = ['id > %d'];
            $args  = [$cursor];

            if ('' !== $type_prefix) {
                $where[] = 'type LIKE %s';
                $args[]  = $type_prefix . '%';
            }

            if ('' !== $channel) {
                $where[] = 'channel = %s';
                $args[]  = $channel;
            }

            if ('' !== $mobile) {
                $where[] = 'mobile LIKE %s';
                $args[]  = '%' . $mobile . '%';
            }

            $args[] = 500;

            $rows = $this->db->get_results(
                'SELECT * FROM {sms_log} WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT %d',
                ...$args
            );

            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                yield $row;
            }
        } while ([] !== $rows);
    }

    /**
     * Latest failed rows for a type prefix (campaign error surfacing).
     *
     * @return array<int, array<string, mixed>>
     */
    public function failures_for(string $type_prefix, int $limit = 3): array
    {
        return $this->db->get_results(
            "SELECT mobile, response, created_at FROM {sms_log}
             WHERE type LIKE %s AND status NOT IN ('sent', 'ok', 'success')
             ORDER BY id DESC LIMIT %d",
            addcslashes($type_prefix, '%_\\') . '%',
            $limit
        );
    }

    /**
     * One log row by id (for resend).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->get_row('SELECT * FROM {sms_log} WHERE id = %d', $id);
    }
}
