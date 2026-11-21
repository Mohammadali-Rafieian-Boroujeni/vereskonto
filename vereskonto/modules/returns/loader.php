<?php
/**
 * Returns module loader — per-item return requests, separate from full
 * order cancellation.
 *
 * @package Vereskonto\Modules\Returns
 * @since   2.9.20
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/returns/repository.php');
vkl_require('modules/returns/account.php');
vkl_require('modules/returns/admin.php');

/**
 * Class VKL_Returns_Module
 */
final class VKL_Returns_Module
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

        $c->singleton('returns.repository', static fn(VKL_Container $c): VKL_Returns_Repository => new VKL_Returns_Repository($c->make('db')));

        VKL_Returns_Repository::register_migrations($c->make('migrator'));

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'returns_enabled'         => '0',
            'returns_window_days'     => 7,
            'returns_sms_enabled'     => '0',
            'returns_pattern_admin'   => '',
            'returns_pattern_decided' => '',
        ]);

        if (is_admin()) {
            (new VKL_Returns_Admin($c->make('returns.repository'), $c->make('security'), $c->make('validator'), $config))->register();
        }

        if (class_exists('WooCommerce')) {
            (new VKL_Returns_Account($c->make('returns.repository'), $config))->register();
        }
    }
}
