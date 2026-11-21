<?php
/**
 * Customisable pieces of the sign-in page (Vereskonto → ورود tab).
 *
 * Everything here has a built-in default, so an untouched install looks and
 * behaves exactly as before; the shop only overrides what it wants to.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Text options: key => [option name, default].
 */
function vkl_login_text_defaults()
{
    return [
        'subtitle' => 'آمد / نام‌نویسی با شماره همراه — زود و بدون گذرواژه',
        'send'     => 'دریافت گذرشماره',
        'hint'     => 'گذرشماره با پیامک یا رایانامه فرستاده می‌شود؛ هزینه‌ای ندارد.',
        'verify'   => 'ورود',
        'footer'   => '🔒 داده‌های شما نزد ورسکالا پوشیده می‌ماند.',
    ];
}

/**
 * One customisable text; blank falls back to the default.
 */
function vkl_login_text($key)
{
    $defaults = vkl_login_text_defaults();
    $default  = $defaults[$key] ?? '';
    $value    = trim((string) get_option('vkl_login_' . $key, ''));

    return '' !== $value ? $value : $default;
}

/**
 * Logo shown on the sign-in card: the chosen media-library image, else the
 * bundled mark.
 */
function vkl_login_logo_url()
{
    $id = (int) get_option('vkl_login_logo', 0);

    if ($id > 0) {
        $url = wp_get_attachment_image_url($id, 'full');

        if (is_string($url) && '' !== $url) {
            return $url;
        }
    }

    return VKL_URL . 'assets/logo.png';
}

/**
 * Valid #rrggbb accent colour, or '' when none is set.
 */
function vkl_login_accent()
{
    $color = trim((string) get_option('vkl_login_accent', ''));

    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '';
}

/**
 * Small <style> block recolouring the card's brand green. Printed once
 * with the form, only when an accent colour has been chosen.
 */
function vkl_login_accent_style()
{
    $c = vkl_login_accent();

    if ('' === $c) {
        return '';
    }

    // Darker shade for the gradient end / text, derived from the accent.
    $rgb  = [hexdec(substr($c, 1, 2)), hexdec(substr($c, 3, 2)), hexdec(substr($c, 5, 2))];
    $dark = sprintf('#%02x%02x%02x', (int) ($rgb[0] * 0.78), (int) ($rgb[1] * 0.78), (int) ($rgb[2] * 0.78));
    $soft = sprintf('rgba(%d,%d,%d,.14)', $rgb[0], $rgb[1], $rgb[2]);

    return '<style>'
        . ".vkl-box{border-top-color:{$c}}"
        . ".vkl-label,.vkl-title{color:{$dark}}"
        . ".vkl-input:focus-within{border-color:{$c};box-shadow:0 0 0 3px {$soft}}"
        . ".vkl-paste,#vkl_resend{border-color:{$c};color:{$c}}"
        . "#vkl_send,#vkl_verify{background:linear-gradient(135deg,{$c},{$dark})}"
        . "#vkl_countdown,.vkl-sent-to button{color:{$c}}"
        . '</style>';
}
