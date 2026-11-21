<?php
/**
 * Site analytics repository.
 *
 * One row per pageview: session, user, page type, product, search term,
 * referrer host, device, country/city (header-derived, no raw IP is ever
 * stored) and a dwell duration filled in later by the footer beacon.
 * Every dashboard number comes from aggregate queries over this table;
 * a daily retention purge keeps it shared-hosting friendly.
 *
 * @package Vereskonto\Modules\Analytics
 * @since   1.33.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Analytics_Repository
 */
final class VKL_Analytics_Repository extends VKL_Repository
{
    /**
     * Table key.
     */
    protected function table_name(): string
    {
        return 'analytics';
    }

    /**
     * Migrations.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.33.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_analytics (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    session CHAR(32) NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    page_type VARCHAR(20) NOT NULL DEFAULT 'other',
                    product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    search_term VARCHAR(190) NOT NULL DEFAULT '',
                    referrer VARCHAR(190) NOT NULL DEFAULT '',
                    device VARCHAR(10) NOT NULL DEFAULT 'desktop',
                    os VARCHAR(20) NOT NULL DEFAULT '',
                    device_type VARCHAR(20) NOT NULL DEFAULT '',
                    visitor_type VARCHAR(16) NOT NULL DEFAULT 'human',
                    country CHAR(2) NOT NULL DEFAULT '',
                    city VARCHAR(100) NOT NULL DEFAULT '',
                    duration INT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY created_at (created_at),
                    KEY session (session),
                    KEY page_type (page_type),
                    KEY product_id (product_id)
                ) {$wpdb->get_charset_collate()};"
            );
        });

        // 1.33.0 already ran on live sites before these two columns
        // existed; CREATE TABLE never re-adds columns, so add them here.
        $migrator->register('1.45.1', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_analytics';

            // Guard each ADD so re-runs and fresh installs are both fine.
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            if (is_array($cols) && !in_array('os', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN os VARCHAR(20) NOT NULL DEFAULT '' AFTER device"); // phpcs:ignore
            }

            if (is_array($cols) && !in_array('device_type', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN device_type VARCHAR(20) NOT NULL DEFAULT '' AFTER os"); // phpcs:ignore
            }
        });

        // 1.47.0 adds visitor_type (human | search_engine | ai_bot | scraper).
        $migrator->register('1.47.0', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_analytics';
            $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            if (is_array($cols) && !in_array('visitor_type', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN visitor_type VARCHAR(16) NOT NULL DEFAULT 'human' AFTER device_type"); // phpcs:ignore
            }
        });

        // 1.89.0 — the report queries filter by page_type/created_at
        // together and group by visitor_type over a created_at range; the
        // single-column indexes from 1.33.0 cannot serve either as an
        // index scan. ADD INDEX is requested as ONLINE DDL (INPLACE,
        // LOCK=NONE) so it never blocks reads/writes on a large table —
        // MySQL errors out instead of silently falling back to a locking
        // rebuild if the online path is unavailable.
        $migrator->register('1.89.0', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_analytics';

            $have = $wpdb->get_col("SHOW INDEX FROM {$table}", 2); // phpcs:ignore

            if (is_array($have) && !in_array('idx_pagetype_created', $have, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_pagetype_created (page_type, created_at), ALGORITHM=INPLACE, LOCK=NONE"); // phpcs:ignore
            }

            $have = $wpdb->get_col("SHOW INDEX FROM {$table}", 2); // phpcs:ignore

            if (is_array($have) && !in_array('idx_created_visitor', $have, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD INDEX idx_created_visitor (created_at, visitor_type), ALGORITHM=INPLACE, LOCK=NONE"); // phpcs:ignore
            }
        });

        // Bots are no longer written row by row — one tally row per day and
        // per visitor type replaces up to tens of thousands of inserts,
        // while the dashboard's visitor split stays intact.
        // Long-range reports read from this summary once the raw rows
        // behind them have been pruned, so history survives retention.
        $migrator->register('1.95.0', static function (): void {
            global $wpdb;

            $table   = $wpdb->prefix . 'vkl_analytics_daily';
            $collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    day DATE NOT NULL,
                    visitor_type VARCHAR(20) NOT NULL DEFAULT 'human',
                    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    visitors BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    avg_duration INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY  (id),
                    UNIQUE KEY day_type (day, visitor_type)
                ) {$collate};"
            );
        });

        $migrator->register('1.90.0', static function (): void {
            global $wpdb;

            $table   = $wpdb->prefix . 'vkl_analytics_tally';
            $collate = $wpdb->get_charset_collate();

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tally_date DATE NOT NULL,
                    visitor_type VARCHAR(20) NOT NULL DEFAULT 'scraper',
                    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY  (id),
                    UNIQUE KEY day_type (tally_date, visitor_type)
                ) {$collate};"
            );
        });
    }

    /**
     * Count one bot view without storing a row for it.
     *
     * A single UPSERT per day and visitor type, instead of one INSERT per
     * request. With roughly two thirds of traffic automated, this is where
     * the write volume actually goes.
     */
    public function tally_bot(string $visitor_type): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_analytics_tally';

