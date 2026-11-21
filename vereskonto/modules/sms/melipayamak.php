<?php
/**
 * Melipayamak SMS driver (REST, rest.payamak-panel.com).
 *
 * Every message goes through /api/SendSMS/BaseServiceNumber: the shop's
 * own approved pattern (bodyId) over the shared service line. That needs
 * no dedicated line, reaches numbers on the operator blacklist, and — the
 * reason it is the only path here — carries the shop's own wording, which
 * is what the operator accepts.
 *
 * Fields are form-urlencoded and authenticated with the panel
 * username/password.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.71.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Melipayamak
 */
final class VKL_Sms_Melipayamak implements VKL_Sms_Driver
{
    private const BASE = 'https://rest.payamak-panel.com/api/SendSMS';


    private VKL_Config $config;

    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    public function is_configured(): bool
    {
        return '' !== $this->config->get_string('meli_username')
            && '' !== $this->config->get_string('meli_password');
    }

    /**
     * Send a pattern message to one or more mobiles.
     *
     * The pattern id goes in bodyId and the parameter VALUES are joined
     * with semicolons, which is how this gateway fills a pattern — so the
     * order the parameters are written in must match the order of the
     * variables inside the approved pattern.
     *
     * @param string[]             $mobiles Recipients.
     * @param string               $pattern bodyId of the approved pattern.
     * @param array<string, mixed> $params  Values, in pattern order.
     *
     * @return array{ok: bool, response: string}
     */
    public function send_pattern(array $mobiles, string $pattern, array $params): array
    {
        if (!$this->is_configured()) {
            return ['ok' => false, 'response' => 'gateway not configured'];
        }

        if ('' === trim($pattern)) {
            return ['ok' => false, 'response' => 'no pattern id configured'];
        }

        // Always the shared service line with the shop's OWN approved
        // pattern. The gateway's built-in one-time-code method sends its
        // own wording instead, and the operator rejects that — which is
        // why codes were being accepted by the panel but never delivered.
        // Empty values are rejected by the gateway with "invalid data",
        // and a semicolon-separated list would also shift every following
        // value into the wrong blank. Substitute a dash so the positions
        // stay aligned and the message still goes out.
        $text   = self::pattern_text($params);
        $values = explode(';', $text);

        // The gateway answers "InvalidData" without saying which field it
        // objected to, so record exactly what left the site. Switch on
        // with: define('VKL_SMS_DEBUG', true); in wp-config.php
        if (defined('VKL_SMS_DEBUG') && VKL_SMS_DEBUG) {
            error_log(sprintf(
                'VKL SMS OUT | bodyId=%s | values=%d | text=%s',
                $pattern,
                count($values),
                $text
            ));
        }

        $sent   = 0;
        $errors = [];

        foreach ($mobiles as $mobile) {
            $to = $this->local_number((string) $mobile);

            if ('' === $to) {
                $errors[] = 'invalid number';

                continue;
            }

            $result = $this->post('/BaseServiceNumber', [
                'to'     => $to,
                'bodyId' => trim($pattern),
                'text'   => $text,
            ]);

            if ($result['ok']) {
                ++$sent;
            } else {
                $errors[] = $result['response'];
            }

            if (count($mobiles) > 1) {
                usleep(150000);
            }
        }

        if ($sent > 0 && [] === $errors) {
            return ['ok' => true, 'response' => 'sent'];
        }

        if ($sent > 0) {
            return ['ok' => true, 'response' => 'partial: ' . implode(' | ', array_unique($errors))];
        }

        return ['ok' => false, 'response' => implode(' | ', array_unique($errors)) ?: 'send failed'];
    }

    /**
     * This gateway has no documented REST phonebook endpoint, so contacts
     * are simply not synced rather than failing noisily.
     */
    public function supports_phonebook(): bool
    {
        return false;
    }

    public function add_contact(string $phonebook_id, string $mobile, string $name): array
    {
        return ['ok' => false, 'response' => 'phonebook sync is not available on this gateway'];
    }

    /**
     * The exact string that goes into the gateway's "text" field.
     *
     * Public and static so the SMS log can show operators precisely what
     * left the site — a report that rebuilds this rule separately would
     * drift from the sender the first time either side changes.
     *
     * @param array<string, mixed> $params Values, in pattern order.
     */
    public static function pattern_text(array $params): string
    {
        $values = [];

        foreach ($params as $value) {
            $value    = trim((string) $value);
            $values[] = '' !== $value ? $value : '-';
        }

        return implode(';', $values);
    }

