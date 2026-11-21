<?php
/**
 * Front-end analytics tracker.
 *
 * Records one row per real-visitor pageview on template_redirect:
 * page type, product, on-site search term, referrer host, device and
 * header-derived country/city. A tiny footer beacon reports how long the
 * page stayed open (visibilitychange/pagehide + sendBeacon), which feeds
 * the "time on site" numbers.
 *
 * Privacy: no raw IP address is ever stored — geo comes only from
 * CDN-provided headers (Cloudflare and friends) when present.
 *
 * @package Vereskonto\Modules\Analytics
 * @since   1.33.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Analytics_Tracker
 */
final class VKL_Analytics_Tracker
{
    private const COOKIE = 'vkl_sid';

    private VKL_Config $config;

    private VKL_Analytics_Repository $repository;

    /**
     * Row id of the hit recorded on THIS request (0 = none).
     */
    private int $hit_id = 0;

    /**
     * Session id used on this request.
     */
    private string $session = '';

    /**
     * Constructor.
     */
    public function __construct(VKL_Config $config, VKL_Analytics_Repository $repository)
    {
        $this->config     = $config;
        $this->repository = $repository;
    }

    /**
     * Hooks.
     */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'track'], 1);
        add_action('wp_footer', [$this, 'beacon_script'], 99);
        add_action('wp_ajax_vkl_dwell', [$this, 'handle_dwell']);
        add_action('wp_ajax_nopriv_vkl_dwell', [$this, 'handle_dwell']);
        add_action('vkl_installment_check', [$this, 'housekeeping']);

        // Second path, on WooCommerce's Action Scheduler. WP-Cron is
        // switched off on this site, so the hook above only fires if the
        // server cron job is actually working — and when it is not, the
        // table grows unchecked, which is what caused the IO problem in
        // the first place. Action Scheduler runs independently.
        add_action('vkl_analytics_maintenance', [$this, 'housekeeping']);
        add_action('init', [$this, 'ensure_maintenance_scheduled'], 20);
    }

    /**
     * Daily retention purge.
     */
    /**
     * Keep a daily maintenance action queued, when Action Scheduler is
     * available. Cheap enough to check on init: one function call.
     */
    public function ensure_maintenance_scheduled(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        // as_has_scheduled_action() is a query on the (often large) Action
        // Scheduler tables. Asking it on EVERY page view is pure cost: the
        // answer only needs to be right now and then, so check from the
        // admin, from cron, and on about one front-end request in 200.
        if (!is_admin() && !wp_doing_cron() && 1 !== wp_rand(1, 200)) {
            return;
        }

        if (as_has_scheduled_action('vkl_analytics_maintenance')) {
            return;
        }

        as_schedule_recurring_action(
            time() + HOUR_IN_SECONDS,
            DAY_IN_SECONDS,
            'vkl_analytics_maintenance',
            [],
            'vereskonto'
        );
    }

    public function housekeeping(): void
    {
        // Two schedulers now point here, so make the day's run happen once.
        if (get_transient('vkl_analytics_housekeeping') ) {
            return;
        }

        set_transient('vkl_analytics_housekeeping', 1, 12 * HOUR_IN_SECONDS);

        $days = $this->config->get_int('analytics_retention_days', 90);

        // Summarise first, delete second. The other order would drop raw
        // rows before their day had been folded into the summary, and the
        // long-range reports would lose that history for good.
        $this->repository->rollup_days();

        // Batched, with the same pacing as the manual tool: one huge
        // DELETE on a shared host either times out or eats the whole IO
        // allowance, which is what this work exists to avoid.
        $budget = 20;

        while ($budget-- > 0) {
            if ($this->repository->prune_batch($days, 2000) < 1) {
                break;
            }

            usleep(200000);
        }
    }

    /**
     * Record the pageview.
     */
    public function track(): void
    {
        if (!$this->config->get_bool('analytics_enabled', true)) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        // Requests that are not a person looking at a page. Each of these
        // used to cost a row: feeds and robots.txt are polled constantly,
        // 404s are mostly probes, and the site's own loopback calls would
        // otherwise count themselves.
        if (is_feed() || is_robots() || is_trackback() || is_404()) {
            return;
        }

        if (defined('DOING_AUTOSAVE') || defined('WP_INSTALLING') || defined('XMLRPC_REQUEST')) {
            return;
        }

        if ($this->is_loopback()) {
            return;
        }

        // Shop staff browsing their own site would only pollute the data.
        // Not just the site owner: a shop manager or editor visits far
        // more often than a customer, and on a small shop a handful of
        // staff can noticeably skew the figures.
        if ($this->is_staff()) {
            return;
        }

        // Browsers and speed plugins fetch pages the visitor never opened.
        // Counting those invents visits that nobody made — and they always
        // record a zero duration, which drags the averages down too.
        if ($this->is_prefetch()) {
            return;
        }

        $visitor = $this->classify_visitor();

        // Bots get counted, not stored. The visitor_type filter added in
        // 1.78.0 only excluded them at READ time, so the write cost was
        // paid on every crawl and then thrown away — with about two thirds
        // of traffic automated, that was the bulk of the database writes.
        if ('human' !== $visitor && !$this->record_bots()) {
            $this->repository->tally_bot($visitor);

            return;
        }

        $this->session = $this->session_id();

        [$type, $product_id, $term] = $this->classify();

        $this->hit_id = $this->repository->record([
            'session'      => $this->session,
            'user_id'      => get_current_user_id(),
            'page_type'    => $type,
            'product_id'   => $product_id,
            'search_term'  => $term,
            'referrer'     => $this->referrer_host(),
            'device'       => wp_is_mobile() ? 'mobile' : 'desktop',
            'visitor_type' => $visitor,
            'os'           => $this->detect_os(),
            'device_type'  => $this->detect_device_type(),
            'country'     => $this->header_country(),
            'city'        => $this->header_city(),
        ]);
    }

    /**
     * Footer beacon: report dwell seconds when the page is left.
     */
    public function beacon_script(): void
    {
        if ($this->hit_id <= 0 || !$this->config->get_bool('analytics_dwell', false)) {
            return;
        }
        ?>
        <script>
        (function () {
            var start = Date.now(), sent = false;
            function report() {
                if (sent) { return; }
                sent = true;
                var secs = Math.round((Date.now() - start) / 1000);
                if (secs < 1) { return; }
                var data = new FormData();
                data.append('action', 'vkl_dwell');
                data.append('id', '<?php echo (int) $this->hit_id; ?>');
                data.append('s', '<?php echo esc_js($this->session); ?>');
                data.append('t', String(secs));
                if (navigator.sendBeacon) {
                    navigator.sendBeacon('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', data);
                }
            }
            document.addEventListener('visibilitychange', function () {
                if ('hidden' === document.visibilityState) { report(); }
            });
            window.addEventListener('pagehide', report);
        })();
        </script>
        <?php
    }

    /**
     * AJAX endpoint for the beacon.
     */
    public function handle_dwell(): void
    {
        // No nonce on purpose: sendBeacon fires while the page unloads and
        // the write is guarded by (row id + session) pairing plus hard
        // caps, so the worst a forged call can do is nothing.
        $id      = isset($_POST['id']) ? absint($_POST['id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $session = isset($_POST['s']) ? sanitize_text_field(wp_unslash($_POST['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $secs    = isset($_POST['t']) ? absint($_POST['t']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($id > 0 && '' !== $session && $secs > 0) {
            $this->repository->add_duration($id, $session, $secs);
        }

        wp_die('', '', ['response' => 204]);
    }

    /* -------------------------------------------------------------- *
     *  Classification & context
     * -------------------------------------------------------------- */

    /**
     * Page type, product id and search term for the current request.
     *
     * @return array{0: string, 1: int, 2: string}
     */
    private function classify(): array
    {
        if (is_search()) {
            return ['search', 0, mb_substr(trim(get_search_query(false)), 0, 190)];
        }

        if (function_exists('is_product') && is_product()) {
            return ['product', (int) get_queried_object_id(), ''];
        }

        if (function_exists('is_product_category') && (is_product_category() || is_product_tag())) {
            return ['category', 0, ''];
        }

        if (function_exists('is_cart') && is_cart()) {
            return ['cart', 0, ''];
        }

        if (function_exists('is_checkout') && is_checkout()) {
            return ['checkout', 0, ''];
        }

        if (is_front_page() || is_home()) {
            return ['home', 0, ''];
        }

        return ['other', 0, ''];
    }

    /**
     * Stable per-visitor session id (30-minute rolling cookie).
     */
    private function session_id(): string
    {
        $sid = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : '';

        if (1 !== preg_match('/^[a-f0-9]{32}$/', $sid)) {
            $sid = md5(uniqid('vkla', true) . wp_rand());
        }

        if (!headers_sent()) {
            setcookie(self::COOKIE, $sid, [
                'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        return $sid;
    }

    /**
     * External referrer host ('' for same-site or none).
     */
    private function referrer_host(): string
    {
        $ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER'])) : '';

        if ('' === $ref) {
            return '';
        }

        $host = strtolower((string) wp_parse_url($ref, PHP_URL_HOST));
        $own  = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        if ('' === $host || $host === $own) {
            return '';
        }

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * Country from CDN headers (Cloudflare / common proxies), '' if none.
     */
    private function header_country(): string
    {
        foreach (['HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE'] as $key) {
            if (!empty($_SERVER[$key])) {
                $code = strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER[$key])));

                if (1 === preg_match('/^[A-Z]{2}$/', $code) && 'XX' !== $code) {
                    return $code;
                }
            }
        }

        return '';
    }

    /**
     * City from CDN headers where available (best effort).
     */
    private function header_city(): string
    {
        foreach (['HTTP_CF_IPCITY', 'HTTP_X_CITY', 'GEOIP_CITY'] as $key) {
            if (!empty($_SERVER[$key])) {
                return mb_substr(sanitize_text_field(rawurldecode(wp_unslash((string) $_SERVER[$key]))), 0, 100);
            }
        }

        return '';
    }

    /**
     * Operating system from the UA string (best-effort, no library).
     */
    private function detect_os(): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        $map = [
            'Windows'      => '/windows nt/i',
            'Android'      => '/android/i',
            'iOS'          => '/iphone|ipad|ipod/i',
            'macOS'        => '/macintosh|mac os x/i',
            'Linux'        => '/linux/i',
            'Chrome OS'    => '/cros/i',
        ];

        foreach ($map as $name => $re) {
            if (1 === preg_match($re, $ua)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Coarse device type: phone, tablet or desktop.
     */
    private function detect_device_type(): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        if (1 === preg_match('/ipad|tablet|playbook|silk|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }

        if (wp_is_mobile()) {
            return 'phone';
        }

        return 'desktop';
    }

    /**
     * Crawler heuristics (same signature list as the cart gate).
     */
    /**
     * Classify the visitor into one of four buckets:
     *   human          - a real person's browser
     *   search_engine  - Google/Bing/etc. indexing crawlers
     *   ai_bot         - LLM/AI crawlers (GPTBot, ClaudeBot, PerplexityBot)
     *   scraper        - generic automation and content scrapers
     * Specific patterns are checked before the generic ones so a known
     * crawler is never mislabelled a scraper.
     */
    /**
     * A request the site made to itself (cron ping, health check).
     *
     * These arrive from the server's own address with WordPress's own
     * user agent, and counting them inflates the figures while costing a
     * write on every tick.
     */
    private function is_loopback(): bool
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        if ('' !== $ua && false !== stripos($ua, 'WordPress/')) {
            return true;
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $server = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';

        if ('' !== $remote && ($remote === $server || '127.0.0.1' === $remote || '::1' === $remote)) {
            return true;
        }

        return isset($_GET['doing_wp_cron']);
    }

    /**
     * Should raw rows still be written for bots?
     *
     * Off by default. The setting and the filter exist so the previous
     * behaviour can be restored without editing code.
     */
    private function record_bots(): bool
    {
        return (bool) apply_filters(
            'vkl_analytics_record_bots',
            $this->config->get_bool('analytics_record_bots', false)
        );
    }

    /**
     * Anyone who works on the shop, not only the administrator.
     */
    private function is_staff(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        $caps = apply_filters('vkl_analytics_staff_caps', [
            'manage_options',      // administrator
            'manage_woocommerce',  // shop manager
            'edit_posts',          // editor / author / contributor
        ]);

        foreach ($caps as $cap) {
            if (current_user_can((string) $cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this a speculative fetch rather than a real page view?
     *
     * Chrome sends Sec-Purpose: prefetch (Speculation Rules), Firefox
     * sends X-Moz: prefetch, and several speed plugins set Purpose.
     */
    private function is_prefetch(): bool
    {
        $headers = [
            'HTTP_SEC_PURPOSE' => ['prefetch', 'prerender'],
            'HTTP_PURPOSE'     => ['prefetch', 'preview'],
            'HTTP_X_MOZ'       => ['prefetch', 'prerender'],
            'HTTP_X_PURPOSE'   => ['preview', 'instant'],
        ];

        foreach ($headers as $key => $needles) {
            if (!isset($_SERVER[$key])) {
                continue;
            }

            $value = strtolower((string) $_SERVER[$key]);

            foreach ($needles as $needle) {
                if (false !== strpos($value, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function classify_visitor(): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : '';

        if ('' === trim($ua)) {
            return 'scraper';
        }

        $ai = ['gptbot', 'oai-searchbot', 'chatgpt', 'claudebot', 'claude-web', 'anthropic',
               'perplexitybot', 'perplexity', 'google-extended', 'ccbot', 'bytespider',
               'amazonbot', 'youbot', 'cohere', 'diffbot', 'meta-externalagent', 'applebot-extended'];

        foreach ($ai as $needle) {
            if (false !== strpos($ua, $needle)) {
                return 'ai_bot';
            }
        }

        $search = ['googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
                   'yandexbot', 'yandex', 'sogou', 'exabot', 'applebot', 'seznambot', 'petalbot'];

        foreach ($search as $needle) {
            if (false !== strpos($ua, $needle)) {
                return 'search_engine';
            }
        }

        $scraper = ['bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python', 'java/',
                    'httpclient', 'headless', 'phantom', 'lighthouse', 'pingdom', 'gtmetrix',
                    'uptime', 'monitor', 'scan', 'preview', 'facebookexternalhit', 'whatsapp',
                    'telegrambot', 'ahrefs', 'semrush', 'mj12', 'dotbot', 'scrapy', 'go-http'];

        foreach ($scraper as $needle) {
            if (false !== strpos($ua, $needle)) {
                return 'scraper';
            }
        }

        return 'human';
    }

}
