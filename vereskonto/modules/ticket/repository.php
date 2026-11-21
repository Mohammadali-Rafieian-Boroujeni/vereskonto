<?php
/**
 * Ticket repository — owns wp_vkl_tickets and wp_vkl_ticket_replies.
 *
 * @package Vereskonto\Modules\Ticket
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Ticket_Repository
 */
final class VKL_Ticket_Repository extends VKL_Repository
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED   = 'closed';

    /**
     * Ticket priorities, lowest to highest. Values are stored as-is;
     * labels are translated at render time.
     */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const DEFAULT_PRIORITY = 'normal';

    protected function table_name(): string
    {
        return 'tickets';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.5.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('tickets') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    subject VARCHAR(190) NOT NULL,
                    status VARCHAR(15) NOT NULL DEFAULT 'open',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY status_updated (status, updated_at)
                ) {$charset};"
            );

            dbDelta(
                'CREATE TABLE ' . $db->table('ticket_replies') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL,
                    is_admin TINYINT(1) NOT NULL DEFAULT 0,
                    content LONGTEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY ticket_id (ticket_id)
                ) {$charset};"
            );
        });

        // 1.7.0: departments (category) + priority. dbDelta adds the new
        // columns and index on existing installs without touching data;
        // old tickets fall back to the column defaults.
        $migrator->register('1.7.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('tickets') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    subject VARCHAR(190) NOT NULL,
                    category VARCHAR(100) NOT NULL DEFAULT '',
                    priority VARCHAR(10) NOT NULL DEFAULT 'normal',
                    status VARCHAR(15) NOT NULL DEFAULT 'open',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY status_updated (status, updated_at),
                    KEY priority (priority)
                ) {$charset};"
            );
        });

        // 2.9.19: customer satisfaction rating (1-5) after close.
        $migrator->register('2.9.19', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('tickets') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    subject VARCHAR(190) NOT NULL,
                    category VARCHAR(100) NOT NULL DEFAULT '',
                    priority VARCHAR(10) NOT NULL DEFAULT 'normal',
                    status VARCHAR(15) NOT NULL DEFAULT 'open',
                    rating TINYINT UNSIGNED NULL DEFAULT NULL,
                    rated_at DATETIME NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY status_updated (status, updated_at),
                    KEY priority (priority)
                ) {$charset};"
            );
        });
    }

    /**
     * Create a ticket with its first message.
     *
     * @param string $category Admin-defined department (may be empty).
     * @param string $priority One of self::PRIORITIES.
     */
    public function create(
        int $user_id,
        string $subject,
        string $content,
        string $category = '',
        string $priority = self::DEFAULT_PRIORITY
    ): int {
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = self::DEFAULT_PRIORITY;
        }

        $now = $this->now();

        $ticket_id = $this->insert_row(
            [
                'user_id'    => $user_id,
                'subject'    => $subject,
                'category'   => $category,
                'priority'   => $priority,
                'status'     => self::STATUS_OPEN,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($ticket_id > 0) {
            $this->add_reply($ticket_id, $user_id, false, $content);
        }

        return $ticket_id;
    }

    /**
     * Create a ticket on a customer's behalf, opened by an admin (e.g. a
     * phone call the shop wants a record of). The opening message is
     * attributed to the admin, so the ticket starts out "answered" —
     * waiting on the customer, not on support.
     *
     * @param string $category Admin-defined department (may be empty).
     * @param string $priority One of self::PRIORITIES.
     */
    public function create_by_admin(
        int $user_id,
        int $admin_id,
        string $subject,
        string $content,
        string $category = '',
        string $priority = self::DEFAULT_PRIORITY
    ): int {
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = self::DEFAULT_PRIORITY;
        }

        $now = $this->now();

        $ticket_id = $this->insert_row(
            [
                'user_id'    => $user_id,
                'subject'    => $subject,
                'category'   => $category,
                'priority'   => $priority,
                'status'     => self::STATUS_OPEN,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($ticket_id > 0) {
            $this->add_reply($ticket_id, $admin_id, true, $content);
        }

        return $ticket_id;
    }

    /**
     * Record the customer's satisfaction rating for a closed ticket.
     * Ownership and "not already rated" are checked by the caller against
     * the row it already has in hand — this only enforces that the
     * ticket is still closed at write time.
     *
     * @param int $rating 1 (dissatisfied) to 5 (excellent).
     */
    public function rate(int $ticket_id, int $rating): bool
    {
        if ($rating < 1 || $rating > 5) {
            return false;
        }

        $updated = $this->db->update(
            'tickets',
            ['rating' => $rating, 'rated_at' => $this->now()],
            ['id' => $ticket_id, 'status' => self::STATUS_CLOSED],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return $updated > 0;
    }

    /**
     * Append a reply and update ticket status.
     */
    public function add_reply(int $ticket_id, int $user_id, bool $is_admin, string $content): int
    {
        $reply_id = $this->db->insert(
            'ticket_replies',
            [
                'ticket_id'  => $ticket_id,
                'user_id'    => $user_id,
                'is_admin'   => $is_admin ? 1 : 0,
                'content'    => $content,
                'created_at' => $this->now(),
            ],
            ['%d', '%d', '%d', '%s', '%s']
        );

        $this->db->update(
            'tickets',
            [
                'status'     => $is_admin ? self::STATUS_ANSWERED : self::STATUS_OPEN,
                'updated_at' => $this->now(),
            ],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );

        return $reply_id;
    }

    /**
     * Tickets whose last word is the customer's — the admin menu badge.
     */
    public function open_count(): int
    {
        return (int) $this->db->get_var(
            'SELECT COUNT(*) FROM {tickets} WHERE status = %s',
            self::STATUS_OPEN
        );
    }

    public function close(int $ticket_id): void
    {
        $this->db->update(
            'tickets',
            ['status' => self::STATUS_CLOSED, 'updated_at' => $this->now()],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $ticket_id): ?array
    {
        return $this->db->get_row('SELECT * FROM {tickets} WHERE id = %d', $ticket_id);
    }

    /** @return array<int, array<string, mixed>> */
    public function for_user(int $user_id, int $limit = 30): array
    {
        return $this->db->get_results(
            'SELECT * FROM {tickets} WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d',
            $user_id,
            $limit
        );
    }

    /** @return array<int, array<string, mixed>> */
    /**
     * Ticket counts per department (open tickets first in mind).
     *
     * @return array<int, array<string, mixed>>
     */
    public function department_counts(): array
    {
        return $this->db->get_results(
            "SELECT category, COUNT(*) AS total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_count
             FROM {tickets}
             GROUP BY category
             ORDER BY total DESC"
        );
    }

    public function latest(int $limit = 30, string $search = '', string $category = ''): array
    {
        if ('' !== $category) {
            return $this->db->get_results(
                "SELECT * FROM {tickets}
                 WHERE category = %s
                 ORDER BY FIELD(status, 'open', 'answered', 'closed'),
                          FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                          updated_at DESC
                 LIMIT %d",
                $category,
                $limit
            );
        }

        if ('' !== $search) {
            return $this->db->get_results(
                "SELECT * FROM {tickets}
                 WHERE subject LIKE %s OR category LIKE %s
                 ORDER BY FIELD(status, 'open', 'answered', 'closed'),
                          FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                          updated_at DESC
                 LIMIT %d",
                '%' . $search . '%',
                '%' . $search . '%',
                $limit
            );
        }

        return $this->db->get_results(
            "SELECT * FROM {tickets}
             ORDER BY FIELD(status, 'open', 'answered', 'closed'),
                      FIELD(priority, 'urgent', 'high', 'normal', 'low'),
                      updated_at DESC
             LIMIT %d",
            $limit
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function replies(int $ticket_id): array
    {
        return $this->db->get_results(
            'SELECT * FROM {ticket_replies} WHERE ticket_id = %d ORDER BY id ASC',
            $ticket_id
        );
    }
}
