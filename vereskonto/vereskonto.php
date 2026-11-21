<?php
/*
Plugin Name: Vereskonto
Plugin URI: https://vereskala.com
Description: Vereskonto — SMS Login & Communication Platform for WooCommerce (SMS/WhatsApp, wallet, tickets, loyalty, cart recovery)
Version: 2.15.2
Requires at least: 6.0
Requires PHP: 8.0
Author: Vereskala
License: GPL2
Text Domain: vereskala-login
*/

if (!defined('ABSPATH')) {
    exit;
}

define('VKL_VERSION', '2.15.2');
define('VKL_FILE', __FILE__);
define('VKL_PATH', plugin_dir_path(__FILE__));
define('VKL_URL', plugin_dir_url(__FILE__));

require_once VKL_PATH . 'includes/functions.php';
require_once VKL_PATH . 'includes/otp.php';
require_once VKL_PATH . 'includes/login-options.php';
require_once VKL_PATH . 'includes/identity.php';
require_once VKL_PATH . 'includes/sms.php';
require_once VKL_PATH . 'includes/auth.php';
require_once VKL_PATH . 'includes/ajax.php';
require_once VKL_PATH . 'includes/woocommerce.php';
require_once VKL_PATH . 'includes/dash-options.php';

/*
|--------------------------------------------------------------------------
| Vereskonto Platform (Core + Modules) â€” additive, since 1.1.0
|--------------------------------------------------------------------------
*/
require_once VKL_PATH . 'core/loader.php';

/*
| WooCommerce High-Performance Order Storage. Every order read and write
| in the plugin goes through wc_get_order()/wc_get_orders() and the order
| object's meta API, so the plugin works with HPOS on or off.
*/
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', VKL_FILE, true);
    }
});

if (is_admin()) {
    require_once VKL_PATH . 'admin/settings.php';
}

add_action('wp_enqueue_scripts', 'vkl_enqueue_assets');

function vkl_enqueue_assets()
{
    wp_enqueue_style(
        'vkl-login',
        VKL_URL . 'assets/login.css',
        [],
        VKL_VERSION
    );

    wp_enqueue_script(
        'vkl-login',
        VKL_URL . 'assets/login.js',
        ['jquery'],
        VKL_VERSION,
        true
    );

    wp_localize_script(
        'vkl-login',
        'vkl',
        [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('vkl_nonce'),
            'expire' => vkl_otp_expire(),
            'otpLen' => vkl_otp_length(),
        ]
    );
}

add_shortcode('vereskala_login', 'vkl_shortcode');

function vkl_shortcode()
{
    ob_start();

    include VKL_PATH . 'templates/login.php';

    return ob_get_clean();
}

add_action('after_setup_theme', function () {

    if (!current_user_can('manage_options')) {
        show_admin_bar(false);
    }

});