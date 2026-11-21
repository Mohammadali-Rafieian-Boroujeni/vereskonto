<?php
/**
 * Festival (promo) wallet credit.
 *
 * Separate ledger from the real wallet: batch-granted bonus credit for
 * campaigns that (a) never appears in the customer's transaction
 * statement, (b) is spent BEFORE real balance at checkout, and (c)
 * silently expires after its window if unused. The customer sees it as
 * a distinct "festival credit until DATE" line, so the main balance
 * stays truthful.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.25.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Promos
 */
final class VKL_Wallet_Promos extends VKL_Repository
{
    /**
     * Batch id of the most recent grant_many() call.
     */
    /**
     * Cron hook for the delayed automatic grant.
     */
    public const AUTO_HOOK = 'vkl_promo_auto_grant';

    public string $last_batch = '';

    private VKL_Config $config;

    /**
     * Constructor.
     */
    public function __construct(VKL_Database $db, VKL_Config $config)
    {
        parent::__construct($db);

        $this->config = $config;
    }

    /**
     * Table key.
     */
    protected function table_name(): string
    {
        return 'wallet_promos';
    }

    /**
     * Migrations.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        foreach (['1.25.0', '1.26.0', '1.27.0'] as $promos_version) :
            $migrator->register($promos_version, static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_wallet_promos (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    title VARCHAR(190) NOT NULL DEFAULT '',
                    batch VARCHAR(32) NOT NULL DEFAULT '',
                    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                    remaining DECIMAL(18,4) NOT NULL DEFAULT 0,
                    min_order DECIMAL(18,4) NOT NULL DEFAULT 0,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_active (user_id, remaining, expires_at)
                ) {$wpdb->get_charset_collate()};"
            );
        });
        endforeach;

        // Existing rows get a derived batch id so old grants stay manageable.
        $migrator->register('1.27.1', static function (): void {
            global $wpdb;

            $wpdb->query("UPDATE {$wpdb->prefix}vkl_wallet_promos SET batch = SUBSTRING(MD5(CONCAT(title, '|', expires_at, '|', amount)), 1, 32) WHERE batch = ''"); // phpcs:ignore
        });
    }

    /**
     * Register hooks: daily expiry sweep piggybacks on the installment
     * daily job (already scheduled), plus a cheap transient guard.
     */
    public function register(): void
    {
        add_action('vkl_installment_check', [$this, 'expire']);

        // Automatic post-purchase festival credit.
        add_action('woocommerce_order_status_completed', [$this, 'schedule_auto_grant'], 20);
        add_action('woocommerce_order_status_processing', [$this, 'schedule_auto_grant'], 20);
        add_action(self::AUTO_HOOK, [$this, 'run_auto_grant'], 10, 2);
    }

