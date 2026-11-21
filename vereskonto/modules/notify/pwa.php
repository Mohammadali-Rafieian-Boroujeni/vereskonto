<?php
/**
 * Installable app and browser notifications.
 *
 * Two pieces the customer sees: an invitation to install the shop as an
 * app, and permission to send notifications when they are not on the site.
 * Both are deliberately quiet — asked once, easy to dismiss, never nagging.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.99.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Pwa
 */
final class VKL_Notify_Pwa
{
    private VKL_Config $config;
    private VKL_Notify_Push $push;

    public function __construct(VKL_Config $config, VKL_Notify_Push $push)
    {
        $this->config = $config;
        $this->push   = $push;
    }

    public function register(): void
    {
        // The manifest and the worker are served from the site root through
        // rewrites: a service worker may only control paths at or below its
        // own, so one parked in /wp-content could never handle the shop.
        add_action('init', [$this, 'add_rewrites']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve'], 1);

        add_action('wp_head', [$this, 'head_tags']);
        add_action('wp_footer', [$this, 'footer_script'], 30);

        add_action('rest_api_init', [$this, 'register_routes']);

        // Two things that must happen exactly once after the feature is
        // switched on: the rewrite rules have to be flushed (otherwise the
        // manifest 404s) and the signing keys have to exist.
        add_action('admin_init', [$this, 'maybe_prepare']);
    }

    /**
     * One-time setup after the feature is enabled.
     */
    public function maybe_prepare(): void
    {
        if (!$this->config->get_bool('pwa_enabled', false)) {
            return;
        }

        if ('1' !== (string) get_option('vkl_pwa_rewrites_done', '')) {
            $this->add_rewrites();
            flush_rewrite_rules(false);
            update_option('vkl_pwa_rewrites_done', '1', false);
        }

        if ($this->config->get_bool('push_enabled', false) && !VKL_Notify_Vapid::has_keys()) {
            VKL_Notify_Vapid::generate();
        }
    }

    public function add_rewrites(): void
    {
        add_rewrite_rule('^vkl-manifest\.json$', 'index.php?vkl_pwa=manifest', 'top');
        add_rewrite_rule('^vkl-sw\.js$', 'index.php?vkl_pwa=sw', 'top');
    }

    /**
     * @param array<int, string> $vars Registered query vars.
     *
     * @return array<int, string>
     */
    public function add_query_vars(array $vars): array
    {
        $vars[] = 'vkl_pwa';

        return $vars;
    }

    public function maybe_serve(): void
    {
        $what = get_query_var('vkl_pwa');

        if ('manifest' === $what) {
            $this->serve_manifest();
        }

        if ('sw' === $what) {
            $this->serve_worker();
        }
    }

    private function serve_manifest(): void
    {
        $icon = (string) $this->config->get_string('pwa_icon');

        $manifest = [
            'name'             => $this->config->get_string('pwa_name') ?: get_bloginfo('name'),
            'short_name'       => $this->config->get_string('pwa_short_name') ?: get_bloginfo('name'),
            'start_url'        => home_url('/?utm_source=pwa'),
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'dir'              => 'rtl',
            'lang'             => 'fa-IR',
            'background_color' => '#ffffff',
            'theme_color'      => $this->config->get_string('pwa_theme') ?: '#42D968',
        ];

        $icons = $this->icon_entries($icon);

        if ([] !== $icons) {
            $manifest['icons'] = $icons;
        }

        nocache_headers();
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Manifest icon entries, declared at the size the file really is.
     *
     * Getting this wrong is silently fatal: Chrome checks the actual image
     * against the declared "sizes" and simply never offers to install when
     * they disagree — with no error anywhere.
     *
     * @return array<int, array<string, string>>
     */
    private function icon_entries(string $icon): array
    {
        if ('' === $icon) {
            return [];
        }

        [$width, $height] = $this->icon_dimensions($icon);
        $type = 'image/png';

        if (preg_match('/\.(jpe?g)(\?|$)/i', $icon)) {
            $type = 'image/jpeg';
        } elseif (preg_match('/\.(webp)(\?|$)/i', $icon)) {
            $type = 'image/webp';
        }

        // Unknown size: claim only what is safe. 192 is the smallest square
        // browsers accept for an install prompt.
        $size = ($width > 0 && $width === $height) ? $width . 'x' . $width : '192x192';

        $entries = [
            ['src' => $icon, 'sizes' => $size, 'type' => $type, 'purpose' => 'any'],
        ];

        // Deliberately no "maskable" entry. That purpose gives Android
        // permission to crop the icon into a circle, which cuts roughly a
        // fifth off every edge and mangles a logo that was drawn to fill
        // its square. Without it the artwork is shown whole.
        return $entries;
    }

    /**
     * Real pixel size of an uploaded image, from the media library.
     *
     * @return array{0: int, 1: int}
     */
    private function icon_dimensions(string $url): array
    {
        $id = attachment_url_to_postid($url);

        if ($id > 0) {
            $meta = wp_get_attachment_metadata($id);

            if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) {
                return [(int) $meta['width'], (int) $meta['height']];
            }
        }

        return [0, 0];
    }

    private function serve_worker(): void
    {
        $file = VKL_PATH . 'assets/sw.js';

        if (!is_readable($file)) {
            status_header(404);
            exit;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache');
        echo file_get_contents($file); // phpcs:ignore

        exit;
    }

    public function head_tags(): void
    {
        if (!$this->enabled()) {
            return;
        }

        printf(
            '<link rel="manifest" href="%s">' . "\n",
            esc_url(home_url('/vkl-manifest.json'))
        );

        printf(
            '<meta name="theme-color" content="%s">' . "\n",
            esc_attr($this->config->get_string('pwa_theme') ?: '#42D968')
        );

        $icon = (string) $this->config->get_string('pwa_icon');

        if ('' !== $icon) {
            // iOS ignores the manifest icon and wants its own tag.
            printf('<link rel="apple-touch-icon" href="%s">' . "\n", esc_url($icon));
        }

        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    }

    private function enabled(): bool
    {
        return $this->config->get_bool('pwa_enabled', false) && is_ssl();
    }

    /**
     * Registration, the install invitation, and the notification opt-in.
     */
    public function footer_script(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $ask_push = $this->config->get_bool('push_enabled', false)
            && VKL_Notify_Vapid::has_keys()
            && is_user_logged_in();
        ?>
        <script>
        (function () {
            if (!('serviceWorker' in navigator)) { return; }

            var swUrl = <?php echo wp_json_encode(home_url('/vkl-sw.js')); ?>;
            var askPush = <?php echo $ask_push ? 'true' : 'false'; ?>;
            var vapid = <?php echo wp_json_encode($ask_push ? VKL_Notify_Vapid::public_key() : ''); ?>;
            var api = <?php echo wp_json_encode(esc_url_raw(rest_url('vereskonto/v1/push/'))); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;

            var txt = <?php echo wp_json_encode([
                'install_title' => __('نصب اپلیکیشن ورسکالا', 'vereskala-login'),
                'install_body'  => __('ورسکالا را روی گوشی خود نصب کنید: بازکردن سریع‌تر، کار با اینترنت ضعیف، و آگاهی از سپارش‌ها.', 'vereskala-login'),
                'install_yes'   => __('نصب کن', 'vereskala-login'),
                'install_no'    => __('بعداً', 'vereskala-login'),
                'push_title'    => __('آگاه‌سازی سپارش‌ها', 'vereskala-login'),
                'push_body'     => __('اجازه می‌دهید از وضعیت سپارش، قسط و پیشنهادها آگاهتان کنیم؟ هر زمان بخواهید می‌توانید خاموشش کنید.', 'vereskala-login'),
                'push_yes'      => __('باشد', 'vereskala-login'),
                'push_no'       => __('نه، ممنون', 'vereskala-login'),
            ]); ?>;

            function seen(key) {
                try { return localStorage.getItem(key); } catch (e) { return null; }
            }

            function remember(key) {
                try { localStorage.setItem(key, String(Date.now())); } catch (e) {}
            }

            function card(title, body, yes, no, onYes, key) {
                var box = document.createElement('div');
                box.className = 'vkl-pwa-card'; // lifted above the account tab bar on phones
                box.setAttribute('dir', 'rtl');
                box.style.cssText = 'position:fixed;inset-inline:12px;bottom:12px;z-index:99998;max-width:420px;' +
                    'margin-inline:auto;background:#fff;border:1px solid #dceee2;border-radius:14px;' +
                    'box-shadow:0 8px 28px rgba(0,0,0,.16);padding:14px 16px;font-family:inherit;line-height:1.8';

                var h = document.createElement('div');
                h.textContent = title;
                h.style.cssText = 'font-weight:800;color:#157f3a;margin-bottom:4px';

                var p = document.createElement('div');
                p.textContent = body;
                p.style.cssText = 'font-size:13.5px;color:#3a4a55;margin-bottom:10px';

                var row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;justify-content:flex-start';

                var ok = document.createElement('button');
                ok.type = 'button';
                ok.textContent = yes;
                ok.style.cssText = 'background:#157f3a;color:#fff;border:0;border-radius:9px;padding:8px 16px;' +
                    'font:inherit;font-weight:700;cursor:pointer';

                var later = document.createElement('button');
                later.type = 'button';
                later.textContent = no;
                later.style.cssText = 'background:none;border:0;color:#667;padding:8px 10px;font:inherit;cursor:pointer';

                function close() { remember(key); box.remove(); }

                ok.addEventListener('click', function () { close(); onYes(); });
                later.addEventListener('click', close);

                row.appendChild(ok);
                row.appendChild(later);
                box.appendChild(h);
                box.appendChild(p);
                box.appendChild(row);
                document.body.appendChild(box);
            }

            navigator.serviceWorker.register(swUrl, { scope: '/' }).then(function (reg) {
                if (!askPush || !('PushManager' in window) || !vapid) { return; }
                if (Notification.permission === 'denied') { return; }
                if (seen('vklPushAsked')) { return; }

                // Only ask once the visitor has settled in. A permission
                // prompt on arrival is the fastest way to a permanent no.
                setTimeout(function () {
                    if (Notification.permission === 'granted') { subscribe(reg); return; }

                    card(txt.push_title, txt.push_body, txt.push_yes, txt.push_no, function () {
                        Notification.requestPermission().then(function (p) {
                            if (p === 'granted') { subscribe(reg); }
                        });
                    }, 'vklPushAsked');
                }, 25000);
            }).catch(function () {});

            function urlB64(base64) {
                var pad = '='.repeat((4 - base64.length % 4) % 4);
                var raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                var out = new Uint8Array(raw.length);
                for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
                return out;
            }

            function subscribe(reg) {
                reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlB64(vapid)
                }).then(function (sub) {
                    var json = sub.toJSON();

                    fetch(api + 'subscribe', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                        body: JSON.stringify({
                            endpoint: sub.endpoint,
                            p256dh: (json.keys && json.keys.p256dh) || '',
                            auth: (json.keys && json.keys.auth) || ''
                        })
                    });
                }).catch(function () {});
            }

            // Install invitation. Chrome and Edge hand us the event; iOS
            // has no equivalent, so nothing is shown there rather than
            // giving instructions the customer cannot act on from a card.
            var deferred = null;

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                deferred = e;

                if (seen('vklInstallAsked')) { return; }

                setTimeout(function () {
                    if (!deferred) { return; }

                    card(txt.install_title, txt.install_body, txt.install_yes, txt.install_no, function () {
                        deferred.prompt();
                        deferred = null;
                    }, 'vklInstallAsked');
                }, 12000);
            });

