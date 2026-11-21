<?php
/**
 * Loyalty (customer club) module loader.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/loyalty/repository.php');
vkl_require('modules/loyalty/hooks.php');
vkl_require('modules/loyalty/account.php');
vkl_require('modules/loyalty/referral.php');
vkl_require('modules/loyalty/survey.php');
vkl_require('modules/loyalty/admin.php');

/**
 * Class VKL_Loyalty_Module
 */
final class VKL_Loyalty_Module
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

        $c->singleton('loyalty.repository', static fn(VKL_Container $c): VKL_Loyalty_Repository => new VKL_Loyalty_Repository($c->make('db')));
        $c->singleton('survey.repository', static fn(VKL_Container $c): VKL_Survey_Repository => new VKL_Survey_Repository($c->make('db')));

        VKL_Loyalty_Repository::register_migrations($c->make('migrator'));
        VKL_Loyalty_Referral::register_migrations($c->make('migrator'));
        VKL_Survey_Repository::register_migrations($c->make('migrator'));

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'loyalty_ref_enabled'   => '0',
            'loyalty_ref_reward'    => 0,
            'loyalty_ref_min_order' => 0,
            'loyalty_ref_pattern'   => '',
        ]);

        $c->singleton('loyalty.referral', static fn(VKL_Container $c): VKL_Loyalty_Referral => new VKL_Loyalty_Referral($c->make('db'), $c->make('config')));

        (new VKL_Loyalty_Hooks($c->make('loyalty.repository'), $config))->register();

        if (is_admin()) {
            (new VKL_Loyalty_Admin($config, $c->make('security'), $c->make('loyalty.repository'), $c->make('survey.repository')))->register();
        }

        if (class_exists('WooCommerce')) {
            (new VKL_Loyalty_Account($c->make('loyalty.repository'), $c->make('wallet'), $config))->register();
            $c->make('loyalty.referral')->register();
            (new VKL_Survey_Account($c->make('survey.repository'), $c->make('loyalty.repository')))->register();
        }
    }
}
