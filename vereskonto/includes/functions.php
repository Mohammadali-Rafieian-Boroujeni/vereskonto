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