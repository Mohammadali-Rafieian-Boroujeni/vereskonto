<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ورود یا ایجاد کاربر با شماره موبایل
 */
function vkl_login_user($mobile)
{
    $mobile = vkl_normalize_mobile($mobile);

    /*
    |--------------------------------------------------------------------------
    | جستجو بر اساس شماره موبایل
    |--------------------------------------------------------------------------
    */

    $users = get_users([
        'meta_key'   => 'billing_phone',
        'meta_value' => $mobile,
        'number'     => 1,
        'fields'     => 'all'
    ]);

    if (!empty($users)) {

        $user = $users[0];

    } else {

        /*
        |--------------------------------------------------------------------------
        | ساخت کاربر جدید
        |--------------------------------------------------------------------------
        */

        $username = 'vk_' . $mobile;

        if (username_exists($username)) {

            $username = $username . '_' . wp_rand(100,999);

        }

        $email = $username . '@vereskala.local';

        while (email_exists($email)) {

            $email = 'vk_' . wp_rand(100000,999999) . '@vereskala.local';

        }

        $user_id = wp_insert_user([

            'user_login' => $username,

            'user_pass'  => wp_generate_password(32),

            'user_email' => $email,

            'role'       => 'customer'

        ]);

        if (is_wp_error($user_id)) {

            return $user_id;

        }

        update_user_meta($user_id, 'billing_phone', $mobile);
        update_user_meta($user_id, 'shipping_phone', $mobile);

        $user = get_user_by('id', $user_id);

    }

    /*
    |--------------------------------------------------------------------------
    | ورود
    |--------------------------------------------------------------------------
    */

    wp_clear_auth_cookie();

    wp_set_current_user($user->ID);

    wp_set_auth_cookie($user->ID, true, is_ssl());

    do_action('wp_login', $user->user_login, $user);

    return $user->ID;
}

/**
 * ورود یا ایجاد کاربر با رایانامه
 */
function vkl_login_user_email($email)
{
    $email = sanitize_email($email);

    if (!is_email($email)) {
        return new WP_Error('vkl_email', 'نشانی رایانامه درست نیست.');
    }

    $user = get_user_by('email', $email);

    if (!$user) {

        $username = sanitize_user(current(explode('@', $email)), true);

        if ('' === $username || username_exists($username)) {
            $username = 'vk_' . wp_rand(100000, 999999);
        }

        while (username_exists($username)) {
            $username = $username . wp_rand(10, 99);
        }

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_pass'  => wp_generate_password(32),
            'user_email' => $email,
            'role'       => 'customer'
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        update_user_meta($user_id, 'billing_email', $email);

        $user = get_user_by('id', $user_id);
    }

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());
    do_action('wp_login', $user->user_login, $user);

    return $user->ID;
}
