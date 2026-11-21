<?php
/**
 * SMS sender service — throttling + logging + driver, one entry point
 * for every outbound SMS in the platform.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Sender
 */
final class VKL_Sms_Sender
{
    private VKL_Sms_Driver $driver;

    private VKL_Sms_Log_Repository $log;

    private VKL_Logger $logger;

    private VKL_Config $config;

    public function __construct(VKL_Sms_Driver $driver, VKL_Sms_Log_Repository $log, VKL_Logger $logger, VKL_Config $config)
    {
        $this->driver = $driver;
        $this->config = $config;
        $this->log    = $log;
        $this->logger = $logger;
    }

    /**
     * Send a pattern SMS to a single recipient, logged, optionally throttled.
     *
     * @param string               $mobile         Local-format mobile.
     * @param string               $type           Message type for the log, e.g. 'order.completed'.
     * @param string               $pattern        Pattern code.
     * @param array<string, mixed> $params         Pattern parameters.
     * @param int                  $throttle_hours 0 = no throttle; otherwise max 1 msg of this type per window.
     */
    public function send(string $mobile, string $type, string $pattern, array $params, int $throttle_hours = 0): bool
    {
        if ('' === $mobile || '' === $pattern) {
            return false;
        }

        if ($throttle_hours > 0 && $this->log->count_recent($mobile, $type, $throttle_hours) > 0) {
            $this->logger->debug('SMS throttled.', ['type' => $type]);

            return false;
        }

        $link = $this->link_param($params);

        if ('' !== $link) {
            $this->log->record(
                $mobile,
                $type,
                $pattern,
                $params,
                VKL_Sms_Log_Repository::STATUS_FAILED,
                $this->link_reason($link)
            );

            $this->logger->error('SMS not sent: web address in a pattern variable.', [
                'type'  => $type,
                'param' => $link,
            ]);

            return false;
        }

        $result = $this->driver->send_pattern([$mobile], $pattern, $params);

        $this->log->record(
            $mobile,
            $type,
            $pattern,
            $params,
            $result['ok'] ? VKL_Sms_Log_Repository::STATUS_SENT : VKL_Sms_Log_Repository::STATUS_FAILED,
            $result['response']
        );

        if (!$result['ok']) {
            $this->logger->error('SMS failed.', ['type' => $type, 'response' => mb_substr($result['response'], 0, 500)]);
        }

        return $result['ok'];
    }

