<?php
/**
 * Spin-the-wheel — a playful "wheel of fortune" shown to visitors instead
 * of (or alongside) the engagement popup. The visitor spins once per
 * period; the server picks the prize by weight (so it cannot be gamed
 * client-side), records it, and grants wallet credit prizes to logged-in
 * customers. Coupon prizes reveal an admin-defined code.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.52.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Wheel
 */
final class VKL_Smart_Wheel
{
    private const AJAX_SPIN   = 'vkl_wheel_spin';
    private const RETURN_COOKIE = 'vkl_wheel_return';
    private const RETURN_TTL    = 1800;
    private const SPIN_META     = '_vkl_wheel_last_spin';
    private const MAX_SLICES = 6;

    private VKL_Config $config;

    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        // As early as the permalink machinery exists, and before the theme
        // loads: a request carrying a wrapped or oversized return parameter
        // is bounced to a clean address (see guard_nested()). (plugins_loaded
        // is too early: $wp_rewrite is not built yet, so permalinks fatal.)
        add_action('setup_theme', [$this, 'guard_nested'], 0);

        // A page that carries a return parameter is a tool for the wheel's
        // own sign-in round trip, not content: keep it out of search indexes.
        if (isset($_GET[self::RETURN_COOKIE])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_filter('wp_robots', static function (array $robots): array {
                unset($robots['index'], $robots['follow']);
                $robots['noindex']  = true;
                $robots['nofollow'] = true;

                return $robots;
            }, 99);
            add_filter('wpseo_robots', static fn(): string => 'noindex, nofollow', 99);
            add_action('send_headers', static function (): void {
                header('X-Robots-Tag: noindex, nofollow', true);
            });
        }

        add_action('wp_footer', [$this, 'render']);
        add_action('wp_ajax_' . self::AJAX_SPIN, [$this, 'ajax_spin']);
        add_action('wp_ajax_nopriv_' . self::AJAX_SPIN, [$this, 'ajax_spin']);

