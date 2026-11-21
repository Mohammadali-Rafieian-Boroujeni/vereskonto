<?php
/**
 * Multi-channel notification dispatcher. Walks a user's preferred
 * channels in priority order and delivers through the first that can
 * reach them — so each notification is sent once, via the richest and
 * cheapest available channel, unless "broadcast" is requested.
 *
 * Channels register themselves; the dispatcher never names a concrete
 * channel, so new ones are added with zero edits here.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Dispatcher
 */
final class VKL_Notify_Dispatcher
{
    /**
     * Registered channels keyed by id.
     *
     * @var array<string, VKL_Notify_Channel>
     */
    private array $channels = [];

    private VKL_Notify_Preferences $prefs;

    private VKL_Logger $logger;

    public function __construct(VKL_Notify_Preferences $prefs, VKL_Logger $logger)
    {
        $this->prefs  = $prefs;
        $this->logger = $logger;
    }

    /**
     * Register a channel (called by each channel's boot).
     */
    public function add_channel(VKL_Notify_Channel $channel): void
    {
        $this->channels[$channel->id()] = $channel;
    }

    /**
     * All registered channels (for the admin/preference screens).
     *
     * @return array<string, VKL_Notify_Channel>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * The ordered, reachable, enabled channels for a user right now.
     *
     * @return VKL_Notify_Channel[]
     */
    private function usable_for(int $user_id): array
    {
        $usable = [];

        foreach ($this->prefs->order_for($user_id) as $id) {
            if (!isset($this->channels[$id])) {
                continue;
            }

            $channel = $this->channels[$id];

            if ($channel->is_enabled() && $channel->can_reach($user_id)) {
                $usable[] = $channel;
            }
        }

        return $usable;
    }

    /**
     * Send a text notification to a user through the first usable channel.
     * Returns the channel id that accepted it, or '' if none could.
     *
     * @param array<string, mixed> $context Extra data.
     */
    public function notify(int $user_id, string $type, string $message, array $context = []): string
    {
        if ($user_id <= 0) {
            return '';
        }

        foreach ($this->usable_for($user_id) as $channel) {
            // A pattern channel with nothing to send on (no pattern/template
            // in context) is skipped, not treated as a failed delivery.
            if (!$channel->supports_free_text()
                && '' === (string) ($context['pattern'] ?? '')
                && '' === (string) ($context['wa_template'] ?? '')) {
                continue;
            }

            if ($channel->send($user_id, $type, $message, $context)) {
                return $channel->id();
            }
        }

        return '';
    }

    /**
     * Send a document to the first usable channel that supports files.
     *
     * @return string Channel id that accepted it, or ''.
     */
    public function notify_document(int $user_id, string $file, string $caption, string $type = 'document'): string
    {
        if ($user_id <= 0 || !is_readable($file)) {
            return '';
        }

        foreach ($this->usable_for($user_id) as $channel) {
            if (!$channel->supports_documents()) {
                continue;
            }

            if ($channel->send_document($user_id, $file, $caption, $type)) {
                return $channel->id();
            }
        }

        return '';
    }

    /**
     * Is at least one channel able to reach this user?
     */
    public function can_reach_any(int $user_id): bool
    {
        return [] !== $this->usable_for($user_id);
    }
}
