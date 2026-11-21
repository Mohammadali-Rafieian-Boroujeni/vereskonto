<?php
/**
 * Notify module loader — multi-channel notification framework.
 *
 * Boots the dispatcher, per-customer preferences, and the concrete
 * channels (Bale first). WhatsApp and SMS are folded in as channels in a
 * later release; adding a channel is one require + one add_channel call,
 * with no change to the dispatcher or callers.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/notify/channel-interface.php');
vkl_require('modules/notify/preferences.php');
vkl_require('modules/notify/dispatcher.php');
vkl_require('modules/notify/channels/bale.php');
vkl_require('modules/notify/channels/sms.php');
vkl_require('modules/notify/channels/whatsapp.php');
vkl_require('modules/notify/webhook.php');
vkl_require('modules/notify/admin.php');
vkl_require('modules/notify/account.php');
vkl_require('modules/notify/events.php');
vkl_require('modules/notify/vapid.php');
vkl_require('modules/notify/push.php');
vkl_require('modules/notify/icon.php');
vkl_require('modules/notify/pwa.php');

/**
 * Class VKL_Notify_Module
 */
final class VKL_Notify_Module
{
    /**
     * Default channel priority: richest & cheapest first. SMS last (it
     * costs money); Bale first (free + documents). WhatsApp registers in a
     * later release and slots between them.
     *
     * @var string[]
     */
    private const DEFAULT_ORDER = ['bale', 'whatsapp', 'sms'];

    public static function boot(): void
    {
        $c = vkl();

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'bale_enabled'         => '0',
            'bale_bot_token'       => '',
            'bale_bot_username'    => '',
            'bale_webhook_secret'  => '',
            'notify_invoice_enabled' => '1',
            'notify_wallet_enabled'  => '1',
            'pwa_enabled'          => '0',
            'pwa_name'             => '',
            'pwa_short_name'       => '',
            'pwa_icon'             => '',
            'pwa_theme'            => '#42D968',
            'push_enabled'         => '0',
        ]);

        $c->singleton('notify.preferences', static fn(): VKL_Notify_Preferences => new VKL_Notify_Preferences(self::DEFAULT_ORDER));

        $c->singleton('notify.dispatcher', static fn(VKL_Container $c): VKL_Notify_Dispatcher => new VKL_Notify_Dispatcher(
            $c->make('notify.preferences'),
            $c->make('logger')
        ));

        $c->singleton('notify.bale', static fn(VKL_Container $c): VKL_Notify_Bale => new VKL_Notify_Bale(
            $c->make('config'),
            $c->make('logger')
        ));

        // Register concrete channels with the dispatcher, in priority order.
        /** @var VKL_Notify_Dispatcher $dispatcher */
        $dispatcher = $c->make('notify.dispatcher');
        $dispatcher->add_channel($c->make('notify.bale'));

        // SMS + WhatsApp wrap the existing senders (sms module boots first).
        if ($c->has('sms.sender')) {
            $c->singleton('notify.sms', static fn(VKL_Container $c): VKL_Notify_Sms => new VKL_Notify_Sms(
                $c->make('sms.sender'),
                $c->make('config')
            ));
            $dispatcher->add_channel($c->make('notify.sms'));
        }

        if ($c->has('wa.sender')) {
            $c->singleton('notify.whatsapp', static fn(VKL_Container $c): VKL_Notify_Whatsapp => new VKL_Notify_Whatsapp(
                $c->make('wa.sender'),
                $c->make('config')
            ));
            $dispatcher->add_channel($c->make('notify.whatsapp'));
        }

        // Webhook (connect flow).
        (new VKL_Notify_Webhook($c->make('notify.bale'), $config))->register();

        // Admin settings — registered as a singleton so the main settings
        // page can render the Bale panel as one of its tabs.
        $c->singleton('notify.admin', static fn(VKL_Container $c): VKL_Notify_Admin => new VKL_Notify_Admin(
            $c->make('config'),
            $c->make('security')
        ));

        if (is_admin()) {
            $c->make('notify.admin')->register();
        }

        // Customer connect + preference UI (front-end account page).
        (new VKL_Notify_Account(
            $c->make('notify.bale'),
            $dispatcher,
            $c->make('notify.preferences')
        ))->register();

        // Route invoices/key events through the dispatcher.
        (new VKL_Notify_Events($dispatcher, $config))->register();

        // Installable app plus browser notifications.
        VKL_Notify_Push::register_migrations($c->make('migrator'));

        $c->singleton('notify.push', static fn(VKL_Container $c): VKL_Notify_Push => new VKL_Notify_Push($c->make('db')));

        (new VKL_Notify_Pwa($config, $c->make('notify.push')))->register();
    }
}
