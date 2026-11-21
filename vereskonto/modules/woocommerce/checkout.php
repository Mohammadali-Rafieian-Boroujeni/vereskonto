<?php
/**
 * Checkout access control.
 *
 * When 'checkout_require_login' is enabled, guests who reach the
 * checkout page are redirected to the My Account login page (where the
 * SMS login form lives). A short-lived cookie remembers the intent, so
 * after a successful login — via SMS OTP or the standard WooCommerce
 * form — the customer is sent straight back to checkout instead of the
 * account dashboard.
 *
 * The 'order-pay' and 'order-received' endpoints are deliberately
 * excluded: payment links for existing orders and thank-you pages must
 * keep working for guests.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Checkout
 */
final class VKL_Wc_Checkout
{
    /**
     * Cookie remembering that the login was triggered from checkout.
     */
    private const COOKIE = 'vkl_after_login';

    /**
     * Cookie lifetime in seconds.
     */
    private const COOKIE_TTL = 900;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * @param VKL_Config $config Config.
     */
    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_redirect_guest'], 8);
        add_filter('vkl_login_redirect', [$this, 'after_login_redirect']);
        add_filter('woocommerce_login_redirect', [$this, 'after_login_redirect']);
    }

    /**
     * Send guests from checkout to the login page.
     */
    public function maybe_redirect_guest(): void
    {
        if (
            !$this->config->get_bool('checkout_require_login', false)
            || is_user_logged_in()
            || !function_exists('is_checkout')
            || !is_checkout()
            || is_wc_endpoint_url('order-pay')
            || is_wc_endpoint_url('order-received')
        ) {
            return;
        }

        // Remember the intent so the login success handler can return here.
        setcookie(
            self::COOKIE,
            'checkout',
            [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        wp_safe_redirect(wc_get_page_permalink('myaccount'));
        exit;
    }

    /**
     * After a successful login that was triggered from checkout, go back
     * to checkout. Works for both the SMS OTP login (vkl_login_redirect)
     * and the standard WooCommerce form (woocommerce_login_redirect).
     *
     * @param mixed $redirect Default redirect target.
     *
     * @return mixed
     */
    public function after_login_redirect(mixed $redirect): mixed
    {
        if ('checkout' !== (string) ($_COOKIE[self::COOKIE] ?? '')) {
            return $redirect;
        }

        // One-shot cookie: expire it immediately.
        setcookie(self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/');
        unset($_COOKIE[self::COOKIE]);

        return wc_get_checkout_url();
    }
}
