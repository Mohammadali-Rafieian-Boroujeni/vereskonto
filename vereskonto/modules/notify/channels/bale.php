<?php
/**
 * Bale channel — delivers via a Bale bot (ble.ir Bot API, Telegram-style).
 *
 * Customers connect by tapping "Start" on a deep link carrying a one-time
 * token; the webhook maps their chat to their account. Free, and can send
 * PDF invoices as documents.
 *
 * @package Vereskonto\Modules\Notify\Channels
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Bale
 */
final class VKL_Notify_Bale implements VKL_Notify_Channel
{
    private const API_BASE   = 'https://tapi.bale.ai/bot';
    private const META_CHAT  = '_vkl_bale_chat_id';
    private const META_TOKEN = '_vkl_bale_link_token';

    private VKL_Config $config;

    private VKL_Logger $logger;

    public function __construct(VKL_Config $config, VKL_Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function id(): string
    {
        return 'bale';
    }

    public function label(): string
    {
        return __('Bale messenger', 'vereskala-login');
    }

    public function is_enabled(): bool
    {
        return $this->config->get_bool('bale_enabled', false)
            && '' !== $this->config->get_string('bale_bot_token');
    }

    public function can_reach(int $user_id): bool
    {
        return '' !== (string) get_user_meta($user_id, self::META_CHAT, true);
    }

    public function supports_free_text(): bool
    {
        return true;
    }

    public function supports_documents(): bool
    {
        return true;
    }

    public function send(int $user_id, string $type, string $message, array $context = []): bool
    {
        $chat = (string) get_user_meta($user_id, self::META_CHAT, true);

        if ('' === $chat) {
            return false;
        }

        return $this->api('sendMessage', [
            'chat_id' => $chat,
            'text'    => $message,
        ]);
    }

    public function send_document(int $user_id, string $file, string $caption, string $type = 'document'): bool
    {
        $chat = (string) get_user_meta($user_id, self::META_CHAT, true);

        if ('' === $chat || !is_readable($file)) {
            return false;
        }

        $token = $this->config->get_string('bale_bot_token');

        if ('' === $token) {
            return false;
        }

        // Multipart upload for the document.
        $boundary = wp_generate_password(24, false);
        $body     = $this->multipart($boundary, [
            'chat_id' => $chat,
            'caption' => $caption,
        ], 'document', $file);

        $response = wp_remote_post(self::API_BASE . $token . '/sendDocument', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Bale document failed.', ['error' => $response->get_error_message()]);

            return false;
        }

        return 200 === (int) wp_remote_retrieve_response_code($response);
    }

    /**
     * A one-time token that ties a Start tap to this user account.
     */
    public function link_token(int $user_id): string
    {
        $token = (string) get_user_meta($user_id, self::META_TOKEN, true);

        if ('' === $token) {
            $token = 'vk' . wp_generate_password(16, false);
            update_user_meta($user_id, self::META_TOKEN, $token);
        }

        return $token;
    }

    /**
     * Deep link the customer taps to connect ("Start" pre-filled).
     */
    public function connect_link(int $user_id): string
    {
        $user = $this->config->get_string('bale_bot_username');

        if ('' === $user) {
            return '';
        }

        return 'https://ble.ir/' . rawurlencode($user) . '?start=' . rawurlencode($this->link_token($user_id));
    }

    public function is_connected(int $user_id): bool
    {
        return $this->can_reach($user_id);
    }

    public function disconnect(int $user_id): void
    {
        delete_user_meta($user_id, self::META_CHAT);
    }

    /**
     * Resolve a start-token to its user id (used by the webhook).
     */
    public function user_by_token(string $token): int
    {
        if ('' === $token) {
            return 0;
        }

        $users = get_users([
            'meta_key'   => self::META_TOKEN,
            'meta_value' => $token,
            'fields'     => 'ID',
            'number'     => 1,
        ]);

        return [] !== $users ? (int) $users[0] : 0;
    }

    /**
     * Bind a chat id to a user (called by the webhook on /start).
     */
    public function bind(int $user_id, string $chat_id): void
    {
        update_user_meta($user_id, self::META_CHAT, $chat_id);
    }

    /**
     * Minimal Bot API call (JSON) returning success.
     *
     * @param array<string, mixed> $params Request params.
     */
    private function api(string $method, array $params): bool
    {
        $token = $this->config->get_string('bale_bot_token');

        if ('' === $token) {
            return false;
        }

        $response = wp_remote_post(self::API_BASE . $token . '/' . $method, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => (string) wp_json_encode($params),
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Bale API failed.', ['method' => $method, 'error' => $response->get_error_message()]);

            return false;
        }

        return 200 === (int) wp_remote_retrieve_response_code($response);
    }

    /**
     * Build a multipart body with one file part.
     *
     * @param array<string, string> $fields Text fields.
     */
    private function multipart(string $boundary, array $fields, string $file_field, string $file): string
    {
        $eol = "\r\n";
        $b   = '';

        foreach ($fields as $name => $value) {
            $b .= '--' . $boundary . $eol;
            $b .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $b .= $value . $eol;
        }

        $b .= '--' . $boundary . $eol;
        $b .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . basename($file) . '"' . $eol;
        $b .= 'Content-Type: application/octet-stream' . $eol . $eol;
        $b .= (string) file_get_contents($file) . $eol;
        $b .= '--' . $boundary . '--' . $eol;

        return $b;
    }
}
