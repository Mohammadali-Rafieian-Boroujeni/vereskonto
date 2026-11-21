<?php
/**
 * WooCommerce module loader.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/woocommerce/hooks.php');
vkl_require('modules/woocommerce/orders.php');
vkl_require('modules/woocommerce/checkout.php');
vkl_require('modules/woocommerce/account.php');
vkl_require('modules/woocommerce/frontendui.php');
vkl_require('modules/woocommerce/coupons.php');
vkl_require('modules/woocommerce/invoice.php');

/**
 * Class VKL_Wc_Module
 */
final class VKL_Wc_Module
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted || !class_exists('WooCommerce')) {
            return;
        }

        self::$booted = true;

        /** @var VKL_Container $c */
        $c = vkl();

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'checkout_require_login' => '0',
        ]);

        $hooks = new VKL_Wc_Hooks($c->make('validator'));
        $hooks->register();

        (new VKL_Wc_Orders($hooks, $c->make('security'), $config))->register();
        (new VKL_Wc_Checkout($config))->register();
        (new VKL_Wc_Account($c->make('validator')))->register();
        (new VKL_Frontend_Ui())->register();
        (new VKL_Wc_Invoice($config))->register();
    }
}
