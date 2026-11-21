<?php
/**
 * Web Push: subscriptions, the pending queue, and delivery.
 *
 * Reaches a customer who is not on the site at all — the browser wakes the
 * service worker, which then fetches the message text from our REST route.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.99.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Push
 */
final class VKL_Notify_Push
{
    private VKL_Database $db;

    public function __construct(VKL_Database $db)
    {
        $this->db = $db;
    }

    /**
     * Tables: one for devices, one for messages waiting to be collected.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.99.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $collate = $wpdb->get_charset_collate();
            $subs    = $wpdb->prefix . 'vkl_push_subs';
            $queue   = $wpdb->prefix . 'vkl_push_queue';

            dbDelta(
                "CREATE TABLE {$subs} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    endpoint VARCHAR(500) NOT NULL,
                    endpoint_hash CHAR(32) NOT NULL,
                    p256dh VARCHAR(200) NOT NULL DEFAULT '',
                    auth VARCHAR(100) NOT NULL DEFAULT '',
                    failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY endpoint_hash (endpoint_hash),
                    KEY user_id (user_id)
                ) {$collate};"
            );

            dbDelta(
                "CREATE TABLE {$queue} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    title VARCHAR(120) NOT NULL DEFAULT '',
                    body VARCHAR(300) NOT NULL DEFAULT '',
                    url VARCHAR(500) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id)
                ) {$collate};"
            );
        });
    }

    /**
     * Store or refresh a device subscription.
     */
    public function subscribe(int $user_id, string $endpoint, string $p256dh, string $auth): bool
    {
        if ($user_id <= 0 || '' === $endpoint) {
            return false;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'vkl_push_subs';

        // The endpoint is too long to index directly, so a hash carries the
        // uniqueness — a customer re-subscribing on the same device must
        // update their row rather than pile up duplicates.
        return false !== $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (user_id, endpoint, endpoint_hash, p256dh, auth, failures, created_at)
                 VALUES (%d, %s, %s, %s, %s, 0, %s)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    p256dh  = VALUES(p256dh),
                    auth    = VALUES(auth),
                    failures = 0",
                $user_id,
                substr($endpoint, 0, 500),
                md5($endpoint),
                substr($p256dh, 0, 200),
                substr($auth, 0, 100),
                current_time('mysql', true)
            )
        );
    }

    public function unsubscribe(string $endpoint): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->prefix . 'vkl_push_subs', ['endpoint_hash' => md5($endpoint)], ['%s']); // phpcs:ignore
    }

    /**
     * Queue a notification and wake every device this customer has.
     */
    public function notify(int $user_id, string $title, string $body, string $url = ''): int
    {
        if ($user_id <= 0 || '' === $title) {
            return 0;
        }

        global $wpdb;

        $wpdb->insert( // phpcs:ignore
            $wpdb->prefix . 'vkl_push_queue',
            [
                'user_id'    => $user_id,
                'title'      => mb_substr($title, 0, 120),
                'body'       => mb_substr($body, 0, 300),
                'url'        => substr('' !== $url ? $url : home_url('/'), 0, 500),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );

        return $this->wake($user_id);
    }

    /**
     * Messages waiting for one customer, clearing them as they are handed
     * over so a notification is not shown twice.
     *
     * @return array<int, array{title: string, body: string, url: string}>
     */
    public function take_pending(int $user_id): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_push_queue';

        $rows = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT 5", $user_id),
            ARRAY_A
        );

        if (!is_array($rows) || [] === $rows) {
            return [];
        }

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);

        $wpdb->query( // phpcs:ignore
            "DELETE FROM {$table} WHERE id IN (" . implode(',', array_map('absint', $ids)) . ')'
        );

        return array_map(
            static fn(array $r): array => [
                'title' => (string) $r['title'],
                'body'  => (string) $r['body'],
                'url'   => (string) $r['url'],
            ],
            $rows
        );
    }

    /**
     * Send an empty push to each of a customer's devices.
     *
     * No payload: the browser only needs to be told "something is waiting",
     * and the service worker collects the text over HTTPS. That keeps the
     * whole ECDH/AES-GCM layer out of the plugin.
     *
     * @return int Devices reached.
     */
    public function wake(int $user_id): int
    {
        if (!VKL_Notify_Vapid::has_keys()) {
            return 0;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'vkl_push_subs';

        $subs = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d LIMIT 20", $user_id),
            ARRAY_A
        );

        $sent = 0;

        foreach ((array) $subs as $sub) {
            $endpoint = (string) $sub['endpoint'];
            $auth     = VKL_Notify_Vapid::auth_header($endpoint);

            if ('' === $auth) {
                continue;
            }

            $response = wp_remote_post($endpoint, [
                'timeout' => 8,
                'headers' => [
                    'Authorization' => $auth,
                    'TTL'           => '86400',
                    'Content-Length' => '0',
                    'Urgency'       => 'normal',
                ],
                'body'    => '',
            ]);

            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

            if ($code >= 200 && $code < 300) {
                ++$sent;

                continue;
            }

            // 404/410 mean the browser threw the subscription away; keeping
            // it would mean retrying a dead endpoint for ever.
            if (404 === $code || 410 === $code) {
                $this->unsubscribe($endpoint);

                continue;
            }

            $wpdb->query( // phpcs:ignore
                $wpdb->prepare("UPDATE {$table} SET failures = failures + 1 WHERE id = %d", (int) $sub['id'])
            );
        }

        // Devices that keep refusing are dropped rather than retried for ever.
        $wpdb->query("DELETE FROM {$table} WHERE failures >= 10"); // phpcs:ignore

        return $sent;
    }

    /**
     * How many devices are subscribed in total.
     */
    public function device_count(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'vkl_push_subs'); // phpcs:ignore
    }
}
