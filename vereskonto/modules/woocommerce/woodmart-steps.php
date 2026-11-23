<?php
/**
 * WoodMart's checkout steps bar with the "address and shipping" step:
 *
 *   cart → address and shipping → checkout → order complete
 *
 * WoodMart declares woodmart_checkout_steps() inside
 * `if (!function_exists(...))`, so this definition (loaded only while the
 * shipping step is enabled, and before the theme) replaces the theme's.
 * Same markup and classes, so the theme's styles and its Elementor /
 * Gutenberg / WPBakery "checkout steps" elements keep working.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   2.24.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('woodmart_checkout_steps')) {
    /**
     * Display checkout steps in page title.
     */
    function woodmart_checkout_steps(): void
    {
        if (function_exists('woodmart_enqueue_inline_style')) {
            woodmart_enqueue_inline_style('woo-mod-checkout-steps');
        }

        $step = VKL_Wc_Shipping_Step::active();

        if (null === $step) {
            return;
        }

        echo $step->woodmart_steps_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in woodmart_steps_html().
    }
}
