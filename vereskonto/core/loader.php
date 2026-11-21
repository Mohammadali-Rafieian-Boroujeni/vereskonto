<?php
/**
 * Platform bootstrap.
 *
 * Loads Core, registers core services, loads translations, then boots
 * each module in dependency order. Removing one boot line disables that
 * module with no side effects on the rest of the plugin.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once VKL_PATH . 'core/container.php';
require_once VKL_PATH . 'core/config.php';
require_once VKL_PATH . 'core/logger.php';
require_once VKL_PATH . 'core/eventmanager.php';
require_once VKL_PATH . 'core/database.php';
require_once VKL_PATH . 'core/migrator.php';
require_once VKL_PATH . 'core/validator.php';
require_once VKL_PATH . 'core/security.php';
require_once VKL_PATH . 'core/repository.php';
require_once VKL_PATH . 'core/helpers.php';

/**
 * Register core services.
 */
add_action('vkl_container_init', static function (VKL_Container $c): void {

    $c->singleton('config', static fn(): VKL_Config => new VKL_Config());

    $c->singleton('logger', static fn(): VKL_Logger => new VKL_Logger());

    $c->singleton(
        'events',
        static fn(VKL_Container $c): VKL_EventManager => new VKL_EventManager($c->make('logger'))
    );

    $c->singleton('db', static fn(): VKL_Database => new VKL_Database());

    $c->singleton(
        'migrator',
        static fn(VKL_Container $c): VKL_Migrator => new VKL_Migrator(
            $c->make('db'),
            $c->make('logger')
        )
    );

    $c->singleton('validator', static fn(): VKL_Validator => new VKL_Validator());

    $c->singleton('security', static fn(): VKL_Security => new VKL_Security());
}, 5);

/**
 * Module loaders. Each exposes a static boot() and nothing else.
 */
require_once VKL_PATH . 'modules/sms/loader.php';
require_once VKL_PATH . 'modules/woocommerce/loader.php';
require_once VKL_PATH . 'modules/wallet/loader.php';
require_once VKL_PATH . 'modules/ticket/loader.php';
require_once VKL_PATH . 'modules/returns/loader.php';
require_once VKL_PATH . 'modules/loyalty/loader.php';
require_once VKL_PATH . 'modules/cart/loader.php';
require_once VKL_PATH . 'modules/smart/loader.php';
require_once VKL_PATH . 'modules/api/loader.php';
require_once VKL_PATH . 'modules/analytics/loader.php';
require_once VKL_PATH . 'modules/notify/loader.php';

add_action('plugins_loaded', static function (): void {

    load_plugin_textdomain(
        'vereskala-login',
        false,
        dirname(plugin_basename(VKL_FILE)) . '/languages'
    );

    // Instantiate the container (fires vkl_container_init once).
    vkl();

    // Boot order matters: sms first (shared sender/log), wallet before
    // loyalty (conversion depends on the wallet service).
    VKL_Sms_Module::boot();
    VKL_Wc_Module::boot();
    VKL_Wallet_Module::boot();
    VKL_Ticket_Module::boot();
    VKL_Returns_Module::boot();
    VKL_Loyalty_Module::boot();
    VKL_Cart_Module::boot();
    VKL_Smart_Module::boot();
    VKL_Api_Module::boot();
    VKL_Analytics_Module::boot();
    VKL_Notify_Module::boot();

    // 1.6.0 renamed the My Account endpoints (vkl-wallet → wallet, …).
    // A rewrite flush is required once, also on zip-overwrite upgrades
    // where the activation hook never fires; the migrator gives us
    // exactly that "run once per version" semantics.
    vkl('migrator')->register('1.6.0', static function (): void {
        update_option('vkl_flush_rewrite', '1', false);
    });

    // 1.11.0 adds the My Account "surveys" endpoint.
    vkl('migrator')->register('1.11.0', static function (): void {
        update_option('vkl_flush_rewrite', '1', false);
    });

    // 2.9.20 adds the My Account "returns" endpoint.
    vkl('migrator')->register('2.9.20', static function (): void {
        update_option('vkl_flush_rewrite', '1', false);
    });

    // Read-only diagnostics page (needs module constants, so booted last).
    if (is_admin()) {
        require_once VKL_PATH . 'core/status.php';
        require_once VKL_PATH . 'core/adminui.php';
        require_once VKL_PATH . 'core/menu.php';
        require_once VKL_PATH . 'core/admintabs.php';
        require_once VKL_PATH . 'core/today.php';
        require_once VKL_PATH . 'core/customers.php';
        require_once VKL_PATH . 'core/messages.php';
        require_once VKL_PATH . 'core/search.php';
        (new VKL_Status_Admin(vkl('config'), vkl('sms.log'), vkl('security')))->register();
        (new VKL_Admin_Ui())->register();
        (new VKL_Admin_Menu())->register();
        (new VKL_Admin_Tabs())->register();
        (new VKL_Today_Admin())->register();
        (new VKL_Customers_Admin())->register();
        (new VKL_Messages_Admin(vkl('config')))->register();
        (new VKL_Admin_Search())->register();
    }

    /**
     * Fires after all Vereskonto modules have booted.
     */
    do_action('vkl_modules_loaded');
}, 20);

/**
 * Activation: run migrations and request a rewrite flush (My Account
 * endpoints). Migrations also run on version bumps via admin_init,
 * covering zip-overwrite updates where the activation hook never fires.
 */
register_activation_hook(VKL_FILE, static function (): void {
    vkl();
    vkl('migrator')->run();
    update_option('vkl_flush_rewrite', '1', false);
});

add_action('admin_init', static function (): void {
    vkl('migrator')->run();
}, 5);

add_action('init', static function (): void {
    if ('1' === get_option('vkl_flush_rewrite')) {
        flush_rewrite_rules();
        delete_option('vkl_flush_rewrite');
    }
}, 99);
