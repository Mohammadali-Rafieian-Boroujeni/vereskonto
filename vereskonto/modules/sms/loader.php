<?php
/**
 * SMS (communications) module loader.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/sms/driver-interface.php');
vkl_require('modules/sms/melipayamak.php');
vkl_require('modules/sms/whatsapp.php');
vkl_require('modules/sms/log.php');
vkl_require('modules/sms/sender.php');
vkl_require('modules/sms/order.php');
vkl_require('modules/sms/ticket.php');
vkl_require('modules/sms/returns.php');
vkl_require('modules/sms/wallet.php');
vkl_require('modules/sms/notification.php');
vkl_require('modules/sms/marketing.php');
vkl_require('modules/sms/birthday.php');
vkl_require('modules/sms/review.php');
vkl_require('modules/sms/admin.php');

/**
 * Class VKL_Sms_Module
 */
final class VKL_Sms_Module
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

        // Bound to the interface, so a further gateway is one new class
        // plus one line here — nothing downstream changes.
        $c->singleton('sms.driver', static fn(VKL_Container $c): VKL_Sms_Driver => new VKL_Sms_Melipayamak($c->make('config')));

        $c->singleton('sms.log', static fn(VKL_Container $c): VKL_Sms_Log_Repository => new VKL_Sms_Log_Repository($c->make('db')));

        $c->singleton('sms.sender', static fn(VKL_Container $c): VKL_Sms_Sender => new VKL_Sms_Sender(
            $c->make('sms.driver'),
            $c->make('sms.log'),
            $c->make('logger'),
            $c->make('config')
        ));

        // WhatsApp gateway (Meta Cloud API) — same log table, channel 'whatsapp'.
        $c->singleton('wa.sender', static fn(VKL_Container $c): VKL_Wa_Sender => new VKL_Wa_Sender(
            $c->make('config'),
            $c->make('sms.log'),
            $c->make('logger')
        ));

        $c->singleton('sms.marketing', static fn(VKL_Container $c): VKL_Sms_Marketing => new VKL_Sms_Marketing(
            $c->make('db'),
            $c->make('sms.sender'),
            $c->make('validator'),
            $c->make('config')
        ));

        VKL_Sms_Log_Repository::register_migrations($c->make('migrator'));
        VKL_Sms_Marketing::register_migrations($c->make('migrator'));

        /** @var VKL_Config $config */
        $config = $c->make('config');

        $config->add_defaults([
            'meli_username'         => '',
            'meli_password'         => '',
            'default_customer_name' => 'ورسکالایی',
            'birthday_sms_enabled'  => '0',
            'birthday_pattern'      => '',
            'birthday_coupon'       => '',
            'birthday_coupon_auto'  => '0',
            'birthday_coupon_amount' => 10,
            'birthday_coupon_days'  => 7,
            'admin_pattern_credit_request' => '',
            'sms_phonebook_id'             => '',
            'order_pattern_tracking_courier' => '',
            'installment_approved_pattern' => '',
            'installment_rejected_pattern' => '',
            'review_sms_enabled'    => '0',
            'review_pattern'        => '',
            'review_delay_days'     => 3,
            'wa_token'         => '',
            'wa_phone_id'      => '',
            'wa_lang'          => 'fa',
            'shipping_methods' => '',
        ]);

        // Listeners.
        (new VKL_Sms_Order($c->make('sms.sender'), $config))->register();
        (new VKL_Sms_Ticket($c->make('sms.sender'), $config))->register();
        (new VKL_Sms_Returns($c->make('sms.sender'), $config))->register();
        (new VKL_Sms_Wallet($c->make('sms.sender'), $config))->register();
        (new VKL_Sms_Notification($c->make('sms.sender'), $config))->register();
        (new VKL_Sms_Birthday($config, $c->make('sms.sender'), $c->make('validator')))->register();
        (new VKL_Sms_Review($config, $c->make('sms.sender'), $c->make('validator')))->register();

        // Campaign processor.
        /** @var VKL_Sms_Marketing $marketing */
        $marketing = $c->make('sms.marketing');
        $marketing->register();

        if (is_admin()) {
            (new VKL_Sms_Admin($config, $c->make('security'), $marketing, $c->make('sms.log')))->register();
        }

        // New customers land in the panel phonebook automatically.
        // Registered OUTSIDE the is_admin() branch on purpose: real
        // customers sign up on the front end, where is_admin() is false,
        // so inside that branch this listener never ran for them.
        add_action('user_register', static function (int $user_id): void {
            $config = vkl('config');
            $book   = $config->get_string('sms_phonebook_id');

            if ('' === $book) {
                return;
            }

            // Nothing to do when the active gateway has no phonebook —
            // otherwise every sign-up logs a failure that cannot be fixed.
            if (!vkl('sms.driver')->supports_phonebook()) {
                return;
            }

            $user = get_userdata($user_id);

            $mobile = vkl('validator')->normalize_mobile(
                (string) (get_user_meta($user_id, 'billing_phone', true) ?: ($user->user_login ?? ''))
            );

            if (!vkl('validator')->is_mobile($mobile)) {
                return;
            }

            $result = vkl('sms.driver')->add_contact($book, $mobile, $user ? $user->display_name : '');

            vkl('sms.log')->record(
                $mobile,
                'contact.add',
                'phonebook:' . $book,
                [],
                $result['ok'] ? VKL_Sms_Log_Repository::STATUS_SENT : VKL_Sms_Log_Repository::STATUS_FAILED,
                $result['ok'] ? '' : $result['response']
            );
        }, 20);
    }
}
