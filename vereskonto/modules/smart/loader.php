<?php
/**
 * Smart module loader — chatbot, product insights, engagement popup.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

vkl_require('modules/smart/insights.php');
vkl_require('modules/smart/chatlog.php');
vkl_require('modules/smart/shipping.php');
vkl_require('modules/smart/chatbot.php');
vkl_require('modules/smart/ai.php');
vkl_require('modules/smart/popup.php');
vkl_require('modules/smart/wheel-return.php');
vkl_require('modules/smart/wheel.php');
vkl_require('modules/smart/admin.php');

/**
 * Class VKL_Smart_Module
 */
final class VKL_Smart_Module
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
            'chatbot_enabled'  => '0',
            'chatbot_history'  => '1',
            'chatbot_guests'   => '1',
            'chatbot_title'    => '',
            'chatbot_greeting' => '',
            'chatbot_notify_staff'     => '0',
            'chatbot_staff'            => '',
            'chatbot_pattern_notify'   => '',
            'chatbot_pattern_escalate' => '',
            'chatbot_reply_sms'        => '0',
            'chatbot_pattern_reply'    => '',
            'chatbot_hours_start'      => 8,
            'chatbot_hours_end'        => 22,
            'chatbot_canned_replies'   => '',
            'insights_enabled' => '0',
            'popup_enabled'    => '0',
            'popup_minutes'    => 2,
            'popup_text'       => '',
            'popup_coupon'     => '',
            'popup_link'       => '',
            'popup_link_smart' => '1',
            'popup_repeat_hours' => 24,
            'wheel_enabled'      => '0',
            'wheel_title'        => '',
            'wheel_minutes'      => 1,
            'wheel_repeat_hours' => 168,
            'wheel_credit_days'  => 7,
            'wheel_slices'       => '',
            'support_whatsapp'   => '',
            'support_phone'      => '',
            'shop_extra_info'    => '',
            'shop_delivery_time' => '',
            'ai_enabled'         => '0',
            'ai_mode'            => 'always',
            'ai_api_key'         => '',
            'ai_provider'        => '', // '' = recognised from ai_base_url (pre-2.9.44 sites)
            'ai_model'           => 'gpt-4o-mini',
            'ai_backup_model'    => '',
            'ai_base_url'        => 'https://api.openai.com/v1',
            'ai_system_prompt'   => '',
            'ai_hourly_limit'    => 20,
            'ai_daily_cap'       => 300,
        ]);

        $c->singleton(
            'smart.insights',
            static fn(VKL_Container $c): VKL_Smart_Insights => new VKL_Smart_Insights($c->make('db'), $config)
        );

        $c->singleton(
            'smart.chatlog',
            static fn(VKL_Container $c): VKL_Smart_Chatlog => new VKL_Smart_Chatlog($c->make('db'))
        );

        // 2.9.36: the AI assistant (هوشواره) answers first. Shops that saved
        // the old "fallback only" default are moved over once; choosing
        // «صرفه‌جویی» again afterwards sticks.
        if ('1' !== (string) get_option('vkl_ai_first_v1', '')) {
            $config->set('ai_mode', 'always');
            update_option('vkl_ai_first_v1', '1', false);
        }

        VKL_Smart_Insights::register_migrations($c->make('migrator'));
        VKL_Smart_Chatlog::register_migrations($c->make('migrator'));

        if (is_admin()) {
            (new VKL_Smart_Admin($config, $c->make('smart.chatlog')))->register();
        }

        // Frontend features need WooCommerce.
        if (!class_exists('WooCommerce')) {
            return;
        }

        $c->make('smart.insights')->register();

        $wallet = null;

        try {
            $wallet = $c->make('wallet');
        } catch (Throwable $e) {
            $wallet = null;
        }

        $sms_sender = null;

        try {
            $sms_sender = $c->make('sms.sender');
        } catch (Throwable $e) {
            $sms_sender = null;
        }

        (new VKL_Smart_Chatbot(
            $config,
            $wallet instanceof VKL_Wallet_Service ? $wallet : null,
            $c->make('smart.chatlog'),
            $sms_sender instanceof VKL_Sms_Sender ? $sms_sender : null
        ))->register();
        (new VKL_Smart_Ai($config, $c->make('smart.chatlog')))->register();
        (new VKL_Smart_Popup($config))->register();
        (new VKL_Smart_Wheel($config))->register();
    }
}
