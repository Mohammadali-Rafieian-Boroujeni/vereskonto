<?php
/**
 * Mobile ↔ national-ID matching ("تطبیق شماره همراه با کد ملی").
 *
 * Asks an external inquiry service whether a mobile number is registered
 * in the name of the owner of a national ID. The service is described by
 * settings (address, method, auth header, request body, and where in the
 * JSON reply the yes/no sits), so any provider with a simple JSON API can
 * be connected without changing code.
 *
 * Results are kept per user (status + a hash of the number/ID pair they
 * were checked for), so the provider is called again only when the
 * mobile or the national ID actually changes.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class VKL_Identity
{
    public const META_STATUS = 'vkl_identity_status'; // match | mismatch | error
    public const META_HASH   = 'vkl_identity_hash';
    public const META_AT     = 'vkl_identity_at';
    public const META_MSG    = 'vkl_identity_msg';

    /** Shahkar inquiry exactly as the provider documents it (POST /api/sw1/Shahkar). */
    public const DEFAULTS = [
        'url'    => 'https://s.api.ir/api/sw1/Shahkar',
        'header' => 'Authorization',
        'body'   => '{"nationalCode":"{national_id}","mobile":"{mobile}","isCompany":false}',
        'path'   => 'data',
        'ok'     => 'true,1',
    ];

    /**
     * A setting, falling back to the documented default when left blank.
     */
    public static function setting(string $key): string
    {
        $value = trim((string) get_option('vkl_id_' . ('ok' === $key ? 'ok_value' : $key), ''));

        return '' !== $value ? $value : (string) (self::DEFAULTS[$key] ?? '');
    }

    /** off | record (verify and show) | require (credit requests need a match) */
    public static function mode(): string
    {
        $mode = (string) get_option('vkl_id_mode', 'off');

        return in_array($mode, ['record', 'require'], true) ? $mode : 'off';
    }

    public static function enabled(): bool
    {
        return 'off' !== self::mode() && '' !== trim((string) get_option('vkl_id_token', ''));
    }

    private static function digits(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return (string) preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * Mobile as the provider wants it: local (09123456789),
     * international (989123456789) or bare (9123456789).
     */
    private static function format_mobile(string $mobile): string
    {
        $local = vkl_normalize_mobile($mobile); // 09xxxxxxxxx

        switch ((string) get_option('vkl_id_mobile_format', 'local')) {
            case 'intl':
                return '98' . substr($local, 1);
            case 'bare':
                return substr($local, 1);
            default:
                return $local;
        }
    }

    /**
     * Read a dotted path (data.result.matched, items.0.ok) from a reply.
     *
     * @param mixed $data Decoded JSON.
     *
     * @return array{found: bool, value: mixed}
     */
    private static function dig($data, string $path): array
    {
        foreach (array_filter(explode('.', trim($path)), 'strlen') as $key) {
            if (is_array($data) && array_key_exists($key, $data)) {
                $data = $data[$key];
            } else {
                return ['found' => false, 'value' => null];
            }
        }

        return ['found' => true, 'value' => $data];
    }

    private static function as_text($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return strtolower(trim((string) $value));
    }

    /**
     * One inquiry. Never throws; failures come back as status 'error'.
     *
     * @return array{status: string, message: string, raw: string}
     */
    public static function check(string $mobile, string $national_id): array
    {
        $url = self::setting('url');

        if ('' === trim((string) get_option('vkl_id_token', ''))) {
            return ['status' => 'error', 'message' => 'توکن سرویس وارد نشده است.', 'raw' => ''];
        }

        $national_id = self::digits($national_id);
        $mobile_out  = self::format_mobile($mobile);
        $method      = 'GET' === strtoupper((string) get_option('vkl_id_method', 'POST')) ? 'GET' : 'POST';

        $args = [
            'method'  => $method,
            'timeout' => max(3, min(30, (int) get_option('vkl_id_timeout', 8))),
            'headers' => ['Accept' => 'application/json'],
        ];

        $header = self::setting('header');
        $token  = trim((string) get_option('vkl_id_token', ''));

        // A bare token on the Authorization header means a Bearer token.
        if ('authorization' === strtolower($header) && 0 !== stripos($token, 'bearer ') && 0 !== stripos($token, 'basic ')) {
            $token = 'Bearer ' . $token;
        }

        if ('' !== $header && '' !== $token) {
            $args['headers'][$header] = $token;
        }

        if ('GET' === $method) {
            $url = str_replace(['{mobile}', '{national_id}'], [rawurlencode($mobile_out), rawurlencode($national_id)], $url);
        } else {
            $template = self::setting('body');

            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = str_replace(['{mobile}', '{national_id}'], [$mobile_out, $national_id], $template);
        }

        // A provider that just timed out is not asked again for five
        // minutes: every blocked inquiry holds a PHP worker for up to the
        // full timeout, and a few of those in a row slow the whole site.
        if (false !== get_transient('vkl_id_down')) {
            return ['status' => 'error', 'message' => 'سرویس تطبیق لحظاتی پیش پاسخ نداد؛ چند دقیقهٔ دیگر دوباره تلاش می‌شود.', 'raw' => ''];
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            set_transient('vkl_id_down', 1, 5 * MINUTE_IN_SECONDS);

            return ['status' => 'error', 'message' => 'اتصال به سرویس برقرار نشد: ' . $response->get_error_message(), 'raw' => ''];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code >= 500) {
            set_transient('vkl_id_down', 1, 5 * MINUTE_IN_SECONDS);
        }

        if ($code < 200 || $code >= 300) {
            return ['status' => 'error', 'message' => sprintf('سرویس کد %d برگرداند.', $code), 'raw' => mb_substr($raw, 0, 600)];
        }

        $path = self::setting('path');

        if (!is_array($data)) {
            return ['status' => 'error', 'message' => 'پاسخ سرویس JSON معتبر نیست.', 'raw' => mb_substr($raw, 0, 600)];
        }

        $hit = self::dig($data, $path);

        if (!$hit['found']) {
            return ['status' => 'error', 'message' => 'مسیر نتیجه در پاسخ سرویس پیدا نشد؛ «مسیر نتیجه» را با نمونهٔ پاسخ مقایسه کنید.', 'raw' => mb_substr($raw, 0, 600)];
        }

        // No verdict at all (null) is a service-side failure, not a mismatch
        // — wrong token, unknown number, quota. Never blame the customer.
        if (null === $hit['value']) {
            $why = isset($data['message']) && is_string($data['message']) && '' !== $data['message'] ? $data['message'] : 'سرویس نتیجه‌ای برنگرداند.';

            return ['status' => 'error', 'message' => mb_substr($why, 0, 200), 'raw' => mb_substr($raw, 0, 600)];
        }

        $ok_values = array_map('strtolower', array_map('trim', explode(',', self::setting('ok'))));
        $matched   = in_array(self::as_text($hit['value']), $ok_values, true);

        return ['status' => $matched ? 'match' : 'mismatch', 'message' => '', 'raw' => mb_substr($raw, 0, 600)];
    }

    /**
     * Verify (or reuse the stored verdict for) a user's current mobile and
     * national ID.
     *
     * @return array{status: string, message: string}
     */
    public static function verify_user(int $user_id, bool $force = false): array
    {
        $mobile   = (string) get_user_meta($user_id, 'billing_phone', true);
        $national = self::digits((string) get_user_meta($user_id, 'vkl_national_id', true));

        if (!self::enabled() || '' === $mobile || '' === $national || !VKL_Wc_Account::is_valid_national_id($national)) {
            return ['status' => 'none', 'message' => ''];
        }

        $hash   = md5(vkl_normalize_mobile($mobile) . '|' . $national);
        $status = (string) get_user_meta($user_id, self::META_STATUS, true);
        $at     = (int) get_user_meta($user_id, self::META_AT, true);

        $same_pair = $hash === (string) get_user_meta($user_id, self::META_HASH, true);

        if (!$force && $same_pair) {
            // A real verdict for this exact pair is final until either changes.
            if (in_array($status, ['match', 'mismatch'], true)) {
                return ['status' => $status, 'message' => ''];
            }

            // A failed inquiry (provider down) is retried after ten minutes.
            if ('error' === $status && (time() - $at) <= 10 * MINUTE_IN_SECONDS) {
                return ['status' => 'error', 'message' => (string) get_user_meta($user_id, self::META_MSG, true)];
            }
        }

        $result = self::check($mobile, $national);

        update_user_meta($user_id, self::META_STATUS, $result['status']);
        update_user_meta($user_id, self::META_HASH, $hash);
        update_user_meta($user_id, self::META_AT, time());
        update_user_meta($user_id, self::META_MSG, $result['message']);

        return ['status' => $result['status'], 'message' => $result['message']];
    }

    /**
     * Stored verdict only (no network): '' when never checked.
     */
    public static function stored_status(int $user_id): string
    {
        return (string) get_user_meta($user_id, self::META_STATUS, true);
    }

    /**
     * Small coloured label for admin screens.
     */
    public static function badge(int $user_id): string
    {
        if (!self::enabled()) {
            return '';
        }

        switch (self::stored_status($user_id)) {
            case 'match':
                return '<span style="color:#157f3a;font-weight:700" title="شمارهٔ همراه به نام صاحب این کد ملی است">✓ تطبیق دارد</span>';
            case 'mismatch':
                return '<span style="color:#b32d2e;font-weight:700" title="شمارهٔ همراه به نام صاحب این کد ملی نیست">✗ مغایرت</span>';
            case 'error':
                return '<span style="color:#996800" title="سرویس پاسخ معتبر نداد">؟ بررسی نشد</span>';
            default:
                return '<span style="color:#888">— بررسی نشده</span>';
        }
    }
}
