<?php
/**
 * Personal coupon factory — auto-generated, single-use discount codes.
 *
 * Generates a real WooCommerce coupon unique to one customer and one
 * context (birthday, abandoned cart, ...): percent discount, usage limit
 * 1 (once, once per user), individual use, expiry after N days, and —
 * when the customer is known — locked to their account e-mail so the
 * code is worthless to anyone else. Codes read like VKL-BD-8K3F2A.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.14.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Coupon_Factory
 */
final class VKL_Coupon_Factory
{
    /**
     * Create a personal coupon and return its code ('' on failure).
     *
     * @param string $context Short context tag (e.g. 'bd', 'cart').
     * @param int    $user_id Customer id (0 = unknown/guest).
     * @param float  $percent Percent discount (1–100).
     * @param int    $days    Validity in days.
     */
    public static function generate(string $context, int $user_id, float $percent, int $days): string
    {
        if (!class_exists('WC_Coupon') || $percent <= 0) {
            return '';
        }

        $context = strtoupper(substr(preg_replace('/[^a-z]/i', '', $context) ?: 'V', 0, 1));

        // Short (5 chars), easy to type: context letter + 4 chars from an
        // alphabet without look-alikes (no O/0, I/1, ...).
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $code = $context;

            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[wp_rand(0, strlen($alphabet) - 1)];
            }
        } while (function_exists('wc_get_coupon_id_by_code') && wc_get_coupon_id_by_code($code) > 0);

        try {
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_discount_type('percent');
            $coupon->set_amount(min(100.0, $percent));
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_date_expires(time() + max(1, $days) * DAY_IN_SECONDS);

            $user = $user_id > 0 ? get_userdata($user_id) : false;

            if ($user && '' !== (string) $user->user_email) {
                $coupon->set_email_restrictions([(string) $user->user_email]);
            }

            // Exclude products already on sale: a personal discount code must
            // not stack on top of a promotional price — that turns every
            // special offer into a double discount and eats the margin.
            $coupon->set_exclude_sale_items(true);

            $coupon->set_description(
                sprintf('Vereskonto auto coupon — context: %s, user: %d', $context, $user_id)
            );

            $coupon->save();
        } catch (Throwable $e) {
            vkl('logger')->error('Coupon factory failed: ' . $e->getMessage());

            return '';
        }

        return $code;
    }
}
