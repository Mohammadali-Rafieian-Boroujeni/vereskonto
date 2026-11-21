<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Force IPv4 when talking to the SMS gateway.
 *
 * A very common cause of "cURL error 28 ... 0 bytes received" on shared
 * hosting is a broken IPv6 route: cURL tries the AAAA address first and
 * hangs until the timeout instead of falling back. Pinning these calls to
 * IPv4 removes that stall. Scoped to the gateway host so nothing else on
 * the site is affected.
 */
/**
 * Circuit breaker for the SMS gateway.
 *
 * WP-Cron runs during ordinary page visits. If the gateway (or a relay in
 * front of it) stops answering, every queued message would sit through the
 * full timeout — and with a retry and several recipients that is minutes of
 * a visitor's page load, which looks exactly like the whole site being
 * down. After a few consecutive failures we therefore stop dialling out
 * for a short while and fail instantly instead.
 */
const VKL_SMS_BREAKER_KEY  = 'vkl_sms_gateway_failures';
const VKL_SMS_BREAKER_TRIP = 2;    // consecutive failures before opening
const VKL_SMS_BREAKER_REST = 300;  // seconds to stay open

/**
 * Is this URL one of our SMS gateways?
 */
function vkl_is_gateway_url($url): bool
{
    if (!is_string($url)) {
        return false;
    }

    $gateways = apply_filters('vkl_sms_gateway_hosts', [
        'melipayamak.com',
        'kavenegar.com',
        'sms.ir',
        'payamak-panel.com',
    ]);

    foreach ($gateways as $needle) {
        if (false !== strpos($url, (string) $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Has the gateway already failed at least once recently? Callers use this
 * to skip their retry so a dead gateway cannot stack up waiting time.
 */
function vkl_sms_gateway_failing(): bool
{
    $state = get_transient(VKL_SMS_BREAKER_KEY);

    return is_array($state) && (int) ($state['count'] ?? 0) > 0;
}

/**
 * Once the gateway has missed once, cut the patience right down so the
 * remaining attempts before the breaker opens stay short.
 */
add_filter('http_request_args', 'vkl_sms_shorten_when_failing', 10, 2);

function vkl_sms_shorten_when_failing($args, $url)
{
    if (!defined('VKL_GATEWAY_TEST') && vkl_is_gateway_url($url) && vkl_sms_gateway_failing()) {
        $args['timeout'] = 6;
    }

    return $args;
}

/**
 * Short-circuit gateway calls while the breaker is open.
 */
add_filter('pre_http_request', 'vkl_sms_breaker_gate', 10, 3);

function vkl_sms_breaker_gate($preempt, $args, $url)
{
    // The admin's own connectivity test must always be allowed through.
    if (defined('VKL_GATEWAY_TEST') || !vkl_is_gateway_url($url)) {
        return $preempt;
    }

    $state = get_transient(VKL_SMS_BREAKER_KEY);

    if (is_array($state) && (int) ($state['count'] ?? 0) >= VKL_SMS_BREAKER_TRIP) {
        return new WP_Error(
            'vkl_gateway_paused',
            __('The SMS gateway is not responding, so sending is paused for a few minutes.', 'vereskala-login')
        );
    }

    return $preempt;
}

/**
 * Count outcomes so the breaker knows when to open and when to close.
 */
add_action('http_api_debug', 'vkl_sms_breaker_record', 10, 5);

function vkl_sms_breaker_record($response, $context, $class, $args, $url): void
{
    if (defined('VKL_GATEWAY_TEST') || !vkl_is_gateway_url($url)) {
        return;
    }

    if (!is_wp_error($response)) {
        // A real answer — even an error code — means the path is alive.
        delete_transient(VKL_SMS_BREAKER_KEY);

        return;
    }

    // Our own short-circuit must not count as a fresh failure.
    if ('vkl_gateway_paused' === $response->get_error_code()) {
        return;
    }

    $state = get_transient(VKL_SMS_BREAKER_KEY);
    $count = is_array($state) ? (int) ($state['count'] ?? 0) : 0;

    set_transient(
        VKL_SMS_BREAKER_KEY,
        ['count' => $count + 1, 'at' => time()],
        VKL_SMS_BREAKER_REST
    );
}

add_action('http_api_curl', 'vkl_force_ipv4_for_gateway', 10, 3);

function vkl_force_ipv4_for_gateway($handle, $args, $url)
{
    if (!is_string($url)) {
        return;
    }

    // Applies to whichever SMS gateway is in use, so the protection
    // survives a change of provider.
    $gateways = apply_filters('vkl_sms_gateway_hosts', [
        'melipayamak.com',
        'kavenegar.com',
        'sms.ir',
        'payamak-panel.com',
    ]);

    $match = false;

    foreach ($gateways as $needle) {
        if (false !== strpos($url, (string) $needle)) {
            $match = true;
            break;
        }
    }

    // VKL_BIND_PROBE lets the Status page send its "what IP does the world
    // see" request through the very same binding, so the admin can verify
    // the configured address really takes effect instead of guessing.
    if (!$match && !defined('VKL_BIND_PROBE')) {
        return;
    }

    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }

    // Fail fast on the connect phase rather than burning the whole budget.
    if (defined('CURLOPT_CONNECTTIMEOUT')) {
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 6);
    }

    // Send from a specific local IP. Hosts often hand out an extra
    // Iranian address for payment gateways; the same address can carry
    // the SMS traffic, which satisfies gateways that reject foreign IPs
    // without moving the site anywhere. Only works when the address is
    // actually bound to this server's network interface.
    $bind_ip = trim((string) get_option('vkl_sms_bind_ip', ''));

    if ('' !== $bind_ip && defined('CURLOPT_INTERFACE')) {
        curl_setopt($handle, CURLOPT_INTERFACE, $bind_ip);
    }

    // Optional relay: some Iranian gateways refuse foreign server IPs. A
    // small proxy inside Iran fixes that without moving the whole site,
    // and because this hook only matches gateway hosts, the rest of the
    // site keeps talking to the internet directly.
    $proxy_host = trim((string) get_option('vkl_sms_proxy_host', ''));
    $proxy_port = (int) get_option('vkl_sms_proxy_port', 0);

    if ('' === $proxy_host || $proxy_port <= 0 || !defined('CURLOPT_PROXY')) {
        return;
    }

    curl_setopt($handle, CURLOPT_PROXY, $proxy_host);
    curl_setopt($handle, CURLOPT_PROXYPORT, $proxy_port);

    $type = (string) get_option('vkl_sms_proxy_type', 'http');

    if ('socks5' === $type && defined('CURLPROXY_SOCKS5')) {
        curl_setopt($handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    }

    $proxy_user = trim((string) get_option('vkl_sms_proxy_user', ''));
    $proxy_pass = (string) get_option('vkl_sms_proxy_pass', '');

    if ('' !== $proxy_user && defined('CURLOPT_PROXYUSERPWD')) {
        curl_setopt($handle, CURLOPT_PROXYUSERPWD, $proxy_user . ':' . $proxy_pass);
    }
}

/**
 * ارسال پیامک OTP
 */
function vkl_send_sms($mobile, $otp)
{
    $pattern = trim((string) get_option('vkl_pattern_code'));

    if ('' === $pattern) {
        return new WP_Error(
            'sms_not_configured',
            __('The SMS gateway is not configured yet.', 'vereskala-login')
        );
    }

    // Go through the shared SMS driver rather than calling one provider
    // directly. This is what makes switching providers actually switch the
    // login code too — this function used to talk to one gateway on its
    // own, so a new driver would have left the most important message of
    // all still pointing at the old gateway.
    if (function_exists('vkl') && vkl()->has('sms.driver')) {
        $result = vkl('sms.driver')->send_pattern(
            [$mobile],
            $pattern,
            ['verification-code' => (string) $otp]
        );

        if (!empty($result['ok'])) {
            return true;
        }

        error_log('VKL OTP SEND : ' . (string) ($result['response'] ?? ''));

        return new WP_Error('sms', (string) ($result['response'] ?? 'send failed'));
    }

    return new WP_Error('sms', 'SMS driver unavailable');
}
