<?php
/**
 * Ticket module loader.
 *
 * @package Vereskonto\Modules\Ticket
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/ticket/repository.php');
vkl_require('modules/ticket/account.php');
vkl_require('modules/ticket/admin.php');

/**
 * Class VKL_Ticket_Module
 */
final class VKL_Ticket_Module
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

        $c->singleton('ticket.repository', static fn(VKL_Container $c): VKL_Ticket_Repository => new VKL_Ticket_Repository($c->make('db')));

        VKL_Ticket_Repository::register_migrations($c->make('migrator'));

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'ticket_categories' => '',
        ]);

        if (is_admin()) {
            (new VKL_Ticket_Admin(
                $c->make('ticket.repository'),
                $c->make('security'),
                $c->make('validator'),
                $config
            ))->register();
        }

        if (class_exists('WooCommerce')) {
            (new VKL_Ticket_Account($c->make('ticket.repository'), $c->make('validator'), $config))->register();
        }
    }
}
