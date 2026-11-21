<?php
/**
 * Mobile app REST API (namespace vereskonto/v1).
 *
 * A small, versioned surface for the Android app: passwordless OTP
 * auth that returns a bearer token, then token-authenticated reads of
 * the customer's own profile, wallet, loyalty and orders. Every route is
 * gated by an on/off setting; auth routes are additionally rate-limited
 * through the existing OTP throttle. Nothing here trusts the caller for
 * anything beyond their own account.
 *
 * @package Vereskonto\Modules\Api
 * @since   1.32.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Api_Rest
 */
final class VKL_Api_Rest
{
    public const NAMESPACE = 'vereskonto/v1';

    private VKL_Config $config;

    private VKL_Validator $validator;

    private VKL_Api_Tokens $tokens;

    /**
     * Resolved user id for the current authenticated request (0 = guest).
     */
    private int $current_user = 0;

    /**
     * Constructor.
     */
    public function __construct(VKL_Config $config, VKL_Validator $validator, VKL_Api_Tokens $tokens)
    {
        $this->config    = $config;
        $this->validator = $validator;
        $this->tokens    = $tokens;
    }

    /**
     * Hooks.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_action('vkl_installment_check', [$this, 'purge']);
    }

    /**
     * Keep the token table tidy.
     */
    public function purge(): void
    {
        $this->tokens->purge_expired();
    }

    /**
     * Register all routes.
     */
    public function routes(): void
    {
        if (!$this->config->get_bool('api_enabled', false)) {
            return;
        }

        $public = '__return_true';
        $auth   = [$this, 'require_token'];

        register_rest_route(self::NAMESPACE, '/ping', [
            'methods'             => 'GET',
            'permission_callback' => $public,
            'callback'            => [$this, 'ping'],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/request-otp', [
            'methods'             => 'POST',
            'permission_callback' => $public,
            'callback'            => [$this, 'request_otp'],
            'args'                => [
                'mobile' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/verify-otp', [
            'methods'             => 'POST',
            'permission_callback' => $public,
            'callback'            => [$this, 'verify_otp'],
            'args'                => [
                'mobile' => ['required' => true, 'type' => 'string'],
                'code'   => ['required' => true, 'type' => 'string'],
                'device' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/auth/logout', [
            'methods'             => 'POST',
            'permission_callback' => $auth,
            'callback'            => [$this, 'logout'],
        ]);

        register_rest_route(self::NAMESPACE, '/me', [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => [$this, 'me'],
        ]);

        register_rest_route(self::NAMESPACE, '/wallet', [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => [$this, 'wallet'],
        ]);

        register_rest_route(self::NAMESPACE, '/loyalty', [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => [$this, 'loyalty'],
        ]);

        register_rest_route(self::NAMESPACE, '/orders', [
            'methods'             => 'GET',
            'permission_callback' => $auth,
            'callback'            => [$this, 'orders'],
            'args'                => [
                'page'     => ['required' => false, 'type' => 'integer'],
                'per_page' => ['required' => false, 'type' => 'integer'],
            ],
        ]);
    }

    /* -------------------------------------------------------------- *
     *  Auth
     * -------------------------------------------------------------- */

    /**
     * Permission callback: validate the Bearer token, remember the user.
     */
    public function require_token(WP_REST_Request $request): bool|WP_Error
    {
        $header = (string) $request->get_header('authorization');

        if (1 !== preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return new WP_Error('vkl_no_token', __('Authorization token missing.', 'vereskala-login'), ['status' => 401]);
        }

        $user_id = $this->tokens->resolve(trim($m[1]));

        if ($user_id <= 0) {
            return new WP_Error('vkl_bad_token', __('Token is invalid or expired.', 'vereskala-login'), ['status' => 401]);
        }

        $this->current_user = $user_id;

        return true;
    }

    /**
     * GET /ping — liveness + whether the API is on.
     */
    public function ping(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok'      => true,
            'name'    => get_bloginfo('name'),
            'version' => defined('VKL_VERSION') ? VKL_VERSION : '',
            'time'    => gmdate('c'),
        ]);
    }

    /**
     * POST /auth/request-otp — send a login code by SMS.
     */
    public function request_otp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $mobile = $this->validator->normalize_mobile((string) $request->get_param('mobile'));

        if (!$this->validator->is_mobile($mobile)) {
            return new WP_Error('vkl_bad_mobile', __('The mobile number is not valid.', 'vereskala-login'), ['status' => 422]);
        }

        if (function_exists('vkl_can_send_otp') && !vkl_can_send_otp($mobile)) {
            return new WP_Error('vkl_rate', __('Too many requests. Please try again later.', 'vereskala-login'), ['status' => 429]);
        }

        $otp = function_exists('vkl_generate_otp') ? (string) vkl_generate_otp() : (string) wp_rand(10000, 99999);

        if (function_exists('vkl_save_otp')) {
            vkl_save_otp($mobile, $otp);
        }

        $sent = function_exists('vkl_send_sms') ? vkl_send_sms($mobile, $otp) : true;

        if (is_wp_error($sent)) {
            return new WP_Error('vkl_sms', $sent->get_error_message(), ['status' => 502]);
        }

