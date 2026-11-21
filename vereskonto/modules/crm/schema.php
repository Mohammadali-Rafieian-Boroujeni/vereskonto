<?php
/**
 * CRM schema: tables, default data, and removal.
 *
 * The CRM keeps its own schema version (option vkl_crm_db_version)
 * instead of the plugin-wide migrator version. Reason: the CRM must be
 * removable on its own ("remove CRM data") and re-installable afterwards;
 * the global migrator only ever moves forward, so a migration it has
 * already recorded would never recreate dropped tables. install() is
 * idempotent (dbDelta + guarded seed data), so running it again is safe.
 *
 * Only NEW tables are created; no existing Vereskonto table is touched.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Schema
 */
final class VKL_Crm_Schema
{
    /** Bump when the table definitions below change. */
    public const VERSION = '2.16.0';

    public const VERSION_OPTION = 'vkl_crm_db_version';

    /** Bare table names (VKL_Database adds wp_ + vkl_). */
    public const TABLES = [
        'crm_contacts',
        'crm_contact_meta',
        'crm_tags',
        'crm_contact_tag',
        'crm_pipelines',
        'crm_stages',
        'crm_deals',
        'crm_activities',
        'crm_tasks',
    ];

    private VKL_Database $db;

    public function __construct(VKL_Database $db)
    {
        $this->db = $db;
    }

    /**
     * Install or upgrade when the stored version is behind. Cheap when
     * current: one non-autoloaded option read.
     */
    public function maybe_install(): bool
    {
        if (version_compare((string) get_option(self::VERSION_OPTION, '0'), self::VERSION, '>=')) {
            return false;
        }

        return $this->install();
    }

    /**
     * Create/upgrade all tables, seed defaults, sync roles. Idempotent.
     */
    public function install(): bool
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($this->definitions() as $sql) {
            dbDelta($sql);
        }

        foreach (self::TABLES as $table) {
            if (!$this->table_exists($table)) {
                if (function_exists('vkl')) {
                    vkl('logger')->error('CRM install: table missing after dbDelta.', ['table' => $table]);
                }

                return false; // Version not recorded; retried next admin request.
            }
        }

        $this->seed_default_pipeline();
        VKL_Crm_Access::sync_roles();

        update_option(self::VERSION_OPTION, self::VERSION, false);

