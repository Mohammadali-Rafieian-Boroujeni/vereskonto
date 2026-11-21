<?php
/**
 * "Today" — the landing screen of Vereskonto: what is waiting on the shop
 * right now, today's figures and a short health check, each linking to
 * the screen where it is handled.
 *
 * Read-only. Every card is computed on its own and guarded, so a module
 * that is switched off (or a query that fails) hides one card instead of
 * breaking the screen.
 *
 * @package Vereskonto\Core
 * @since   2.12.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Today_Admin
 */
final class VKL_Today_Admin
{
    public const PAGE = 'vkl-today';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
    }

    public function menu(): void
    {
        add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Today', 'vereskala-login'),
            __('Today', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            self::PAGE,
            [$this, 'render']
        );
    }

    /**
     * Run one card's computation; null when its module is off or it fails.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|null
     */
    private static function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function service(string $id): ?object
    {
        if (!function_exists('vkl') || !vkl()->has($id)) {
            return null;
        }

        $service = vkl($id);

        return is_object($service) ? $service : null;
    }

    /**
     * Admin URL of the WooCommerce order list filtered by status (HPOS aware).
     */
    public static function orders_url(string $status = ''): string
    {
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos) {
            return admin_url('admin.php?page=wc-orders' . ('' !== $status ? '&status=' . $status : ''));
        }

        return admin_url('edit.php?post_type=shop_order' . ('' !== $status ? '&post_status=' . $status : ''));
    }

    /**
     * Cards for things waiting on the shop.
     *
     * @return array<int, array{label: string, count: int, url: string, done: string}>
     */
    public function waiting(): array
    {
        $counts = VKL_Admin_Menu::counts();
        $cards  = [];

        if (isset($counts['vkl-tickets'])) {
            $cards[] = ['label' => __('Tickets waiting for a reply', 'vereskala-login'), 'count' => $counts['vkl-tickets'], 'url' => admin_url('admin.php?page=vkl-tickets'), 'done' => __('All answered', 'vereskala-login')];
        }

        if (isset($counts['vkl-chatbot-inbox'])) {
            $cards[] = ['label' => __('Chats asking for a person', 'vereskala-login'), 'count' => $counts['vkl-chatbot-inbox'], 'url' => admin_url('admin.php?page=vkl-chatbot-inbox'), 'done' => __('Nobody waiting', 'vereskala-login')];
        }

        if (isset($counts['vkl-returns'])) {
            $cards[] = ['label' => __('Return requests to review', 'vereskala-login'), 'count' => $counts['vkl-returns'], 'url' => admin_url('admin.php?page=vkl-returns'), 'done' => __('None pending', 'vereskala-login')];
        }

        $requests = self::safe(static function (): ?int {
            $s = self::service('wallet.credit_requests');

            return $s ? count((array) $s->pending(100)) : null;
        });

        if (null !== $requests) {
            $cards[] = ['label' => __('Credit requests', 'vereskala-login'), 'count' => $requests, 'url' => admin_url('admin.php?page=vkl-credit-customers&view=requests'), 'done' => __('None pending', 'vereskala-login')];
        }

        $due = self::safe(static function (): ?int {
            $s = self::service('wallet.installments');

            return $s ? count((array) $s->due_now()) + count((array) $s->arrears()) : null;
        });

        if (null !== $due) {
            $cards[] = ['label' => __('Installments due or in arrears', 'vereskala-login'), 'count' => $due, 'url' => admin_url('admin.php?page=vkl-credit-customers&view=due'), 'done' => __('Everyone on schedule', 'vereskala-login')];
        }

        $to_ship = self::safe(static function (): ?int {
            if (!function_exists('wc_get_orders')) {
                return null;
            }

            $r = wc_get_orders(['status' => ['wc-processing'], 'limit' => 1, 'paginate' => true, 'return' => 'ids']);

            return (int) ($r->total ?? 0);
        });

        if (null !== $to_ship) {
            $cards[] = ['label' => __('Paid orders to dispatch', 'vereskala-login'), 'count' => $to_ship, 'url' => self::orders_url('wc-processing'), 'done' => __('All dispatched', 'vereskala-login')];
        }

        return $cards;
    }

    /**
     * Today's figures (site time zone).
     *
     * @return array{orders: ?int, revenue: ?float, sms_sent: ?int, sms_failed: ?int}
     */
    public function figures(): array
    {
        $start_local = function_exists('wp_date') ? (string) wp_date('Y-m-d 00:00:00') : gmdate('Y-m-d 00:00:00');
        $start_ts    = (int) strtotime($start_local . ' ' . (function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC'));

        $orders = self::safe(static function () use ($start_ts): ?array {
            if (!function_exists('wc_get_orders')) {
                return null;
            }

            $ids = (array) wc_get_orders([
                'status'       => ['wc-processing', 'wc-completed', 'wc-on-hold'],
                'date_created' => '>=' . $start_ts,
                'limit'        => 500,
                'return'       => 'ids',
            ]);
            $sum = 0.0;

            foreach ($ids as $id) {
                $order = wc_get_order($id);
                $sum  += $order ? (float) $order->get_total() : 0.0;
            }

            return [count($ids), $sum];
        });

        $sms = self::safe(static function () use ($start_ts): ?array {
            $s = self::service('sms.log');

            return $s ? (array) $s->since_summary(gmdate('Y-m-d H:i:s', $start_ts)) : null;
        });

        return [
            'orders'     => null !== $orders ? (int) $orders[0] : null,
            'revenue'    => null !== $orders ? (float) $orders[1] : null,
            'sms_sent'   => null !== $sms ? (int) ($sms['sent'] ?? 0) : null,
            'sms_failed' => null !== $sms ? (int) ($sms['failed'] ?? 0) : null,
        ];
    }

    /**
     * Short health checks.
     *
     * @return array<int, array{ok: bool, label: string, hint: string, url: string}>
     */
    public function health(): array
    {
        $gateway = '' !== trim((string) get_option('vkl_meli_username', '')) || '' !== trim((string) get_option('vkl_api_key', ''));
        $checks  = [[
            'ok'    => $gateway,
            'label' => __('SMS gateway', 'vereskala-login'),
            'hint'  => $gateway ? __('Connected', 'vereskala-login') : __('No gateway credentials — customers cannot receive sign-in codes.', 'vereskala-login'),
            'url'   => admin_url('admin.php?page=vereskala-login&tab=sms'),
        ]];

        $cron_off = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next     = function_exists('wp_next_scheduled') ? wp_next_scheduled('vkl_installment_check') : false;
        $late     = false !== $next && (int) $next < time() - 2 * HOUR_IN_SECONDS;
        $checks[] = [
            'ok'    => !$late,
            'label' => __('Scheduled jobs', 'vereskala-login'),
            'hint'  => $late
                ? __('Daily jobs are running late — reminders and late fees may be delayed.', 'vereskala-login')
                : ($cron_off ? __('WP-Cron is run by the server.', 'vereskala-login') : __('Running', 'vereskala-login')),
            'url'   => admin_url('admin.php?page=vkl-status'),
        ];

        $failed = self::safe(static function (): ?array {
            $s = self::service('sms.log');

            return $s ? (array) $s->since_summary(gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)) : null;
        });

        if (null !== $failed) {
            $n        = (int) ($failed['failed'] ?? 0);
            $checks[] = [
                'ok'    => 0 === $n,
                'label' => __('Messages in the last 24 hours', 'vereskala-login'),
                /* translators: %s: number of failed messages. */
                'hint'  => 0 === $n ? __('No failures', 'vereskala-login') : sprintf(__('%s failed — see the SMS log for the reason.', 'vereskala-login'), number_format_i18n($n)),
                'url'   => admin_url('admin.php?page=vkl-sms-logs'),
            ];
        }

        return $checks;
    }

    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }

        $waiting = $this->waiting();
        $figures = $this->figures();
        $health  = $this->health();
        $open    = array_sum(array_column($waiting, 'count'));
        $price   = static fn(float $v): string => function_exists('wc_price') ? wp_kses_post(wc_price($v)) : esc_html(number_format_i18n($v));
        ?>
        <div class="wrap vkl-today">
            <h1><?php esc_html_e('Today', 'vereskala-login'); ?></h1>
            <p class="description">
                <?php
                echo 0 === $open
                    ? esc_html__('Nothing is waiting on you right now.', 'vereskala-login')
                    /* translators: %s: number of items. */
                    : esc_html(sprintf(__('Items waiting on you: %s', 'vereskala-login'), number_format_i18n($open)));
                ?>
            </p>

            <style>
                .vkl-today .vkl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin:10px 0 26px;max-width:1100px}
                .vkl-today .vkl-card{display:flex;flex-direction:column;gap:4px;background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:14px 16px;text-decoration:none;color:#1d2327}
                .vkl-today a.vkl-card:hover,.vkl-today a.vkl-card:focus-visible{border-color:#157f3a;box-shadow:0 2px 10px rgba(16,42,34,.08);outline:none}
                .vkl-today .vkl-card .n{font-size:28px;font-weight:800;line-height:1.2;font-variant-numeric:tabular-nums}
                .vkl-today .vkl-card.is-waiting{border-inline-start:4px solid #d63638}
                .vkl-today .vkl-card.is-waiting .n{color:#b32d2e}
                .vkl-today .vkl-card.is-clear .n{color:#157f3a;font-size:15px;font-weight:700}
                .vkl-today .vkl-card .l{font-size:13px;color:#50575e}
                .vkl-today .vkl-health{list-style:none;margin:10px 0;padding:0;max-width:1100px;display:grid;gap:8px}
                .vkl-today .vkl-health a{display:flex;gap:10px;align-items:baseline;background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px;text-decoration:none;color:#1d2327}
                .vkl-today .vkl-health .dot{flex:0 0 auto;width:10px;height:10px;border-radius:50%;background:#157f3a}
                .vkl-today .vkl-health .bad .dot{background:#d63638}
                .vkl-today .vkl-health .hint{color:#50575e;font-size:13px}
            </style>

            <h2 class="title"><?php esc_html_e('Waiting on you', 'vereskala-login'); ?></h2>
            <div class="vkl-grid">
                <?php foreach ($waiting as $card) : ?>
                    <a class="vkl-card <?php echo $card['count'] > 0 ? 'is-waiting' : 'is-clear'; ?>" href="<?php echo esc_url($card['url']); ?>">
                        <span class="n"><?php echo $card['count'] > 0 ? esc_html(number_format_i18n($card['count'])) : '✓ ' . esc_html($card['done']); ?></span>
                        <span class="l"><?php echo esc_html($card['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <h2 class="title"><?php esc_html_e('Today so far', 'vereskala-login'); ?></h2>
            <div class="vkl-grid">
                <?php if (null !== $figures['orders']) : ?>
                    <a class="vkl-card" href="<?php echo esc_url(self::orders_url()); ?>">
                        <span class="n"><?php echo esc_html(number_format_i18n($figures['orders'])); ?></span>
                        <span class="l"><?php esc_html_e('Paid orders today', 'vereskala-login'); ?></span>
                    </a>
                    <a class="vkl-card" href="<?php echo esc_url(admin_url('admin.php?page=vkl-history&vkl_range=day')); ?>">
                        <span class="n"><?php echo $price((float) $figures['revenue']); // phpcs:ignore ?></span>
                        <span class="l"><?php esc_html_e('Sales today', 'vereskala-login'); ?></span>
                    </a>
                <?php endif; ?>
                <?php if (null !== $figures['sms_sent']) : ?>
                    <a class="vkl-card" href="<?php echo esc_url(admin_url('admin.php?page=vkl-sms-logs')); ?>">
                        <span class="n"><?php echo esc_html(number_format_i18n($figures['sms_sent'])); ?></span>
                        <span class="l">
                            <?php esc_html_e('Messages sent today', 'vereskala-login'); ?>
                            <?php if ($figures['sms_failed'] > 0) : ?>
                                · <?php /* translators: %s: number of failed messages. */ echo esc_html(sprintf(__('%s failed', 'vereskala-login'), number_format_i18n((int) $figures['sms_failed']))); ?>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endif; ?>
            </div>

            <h2 class="title"><?php esc_html_e('Health', 'vereskala-login'); ?></h2>
            <ul class="vkl-health">
                <?php foreach ($health as $check) : ?>
                    <li class="<?php echo $check['ok'] ? 'ok' : 'bad'; ?>">
                        <a href="<?php echo esc_url($check['url']); ?>">
                            <span class="dot" aria-hidden="true"></span>
                            <strong><?php echo esc_html($check['label']); ?></strong>
                            <span class="hint"><?php echo esc_html($check['hint']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}