        return new WP_REST_Response([
            'ok'         => true,
            'expires_in' => function_exists('vkl_otp_remaining_time') ? (int) vkl_otp_remaining_time($mobile) : 120,
        ]);
    }

    /**
     * POST /auth/verify-otp — check the code, log in / create the user,
     * and hand back a bearer token for subsequent calls.
     */
    public function verify_otp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $mobile = $this->validator->normalize_mobile((string) $request->get_param('mobile'));
        $code   = trim((string) $request->get_param('code'));

        if (!$this->validator->is_mobile($mobile)) {
            return new WP_Error('vkl_bad_mobile', __('The mobile number is not valid.', 'vereskala-login'), ['status' => 422]);
        }

        $expected = function_exists('vkl_get_otp') ? vkl_get_otp($mobile) : false;

        if (false === $expected || !hash_equals((string) $expected, $code)) {
            if (function_exists('vkl_add_failed_attempt')) {
                vkl_add_failed_attempt($mobile);
            }

            return new WP_Error('vkl_bad_code', __('The code is incorrect or has expired.', 'vereskala-login'), ['status' => 401]);
        }

        if (function_exists('vkl_delete_otp')) {
            vkl_delete_otp($mobile);
        }

        $user_id = $this->provision_user($mobile);

        if (is_wp_error($user_id)) {
            return new WP_Error('vkl_user', $user_id->get_error_message(), ['status' => 500]);
        }

        $device = sanitize_text_field((string) $request->get_param('device'));
        $ttl    = max(1, $this->config->get_int('api_token_ttl_days', 90));
        $token  = $this->tokens->issue($user_id, $device, $ttl);

        return new WP_REST_Response([
            'ok'         => true,
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * DAY_IN_SECONDS,
            'user'       => $this->user_payload($user_id),
        ]);
    }

    /**
     * POST /auth/logout — revoke the presented token.
     */
    public function logout(WP_REST_Request $request): WP_REST_Response
    {
        if (1 === preg_match('/^Bearer\s+(.+)$/i', (string) $request->get_header('authorization'), $m)) {
            $this->tokens->revoke(trim($m[1]));
        }

        return new WP_REST_Response(['ok' => true]);
    }

    /* -------------------------------------------------------------- *
     *  Authenticated reads (always the caller's OWN data)
     * -------------------------------------------------------------- */

    /**
     * GET /me — profile.
     */
    public function me(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'user' => $this->user_payload($this->current_user)]);
    }

    /**
     * GET /wallet — balance + festival credit.
     */
    public function wallet(): WP_REST_Response
    {
        $balance = 0.0;
        $promo   = 0.0;

        if (vkl()->has('wallet')) {
            $balance = (float) vkl('wallet')->balance($this->current_user);
        }

        if (vkl()->has('wallet.promos')) {
            $promo = (float) vkl('wallet.promos')->active_balance($this->current_user);
        }

        return new WP_REST_Response([
            'ok'              => true,
            'balance'         => $balance,
            'festival_credit' => $promo,
            'currency'        => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ]);
    }

    /**
     * GET /loyalty — points + tier label.
     */
    public function loyalty(): WP_REST_Response
    {
        $points = 0;
        $tier   = '';

        if (vkl()->has('loyalty.repository')) {
            $points = (int) vkl('loyalty.repository')->balance($this->current_user);
        }

        return new WP_REST_Response([
            'ok'     => true,
            'points' => $points,
            'tier'   => $tier,
        ]);
    }

    /**
     * GET /orders — the customer's recent orders (paged).
     */
    public function orders(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wc_get_orders')) {
            return new WP_REST_Response(['ok' => true, 'orders' => [], 'page' => 1]);
        }

        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(50, max(1, (int) ($request->get_param('per_page') ?: 10)));

        $orders = wc_get_orders([
            'customer_id' => $this->current_user,
            'limit'       => $per_page,
            'paged'       => $page,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $out = [];

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $out[] = [
                'id'       => $order->get_id(),
                'number'   => $order->get_order_number(),
                'status'   => $order->get_status(),
                'total'    => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'date'     => $order->get_date_created() ? $order->get_date_created()->date('c') : '',
                'items'    => $order->get_item_count(),
            ];
        }

        return new WP_REST_Response(['ok' => true, 'orders' => $out, 'page' => $page]);
    }

    /* -------------------------------------------------------------- *
     *  Helpers
     * -------------------------------------------------------------- */

    /**
     * Find-or-create the WP user for a mobile (mirrors the web login).
     */
    private function provision_user(string $mobile): int|WP_Error
    {
        if (function_exists('vkl_login_user')) {
            // Reuse the canonical provisioning, but DON'T set an auth
            // cookie for an API call — resolve the id and move on.
            $existing = get_users([
                'meta_key'   => 'billing_phone',
                'meta_value' => $mobile,
                'number'     => 1,
                'fields'     => 'ID',
            ]);

            if (!empty($existing)) {
                return (int) $existing[0];
            }
        }

        $username = 'vk_' . $mobile;

        if (username_exists($username)) {
            $username .= '_' . wp_rand(100, 999);
        }

        $email = $username . '@vereskala.local';

        while (email_exists($email)) {
            $email = 'vk_' . wp_rand(100000, 999999) . '@vereskala.local';
        }

        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_pass'  => wp_generate_password(32),
            'user_email' => $email,
            'role'       => 'customer',
        ]);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        update_user_meta($user_id, 'billing_phone', $mobile);
        update_user_meta($user_id, 'shipping_phone', $mobile);

        return (int) $user_id;
    }

    /**
     * Public-safe user payload.
     *
     * @return array<string, mixed>
     */
    private function user_payload(int $user_id): array
    {
        $user = get_userdata($user_id);

        return [
            'id'     => $user_id,
            'name'   => $user ? $user->display_name : '',
            'mobile' => (string) get_user_meta($user_id, 'billing_phone', true),
            'email'  => $user && !str_ends_with((string) $user->user_email, '@vereskala.local') ? $user->user_email : '',
        ];
    }
}
