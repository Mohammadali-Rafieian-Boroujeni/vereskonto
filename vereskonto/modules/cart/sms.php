<?php
/**
 * Abandoned-cart notifier (SMS + WhatsApp).
 *
 * Listens for 'cart.abandoned'. The cart module never calls this class —
 * all coupling goes through the event manager. Delivery itself is
 * delegated to the shared gateways in the communications module
 * (VKL_Sms_Sender / VKL_Wa_Sender), which handle logging and
 * per-recipient throttling centrally.
 *
 * Pattern/template variables: name, coupon. The coupon value comes from
 * a WooCommerce coupon chosen by the admin in the cart settings
 * ('cart_coupon_code'), so the SMS can carry a real discount code.
 *
 * Also exposes notify_sms()/notify_whatsapp() so the admin overview can
 * trigger a message for a specific cart manually.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Sms
 */
final class VKL_Cart_Sms
{
    /**
     * Message type recorded in the message log.
     */
    private const TYPE = 'cart.abandoned';

    /**
     * Automatic per-recipient throttle window in hours (max one nag per day).
     * Manual sends from the admin overview bypass this on purpose.
     */
    private const THROTTLE_HOURS = 24;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Shared SMS gateway.
     */
    private VKL_Sms_Sender $sender;

    /**
     * Shared WhatsApp gateway.
     */
    private VKL_Wa_Sender $whatsapp;

    /**
     * @param VKL_Config     $config   Config.
     * @param VKL_Sms_Sender $sender   Shared SMS gateway.
     * @param VKL_Wa_Sender  $whatsapp Shared WhatsApp gateway.
     */
    public function __construct(VKL_Config $config, VKL_Sms_Sender $sender, VKL_Wa_Sender $whatsapp)
    {
        $this->config   = $config;
        $this->sender   = $sender;
        $this->whatsapp = $whatsapp;
    }

    /**
     * Register event listeners.
     */
    public function register(): void
    {
        vkl_listen('cart.abandoned', [$this, 'on_cart_abandoned']);
    }

    /**
     * Handle an abandoned cart (automatic path, throttled).
     *
     * @param array<string, mixed> $payload Event payload from the scheduler.
     */
    public function on_cart_abandoned(array $payload): void
    {
        $mobile  = (string) ($payload['mobile'] ?? '');
        $user_id = (int) ($payload['user_id'] ?? 0);

        if ('' === $mobile) {
            return;
        }

        if ($this->config->get_bool('cart_sms_enabled', false)) {
            $this->notify_sms($mobile, $user_id, $payload, self::THROTTLE_HOURS);
        }

        if ($this->config->get_bool('cart_wa_enabled', false)) {
            $this->notify_whatsapp($mobile, $user_id, $payload, self::THROTTLE_HOURS);
        }
    }

    /**
     * Send the abandoned-cart SMS to one recipient.
     *
     * @param string               $mobile         Recipient.
     * @param int                  $user_id        User id (0 for guests).
     * @param array<string, mixed> $payload        Cart payload for the params filter.
     * @param int                  $throttle_hours 0 = no throttle (manual send).
     *
     * @return bool True when the gateway accepted the message.
     */
    public function notify_sms(string $mobile, int $user_id, array $payload = [], int $throttle_hours = 0): bool
    {
        $pattern = $this->config->get_string('cart_pattern_code');

        if ('' === $mobile || '' === $pattern) {
            return false;
        }

        return $this->sender->send($mobile, self::TYPE, $pattern, $this->params($user_id, $payload), $throttle_hours);
    }

    /**
     * Send the abandoned-cart WhatsApp template to one recipient.
     *
     * @param string               $mobile         Recipient.
     * @param int                  $user_id        User id (0 for guests).
     * @param array<string, mixed> $payload        Cart payload for the params filter.
     * @param int                  $throttle_hours 0 = no throttle (manual send).
     *
     * @return bool True when the gateway accepted the message.
     */
    public function notify_whatsapp(string $mobile, int $user_id, array $payload = [], int $throttle_hours = 0): bool
    {
        $template = $this->config->get_string('cart_wa_template');

        if ('' === $mobile || '' === $template) {
            return false;
        }

        return $this->whatsapp->send($mobile, self::TYPE, $template, $this->params($user_id, $payload), $throttle_hours);
    }

    /**
     * Pattern/template parameters: name + coupon (order matters for
     * WhatsApp positional templates: {{1}} = name, {{2}} = coupon).
     *
     * @param int                  $user_id User id.
     * @param array<string, mixed> $payload Cart payload.
     *
     * @return array<string, string>
     */
    private function params(int $user_id, array $payload): array
    {
        $coupon = $this->config->get_string('cart_coupon_code');

        // Personal single-use recovery code, when enabled — the strongest
        // nudge an abandoned-cart message can carry.
        if ($this->config->get_bool('cart_coupon_auto', false) && class_exists('VKL_Coupon_Factory')) {
            $generated = VKL_Coupon_Factory::generate(
                'cart',
                $user_id,
                (float) $this->config->get_int('cart_coupon_amount', 10),
                $this->config->get_int('cart_coupon_days', 3)
            );

            if ('' !== $generated) {
                $coupon = $generated;
            }
        }

        $params = [
            'name'   => $this->sender->resolve_name($user_id),
            'coupon' => $coupon,
        ];

        /**
         * Filters the pattern parameters for the abandoned-cart message.
         * Keys must match the variables defined in the IPPanel pattern;
         * value order must match the WhatsApp template placeholders.
         *
         * @param array $params  Pattern parameters.
         * @param array $payload Cart event payload.
         */
        return (array) apply_filters('vkl_cart_sms_params', $params, $payload);
    }
}