        return true;
    }

    /**
     * Remove every CRM table, role, capability and option. Irreversible —
     * only ever called from an explicit, confirmed admin action.
     */
    public function uninstall(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->table($table));
        }

        VKL_Crm_Access::remove_roles();

        foreach ([self::VERSION_OPTION, VKL_Crm_Backfill::STATE_OPTION] as $option) {
            delete_option($option);
        }

        wp_clear_scheduled_hook(VKL_Crm_Backfill::HOOK);
    }

    public function table_exists(string $table): bool
    {
        $name = $this->db->table($table);

        return $name === $this->db->get_var('SHOW TABLES LIKE %s', $name);
    }

    /**
     * One default pipeline with the usual stages, created once.
     */
    private function seed_default_pipeline(): void
    {
        if ((int) $this->db->get_var('SELECT COUNT(*) FROM {crm_pipelines}') > 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $pid = $this->db->insert('crm_pipelines', [
            'name'       => 'فروش',
            'position'   => 0,
            'is_default' => 1,
            'created_at' => $now,
        ], ['%s', '%d', '%d', '%s']);

        if ($pid <= 0) {
            return;
        }

        $stages = [
            ['سرنخ جدید', 10, 'open', '#8c8f94'],
            ['تماس گرفته شد', 25, 'open', '#2271b1'],
            ['نیاز سنجی', 40, 'open', '#996800'],
            ['پیشنهاد قیمت', 60, 'open', '#8a2be2'],
            ['مذاکره', 80, 'open', '#d63638'],
            ['برنده', 100, 'won', '#00a32a'],
            ['از دست رفته', 0, 'lost', '#50575e'],
        ];

        foreach ($stages as $i => [$name, $probability, $kind, $color]) {
            $this->db->insert('crm_stages', [
                'pipeline_id' => $pid,
                'name'        => $name,
                'position'    => $i,
                'probability' => $probability,
                'kind'        => $kind,
                'color'       => $color,
            ], ['%d', '%s', '%d', '%d', '%s', '%s']);
        }
    }

    /**
     * CREATE TABLE statements in dbDelta's strict format (one column per
     * line, two spaces after PRIMARY KEY). All datetimes are UTC.
     *
     * @return string[]
     */
    private function definitions(): array
    {
        $c = $this->db->charset_collate();
        $t = fn(string $name): string => $this->db->table($name);

        return [
            // mobile and user_id are NULL when unknown so the UNIQUE keys
            // allow many contacts without one, but never two with the same.
            "CREATE TABLE {$t('crm_contacts')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                first_name VARCHAR(100) NOT NULL DEFAULT '',
                last_name VARCHAR(100) NOT NULL DEFAULT '',
                company VARCHAR(190) NOT NULL DEFAULT '',
                mobile VARCHAR(20) NULL,
                email VARCHAR(190) NOT NULL DEFAULT '',
                lifecycle VARCHAR(20) NOT NULL DEFAULT 'lead',
                source VARCHAR(40) NOT NULL DEFAULT '',
                owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                score INT NOT NULL DEFAULT 0,
                city VARCHAR(100) NOT NULL DEFAULT '',
                birthday DATE NULL,
                orders_count INT UNSIGNED NOT NULL DEFAULT 0,
                total_spent DECIMAL(18,4) NOT NULL DEFAULT 0,
                last_order_at DATETIME NULL,
                last_activity_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                UNIQUE KEY mobile (mobile),
                KEY email (email),
                KEY owner_lifecycle (owner_id,lifecycle),
                KEY lifecycle (lifecycle),
                KEY last_activity_at (last_activity_at),
                KEY created_at (created_at),
                KEY name (last_name,first_name)
            ) {$c};",

            "CREATE TABLE {$t('crm_contact_meta')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contact_id BIGINT UNSIGNED NOT NULL,
                meta_key VARCHAR(100) NOT NULL,
                meta_value LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY contact_key (contact_id,meta_key)
            ) {$c};",

            "CREATE TABLE {$t('crm_tags')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type VARCHAR(20) NOT NULL DEFAULT 'tag',
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                color VARCHAR(7) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY type_slug (type,slug)
            ) {$c};",

            "CREATE TABLE {$t('crm_contact_tag')} (
                contact_id BIGINT UNSIGNED NOT NULL,
                tag_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (contact_id,tag_id),
                KEY tag_id (tag_id)
            ) {$c};",

            "CREATE TABLE {$t('crm_pipelines')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                position INT NOT NULL DEFAULT 0,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id)
            ) {$c};",

            "CREATE TABLE {$t('crm_stages')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                pipeline_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                position INT NOT NULL DEFAULT 0,
                probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
                kind VARCHAR(10) NOT NULL DEFAULT 'open',
                color VARCHAR(7) NOT NULL DEFAULT '',
                PRIMARY KEY  (id),
                KEY pipeline_position (pipeline_id,position)
            ) {$c};",

            "CREATE TABLE {$t('crm_deals')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contact_id BIGINT UNSIGNED NOT NULL,
                pipeline_id BIGINT UNSIGNED NOT NULL,
                stage_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL DEFAULT '',
                value DECIMAL(18,4) NOT NULL DEFAULT 0,
                owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                expected_close DATE NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'open',
                lost_reason VARCHAR(190) NOT NULL DEFAULT '',
                order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source VARCHAR(40) NOT NULL DEFAULT '',
                position INT NOT NULL DEFAULT 0,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY contact_id (contact_id),
                KEY stage_status (stage_id,status),
                KEY owner_status (owner_id,status),
                KEY order_id (order_id)
            ) {$c};",

            // dedupe_key makes system events idempotent: the same order
            // status change logged twice (retries, duplicate hooks) is
            // stored once. NULL for anything a person writes.
            "CREATE TABLE {$t('crm_activities')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                contact_id BIGINT UNSIGNED NOT NULL,
                deal_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                type VARCHAR(20) NOT NULL,
                direction VARCHAR(10) NOT NULL DEFAULT '',
                subject VARCHAR(190) NOT NULL DEFAULT '',
                body TEXT NULL,
                outcome VARCHAR(40) NOT NULL DEFAULT '',
                duration INT UNSIGNED NOT NULL DEFAULT 0,
                scheduled_at DATETIME NULL,
                done_at DATETIME NULL,
                author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                ref_type VARCHAR(30) NOT NULL DEFAULT '',
                ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                dedupe_key VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe_key (dedupe_key),
                KEY contact_created (contact_id,created_at),
                KEY deal_id (deal_id),
                KEY type_created (type,created_at),
                KEY ref (ref_type,ref_id)
            ) {$c};",

            "CREATE TABLE {$t('crm_tasks')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(190) NOT NULL,
                description TEXT NULL,
                contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                deal_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                assignee_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                due_at DATETIME NULL,
                remind_at DATETIME NULL,
                reminded_at DATETIME NULL,
                priority VARCHAR(10) NOT NULL DEFAULT 'normal',
                status VARCHAR(12) NOT NULL DEFAULT 'open',
                completed_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY assignee_status_due (assignee_id,status,due_at),
                KEY contact_id (contact_id),
                KEY deal_id (deal_id),
                KEY status_remind (status,remind_at)
            ) {$c};",
        ];
    }
}
