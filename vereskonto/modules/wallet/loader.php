<?php
/**
 * Wallet module loader.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/wallet/repository.php');
vkl_require('modules/wallet/service.php');
vkl_require('modules/wallet/checkout.php');
vkl_require('modules/wallet/topup.php');
vkl_require('modules/wallet/installments.php');
vkl_require('modules/wallet/gateway.php');
vkl_require('modules/wallet/creditrequest.php');
vkl_require('modules/wallet/promos.php');
vkl_require('modules/wallet/account.php');
vkl_require('modules/wallet/carrier-order.php');
vkl_require('modules/wallet/statement.php');
vkl_require('modules/wallet/admin.php');

/**
 * Class VKL_Wallet_Module
 */
final class VKL_Wallet_Module
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

        $c->singleton('wallet.repository', static fn(VKL_Container $c): VKL_Wallet_Repository => new VKL_Wallet_Repository($c->make('db')));

        $c->singleton('wallet', static fn(VKL_Container $c): VKL_Wallet_Service => new VKL_Wallet_Service(
            $c->make('wallet.repository'),
            $c->make('validator')
        ));

        VKL_Wallet_Repository::register_migrations($c->make('migrator'));
        VKL_Wallet_Installments::register_migrations($c->make('migrator'));
        VKL_Credit_Requests::register_migrations($c->make('migrator'));
        VKL_Wallet_Promos::register_migrations($c->make('migrator'));

        $c->singleton('wallet.credit_requests', static fn(VKL_Container $c): VKL_Credit_Requests => new VKL_Credit_Requests(
            $c->make('db'),
            $c->make('config'),
            $c->make('wallet.installments'),
            $c->make('security')
        ));

        $c->singleton('wallet.promos', static fn(VKL_Container $c): VKL_Wallet_Promos => new VKL_Wallet_Promos(
            $c->make('db'),
            $c->make('config')
        ));

        $c->singleton('wallet.installments', static fn(VKL_Container $c): VKL_Wallet_Installments => new VKL_Wallet_Installments(
            $c->make('db'),
            $c->make('config'),
            $c->make('logger')
        ));

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'wallet_topup_enabled'    => '0',
            'wallet_checkout_enabled' => '1',
            'installment_penalty_percent' => 0,
            'credit_max_amount'           => 10000000,
            'promo_announce_pattern'      => '',
            'promo_auto_enabled'          => '0',
            'promo_auto_amount'           => 0,
            'promo_auto_min_order'        => 0,
            'promo_auto_delay_hours'      => 0,
            'promo_auto_days'             => 7,
            'promo_auto_use_min'          => 0,
            'promo_auto_title'            => '',
            'promo_auto_pattern'          => '',
            'installment_notice_pattern'  => '',
            'installment_due_pattern'     => '',
            'wallet_credit_terms'     => '',
            'wallet_topup_min'     => 1,
            'wallet_topup_max'     => 0,
            'carrier_order_status' => '1',
            'carrier_order_silent' => '1',
        ]);

        if (is_admin()) {
            (new VKL_Wallet_Admin($c->make('wallet'), $c->make('wallet.repository'), $c->make('security'), $config, $c->make('wallet.installments')))->register();
        }

        if (class_exists('WooCommerce')) {
            (new VKL_Wallet_Checkout($c->make('wallet'), $config))->register();
            (new VKL_Wallet_Account($c->make('wallet'), $c->make('wallet.repository')))->register();
            $c->singleton('wallet.topup', static fn(VKL_Container $c): VKL_Wallet_Topup => new VKL_Wallet_Topup(
                $c->make('wallet'),
                $config,
                $c->make('logger')
            ));
            $c->make('wallet.topup')->register();

            $c->singleton('wallet.carrier_order', static fn(VKL_Container $c): VKL_Wallet_Carrier_Order => new VKL_Wallet_Carrier_Order(
                $c->make('config'),
                $c->make('logger')
            ));
            $c->make('wallet.carrier_order')->register();

            $c->singleton('wallet.statement', static fn(VKL_Container $c): VKL_Wallet_Statement => new VKL_Wallet_Statement(
                $c->make('wallet.installments')
            ));
            $c->make('wallet.statement')->register();

            $c->make('wallet.installments')->register();
            $c->make('wallet.credit_requests')->register();
            $c->make('wallet.promos')->register();
        }
    }
}

register_deactivation_hook(VKL_FILE, ['VKL_Wallet_Installments', 'unschedule']);
