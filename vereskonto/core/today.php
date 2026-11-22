<?php
/**
 * "Today" — the landing screen of Vereskonto: what is waiting on the shop
 * right now, today's figures against yesterday, the last seven days, the
 * latest orders and a short health check, each linking to the screen
 * where it is handled.
 *
 * Read-only. Every block is computed on its own and guarded, so a module
 * that is switched off (or a query that fails) hides one block instead
 * of breaking the screen.
 *
 * Money figures count sales only: wallet top-ups and installment
 * repayments travel through WooCommerce orders too, but nothing was
 * sold (see vkl_is_carrier_order()). Before 2.21.0 they were added to
 * "Sales today", so a busy repayment day looked like a busy sales day.
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

    /** Order statuses that count as a sale (money received or promised). */
    private const SALE_STATUSES = ['wc-processing', 'wc-completed', 'wc-on-hold'];

    /** Days in the trend chart, today included. */
    private const TREND_DAYS = 7;

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
     * Run one block's computation; null when its module is off or it fails.
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

    private static function timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        try {
            return new DateTimeZone(function_exists('wp_timezone_string') ? (string) wp_timezone_string() : 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
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
     * Edit screen of one order (HPOS aware).
     */
    private static function order_edit_url(int $order_id): string
    {
        $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return $hpos
            ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id)
            : admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private static function is_carrier(int $order_id): bool
    {
        return function_exists('vkl_is_carrier_order') && vkl_is_carrier_order($order_id);
    }

    /**
     * Cards for things waiting on the shop; the ones with work first.
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

        // The viewer's own CRM follow-ups (due today or overdue).
        if (isset($counts['vkl-crm-tasks']) && current_user_can('vkl_crm_access')) {
            $cards[] = ['label' => __('My CRM tasks due today or overdue', 'vereskala-login'), 'count' => $counts['vkl-crm-tasks'], 'url' => admin_url('admin.php?page=vkl-crm-tasks'), 'done' => __('Nothing due', 'vereskala-login')];
        }

        // Work first, "all clear" cards after; stable within each half.
        $busy  = array_values(array_filter($cards, static fn(array $c): bool => $c['count'] > 0));
        $clear = array_values(array_filter($cards, static fn(array $c): bool => $c['count'] <= 0));

        return array_merge($busy, $clear);
    }

    /**
     * Sales of the last TREND_DAYS local days, plus today against
     * yesterday up to the same time of day. One order query for all of it.
     *
     * @return array{
     *     orders: ?int, revenue: ?float, aov: ?float,
     *     y_orders: ?int, y_revenue: ?float,
     *     days: array<int, array{ts: int, orders: int, revenue: float}>,
     *     sms_sent: ?int, sms_failed: ?int, new_customers: ?int
     * }
     */
    public function figures(): array
    {
        $tz    = self::timezone();
        $now   = new DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0);
        $from  = $today->modify('-' . (self::TREND_DAYS - 1) . ' days');
        $since = $now->getTimestamp() - $today->getTimestamp(); // seconds into today

        $sales = self::safe(static function () use ($today, $from, $since): ?array {
            if (!function_exists('wc_get_orders')) {
                return null;
            }

            $days = [];

            for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
                $days[$d->format('Y-m-d')] = ['ts' => $d->getTimestamp(), 'orders' => 0, 'revenue' => 0.0];
            }

            $y_start   = $today->modify('-1 day')->getTimestamp();
            $y_orders  = 0;
            $y_revenue = 0.0;

            $orders = (array) wc_get_orders([
                'status'       => self::SALE_STATUSES,
                'date_created' => '>=' . $from->getTimestamp(),
                'limit'        => 2000,
                'type'         => 'shop_order',
            ]);

            foreach ($orders as $order) {
                if (!is_object($order) || !method_exists($order, 'get_date_created') || self::is_carrier((int) $order->get_id())) {
                    continue;
                }

                $created = $order->get_date_created();

                if (!$created) {
                    continue;
                }

                $ts  = (int) $created->getTimestamp();
                $key = (new DateTimeImmutable('@' . $ts))->setTimezone($today->getTimezone())->format('Y-m-d');

                if (!isset($days[$key])) {
                    continue;
                }

                $total = (float) $order->get_total();
                $days[$key]['orders']++;
                $days[$key]['revenue'] += $total;

                if ($ts >= $y_start && $ts < $y_start + $since) {
                    $y_orders++;
                    $y_revenue += $total;
                }
            }

            return ['days' => array_values($days), 'y_orders' => $y_orders, 'y_revenue' => $y_revenue];
        });

        $sms = self::safe(static function () use ($today): ?array {
            $s = self::service('sms.log');

            return $s ? (array) $s->since_summary(gmdate('Y-m-d H:i:s', $today->getTimestamp())) : null;
        });

        $new_customers = self::safe(static function () use ($today): ?int {
            if (!class_exists('WP_User_Query')) {
                return null;
            }

            $q = new WP_User_Query([
                'number'       => 1,
                'fields'       => 'ID',
                'count_total'  => true,
                'role__not_in' => ['administrator'],
                'date_query'   => [['after' => gmdate('Y-m-d H:i:s', $today->getTimestamp()), 'inclusive' => true, 'column' => 'user_registered']],
            ]);

            return (int) $q->get_total();
        });

        $todays = null !== $sales ? end($sales['days']) : false;
        $orders = false !== $todays ? (int) $todays['orders'] : null;
        $rev    = false !== $todays ? (float) $todays['revenue'] : null;

        return [
            'orders'        => $orders,
            'revenue'       => $rev,
            'aov'           => null !== $orders && $orders > 0 ? $rev / $orders : null,
            'y_orders'      => null !== $sales ? (int) $sales['y_orders'] : null,
            'y_revenue'     => null !== $sales ? (float) $sales['y_revenue'] : null,
            'days'          => null !== $sales ? $sales['days'] : [],
            'sms_sent'      => null !== $sms ? (int) ($sms['sent'] ?? 0) : null,
            'sms_failed'    => null !== $sms ? (int) ($sms['failed'] ?? 0) : null,
            'new_customers' => $new_customers,
        ];
    }

    /**
     * The latest orders of any status, newest first.
     *
     * @return array<int, object>
     */
    public function recent_orders(int $limit = 8): array
    {
        $orders = self::safe(static function () use ($limit): array {
            if (!function_exists('wc_get_orders')) {
                return [];
            }

            return array_values(array_filter((array) wc_get_orders([
                'limit'   => $limit,
                'orderby' => 'date',
                'order'   => 'DESC',
                'type'    => 'shop_order',
            ]), 'is_object'));
        });

        return is_array($orders) ? $orders : [];
    }

    /**
     * Whether the SMS gateway has what it needs to send.
     */
    private static function sms_ready(): bool
    {
        $driver = self::service('sms.driver');

        if ($driver instanceof VKL_Sms_Driver) {
            return $driver->is_configured();
        }

        return '' !== trim((string) get_option('vkl_meli_username', ''))
            && '' !== trim((string) get_option('vkl_meli_password', ''));
    }

    /**
     * Short health checks.
     *
     * @return array<int, array{ok: bool, label: string, hint: string, url: string}>
     */
    public function health(): array
    {
        $gateway = self::sms_ready();
        $pattern = '' !== trim((string) get_option('vkl_pattern_code', ''));
        $checks  = [[
            'ok'    => $gateway && $pattern,
            'label' => __('SMS gateway', 'vereskala-login'),
            'hint'  => !$gateway
                ? __('No gateway credentials — customers cannot receive sign-in codes.', 'vereskala-login')
                : ($pattern ? __('Connected', 'vereskala-login') : __('Connected, but the sign-in code pattern is empty — customers cannot sign in.', 'vereskala-login')),
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

    /**
     * "▲ 12% vs. yesterday by now" for a figure, or '' with nothing to compare.
     *
     * @return array{0: string, 1: string} [text, css class]
     */
    private static function delta(?float $now, ?float $before): array
    {
        if (null === $now || null === $before) {
            return ['', ''];
        }

        if ($before <= 0.0) {
            return $now > 0.0 ? [__('Yesterday by now: none', 'vereskala-login'), 'is-up'] : ['', ''];
        }

        $pct = (int) round(($now - $before) / $before * 100);

        if (0 === $pct) {
            return [__('Same as yesterday by now', 'vereskala-login'), ''];
        }

        /* translators: %s: signed percentage, e.g. ▲ 12%. */
        $text = sprintf(__('%s vs. yesterday by now', 'vereskala-login'), ($pct > 0 ? '▲ ' : '▼ ') . number_format_i18n(abs($pct)) . '٪');

        return [$text, $pct > 0 ? 'is-up' : 'is-down'];
    }

    private static function day_label(int $ts, bool $is_today): string
    {
        if ($is_today) {
            return __('Today', 'vereskala-login');
        }

        $weekday = function_exists('wp_date') ? (string) wp_date('l', $ts) : '';
        $date    = class_exists('VKL_Jalali') ? substr(VKL_Jalali::format(gmdate('Y-m-d H:i:s', $ts + 12 * HOUR_IN_SECONDS), 'short'), 5) : gmdate('m/d', $ts);

        return trim($weekday . ' ' . $date);
    }

    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }

        $waiting = $this->waiting();
        $figures = $this->figures();
        $recent  = $this->recent_orders();
        $health  = $this->health();
        $open    = array_sum(array_map(static fn(array $c): int => max(0, (int) $c['count']), $waiting));
        $price   = static fn(float $v): string => function_exists('wc_price') ? wp_kses_post(wc_price($v)) : esc_html(number_format_i18n($v));
        $date    = class_exists('VKL_Jalali') ? (string) self::safe(static fn(): string => VKL_Jalali::format(gmdate('Y-m-d H:i:s'), 'long')) : '';
        $issues  = count(array_filter($health, static fn(array $h): bool => !$h['ok']));
        ?>
        <div class="wrap vkl-today">
            <h1><?php esc_html_e('Today', 'vereskala-login'); ?><?php if ('' !== $date && '—' !== $date) : ?> <span class="vkl-date"><?php echo esc_html($date); ?></span><?php endif; ?></h1>
            <p class="vkl-summary">
                <?php
                echo 0 === $open
                    ? esc_html__('Nothing is waiting on you right now.', 'vereskala-login')
                    /* translators: %s: number of items. */
                    : esc_html(sprintf(__('Items waiting on you: %s', 'vereskala-login'), number_format_i18n($open)));

                if ($issues > 0) {
                    /* translators: %s: number of failing health checks. */
                    echo ' · <a href="#vkl-health">' . esc_html(sprintf(__('%s health warnings', 'vereskala-login'), number_format_i18n($issues))) . '</a>';
                }
                ?>
            </p>

            <style>
                .vkl-today{max-width:1140px}
                .vkl-today h1 .vkl-date{font-size:14px;font-weight:400;color:#50575e;margin-inline-start:8px}
                .vkl-today .vkl-summary{font-size:14px;margin:4px 0 14px}
                .vkl-today .vkl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;margin:10px 0 26px}
                .vkl-today .vkl-card{display:flex;flex-direction:column;gap:4px;background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:14px 16px;text-decoration:none;color:#1d2327}
                .vkl-today a.vkl-card:hover,.vkl-today a.vkl-card:focus-visible{border-color:#157f3a;box-shadow:0 2px 10px rgba(16,42,34,.08);outline:none}
                .vkl-today .vkl-card .n{font-size:26px;font-weight:800;line-height:1.25;font-variant-numeric:tabular-nums}
                .vkl-today .vkl-card.is-waiting{border-inline-start:4px solid #d63638}
                .vkl-today .vkl-card.is-waiting .n{color:#b32d2e}
                .vkl-today .vkl-card.is-clear{background:#f6f7f7}
                .vkl-today .vkl-card.is-clear .n{color:#157f3a;font-size:15px;font-weight:700}
                .vkl-today .vkl-card .l{font-size:13px;color:#50575e}
                .vkl-today .vkl-card .d{font-size:12px;color:#50575e}
                .vkl-today .vkl-card .d.is-up{color:#157f3a}
                .vkl-today .vkl-card .d.is-down{color:#b32d2e}
                .vkl-today .vkl-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:16px;margin-bottom:26px}
                @media (max-width:1100px){.vkl-today .vkl-two{grid-template-columns:1fr}}
                .vkl-today .vkl-panel{background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:14px 16px}
                .vkl-today .vkl-panel h2{margin:0 0 10px;font-size:14px}
                .vkl-today .vkl-bars{list-style:none;margin:0;padding:0;display:grid;gap:6px}
                .vkl-today .vkl-bars li{display:grid;grid-template-columns:110px 1fr auto;gap:8px;align-items:center;font-size:12px}
                .vkl-today .vkl-bars .track{background:#f0f0f1;border-radius:4px;height:14px;overflow:hidden}
                .vkl-today .vkl-bars .fill{display:block;height:100%;background:#7ab893;border-radius:4px}
                .vkl-today .vkl-bars li.is-today .fill{background:#157f3a}
                .vkl-today .vkl-bars li.is-today{font-weight:700}
                .vkl-today .vkl-bars .v{font-variant-numeric:tabular-nums;white-space:nowrap}
                .vkl-today .vkl-panel table{border:0}
                .vkl-today .vkl-panel td{vertical-align:middle}
                .vkl-today .vkl-pill{display:inline-block;padding:0 8px;border-radius:999px;background:#f0f0f1;font-size:12px;white-space:nowrap}
                .vkl-today .vkl-pill.st-processing{background:#e7f5ec;color:#0f5d2b}
                .vkl-today .vkl-pill.st-on-hold{background:#fcf3e2;color:#7a4b00}
                .vkl-today .vkl-pill.st-pending{background:#eef2f8;color:#2c3e66}
                .vkl-today .vkl-pill.st-cancelled,.vkl-today .vkl-pill.st-failed{background:#fbeaea;color:#8a1f1f}
                .vkl-today .vkl-health{list-style:none;margin:10px 0;padding:0;display:grid;gap:8px}
                .vkl-today .vkl-health a{display:flex;gap:10px;align-items:baseline;background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px;text-decoration:none;color:#1d2327}
                .vkl-today .vkl-health .dot{flex:0 0 auto;width:10px;height:10px;border-radius:50%;background:#157f3a}
                .vkl-today .vkl-health .bad a{border-color:#f0c4c4}
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
                    <?php [$d_text, $d_class] = self::delta((float) $figures['orders'], null !== $figures['y_orders'] ? (float) $figures['y_orders'] : null); ?>
                    <a class="vkl-card" href="<?php echo esc_url(self::orders_url()); ?>">
                        <span class="n"><?php echo esc_html(number_format_i18n($figures['orders'])); ?></span>
                        <span class="l"><?php esc_html_e('Paid orders today', 'vereskala-login'); ?></span>
                        <?php if ('' !== $d_text) : ?><span class="d <?php echo esc_attr($d_class); ?>"><?php echo esc_html($d_text); ?></span><?php endif; ?>
                    </a>
                    <?php [$d_text, $d_class] = self::delta((float) $figures['revenue'], $figures['y_revenue']); ?>
                    <a class="vkl-card" href="<?php echo esc_url(admin_url('admin.php?page=vkl-history&vkl_range=day')); ?>">
                        <span class="n"><?php echo $price((float) $figures['revenue']); // phpcs:ignore ?></span>
                        <span class="l"><?php esc_html_e('Sales today', 'vereskala-login'); ?></span>
                        <?php if ('' !== $d_text) : ?><span class="d <?php echo esc_attr($d_class); ?>"><?php echo esc_html($d_text); ?></span><?php endif; ?>
                    </a>
                    <div class="vkl-card">
                        <span class="n"><?php echo null !== $figures['aov'] ? $price((float) $figures['aov']) : '—'; // phpcs:ignore ?></span>
                        <span class="l"><?php esc_html_e('Average order value', 'vereskala-login'); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (null !== $figures['new_customers']) : ?>
                    <a class="vkl-card" href="<?php echo esc_url(admin_url('admin.php?page=vkl-customers')); ?>">
                        <span class="n"><?php echo esc_html(number_format_i18n($figures['new_customers'])); ?></span>
                        <span class="l"><?php esc_html_e('New customer accounts', 'vereskala-login'); ?></span>
                    </a>
                <?php endif; ?>
                <?php if (null !== $figures['sms_sent']) : ?>
                    <a class="vkl-card" href="<?php echo esc_url(admin_url('admin.php?page=vkl-sms-logs')); ?>">
                        <span class="n"><?php echo esc_html(number_format_i18n($figures['sms_sent'])); ?></span>
                        <span class="l"><?php esc_html_e('Messages sent today', 'vereskala-login'); ?></span>
                        <?php if ($figures['sms_failed'] > 0) : ?>
                            <span class="d is-down"><?php /* translators: %s: number of failed messages. */ echo esc_html(sprintf(__('%s failed', 'vereskala-login'), number_format_i18n((int) $figures['sms_failed']))); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ([] !== $figures['days'] || [] !== $recent) : ?>
                <div class="vkl-two">
                    <?php if ([] !== $figures['days']) : ?>
                        <section class="vkl-panel">
                            <h2><?php esc_html_e('Sales, last 7 days', 'vereskala-login'); ?></h2>
                            <?php
                            $max  = max(1.0, (float) max(array_column($figures['days'], 'revenue')));
                            $last = count($figures['days']) - 1;
                            $week = array_sum(array_column($figures['days'], 'revenue'));
                            ?>
                            <ul class="vkl-bars">
                                <?php foreach (array_reverse($figures['days'], true) as $i => $day) : ?>
                                    <li class="<?php echo $i === $last ? 'is-today' : ''; ?>">
                                        <span><?php echo esc_html(self::day_label((int) $day['ts'], $i === $last)); ?></span>
                                        <span class="track" aria-hidden="true"><span class="fill" style="width:<?php echo esc_attr((string) round($day['revenue'] / $max * 100, 1)); ?>%"></span></span>
                                        <span class="v"><?php echo $price((float) $day['revenue']); // phpcs:ignore ?> · <?php /* translators: %s: number of orders. */ echo esc_html(sprintf(_n('%s order', '%s orders', (int) $day['orders'], 'vereskala-login'), number_format_i18n((int) $day['orders']))); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="description">
                                <?php esc_html_e('Total', 'vereskala-login'); ?>: <?php echo $price((float) $week); // phpcs:ignore ?> ·
                                <?php esc_html_e('Wallet top-ups and installment repayments are not counted as sales.', 'vereskala-login'); ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=vkl-history')); ?>"><?php esc_html_e('Sales report', 'vereskala-login'); ?> ←</a>
                            </p>
                        </section>
                    <?php endif; ?>

                    <?php if ([] !== $recent) : ?>
                        <section class="vkl-panel">
                            <h2><?php esc_html_e('Latest orders', 'vereskala-login'); ?></h2>
                            <table class="widefat striped">
                                <tbody>
                                <?php foreach ($recent as $order) : ?>
                                    <?php
                                    $oid     = (int) $order->get_id();
                                    $uid     = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
                                    $name    = trim((string) $order->get_formatted_billing_full_name());
                                    $status  = (string) $order->get_status();
                                    $created = $order->get_date_created();
                                    $carrier = self::is_carrier($oid);
                                    ?>
                                    <tr>
                                        <td><a href="<?php echo esc_url(self::order_edit_url($oid)); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a></td>
                                        <td>
                                            <?php if ($uid > 0) : ?>
                                                <a href="<?php echo esc_url(VKL_Customers_Admin::profile_url($uid)); ?>"><?php echo esc_html('' !== $name ? $name : __('Customer', 'vereskala-login')); ?></a>
                                            <?php else : ?>
                                                <?php echo esc_html('' !== $name ? $name : __('Guest', 'vereskala-login')); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="num"><?php echo $price((float) $order->get_total()); // phpcs:ignore ?></td>
                                        <td>
                                            <span class="vkl-pill st-<?php echo esc_attr($status); ?>"><?php echo esc_html(function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status); ?></span>
                                            <?php if ($carrier) : ?><span class="vkl-pill"><?php esc_html_e('Not a sale', 'vereskala-login'); ?></span><?php endif; ?>
                                        </td>
                                        <td><?php echo $created && class_exists('VKL_Jalali') ? esc_html(VKL_Jalali::format(gmdate('Y-m-d H:i:s', (int) $created->getTimestamp()), 'short', true)) : ''; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p><a href="<?php echo esc_url(self::orders_url()); ?>"><?php esc_html_e('All orders', 'vereskala-login'); ?> ←</a></p>
                        </section>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2 class="title" id="vkl-health"><?php esc_html_e('Health', 'vereskala-login'); ?></h2>
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
