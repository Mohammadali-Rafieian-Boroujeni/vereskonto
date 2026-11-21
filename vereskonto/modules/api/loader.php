<?php
/**
 * API module loader — mobile app REST surface + token store.
 *
 * @package Vereskonto\Modules\Api
 * @since   1.32.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/api/tokens.php');
vkl_require('modules/api/rest.php');
vkl_require('modules/api/admin.php');

/**
 * Class VKL_Api_Module
 */
final class VKL_Api_Module
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
            'api_enabled'        => '0',
            'api_token_ttl_days' => 90,
        ]);

        $c->singleton('api.tokens', static fn(VKL_Container $c): VKL_Api_Tokens => new VKL_Api_Tokens($c->make('db')));

        VKL_Api_Tokens::register_migrations($c->make('migrator'));

        (new VKL_Api_Rest($config, $c->make('validator'), $c->make('api.tokens')))->register();

        if (is_admin()) {
            $c->singleton('api.admin', static fn(VKL_Container $c): VKL_Api_Admin => new VKL_Api_Admin(
                $c->make('config'),
                $c->make('security'),
                $c->make('api.tokens')
            ));
            $c->make('api.admin')->register();
        }
    }
}
