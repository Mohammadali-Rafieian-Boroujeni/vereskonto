<?php
/**
 * Order return requests — owns wp_vkl_order_returns and
 * wp_vkl_order_return_items. A customer requests specific line items
 * (and quantities) back from an order they already received; admin
 * approves or rejects from the order screen, then processes the actual
 * refund through WooCommerce's own per-item refund tool.
 *
 * @package Vereskonto\Modules\Returns
 * @since   2.9.20
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Returns_Repository
 */
final class VKL_Returns_Repository extends VKL_Repository
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected function table_name(): string
    {
        return 'order_returns';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('2.9.20', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('order_returns') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    order_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL,
                    status VARCHAR(15) NOT NULL DEFAULT 'pending',
                    reason TEXT NOT NULL,
                    admin_note TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY order_id (order_id),
                    KEY user_id (user_id),
                    KEY status (status)
                ) {$charset};"
            );

            dbDelta(
                'CREATE TABLE ' . $db->table('order_return_items') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    return_id BIGINT UNSIGNED NOT NULL,
                    order_item_id BIGINT UNSIGNED NOT NULL,
                    product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    qty INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY  (id),
                    KEY return_id (return_id),
                    KEY order_item_id (order_item_id)
                ) {$charset};"
            );
        });
    }

    /**
     * Create a return request with its items in one call.
     *
     * @param array<int, array{order_item_id: int, product_id: int, qty: int}> $items
     *
     * @return int Return id, 0 on failure.
     */
    public function create(int $order_id, int $user_id, string $reason, array $items): int
    {
        if ([] === $items) {
            return 0;
        }

        $now = $this->now();

        $return_id = $this->insert_row(
            [
                'order_id'   => $order_id,
                'user_id'    => $user_id,
                'status'     => self::STATUS_PENDING,
                'reason'     => $reason,
                'admin_note' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($return_id <= 0) {
            return 0;
        }

        foreach ($items as $item) {
            $this->db->insert(
                'order_return_items',
                [
                    'return_id'     => $return_id,
                    'order_item_id' => (int) $item['order_item_id'],
                    'product_id'    => (int) $item['product_id'],
                    'qty'           => (int) $item['qty'],
                ],
                ['%d', '%d', '%d', '%d']
            );
        }

        return $return_id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $return_id): ?array
    {
        return $this->db->get_row('SELECT * FROM {order_returns} WHERE id = %d', $return_id);
    }

    /** @return array<int, array<string, mixed>> */
    public function items(int $return_id): array
    {
        return $this->db->get_results(
            'SELECT * FROM {order_return_items} WHERE return_id = %d ORDER BY id ASC',
            $return_id
        );
    }

    /**
     * All return requests for one order, newest first, each with its
     * items attached under the 'items' key.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_order(int $order_id): array
    {
        $rows = $this->db->get_results(
            'SELECT * FROM {order_returns} WHERE order_id = %d ORDER BY id DESC',
            $order_id
        );

        foreach ($rows as &$row) {
            $row['items'] = $this->items((int) $row['id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * All of one customer's own requests, across every order, newest
     * first — the My Account overview tab.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_user(int $user_id, int $limit = 30): array
    {
        $rows = $this->db->get_results(
            'SELECT * FROM {order_returns} WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            $user_id,
            $limit
        );

        foreach ($rows as &$row) {
            $row['items'] = $this->items((int) $row['id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Quantity of one order item already covered by a non-rejected
     * return request (pending or approved) — the cap on how much more
     * of it the customer may still request.
     */
    public function requested_qty_for_item(int $order_item_id): int
    {
        return (int) $this->db->get_var(
            'SELECT COALESCE(SUM(ri.qty), 0)
             FROM {order_return_items} ri
             INNER JOIN {order_returns} r ON r.id = ri.return_id
             WHERE ri.order_item_id = %d AND r.status != %s',
            $order_item_id,
            self::STATUS_REJECTED
        );
    }

    /**
     * Move a pending request to approved/rejected.
     */
    public function set_status(int $return_id, string $status, string $admin_note = ''): bool
    {
        if (!in_array($status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            return false;
        }

        $updated = $this->db->update(
            'order_returns',
            ['status' => $status, 'admin_note' => $admin_note, 'updated_at' => $this->now()],
            ['id' => $return_id, 'status' => self::STATUS_PENDING],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );

        return $updated > 0;
    }

    /**
     * Pending requests count — used for the admin menu badge.
     */
    public function pending_count(): int
    {
        return (int) $this->db->get_var(
            'SELECT COUNT(*) FROM {order_returns} WHERE status = %s',
            self::STATUS_PENDING
        );
    }

    /**
     * Latest requests across all orders, for the admin overview list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 30, string $status = ''): array
    {
        if ('' !== $status) {
            $rows = $this->db->get_results(
                'SELECT * FROM {order_returns} WHERE status = %s ORDER BY id DESC LIMIT %d',
                $status,
                $limit
            );
        } else {
            $rows = $this->db->get_results(
                'SELECT * FROM {order_returns} ORDER BY id DESC LIMIT %d',
                $limit
            );
        }

        foreach ($rows as &$row) {
            $row['items'] = $this->items((int) $row['id']);
        }
        unset($row);

        return $rows;
    }
}
