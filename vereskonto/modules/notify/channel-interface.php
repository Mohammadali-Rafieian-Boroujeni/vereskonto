<?php
/**
 * Notification channel contract. Every delivery channel (Bale, WhatsApp,
 * SMS, …) implements this, so the dispatcher can treat them uniformly and
 * new channels are added without touching the dispatcher or callers.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface VKL_Notify_Channel
 */
interface VKL_Notify_Channel
{
    /**
     * Stable machine id: 'bale', 'whatsapp', 'sms'.
     */
    public function id(): string;

    /**
     * Human label for the admin and the customer's preference screen.
     */
    public function label(): string;

    /**
     * Whether the channel is switched on in the admin (credentials set).
     */
    public function is_enabled(): bool;

    /**
     * Whether THIS user can currently be reached on THIS channel
     * (e.g. Bale connected, or a valid mobile on file).
     */
    public function can_reach(int $user_id): bool;

    /**
     * Whether the channel can carry arbitrary free text (Bale can; SMS and
     * WhatsApp need a pre-approved pattern/template instead).
     */
    public function supports_free_text(): bool;

    /**
     * Whether the channel can carry a file/document (invoices).
     */
    public function supports_documents(): bool;

    /**
     * Send a plain-text notification. Free-text channels use $message;
     * pattern channels fall back to their configured pattern for $type and
     * ignore $message. Returns true when accepted for delivery.
     *
     * @param int                  $user_id User id.
     * @param string               $type    Machine type, e.g. 'order.completed'.
     * @param string               $message Ready-to-send text (already localised).
     * @param array<string, mixed> $context Pattern params / extra data.
     */
    public function send(int $user_id, string $type, string $message, array $context = []): bool;

    /**
     * Send a document (e.g. a PDF invoice) with a short caption. Channels
     * that cannot carry files return false so the dispatcher can fall
     * back or skip.
     *
     * @param int    $user_id  User id.
     * @param string $file     Absolute path to the file.
     * @param string $caption  Caption text.
     * @param string $type     Machine type, e.g. 'invoice'.
     */
    public function send_document(int $user_id, string $file, string $caption, string $type = 'document'): bool;
}