        // Remember the page the customer came from, then bring them back
        // to it (with the wheel re-opened) once they have signed in.
        add_action('template_redirect', [$this, 'remember_return'], 8);
        add_filter('vkl_login_redirect', [$this, 'after_login_redirect']);
        add_filter('woocommerce_login_redirect', [$this, 'after_login_redirect']);
    }

    /**
     * The page customers sign in on — the shop's own account page, never
     * the WordPress admin login (which many sites rename or lock down).
     */
    /**
     * Account page address ('' when WooCommerce cannot say).
     */
    private function account_url(): string
    {
        return function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('myaccount') : '';
    }

    /**
     * The page the visitor is on, as a SAFE return address: any return
     * parameter the page itself carries is peeled off (never wrapped again),
     * add-to-cart is dropped, and the account page itself yields ''.
     */
    private function current_return_url(): string
    {
        return VKL_Wheel_Return::clean((string) home_url(add_query_arg([])), home_url('/'), $this->account_url());
    }

    /**
     * Bounce a request whose return parameter is wrapped (it contains
     * another return parameter), empty, or longer than any real address.
     *
     * Crawlers found a loop: the wheel link used to be built from the
     * current address, so on a page that already carried the parameter each
     * new link wrapped the previous one — a different, never-cached URL per
     * step, each running the whole of WordPress. A 301 to the clean address
     * collapses all of them onto one cacheable page.
     */
    public function guard_nested(): void
    {
        if (!isset($_GET[self::RETURN_COOKIE])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron()
            || !in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)
        ) {
            return;
        }

        $raw = $_GET[self::RETURN_COOKIE]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = is_string($raw) ? (string) wp_unslash($raw) : '';

        if ('' !== $raw && strlen($raw) <= VKL_Wheel_Return::MAX_LEN && !VKL_Wheel_Return::is_nested($raw)) {
            return; // an ordinary one-level return: leave it alone
        }

        $home    = home_url('/');
        $account = $this->account_url();
        $inner   = VKL_Wheel_Return::clean($raw, $home, $account);

        // Stay on the page that was asked for (clean path, no other query).
        $path    = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $origin  = (string) wp_parse_url($home, PHP_URL_SCHEME) . '://' . (string) wp_parse_url($home, PHP_URL_HOST)
            . (wp_parse_url($home, PHP_URL_PORT) ? ':' . (int) wp_parse_url($home, PHP_URL_PORT) : '');
        $acc_path = '' !== $account ? rtrim((string) wp_parse_url($account, PHP_URL_PATH), '/') : '';

        $target = '' !== $acc_path && rtrim($path, '/') === $acc_path
            ? $account
            : $origin . ('' === $path ? '/' : $path);

        // Plain-permalink sites identify the page by a query argument.
        foreach (['page_id', 'p'] as $identity) {
            if (isset($_GET[$identity]) && is_scalar($_GET[$identity]) && absint($_GET[$identity]) > 0) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $target = add_query_arg($identity, absint($_GET[$identity]), $target); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }

        if ('' !== $inner) {
            $target = add_query_arg(self::RETURN_COOKIE, $inner, $target);
        }

        wp_safe_redirect($target, 301);
        exit;
    }

    private function customer_login_url(string $return_to = ''): string
    {
        $url = function_exists('wc_get_page_permalink')
            ? (string) wc_get_page_permalink('myaccount')
            : '';

        if ('' === $url || false === strpos($url, 'http')) {
            $url = home_url('/');
        }

        // Always cleaned, whoever calls: the return value must never carry
        // a return parameter of its own. add_query_arg() encodes the value
        // once; encoding it here as well is what produced the %253A layers.
        $clean = '' !== $return_to
            ? VKL_Wheel_Return::clean($return_to, home_url('/'), $this->account_url())
            : '';

        if ('' !== $clean) {
            $url = add_query_arg(self::RETURN_COOKIE, $clean, $url);
        }

        return $url;
    }

    /**
     * Store the return address in a short-lived one-shot cookie.
     */
    public function remember_return(): void
    {
        if (!isset($_GET[self::RETURN_COOKIE])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $raw = $_GET[self::RETURN_COOKIE]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Innermost address, own host only, no add-to-cart, never the
        // account page itself — or nothing at all.
        $url = is_string($raw) ? VKL_Wheel_Return::clean((string) wp_unslash($raw), home_url('/'), $this->account_url()) : '';
        $url = '' !== $url ? wp_validate_redirect($url, '') : '';

        if ('' === $url) {
            return;
        }

        setcookie(
            self::RETURN_COOKIE,
            $url,
            time() + self::RETURN_TTL,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );
    }

    /**
     * After a successful sign-in that started at the wheel, go back to the
     * page it was shown on and re-open it. Handles both the SMS OTP login
     * and the standard WooCommerce form.
     *
     * @param mixed $redirect Default redirect target.
     *
     * @return mixed
     */
    public function after_login_redirect(mixed $redirect): mixed
    {
        $url = (string) ($_COOKIE[self::RETURN_COOKIE] ?? '');

        if ('' === $url) {
            return $redirect;
        }

        // One-shot: clear it so a later login is not hijacked.
        setcookie(self::RETURN_COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/');
        unset($_COOKIE[self::RETURN_COOKIE]);

        // The cookie is the visitor's to edit: clean it again, and only
        // ever go back to this site.
        $url = VKL_Wheel_Return::clean($url, home_url('/'), $this->account_url());
        $url = '' !== $url ? wp_validate_redirect($url, '') : '';

        if ('' === $url) {
            return $redirect;
        }

        // vkl_wheel=1 tells the front-end to open the wheel straight away.
        return add_query_arg('vkl_wheel', '1', $url);
    }

    /**
     * Has this customer already spun within the current period?
     *
     * The browser flag alone was not enough: it lives in localStorage and
     * the ?vkl_wheel=1 return from sign-in bypassed it, so a refresh handed
     * out another prize. The authoritative record lives on the account.
     */
    private function already_spun(int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        if ($user_id <= 0) {
            return false; // guests are limited by the browser flag only
        }

        $last = (int) get_user_meta($user_id, self::SPIN_META, true);

        if ($last <= 0) {
            return false;
        }

        $hours = max(1, $this->config->get_int('wheel_repeat_hours', 168));

        return (time() - $last) < ($hours * HOUR_IN_SECONDS);
    }

    /**
     * Stamp the spin so the prize cannot be claimed again this period.
     */
    private function mark_spun(int $user_id): void
    {
        if ($user_id > 0) {
            update_user_meta($user_id, self::SPIN_META, time());
        }
    }

    /**
     * The configured slices, normalised to a clean list.
     *
     * @return array<int, array{label:string,type:string,value:int,code:string,weight:int}>
     */
    public function slices(): array
    {
        return self::sanitize_slice_rows(
            json_decode($this->config->get_string('wheel_slices'), true)
        );
    }

    /**
     * The one place that decides what a valid wheel slice looks like.
     *
     * Both the front-end reader and the settings sanitiser call this, so a
     * change to the rules (a new prize type, a different maximum) cannot
     * drift apart between the two any more.
     *
     * @param mixed $decoded Raw decoded rows.
     *
     * @return array<int, array{label:string,type:string,value:int,code:string,weight:int}>
     */
    public static function sanitize_slice_rows(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = isset($row['label']) ? sanitize_text_field((string) $row['label']) : '';

            if ('' === $label) {
                continue;
            }

            $type = isset($row['type']) ? sanitize_key((string) $row['type']) : 'none';

            if (!in_array($type, ['credit', 'coupon', 'none'], true)) {
                $type = 'none';
            }

            $out[] = [
                'label'  => $label,
                'type'   => $type,
                'value'  => isset($row['value']) ? max(0, (int) $row['value']) : 0,
                'code'   => isset($row['code']) ? sanitize_text_field((string) $row['code']) : '',
                'weight' => isset($row['weight']) ? max(1, (int) $row['weight']) : 1,
            ];

            if (count($out) >= self::MAX_SLICES) {
                break;
            }
        }

        return $out;
    }

    /**
     * Whether any slice awards wallet credit. Credit can only be paid to a
     * real account, so a wheel with credit prizes requires a logged-in
     * customer.
     *
     * @param array<int, array<string, mixed>> $slices Slice data.
     */
    private function has_credit_prize(array $slices): bool
    {
        foreach ($slices as $s) {
            if ('credit' === $s['type'] && (int) $s['value'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render the wheel markup + styles + behaviour in the footer.
     */
    public function render(): void
    {
        if (!$this->config->get_bool('wheel_enabled', false) || is_admin() || (function_exists('is_checkout') && is_checkout())) {
            return;
        }

        $slices = $this->slices();

        if (count($slices) < 2) {
            return; // a wheel needs at least two slices
        }

        // Already had their turn this period — nothing to show.
        if ($this->already_spun()) {
            return;
        }

        $minutes = max(0, $this->config->get_int('wheel_minutes', 1));
        $repeat  = max(1, $this->config->get_int('wheel_repeat_hours', 168));
        $title   = $this->config->get_string('wheel_title');
        $title   = '' !== $title ? $title : __('Spin & win!', 'vereskala-login');

        // Credit prizes need an account to pay into: ask guests to sign in
        // rather than letting them win something that cannot be delivered.
        $needs_login = !is_user_logged_in() && $this->has_credit_prize($slices);
        $login_url   = $this->customer_login_url($this->current_return_url());

        // Campaign fingerprint: changing the slices re-shows the wheel.
        $sig = substr(md5(wp_json_encode(array_map(static fn($s) => [$s['label'], $s['type'], $s['value']], $slices)) . '|' . $minutes), 0, 12);

        // Attractive alternating gradient palette (green/red brand pairs
        // plus gold accents), each slice gets a distinct pair.
        $palette = [
            ['#1c9c4a', '#0d5f2a'], // green
            ['#f14b52', '#c11d24'], // red
            ['#2fbf63', '#157f3a'], // bright green
            ['#ff6b6f', '#d92b31'], // coral red
            ['#3ad679', '#0e7a35'], // mint green
            ['#ffb020', '#e07a00'], // gold
        ];
        $n   = count($slices);
        $seg = 360 / $n;

        // Prize label text per slice (kept short for the wheel face).
        $face = [];
        foreach ($slices as $s2) {
            $t = $s2['label'];

            if ('credit' === $s2['type'] && $s2['value'] > 0) {
                $t = number_format_i18n($s2['value']);
            }

            $face[] = mb_substr($t, 0, 14);
        }
        ?>
        <style>
            #vkl-wheel-wrap{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;background:radial-gradient(circle at 50% 40%,rgba(6,40,20,.55),rgba(4,20,10,.78));backdrop-filter:blur(3px)}
            #vkl-wheel-box{background:linear-gradient(180deg,#ffffff,#f3fbf6);border-radius:22px;padding:22px 20px 26px;max-width:360px;width:calc(100% - 32px);text-align:center;box-shadow:0 22px 60px rgba(0,0,0,.45);position:relative;overflow:hidden}
            #vkl-wheel-box:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 50% 0,rgba(66,217,104,.16),transparent 60%);pointer-events:none}
            #vkl-wheel-box h3{margin:0 0 4px;font-size:20px;font-weight:900;background:linear-gradient(90deg,#0e5f29,#22a84d,#e5262c);-webkit-background-clip:text;background-clip:text;color:transparent}
            #vkl-wheel-box .sub{color:#5c6b62;font-size:12.5px;margin:0 0 16px}
            #vkl-wheel-stage{position:relative;width:290px;height:290px;margin:0 auto;perspective:900px}
            #vkl-wheel-pointer{position:absolute;top:-2px;left:50%;transform:translateX(-50%);z-index:4;width:0;height:0;border-left:15px solid transparent;border-right:15px solid transparent;border-top:26px solid #e5262c;filter:drop-shadow(0 3px 3px rgba(0,0,0,.4))}
            #vkl-wheel-pointer:after{content:"";position:absolute;top:-30px;left:-7px;width:14px;height:14px;border-radius:50%;background:#fff;box-shadow:0 0 0 3px #e5262c,0 2px 4px rgba(0,0,0,.3)}
            #vkl-wheel-tilt{transform:rotateX(18deg);transform-style:preserve-3d;filter:drop-shadow(0 16px 18px rgba(0,0,0,.4))}
            #vkl-wheel{width:290px;height:290px;transition:transform 4.6s cubic-bezier(.15,.7,.12,1);will-change:transform}
            #vkl-wheel-hub{position:absolute;top:50%;left:50%;width:56px;height:56px;margin:-28px 0 0 -28px;border-radius:50%;background:radial-gradient(circle at 40% 35%,#ffffff,#e9f6ee);border:3px solid #0e5f29;z-index:3;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 10px rgba(0,0,0,.35),inset 0 -3px 6px rgba(0,0,0,.12)}
            #vkl-wheel-spin{margin-top:20px;background:linear-gradient(180deg,#22a84d,#157f3a);color:#fff;border:0;border-radius:14px;padding:13px 40px;font-size:16px;font-weight:900;letter-spacing:.5px;cursor:pointer;box-shadow:0 6px 0 #0e5f29,0 10px 18px rgba(21,127,58,.45);transition:transform .08s,box-shadow .08s}
            #vkl-wheel-spin:active{transform:translateY(4px);box-shadow:0 2px 0 #0e5f29,0 6px 12px rgba(21,127,58,.4)}
            #vkl-wheel-spin:disabled{opacity:.6;cursor:default;transform:none;box-shadow:0 6px 0 #0e5f29}
            #vkl-wheel-result .vkl-wheel-inline-login{display:inline-block;margin-top:8px;background:linear-gradient(180deg,#22a84d,#157f3a);color:#fff !important;border-radius:10px;padding:7px 20px;font-size:13.5px;font-weight:800;text-decoration:none}
            #vkl-wheel-login{display:inline-block;margin-top:14px;background:linear-gradient(180deg,#22a84d,#157f3a);color:#fff !important;border-radius:14px;padding:12px 34px;font-size:15px;font-weight:900;text-decoration:none;box-shadow:0 6px 0 #0e5f29,0 10px 18px rgba(21,127,58,.45)}
            #vkl-wheel-result{margin-top:16px;font-size:15px;font-weight:800;color:#0e5f29;min-height:24px}
            #vkl-wheel-result .code{display:inline-block;background:linear-gradient(180deg,#eafff1,#d7f7e2);border:1px dashed #157f3a;border-radius:9px;padding:4px 14px;margin-top:8px;font-family:monospace;font-size:16px;user-select:all;box-shadow:inset 0 1px 3px rgba(0,0,0,.06)}
            #vkl-wheel-close{position:absolute;top:10px;inset-inline-end:14px;border:0;background:rgba(0,0,0,.05);width:30px;height:30px;border-radius:50%;font-size:20px;color:#556;cursor:pointer;line-height:1;z-index:5}
            @media(max-width:400px){#vkl-wheel-stage,#vkl-wheel{width:250px;height:250px}#vkl-wheel-stage{height:250px}}
        </style>

        <div id="vkl-wheel-wrap" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($title); ?>">
            <div id="vkl-wheel-box" dir="rtl">
                <button type="button" id="vkl-wheel-close" aria-label="<?php esc_attr_e('Close', 'vereskala-login'); ?>">×</button>
                <h3><?php echo esc_html($title); ?></h3>
                <p class="sub"><?php esc_html_e('Give the wheel a spin and see what you win.', 'vereskala-login'); ?></p>
                <div id="vkl-wheel-stage">
                    <div id="vkl-wheel-pointer"></div>
                    <div id="vkl-wheel-tilt">
                        <div id="vkl-wheel">
                            <?php echo $this->render_svg($slices, $face, $palette, $seg); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </div>
                    <div id="vkl-wheel-hub">🎁</div>
                </div>
                <?php if ($needs_login) : ?>
                    <div style="margin-top:18px;padding:12px 14px;background:#fff8e6;border:1px solid #f0dca8;border-radius:12px;color:#7a5b12;font-size:13.5px">
                        🔐 <?php esc_html_e('Sign in first so your prize can be added to your account.', 'vereskala-login'); ?>
                    </div>
                    <a id="vkl-wheel-login" href="<?php echo esc_url($login_url); ?>" rel="nofollow"><?php esc_html_e('Sign in and spin', 'vereskala-login'); ?></a>
                <?php else : ?>
                    <button type="button" id="vkl-wheel-spin"><?php esc_html_e('SPIN', 'vereskala-login'); ?></button>
                <?php endif; ?>
                <div id="vkl-wheel-result" aria-live="polite"></div>
            </div>
        </div>

        <script>
        (function () {
            var KEY = 'vkl_wheel_<?php echo esc_js($sig); ?>';
            // Returning from sign-in: open immediately, bypassing both the
            // delay and the once-per-period flag.
            var forced = /[?&]vkl_wheel=1(&|$)/.test(window.location.search);

            // Take the flag out of the address bar straight away: leaving
            // it there meant every refresh counted as a fresh arrival from
            // sign-in and re-opened the wheel.
            if (forced && window.history && window.history.replaceState) {
                try {
                    var clean = window.location.href
                        .replace(/([?&])vkl_wheel=1&?/, '$1')
                        .replace(/[?&]$/, '');
                    window.history.replaceState({}, '', clean);
                } catch (e) {}
            }

            if (!forced) {
                try { if (localStorage.getItem(KEY)) { return; } } catch (e) {}
            }

            var wrap = document.getElementById('vkl-wheel-wrap');
            var wheel = document.getElementById('vkl-wheel');
            var spinBtn = document.getElementById('vkl-wheel-spin');
            var closeBtn = document.getElementById('vkl-wheel-close');
            var result = document.getElementById('vkl-wheel-result');
            if (!wrap || !wheel) { return; }
            // Guests see a sign-in link instead of the spin button.
            if (!spinBtn) {
                if (forced) { show(); } else { setTimeout(show, <?php echo (int) $minutes * 60000; ?>); }
                if (closeBtn) { closeBtn.addEventListener('click', hideGuest); }
                wrap.addEventListener('click', function (e) { if (e.target === wrap) { hideGuest(); } });
                return;
            }

            var n = <?php echo (int) $n; ?>;
            var seg = 360 / n;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('vkl_wheel')); ?>;
            var spun = false;

            function show() { wrap.style.display = 'flex'; }
            function hideGuest() {
                wrap.style.display = 'none';
                try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
            }
            function hide() {
                wrap.style.display = 'none';
                try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
            }

            if (forced) { show(); } else { setTimeout(show, <?php echo (int) $minutes * 60000; ?>); }
            closeBtn.addEventListener('click', hide);
            wrap.addEventListener('click', function (e) { if (e.target === wrap) { hide(); } });

            spinBtn.addEventListener('click', function () {
                if (spun) { return; }
                spun = true;
                spinBtn.disabled = true;

                var body = new URLSearchParams();
                body.append('action', '<?php echo esc_js(self::AJAX_SPIN); ?>');
                body.append('nonce', nonce);

                fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        // The server refuses a guest spin when credit prizes
                        // exist: show the sign-in prompt, not a generic error.
                        if (res && !res.success && res.data && res.data.need_login) {
                            var msg = res.data.message || <?php echo wp_json_encode(__('Please sign in first so your prize can be added to your account.', 'vereskala-login')); ?>;
                            var url = res.data.login_url || '';
                            result.innerHTML = '🔐 ' + msg
                                + (url ? '<br><a class="vkl-wheel-inline-login" href="' + url + '">'
                                    + <?php echo wp_json_encode(__('Sign in and spin', 'vereskala-login')); ?> + '</a>' : '');
                            spinBtn.disabled = true;
                            return;
                        }

                        // Turn already used this period.
                        if (res && !res.success && res.data && res.data.spent) {
                            result.textContent = res.data.message || '';
                            spinBtn.disabled = true;
                            try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}

                            return;
                        }

                        if (!res || !res.success) {
                            var err = (res && res.data && res.data.message) ? res.data.message : '';
                            throw new Error(err || 'spin failed');
                        }

                        var idx = res.data.index; // winning slice index from server
                        // Land the pointer (top) on the middle of the winning slice.
                        var target = 360 * 5 + (360 - (idx * seg + seg / 2));
                        wheel.style.transform = 'rotate(' + target + 'deg)';
                        setTimeout(function () {
                            var html = '🎉 ' + res.data.label;
                            if (res.data.code) {
                                html += '<br><span class="code">' + res.data.code + '</span>';
                            }
                            result.innerHTML = html;
                        }, 4300);
                    })
                    .catch(function (e) {
                        var fallback = <?php echo wp_json_encode(__('Something went wrong — please try again later.', 'vereskala-login')); ?>;
                        result.textContent = (e && e.message && e.message !== 'spin failed') ? e.message : fallback;
                        spinBtn.disabled = false;
                        spun = false;
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * Build the wheel face as an SVG: one gradient wedge per slice, the
     * prize text curved along each wedge, plus a glossy rim and bulbs for
     * a 3D look.
     *
     * @param array<int, array<string, mixed>> $slices  Slice data.
     * @param array<int, string>               $face    Short labels.
     * @param array<int, array{0:string,1:string}> $palette Gradient pairs.
     * @param float                            $seg     Degrees per slice.
     */
    private function render_svg(array $slices, array $face, array $palette, float $seg): string
    {
        $n   = count($slices);
        $cx  = 145.0;
        $cy  = 145.0;
        $r   = 138.0;
        $defs  = '';
        $wedges = '';
        $labels = '';

        for ($i = 0; $i < $n; $i++) {
            $pair = $palette[$i % count($palette)];
            $gid  = 'vkw' . $i;

            // Wedge geometry. Start at -90deg (12 o'clock) so slice 0 is on top.
            $a0 = deg2rad($i * $seg - 90);
            $a1 = deg2rad(($i + 1) * $seg - 90);
            $x0 = $cx + $r * cos($a0);
            $y0 = $cy + $r * sin($a0);
            $x1 = $cx + $r * cos($a1);
            $y1 = $cy + $r * sin($a1);
            $large = $seg > 180 ? 1 : 0;

            // Radial gradient makes each wedge look domed (3D).
            $defs .= sprintf(
                '<radialGradient id="%s" cx="50%%" cy="50%%" r="75%%"><stop offset="0%%" stop-color="%s"/><stop offset="100%%" stop-color="%s"/></radialGradient>',
                esc_attr($gid),
                esc_attr($pair[0]),
                esc_attr($pair[1])
            );

            $wedges .= sprintf(
                '<path d="M%.2f %.2f L%.2f %.2f A%.2f %.2f 0 %d 1 %.2f %.2f Z" fill="url(#%s)" stroke="rgba(255,255,255,.85)" stroke-width="2"/>',
                $cx, $cy, $x0, $y0, $r, $r, $large, $x1, $y1, esc_attr($gid)
            );

            // Prize label, centred in the wedge and rotated to read radially.
            $mid   = ($i + 0.5) * $seg;              // degrees from top
            $tr    = $r * 0.62;                       // text radius
            $ta    = deg2rad($mid - 90);
            $tx    = $cx + $tr * cos($ta);
            $ty    = $cy + $tr * sin($ta);
            $rot   = $mid;                            // upright-ish along radius
            if ($rot > 90 && $rot < 270) {
                $rot += 180; // flip so text is never upside-down
            }

            $labels .= sprintf(
                '<text x="%.2f" y="%.2f" transform="rotate(%.2f %.2f %.2f)" text-anchor="middle" dominant-baseline="middle" fill="#fff" font-size="13" font-weight="800" style="paint-order:stroke;stroke:rgba(0,0,0,.28);stroke-width:2px">%s</text>',
                $tx, $ty, $rot, $tx, $ty, esc_html($face[$i] ?? '')
            );
        }

        // Rim bulbs around the edge.
        $bulbs = '';
        $bn    = max(12, $n * 3);
        for ($b = 0; $b < $bn; $b++) {
            $ba = deg2rad($b * (360 / $bn) - 90);
            $bx = $cx + ($r + 6) * cos($ba);
            $by = $cy + ($r + 6) * sin($ba);
            $bulbs .= sprintf('<circle cx="%.2f" cy="%.2f" r="3.2" fill="%s"/>', $bx, $by, $b % 2 ? '#fff3b0' : '#ffd43b');
        }

        return sprintf(
            '<svg viewBox="0 0 290 290" width="100%%" height="100%%" xmlns="http://www.w3.org/2000/svg">'
            . '<defs>%s'
            . '<radialGradient id="vkwGloss" cx="50%%" cy="35%%" r="65%%"><stop offset="0%%" stop-color="rgba(255,255,255,.35)"/><stop offset="55%%" stop-color="rgba(255,255,255,0)"/></radialGradient>'
            . '</defs>'
            . '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#0e5f29"/>'      // outer ring shadow
            . '<g>%s</g>'                                                    // wedges
            . '<g>%s</g>'                                                    // labels
            . '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="#fff" stroke-width="6"/>' // inner white rim
            . '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="#0e5f29" stroke-width="4"/>' // green rim
            . '%s'                                                           // bulbs
            . '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="url(#vkwGloss)"/>' // gloss highlight
            . '</svg>',
            $defs,
            $cx, $cy, $r + 8,
            $wedges,
            $labels,
            $cx, $cy, $r,
            $cx, $cy, $r + 4,
            $bulbs,
            $cx, $cy, $r
        );
    }

    /**
     * AJAX: pick a prize by weight on the server, record + grant it.
     */
    public function ajax_spin(): void
    {
        check_ajax_referer('vkl_wheel', 'nonce');

        $slices = $this->slices();

        if (count($slices) < 2) {
            wp_send_json_error(['message' => 'no wheel']);
        }

        // A credit prize has no account to land in for a guest, so refuse
        // the spin instead of letting them "win" something undeliverable.
        if (!is_user_logged_in() && $this->has_credit_prize($slices)) {
            wp_send_json_error([
                'need_login' => true,
                'login_url'  => $this->customer_login_url(),
                'message'    => __('Please sign in first so your prize can be added to your account.', 'vereskala-login'),
            ]);
        }

        // One turn per period, checked on the server so a page refresh
        // cannot hand out another prize.
        $user_id = get_current_user_id();

        if ($this->already_spun($user_id)) {
            wp_send_json_error([
                'spent'   => true,
                'message' => __('You have already had your turn. Come back later for another spin.', 'vereskala-login'),
            ]);
        }

        // Claim the turn before awarding anything, so two quick clicks
        // cannot both get through.
        $this->mark_spun($user_id);

        // Weighted random pick.
        $total = array_sum(array_map(static fn($s): int => (int) $s['weight'], $slices));
        $roll  = wp_rand(1, max(1, $total));
        $acc   = 0;
        $index = 0;

        foreach ($slices as $i => $s) {
            $acc += (int) $s['weight'];

            if ($roll <= $acc) {
                $index = $i;
                break;
            }
        }

        $win     = $slices[$index];
        $user_id = get_current_user_id();
        $code    = '';

        if ('coupon' === $win['type']) {
            $code = $win['code'];
        } elseif ('credit' === $win['type'] && $win['value'] > 0) {
            if ($user_id <= 0 || !vkl()->has('wallet.promos')) {
                // Should not happen (guarded above), but never let a prize
                // vanish silently.
                wp_send_json_error([
                    'need_login' => true,
                    'login_url'  => $this->customer_login_url(),
                    'message'    => __('Please sign in first so your prize can be added to your account.', 'vereskala-login'),
                ]);
            }

            // Grant festival credit to the logged-in customer.
            try {
                $days = max(1, $this->config->get_int('wheel_credit_days', 7));
                vkl('wallet.promos')->grant_many(
                    [$user_id],
                    (float) $win['value'],
                    $days,
                    $this->config->get_string('wheel_title') ?: __('Wheel prize', 'vereskala-login'),
                    0.0,
                    'wheel-' . gmdate('Y-m')
                );
            } catch (Throwable $e) {
                wp_send_json_error(['message' => __('The prize could not be added — please contact us.', 'vereskala-login')]);
            }
        }

        wp_send_json_success([
            'index' => $index,
            'label' => $win['label'],
            'code'  => $code,
        ]);
    }
}
