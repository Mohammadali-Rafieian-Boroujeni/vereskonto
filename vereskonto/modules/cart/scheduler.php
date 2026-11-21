<?php
/**
 * Cart scheduler.
 *
 * Detects abandoned carts via WP-Cron. Batched (max 50 carts per run),
 * atomic claim per cart, dispatches 'cart.abandoned'. It never sends
 * anything itself — listeners decide what happens.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Scheduler
 */
final class VKL_Cart_Scheduler
{
    /**
     * Cron hook name.
     */
    public const CRON_HOOK = 'vkl_cart_check_abandoned';

    /**
     * Custom cron interval id.
     */
    public const CRON_INTERVAL = 'vkl_five_minutes';

    /**
     * Max carts processed per cron run.
     */
    private const BATCH_SIZE = 50;

    /**
     * Repository.
     */
    private VKL_Cart_Repository $repository;

    /**
     * Event manager.
     */
    private VKL_EventManager $events;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * @param VKL_Cart_Repository $repository Repository.
     * @param VKL_EventManager    $events     Event manager.
     * @param VKL_Config          $config     Config.
     */
    public function __construct(
        VKL_Cart_Repository $repository,
        VKL_EventManager $events,
        VKL_Config $config
    ) {
        $this->repository = $repository;
        $this->events     = $events;
        $this->config     = $config;
    }

    /**
     * Register cron interval, schedule and handler.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'add_interval']);
        add_action(self::CRON_HOOK, [$this, 'run']);
        add_action('init', [$this, 'maybe_schedule']);

        // Second path on Action Scheduler. WP-Cron is disabled on this
        // site, so the hook above depends on the server cron job actually
        // running; when it does not, abandoned-cart reminders stop and the
        // table is never trimmed.
        add_action('vkl_cart_maintenance', [$this, 'run']);
        add_action('init', [$this, 'ensure_action_scheduled'], 20);
    }

    /**
     * Add a five-minute interval.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Schedules.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function add_interval(array $schedules): array
    {
        $schedules[self::CRON_INTERVAL] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every five minutes (Vereskonto)', 'vereskala-login'),
        ];

        return $schedules;
    }

    /**
     * Ensure the recurring event exists (or is removed when disabled).
     */
    /**
     * Keep a recurring Action Scheduler job queued as a fallback.
     */
    public function ensure_action_scheduled(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        // Same reasoning as the analytics module: a query on the Action
        // Scheduler tables does not belong on every page view.
        if (!is_admin() && !wp_doing_cron() && 1 !== wp_rand(1, 200)) {
            return;
        }

        if (as_has_scheduled_action('vkl_cart_maintenance')) {
            return;
        }

        as_schedule_recurring_action(
            time() + 5 * MINUTE_IN_SECONDS,
            15 * MINUTE_IN_SECONDS,
            'vkl_cart_maintenance',
            [],
            'vereskonto'
        );
    }

    public function maybe_schedule(): void
    {
        $enabled   = $this->config->get_bool('cart_tracking_enabled', true);
        $scheduled = wp_next_scheduled(self::CRON_HOOK);

        if ($enabled && !$scheduled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK);
        }

        if (!$enabled && $scheduled) {
            wp_unschedule_event($scheduled, self::CRON_HOOK);
        }
    }

    /**
     * Remove the recurring event (plugin deactivation).
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    /**
     * Cron handler: claim idle carts and dispatch 'cart.abandoned'.
     */
    public function run(): void
    {
        // Two schedulers can now reach this. A short lock keeps a double
        // tick from sending the same reminder twice.
        if (get_transient('vkl_cart_run_lock')) {
            return;
        }

        set_transient('vkl_cart_run_lock', 1, 4 * MINUTE_IN_SECONDS);

        // Housekeeping once a day (piggybacks on the 5-minute job): keep
        // the stats window honest by dropping empty and stale rows.
        if (false === get_transient('vkl_cart_cleanup_done')) {
            set_transient('vkl_cart_cleanup_done', '1', DAY_IN_SECONDS);
            $this->repository->cleanup($this->config->get_int('cart_retention_days', 45));
        }

        $idle_minutes = max(5, $this->config->get_int('cart_abandon_minutes', 60));

        $this->repository->mark_stale_abandoned($idle_minutes);

        $candidates = $this->repository->find_abandonment_candidates(
            $idle_minutes,
            self::BATCH_SIZE
        );

        foreach ($candidates as $cart) {

            if (!$this->repository->claim_abandoned((int) $cart['id'])) {
                continue; // Another process claimed it.
            }

            $this->repository->add_history(
                (string) $cart['cart_token'],
                (int) $cart['user_id'],
                (string) $cart['mobile'],
                'abandoned',
                0,
                0,
                (float) $cart['total']
            );

            $this->events->dispatch('cart.abandoned', [
                'cart_id'    => (int) $cart['id'],
                'cart_token' => (string) $cart['cart_token'],
                'user_id'    => (int) $cart['user_id'],
                'mobile'     => (string) $cart['mobile'],
                'items'      => json_decode((string) $cart['items'], true) ?: [],
                'item_count' => (int) $cart['item_count'],
                'total'      => (float) $cart['total'],
                'currency'   => (string) $cart['currency'],
            ]);
        }

        // Other cart-module jobs (login reward, reminders) share this tick.
        do_action('vkl_cart_tick');
    }
}
