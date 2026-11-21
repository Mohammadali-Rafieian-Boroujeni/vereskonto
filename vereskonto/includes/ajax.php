<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| ارسال کد
|--------------------------------------------------------------------------
*/

add_action('wp_ajax_vkl_send', 'vkl_send');
add_action('wp_ajax_nopriv_vkl_send', 'vkl_send');

function vkl_send()
{
    check_ajax_referer('vkl_nonce', 'nonce');

    $raw = isset($_POST['mobile']) ? sanitize_text_field(wp_unslash($_POST['mobile'])) : '';

    // یک ورودی، دو راه: شماره همراه یا رایانامه
    $is_email = (false !== strpos($raw, '@'));
    $mobile   = $is_email ? sanitize_email($raw) : vkl_normalize_mobile($raw);

    if ($is_email ? !is_email($mobile) : !vkl_is_mobile($mobile)) {

        wp_send_json_error([
            'message' => 'شماره همراه یا نشانی رایانامه درست نیست.'
        ]);

    }

    if (!vkl_can_send_otp($mobile)) {

        wp_send_json_error([
            'message' => 'شمار درخواست‌ها بیش از اندازه است.'
        ]);

    }

    $otp = vkl_generate_otp();

    vkl_save_otp($mobile, $otp);

    $sent = $is_email
        ? vkl_send_otp_email($mobile, $otp)
        : vkl_send_sms($mobile, $otp);

    if (is_wp_error($sent)) {

        // Never show the customer a raw transport error such as
        // "cURL error 28: Operation timed out". Keep the technical detail
        // in the log for the shop owner and show a message they can act on.
        $code   = (string) $sent->get_error_code();
        $detail = (string) $sent->get_error_message();

        error_log('VKL OTP SEND FAILED [' . $code . '] ' . $detail);

        $network = false !== stripos($detail, 'curl')
            || false !== stripos($detail, 'timed out')
            || false !== stripos($detail, 'timeout')
            || 'http_request_failed' === $code;

        // The text message did not get through. Rather than silently
        // switching channel — which left customers holding a code the
        // form would not accept — invite them to use their email address
        // instead, which is a path the same form already supports.
        wp_send_json_error([
            'need_email' => !$is_email,
            'message'    => $is_email
                ? __('The code could not be emailed right now. Please try again in a moment.', 'vereskala-login')
                : __('The text message could not be delivered. Please enter your email address instead and we will send the code there.', 'vereskala-login'),
        ]);

    }

    wp_send_json_success([
        'message' => $is_email
            ? 'گذرشماره به رایانامه شما فرستاده شد.'
            : 'گذرشماره پیامک شد.'
    ]);
}

/*
|--------------------------------------------------------------------------
| تایید کد
|--------------------------------------------------------------------------
*/

add_action('wp_ajax_vkl_verify', 'vkl_verify');
add_action('wp_ajax_nopriv_vkl_verify', 'vkl_verify');

function vkl_verify()
{
    check_ajax_referer('vkl_nonce', 'nonce');

    $raw = isset($_POST['mobile']) ? sanitize_text_field(wp_unslash($_POST['mobile'])) : '';

    $is_email = (false !== strpos($raw, '@'));
    $mobile   = $is_email ? sanitize_email($raw) : vkl_normalize_mobile($raw);

    $otp = isset($_POST['otp'])
        ? sanitize_text_field(wp_unslash($_POST['otp']))
        : '';

    if ($is_email ? !is_email($mobile) : !vkl_is_mobile($mobile)) {

        wp_send_json_error([
            'message' => 'شماره همراه یا نشانی رایانامه درست نیست.'
        ]);

    }

    /*
|--------------------------------------------------------------------------
| جلوگیری از حمله Brute Force
|--------------------------------------------------------------------------
*/

if (vkl_otp_locked($mobile)) {

    wp_send_json_error([
        'message' => 'به دلیل واردکردن چندباره گذرشماره نادرست، آمد شما ۱۵ دقیقه بسته شد.'
    ]);

}
    $saved = vkl_get_otp($mobile);

    if (!$saved) {

        wp_send_json_error([
            'message' => 'گذرشماره از کار افتاده است.'
        ]);

    }

    if (!vkl_otp_matches($saved, $otp)) {

        $count = vkl_add_failed_attempt($mobile);

        wp_send_json_error([
        'message' => 'گذرشماره درست نیست. (' . $count . ' از ' . vkl_otp_max_failures() . ')'
        ]);

    }

    // The code is NOT deleted yet. On a flaky mobile connection the reply
    // often never reaches the phone: the customer sees "no connection",
    // taps again, and used to be told the code had expired — because the
    // first attempt had already consumed it. It is cleared only once the
    // sign-in has actually succeeded.
    $user = $is_email ? vkl_login_user_email($mobile) : vkl_login_user($mobile);

    if (is_wp_error($user)) {

        wp_send_json_error([
            'message' => $user->get_error_message()
        ]);

    }

    vkl_delete_otp($mobile);
    vkl_reset_failed_attempts($mobile);

    wp_send_json_success([
        'message' => 'آمدید! خوش آمدید.',
        'redirect' => apply_filters('vkl_login_redirect', wc_get_page_permalink('myaccount'))
    ]);
}