<?php
/**
 * API access tokens.
 *
 * Opaque bearer tokens for the Android/mobile app. Each token is a long
 * random string; only its SHA-256 hash is stored, so a database leak does
 * not expose usable credentials. Tokens carry an expiry and a
 * last-seen-at, and can be revoked individually or per user.
 *
 * @package Vereskonto\Modules\Api
 * @since   1.32.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Api_Tokens
 */
final class VKL_Api_Tokens extends VKL_Repository
{
    /**
     * Table key.
     */
    protected function table_name(): string
    {
        return 'api_tokens';
    }

    /**
     * Migrations.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.32.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_api_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    device VARCHAR(190) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL,
                    last_used_at DATETIME NULL,
                    expires_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY token_hash (token_hash),
                    KEY user_id (user_id),
                    KEY expires_at (expires_at)
                ) {$wpdb->get_charset_collate()};"
            );
        });
    }

    /**
     * Issue a fresh token for a user. Returns the PLAINTEXT token — it is
     * shown exactly once and never recoverable afterwards.
     */
    public function issue(int $user_id, string $device, int $ttl_days): string
    {
        $plain = $this->random_token();

        $this->insert_row([
            'user_id'    => $user_id,
            'token_hash' => hash('sha256', $plain),
            'device'     => mb_substr($device, 0, 190),
            'created_at' => $this->now(),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + max(1, $ttl_days) * DAY_IN_SECONDS),
        ]);

        return $plain;
    }

    /**
     * Resolve a plaintext bearer token to a user id (0 when invalid or
     * expired). Touches last_used_at on success.
     */
    public function resolve(string $plain): int
    {
        $plain = trim($plain);

        if ('' === $plain) {
            return 0;
        }

        $row = $this->db->get_row(
            'SELECT id, user_id, expires_at FROM {api_tokens} WHERE token_hash = %s',
            hash('sha256', $plain)
        );

        if (null === $row) {
            return 0;
        }

        if (strtotime((string) $row['expires_at']) <= time()) {
            $this->db->delete('api_tokens', ['id' => (int) $row['id']]);

            return 0;
        }

        $this->db->update(
            'api_tokens',
            ['last_used_at' => $this->now()],
            ['id' => (int) $row['id']]
        );

        return (int) $row['user_id'];
    }

    /**
     * Revoke exactly one token (by its plaintext value).
     */
    public function revoke(string $plain): void
    {
        $this->db->delete('api_tokens', ['token_hash' => hash('sha256', trim($plain))]);
    }

    /**
     * Revoke every token of a user (e.g. "log out everywhere").
     */
    public function revoke_all(int $user_id): int
    {
        return (int) $this->db->query('DELETE FROM {api_tokens} WHERE user_id = %d', $user_id);
    }

    /**
     * Housekeeping: drop expired tokens. Hooked on the daily cron.
     */
    public function purge_expired(): int
    {
        return (int) $this->db->query('DELETE FROM {api_tokens} WHERE expires_at <= %s', $this->now());
    }

    /**
     * Active-token stats for the admin surface.
     *
     * @return array{tokens: int, users: int}
     */
    public function stats(): array
    {
        $row = $this->db->get_row(
            'SELECT COUNT(*) AS tokens, COUNT(DISTINCT user_id) AS users
             FROM {api_tokens} WHERE expires_at > %s',
            $this->now()
        );

        return ['tokens' => (int) ($row['tokens'] ?? 0), 'users' => (int) ($row['users'] ?? 0)];
    }

    /**
     * Cryptographically strong URL-safe token.
     */
    private function random_token(): string
    {
        try {
            $bytes = random_bytes(32);
        } catch (\Exception $e) {
            $bytes = wp_generate_password(48, false, false) . uniqid('', true);
        }

        return rtrim(strtr(base64_encode((string) $bytes), '+/', '-_'), '=');
    }
}
