<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Replace WooCommerce Login Template
|--------------------------------------------------------------------------
*/

add_filter('wc_get_template', 'vkl_override_wc_template', 10, 5);

function vkl_override_wc_template($located, $template_name, $args, $template_path, $default_path)
{

    if (
        !is_admin()
        &&
        function_exists('is_account_page')
        &&
        is_account_page()
        &&
        !is_user_logged_in()
    ) {

        if ($template_name === 'myaccount/form-login.php') {

            $override = VKL_PATH . 'woocommerce/myaccount/form-login.php';

            // Only take over the template when the file is really there.
            // Handing WooCommerce a path that does not exist produced a
            // notice on every visit to the account page, and enough of
            // those pile up into 503s under load.
            if (is_readable($override)) {

                return $override;

            }

        }

    }

    return $located;

}