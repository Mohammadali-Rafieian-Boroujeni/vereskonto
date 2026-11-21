<?php
/**
 * WhatsApp gateway (Meta WhatsApp Business Cloud API).
 *
 * Sends pre-approved *template* messages — the only message type Meta
 * allows outside a 24h customer-service window, which is exactly the
 * abandoned-cart / notification use case.
 *
 * Configuration (vkl_ options):
 * - wa_token       Permanent access token of the Meta app.
 * - wa_phone_id    Phone number ID from the WhatsApp Business account.
 * - wa_lang        Template language code (default 'fa').
 *
 * Template body parameters are positional in the Cloud API; this class
 * sends the values of the given params array in order, so templates
 * should declare {{1}}, {{2}}, … matching the same order used for the
 * SMS pattern (name, coupon, …).
 *
 * All attempts are recorded in wp_vkl_sms_log with channel 'whatsapp'.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.6.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wa_Sender
 */
final class VKL_Wa_Sender
{
    /**
     * Log channel identifier.
     */
    public const CHANNEL = 'whatsapp';

    /**
     * Cloud API version.
     */
    private const API_VERSION = 'v19.0';

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Message log.
     */
    private VKL_Sms_Log_Repository $log;

    /**
     * Logger.
     */
    private VKL_Logger $logger;

    /**
     * @param VKL_Config             $config Config.
     * @param VKL_Sms_Log_Repository $log    Central message log.
     * @param VKL_Logger             $logger Core logger.
     */
    public function __construct(VKL_Config $config, VKL_Sms_Log_Repository $log, VKL_Logger $logger)
    {
        $this->config = $config;
        $this->log    = $log;
        $this->logger = $logger;
    }

    /**
     * Whether the gateway has credentials.
     */
    public function is_configured(): bool
    {
        return '' !== $this->config->get_string('wa_token')
            && '' !== $this->config->get_string('wa_phone_id');
    }

    /**
     * Send a WhatsApp template message and record the outcome.
     *
     * @param string               $mobile         Recipient (local format, e.g. 09123456789).
     * @param string               $type           Log type, e.g. 'cart.abandoned'.
     * @param string               $template       Approved template name.
     * @param array<string, mixed> $params         Body parameters (values used positionally).
     * @param int                  $throttle_hours Max one message per recipient/type in this window (0 = off).
     *
     * @return bool True when the API accepted the message.
     */
    public function send(string $mobile, string $type, string $template, array $params, int $throttle_hours = 0): bool
    {
        if (!$this->is_configured() || '' === $template) {
            $this->logger->warning('WhatsApp send skipped: gateway or template not configured.');

            return false;
        }

        if ($throttle_hours > 0 && $this->log->count_recent($mobile, $type, $throttle_hours) > 0) {
            $this->logger->debug('WhatsApp message throttled.', ['mobile' => $mobile, 'type' => $type]);

            return false;
        }

        $components = [];

        if ([] !== $params) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    static fn($value): array => ['type' => 'text', 'text' => (string) $value],
                    array_values($params)
                ),
            ];
        }

        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            self::API_VERSION,
            rawurlencode($this->config->get_string('wa_phone_id'))
        );

        $response = wp_remote_post($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->get_string('wa_token'),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode([
                'messaging_product' => 'whatsapp',
                'to'                => vkl_mobile_to_international($mobile),
                'type'              => 'template',
                'template'          => [
                    'name'       => $template,
                    'language'   => ['code' => $this->config->get_string('wa_lang', 'fa')],
                    'components' => $components,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->log->record(
                $mobile,
                $type,
                $template,
                $params,
                VKL_Sms_Log_Repository::STATUS_FAILED,
                $response->get_error_message(),
                self::CHANNEL
            );

            $this->logger->error('WhatsApp send failed.', ['error' => $response->get_error_message()]);

            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        $ok     = $status >= 200 && $status < 300;

        $this->log->record(
            $mobile,
            $type,
            $template,
            $params,
            $ok ? VKL_Sms_Log_Repository::STATUS_SENT : VKL_Sms_Log_Repository::STATUS_FAILED,
            $body,
            self::CHANNEL
        );

        if (!$ok) {
            $this->logger->error('WhatsApp message rejected by gateway.', ['status' => $status]);
        }

        return $ok;
    }
}