            window.addEventListener('appinstalled', function () { remember('vklInstallAsked'); });
        })();
        </script>
        <?php
    }

    public function register_routes(): void
    {
        register_rest_route('vereskonto/v1', '/push/subscribe', [
            'methods'             => 'POST',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback'            => [$this, 'rest_subscribe'],
        ]);

        register_rest_route('vereskonto/v1', '/push/unsubscribe', [
            'methods'             => 'POST',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback'            => [$this, 'rest_unsubscribe'],
        ]);

        register_rest_route('vereskonto/v1', '/push/pending', [
            'methods'             => 'GET',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback'            => [$this, 'rest_pending'],
        ]);
    }

    public function rest_subscribe(WP_REST_Request $request): WP_REST_Response
    {
        $ok = $this->push->subscribe(
            get_current_user_id(),
            (string) $request->get_param('endpoint'),
            (string) $request->get_param('p256dh'),
            (string) $request->get_param('auth')
        );

        return new WP_REST_Response(['ok' => $ok], $ok ? 200 : 400);
    }

    public function rest_unsubscribe(WP_REST_Request $request): WP_REST_Response
    {
        $this->push->unsubscribe((string) $request->get_param('endpoint'));

        return new WP_REST_Response(['ok' => true]);
    }

    public function rest_pending(): WP_REST_Response
    {
        $icon = (string) $this->config->get_string('pwa_icon');

        return new WP_REST_Response([
            'items'          => $this->push->take_pending(get_current_user_id()),
            'icon'           => $icon,
            'badge'          => $icon,
            'fallback_title' => get_bloginfo('name'),
            'fallback_body'  => __('پیام تازه‌ای برای شما هست.', 'vereskala-login'),
        ]);
    }
}
