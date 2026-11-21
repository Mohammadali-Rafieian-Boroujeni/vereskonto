<?php
/**
 * Analytics module loader — visit tracking + the Views dashboard.
 *
 * @package Vereskonto\Modules\Analytics
 * @since   1.33.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/analytics/repository.php');
vkl_require('modules/analytics/tracker.php');
vkl_require('modules/analytics/admin.php');

/**
 * Class VKL_Analytics_Module
 */
final class VKL_Analytics_Module
{
    /**
     * Boot the module.
     */
    public static function boot(): void
    {
        $c = vkl();

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'analytics_enabled'        => '1',
            'analytics_retention_days' => 90,
            'analytics_record_bots'    => '0',
            'analytics_dwell'          => '0',
        ]);

        $c->singleton(
            'analytics.repository',
            static fn(VKL_Container $c): VKL_Analytics_Repository => new VKL_Analytics_Repository($c->make('db'))
        );

        VKL_Analytics_Repository::register_migrations($c->make('migrator'));

        (new VKL_Analytics_Tracker($config, $c->make('analytics.repository')))->register();

        if (is_admin()) {
            (new VKL_Analytics_Admin($config, $c->make('security'), $c->make('analytics.repository')))->register();
        }
    }
}
