<?php
/**
 * Loyalty repository — append-only points ledger in wp_vkl_points.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Loyalty_Repository
 */
final class VKL_Loyalty_Repository extends VKL_Repository
{
    protected function table_name(): string
    {
        return 'points';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.5.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('points') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    points INT NOT NULL,
                    reason VARCHAR(50) NOT NULL,
                    reference VARCHAR(100) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY reference (reference)
                ) {$charset};"
            );
        });
    }

    public function balance(int $user_id): int
    {
        return (int) ($this->db->get_var(
            'SELECT COALESCE(SUM(points), 0) FROM {points} WHERE user_id = %d',
            $user_id
        ) ?? 0);
    }

    public function append(int $user_id, int $points, string $reason, string $reference = ''): int
    {
        return $this->insert_row(
            [
                'user_id'    => $user_id,
                'points'     => $points,
                'reason'     => $reason,
                'reference'  => $reference,
                'created_at' => $this->now(),
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }

    /** Idempotency: has this reference already earned points? */
    public function exists(string $reason, string $reference): bool
    {
        return null !== $this->db->get_var(
            'SELECT id FROM {points} WHERE reason = %s AND reference = %s LIMIT 1',
            $reason,
            $reference
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function latest_for_user(int $user_id, int $limit = 20): array
    {
        return $this->db->get_results(
            'SELECT * FROM {points} WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            $user_id,
            $limit
        );
    }

    /**
     * Club members ranked by balance, with this year's earned points
     * (a direct proxy for this year's purchases, since points are
     * granted per completed-order amount).
     *
     * @param int $limit Max members.
     * @param int $year  Calendar year for the yearly figures.
     *
     * @return array<int, array<string, mixed>>
     */
    public function members(int $limit = 20, int $year = 0, int $offset = 0, array $only_ids = []): array
    {
        $year = $year > 0 ? $year : (int) gmdate('Y');

        // Optional search filter: restrict to a resolved set of user ids.
        $filter = '';
        $args   = [$year];

        if ([] !== $only_ids) {
            $ids    = implode(',', array_map('intval', $only_ids));
            $filter = "WHERE user_id IN ({$ids})";
        }

        $args[] = $limit;
        $args[] = max(0, $offset);

        return $this->db->get_results(
            "SELECT user_id,
                    SUM(points) AS balance,
                    SUM(CASE WHEN reason = 'order_completed' AND YEAR(created_at) = %d THEN points ELSE 0 END) AS year_points
             FROM {points}
             {$filter}
             GROUP BY user_id
             HAVING balance > 0
             ORDER BY year_points DESC, balance DESC
             LIMIT %d OFFSET %d",
            ...$args
        );
    }

    /**
     * First points-ledger entry for a user (proxy for "member since").
     */
    public function member_since(int $user_id): string
    {
        return (string) $this->db->get_var(
            'SELECT MIN(created_at) FROM {points} WHERE user_id = %d',
            $user_id
        );
    }

    /**
     * Lifetime and yearly order counts + spend for one member, read from
     * the points ledger (order_completed rows carry the spend basis).
     *
     * @return array{orders: int, year_orders: int}
     */
    public function member_activity(int $user_id, int $year = 0): array
    {
        $year = $year > 0 ? $year : (int) gmdate('Y');

        $row = $this->db->get_row(
            "SELECT COUNT(*) AS orders,
                    SUM(CASE WHEN YEAR(created_at) = %d THEN 1 ELSE 0 END) AS year_orders
             FROM {points}
             WHERE user_id = %d AND reason = 'order_completed'",
            $year,
            $user_id
        );

        return [
            'orders'      => (int) ($row['orders'] ?? 0),
            'year_orders' => (int) ($row['year_orders'] ?? 0),
        ];
    }

    /**
     * How many members hold a positive balance (pagination total).
     */
    public function members_count(): int
    {
        return (int) $this->db->get_var(
            'SELECT COUNT(*) FROM (
                SELECT user_id FROM {points} GROUP BY user_id HAVING SUM(points) > 0
             ) AS m'
        );
    }

    /**
     * Points earned by one user in a calendar year (tier basis).
     */
    public function year_points(int $user_id, int $year = 0): int
    {
        $year = $year > 0 ? $year : (int) gmdate('Y');

        return (int) $this->db->get_var(
            "SELECT COALESCE(SUM(points), 0) FROM {points}
             WHERE user_id = %d AND reason = 'order_completed' AND YEAR(created_at) = %d",
            $user_id,
            $year
        );
    }
}
