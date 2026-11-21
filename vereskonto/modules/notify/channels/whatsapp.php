<?php
/**
 * WhatsApp channel adapter — wraps the existing Meta Cloud template
 * sender for the multi-channel dispatcher.
 *
 * Like SMS, WhatsApp needs an approved template, so free-text calls
 * without a template in the context are declined and the dispatcher falls
 * through to the next channel.
 *
 * @package Vereskonto\Modules\Notify\Channels
 * @since   1.49.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Whatsapp
 */
final class VKL_Notify_Whatsapp implements VKL_Notify_Channel
{
    private VKL_Wa_Sender $sender;

    private VKL_Config $config;

    public function __construct(VKL_Wa_Sender $sender, VKL_Config $config)
    {
        $this->sender = $sender;
        $this->config = $config;
    }

    public function id(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return __('WhatsApp', 'vereskala-login');
    }

    public function is_enabled(): bool
    {
        return $this->sender->is_configured();
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
        // Meta Cloud can send documents, but only inside an open 24h
        // session or an approved media template — out of scope here.
        return false;
    }

    public function send(int $user_id, string $type, string $message, array $context = []): bool
    {
        $mobile = $this->mobile($user_id);

        if ('' === $mobile) {
            return false;
        }

        // WhatsApp needs an approved template name for the type.
        $template = (string) ($context['wa_template'] ?? '');

        if ('' === $template) {
            return false;
        }

        $params   = is_array($context['params'] ?? null) ? $context['params'] : [];
        $throttle = (int) ($context['throttle_hours'] ?? 0);

        return $this->sender->send($mobile, $type, $template, $params, $throttle);
    }

    public function send_document(int $user_id, string $file, string $caption, string $type = 'document'): bool
    {
        return false;
    }

    private function mobile(int $user_id): string
    {
        return (string) get_user_meta($user_id, 'billing_phone', true);
    }
}
