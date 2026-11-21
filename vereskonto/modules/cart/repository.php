<?php
/**
 * Cart repository.
 *
 * Owns the wp_vkl_cart (current snapshot per cart) and wp_vkl_cart_history
 * (append-only action log) tables. Every SQL statement of the cart module
 * lives in this file.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Repository
 */
final class VKL_Cart_Repository extends VKL_Repository
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_RECOVERED = 'recovered';
    public const STATUS_CONVERTED = 'converted';

    /**
     * {@inheritDoc}
     */
    protected function table_name(): string
    {
        return 'cart';
    }

    /**
     * Register schema migrations for this module.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.1.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('cart') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    cart_token VARCHAR(64) NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mobile VARCHAR(20) NOT NULL DEFAULT '',
                    items LONGTEXT NULL,
                    item_count INT UNSIGNED NOT NULL DEFAULT 0,
                    total DECIMAL(18,4) NOT NULL DEFAULT 0,
                    currency VARCHAR(10) NOT NULL DEFAULT '',
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    notified_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY cart_token (cart_token),
                    KEY user_id (user_id),
                    KEY mobile (mobile),
                    KEY status_updated (status, updated_at)
                ) {$charset};"
            );

            dbDelta(
                'CREATE TABLE ' . $db->table('cart_history') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    cart_token VARCHAR(64) NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    mobile VARCHAR(20) NOT NULL DEFAULT '',
                    action VARCHAR(20) NOT NULL,
                    product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    quantity INT NOT NULL DEFAULT 0,
                    cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY cart_token (cart_token),
                    KEY mobile (mobile),
                    KEY action_created (action, created_at),
                    KEY created_at (created_at)
                ) {$charset};"
            );
        });
    }

    /**
     * Insert or update the snapshot for a cart token.
     *
     * @param array{
     *     cart_token: string,
     *     user_id: int,
     *     mobile: string,
     *     items: array,
     *     item_count: int,
     *     total: float,
     *     currency: string
     * } $snapshot Cart snapshot.
     *
     * @return string Previous status ('' when the cart is new).
     */
    public function save_snapshot(array $snapshot): string
    {
        $existing = $this->db->get_row(
            'SELECT id, status FROM {cart} WHERE cart_token = %s',
            $snapshot['cart_token']
        );

        $now  = $this->now();
        $data = [
            'user_id'    => $snapshot['user_id'],
            'mobile'     => $snapshot['mobile'],
            'items'      => wp_json_encode($snapshot['items'], JSON_UNESCAPED_UNICODE),
            'item_count' => $snapshot['item_count'],
            'total'      => $snapshot['total'],
            'currency'   => $snapshot['currency'],
            'status'     => self::STATUS_ACTIVE,
            'updated_at' => $now,
        ];

        if (null === $existing) {
            $data['cart_token'] = $snapshot['cart_token'];
            $data['created_at'] = $now;

            $this->db->insert(
                'cart',
                $data,
                ['%d', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s']
            );

            return '';
        }

        // Activity on a previously abandoned cart clears the notification
        // lock so a future abandonment can notify again.
        if (self::STATUS_ABANDONED === $existing['status']) {
            $data['notified_at'] = null;
        }

        $this->db->update(
            'cart',
            $data,
            ['id' => (int) $existing['id']],
            [],
            ['%d']
        );

        return (string) $existing['status'];
    }

    /**
     * Mark a cart converted (order placed).
     *
     * @param string $cart_token Cart token.
     */
    public function mark_converted(string $cart_token): void
    {
        $this->db->update(
            'cart',
            [
                'status'     => self::STATUS_CONVERTED,
                'updated_at' => $this->now(),
            ],
            ['cart_token' => $cart_token],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Mark a cart abandoned and record the notification timestamp.
     *
     * Uses a conditional UPDATE as an atomic claim so two overlapping cron
     * runs can never notify the same cart twice.
     *
     * @param int $cart_id Cart row id.
     *
     * @return bool True when this call claimed the cart.
     */
    public function claim_abandoned(int $cart_id): bool
    {
        // Atomic notify-claim: the truth pass has already flipped stale
        // carts to abandoned, so the claim is on notified_at alone —
        // exactly one worker wins the row.
        $affected = $this->db->query(
            "UPDATE {cart}
             SET status = %s, notified_at = %s
             WHERE id = %d AND notified_at IS NULL",
            self::STATUS_ABANDONED,
            $this->now(),
            $cart_id
        );

        return 1 === $affected;
    }

    /**
     * Find active, non-empty carts with a mobile number and no activity
     * since the cutoff. Batched.
     *
     * @param int $idle_minutes Minutes of inactivity before abandonment.
     * @param int $limit        Batch size.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Truth pass: any active cart idle past the threshold IS abandoned,
     * whether or not we can message it. Messaging eligibility is a
     * separate question — this is what kept "active" artificially huge
     * and the messaged counter frozen.
     */
    public function mark_stale_abandoned(int $idle_minutes): int
    {
        return (int) $this->db->query(
            'UPDATE {cart} SET status = %s
             WHERE status = %s AND item_count > 0 AND updated_at < %s',
            self::STATUS_ABANDONED,
            self::STATUS_ACTIVE,
            gmdate('Y-m-d H:i:s', time() - max(5, $idle_minutes) * MINUTE_IN_SECONDS)
        );
    }

    public function find_abandonment_candidates(int $idle_minutes, int $limit): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($idle_minutes * MINUTE_IN_SECONDS));

        return $this->db->get_results(
            "SELECT * FROM {cart}
             WHERE status = %s
               AND item_count > 0
               AND mobile <> ''
               AND notified_at IS NULL
               AND updated_at < %s
             ORDER BY updated_at ASC
             LIMIT %d",
            self::STATUS_ABANDONED,
            $cutoff,
            $limit
        );
    }

    /**
     * Append a row to the cart history log.
     *
     * @param string $cart_token Cart token.
     * @param int    $user_id    User id (0 for guests).
     * @param string $mobile     Mobile ('' when unknown).
     * @param string $action     Action: add|update|remove|restore|empty|converted|abandoned|recovered.
     * @param int    $product_id Product id (0 when not item-specific).
     * @param int    $quantity   Quantity involved.
     * @param float  $cart_total Cart total after the action.
     */
    /**
     * Was this exact event already recorded moments ago?
     */
    private function history_is_repeat(
        string $cart_token,
        string $action,
        int $product_id,
        int $quantity,
        int $window = 120
    ): bool {
        $last = $this->db->get_row(
            "SELECT action, product_id, quantity FROM {cart_history}
             WHERE cart_token = %s AND created_at > %s
             ORDER BY id DESC LIMIT 1",
            $cart_token,
            gmdate('Y-m-d H:i:s', time() - max(10, $window))
        );

        if (!is_array($last)) {
            return false;
        }

        return (string) $last['action'] === $action
            && (int) $last['product_id'] === $product_id
            && (int) $last['quantity'] === $quantity;
    }

    public function add_history(
        string $cart_token,
        int $user_id,
        string $mobile,
        string $action,
        int $product_id = 0,
        int $quantity = 0,
        float $cart_total = 0.0
    ): void {
        // Skip a repeat of the same event on the same cart within a short
        // window. Reloading a product page or a double-tap on "add" used
        // to append an identical row every time — that is where a good
        // part of the 90k rows came from, and none of it says anything
        // new about what the customer did.
        if ($this->history_is_repeat($cart_token, $action, $product_id, $quantity)) {
            return;
        }

        $this->db->insert(
            'cart_history',
            [
                'cart_token' => $cart_token,
                'user_id'    => $user_id,
                'mobile'     => $mobile,
                'action'     => $action,
                'product_id' => $product_id,
                'quantity'   => $quantity,
                'cart_total' => $cart_total,
                'created_at' => $this->now(),
            ],
            ['%s', '%d', '%s', '%s', '%d', '%d', '%f', '%s']
        );
    }

    /**
     * Record that a recovery message was sent for this cart (manual send).
     *
     * @param string $cart_token Cart token.
     */
    public function mark_notified(string $cart_token): void
    {
        $this->db->query(
            'UPDATE {cart} SET notified_at = %s WHERE cart_token = %s',
            $this->now(),
            $cart_token
        );
    }

    /**
     * Find one cart by primary key (admin manual send).
     *
     * @param int $id Cart row id.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->get_row('SELECT * FROM {cart} WHERE id = %d', $id);

        return is_array($row) ? $row : null;
    }

    /**
     * Latest cart snapshots for the admin screen.
     *
     * @param int $limit  Page size.
     * @param int $offset Offset.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20, int $offset = 0, string $status = '', ?bool $notified = null, int $product_id = 0, string $mobile = ''): array
    {
        $where = [];
        $args  = [];

        if ('' !== $status) {
            $where[] = 'status = %s';
            $args[]  = $status;
        }

        if (true === $notified) {
            $where[] = 'notified_at IS NOT NULL';
        } elseif (false === $notified) {
            $where[] = 'notified_at IS NULL';
        }

        if ($product_id > 0) {
            // Items are stored as JSON; the product id key is exact enough
            // for grouping ("which abandoned carts contain product X").
            $where[] = 'items LIKE %s';
            $args[]  = '%"product_id":' . $product_id . ',%';
        }

        if ('' !== $mobile) {
            $where[] = 'mobile LIKE %s';
            $args[]  = '%' . $mobile . '%';
        }

        $sql = 'SELECT * FROM {cart}'
            . ([] !== $where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';

        $args[] = $limit;
        $args[] = $offset;

        return $this->db->get_results($sql, ...$args);
    }

    /**
     * Products appearing in abandoned carts, with cart counts —
     * decoded from a bounded sample so the page stays fast.
     *
     * @return array<int, array{product_id: int, name: string, carts: int}>
     */
    public function abandoned_products(int $sample = 200): array
    {
        $rows = $this->db->get_results(
            'SELECT items FROM {cart} WHERE status = %s ORDER BY updated_at DESC LIMIT %d',
            self::STATUS_ABANDONED,
            $sample
        );

        $found = [];

        foreach ($rows as $row) {
            $items = json_decode((string) ($row['items'] ?? ''), true);

            if (!is_array($items)) {
                continue;
            }

            $seen = [];

            foreach ($items as $item) {
                $pid = (int) ($item['product_id'] ?? 0);

                if ($pid <= 0 || isset($seen[$pid])) {
                    continue;
                }

                $seen[$pid] = true;

                if (!isset($found[$pid])) {
                    $found[$pid] = ['product_id' => $pid, 'name' => (string) ($item['name'] ?? ('#' . $pid)), 'carts' => 0];
                }

                ++$found[$pid]['carts'];
            }
        }

        usort($found, static fn(array $a, array $b): int => $b['carts'] <=> $a['carts']);

        return array_slice(array_values($found), 0, 25);
    }

    /**
     * Number of abandoned carts that have already been notified.
     */
    public function count_notified(): int
    {
        return (int) $this->db->get_var(
            'SELECT COUNT(*) FROM {cart} WHERE status = %s AND notified_at IS NOT NULL',
            self::STATUS_ABANDONED
        );
    }

    /**
     * Count carts per status for the admin screen.
     *
     * @return array<string, int>
     */
    public function count_by_status(): array
    {
        $rows = $this->db->get_results(
            'SELECT status, COUNT(*) AS total FROM {cart} GROUP BY status'
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Remove one cart row (and nothing else) by token.
     */
    public function purge_token(string $cart_token): void
    {
        $this->db->delete('cart', ['cart_token' => $cart_token], ['%s']);
    }

    /**
     * Retention: delete empty carts and anything not touched for N days.
     * Converted/recovered numbers live in the orders themselves, so old
     * rows here are pure noise — this is what keeps the stats honest.
     *
     * @return int Rows removed.
     */
    /**
     * Delete non-active (abandoned / recovered / converted) carts older
     * than N days. Powers both the manual button and the automatic
     * purge; 0 days = only the manual "right now" sweep uses it.
     */
    public function purge_inactive(int $days): int
    {
        return (int) $this->db->query(
            "DELETE FROM {cart} WHERE status <> %s AND updated_at < %s",
            self::STATUS_ACTIVE,
            gmdate('Y-m-d H:i:s', time() - max(0, $days) * DAY_IN_SECONDS)
        );
    }

    public function cleanup(int $retention_days): int
    {
        $removed = $this->db->query('DELETE FROM {cart} WHERE item_count = 0');

        // Guest rows with no mobile can never be messaged or attributed —
        // after a 2-day grace (a live session may still convert) they are
        // noise, and in practice mostly bots that slipped past the gate.
        $removed += $this->db->query(
            "DELETE FROM {cart} WHERE user_id = 0 AND (mobile = '' OR mobile IS NULL) AND updated_at < %s",
            gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)
        );

        // Optional automatic purge of ALL inactive carts after a
        // configured number of days (0 = keep until retention).
        $purge_days = (int) apply_filters('vkl_cart_purge_days', vkl('config')->get_int('cart_purge_days', 0));

        if ($purge_days > 0) {
            $removed += $this->purge_inactive($purge_days);
        }

        $removed += $this->db->query(
            'DELETE FROM {cart} WHERE updated_at < %s',
            gmdate('Y-m-d H:i:s', time() - max(7, $retention_days) * DAY_IN_SECONDS)
        );

        return (int) $removed;
    }

    /**
     * One-time 1.18.0 cleanup of the historical pile.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_cleanup_migration(VKL_Migrator $migrator): void
    {
        $migrator->register('1.28.0', static function (): void {
            global $wpdb;

            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}vkl_cart SET status = 'abandoned' WHERE status = 'active' AND item_count > 0 AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS))); // phpcs:ignore
        });

        $migrator->register('1.23.0', static function (): void {
            global $wpdb;

            // One-time sweep of the historical bot pile: unmessageable
            // guest rows older than two days.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}vkl_cart WHERE user_id = 0 AND (mobile = '' OR mobile IS NULL) AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS))); // phpcs:ignore
        });

        $migrator->register('1.18.0', static function (): void {
            global $wpdb;

            $wpdb->query("DELETE FROM {$wpdb->prefix}vkl_cart WHERE item_count = 0"); // phpcs:ignore
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}vkl_cart WHERE updated_at < %s", gmdate('Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS))); // phpcs:ignore
        });
    }
}