        $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "INSERT INTO {$table} (tally_date, visitor_type, views)
                 VALUES (%s, %s, 1)
                 ON DUPLICATE KEY UPDATE views = views + 1",
                current_time('Y-m-d'),
                substr($visitor_type, 0, 20)
            )
        );
    }

    /**
     * Bot views per type in a date range, from the tally table.
     *
     * @return array<string, int>
     */
    public function tally_breakdown(string $from, string $to): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_analytics_tally';

        $rows = $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT visitor_type, SUM(views) AS views
                 FROM {$table}
                 WHERE tally_date BETWEEN %s AND %s
                 GROUP BY visitor_type",
                substr($from, 0, 10),
                substr($to, 0, 10)
            ),
            ARRAY_A
        );

        $out = [];

        foreach ((array) $rows as $row) {
            $out[(string) $row['visitor_type']] = (int) $row['views'];
        }

        return $out;
    }

    /**
     * Record one pageview. Returns the row id (for the dwell beacon).
     *
     * @param array<string, mixed> $hit Pageview fields.
     */
    public function record(array $hit): int
    {
        $row = [
            'session'     => substr((string) ($hit['session'] ?? ''), 0, 32),
            'user_id'     => (int) ($hit['user_id'] ?? 0),
            'page_type'   => substr((string) ($hit['page_type'] ?? 'other'), 0, 20),
            'product_id'  => (int) ($hit['product_id'] ?? 0),
            'search_term' => mb_substr((string) ($hit['search_term'] ?? ''), 0, 190),
            'referrer'    => mb_substr((string) ($hit['referrer'] ?? ''), 0, 190),
            'device'      => in_array($hit['device'] ?? '', ['mobile', 'desktop'], true) ? $hit['device'] : 'desktop',
            'country'     => strtoupper(substr((string) ($hit['country'] ?? ''), 0, 2)),
            'city'        => mb_substr((string) ($hit['city'] ?? ''), 0, 100),
            'duration'    => 0,
            'created_at'  => $this->now(),
        ];

        // os / device_type were added in 1.45.1; on a site that has not
        // run that migration yet, skip them so the insert still succeeds.
        if ($this->has_platform_columns()) {
            $row['os']          = mb_substr((string) ($hit['os'] ?? ''), 0, 20);
            $row['device_type'] = mb_substr((string) ($hit['device_type'] ?? ''), 0, 20);
        }

        if ($this->has_visitor_column()) {
            $allowed = ['human', 'search_engine', 'ai_bot', 'scraper'];
            $vt      = (string) ($hit['visitor_type'] ?? 'human');
            $row['visitor_type'] = in_array($vt, $allowed, true) ? $vt : 'human';
        }

        return $this->insert_row($row);
    }

    /**
     * Whether the os/device_type columns exist (cached per request).
     */
    private function has_platform_columns(): bool
    {
        static $has = null;

        if (null !== $has) {
            return $has;
        }

        // Once confirmed, remembered in an autoloaded option so every
        // later pageview reads it off the existing alloptions cache
        // instead of running SHOW COLUMNS again on every single request.
        if ('1' === get_option('vkl_analytics_has_platform_cols', '')) {
            return $has = true;
        }

        global $wpdb;

        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->prefix . 'vkl_analytics'); // phpcs:ignore
        $has  = is_array($cols) && in_array('device_type', $cols, true);

        if ($has) {
            update_option('vkl_analytics_has_platform_cols', '1', true);
        }

        return $has;
    }

    /**
     * When true, every report query counts only real people.
     *
     * The headline numbers used to mix bots in with humans, so the figures
     * a shop owner makes decisions on were inflated by crawler traffic.
     * This flag lets the dashboard switch the whole page at once instead
     * of filtering panel by panel.
     */
    private bool $humans_only = false;

    /**
     * Limit every subsequent query in this request to human visits.
     */
    public function set_humans_only(bool $on): void
    {
        $this->humans_only = $on;
    }

    public function humans_only(): bool
    {
        return $this->humans_only;
    }

    /**
     * SQL fragment to append inside an existing WHERE clause.
     *
     * Returns '' when the filter is off or the column has not been
     * migrated yet, so old installations keep working untouched.
     */
    private function human_clause(string $alias = ''): string
    {
        if (!$this->humans_only || !$this->has_visitor_column()) {
            return '';
        }

        $prefix = '' !== $alias ? $alias . '.' : '';

        return " AND {$prefix}visitor_type = 'human'";
    }

    /**
     * Whether the visitor_type column exists (cached per request).
     */
    private function has_visitor_column(): bool
    {
        static $has = null;

        if (null !== $has) {
            return $has;
        }

        // Same reasoning as has_platform_columns(): skip the SHOW COLUMNS
        // round-trip on every pageview once we already know the answer.
        if ('1' === get_option('vkl_analytics_has_visitor_col', '')) {
            return $has = true;
        }

        global $wpdb;

        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . $wpdb->prefix . 'vkl_analytics'); // phpcs:ignore
        $has  = is_array($cols) && in_array('visitor_type', $cols, true);

        if ($has) {
            update_option('vkl_analytics_has_visitor_col', '1', true);
        }

        return $has;
    }

    /**
     * Visitor-type split (human/search_engine/ai_bot/scraper) in a range.
     *
     * @return array<string, array{views: int, sessions: int}>
     */
    public function visitor_breakdown(string $from, string $to): array
    {
        $out = [
            'human'         => ['views' => 0, 'sessions' => 0],
            'search_engine' => ['views' => 0, 'sessions' => 0],
            'ai_bot'        => ['views' => 0, 'sessions' => 0],
            'scraper'       => ['views' => 0, 'sessions' => 0],
        ];

        if (!$this->has_visitor_column()) {
            return $out;
        }

        $rows = $this->db->get_results(
            "SELECT visitor_type, COUNT(*) AS views, COUNT(DISTINCT session) AS sessions
             FROM {analytics}
             WHERE created_at BETWEEN %s AND %s
             GROUP BY visitor_type",
            $from,
            $to
        );

        // Bot views live in the tally table now, not as raw rows. Merge
        // them in so the split keeps showing the whole picture even though
        // only human visits are stored individually.
        foreach ($this->tally_breakdown($from, $to) as $bot_type => $bot_views) {
            if (isset($out[$bot_type])) {
                $out[$bot_type]['views'] += $bot_views;
            }
        }

        foreach ($rows as $row) {
            $key = (string) $row['visitor_type'];

            if (isset($out[$key])) {
                $out[$key] = [
                    'views'    => (int) $row['views'] + (int) ($out[$key]['views'] ?? 0),
                    'sessions' => (int) $row['sessions'],
                ];
            }
        }

        return $out;
    }

    /**
     * Per-product visitor-type split (for the top-products chips).
     *
     * @return array<int, array<string, mixed>>
     */
    public function product_visitor_types(int $product_id, string $from, string $to): array
    {
        if (!$this->has_visitor_column()) {
            return [];
        }

        return $this->db->get_results(
            "SELECT visitor_type, COUNT(*) AS views
             FROM {analytics}
             WHERE page_type = 'product' AND product_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY visitor_type
             ORDER BY views DESC",
            $product_id,
            $from,
            $to
        );
    }

    /**
     * Dwell beacon: add seconds to one row, guarded by its session so a
     * stranger cannot inflate someone else's hit.
     */
    public function add_duration(int $id, string $session, int $seconds): void
    {
        $this->db->query(
            'UPDATE {analytics} SET duration = LEAST(duration + %d, 7200)
             WHERE id = %d AND session = %s',
            max(0, min(3600, $seconds)),
            $id,
            $session
        );
    }

    /**
     * Headline numbers for a range.
     *
     * @return array{views: int, sessions: int, visitors: int, avg_duration: int, product_views: int, searches: int}
     */
    public function summary(string $from, string $to): array
    {
        $row = $this->db->get_row(
            "SELECT COUNT(*) AS views,
                    COUNT(DISTINCT session) AS sessions,
                    COUNT(DISTINCT CASE WHEN user_id > 0 THEN CONCAT('u', user_id) ELSE session END) AS visitors,
                    COALESCE(SUM(duration), 0) AS total_duration,
                    SUM(CASE WHEN page_type = 'product' THEN 1 ELSE 0 END) AS product_views,
                    SUM(CASE WHEN page_type = 'search' THEN 1 ELSE 0 END) AS searches
             FROM {analytics}
             WHERE created_at BETWEEN %s AND %s" . $this->human_clause() . "",
            $from,
            $to
        );

        $sessions = (int) ($row['sessions'] ?? 0);

        return [
            'views'         => (int) ($row['views'] ?? 0),
            'sessions'      => $sessions,
            'visitors'      => (int) ($row['visitors'] ?? 0),
            'avg_duration'  => $sessions > 0 ? (int) round((int) ($row['total_duration'] ?? 0) / $sessions) : 0,
            'product_views' => (int) ($row['product_views'] ?? 0),
            'searches'      => (int) ($row['searches'] ?? 0),
        ];
    }

    /**
     * Daily series (views + sessions per day) for the trend chart.
     *
     * @return array<int, array<string, mixed>>
     */
    public function daily(string $from, string $to): array
    {
        return $this->db->get_results(
            "SELECT DATE(created_at) AS day,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session) AS sessions
             FROM {analytics}
             WHERE created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $from,
            $to
        );
    }

    /**
     * Most viewed products in a range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function top_products(string $from, string $to, int $limit = 10, int $offset = 0, string $search = '', string $orderby = 'views'): array
    {
        global $wpdb;

        [$s_sql, $s_args] = $this->search_clause($search);

        // Whitelisted sort column — never interpolate raw user input.
        $columns = [
            'views'       => 'views',
            'sessions'    => 'sessions',
            'known_users' => 'known_users',
            'duration'    => 'total_duration',
        ];
        $order = $columns[$orderby] ?? 'views';

        return $this->db->get_results(
            "SELECT a.product_id,
                    COUNT(*) AS views,
                    COUNT(DISTINCT a.session) AS sessions,
                    COUNT(DISTINCT CASE WHEN a.user_id > 0 THEN a.user_id END) AS known_users,
                    COALESCE(SUM(a.duration), 0) AS total_duration
             FROM {analytics} a
             {$s_sql}
             WHERE a.page_type = 'product' AND a.product_id > 0 AND a.created_at BETWEEN %s AND %s" . $this->human_clause('a') . "
             GROUP BY a.product_id
             ORDER BY {$order} DESC
             LIMIT %d OFFSET %d",
            ...array_merge($s_args, [$from, $to, $limit, max(0, $offset)])
        );
    }

    /**
     * How many distinct products match (pagination total).
     */
    public function top_products_count(string $from, string $to, string $search = ''): int
    {
        global $wpdb;

        [$s_sql, $s_args] = $this->search_clause($search);

        return (int) $this->db->get_var(
            "SELECT COUNT(DISTINCT a.product_id)
             FROM {analytics} a
             {$s_sql}
             WHERE a.page_type = 'product' AND a.product_id > 0 AND a.created_at BETWEEN %s AND %s" . $this->human_clause('a') . "",
            ...array_merge($s_args, [$from, $to])
        );
    }

    /**
     * Registered users who viewed one product in the range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function product_viewers(int $product_id, string $from, string $to, int $limit = 8): array
    {
        return $this->db->get_results(
            "SELECT user_id, COUNT(*) AS views, MAX(created_at) AS last_at
             FROM {analytics}
             WHERE page_type = 'product' AND product_id = %d AND user_id > 0
               AND created_at BETWEEN %s AND %s
             GROUP BY user_id
             ORDER BY views DESC
             LIMIT %d",
            $product_id,
            $from,
            $to,
            $limit
        );
    }

    /**
     * Visits by registered (logged-in) users, aggregated per user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function user_visits(string $from, string $to, int $limit = 15): array
    {
        return $this->db->get_results(
            "SELECT user_id,
                    COUNT(*) AS views,
                    COUNT(DISTINCT session) AS sessions,
                    COALESCE(SUM(duration), 0) AS total_duration,
                    SUM(CASE WHEN page_type = 'product' THEN 1 ELSE 0 END) AS product_views,
                    MAX(created_at) AS last_at
             FROM {analytics}
             WHERE user_id > 0 AND created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY user_id
             ORDER BY views DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        );
    }

    /**
     * Device/OS breakdown for one product in the range — what people are
     * browsing it on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function product_platforms(int $product_id, string $from, string $to): array
    {
        if (!$this->has_platform_columns()) {
            return [];
        }

        return $this->db->get_results(
            "SELECT device, device_type, os, COUNT(*) AS views, COUNT(DISTINCT session) AS sessions
             FROM {analytics}
             WHERE page_type = 'product' AND product_id = %d AND created_at BETWEEN %s AND %s
             GROUP BY device, device_type, os
             ORDER BY views DESC",
            $product_id,
            $from,
            $to
        );
    }

    /**
     * One user's recent hits in the range (for the drill-down).
     *
     * @return array<int, array<string, mixed>>
     */
    public function user_hits(int $user_id, string $from, string $to, int $limit = 15): array
    {
        return $this->db->get_results(
            'SELECT page_type, product_id, search_term, duration, created_at
             FROM {analytics}
             WHERE user_id = %d AND created_at BETWEEN %s AND %s
             ORDER BY id DESC
             LIMIT %d',
            $user_id,
            $from,
            $to,
            $limit
        );
    }

    /**
     * Optional title-search JOIN for product queries.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function search_clause(string $search): array
    {
        global $wpdb;

        $search = trim($search);

        if ('' === $search) {
            return ['', []];
        }

        return [
            "INNER JOIN {$wpdb->posts} p ON p.ID = a.product_id AND p.post_title LIKE %s",
            ['%' . addcslashes($search, '%_\\') . '%'],
        ];
    }

    /**
     * On-site search terms.
     *
     * @return array<int, array<string, mixed>>
     */
    public function top_searches(string $from, string $to, int $limit = 12): array
    {
        return $this->db->get_results(
            "SELECT search_term, COUNT(*) AS searches
             FROM {analytics}
             WHERE page_type = 'search' AND search_term <> '' AND created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY search_term
             ORDER BY searches DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        );
    }

    /**
     * Countries (and best-effort cities) by sessions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function top_locations(string $from, string $to, int $limit = 10): array
    {
        return $this->db->get_results(
            "SELECT country, city, COUNT(DISTINCT session) AS sessions, COUNT(*) AS views
             FROM {analytics}
             WHERE country <> '' AND created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY country, city
             ORDER BY sessions DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        );
    }

    /**
     * External referrer hosts by sessions.
     *
     * @return array<int, array<string, mixed>>
     */
    public function top_referrers(string $from, string $to, int $limit = 10): array
    {
        return $this->db->get_results(
            "SELECT referrer, COUNT(DISTINCT session) AS sessions, COUNT(*) AS views
             FROM {analytics}
             WHERE referrer <> '' AND created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY referrer
             ORDER BY sessions DESC
             LIMIT %d",
            $from,
            $to,
            $limit
        );
    }

    /**
     * Device split.
     *
     * @return array<string, int> device => sessions
     */
    public function devices(string $from, string $to): array
    {
        $rows = $this->db->get_results(
            "SELECT device, COUNT(DISTINCT session) AS sessions
             FROM {analytics}
             WHERE created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY device",
            $from,
            $to
        );

        $out = ['mobile' => 0, 'desktop' => 0];

        foreach ($rows as $row) {
            $out[(string) $row['device']] = (int) $row['sessions'];
        }

        return $out;
    }

    /**
     * Page-type split for the range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function page_types(string $from, string $to): array
    {
        return $this->db->get_results(
            "SELECT page_type, COUNT(*) AS views
             FROM {analytics}
             WHERE created_at BETWEEN %s AND %s" . $this->human_clause() . "
             GROUP BY page_type
             ORDER BY views DESC",
            $from,
            $to
        );
    }

    /**
     * Fold completed days of raw rows into the daily summary.
     *
     * Runs BEFORE the prune, so a day is always summarised before its raw
     * rows can be deleted — otherwise retention would quietly erase the
     * history that long-range reports depend on.
     *
     * @return int Days summarised.
     */
    public function rollup_days(int $days_back = 120): int
    {
        global $wpdb;

        $raw   = $wpdb->prefix . 'vkl_analytics';
        $daily = $wpdb->prefix . 'vkl_analytics_daily';

        // Yesterday backwards: today is still accumulating.
        $from = gmdate('Y-m-d', time() - max(2, $days_back) * DAY_IN_SECONDS);
        $to   = gmdate('Y-m-d', time() - DAY_IN_SECONDS);

        $done = (int) $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "INSERT INTO {$daily} (day, visitor_type, views, sessions, visitors, avg_duration)
                 SELECT DATE(created_at), visitor_type,
                        COUNT(*), COUNT(DISTINCT session),
                        COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END),
                        COALESCE(ROUND(AVG(NULLIF(duration, 0))), 0)
                 FROM {$raw}
                 WHERE DATE(created_at) BETWEEN %s AND %s
                 GROUP BY DATE(created_at), visitor_type
                 ON DUPLICATE KEY UPDATE
                    views = VALUES(views),
                    sessions = VALUES(sessions),
                    visitors = VALUES(visitors),
                    avg_duration = VALUES(avg_duration)",
                $from,
                $to
            )
        );

        return $done;
    }

    /**
     * How many raw rows are older than the retention window.
     */
    public function prunable(int $days): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_analytics';

        return (int) $wpdb->get_var( // phpcs:ignore
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at < %s",
                gmdate('Y-m-d H:i:s', time() - max(7, $days) * DAY_IN_SECONDS)
            )
        );
    }

    /**
     * Delete one batch of old rows.
     *
     * Deliberately not a single DELETE: on a shared host a statement that
     * removes hundreds of thousands of rows either times out or saturates
     * the IO allowance, which is the very problem this is meant to fix.
     *
     * @return int Rows removed in this batch.
     */
    public function prune_batch(int $days, int $limit = 2000): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_analytics';

        return (int) $wpdb->query( // phpcs:ignore
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                gmdate('Y-m-d H:i:s', time() - max(7, $days) * DAY_IN_SECONDS),
                max(100, min(5000, $limit))
            )
        );
    }

    /**
     * Reclaim the disk space freed by deleted rows.
     *
     * Deleting rows only marks the space reusable; the file keeps its
     * size until the table is rebuilt. This locks the table while it runs,
     * so it belongs in a quiet hour and behind a deliberate click.
     */
    public function optimize(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vkl_analytics';

        return false !== $wpdb->query("OPTIMIZE TABLE {$table}"); // phpcs:ignore
    }

    /**
     * Size and row count of every table this plugin owns.
     *
     * @return array<int, array{name: string, rows: int, mb: float}>
     */
    public function table_health(): array
    {
        global $wpdb;

        $names = [
            'vkl_analytics',
            'vkl_analytics_tally',
            'vkl_cart_history',
            'vkl_sms_log',
            'vkl_installments',
            'vkl_wallet',
            'vkl_wallet_promos',
        ];

        $out = [];

        foreach ($names as $short) {
            $table = $wpdb->prefix . $short;

            $row = $wpdb->get_row( // phpcs:ignore
                $wpdb->prepare(
                    "SELECT table_rows AS rows_est,
                            ROUND((data_length + index_length) / 1048576, 1) AS mb
                     FROM information_schema.TABLES
                     WHERE table_schema = DATABASE() AND table_name = %s",
                    $table
                ),
                ARRAY_A
            );

            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'name' => $short,
                'rows' => (int) $row['rows_est'],
                'mb'   => (float) $row['mb'],
            ];
        }

        return $out;
    }

    /**
     * Retention purge: rows older than N days (daily housekeeping).
     */
    public function purge(int $days): int
    {
        return (int) $this->db->query(
            'DELETE FROM {analytics} WHERE created_at < %s',
            gmdate('Y-m-d H:i:s', time() - max(7, $days) * DAY_IN_SECONDS)
        );
    }
}
