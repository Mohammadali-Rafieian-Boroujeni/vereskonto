<?php
/**
 * Birthday greetings — my pick for the "add something you think is good"
 * request. The account page has been collecting birthdays since 1.8.0;
 * this closes the loop: a daily job congratulates every customer on their
 * birthday by SMS (pattern variables: name, coupon), optionally carrying
 * a gift coupon code. Sent at most once per customer per year.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.11.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Birthday
 */
final class VKL_Sms_Birthday
{
    public const CRON_HOOK = 'vkl_birthday_check';

    /**
     * Max greetings per daily run (safety valve).
     */
    private const BATCH = 100;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Shared SMS gateway.
     */
    private VKL_Sms_Sender $sender;

    /**
     * Validator.
     */
    private VKL_Validator $validator;

    /**
     * @param VKL_Config     $config    Config.
     * @param VKL_Sms_Sender $sender    Shared sender.
     * @param VKL_Validator  $validator Validator.
     */
    public function __construct(VKL_Config $config, VKL_Sms_Sender $sender, VKL_Validator $validator)
    {
        $this->config    = $config;
        $this->sender    = $sender;
        $this->validator = $validator;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'maybe_schedule']);
        add_action(self::CRON_HOOK, [$this, 'run']);
    }

    /**
     * Schedule the daily check.
     */
    public function maybe_schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Unschedule (deactivation).
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Send today's greetings.
     */
    public function run(): void
    {
        if (!$this->config->get_bool('birthday_sms_enabled', false)) {
            return;
        }

        $pattern = $this->config->get_string('birthday_pattern');

        if ('' === $pattern) {
            return;
        }

        $today     = current_datetime()->format('m-d');
        $year      = current_datetime()->format('Y');
        $sent_key  = 'vkl_bday_sent_' . $year;

        // Birthdays are stored Y-m-d (see VKL_Wc_Account); match the
        // month-day suffix so the birth year is irrelevant.
        $users = get_users([
            'number'     => self::BATCH,
            'fields'     => 'ID',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'AND',
                [
                    'key'     => VKL_Wc_Account::META_BIRTHDAY,
                    'value'   => '-' . $today,
                    'compare' => 'LIKE',
                ],
                [
                    'key'     => $sent_key,
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $coupon_static = $this->config->get_string('birthday_coupon');
        $coupon_auto   = $this->config->get_bool('birthday_coupon_auto', false);
        $auto_percent  = (float) $this->config->get_int('birthday_coupon_amount', 10);
        $auto_days     = $this->config->get_int('birthday_coupon_days', 7);

        foreach ($users as $user_id) {
            $user_id = (int) $user_id;
            $mobile  = $this->validator->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

            // Mark first — a failed send must never repeat next cron run
            // and spam; failures stay visible in the SMS log.
            update_user_meta($user_id, $sent_key, gmdate('Y-m-d H:i:s'));

            if ('' === $mobile || !$this->validator->is_mobile($mobile)) {
                continue;
            }

            // A personal, single-use code beats a shared one: it feels like
            // a real gift and cannot leak to coupon sites.
            $coupon = $coupon_auto && class_exists('VKL_Coupon_Factory')
                ? VKL_Coupon_Factory::generate('bd', $user_id, $auto_percent, $auto_days)
                : $coupon_static;

            $this->sender->send($mobile, 'birthday', $pattern, [
                'name'   => $this->sender->resolve_name($user_id),
                'coupon' => '' !== $coupon ? $coupon : $coupon_static,
            ]);
        }
    }
}