    /**
     * Does this value look like a web address?
     *
     * Measured against the live panel on 24.08.2026 with pattern 506583:
     * the same four values went through with a plain word in the last
     * slot, and were refused with "https://vereskala.com/product/…",
     * with the same address without the scheme, and with the bare domain.
     * Shared-line patterns simply do not carry links.
     */
    public static function looks_like_url(string $value): bool
    {
        $value = trim($value);

        if ('' === $value) {
            return false;
        }

        if (false !== strpos($value, '://') || 0 === stripos($value, 'www.')) {
            return true;
        }

        // A bare domain: label.tld, optionally with a path behind it.
        return 1 === preg_match('~\b[a-z0-9][a-z0-9-]*\.(com|net|org|ir|co|info|shop|store|site|online|me|io|app)\b~i', $value);
    }

    /**
     * POST a form-urlencoded request and normalise the answer.
     *
     * @param array<string, string> $fields Extra fields.
     *
     * @return array{ok: bool, response: string}
     */
    private function post(string $path, array $fields): array
    {
        $body = array_merge(
            [
                'username' => $this->config->get_string('meli_username'),
                'password' => $this->config->get_string('meli_password'),
            ],
            $fields
        );

        $args = [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => $body,
        ];

        $response = wp_remote_post(self::BASE . $path, $args);

        if (is_wp_error($response)
            && (!function_exists('vkl_sms_gateway_failing') || !vkl_sms_gateway_failing())
        ) {
            usleep(400000);
            $response = wp_remote_post(self::BASE . $path, $args);
        }

        if (is_wp_error($response)) {
            return ['ok' => false, 'response' => 'network: ' . $response->get_error_message()];
        }

        $raw = trim((string) wp_remote_retrieve_body($response));

        if (defined('VKL_SMS_DEBUG') && VKL_SMS_DEBUG) {
            error_log('VKL SMS REPLY | ' . mb_substr($raw, 0, 500));
        }

        return $this->interpret($raw);
    }

    /**
     * The gateway answers either as JSON (RetStatus / StrRetStatus) or as a
     * bare number, so both shapes are handled.
     *
     * @return array{ok: bool, response: string}
     */
    private function interpret(string $raw): array
    {
        $data = json_decode($raw, true);

        if (is_array($data)) {
            $status = isset($data['RetStatus']) ? (int) $data['RetStatus'] : -1;
            $text   = (string) ($data['StrRetStatus'] ?? '');
            $value  = (string) ($data['Value'] ?? '');

            if (1 === $status || 'Ok' === $text) {
                return ['ok' => true, 'response' => '' !== $value ? $value : 'ok'];
            }

            return ['ok' => false, 'response' => $this->status_text($status, $text)];
        }

        // Bare numeric reply: a long id means accepted, a small number is a
        // status code.
        if (is_numeric($raw)) {
            if (strlen($raw) > 3 && (float) $raw > 1000) {
                return ['ok' => true, 'response' => $raw];
            }

            return ['ok' => false, 'response' => $this->status_text((int) $raw, '')];
        }

        return ['ok' => false, 'response' => '' !== $raw ? mb_substr($raw, 0, 160) : 'empty reply'];
    }

    /**
     * Turn a gateway status into something the shop owner can act on.
     */
    private function status_text(int $status, string $fallback): string
    {
        $map = [
            0  => __('The gateway rejected the request.', 'vereskala-login'),
            2  => __('The panel username or password is wrong.', 'vereskala-login'),
            3  => __('Not enough credit in the panel.', 'vereskala-login'),
            4  => __('Sending is limited on this account — contact the provider.', 'vereskala-login'),
            5  => __('The sender number is not valid.', 'vereskala-login'),
            6  => __('The provider is updating their system — try again shortly.', 'vereskala-login'),
            7  => __('The message text contains a blocked word.', 'vereskala-login'),
            10 => __('The account is inactive.', 'vereskala-login'),
            11 => __('The request was not sent.', 'vereskala-login'),
            12 => __('The account is not verified yet.', 'vereskala-login'),
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        if ('' !== $fallback) {
            return $fallback;
        }

        return sprintf(
            /* translators: %d: numeric status from the SMS gateway. */
            __('Gateway status %d.', 'vereskala-login'),
            $status
        );
    }

    /**
     * The gateway expects local numbers beginning with 0.
     */
    private function local_number(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if (0 === strpos($digits, '98')) {
            $digits = substr($digits, 2);
        }

        $digits = ltrim($digits, '0');

        return '' !== $digits ? '0' . $digits : '';
    }
}
