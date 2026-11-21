<?php
/**
 * SMS channel adapter — wraps the existing pattern SMS sender so it plugs
 * into the multi-channel dispatcher.
 *
 * SMS cannot carry arbitrary text (the gateway needs an approved pattern),
 * so this channel only sends when a pattern is provided in the context
 * (context['pattern']) or resolvable for the type. Free-text calls without
 * a pattern are declined, letting the dispatcher fall through.
 *
 * @package Vereskonto\Modules\Notify\Channels
 * @since   1.49.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Sms
 */
final class VKL_Notify_Sms implements VKL_Notify_Channel
{
    private VKL_Sms_Sender $sender;

    private VKL_Config $config;

    public function __construct(VKL_Sms_Sender $sender, VKL_Config $config)
    {
        $this->sender = $sender;
        $this->config = $config;
    }

    public function id(): string
    {
        return 'sms';
    }

    public function label(): string
    {
        return __('SMS', 'vereskala-login');
    }

    public function is_enabled(): bool
    {
        // The SMS driver is configured whenever an API key is present.
        return '' !== $this->config->get_string('api_key');
    }

    public function can_reach(int $user_id): bool
    {
        return '' !== $this->mobile($user_id);
    }

    public function supports_free_text(): bool
    {
        return false;
    }

    public function supports_documents(): bool
    {
        return false;
    }

    public function send(int $user_id, string $type, string $message, array $context = []): bool
    {
        $mobile = $this->mobile($user_id);

        if ('' === $mobile) {
            return false;
        }

        // A pattern must be supplied for the type (SMS cannot send raw text).
        $pattern = (string) ($context['pattern'] ?? '');

        if ('' === $pattern) {
            return false;
        }

        $params   = is_array($context['params'] ?? null) ? $context['params'] : [];
        $throttle = (int) ($context['throttle_hours'] ?? 0);

        return $this->sender->send($mobile, $type, $pattern, $params, $throttle);
    }

    public function send_document(int $user_id, string $file, string $caption, string $type = 'document'): bool
    {
        return false; // SMS cannot carry files.
    }

    private function mobile(int $user_id): string
    {
        return (string) get_user_meta($user_id, 'billing_phone', true);
    }
}
