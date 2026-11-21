<?php
/**
 * Cart module loader.
 *
 * The single entry point of the cart module. Removing the boot() call in
 * core/loader.php disables the entire module; nothing else in the plugin
 * references these classes.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/cart/repository.php');
vkl_require('modules/cart/tracker.php');
vkl_require('modules/cart/hooks.php');
vkl_require('modules/cart/scheduler.php');
vkl_require('modules/cart/sms.php');
vkl_require('modules/cart/activeusers.php');
vkl_require('modules/cart/admin.php');

/**
 * Class VKL_Cart_Module
 */
final class VKL_Cart_Module
{
    /**
     * Boot guard.
     */
    private static bool $booted = false;

    /**
     * Register services and hooks for the cart module.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        /** @var VKL_Container $c */
        $c = vkl();

        $c->singleton(
            'cart.repository',
            static fn(VKL_Container $c): VKL_Cart_Repository => new VKL_Cart_Repository($c->make('db'))
        );

        $c->singleton(
            'cart.tracker',
            static fn(VKL_Container $c): VKL_Cart_Tracker => new VKL_Cart_Tracker(
                $c->make('cart.repository'),
                $c->make('events'),
                $c->make('validator')
            )
        );

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'cart_tracking_enabled' => '1',
            'cart_sms_enabled'      => '0',
            'cart_wa_enabled'       => '0',
            'cart_abandon_minutes'  => 60,
            'cart_pattern_code'     => '',
            'cart_wa_template'      => '',
            'cart_coupon_code'      => '',
            'cart_coupon_auto'      => '0',
            'cart_coupon_amount'    => 10,
            'cart_coupon_days'      => 3,
            'cart_retention_days'   => 45,
            'cart_purge_days'       => 0,
        ]);

        // Shared notifier (SMS + WhatsApp): used by the abandoned-cart
        // listener and by the admin overview for manual sends. The sms
        // module boots before this module (see core/loader.php).
        $c->singleton(
            'cart.notifier',
            static fn(VKL_Container $c): VKL_Cart_Sms => new VKL_Cart_Sms(
                $c->make('config'),
                $c->make('sms.sender'),
                $c->make('wa.sender')
            )
        );

        // Schema. (The SMS log schema is owned by the sms module since 1.5.0.)
        VKL_Cart_Repository::register_migrations($c->make('migrator'));
        VKL_Cart_Repository::register_cleanup_migration($c->make('migrator'));

        // Active users ("کاربران کارا"): login reward + online list. Runs
        // on the front end too (login hook, last-seen stamp, cron tick).
        VKL_Cart_ActiveUsers::register_migrations($c->make('migrator'));

        $c->singleton(
            'cart.active',
            static fn(VKL_Container $c): VKL_Cart_ActiveUsers => new VKL_Cart_ActiveUsers(
                $c->make('db'),
                $c->make('config'),
                $c->make('security')
            )
        );
        $c->make('cart.active')->register();

        // Admin is always available (settings must stay reachable).
        if (is_admin()) {
            (new VKL_Cart_Admin(
                $c->make('cart.repository'),
                $config,
                $c->make('security'),
                $c->make('cart.notifier')
            ))->register();
        }

        // Scheduler manages its own enable/disable state.
        $scheduler = new VKL_Cart_Scheduler(
            $c->make('cart.repository'),
            $c->make('events'),
            $config
        );
        $scheduler->register();

        // Listeners.
        $c->make('cart.notifier')->register();

        // Tracking hooks only when WooCommerce is active and tracking is on.
        if (class_exists('WooCommerce') && $config->get_bool('cart_tracking_enabled', true)) {
            (new VKL_Cart_Hooks($c->make('cart.tracker')))->register();
            add_action('wp_footer', [$c->make('cart.tracker'), 'human_beacon'], 99);
        }
    }
}

register_deactivation_hook(VKL_FILE, ['VKL_Cart_Scheduler', 'unschedule']);