    /**
     * A qualifying order books its reward for later. The delay, amount,
     * validity window and spend threshold are all admin settings, so the
     * shop can run "buy today, get credit tomorrow" campaigns without a
     * developer. The order carries a flag so a status change from
     * processing to completed cannot book the same reward twice.
     */
    public function schedule_auto_grant(int $order_id): void
    {
        if (!$this->config->get_bool('promo_auto_enabled', false)) {
            return;
        }

        $amount = (float) $this->config->get_int('promo_auto_amount', 0);

        if ($amount <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $user_id = (int) $order->get_customer_id();

        if ('yes' === $order->get_meta('_vkl_promo_auto')) {
            return;
        }

        if ($user_id <= 0) {
            // Every silent skip used to leave no trace, so "it just
            // doesn't work" had no way to be diagnosed from wp-admin.
            // Recorded under the same 'promo.auto' type as the SMS
            // notice, so both show up together in the SMS/campaign log.
            $this->log_skip($order_id, 'مهمان بدون حساب کاربری — اعتبار جشنواره فقط به مشتریان واردشده تعلق می‌گیرد.');

            return;
        }

        // Only orders at or above the qualifying total earn the reward.
        $min_order = (float) $this->config->get_int('promo_auto_min_order', 0);

        if ((float) $order->get_total() < $min_order) {
            $this->log_skip($order_id, sprintf(
                'جمع سفارش (%1$s) کمتر از حد نصاب تعیین‌شده (%2$s) است.',
                number_format_i18n((int) $order->get_total()),
                number_format_i18n((int) $min_order)
            ));

            return;
        }

        $order->update_meta_data('_vkl_promo_auto', 'yes');
        $order->save();

        $delay = max(0, $this->config->get_int('promo_auto_delay_hours', 0));

        if ($delay > 0) {
            wp_schedule_single_event(time() + $delay * HOUR_IN_SECONDS, self::AUTO_HOOK, [$order_id, $user_id]);

            return;
        }

        $this->run_auto_grant($order_id, $user_id);
    }

    /**
     * Record why an automatic grant was skipped, so it shows up in the
     * SMS/campaign log instead of vanishing silently.
     */
    private function log_skip(int $order_id, string $reason): void
    {
        if (!vkl()->has('sms.log')) {
            return;
        }

        vkl('sms.log')->record(
            '-',
            'promo.auto',
            'promo:' . $order_id,
            [],
            VKL_Sms_Log_Repository::STATUS_FAILED,
            $reason
        );
    }

    /**
     * Deposit the booked reward and announce it.
     */
    public function run_auto_grant(int $order_id, int $user_id): void
    {
        if (!$this->config->get_bool('promo_auto_enabled', false)) {
            return;
        }

        $amount = (float) $this->config->get_int('promo_auto_amount', 0);
        $days   = max(1, $this->config->get_int('promo_auto_days', 7));
        $min    = (float) $this->config->get_int('promo_auto_use_min', 0);
        $title  = $this->config->get_string('promo_auto_title');
        $title  = '' !== $title ? $title : 'اعتبار جشنواره تشکر از خرید';

        if ($amount <= 0 || $user_id <= 0) {
            return;
        }

        // One batch per month keeps the admin list readable: the whole
        // month's automatic grants show up as a single campaign row.
        $batch = 'auto-' . gmdate('Y-m');

        if (1 !== $this->grant_many([$user_id], $amount, $days, $title, $min, $batch)) {
            $this->log_skip($order_id, 'خطا در ثبت اعتبار جشنواره در پایگاه داده — بررسی کنید جدول wallet_promos موجود و سالم باشد.');

            return;
        }

        $pattern = $this->config->get_string('promo_auto_pattern');
        $mobile  = (string) get_user_meta($user_id, 'billing_phone', true);

        // Log why the notice was skipped, so an empty pattern or missing
        // mobile is visible in the SMS report instead of failing silently.
        if ('' === $pattern || !vkl()->has('sms.sender') || '' === $mobile) {
            if (vkl()->has('sms.log')) {
                $reason = '' === $pattern
                    ? 'کد پترن پیامک اعتبار جشنواره خالی است — آن را در تنظیمات کیف پول وارد کنید.'
                    : ('' === $mobile ? 'مشتری شماره موبایلی ثبت‌شده ندارد.' : 'سرویس ارسال پیامک در دسترس نیست.');

                vkl('sms.log')->record(
                    '' !== $mobile ? $mobile : '-',
                    'promo.auto',
                    'promo:' . $order_id,
                    [],
                    VKL_Sms_Log_Repository::STATUS_FAILED,
                    $reason
                );
            }

            return;
        }

        vkl('sms.sender')->send_bulk(
            [$mobile],
            'promo.auto',
            $pattern,
            [
                'name'   => 'auto',
                'amount' => (string) (int) $amount,
                'min'    => (string) (int) $min,
                'days'   => (string) $days,
            ]
        );
    }

    /**
     * Unused festival credit still valid for a user.
     */
    public function active_balance(int $user_id, float $order_total = 0.0): float
    {
        return (float) $this->db->get_var(
            'SELECT COALESCE(SUM(remaining), 0) FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s AND min_order <= %f',
            $user_id,
            gmdate('Y-m-d H:i:s'),
            $order_total > 0 ? $order_total : PHP_FLOAT_MAX
        );
    }

    /**
     * Smallest minimum-order condition among active grants (0 = none).
     */
    public function min_condition(int $user_id): float
    {
        return (float) $this->db->get_var(
            'SELECT COALESCE(MIN(min_order), 0) FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s AND min_order > 0',
            $user_id,
            gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * All non-expired promo credit, ignoring any minimum-order condition.
     * Used to show the customer what they hold even when the current cart
     * does not yet qualify.
     */
    public function total_active(int $user_id): float
    {
        return (float) $this->db->get_var(
            'SELECT COALESCE(SUM(remaining), 0) FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s',
            $user_id,
            gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * Credit that is currently LOCKED because the cart is below its
     * minimum-order condition, plus the smallest cart total that would
     * unlock some of it.
     *
     * @return array{amount: float, min_order: float}
     */
    public function next_unlock(int $user_id, float $order_total): array
    {
        $row = $this->db->get_row(
            'SELECT COALESCE(SUM(remaining), 0) AS amount, COALESCE(MIN(min_order), 0) AS min_order
             FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s AND min_order > %f',
            $user_id,
            gmdate('Y-m-d H:i:s'),
            $order_total
        );

        return [
            'amount'    => (float) ($row['amount'] ?? 0),
            'min_order' => (float) ($row['min_order'] ?? 0),
        ];
    }

    /**
     * Nearest expiry of the user's active promo credit ('' if none).
     */
    public function next_expiry(int $user_id): string
    {
        return (string) $this->db->get_var(
            'SELECT MIN(expires_at) FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s',
            $user_id,
            gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * Consume promo credit FIFO by soonest expiry. Returns the amount
     * actually consumed (0..$amount).
     */
    public function consume(int $user_id, float $amount, float $order_total = 0.0): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        $rows = $this->db->get_results(
            'SELECT id, remaining FROM {wallet_promos}
             WHERE user_id = %d AND remaining > 0 AND expires_at > %s AND min_order <= %f
             ORDER BY expires_at ASC
             LIMIT 200',
            $user_id,
            gmdate('Y-m-d H:i:s'),
            $order_total > 0 ? $order_total : PHP_FLOAT_MAX
        );

        $left = $amount;

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min((float) $row['remaining'], $left);

            $this->db->update(
                'wallet_promos',
                ['remaining' => (float) $row['remaining'] - $take],
                ['id' => (int) $row['id']]
            );

            $left -= $take;
        }

        return $amount - $left;
    }

    /**
     * Batch grant to many users (chunked inserts).
     *
     * @param int[] $user_ids Users.
     *
     * @return int Rows granted.
     */
    public function grant_many(array $user_ids, float $amount, int $days, string $title, float $min_order = 0.0, string $batch = ''): int
    {
        if ($amount <= 0 || [] === $user_ids) {
            return 0;
        }

        $expires = gmdate('Y-m-d H:i:s', time() + max(1, $days) * DAY_IN_SECONDS);
        $now     = gmdate('Y-m-d H:i:s');
        $batch   = '' !== $batch ? substr($batch, 0, 32) : substr(md5(uniqid('vklp', true)), 0, 32);
        $this->last_batch = $batch;
        $granted = 0;

        foreach (array_chunk(array_unique(array_map('intval', $user_ids)), 200) as $chunk) {
            foreach ($chunk as $uid) {
                if ($uid <= 0) {
                    continue;
                }

                $inserted = $this->insert_row([
                    'user_id'    => $uid,
                    'batch'      => $batch,
                    'title'      => mb_substr($title, 0, 190),
                    'amount'     => $amount,
                    'remaining'  => $amount,
                    'min_order'  => max(0.0, $min_order),
                    'expires_at' => $expires,
                    'created_at' => $now,
                ]);

                // Count only rows that actually landed — insert_row()
                // returns 0 on a failed write, and the caller (including
                // the automatic post-purchase grant) relies on this count
                // to know whether the credit really was booked, not just
                // attempted.
                if ($inserted > 0) {
                    ++$granted;
                }
            }
        }

        return $granted;
    }

    /**
     * Expiry sweep: zero out anything past its window. Silent by design —
     * the whole point is that unused festival money disappears without a
     * statement entry.
     */
    public function expire(): void
    {
        $this->db->query(
            'UPDATE {wallet_promos} SET remaining = 0
             WHERE remaining > 0 AND expires_at <= %s',
            gmdate('Y-m-d H:i:s')
        );
    }

    /**
     * Admin stats: users holding active promo credit + total outstanding.
     *
     * @return array{users: int, total: float}
     */
    public function stats(): array
    {
        $row = $this->db->get_row(
            'SELECT COUNT(DISTINCT user_id) AS users, COALESCE(SUM(remaining), 0) AS total
             FROM {wallet_promos} WHERE remaining > 0 AND expires_at > %s',
            gmdate('Y-m-d H:i:s')
        );

        return ['users' => (int) ($row['users'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
    }

    /**
     * Grant batches overview: one row per batch with recipient/consumer
     * counts and money totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function batches(int $limit = 30): array
    {
        return $this->db->get_results(
            'SELECT batch, title, amount, min_order, expires_at, MIN(created_at) AS created_at,
                    COUNT(*) AS recipients,
                    SUM(CASE WHEN remaining < amount THEN 1 ELSE 0 END) AS consumers,
                    COALESCE(SUM(amount - remaining), 0) AS consumed,
                    COALESCE(SUM(remaining), 0) AS remaining_total
             FROM {wallet_promos}
             GROUP BY batch, title, amount, min_order, expires_at
             ORDER BY created_at DESC
             LIMIT %d',
            $limit
        );
    }

    /**
     * Recipients of one batch (consumers first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function batch_recipients(string $batch, int $limit = 100): array
    {
        return $this->db->get_results(
            'SELECT user_id, amount, remaining FROM {wallet_promos}
             WHERE batch = %s
             ORDER BY (amount - remaining) DESC, id ASC
             LIMIT %d',
            $batch,
            $limit
        );
    }

    /**
     * Edit a batch: title, expiry, minimum order (amount stays — partial
     * consumption makes retroactive amount edits dishonest).
     */
    public function update_batch(string $batch, string $title, string $expires_at, float $min_order): void
    {
        $ts = strtotime($expires_at);

        $this->db->query(
            'UPDATE {wallet_promos} SET title = %s, expires_at = %s, min_order = %f WHERE batch = %s',
            mb_substr($title, 0, 190),
            false !== $ts ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS),
            max(0.0, $min_order),
            $batch
        );
    }

    /**
     * Delete a batch entirely (remaining credit disappears for everyone).
     */
    public function delete_batch(string $batch): void
    {
        $this->db->delete('wallet_promos', ['batch' => $batch]);
    }

    /**
     * Most recent automatic (post-purchase) grants.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent_auto(int $limit = 15): array
    {
        return $this->db->get_results(
            "SELECT * FROM {wallet_promos}
             WHERE batch LIKE %s
             ORDER BY id DESC LIMIT %d",
            'auto-%',
            $limit
        );
    }
}
