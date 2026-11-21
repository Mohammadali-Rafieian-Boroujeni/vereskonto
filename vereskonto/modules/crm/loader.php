<?php
/**
 * CRM module loader.
 *
 * Contacts (leads and customers), tags, sales pipelines and deals, the
 * activity timeline and staff tasks. Switched off with the option
 * vkl_crm_enabled = '0' — the data stays, nothing CRM runs.
 *
 * Services in the container:
 *   crm.schema, crm.access, crm.contacts, crm.tags, crm.pipelines,
 *   crm.deals, crm.activities, crm.tasks, crm.contact_service, crm.backfill
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/crm/schema.php');
vkl_require('modules/crm/access.php');
vkl_require('modules/crm/repositories/base.php');
vkl_require('modules/crm/repositories/contacts.php');
vkl_require('modules/crm/repositories/tags.php');
vkl_require('modules/crm/repositories/pipelines.php');
vkl_require('modules/crm/repositories/deals.php');
vkl_require('modules/crm/repositories/activities.php');
vkl_require('modules/crm/repositories/tasks.php');
vkl_require('modules/crm/services/contacts.php');
vkl_require('modules/crm/services/backfill.php');

/**
 * Class VKL_Crm_Module
 */
final class VKL_Crm_Module
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        /** @var VKL_Container $c */
        $c = vkl();

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'crm_enabled'               => '1',
            'crm_agents_see_unassigned' => '1',
        ]);

        if (!$config->get_bool('crm_enabled', true)) {
            return;
        }

        $c->singleton('crm.schema', static fn(VKL_Container $c): VKL_Crm_Schema => new VKL_Crm_Schema($c->make('db')));
        $c->singleton('crm.access', static fn(): VKL_Crm_Access => new VKL_Crm_Access());

        foreach ([
            'crm.contacts'   => VKL_Crm_Contacts::class,
            'crm.tags'       => VKL_Crm_Tags::class,
            'crm.pipelines'  => VKL_Crm_Pipelines::class,
            'crm.deals'      => VKL_Crm_Deals::class,
            'crm.activities' => VKL_Crm_Activities::class,
            'crm.tasks'      => VKL_Crm_Tasks::class,
        ] as $id => $class) {
            $c->singleton($id, static fn(VKL_Container $c) => new $class($c->make('db')));
        }

        $c->singleton('crm.contact_service', static fn(VKL_Container $c): VKL_Crm_Contact_Service => new VKL_Crm_Contact_Service($c->make('crm.contacts')));
        $c->singleton('crm.backfill', static fn(VKL_Container $c): VKL_Crm_Backfill => new VKL_Crm_Backfill($c->make('crm.contact_service')));

        // Create/upgrade tables on admin requests, right after the
        // plugin-wide migrator (priority 5). Then start the one-time
        // import of existing customers.
        add_action('admin_init', static function () use ($c): void {
            $c->make('crm.schema')->maybe_install();

            if (self::installed()) {
                $c->make('crm.backfill')->start();
            }
        }, 6);

        VKL_Crm_Access::register_admin_access();

        // Nothing that writes to CRM tables is hooked before they exist —
        // after a zip upload, front-end requests can arrive before the
        // first admin page load creates them. Users who register in that
        // window are picked up by the backfill (it walks every account).
        if (self::installed()) {
            $c->make('crm.contact_service')->register_sync();
            $c->make('crm.backfill')->register();
        }
    }

    /**
     * Whether the CRM tables exist at the current schema version or an
     * older one (any recorded version means the tables are there).
     */
    public static function installed(): bool
    {
        return '' !== (string) get_option(VKL_Crm_Schema::VERSION_OPTION, '');
    }
}
