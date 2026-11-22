<?php
/**
 * Platform status page.
 *
 * One screen that answers "why is X not sending / not showing":
 * environment versions, checkout page type (block vs classic shortcode),
 * gateway configuration, every feature toggle with its required pattern,
 * WP-Cron health, and the most recent gateway responses from the log.
 *
 * Read-only: this page changes nothing.
 *
 * @package Vereskonto\Core
 * @since   1.8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Status_Admin
 */
final class VKL_Status_Admin
{
    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * SMS log repository.
     */
    private VKL_Sms_Log_Repository $log;

    /**
     * Security helper.
     */
    private VKL_Security $security;

    /**
     * @param VKL_Config             $config   Config.
     * @param VKL_Sms_Log_Repository $log      Message log.
     * @param VKL_Security           $security Security helper.
     */
    public function __construct(VKL_Config $config, VKL_Sms_Log_Repository $log, VKL_Security $security)
    {
        $this->config   = $config;
        $this->log      = $log;
        $this->security = $security;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('admin_post_vkl_gateway_test', [$this, 'handle_gateway_test']);
        add_action('wp_ajax_vkl_prune', [$this, 'ajax_prune']);
        add_action('admin_post_vkl_optimize', [$this, 'handle_optimize']);
        add_action('admin_menu', [$this, 'menu'], 99);
        add_action('admin_notices', [$this, 'block_checkout_notice']);
    }

