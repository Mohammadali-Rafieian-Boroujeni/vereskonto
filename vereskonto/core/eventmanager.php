<?php
/**
 * Event manager.
 *
 * The communication backbone of the platform. Modules never call each
 * other directly — they dispatch named events and register listeners.
 *
 * Every dispatch is additionally mirrored to the WordPress hook system
 * ("vkl_event" and "vkl_event_{name}") so third-party code can listen
 * without touching the container.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_EventManager
 */
final class VKL_EventManager
{
    /**
     * Listeners, keyed by event name, grouped by priority.
     *
     * @var array<string, array<int, array<int, callable>>>
     */
    private array $listeners = [];

    /**
     * Logger for listener failures.
     */
    private VKL_Logger $logger;

    /**
     * @param VKL_Logger $logger Core logger.
     */
    public function __construct(VKL_Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register a listener for an event.
     *
     * @param string   $event    Event name, e.g. 'cart.abandoned'.
     * @param callable $listener Callable receiving (array $payload, string $event).
     * @param int      $priority Lower runs earlier. Default 10.
     */
    public function listen(string $event, callable $listener, int $priority = 10): void
    {
        $this->listeners[$event][$priority][] = $listener;
    }

    /**
     * Dispatch an event to all listeners.
     *
     * A listener throwing an exception is logged and skipped; one broken
     * listener must never take down the request or other listeners.
     *
     * @param string               $event   Event name, e.g. 'cart.updated'.
     * @param array<string, mixed> $payload Event payload.
     */
    public function dispatch(string $event, array $payload = []): void
    {
        if (isset($this->listeners[$event])) {
            ksort($this->listeners[$event]);

            foreach ($this->listeners[$event] as $group) {
                foreach ($group as $listener) {
                    try {
                        $listener($payload, $event);
                    } catch (Throwable $e) {
                        $this->logger->error(
                            'Event listener failed.',
                            [
                                'event' => $event,
                                'error' => $e->getMessage(),
                            ]
                        );
                    }
                }
            }
        }

        /**
         * Fires for every platform event.
         *
         * @param array  $payload Event payload.
         * @param string $event   Event name.
         */
        do_action('vkl_event', $payload, $event);

        /**
         * Fires for a specific platform event, e.g. vkl_event_cart_abandoned.
         *
         * @param array $payload Event payload.
         */
        do_action('vkl_event_' . str_replace('.', '_', $event), $payload);
    }
}
