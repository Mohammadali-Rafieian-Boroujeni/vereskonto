<?php
/**
 * Engagement popup — after the visitor has spent a configurable number
 * of minutes on the site (accumulated across pages in this browser
 * session), a slide-up bar makes a tempting offer, optionally with a
 * coupon code and a target link. Shown at most once per session.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Popup
 */
final class VKL_Smart_Popup
{
    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * @param VKL_Config $config Config.
     */
    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_footer', [$this, 'render']);
        add_action('template_redirect', [$this, 'track_search'], 1);
    }

    /**
     * Remember a search phrase in the visitor's cookie (last 10, 7 days).
     * Readable by JS on purpose — the popup builds its smart link from it.
     */
    public static function remember_search(string $term): void
    {
        if (headers_sent()) {
            return;
        }

        $term = trim(mb_substr($term, 0, 60));

        if ('' === $term) {
            return;
        }

        $history = [];

        if (isset($_COOKIE['vkl_search_history'])) {
            $decoded = json_decode(rawurldecode((string) $_COOKIE['vkl_search_history']), true);

            if (is_array($decoded)) {
                $history = array_values(array_filter(array_map('strval', $decoded)));
            }
        }

        $history[] = $term;
        $history   = array_slice($history, -10);

        setcookie(
            'vkl_search_history',
            rawurlencode((string) wp_json_encode($history)),
            time() + 7 * DAY_IN_SECONDS,
            '/',
            '',
            is_ssl(),
            false // JS must read it.
        );
    }

    /**
     * Track native WordPress/WooCommerce searches.
     */
    public function track_search(): void
    {
        if (is_search()) {
            self::remember_search((string) get_search_query());
        }
    }

    /**
     * Render markup + timer script.
     */
    public function render(): void
    {
        if (!$this->config->get_bool('popup_enabled', false) || is_admin() || is_checkout()) {
            return;
        }

        $text = $this->config->get_string('popup_text');

        if ('' === $text) {
            return;
        }

        $minutes = max(1, $this->config->get_int('popup_minutes', 2));
        $repeat  = max(1, $this->config->get_int('popup_repeat_hours', 24));
        // Campaign fingerprint: changing the offer shows the popup again.
        $sig = substr(md5($text . '|' . $this->config->get_string('popup_coupon') . '|' . $minutes), 0, 12);
        $coupon  = $this->config->get_string('popup_coupon');
        $link       = $this->config->get_string('popup_link');
        $link       = '' !== $link ? $link : (function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/'));
        $smart_link = $this->config->get_bool('popup_link_smart', true);
        ?>
        <style>
            /* The three actions are one visual set, so they share a width:
               CTA | gift code | copy — reading right-to-left the strip is
               message, copy, code, CTA. */
            #vkl-popup .vkl-pop-item{flex:0 0 148px;width:148px;box-sizing:border-box;
                margin:0;height:38px;line-height:22px;display:inline-flex;align-items:center;justify-content:center;
                padding:8px 10px;border-radius:8px;font-size:13px;font-weight:700;font-family:inherit;
                text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;-webkit-appearance:none;appearance:none}
            @media (max-width: 600px) {
                #vkl-popup .vkl-pop-item{flex:1 1 0;width:auto;min-width:0;height:34px;line-height:20px;padding:6px 4px;font-size:11.5px;border-radius:7px}
                #vkl-popup{padding:7px 10px !important;font-size:12px}
                #vkl-popup > div{gap:6px !important;flex-wrap:wrap;position:relative;padding-inline-end:22px}
                #vkl-popup .vkl-pop-msg{flex:1 1 100% !important;min-width:0 !important;font-size:12px !important;line-height:1.6 !important;margin:0 0 2px !important}
                #vkl-popup p{margin:0 !important;font-size:12px !important;line-height:1.6 !important}
                #vkl-popup #vkl-popup-close{position:absolute;top:-3px;inset-inline-end:0;inset-inline-start:auto;font-size:18px;padding:2px}
            }
        </style>
        <div id="vkl-popup" dir="rtl" style="display:none;position:fixed;bottom:0;inset-inline:0;z-index:99999;background:#124e27;color:#fff;padding:14px 18px;box-shadow:0 -6px 24px rgba(0,0,0,.3)">
            <div style="max-width:960px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:10px">
                <span class="vkl-pop-msg" style="flex:1;min-width:180px;line-height:1.8"><?php echo esc_html($text); ?></span>

                <?php if ('' !== $coupon) : ?>
                    <button type="button" id="vkl-popup-copy" class="vkl-pop-item"
                            style="background:#22a84d;border:1px solid #5fd487;color:#fff;cursor:pointer">
                        <?php esc_html_e('Copy the code', 'vereskala-login'); ?>
                    </button>
                    <code id="vkl-popup-coupon" class="vkl-pop-item"
                          style="background:#fff;color:#124e27;cursor:pointer;border:1px solid #fff"
                          title="<?php esc_attr_e('Click to copy', 'vereskala-login'); ?>"><span aria-hidden="true">🎁</span> <span id="vkl-popup-code"><?php echo esc_html($coupon); ?></span></code>
                <?php endif; ?>

                <a id="vkl-popup-cta" class="vkl-pop-item" href="<?php echo esc_url($link); ?>"
                   style="background:#e5262c;color:#fff;border:1px solid #e5262c;text-decoration:none">
                    <?php esc_html_e('Shop now', 'vereskala-login'); ?>
                </a>

                <button type="button" id="vkl-popup-close" aria-label="close"
                        style="background:none;border:0;color:#fff;font-size:20px;cursor:pointer;line-height:1">&times;</button>
            </div>
        </div>
        <script>
        (function () {
            try {
                var sig = <?php echo wp_json_encode($sig); ?>;
                var repeatMs = <?php echo (int) $repeat; ?> * 3600 * 1000;

                // Shown before? Only suppress while the offer is unchanged
                // AND the repeat window has not passed.
                var last = null;
                try { last = JSON.parse(localStorage.getItem('vklPopup') || 'null'); } catch (e) {}
                if (last && last.sig === sig && (Date.now() - last.t) < repeatMs) { return; }

                var target = <?php echo (int) ($minutes * 60); ?>;
                var secKey = 'vklPopupSeconds_' + sig;
                var spent = parseInt(sessionStorage.getItem(secKey) || '0', 10);
                var bar = document.getElementById('vkl-popup');

                var timer = setInterval(function () {
                    if (document.hidden) { return; }
                    spent += 5;
                    sessionStorage.setItem(secKey, String(spent));
                    if (spent >= target) {
                        clearInterval(timer);
                        try { localStorage.setItem('vklPopup', JSON.stringify({ sig: sig, t: Date.now() })); } catch (e) {}
                        sessionStorage.removeItem(secKey);
                        bar.style.display = 'block';
                    }
                }, 5000);

                document.getElementById('vkl-popup-close').addEventListener('click', function () {
                    bar.style.display = 'none';
                });

                var coupon = document.getElementById('vkl-popup-coupon');
                var copyBtn = document.getElementById('vkl-popup-copy');
                var copiedLabel = <?php echo wp_json_encode(__('Copied!', 'vereskala-login')); ?>;
                function doCopy() {
                    if (!coupon) { return; }
                    var codeEl = document.getElementById('vkl-popup-code');
                    var code = (codeEl ? codeEl.textContent : coupon.textContent).trim();
                    if (navigator.clipboard) { navigator.clipboard.writeText(code); }
                    coupon.style.background = '#c9f7d9';
                    if (copyBtn) {
                        copyBtn.textContent = copiedLabel;
                        copyBtn.style.background = '#157f3a';
                        copyBtn.style.borderColor = '#157f3a';
                        copyBtn.style.color = '#fff';
                    }
                }
                if (coupon) { coupon.addEventListener('click', doCopy); }
                if (copyBtn) { copyBtn.addEventListener('click', doCopy); }

                <?php if ($smart_link) : ?>
                // Point the button at what this visitor searched for most.
                try {
                    var raw = document.cookie.split('; ').find(function (c) { return c.indexOf('vkl_search_history=') === 0; });
                    if (raw) {
                        var list = JSON.parse(decodeURIComponent(raw.split('=').slice(1).join('=')));
                        if (Array.isArray(list) && list.length) {
                            var freq = {};
                            list.forEach(function (t, i) { freq[t] = (freq[t] || 0) + 1 + i * 0.01; });
                            var top = Object.keys(freq).sort(function (a, b) { return freq[b] - freq[a]; })[0];
                            if (top) {
                                var base = <?php echo wp_json_encode(esc_url(home_url('/'))); ?>;
                                document.getElementById('vkl-popup-cta').href =
                                    base + '?s=' + encodeURIComponent(top) + '&post_type=product';
                            }
                        }
                    }
                } catch (err) { /* keep default link */ }
                <?php endif; ?>
            } catch (e) { /* sessionStorage unavailable */ }
        })();
        </script>
        <?php
    }
}
