<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| تنظیمات قابل شخصی‌سازی (Vereskonto ← ورود)
|--------------------------------------------------------------------------
| هرکدام یک عدد در بازهٔ مجاز؛ مقدار نامعتبر یا خالی به پیش‌فرض برمی‌گردد.
*/
function vkl_login_int($option, $default, $min, $max)
{
    $value = (int) get_option($option, $default);

    return $value < $min || $value > $max ? $default : $value;
}

/** تعداد ارقام گذرشماره (۴ تا ۸). */
function vkl_otp_length()
{
    return vkl_login_int('vkl_otp_length', 5, 4, 8);
}

/** مدت اعتبار گذرشماره به ثانیه. */
function vkl_otp_expire()
{
    return vkl_login_int('vkl_otp_expire', 120, 30, 900);
}

/** بیشینهٔ درخواست گذرشماره در هر بازه. */
function vkl_otp_max_requests()
{
    return vkl_login_int('vkl_otp_max_requests', 5, 1, 30);
}

/** طول بازهٔ شمارش درخواست‌ها، به ثانیه. */
function vkl_otp_rate_window()
{
    return vkl_login_int('vkl_otp_rate_minutes', 15, 1, 240) * MINUTE_IN_SECONDS;
}

/** بیشینهٔ گذرشمارهٔ نادرست پیش از بسته‌شدن آمد. */
function vkl_otp_max_failures()
{
    return vkl_login_int('vkl_otp_max_fail', 5, 1, 20);
}

/**
 * ذخیره OTP
 */
function vkl_save_otp($mobile, $otp)
{
    $data = [
        'code'    => $otp,
        'created' => time()
    ];

    set_transient(
        'vkl_otp_' . md5($mobile),
        $data,
        vkl_otp_expire()
    );
}

/**
 * دریافت OTP
 */
function vkl_get_otp($mobile)
{
    $data = get_transient(
        'vkl_otp_' . md5($mobile)
    );

    if (!$data) {
        return false;
    }

    if (
        (time() - $data['created']) >
        vkl_otp_expire()
    ) {

        vkl_delete_otp($mobile);

        return false;
    }

    return $data['code'];
}

/**
 * حذف OTP
 */
function vkl_delete_otp($mobile)
{
    delete_transient(
        'vkl_otp_' . md5($mobile)
    );
}

/**
 * بیشینهٔ درخواست گذرشماره از یک نشانی IP در هر بازه (۰ = خاموش).
 *
 * @since 2.15.2
 */
function vkl_otp_max_requests_ip()
{
    return vkl_login_int('vkl_otp_max_requests_ip', 30, 0, 500);
}

/**
 * بررسی محدودیت ارسال
 *
 * Two buckets: per number (as before) and, since 2.15.2, per visitor IP —
 * otherwise one script can text a code to thousands of different numbers
 * at the shop's expense. Both are checked before either is counted, so a
 * refused request never eats into the other bucket.
 */
function vkl_can_send_otp($mobile)
{
    $window  = vkl_otp_rate_window();
    $key     = 'vkl_rate_' . md5($mobile);
    $count   = (int) get_transient($key);

    if ($count >= vkl_otp_max_requests()) {
        return false;
    }

    $ip_max   = vkl_otp_max_requests_ip();
    $ip       = $ip_max > 0 ? vkl_client_ip() : '';
    $ip_key   = '' !== $ip ? 'vkl_rate_ip_' . md5($ip) : '';
    $ip_count = '' !== $ip_key ? (int) get_transient($ip_key) : 0;

    if ('' !== $ip_key && $ip_count >= $ip_max) {
        return false;
    }

    set_transient($key, $count + 1, $window);

    if ('' !== $ip_key) {
        set_transient($ip_key, $ip_count + 1, $window);
    }

    return true;
}

/**
 * آیا ورود این شماره به خاطر گذرشمارهٔ نادرست بسته است؟
 *
 * @since 2.15.2
 */
function vkl_otp_locked($mobile)
{
    return vkl_get_failed_attempts($mobile) >= vkl_otp_max_failures();
}

/**
 * مدت باقیمانده اعتبار
 */
function vkl_otp_remaining_time($mobile)
{
    $data = get_transient(
        'vkl_otp_' . md5($mobile)
    );

    if (!$data) {
        return 0;
    }

    $remaining = vkl_otp_expire() - (time() - $data['created']);

    return max(0, $remaining);
}

/*
|--------------------------------------------------------------------------
| تعداد تلاش‌های اشتباه
|--------------------------------------------------------------------------
*/

function vkl_get_failed_attempts($mobile)
{
    return (int) get_transient(
        'vkl_fail_' . md5($mobile)
    );
}

function vkl_add_failed_attempt($mobile)
{
    $count = vkl_get_failed_attempts($mobile);

    $count++;

    set_transient(
        'vkl_fail_' . md5($mobile),
        $count,
        15 * MINUTE_IN_SECONDS
    );

    return $count;
}

function vkl_reset_failed_attempts($mobile)
{
    delete_transient(
        'vkl_fail_' . md5($mobile)
    );
}

/**
 * ارسال گذرشماره با رایانامه
 */
function vkl_send_otp_email($email, $otp)
{
    $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

    $subject = sprintf('%s — گذرشماره ورود', $site);

    $body  = '<div style="font-family:Tahoma,sans-serif;direction:rtl;text-align:right;background:#f6f8f7;padding:24px">';
    $body .= '<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #dceee2">';
    $body .= '<div style="background:linear-gradient(135deg,#0e5f29,#073b18);border-bottom:3px solid #e5262c;padding:18px;text-align:center;color:#fff;font-size:17px;font-weight:bold">' . esc_html($site) . '</div>';
    $body .= '<div style="padding:22px;line-height:2;color:#233">';
    $body .= '<p style="margin:0 0 12px">گذرشماره ورود شما:</p>';
    $body .= '<p style="margin:0 0 14px;text-align:center"><span style="display:inline-block;background:#eaf9ef;color:#0e5f29;border:1px dashed #22a84d;border-radius:10px;padding:10px 26px;font-size:26px;letter-spacing:6px;font-weight:bold;direction:ltr">' . esc_html($otp) . '</span></p>';
    $body .= '<p style="margin:0;color:#667;font-size:13px">این گذرشماره تا چند دقیقه دیگر از کار می‌افتد. اگر شما درخواستش نکرده‌اید، همین پیام را نادیده بگیرید.</p>';
    $body .= '</div></div></div>';

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($email, $subject, $body, $headers);

    if (!$sent) {
        return new WP_Error('vkl_mail', 'فرستادن رایانامه ناکام ماند. لطفاً با شماره همراه وارد شوید.');
    }

    return true;
}
