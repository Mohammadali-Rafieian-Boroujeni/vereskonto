<?php
/**
 * Database abstraction.
 *
 * Thin, safe wrapper around $wpdb so modules never touch $wpdb directly
 * and table names are built in exactly one place.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Database
 */
final class VKL_Database
{
    /**
     * Platform table prefix (after the WordPress prefix).
     */
    private const TABLE_PREFIX = 'vkl_';

    /**
     * WordPress database object.
     */
    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;

        $this->wpdb = $wpdb;
    }

    /**
     * Fully-qualified table name, e.g. table('cart_history') => wp_vkl_cart_history.
     *
     * @param string $name Bare table name.
     */
    public function table(string $name): string
    {
        return $this->wpdb->prefix . self::TABLE_PREFIX . $name;
    }

    /**
     * Charset/collation clause for CREATE TABLE.
     */
    public function charset_collate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * Insert a row.
     *
     * @param string               $table  Bare table name.
     * @param array<string, mixed> $data   Column => value.
     * @param string[]             $format wpdb formats (%s, %d, %f).
     *
     * @return int Insert id, 0 on failure.
     */
    public function insert(string $table, array $data, array $format = []): int
    {
        $result = $this->wpdb->insert($this->table($table), $data, $format ?: null);

        return false === $result ? 0 : (int) $this->wpdb->insert_id;
    }

    /**
     * Update rows.
     *
     * @param string               $table        Bare table name.
     * @param array<string, mixed> $data         Column => value.
     * @param array<string, mixed> $where        Column => value.
     * @param string[]             $format       Data formats.
     * @param string[]             $where_format Where formats.
     *
     * @return int Rows affected (-1 on failure).
     */
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int
    {
        $result = $this->wpdb->update(
            $this->table($table),
            $data,
            $where,
            $format ?: null,
            $where_format ?: null
        );

        return false === $result ? -1 : (int) $result;
    }

    /**
     * Delete rows.
     *
     * @param string               $table        Bare table name.
     * @param array<string, mixed> $where        Column => value.
     * @param string[]             $where_format Where formats.
     *
     * @return int Rows affected (-1 on failure).
     */
    public function delete(string $table, array $where, array $where_format = []): int
    {
        $result = $this->wpdb->delete($this->table($table), $where, $where_format ?: null);

        return false === $result ? -1 : (int) $result;
    }

    /**
     * Prepared SELECT returning all rows.
     *
     * @param string $sql  SQL with placeholders. Use {table} tokens, e.g. {cart_history}.
     * @param mixed  ...$args Placeholder values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_results(string $sql, mixed ...$args): array
    {
        $sql = $this->replace_tokens($sql);

        if ($args) {
            $sql = $this->wpdb->prepare($sql, ...$args); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_array($rows) ? $rows : [];
    }

    /**
     * Prepared SELECT returning one row.
     *
     * @param string $sql     SQL with placeholders and {table} tokens.
     * @param mixed  ...$args Placeholder values.
     *
     * @return array<string, mixed>|null
     */
    public function get_row(string $sql, mixed ...$args): ?array
    {
        $sql = $this->replace_tokens($sql);

        if ($args) {
            $sql = $this->wpdb->prepare($sql, ...$args); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $row = $this->wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return is_array($row) ? $row : null;
    }

    /**
     * Prepared SELECT returning a single scalar.
     *
     * @param string $sql     SQL with placeholders and {table} tokens.
     * @param mixed  ...$args Placeholder values.
     */
    public function get_var(string $sql, mixed ...$args): ?string
    {
        $sql = $this->replace_tokens($sql);

        if ($args) {
            $sql = $this->wpdb->prepare($sql, ...$args); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $var = $this->wpdb->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return null === $var ? null : (string) $var;
    }

    /**
     * Execute a write query (used by the migrator only).
     *
     * @param string $sql     SQL with placeholders and {table} tokens.
     * @param mixed  ...$args Placeholder values.
     */
    public function query(string $sql, mixed ...$args): int
    {
        $sql = $this->replace_tokens($sql);

        if ($args) {
            $sql = $this->wpdb->prepare($sql, ...$args); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $result = $this->wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return false === $result ? -1 : (int) $result;
    }

    /**
     * Replace {name} tokens with fully-qualified table names.
     *
     * @param string $sql SQL containing {table} tokens.
     */
    private function replace_tokens(string $sql): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            fn(array $m): string => $this->table($m[1]),
            $sql
        );
    }
}
