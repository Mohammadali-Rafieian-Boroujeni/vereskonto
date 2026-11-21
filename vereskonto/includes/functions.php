<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * تولید کد تایید
 */
function vkl_generate_otp($length = 0)
{
    $length = (int) $length > 0
        ? (int) $length
        : (function_exists('vkl_otp_length') ? vkl_otp_length() : 5);

    return str_pad(
        (string) random_int(0, (10 ** $length) - 1),
        $length,
        '0',
        STR_PAD_LEFT
    );
}

/**
 * نرمال سازی شماره موبایل
 */
function vkl_normalize_mobile($mobile)
{
    $mobile = preg_replace('/[^0-9]/', '', $mobile);

    if (strpos($mobile, '0098') === 0) {
        $mobile = substr($mobile, 4);
    }

    if (strpos($mobile, '98') === 0) {
        $mobile = substr($mobile, 2);
    }

    if (strpos($mobile, '0') !== 0) {
        $mobile = '0' . $mobile;
    }

    return $mobile;
}

/**
 * اعتبارسنجی شماره موبایل ایران
 */
function vkl_is_mobile($mobile)
{
    return (bool) preg_match(
        '/^09[0-9]{9}$/',
        $mobile
    );
}

/**
 * تبدیل به فرمت بین‌المللی
 */
function vkl_mobile_to_international($mobile)
{
    $mobile = vkl_normalize_mobile($mobile);

    return '+98' . substr($mobile, 1);
}

/**
 * تولید نام کاربری
 */
function vkl_generate_username($mobile)
{
    return 'vk_' . substr(
        md5($mobile . microtime()),
        0,
        12
    );
}

/**
 * تبدیل رقم‌های فارسی و عربی به رقم لاتین.
 *
 * @since 2.15.2
 */
function vkl_latin_digits($value)
{
    return strtr(
        (string) $value,
        [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]
    );
}

/**
 * Compare a stored one-time code with what the visitor typed.
 *
 * Timing-safe (hash_equals) and strict: the previous loose `!=` treated
 * numeric strings as numbers, so "012345" and "12345" compared equal.
 * Persian/Arabic digits typed on a phone keyboard are accepted.
 *
 * @since 2.15.2
 */
function vkl_otp_matches($expected, $given)
{
    $expected = (string) $expected;
    $given    = trim(vkl_latin_digits((string) $given));

    if ('' === $expected || '' === $given) {
        return false;
    }

    return hash_equals($expected, $given);
}

/**
 * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
 * Filterable so a different proxy/CDN can be trusted instead.
 *
 * @since 2.15.2
 *
 * @return string[]
 */
function vkl_trusted_proxy_ranges()
{
    $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    return function_exists('apply_filters') ? (array) apply_filters('vkl_trusted_proxy_ranges', $ranges) : $ranges;
}

/**
 * Whether an IP address (v4 or v6) falls inside a CIDR range.
 *
 * @since 2.15.2
 */
function vkl_ip_in_cidr($ip, $cidr)
{
    $parts = explode('/', (string) $cidr, 2);
    $ip_b  = @inet_pton((string) $ip);
    $net_b = @inet_pton($parts[0]);

    if (false === $ip_b || false === $net_b || strlen($ip_b) !== strlen($net_b)) {
        return false;
    }

    $bits = isset($parts[1]) ? (int) $parts[1] : strlen($ip_b) * 8;
    $full = intdiv($bits, 8);
    $rest = $bits % 8;

    if (0 !== strncmp($ip_b, $net_b, $full)) {
        return false;
    }

    if (0 === $rest) {
        return true;
    }

    $mask = (0xFF << (8 - $rest)) & 0xFF;

    return (ord($ip_b[$full]) & $mask) === (ord($net_b[$full]) & $mask);
}

/**
 * The visitor's real IP address.
 *
 * The shop sits behind Cloudflare, so REMOTE_ADDR is usually a Cloudflare
 * edge. CF-Connecting-IP is trusted ONLY when the connection really comes
 * from a trusted proxy range — anyone else could forge that header to
 * dodge the rate limits. Returns '' when no valid address is known.
 *
 * @since 2.15.2
 */
function vkl_client_ip()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

    if (false === filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '';
    }

    $ip = $remote;

    if ('' !== $remote && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $forwarded = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);

        if (false !== filter_var($forwarded, FILTER_VALIDATE_IP)) {
            foreach (vkl_trusted_proxy_ranges() as $range) {
                if (vkl_ip_in_cidr($remote, $range)) {
                    $ip = $forwarded;
                    break;
                }
            }
        }
    }

    return function_exists('apply_filters') ? (string) apply_filters('vkl_client_ip', $ip, $remote) : $ip;
}
