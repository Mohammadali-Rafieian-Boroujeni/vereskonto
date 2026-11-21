<?php
/**
 * One-time import of existing customers into the CRM.
 *
 * Two stages: every non-staff user account, then guest orders (no
 * account) by their billing mobile. Work happens in small batches so a
 * shop with many customers is never locked up:
 *
 *   - a WP-Cron event runs a batch and re-schedules itself until done;
 *   - if WP-Cron is disabled or not firing, a smaller batch also runs on
 *     admin page loads (at most one per minute, under a lock).
 *
 * Progress lives in one option, so the import resumes where it stopped
 * after any interruption. Every step is idempotent (contacts are keyed
 * by user and by mobile), so re-running it never creates duplicates.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Backfill
 */
final class VKL_Crm_Backfill
{
    public const HOOK         = 'vkl_crm_backfill';
    public const STATE_OPTION = 'vkl_crm_backfill';

    private const LOCK         = 'vkl_crm_backfill_lock';
    private const CRON_BATCH   = 100;
    private const ADMIN_BATCH  = 25;
    private const ADMIN_EVERY  = 60;
    private const GUEST_PAGE   = 25;

    private VKL_Crm_Contact_Service $service;

    public function __construct(VKL_Crm_Contact_Service $service)
    {
        $this->service = $service;
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run_cron']);
        add_action('admin_init', [$this, 'run_on_admin'], 20);
    }

    /**
     * @return array{stage: string, cursor: int, users: int, guests: int, started_at: string, finished_at: string, last_run: int}
     */
    public function state(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return (is_array($state) ? $state : []) + [
            'stage'       => '',
            'cursor'      => 0,
            'users'       => 0,
            'guests'      => 0,
            'started_at'  => '',
            'finished_at' => '',
            'last_run'    => 0,
        ];
    }

    public function is_done(): bool
    {
        return 'done' === $this->state()['stage'];
    }

    /**
     * Begin the import once (no-op when it already started or finished).
     */
    public function start(): void
    {
        if ('' !== $this->state()['stage']) {
            return;
        }

        $this->save(['stage' => 'users', 'cursor' => 0, 'started_at' => gmdate('Y-m-d H:i:s')]);
        $this->schedule(5);
    }

    public function run_cron(): void
    {
        $this->run(self::CRON_BATCH, 20.0);

        if (!$this->is_done()) {
            $this->schedule(30);
        }
    }

    /**
     * Fallback for sites where WP-Cron does not fire.
     */
    public function run_on_admin(): void
    {
        $state = $this->state();

        if (!in_array($state['stage'], ['users', 'guests'], true) || wp_doing_ajax()) {
            return;
        }

        if (time() - (int) $state['last_run'] < self::ADMIN_EVERY || !current_user_can('manage_options')) {
            return;
        }

        $this->run(self::ADMIN_BATCH, 3.0);

        if (!$this->is_done() && !wp_next_scheduled(self::HOOK)) {
            $this->schedule(30);
        }
    }

    /**
     * Process up to $limit items or until $seconds have passed.
     *
     * @return int Items processed.
     */
    public function run(int $limit, float $seconds): int
    {
        if (get_transient(self::LOCK)) {
            return 0;
        }

        set_transient(self::LOCK, 1, 120);

        $done  = 0;
        $start = microtime(true);

        try {
            while ($done < $limit && (microtime(true) - $start) < $seconds) {
                $state = $this->state();

                if ('users' === $state['stage']) {
                    $n = $this->users_batch(min(25, $limit - $done));
                } elseif ('guests' === $state['stage']) {
                    $n = $this->guests_batch(min(25, $limit - $done));
                } else {
                    break;
                }

                if (0 === $n) {
                    continue; // Stage advanced; loop picks up the next one.
                }

                $done += $n;
            }
        } catch (Throwable $e) {
            if (function_exists('vkl')) {
                vkl('logger')->error('CRM backfill batch failed.', ['error' => $e->getMessage()]);
            }
        } finally {
            $this->save(['last_run' => time()]);
            delete_transient(self::LOCK);
        }

        return $done;
    }

    /**
     * Next users by ascending ID after the cursor.
     */
    private function users_batch(int $size): int
    {
        global $wpdb;

        $state = $this->state();
        $ids   = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
            (int) $state['cursor'],
            max(1, $size)
        )));

        if ([] === $ids) {
            $this->save(['stage' => 'guests', 'cursor' => 0]);

            return 0;
        }

        $made = 0;

        foreach ($ids as $user_id) {
            if (VKL_Crm_Contact_Service::is_staff($user_id)) {
                continue;
            }

            $contact_id = $this->service->for_user($user_id);

            if ($contact_id > 0) {
                $this->service->refresh_stats($contact_id);
                ++$made;
            }
        }

        $this->save(['cursor' => end($ids), 'users' => (int) $state['users'] + $made]);

        return count($ids);
    }

    /**
     * Paid guest orders, oldest first, a fixed-size page at a time;
     * cursor = pages done. Plain paging through wc_get_orders behaves the
     * same on HPOS and legacy order storage; new orders only ever append
     * at the end of an ID-ascending list, so pages already done stay done.
     * The page size is a constant on purpose — changing it between runs
     * would shift page boundaries.
     */
    private function guests_batch(int $size): int
    {
        if (!function_exists('wc_get_orders')) {
            $this->finish();

            return 0;
        }

        $state = $this->state();
        $ids   = array_map('intval', (array) wc_get_orders([
            'customer_id' => 0,
            'status'      => VKL_Crm_Contact_Service::paid_statuses(),
            'type'        => 'shop_order',
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'limit'       => self::GUEST_PAGE,
            'paged'       => (int) $state['cursor'] + 1,
            'return'      => 'ids',
        ]));

        if ([] === $ids) {
            $this->finish();

            return 0;
        }

        $made = 0;

        foreach ($ids as $order_id) {
            $order = wc_get_order($order_id);

            if (!$order instanceof WC_Order || vkl_is_carrier_order($order_id)) {
                continue;
            }

            $contact_id = $this->service->for_order($order);

            if ($contact_id > 0) {
                $this->service->refresh_stats($contact_id);
                ++$made;
            }
        }

        $this->save(['cursor' => (int) $state['cursor'] + 1, 'guests' => (int) $state['guests'] + $made]);

        if (count($ids) < self::GUEST_PAGE) {
            $this->finish();
        }

        return count($ids);
    }

    private function finish(): void
    {
        $this->save(['stage' => 'done', 'finished_at' => gmdate('Y-m-d H:i:s')]);
        wp_clear_scheduled_hook(self::HOOK);

        if (function_exists('vkl')) {
            $s = $this->state();
            vkl('logger')->info('CRM backfill finished.', ['users' => $s['users'], 'guests' => $s['guests']]);
        }
    }

    private function schedule(int $in_seconds): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $in_seconds, self::HOOK);
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function save(array $changes): void
    {
        update_option(self::STATE_OPTION, array_merge($this->state(), $changes), false);
    }
}