    /**
     * Send one pattern with identical params to many recipients (one API
     * call per chunk, one log row per recipient).
     *
     * @param string[]             $mobiles Local-format mobiles.
     * @param array<string, mixed> $params  Pattern parameters.
     *
     * @return array{sent: int, failed: int}
     */
    public function send_bulk(array $mobiles, string $type, string $pattern, array $params, int $throttle_hours = 0): array
    {
        $sent    = 0;
        $failed  = 0;
        $skipped = 0;

        // The pattern endpoint accepts exactly ONE recipient per request
        // («گزینه گیرندگان باید شامل 1 آیتم باشد») — a 50-recipient chunk
        // failed the WHOLE batch. One call per mobile, individually
        // logged, with a short breather to stay gateway-friendly.
        foreach ($mobiles as $mobile) {
            // Per-recipient variables: any param whose value is the
            // literal word `auto` is resolved per mobile. Currently
            // supported: name — «تکمیل گزینه params.name الزامی است»
            // was exactly this gap: patterns with %name% need a value
            // for every single recipient.
            // Anti-repeat: never send the same type to the same mobile
            // twice inside the window. Protects against overlapping cron
            // runs and a customer re-triggering the same event (e.g.
            // abandoning a cart again the next day).
            if ($throttle_hours > 0 && $this->log->count_recent($mobile, $type, $throttle_hours) > 0) {
                ++$skipped;

                continue;
            }

            $params_for = $this->resolve_auto_params($params, $mobile);

            // Checked per recipient, because an `auto` variable only
            // becomes a concrete value here — and a thousand doomed calls
            // are a thousand wasted seconds.
            $link = $this->link_param($params_for);

            if ('' !== $link) {
                $this->log->record(
                    $mobile,
                    $type,
                    $pattern,
                    $params_for,
                    VKL_Sms_Log_Repository::STATUS_FAILED,
                    $this->link_reason($link)
                );

                ++$failed;

                continue;
            }

            $result = $this->driver->send_pattern([$mobile], $pattern, $params_for);

            $this->log->record(
                $mobile,
                $type,
                $pattern,
                $params_for,
                $result['ok'] ? VKL_Sms_Log_Repository::STATUS_SENT : VKL_Sms_Log_Repository::STATUS_FAILED,
                $result['ok'] ? '' : $result['response']
            );

            $result['ok'] ? ++$sent : ++$failed;

            usleep(150000);
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Name of the first parameter carrying a web address, or ''.
     *
     * The shared service line refuses these outright, so sending is
     * pointless; the shop is told which variable to fix instead of
     * reading "InvalidData" and guessing. Switchable, in case a panel
     * ever approves the domain.
     *
     * @param array<string, mixed> $params Pattern parameters.
     */
    private function link_param(array $params): string
    {
        if (!$this->config->get_bool('sms_block_links', true) || !class_exists('VKL_Melipayamak')) {
            return '';
        }

        foreach ($params as $key => $value) {
            if (VKL_Melipayamak::looks_like_url((string) $value)) {
                return (string) $key;
            }
        }

        return '';
    }

    /**
     * Reason text stored in the log for a blocked message.
     */
    private function link_reason(string $key): string
    {
        return sprintf(
            /* translators: %s: name of the pattern variable holding a web address. */
            __('Not sent from here: variable "%s" contains a web address, and the shared service line refuses those. Put the address inside the approved pattern text, or have the provider approve your domain.', 'vereskala-login'),
            $key
        );
    }

    /**
     * Friendly first name for pattern params, with translatable fallback.
     */
    public function resolve_name(int $user_id): string
    {
        if ($user_id > 0) {
            foreach (['first_name', 'billing_first_name'] as $key) {
                $name = trim((string) get_user_meta($user_id, $key, true));

                if ('' !== $name) {
                    return $name;
                }
            }
        }

        // Brand fallback for customers without a stored name
        // (configurable; defaults to the Vereskala community word).
        $fallback = $this->config->get_string('default_customer_name');

        return '' !== $fallback ? $fallback : 'ورسکالایی';
    }

    /**
     * Per-recipient dynamic variables: any param set to the literal
     * `auto` resolves per mobile. Supported keys:
     *   name   → customer display name
     *   wallet → wallet balance (formatted)
     *   points → club points balance
     *   cart   → total of their latest abandoned cart (formatted)
     *
     * @param array<string, string> $params Raw campaign params.
     *
     * @return array<string, string>
     */
    private function resolve_auto_params(array $params, string $mobile): array
    {
        $auto = array_filter(
            $params,
            static fn($v): bool => 'auto' === strtolower(trim((string) $v))
        );

        if ([] === $auto) {
            return $params;
        }

        $user_id = $this->user_by_mobile($mobile);

        foreach (array_keys($auto) as $key) {
            switch ($key) {
                case 'name':
                    $name = $user_id > 0 ? $this->resolve_name($user_id) : '';

                    $params[$key] = '' !== $name ? $name : 'ورسکالایی';
                    break;

                case 'wallet':
                    $params[$key] = (string) (int) ($user_id > 0 ? vkl('wallet')->balance($user_id) : 0);
                    break;

                case 'points':
                    $params[$key] = (string) ($user_id > 0 ? (int) vkl('loyalty.repository')->balance($user_id) : 0);
                    break;

                case 'cart':
                    global $wpdb;

                    $params[$key] = (string) (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
                        "SELECT total FROM {$wpdb->prefix}vkl_cart WHERE mobile = %s AND status = 'abandoned' ORDER BY updated_at DESC LIMIT 1",
                        $mobile
                    ));
                    break;

                default:
                    // Unknown auto key: send empty rather than the word 'auto'.
                    $params[$key] = '';
            }
        }

        return $params;
    }

    /**
     * WP user id for a mobile (billing_phone lookup), 0 when unknown.
     */
    private function user_by_mobile(string $mobile): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'billing_phone' AND meta_value = %s LIMIT 1",
            $mobile
        ));
    }

}
