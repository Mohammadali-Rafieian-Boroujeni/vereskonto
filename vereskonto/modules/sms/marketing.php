<?php
/**
 * Marketing campaigns — bulk pattern SMS to audience segments, processed
 * in cron batches with a cursor so 100k+ recipients never block a request.
 *
 * Audiences: all (any user with a valid mobile), buyers (users with at
 * least one paid order), abandoned (abandoned-cart mobiles), wallet
 * (users with positive wallet balance).
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Marketing
 */
final class VKL_Sms_Marketing extends VKL_Repository
{
    public const CRON_HOOK = 'vkl_campaign_process';

    public const AUDIENCES = ['all', 'buyers', 'abandoned', 'wallet', 'club', 'tier_bronze', 'tier_silver', 'tier_gold', 'tier_diamond'];

    private const BATCH = 100;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';

    private VKL_Sms_Sender $sender;

    private VKL_Validator $validator;

    /**
     * Config (tier thresholds for club segments).
     */
    private VKL_Config $config;

    public function __construct(VKL_Database $db, VKL_Sms_Sender $sender, VKL_Validator $validator, VKL_Config $config)
    {
        parent::__construct($db);
        $this->config = $config;

        $this->sender    = $sender;
        $this->validator = $validator;
    }

    protected function table_name(): string
    {
        return 'campaigns';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        // 1.19.0 re-declares to add campaign_type (survey invitations get
        // their own traceable log type).
        foreach (['1.5.0', '1.19.0', '1.30.0'] as $campaigns_version) :
            $migrator->register($campaigns_version, static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('campaigns') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    title VARCHAR(190) NOT NULL,
                    campaign_type VARCHAR(30) NOT NULL DEFAULT 'campaign',
                    repeat_json TEXT NULL,
                    pattern_code VARCHAR(50) NOT NULL,
                    params LONGTEXT NULL,
                    audience VARCHAR(20) NOT NULL,
                    status VARCHAR(15) NOT NULL DEFAULT 'pending',
                    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    sent INT UNSIGNED NOT NULL DEFAULT 0,
                    failed INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    finished_at DATETIME NULL,
                    PRIMARY KEY  (id),
                    KEY status (status)
                ) {$charset};"
            );
        });
        endforeach;

        // 1.7.0: scheduling + send cap. dbDelta adds the new columns on
        // existing installs; old campaigns keep NULL/0 = unrestricted.
        $migrator->register('1.7.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('campaigns') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    title VARCHAR(190) NOT NULL,
                    campaign_type VARCHAR(30) NOT NULL DEFAULT 'campaign',
                    repeat_json TEXT NULL,
                    pattern_code VARCHAR(50) NOT NULL,
                    params LONGTEXT NULL,
                    audience VARCHAR(20) NOT NULL,
                    status VARCHAR(15) NOT NULL DEFAULT 'pending',
                    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    sent INT UNSIGNED NOT NULL DEFAULT 0,
                    failed INT UNSIGNED NOT NULL DEFAULT 0,
                    max_recipients INT UNSIGNED NOT NULL DEFAULT 0,
                    scheduled_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    finished_at DATETIME NULL,
                    PRIMARY KEY  (id),
                    KEY status (status)
                ) {$charset};"
            );
        });
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'add_interval']);
        add_action(self::CRON_HOOK, [$this, 'process']);
        add_action('init', [$this, 'maybe_schedule']);
    }

    /**
     * Own the five-minute interval instead of borrowing the cart
     * module's: if that module ever stops registering it,
     * wp_schedule_event() would silently fail and campaigns would sit in
     * the queue forever.
     *
     * @param array<string, array{interval: int, display: string}> $schedules Schedules.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function add_interval(array $schedules): array
    {
        if (!isset($schedules[VKL_Cart_Scheduler::CRON_INTERVAL])) {
            $schedules[VKL_Cart_Scheduler::CRON_INTERVAL] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every five minutes (Vereskonto)', 'vereskala-login'),
            ];
        }

        return $schedules;
    }

    public function maybe_schedule(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return;
        }

        $scheduled = wp_schedule_event(time() + MINUTE_IN_SECONDS, VKL_Cart_Scheduler::CRON_INTERVAL, self::CRON_HOOK);

        // Never leave the queue unattended: fall back to hourly if the
        // custom interval is unavailable for any reason.
        if (false === $scheduled) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    /**
     * Run one batch immediately (manual "process now" from the admin).
     */
    /**
     * Sent and failed counters of the most recently created campaign.
     *
     * @return array{sent: int, failed: int}
     */
    public function batch_stats(): array
    {
        $row = $this->db->get_row(
            'SELECT sent, failed FROM {campaigns} ORDER BY id DESC LIMIT 1'
        );

        return [
            'sent'   => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    public function process_now(): void
    {
        $this->process();
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);

        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * Create a campaign (queued for cron processing).
     *
     * @param array<string, string> $params         Pattern parameters (static).
     * @param string                $scheduled_at   Local 'Y-m-d H:i' start time; '' = send as soon as possible.
     * @param int                   $max_recipients Stop after this many attempts; 0 = no cap.
     */
    public function create(
        string $title,
        string $pattern,
        array $params,
        string $audience,
        string $scheduled_at = '',
        int $max_recipients = 0,
        string $campaign_type = 'campaign',
        string $repeat_json = ''
    ): int {
        $scheduled_utc = null;

        if ('' !== $scheduled_at) {
            $ts = strtotime(get_gmt_from_date($scheduled_at));

            if (false !== $ts && $ts > 0) {
                $scheduled_utc = gmdate('Y-m-d H:i:s', $ts);
            }
        }

        return $this->insert_row(
            [
                'title'          => $title,
                'campaign_type'  => in_array($campaign_type, ['campaign', 'campaign.survey'], true) ? $campaign_type : 'campaign',
                'repeat_json'    => $repeat_json,
                'pattern_code'   => $pattern,
                'params'         => wp_json_encode($params, JSON_UNESCAPED_UNICODE),
                'audience'       => $this->parse_audience($audience)[1],
                'status'         => self::STATUS_PENDING,
                'max_recipients' => max(0, $max_recipients),
                'scheduled_at'   => $scheduled_utc,
                'created_at'     => $this->now(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Latest campaigns for the admin screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20): array
    {
        return $this->db->get_results('SELECT * FROM {campaigns} ORDER BY id DESC LIMIT %d', $limit);
    }

    /**
     * Cron handler: advance the oldest due, unfinished campaign by one batch.
     */
    public function process(): void
    {
        $campaign = $this->db->get_row(
            'SELECT * FROM {campaigns}
             WHERE status IN (%s, %s)
               AND (scheduled_at IS NULL OR scheduled_at <= %s)
             ORDER BY id ASC LIMIT 1',
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            $this->now()
        );

        if (null === $campaign) {
            return;
        }

        $id     = (int) $campaign['id'];
        $cursor = (int) $campaign['cursor_id'];
        $cap    = (int) ($campaign['max_recipients'] ?? 0);
        $used   = (int) $campaign['sent'] + (int) $campaign['failed'];

        // Recipient cap reached → finish without another batch.
        if ($cap > 0 && $used >= $cap) {
            $this->finish($id);

            return;
        }

        $batch = $this->next_batch((string) $campaign['audience'], $cursor);

        // Respect the cap inside the batch too.
        if ($cap > 0 && count($batch['mobiles']) > $cap - $used) {
            $batch['mobiles'] = array_slice($batch['mobiles'], 0, $cap - $used);
        }

        if (!$batch['mobiles']) {
            $this->finish($id);

            return;
        }

        $params = json_decode((string) $campaign['params'], true) ?: [];

        $result = $this->sender->send_bulk(
            $batch['mobiles'],
            (string) ($campaign['campaign_type'] ?? 'campaign') . '.' . $id,
            (string) $campaign['pattern_code'],
            $params
        );

        $this->db->query(
            'UPDATE {campaigns}
             SET status = %s, cursor_id = %d, sent = sent + %d, failed = failed + %d
             WHERE id = %d',
            self::STATUS_PROCESSING,
            $batch['cursor'],
            $result['sent'],
            $result['failed'],
            $id
        );
    }

    /**
     * Mark a campaign done and announce it.
     */
    /**
     * Next occurrence (GMT) from a repeat spec: JSON array of
     * {d: 0-6 or "*", t: "HH:MM"} rows in SITE time. '' when invalid.
     */
    public function next_occurrence(string $repeat_json): string
    {
        $rows = json_decode($repeat_json, true);

        if (!is_array($rows) || [] === $rows) {
            return '';
        }

        $now  = (int) current_time('timestamp');
        $best = 0;

        foreach ($rows as $row) {
            $time = (string) ($row['t'] ?? '');

            if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
                continue;
            }

            for ($ahead = 0; $ahead <= 7; $ahead++) {
                $day = strtotime(gmdate('Y-m-d', $now + $ahead * DAY_IN_SECONDS));
                $ts  = strtotime(gmdate('Y-m-d', $now + $ahead * DAY_IN_SECONDS) . ' ' . $time);

                if ($ts <= $now) {
                    continue;
                }

                $weekday = (int) gmdate('w', $day);
                $wanted  = $row['d'] ?? '*';

                if ('*' === $wanted || (int) $wanted === $weekday) {
                    $best = 0 === $best ? $ts : min($best, $ts);

                    break;
                }
            }
        }

        return $best > 0 ? gmdate('Y-m-d H:i:s', $best - ((int) current_time('timestamp') - time())) : '';
    }

    private function finish(int $id): void
    {
        // Recurring campaign: instead of closing, jump to the next slot
        // with a fresh cursor (sent/failed keep accumulating for stats).
        $campaign = $this->db->get_row('SELECT repeat_json FROM {campaigns} WHERE id = %d', $id);
        $next     = null !== $campaign ? $this->next_occurrence((string) ($campaign['repeat_json'] ?? '')) : '';

        if ('' !== $next) {
            $this->db->update(
                'campaigns',
                ['status' => self::STATUS_PENDING, 'scheduled_at' => $next, 'cursor_id' => 0],
                ['id' => $id],
                ['%s', '%s', '%d'],
                ['%d']
            );

            return;
        }

        $this->db->update(
            'campaigns',
            ['status' => self::STATUS_DONE, 'finished_at' => $this->now()],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        vkl_dispatch('campaign.finished', ['campaign_id' => $id]);
    }

    /**
     * Next batch of unique, valid mobiles for an audience, after a cursor.
     *
     * @return array{mobiles: string[], cursor: int}
     */
    private function next_batch(string $audience, int $cursor): array
    {
        global $wpdb;

        // Optional purchase-date window: 'buyers@FROM,TO' (either side may
        // be empty; dates are YYYY-MM-DD in site time). Filters the base
        // audience by completed-order date.
        $win_from = '';
        $win_to   = '';

        if (false !== strpos($audience, '@')) {
            [$audience, $window] = explode('@', $audience, 2);
            $parts    = explode(',', $window);
            $win_from = $this->normalize_window_date($parts[0] ?? '', false);
            $win_to   = $this->normalize_window_date($parts[1] ?? '', true);
        }

        // Club tier segments: yearly earned points vs the configured
        // thresholds (same maths as the "Top members" ranking).
        // Festival-grant recipients: audience 'promo:{batch}' targets
        // exactly the customers of one wallet-promo batch.
        if (str_starts_with($audience, 'promo:')) {
            $batch = substr($audience, 6);

            $rows = $this->db->get_results(
                "SELECT p.id, u.meta_value AS mobile
                 FROM {wallet_promos} p
                 INNER JOIN {$wpdb->usermeta} u ON u.user_id = p.user_id AND u.meta_key = 'billing_phone'
                 WHERE p.batch = %s AND p.id > %d
                 ORDER BY p.id ASC LIMIT %d",
                $batch,
                $cursor,
                self::BATCH
            );

            $mobiles = [];
            $max_id  = $cursor;

            foreach ($rows as $row) {
                $max_id = max($max_id, (int) $row['id']);
                $mobile = $this->validator->normalize_mobile((string) $row['mobile']);

                if ($this->validator->is_mobile($mobile)) {
                    $mobiles[] = $mobile;
                }
            }

            return ['mobiles' => array_values(array_unique($mobiles)), 'cursor' => $max_id];
        }

        if ('club' === $audience || str_starts_with($audience, 'tier_')) {
            $silver  = max(1, $this->config->get_int('loyalty_tier_silver', 50));
            $gold    = max($silver + 1, $this->config->get_int('loyalty_tier_gold', 200));
            $diamond = max($gold + 1, $this->config->get_int('loyalty_tier_diamond', 500));

            [$min, $max] = match ($audience) {
                'tier_bronze'  => [0, $silver - 1],
                'tier_silver'  => [$silver, $gold - 1],
                'tier_gold'    => [$gold, $diamond - 1],
                'tier_diamond' => [$diamond, PHP_INT_MAX],
                default        => [1, PHP_INT_MAX], // 'club': anyone with points.
            };

            $year = (int) gmdate('Y');

            $rows = $this->db->get_results(
                "SELECT p.user_id AS id, um.meta_value AS mobile
                 FROM (
                     SELECT user_id,
                            SUM(CASE WHEN reason = 'order_completed' AND YEAR(created_at) = %d THEN points ELSE 0 END) AS year_points,
                            SUM(points) AS balance
                     FROM {points}
                     GROUP BY user_id
                     HAVING balance > 0 AND year_points BETWEEN %d AND %d
                 ) p
                 JOIN {$wpdb->usermeta} um ON um.user_id = p.user_id AND um.meta_key = 'billing_phone'
                 WHERE p.user_id > %d
                 ORDER BY p.user_id ASC LIMIT %d",
                $year,
                $min,
                $max,
                $cursor,
                self::BATCH
            );

            return $this->collect($rows, $cursor);
        }

        switch ($audience) {

            case 'abandoned':
                $rows = $this->db->get_results(
                    "SELECT id, mobile FROM {cart}
                     WHERE status = %s AND mobile <> '' AND id > %d
                     ORDER BY id ASC LIMIT %d",
                    VKL_Cart_Repository::STATUS_ABANDONED,
                    $cursor,
                    self::BATCH
                );
                break;

            case 'wallet':
                [$w_sql, $w_args] = $this->window_clause('created_at', $win_from, $win_to);
                $rows = $this->db->get_results(
                    "SELECT w.user_id AS id, um.meta_value AS mobile
                     FROM (SELECT user_id, SUM(amount) AS balance FROM {wallet}
                           WHERE 1 = 1 {$w_sql}
                           GROUP BY user_id HAVING balance > 0) w
                     JOIN {$wpdb->usermeta} um ON um.user_id = w.user_id AND um.meta_key = 'billing_phone'
                     WHERE w.user_id > %d
                     ORDER BY w.user_id ASC LIMIT %d",
                    ...array_merge($w_args, [$cursor, self::BATCH])
                );
                break;

            case 'buyers':
                if ('' !== $win_from || '' !== $win_to) {
                    // Customers with a completed/processing order in the
                    // window, resolved through WooCommerce analytics.
                    [$date_sql, $date_args] = $this->window_clause('os.date_created_gmt', $win_from, $win_to);

                    $rows = $this->db->get_results(
                        "SELECT DISTINCT um.user_id AS id, um.meta_value AS mobile
                         FROM {$wpdb->prefix}wc_order_stats os
                         JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = os.customer_id
                         JOIN {$wpdb->usermeta} um ON um.user_id = cl.user_id AND um.meta_key = 'billing_phone'
                         WHERE os.status IN ('wc-completed', 'wc-processing') {$date_sql}
                           AND um.user_id > %d
                         ORDER BY um.user_id ASC LIMIT %d",
                        ...array_merge($date_args, [$cursor, self::BATCH])
                    );
                    break;
                }

                $rows = $this->db->get_results(
                    "SELECT DISTINCT um.user_id AS id, um.meta_value AS mobile
                     FROM {$wpdb->usermeta} um
                     JOIN {$wpdb->usermeta} paying
                       ON paying.user_id = um.user_id
                      AND paying.meta_key = 'paying_customer'
                      AND paying.meta_value = '1'
                     WHERE um.meta_key = 'billing_phone' AND um.user_id > %d
                     ORDER BY um.user_id ASC LIMIT %d",
                    $cursor,
                    self::BATCH
                );
                break;

            case 'all':
            default:
                if ('' !== $win_from || '' !== $win_to) {
                    [$a_sql, $a_args] = $this->window_clause('os.date_created_gmt', $win_from, $win_to);
                    $rows = $this->db->get_results(
                        "SELECT DISTINCT um.user_id AS id, um.meta_value AS mobile
                         FROM {$wpdb->prefix}wc_order_stats os
                         JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = os.customer_id
                         JOIN {$wpdb->usermeta} um ON um.user_id = cl.user_id AND um.meta_key = 'billing_phone'
                         WHERE os.status IN ('wc-completed', 'wc-processing') {$a_sql}
                           AND um.user_id > %d
                         ORDER BY um.user_id ASC LIMIT %d",
                        ...array_merge($a_args, [$cursor, self::BATCH])
                    );
                    break;
                }

                $rows = $this->db->get_results(
                    "SELECT um.user_id AS id, um.meta_value AS mobile
                     FROM {$wpdb->usermeta} um
                     WHERE um.meta_key = 'billing_phone' AND um.meta_value <> '' AND um.user_id > %d
                     ORDER BY um.user_id ASC LIMIT %d",
                    $cursor,
                    self::BATCH
                );
        }

        return $this->collect($rows, $cursor);
    }

    /**
     * Normalise a batch of {id, mobile} rows into unique valid mobiles
     * plus the advanced cursor.
     *
     * @param array<int, array<string, mixed>> $rows   Rows.
     * @param int                              $cursor Previous cursor.
     *
     * @return array{mobiles: string[], cursor: int}
     */
    /**
     * How many mobiles a campaign to this audience would actually reach.
     *
     * Walks the same batches the sender uses, so the number matches what
     * will be billed. Capped so a huge list cannot stall the screen —
     * beyond the cap the caller is told the count is a floor.
     *
     * @return array{count: int, capped: bool}
     */
    public function audience_size(string $audience, int $cap = 5000): array
    {
        $seen   = [];
        $cursor = 0;
        $guard  = 0;

        while ($guard < 200) {
            ++$guard;

            $batch = $this->next_batch($audience, $cursor);
            $list  = $batch['mobiles'] ?? [];

            if ([] === $list) {
                break;
            }

            foreach ($list as $mobile) {
                $seen[$mobile] = true;
            }

            if (count($seen) >= $cap) {
                return ['count' => $cap, 'capped' => true];
            }

            $next = (int) ($batch['cursor'] ?? 0);

            if ($next <= $cursor) {
                break; // no progress — stop rather than loop for ever
            }

            $cursor = $next;
        }

        return ['count' => count($seen), 'capped' => false];
    }

    private function collect(array $rows, int $cursor): array
    {
        $mobiles = [];
        $last    = $cursor;

        foreach ($rows as $row) {
            $last   = max($last, (int) $row['id']);
            $mobile = $this->validator->normalize_mobile((string) $row['mobile']);

            if ($this->validator->is_mobile($mobile)) {
                $mobiles[] = $mobile;
            }
        }

        return ['mobiles' => array_values(array_unique($mobiles)), 'cursor' => $last];
    }

    /**
     * Update an editable (pending/scheduled) campaign.
     */
    public function update_campaign(int $id, string $title, string $pattern, array $params, string $audience, string $scheduled_at): bool
    {
        $campaign = $this->db->get_row('SELECT * FROM {campaigns} WHERE id = %d', $id);

        if (null === $campaign || self::STATUS_PENDING !== (string) $campaign['status']) {
            return false;
        }

        $scheduled_utc = null;

        if ('' !== $scheduled_at) {
            $ts = strtotime(get_gmt_from_date($scheduled_at));

            if (false !== $ts && $ts > 0) {
                $scheduled_utc = gmdate('Y-m-d H:i:s', $ts);
            }
        }

        return 0 < $this->db->update('campaigns', [
            'title'        => mb_substr($title, 0, 190),
            'pattern_code' => $pattern,
            'params'       => wp_json_encode($params, JSON_UNESCAPED_UNICODE),
            'audience'     => $this->parse_audience($audience)[1],
            'scheduled_at' => $scheduled_utc,
            'status'       => self::STATUS_PENDING,
        ], ['id' => $id]);
    }

    /**
     * Delete a campaign (any status — a processing one simply stops).
     */
    public function delete_campaign(int $id): void
    {
        $this->db->delete('campaigns', ['id' => $id]);
    }

    /**
     * Split an 'audience@from,to' spec into a validated base audience and
     * a canonical window suffix (or '' when there is no window).
     *
     * @return array{0: string, 1: string} [base, spec_with_window]
     */
    public function parse_audience(string $raw): array
    {
        $window = '';

        if (false !== strpos($raw, '@')) {
            [$raw, $window] = explode('@', $raw, 2);
        }

        $base = str_starts_with($raw, 'promo:')
            ? $raw
            : $this->validator->one_of($raw, self::AUDIENCES, 'buyers');

        // Only audiences with a purchase-date dimension keep a window.
        if ('' !== $window && in_array($base, ['buyers', 'all', 'wallet', 'club'], true)) {
            $parts = explode(',', $window);
            $from  = $this->normalize_window_date($parts[0] ?? '', false);
            $to    = $this->normalize_window_date($parts[1] ?? '', true);

            if ('' !== $from || '' !== $to) {
                return [$base, $base . '@' . $from . ',' . $to];
            }
        }

        return [$base, $base];
    }

    /**
     * Normalize one side of a window to a full GMT datetime, or '' if the
     * input is not a valid YYYY-MM-DD. $end pins the day to 23:59:59.
     */
    private function normalize_window_date(string $date, bool $end): string
    {
        $date = trim($date);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        $ts = strtotime($date . ($end ? ' 23:59:59' : ' 00:00:00'));

        if (false === $ts) {
            return '';
        }

        // Site-time input to GMT for comparison against *_gmt columns.
        return gmdate('Y-m-d H:i:s', $ts - (int) (wp_timezone()->getOffset(new DateTime('now')) ));
    }

    /**
     * Build a "AND col >= %s AND col <= %s" fragment and its args from an
     * already-normalized GMT window (either side may be empty).
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function window_clause(string $column, string $from, string $to): array
    {
        $sql  = '';
        $args = [];

        if ('' !== $from) {
            $sql   .= " AND {$column} >= %s";
            $args[] = $from;
        }

        if ('' !== $to) {
            $sql   .= " AND {$column} <= %s";
            $args[] = $to;
        }

        return [$sql, $args];
    }
}
