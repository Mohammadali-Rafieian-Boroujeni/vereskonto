<?php
/**
 * Repository base class.
 *
 * Modules extend this to persist their aggregates. All SQL lives inside
 * repositories — never in hooks, trackers, or admin screens.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Repository
 */
abstract class VKL_Repository
{
    /**
     * Database abstraction.
     */
    protected VKL_Database $db;

    /**
     * @param VKL_Database $db Database.
     */
    public function __construct(VKL_Database $db)
    {
        $this->db = $db;
    }

    /**
     * Bare table name (without any prefix) this repository owns.
     */
    abstract protected function table_name(): string;

    /**
     * Current UTC timestamp in MySQL format.
     */
    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Insert a row into the repository's table.
     *
     * @param array<string, mixed> $data   Column => value.
     * @param string[]             $format wpdb formats.
     *
     * @return int Insert id, 0 on failure.
     */
    protected function insert_row(array $data, array $format = []): int
    {
        return $this->db->insert($this->table_name(), $data, $format);
    }
}
