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
 *   crm.deals, crm.activities, crm.tasks, crm.contact_service, crm.backfill,
 *   crm.timeline, crm.deal_service, crm.leads, crm.task_service
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
vkl_require('modules/crm/services/timeline.php');
vkl_require('modules/crm/services/deals.php');
vkl_require('modules/crm/services/leads.php');
vkl_require('modules/crm/services/tasks.php');

if (is_admin()) {
    vkl_require('modules/crm/admin/ui.php');
    vkl_require('modules/crm/admin/contacts-table.php');
    vkl_require('modules/crm/admin/timeline-view.php');
    vkl_require('modules/crm/admin/contacts-page.php');
    vkl_require('modules/crm/admin/deals-page.php');
    vkl_require('modules/crm/admin/task-view.php');
    vkl_require('modules/crm/admin/tasks-page.php');
    vkl_require('modules/crm/admin/orders-page.php');
    vkl_require('modules/crm/admin/settings-page.php');
}

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
            'crm_lead_chatbot'          => '1',
            'crm_lead_cart'             => '1',
            'crm_deal_cart'             => '0',
            'crm_deal_credit'           => '0',
            'crm_lost_reasons'          => '',
            'crm_task_email'            => '1',
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
        $c->singleton('crm.timeline', static fn(VKL_Container $c): VKL_Crm_Timeline => new VKL_Crm_Timeline($c->make('crm.activities'), $c->make('crm.contact_service')));
        $c->singleton('crm.deal_service', static fn(VKL_Container $c): VKL_Crm_Deal_Service => new VKL_Crm_Deal_Service($c->make('crm.deals'), $c->make('crm.pipelines'), $c->make('crm.contacts'), $c->make('crm.activities'), $c->make('crm.contact_service')));
        $c->singleton('crm.task_service', static fn(VKL_Container $c): VKL_Crm_Task_Service => new VKL_Crm_Task_Service($c->make('crm.tasks'), $c->make('crm.contacts'), $c->make('crm.deals'), $c->make('crm.activities'), $c->make('crm.access'), $c->make('config')));
        $c->singleton('crm.leads', static fn(VKL_Container $c): VKL_Crm_Lead_Capture => new VKL_Crm_Lead_Capture($c->make('db'), $c->make('crm.contact_service'), $c->make('crm.activities'), $c->make('crm.deal_service'), $c->make('crm.deals'), $c->make('config')));

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
            $c->make('crm.timeline')->register();
            $c->make('crm.deal_service')->register_sync();
            $c->make('crm.leads')->register();
            $c->make('crm.task_service')->register();

            if (is_admin()) {
                $view = new VKL_Crm_Timeline_View($c->make('crm.contacts'), $c->make('crm.activities'), $c->make('crm.deals'), $c->make('crm.access'));
                $view->register();

                $task_view = new VKL_Crm_Task_View($c->make('crm.tasks'), $c->make('crm.task_service'), $c->make('crm.contacts'), $c->make('crm.deals'), $c->make('crm.access'));
                $task_view->register();

                (new VKL_Crm_Contacts_Page(
                    $c->make('crm.contacts'),
                    $c->make('crm.contact_service'),
                    $c->make('crm.tags'),
                    $c->make('crm.activities'),
                    $c->make('crm.access'),
                    $c->make('crm.timeline'),
                    $view,
                    $c->make('crm.deals'),
                    $c->make('crm.pipelines'),
                    $task_view
                ))->register();
                (new VKL_Crm_Deals_Page(
                    $c->make('crm.deals'),
                    $c->make('crm.deal_service'),
                    $c->make('crm.pipelines'),
                    $c->make('crm.contacts'),
                    $c->make('crm.activities'),
                    $c->make('crm.access'),
                    $view,
                    $task_view
                ))->register();
                (new VKL_Crm_Tasks_Page($c->make('crm.tasks'), $c->make('crm.access'), $task_view))->register();
                (new VKL_Crm_Orders_Page($c->make('crm.contacts'), $c->make('crm.contact_service'), $c->make('crm.deals'), $c->make('crm.deal_service'), $c->make('crm.access')))->register();
                (new VKL_Crm_Settings_Page($c->make('crm.tags'), $c->make('crm.access'), $config, $c->make('crm.pipelines')))->register();
            }
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
