<?php
/**
 * Schema migrator.
 *
 * Versioned database migrations. Modules register their schema with the
 * migrator; the migrator runs pending migrations on activation and on
 * version bumps (covers zip-overwrite updates where the activation hook
 * does not fire).
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Migrator
 */
final class VKL_Migrator
{
    /**
     * Option storing the installed schema version. Not autoloaded.
     */
    private const VERSION_OPTION = 'vkl_db_version';

    /**
     * Registered migrations: version => list of callables.
     *
     * @var array<string, array<int, callable>>
     */
    private array $migrations = [];

    /**
     * Database abstraction.
     */
    private VKL_Database $db;

    /**
     * Logger.
     */
    private VKL_Logger $logger;

    /**
     * @param VKL_Database $db     Database.
     * @param VKL_Logger   $logger Logger.
     */
    public function __construct(VKL_Database $db, VKL_Logger $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;
    }

    /**
     * Register a migration for a schema version.
     *
     * @param string   $version   Schema version, e.g. '1.1.0'.
     * @param callable $migration Callable receiving (VKL_Database $db).
     */
    public function register(string $version, callable $migration): void
    {
        $this->migrations[$version][] = $migration;
    }

    /**
     * Run all migrations newer than the installed schema version.
     *
     * Safe to call on every admin request — returns immediately when the
     * schema is current (single non-autoloaded option read).
     */
    public function run(): void
    {
        $installed = (string) get_option(self::VERSION_OPTION, '0.0.0');
        $target    = $this->latest_version();

        if ('' === $target || version_compare($installed, $target, '>=')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $versions = array_keys($this->migrations);
        usort($versions, 'version_compare');

        foreach ($versions as $version) {
            if (version_compare($installed, $version, '>=')) {
                continue;
            }

            foreach ($this->migrations[$version] as $migration) {
                try {
                    $migration($this->db);
                } catch (Throwable $e) {
                    $this->logger->error(
                        'Migration failed.',
                        ['version' => $version, 'error' => $e->getMessage()]
                    );

                    return; // Stop; do not record a version we failed to reach.
                }
            }

            update_option(self::VERSION_OPTION, $version, false);

            $this->logger->info('Migrated schema.', ['version' => $version]);
        }
    }

    /**
     * Highest registered migration version.
     */
    private function latest_version(): string
    {
        $versions = array_keys($this->migrations);

        if (!$versions) {
            return '';
        }

        usort($versions, 'version_compare');

        return (string) end($versions);
    }
}