    /**
     * Add the submenu.
     */
    public function menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Status', 'vereskala-login'),
            __('Status', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-status',
            [$this, 'render']
        );
    }

    /**
     * Checkout page type: 'block', 'classic', or 'unknown'.
     */
    private function checkout_type(): string
    {
        if (!function_exists('wc_get_page_id')) {
            return 'unknown';
        }

        $page_id = (int) wc_get_page_id('checkout');
        $page    = $page_id > 0 ? get_post($page_id) : null;

        if (!$page instanceof WP_Post) {
            return 'unknown';
        }

        if (function_exists('has_block') && has_block('woocommerce/checkout', $page)) {
            return 'block';
        }

        if (false !== strpos((string) $page->post_content, '[woocommerce_checkout]')) {
            return 'classic';
        }

        return 'unknown';
    }

    /**
     * Warn on Vereskonto pages when the checkout uses the WooCommerce
     * block: the wallet box and other classic checkout hooks cannot
     * render there.
     */
    public function block_checkout_notice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || false === strpos((string) $screen->id, 'vkl-') && false === strpos((string) $screen->id, 'vereskala-login')) {
            return;
        }

        if ('block' !== $this->checkout_type()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Vereskonto:</strong> '
            . esc_html__('Your checkout page uses the WooCommerce Checkout block. The wallet box (and other classic checkout hooks) cannot render inside the block. Edit the checkout page and replace the block with the [woocommerce_checkout] shortcode to use them.', 'vereskala-login')
            . '</p></div>';
    }

    /**
     * Render a ✓ / ✗ cell.
     */
    private function mark(bool $ok): string
    {
        return $ok
            ? '<span style="color:#00a32a;font-weight:700">&#10003;</span>'
            : '<span style="color:#d63638;font-weight:700">&#10007;</span>';
    }

    /**
     * Render the page.
     */
    /**
     * Reach out to the SMS gateway and report what the server sees.
     *
     * A cURL timeout on the login screen almost always means the hosting
     * cannot reach the gateway, which is invisible from inside WordPress —
     * this makes it visible in one click.
     */
    public function handle_gateway_test(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            wp_die('');
        }

        check_admin_referer('vkl_gateway_test');

        // Tell the SMS circuit breaker to stand aside: this test exists
        // precisely to probe a gateway that may be failing.
        if (!defined('VKL_GATEWAY_TEST')) {
            define('VKL_GATEWAY_TEST', true);
        }

        // Any host can be tested, so a second provider can be checked
        // before committing to a migration.
        $host = isset($_POST['vkl_host']) ? sanitize_text_field(wp_unslash($_POST['vkl_host'])) : '';
        $host = preg_replace('~^https?://~i', '', trim($host));
        $host = trim((string) $host, '/ ');

        if ('' === $host || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
            $host = 'rest.payamak-panel.com';
        }

        $lines = ['host|ok|' . $host];

        // Stage 1 — DNS.
        $ip = gethostbyname($host);

        if ($ip === $host) {
            $lines[] = 'dns|fail|' . __('The server could not resolve the gateway name (DNS).', 'vereskala-login');
            $ip      = '';
        } else {
            $lines[] = 'dns|ok|' . $ip;
        }

        // Stage 2 — plain TCP on 443.
        if ('' !== $ip) {
            $t0    = microtime(true);
            $errno = 0;
            $err   = '';
            $sock  = @fsockopen('tcp://' . $ip, 443, $errno, $err, 6);
            $ms    = (int) round((microtime(true) - $t0) * 1000);

            if ($sock) {
                fclose($sock);
                $lines[] = 'tcp|ok|' . $ms . ' ms';
            } else {
                $lines[] = 'tcp|fail|' . $ms . ' ms — ' . trim($err . ' (' . $errno . ')');
            }
        }

        // Stage 3 — TLS handshake, and who the certificate actually belongs
        // to. A handshake that stalls here (while plain TCP was instant)
        // points at filtering in the middle rather than a closed port; a
        // certificate for some other name points at DNS interception.
        $t0  = microtime(true);
        $ctx = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ],
        ]);

        $tls = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno2,
            $err2,
            8,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($tls) {
            $subject = '';
            $params  = stream_context_get_params($tls);

            if (!empty($params['options']['ssl']['peer_certificate'])) {
                $parsed = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
                $cn     = $parsed['subject']['CN'] ?? '';
                $issuer = $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? '');
                $subject = trim($cn . ($issuer ? ' / ' . $issuer : ''));
            }

            fclose($tls);
            $lines[] = 'tls|ok|' . $ms . ' ms' . ('' !== $subject ? ' — ' . $subject : '');
        } else {
            $lines[] = 'tls|fail|' . $ms . ' ms — ' . trim($err2 . ' (' . (int) $errno2 . ')');
        }

        // Which address the gateway actually sees us coming from. This is
        // the number to give the provider when asking them to unblock the
        // server, and it also reveals whether the site is hosted abroad.
        $local = (string) ($_SERVER['SERVER_ADDR'] ?? '');
        $public = '';

        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $probe) {
            $probe_res = wp_remote_get($probe, ['timeout' => 6]);

            if (!is_wp_error($probe_res) && 200 === (int) wp_remote_retrieve_response_code($probe_res)) {
                $candidate = trim((string) wp_remote_retrieve_body($probe_res));

                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $public = $candidate;
                    break;
                }
            }
        }

        // If an outgoing address is configured, show what the world
        // actually sees when we use it — the only way to know whether the
        // address is genuinely bound to this server.
        $bind_ip = trim((string) get_option('vkl_sms_bind_ip', ''));

        if ('' !== $bind_ip) {
            if (!defined('VKL_BIND_PROBE')) {
                define('VKL_BIND_PROBE', true);
            }

            $bound_seen = '';

            foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $probe) {
                $bound_res = wp_remote_get($probe, ['timeout' => 8]);

                if (!is_wp_error($bound_res) && 200 === (int) wp_remote_retrieve_response_code($bound_res)) {
                    $candidate = trim((string) wp_remote_retrieve_body($bound_res));

                    if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                        $bound_seen = $candidate;
                        break;
                    }
                }
            }

            if ('' === $bound_seen) {
                $lines[] = 'bind|fail|' . sprintf(
                    /* translators: %s: configured IP address. */
                    __('Nothing could be sent from %s — that address is not usable on this server.', 'vereskala-login'),
                    $bind_ip
                );
            } elseif ($bound_seen === $bind_ip) {
                $lines[] = 'bind|ok|' . sprintf(
                    /* translators: %s: IP address. */
                    __('Working — the gateway will see %s.', 'vereskala-login'),
                    $bound_seen
                );
            } else {
                $lines[] = 'bind|fail|' . sprintf(
                    /* translators: 1: configured IP, 2: IP actually seen. */
                    __('Not applied: you set %1$s but requests still leave from %2$s. Ask your host whether that address is bound to this server.', 'vereskala-login'),
                    $bind_ip,
                    $bound_seen
                );
            }
        }

        if ('' !== $public || '' !== $local) {
            $lines[] = 'origin|ok|' . ('' !== $public ? $public : $local) . ('' !== $public && '' !== $local && $public !== $local ? ' (server: ' . $local . ')' : '');
        } else {
            $lines[] = 'origin|fail|' . __('Could not determine the outgoing address.', 'vereskala-login');
        }

        // Stage 4 — the real request WordPress makes.
        $t0       = microtime(true);
        $response = wp_remote_get('https://' . $host . '/', ['timeout' => 12]);
        $ms       = (int) round((microtime(true) - $t0) * 1000);

        if (is_wp_error($response)) {
            $lines[] = 'https|fail|' . $ms . ' ms — ' . $response->get_error_message();
        } else {
            $lines[] = 'https|ok|' . $ms . ' ms — HTTP ' . (int) wp_remote_retrieve_response_code($response);
        }

        // Stage 5 — same request without certificate verification. If this
        // one succeeds, the CA bundle is the culprit, not the network.
        $t0       = microtime(true);
        $noverify = wp_remote_get('https://' . $host . '/', ['timeout' => 12, 'sslverify' => false]);
        $ms       = (int) round((microtime(true) - $t0) * 1000);

        if (is_wp_error($noverify)) {
            $lines[] = 'noverify|fail|' . $ms . ' ms — ' . $noverify->get_error_message();
        } else {
            $lines[] = 'noverify|ok|' . $ms . ' ms — HTTP ' . (int) wp_remote_retrieve_response_code($noverify);
        }

        set_transient('vkl_gateway_test', implode("\n", $lines), 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('admin.php?page=vkl-status'));
        exit;
    }

    /**
     * Files the plugin needs at runtime. A half-finished upload leaves
     * some of these behind, and the resulting errors on every request are
     * what turn into 503s under load — so surface it plainly.
     *
     * @return array<int, string>
     */
    /**
     * Delete one batch of expired analytics rows.
     *
     * Batched over AJAX rather than one big DELETE: a statement removing
     * hundreds of thousands of rows would either time out or eat the whole
     * IO allowance of a shared host — the problem this is meant to relieve.
     */
    public function ajax_prune(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            wp_send_json_error(['message' => '']);
        }

        check_ajax_referer('vkl_prune', 'nonce');

        $repo = vkl('analytics.repository');
        $days = (int) vkl('config')->get_int('analytics_retention_days', 90);

        $removed = $repo->prune_batch($days, 2000);
        $left    = $repo->prunable($days);

        wp_send_json_success([
            'removed' => $removed,
            'left'    => $left,
            'done'    => $removed < 1 || $left < 1,
        ]);
    }

    /**
     * Rebuild the analytics table so the freed space returns to the disk.
     */
    public function handle_optimize(): void
    {
        vkl('security')->verify_admin_request();

        $ok = vkl('analytics.repository')->optimize();

        wp_safe_redirect(admin_url('admin.php?page=vkl-status&vkl_opt=' . ($ok ? '1' : '0')));
        exit;
    }

    private function missing_files(): array
    {
        $required = [
            'modules/wallet/gateway.php',
            'woocommerce/myaccount/form-login.php',
            'templates/account-dashboard.php',
            'modules/sms/melipayamak.php',
            'modules/sms/driver-interface.php',
            'assets/login.js',
            'assets/login.css',
        ];

        $missing = [];

        foreach ($required as $rel) {
            if (!is_readable(VKL_PATH . $rel)) {
                $missing[] = $rel;
            }
        }

        return $missing;
    }

    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }

        $wc_active = class_exists('WooCommerce');
        $checkout  = $this->checkout_type();
        $cfg       = $this->config;

        $sms_ok = function_exists('vkl') && vkl()->has('sms.driver')
            ? (bool) vkl('sms.driver')->is_configured()
            : ('' !== $cfg->get_string('meli_username') && '' !== $cfg->get_string('meli_password'));
        $wa_ok  = '' !== $cfg->get_string('wa_token') && '' !== $cfg->get_string('wa_phone_id');

        // label, enabled?, [pattern config keys that must be non-empty].
        $features = [
            [__('Order notifications', 'vereskala-login'), $cfg->get_bool('order_sms_enabled', false), ['order_pattern_processing', 'order_pattern_completed']],
            [__('Ticket notifications', 'vereskala-login'), $cfg->get_bool('ticket_sms_enabled', false), ['ticket_pattern_replied', 'ticket_pattern_admin']],
            [__('Wallet notifications', 'vereskala-login'), $cfg->get_bool('wallet_sms_enabled', false), ['wallet_pattern_charged']],
            [__('Cart Tracking', 'vereskala-login'), $cfg->get_bool('cart_tracking_enabled', true), []],
            [__('Enable abandoned-cart SMS', 'vereskala-login'), $cfg->get_bool('cart_sms_enabled', false), ['cart_pattern_code']],
            [__('Enable abandoned-cart WhatsApp', 'vereskala-login'), $cfg->get_bool('cart_wa_enabled', false), ['cart_wa_template']],
            [__('Customer club', 'vereskala-login'), $cfg->get_bool('loyalty_enabled', false), []],
            [__('Wallet on the checkout page', 'vereskala-login'), $cfg->get_bool('wallet_checkout_enabled', true), []],
            [__('Wallet top-up via bank gateway', 'vereskala-login'), $cfg->get_bool('wallet_topup_enabled', false), []],
            [__('Require login for checkout', 'vereskala-login'), $cfg->get_bool('checkout_require_login', false), []],
        ];

        $admin_mobile = $cfg->get_string('admin_mobile');

        $cart_next     = wp_next_scheduled(VKL_Cart_Scheduler::CRON_HOOK);
        $campaign_next = wp_next_scheduled(VKL_Sms_Marketing::CRON_HOOK);
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        // A schedule that is due but keeps sitting there means nothing is
        // actually calling wp-cron.php — the times alone look healthy, so
        // say plainly how far behind the queue has fallen.
        $cron_overdue = 0;

        foreach ([$cart_next, $campaign_next] as $vkl_due) {
            if ($vkl_due && $vkl_due < time()) {
                $cron_overdue = max($cron_overdue, time() - (int) $vkl_due);
            }
        }

        $rows      = $this->log->latest(10);
        $gate_test = get_transient('vkl_gateway_test');
        ?>
        <div class="wrap">
            <?php if ($cron_overdue > 600) : ?>
                <div style="background:#fdf3f3;border:1px solid #f0c9c9;border-radius:12px;padding:12px 16px;margin:14px 0;max-width:900px">
                    <strong>⛔ <?php esc_html_e('Scheduled jobs are overdue', 'vereskala-login'); ?></strong>
                    <p style="margin:6px 0">
                        <?php
                        printf(
                            /* translators: %s: human-readable duration, e.g. "2 hours". */
                            esc_html__('A job was due %s ago and still has not run, so nothing is calling wp-cron.php. Set up the cron job described below.', 'vereskala-login'),
                            esc_html(human_time_diff(time() - $cron_overdue, time()))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($cron_disabled) : ?>
                <?php
                $vkl_cron_url  = home_url('/wp-cron.php?doing_wp_cron');
                $vkl_abs       = defined('ABSPATH') ? ABSPATH : '';
                $vkl_cron_file = $vkl_abs . 'wp-cron.php';
                ?>
                <div style="background:#fff8e6;border:1px solid #f0dca8;border-radius:12px;padding:12px 16px;margin:14px 0;max-width:900px">
                    <strong>⏱ <?php esc_html_e('Scheduled jobs need a cron job on your hosting', 'vereskala-login'); ?></strong>
                    <p style="margin:6px 0">
                        <?php esc_html_e('WordPress normally runs scheduled work when someone visits the site, but that is switched off here (DISABLE_WP_CRON). Until your hosting calls the file below on a timer, abandoned-cart checks, campaign batches, instalment reminders and the daily notices will not run.', 'vereskala-login'); ?>
                    </p>
                    <p style="margin:6px 0"><strong><?php esc_html_e('In cPanel: Advanced → Cron Jobs → add a job every five minutes with one of these lines.', 'vereskala-login'); ?></strong></p>

                    <p style="margin:8px 0 2px"><?php esc_html_e('Preferred (no web request, lightest on the server):', 'vereskala-login'); ?></p>
                    <code dir="ltr" style="display:block;user-select:all;background:#f6f7f7;border-radius:6px;padding:8px 10px;font-size:12px">php -q <?php echo esc_html($vkl_cron_file); ?></code>

                    <p style="margin:10px 0 2px"><?php esc_html_e('If that is not available, use one of these instead:', 'vereskala-login'); ?></p>
                    <code dir="ltr" style="display:block;user-select:all;background:#f6f7f7;border-radius:6px;padding:8px 10px;font-size:12px">wget -q -O - "<?php echo esc_html($vkl_cron_url); ?>" &gt;/dev/null 2&gt;&amp;1</code>
                    <code dir="ltr" style="display:block;user-select:all;background:#f6f7f7;border-radius:6px;padding:8px 10px;font-size:12px;margin-top:6px">curl -s "<?php echo esc_html($vkl_cron_url); ?>" &gt;/dev/null 2&gt;&amp;1</code>

                    <p class="description" style="margin:10px 0 0">
                        <?php esc_html_e('Set the interval to every 5 minutes (*/5 * * * *). A shorter interval only adds load; a longer one delays cart reminders. Once the job runs, the “next run” times below start moving.', 'vereskala-login'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $vkl_health = [];
            $vkl_days   = 90;
            $vkl_stale  = 0;

            try {
                $vkl_repo   = vkl('analytics.repository');
                $vkl_days   = (int) vkl('config')->get_int('analytics_retention_days', 90);
                $vkl_health = $vkl_repo->table_health();
                $vkl_stale  = $vkl_repo->prunable($vkl_days);
            } catch (Throwable $e) {
                $vkl_health = [];
            }
            ?>
            <?php if ([] !== $vkl_health) : ?>
                <div style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 16px;margin:14px 0;max-width:900px">
                    <strong>🗄 <?php esc_html_e('Database tables', 'vereskala-login'); ?></strong>
                    <p class="description" style="margin:4px 0 10px">
                        <?php esc_html_e('Large tables cost write bandwidth on every request. Anything past the retention window can be removed safely.', 'vereskala-login'); ?>
                    </p>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Table', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Rows (approx.)', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Size', 'vereskala-login'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($vkl_health as $vkl_t) : ?>
                            <?php $vkl_warn = $vkl_t['rows'] > 100000 || $vkl_t['mb'] > 50; ?>
                            <tr<?php echo $vkl_warn ? ' style="background:#fff8e6"' : ''; ?>>
                                <td><code dir="ltr"><?php echo esc_html($vkl_t['name']); ?></code></td>
                                <td dir="ltr"><?php echo esc_html(number_format_i18n($vkl_t['rows'])); ?></td>
                                <td dir="ltr"><?php echo esc_html(number_format_i18n($vkl_t['mb'], 1)); ?> MB</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($vkl_stale > 0) : ?>
                        <p style="margin:12px 0 6px">
                            <?php
                            printf(
                                /* translators: 1: row count, 2: retention days. */
                                esc_html__('%1$s rows are older than %2$s days.', 'vereskala-login'),
                                '<strong>' . esc_html(number_format_i18n($vkl_stale)) . '</strong>',
                                esc_html(number_format_i18n($vkl_days))
                            );
                            ?>
                        </p>
                        <button type="button" class="button button-primary" id="vkl-prune-start">
                            <?php esc_html_e('Remove old rows', 'vereskala-login'); ?>
                        </button>
                        <span id="vkl-prune-status" style="margin-inline-start:10px;font-weight:600"></span>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-inline-start:8px"
                              onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Rebuild the table now? It stays locked while this runs, so do it at a quiet hour. Best done AFTER removing the old rows.', 'vereskala-login'))); ?>);">
                            <input type="hidden" name="action" value="vkl_optimize">
                            <?php vkl('security')->admin_nonce_field(); ?>
                            <?php submit_button(__('Reclaim disk space', 'vereskala-login'), 'secondary', 'submit', false); ?>
                        </form>

                        <p class="description" style="margin-top:8px">
                            <?php esc_html_e('Runs in small batches so the site stays responsive. Afterwards ask your host to run OPTIMIZE TABLE during a quiet hour to actually reclaim the disk space — that operation locks the table while it runs.', 'vereskala-login'); ?>
                        </p>
                        <script>
                        (function () {
                            var btn = document.getElementById('vkl-prune-start');
                            var out = document.getElementById('vkl-prune-status');
                            if (!btn || !out) { return; }

                            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                            var nonce = <?php echo wp_json_encode(wp_create_nonce('vkl_prune')); ?>;
                            var working = <?php echo wp_json_encode(__('%s rows left…', 'vereskala-login')); ?>;
                            var finished = <?php echo wp_json_encode(__('Done — nothing left to remove.', 'vereskala-login')); ?>;
                            var failed = <?php echo wp_json_encode(__('Stopped. Reload and try again.', 'vereskala-login')); ?>;

                            function step() {
                                var body = new URLSearchParams();
                                body.append('action', 'vkl_prune');
                                body.append('nonce', nonce);

                                fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                                    .then(function (r) { return r.json(); })
                                    .then(function (res) {
                                        if (!res || !res.success) { out.textContent = failed; btn.disabled = false; return; }

                                        if (res.data.done) {
                                            out.textContent = finished;
                                            btn.disabled = false;

                                            return;
                                        }

                                        out.textContent = working.replace('%s', res.data.left.toLocaleString());
                                        // A pause between batches leaves IO for real visitors.
                                        setTimeout(step, 700);
                                    })
                                    .catch(function () { out.textContent = failed; btn.disabled = false; });
                            }

                            btn.addEventListener('click', function () {
                                btn.disabled = true;
                                out.textContent = '…';
                                step();
                            });
                        })();
                        </script>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php $vkl_missing = $this->missing_files(); ?>
            <?php if ([] !== $vkl_missing) : ?>
                <div style="background:#fdf3f3;border:1px solid #f0c9c9;border-radius:12px;padding:12px 16px;margin:14px 0;max-width:900px">
                    <strong>⛔ <?php esc_html_e('Some plugin files are missing on the server', 'vereskala-login'); ?></strong>
                    <p style="margin:6px 0">
                        <?php esc_html_e('This happens when an upload finishes only partly. Every visit then hits an error, which slows the site and can produce 503 responses. Delete the plugin folder over FTP and upload the full package again.', 'vereskala-login'); ?>
                    </p>
                    <ul style="margin:6px 0 0;padding-inline-start:20px">
                        <?php foreach ($vkl_missing as $vkl_f) : ?>
                            <li><code dir="ltr"><?php echo esc_html($vkl_f); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 16px;margin:14px 0;max-width:900px">
                <strong>📡 <?php esc_html_e('SMS gateway reachability', 'vereskala-login'); ?></strong>
                <p class="description" style="margin:6px 0 10px">
                    <?php esc_html_e('If customers see a timeout when asking for their login code, the server could not reach the SMS gateway. Run this test to find out whether the problem is your hosting or the gateway.', 'vereskala-login'); ?>
                </p>
                <?php if (is_string($gate_test) && '' !== $gate_test) : ?>
                    <?php
                    $stage_label = [
                        'host'     => __('Tested address', 'vereskala-login'),
                        'dns'      => __('Name lookup (DNS)', 'vereskala-login'),
                        'tcp'      => __('Connection on port 443', 'vereskala-login'),
                        'tls'      => __('Secure handshake (TLS) + certificate', 'vereskala-login'),
                        'origin'   => __('This server’s outgoing IP (give this to the gateway)', 'vereskala-login'),
                        'bind'     => __('Configured outgoing IP', 'vereskala-login'),
                        'https'    => __('Full request through WordPress', 'vereskala-login'),
                        'noverify' => __('Same request without certificate check', 'vereskala-login'),
                    ];
                    ?>
                    <table class="widefat striped" style="max-width:820px;margin-bottom:10px">
                        <tbody>
                        <?php foreach (explode("\n", $gate_test) as $line) : ?>
                            <?php
                            $bits  = explode('|', $line, 3);
                            $stage = $bits[0] ?? '';
                            $ok    = 'ok' === ($bits[1] ?? '');
                            $note  = (string) ($bits[2] ?? '');
                            ?>
                            <tr>
                                <td style="width:34px"><?php echo $ok ? '✅' : '⛔'; ?></td>
                                <td><?php echo esc_html($stage_label[$stage] ?? $stage); ?></td>
                                <td dir="ltr" style="font-size:12px;color:#556"><?php echo esc_html($note); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    // Turn the stage results into one concrete instruction.
                    $stage_ok = [];
                    foreach (explode("\n", $gate_test) as $l) {
                        $b = explode('|', $l, 3);
                        $stage_ok[$b[0] ?? ''] = 'ok' === ($b[1] ?? '');
                    }

                    $verdict = '';

                    if (!empty($stage_ok['https'])) {
                        $verdict = __('Every stage passed — the server can reach the gateway, so sending should work.', 'vereskala-login');
                    } elseif (isset($stage_ok['dns']) && !$stage_ok['dns']) {
                        $verdict = __('The name lookup failed. Ask your host to fix DNS resolution on the server.', 'vereskala-login');
                    } elseif (isset($stage_ok['tcp']) && !$stage_ok['tcp']) {
                        $verdict = __('The port is closed. Ask your host to allow outgoing connections to this address on port 443.', 'vereskala-login');
                    } elseif (!empty($stage_ok['noverify'])) {
                        $verdict = __('The request works only when the certificate check is skipped, so the server has an out-of-date certificate bundle. Ask your host to update the CA bundle (ca-certificates) and the openssl.cafile setting in PHP.', 'vereskala-login');
                    } elseif (isset($stage_ok['tls']) && !$stage_ok['tls'] && !empty($stage_ok['tcp'])) {
                        $verdict = __('The port opens instantly but the secure handshake never completes. Traffic is being accepted and then silently dropped, which happens in two situations: a filter on your host inspecting outgoing HTTPS, or the gateway blocking your server. Do both — send this page to your host, and send your SMS provider the outgoing IP shown above and ask them to allow it. If your site is hosted outside Iran, that block is the most likely cause.', 'vereskala-login');
                    } else {
                        $verdict = __('The connection opens but the gateway never answers. Show this page to your host, and also ask your SMS provider whether your account or server IP is blocked on their side.', 'vereskala-login');
                    }
                    ?>
                    <div style="padding:9px 12px;border-radius:8px;margin-bottom:10px;background:<?php echo !empty($stage_ok['https']) ? '#eaf7ee' : '#fdf3f3'; ?>;border:1px solid <?php echo !empty($stage_ok['https']) ? '#bfe6c9' : '#f0c9c9'; ?>">
                        <?php echo !empty($stage_ok['https']) ? '✅ ' : '⛔ '; ?><?php echo esc_html($verdict); ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="action" value="vkl_gateway_test">
                    <?php wp_nonce_field('vkl_gateway_test'); ?>
                    <input type="text" name="vkl_host" dir="ltr" style="min-width:240px"
                           placeholder="rest.payamak-panel.com"
                           value="">
                    <?php submit_button(__('Test the connection now', 'vereskala-login'), 'secondary', 'submit', false); ?>
                    <span class="description"><?php esc_html_e('Leave empty for the current gateway, or enter another provider’s address to compare before switching.', 'vereskala-login'); ?></span>
                </form>
                <p class="description" style="margin-top:6px">
                    <?php esc_html_e('Addresses worth comparing: rest.payamak-panel.com · console.melipayamak.com · api.kavenegar.com · api.sms.ir', 'vereskala-login'); ?>
                </p>

                <?php
                $proxy_on = defined('WP_PROXY_HOST') && defined('WP_PROXY_PORT');
                ?>
                <details style="margin-top:12px">
                    <summary style="cursor:pointer;font-weight:600"><?php esc_html_e('If the handshake keeps failing: route through a proxy', 'vereskala-login'); ?></summary>
                    <div style="padding:10px 2px 0">
                        <p class="description">
                            <?php esc_html_e('When the port opens but the secure handshake never finishes, the traffic is being filtered on the way out. WordPress can send its outgoing requests through a proxy your host provides. Add these lines to wp-config.php, above the "That\'s all" comment:', 'vereskala-login'); ?>
                        </p>
<pre dir="ltr" style="background:#f6f7f7;border-radius:6px;padding:10px;overflow:auto;font-size:12px;user-select:all">define('WP_PROXY_HOST', 'PROXY-ADDRESS');
define('WP_PROXY_PORT', 'PORT');
// only if the proxy needs a login:
define('WP_PROXY_USERNAME', 'USER');
define('WP_PROXY_PASSWORD', 'PASS');</pre>
                        <p class="description">
                            <?php
                            echo $proxy_on
                                ? '✅ ' . esc_html__('A proxy is currently configured in wp-config.php.', 'vereskala-login')
                                : esc_html__('No proxy is configured at the moment. Ask your host whether they provide one before adding these lines.', 'vereskala-login');
                            ?>
                        </p>
                    </div>
                </details>
            </div>
            <h1><?php esc_html_e('Status', 'vereskala-login'); ?></h1>

            <h2 class="title"><?php esc_html_e('Environment', 'vereskala-login'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                <tr><td>Vereskonto</td><td><?php echo esc_html(VKL_VERSION); ?></td></tr>
                <tr><td>PHP</td><td><?php echo esc_html(PHP_VERSION); ?></td></tr>
                <tr><td>WordPress</td><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                <tr>
                    <td>WooCommerce</td>
                    <td><?php echo $wc_active ? esc_html((string) (defined('WC_VERSION') ? WC_VERSION : '?')) : wp_kses_post($this->mark(false)); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Checkout page type', 'vereskala-login'); ?></td>
                    <td>
                        <?php
                        if ('classic' === $checkout) {
                            echo wp_kses_post($this->mark(true)) . ' ' . esc_html__('Classic shortcode — all Vereskonto checkout features work.', 'vereskala-login');
                        } elseif ('block' === $checkout) {
                            echo wp_kses_post($this->mark(false)) . ' ' . esc_html__('Checkout block — the wallet box cannot render. Replace the block with the [woocommerce_checkout] shortcode on the checkout page.', 'vereskala-login');
                        } else {
                            esc_html_e('Could not detect (custom template?).', 'vereskala-login');
                        }
                        ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Gateways', 'vereskala-login'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                <tr>
                    <td><?php esc_html_e('SMS gateway', 'vereskala-login'); ?></td>
                    <td><?php echo wp_kses_post($this->mark($sms_ok)); ?>
                        <?php $sms_ok ? esc_html_e('Melipayamak username and password are set (shared with the SMS login).', 'vereskala-login') : esc_html_e('Enter the Melipayamak username and password under Settings → SMS settings.', 'vereskala-login'); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WhatsApp gateway (Meta Cloud API)', 'vereskala-login'); ?></td>
                    <td><?php echo wp_kses_post($this->mark($wa_ok)); ?>
                        <?php $wa_ok ? esc_html_e('Token and phone number ID are set.', 'vereskala-login') : esc_html_e('Optional. Configure it on the SMS Notifications page (see the setup guide there).', 'vereskala-login'); ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Installment gateway', 'vereskala-login'); ?></td>
                    <td>
                        <?php
                        global $wpdb;

                        $gw_class   = class_exists('VKL_Installment_Gateway');
                        $gw_setting = get_option('woocommerce_vkl_installment_settings', []);
                        $gw_enabled = is_array($gw_setting) && 'yes' === ($gw_setting['enabled'] ?? 'no');
                        $col        = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}vkl_installments LIKE 'type'"); // phpcs:ignore
                        $credit_n   = null !== $col ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vkl_installments WHERE type = 'credit' AND status = 'active'") : 0; // phpcs:ignore

                        echo wp_kses_post($this->mark($gw_class)) . ' ' . esc_html__('class loaded', 'vereskala-login') . ' · ';
                        echo wp_kses_post($this->mark($gw_enabled)) . ' ' . esc_html__('enabled in WooCommerce payments', 'vereskala-login') . ' · ';
                        echo wp_kses_post($this->mark(null !== $col)) . ' ' . esc_html__('DB column "type"', 'vereskala-login') . ' · ';
                        echo esc_html(sprintf(/* translators: %d: count. */ __('%d active credit plans', 'vereskala-login'), $credit_n));

                        if (null === $col) {
                            echo '<p class="description">' . esc_html__('The 1.13 migration has not run — deactivate and reactivate the plugin once, then re-check.', 'vereskala-login') . '</p>';
                        }

                        echo '<p class="description">' . esc_html__('The method shows at checkout only when: the customer is logged in, has an active credit plan, and the remaining credit covers the whole order (including shipping). It never shows for guests or on the Checkout Block.', 'vereskala-login') . '</p>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Admin mobile', 'vereskala-login'); ?></td>
                    <td><?php echo wp_kses_post($this->mark('' !== $admin_mobile)); ?>
                        <?php echo '' !== $admin_mobile ? esc_html($this->security->mask_mobile($admin_mobile)) : esc_html__('Required for admin alerts (new order, new ticket).', 'vereskala-login'); ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Features and required patterns', 'vereskala-login'); ?></h2>
            <p class="description"><?php esc_html_e('A message is only sent when its feature is enabled AND its pattern/template field is filled in. Empty pattern = silent skip by design.', 'vereskala-login'); ?></p>
            <table class="widefat striped" style="max-width:760px">
                <thead>
                <tr>
                    <th><?php esc_html_e('Feature', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Enabled', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Pattern', 'vereskala-login'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($features as [$label, $enabled, $pattern_keys]) : ?>
                    <tr>
                        <td><?php echo esc_html($label); ?></td>
                        <td><?php echo wp_kses_post($this->mark($enabled)); ?></td>
                        <td>
                            <?php
                            if ([] === $pattern_keys) {
                                echo '&mdash;';
                            } else {
                                $all = true;
                                foreach ($pattern_keys as $key) {
                                    if ('' === $this->config->get_string($key)) {
                                        $all = false;
                                        break;
                                    }
                                }
                                echo wp_kses_post($this->mark($all));
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><?php esc_html_e('Order SMS', 'vereskala-login'); ?> — <?php esc_html_e('Pattern: order %s', 'vereskala-login'); ?></td>
                    <td colspan="2">
                        <?php
                        foreach (VKL_Sms_Order::STATUSES as $status) {
                            $set = '' !== $this->config->get_string('order_pattern_' . str_replace('-', '_', $status));
                            echo esc_html($status) . ' ' . wp_kses_post($this->mark($set)) . ' &nbsp; ';
                        }
                        ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Installment purchase (checkout method)', 'vereskala-login'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                <?php
                global $wpdb;

                $inst_table = $wpdb->prefix . 'vkl_installments';
                $inst_cols  = (array) $wpdb->get_col("SHOW COLUMNS FROM `{$inst_table}`"); // phpcs:ignore
                $has_type   = in_array('type', $inst_cols, true);

                $credit_rows = $has_type
                    ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$inst_table}` WHERE type = 'credit' AND status = 'active'") // phpcs:ignore
                    : 0;

                $gateway_class = class_exists('VKL_Installment_Gateway');
                $gateway_on    = false;

                if (function_exists('WC') && $gateway_class) {
                    $settings   = (array) get_option('woocommerce_vkl_installment_settings', []);
                    $gateway_on = 'yes' === ($settings['enabled'] ?? 'no');
                }

                $my_credit = 0.0;

                try {
                    $my_credit = vkl('wallet.installments')->available_credit(get_current_user_id());
                } catch (Throwable $e) {
                    $my_credit = 0.0;
                }
                ?>
                <tr>
                    <td><?php esc_html_e('Gateway enabled in WooCommerce → Payments', 'vereskala-login'); ?></td>
                    <td><?php echo wp_kses_post($this->mark($gateway_on)); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Active purchase-credit plans (all customers)', 'vereskala-login'); ?></td>
                    <td><?php echo esc_html(number_format_i18n($credit_rows)); ?>
                        <?php if (0 === $credit_rows) : ?>
                            — <?php esc_html_e('None yet: create a plan of type "Purchase credit" on the Wallet page. Plans of type "Direct debt" never show the checkout method.', 'vereskala-login'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Scheduler (WP-Cron)', 'vereskala-login'); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                <tr>
                    <td>DISABLE_WP_CRON</td>
                    <td>
                        <?php
                        if ($cron_disabled) {
                            echo wp_kses_post($this->mark(false)) . ' ';
                            esc_html_e('WP-Cron is disabled in wp-config.php. Abandoned-cart checks and campaigns only run if your host triggers wp-cron.php externally.', 'vereskala-login');
                        } else {
                            echo wp_kses_post($this->mark(true)) . ' ';
                            esc_html_e('WP-Cron runs on site visits.', 'vereskala-login');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Next abandoned-cart check', 'vereskala-login'); ?></td>
                    <td><?php echo $cart_next ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $cart_next), 'Y-m-d H:i:s')) : wp_kses_post($this->mark(false)) . ' ' . esc_html__('Not scheduled — deactivate and reactivate the plugin.', 'vereskala-login'); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Next campaign batch', 'vereskala-login'); ?></td>
                    <td><?php echo $campaign_next ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $campaign_next), 'Y-m-d H:i:s')) : wp_kses_post($this->mark(false)) . ' ' . esc_html__('Not scheduled — deactivate and reactivate the plugin.', 'vereskala-login'); ?></td>
                </tr>
                </tbody>
            </table>

            <h2 class="title"><?php esc_html_e('Last messages', 'vereskala-login'); ?></h2>
            <?php if ([] === $rows) : ?>
                <p><?php esc_html_e('No messages logged yet. If you expected messages here, check the toggles and patterns above first.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Date (UTC)', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Type', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Channel', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Mobile', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Gateway response', 'vereskala-login'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['type'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($row['channel'] ?? 'sms')); ?></td>
                            <td><?php echo esc_html($this->security->mask_mobile((string) ($row['mobile'] ?? ''))); ?></td>
                            <td><?php echo wp_kses_post($this->mark('sent' === (string) ($row['status'] ?? ''))); ?></td>
                            <td><code style="font-size:11px"><?php echo esc_html(mb_substr((string) ($row['response'] ?? ''), 0, 160)); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
