<?php
/**
 * Customer-facing UI polish for the Vereskonto My Account tabs.
 *
 * One scoped stylesheet, printed only on My Account pages, that turns the
 * plugin's endpoint content (wallet, tickets, club, surveys) into a
 * consistent card language: soft borders, rounded corners, brand accents,
 * styled tables/forms/buttons, RTL-safe. No markup changes — existing
 * structures are styled from outside, so every tab (present and future)
 * inherits the look automatically.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.13.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Frontend_Ui
 */
final class VKL_Frontend_Ui
{
    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_head', [$this, 'global_styles']);
        add_action('wp_head', [$this, 'styles']);
        add_filter('woocommerce_account_menu_items', [$this, 'remove_payment_methods'], 99);
        add_filter('wc_get_template', [$this, 'dashboard_template'], 20, 2);
        add_action('woocommerce_account_content', [$this, 'account_back_bar'], 1);
        add_action('wp_head', [$this, 'account_styles']);
        add_action('wp_footer', [$this, 'account_tabbar']);
        add_filter('body_class', [$this, 'tabbar_body_class'], 999);
        add_filter('woodmart_option', [$this, 'theme_toolbar_option'], 99, 2);
        add_filter('woocommerce_add_to_cart_fragments', [$this, 'cart_fragment']);
    }

    /**
     * Tabs of the current request, memoised; [] while not computed.
     *
     * @var array<int, array{key: string, label: string, endpoint: string, active: bool}>|null
     */
    private static ?array $bar = null;

    /**
     * Account endpoint each account tab opens.
     */
    private const TAB_ENDPOINTS = ['purchases' => 'orders', 'wallet' => 'wallet', 'support' => 'tickets', 'account' => 'edit-account'];

    /**
     * Account pages each account tab stays highlighted on.
     */
    private const TAB_COVERS = [
        'purchases' => ['orders', 'view-order', 'returns', 'downloads'],
        'wallet'    => ['wallet', 'credit'],
        'support'   => ['tickets'],
        'account'   => ['edit-account', 'edit-address', 'club', 'surveys'],
    ];

    /**
     * Bottom tab bar items, as set under Settings › Mobile tab bar:
     * enabled tabs in their order. Home opens the shop's front page (or
     * the dashboard, if the admin chose that). Account tabs stay
     * highlighted on every page of their group and are left out for
     * guests and when their module is off (endpoint not in the menu).
     * The "My account" tab catches every other account page.
     *
     * @param string                $current Current endpoint ('' = dashboard or not an account page).
     * @param array<string, string> $menu    wc_get_account_menu_items().
     * @param array<string, bool>   $ctx     account, logged_in, front, shop, cart.
     * @param array<string, mixed>  $opts    VKL_Dash_Options::get().
     *
     * @return array<int, array{key: string, label: string, endpoint: string, active: bool}>
     */
    public static function tabbar_items(string $current, array $menu, array $ctx = [], ?array $opts = null): array
    {
        $opts = $opts ?? VKL_Dash_Options::get();
        $ctx += ['account' => true, 'logged_in' => true, 'front' => false, 'shop' => false, 'cart' => false];
        $tabs = (array) $opts['tabs'];
        $keys = array_values(array_filter(VKL_Dash_Options::tab_keys(), static fn($k): bool => !empty($tabs[$k]['on'])));

        usort($keys, static fn($a, $b): int => (int) $tabs[$a]['pos'] <=> (int) $tabs[$b]['pos']);

        $out = [];

        foreach ($keys as $key) {
            $endpoint = self::TAB_ENDPOINTS[$key] ?? '';

            if ('' !== $endpoint && (!$ctx['logged_in'] || !isset($menu[$endpoint]))) {
                continue;
            }

            $label = trim((string) ($tabs[$key]['label'] ?? ''));

            if ('custom' === $key && ('' === (string) $opts['custom_url'] || '' === $label)) {
                continue;
            }

            switch ($key) {
                case 'home':
                    $active = 'site' === $opts['home_target'] ? $ctx['front'] : ($ctx['account'] && '' === $current);
                    break;
                case 'shop':
                    $active = $ctx['shop'];
                    break;
                case 'cart':
                    $active = $ctx['cart'];
                    break;
                default:
                    $active = '' !== $endpoint && $ctx['account'] && in_array($current, self::TAB_COVERS[$key], true);
            }

            $out[] = [
                'key'      => $key,
                'label'    => '' !== $label ? $label : VKL_Dash_Options::tab_default_label($key),
                'endpoint' => $endpoint,
                'active'   => (bool) $active,
            ];

            if (count($out) >= VKL_Dash_Options::MAX_TABS) {
                break;
            }
        }

        if ($ctx['account'] && !in_array(true, array_column($out, 'active'), true)) {
            foreach ($out as $i => $item) {
                if ('dashboard' === $item['key']) {
                    $out[$i]['active'] = true;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Where a tab goes.
     *
     * @param array{key: string, endpoint: string} $item
     * @param array<string, mixed>                 $opts
     */
    public static function tab_url(array $item, array $opts): string
    {
        switch ($item['key']) {
            case 'home':
                return 'site' === $opts['home_target'] ? home_url('/') : (string) wc_get_page_permalink('myaccount');
            case 'shop':
                return (string) wc_get_page_permalink('shop');
            case 'cart':
                return (string) wc_get_cart_url();
            case 'dashboard':
                return (string) wc_get_page_permalink('myaccount');
            case 'custom':
                return (string) $opts['custom_url'];
        }

        return (string) wc_get_account_endpoint_url($item['endpoint']);
    }

    /**
     * The tabs shown on this page, or [] when the bar stays off here:
     * switched off, outside the account area while limited to it, for
     * guests when they are excluded, on the sign-in and logout screens,
     * and on checkout when the admin hides it there.
     *
     * @return array<int, array{key: string, label: string, endpoint: string, active: bool}>
     */
    public static function current_bar(): array
    {
        if (null !== self::$bar) {
            return self::$bar;
        }

        self::$bar = [];
        $opts      = VKL_Dash_Options::get();

        if (empty($opts['bar_enabled']) || !function_exists('is_account_page') || !function_exists('WC') || !WC()->query) {
            return self::$bar;
        }

        $logged  = is_user_logged_in();
        $account = is_account_page();
        $current = $account ? (string) WC()->query->get_current_endpoint() : '';

        if ('customer-logout' === $current || ($account && !$logged)) {
            return self::$bar; // Logout, and the sign-in screen with its own layout.
        }

        if (('account' === $opts['bar_scope'] && !$account) || (!$logged && empty($opts['bar_guests']))) {
            return self::$bar;
        }

        $checkout = function_exists('is_checkout') && is_checkout();

        if ($checkout && !empty($opts['hide_checkout'])) {
            return self::$bar;
        }

        $ctx = [
            'account'   => $account,
            'logged_in' => $logged,
            'front'     => function_exists('is_front_page') && is_front_page(),
            'shop'      => (function_exists('is_shop') && is_shop())
                || (function_exists('is_product_taxonomy') && is_product_taxonomy())
                || (function_exists('is_product') && is_product()),
            'cart'      => (function_exists('is_cart') && is_cart()) || $checkout,
        ];

        $items     = self::tabbar_items($current, wc_get_account_menu_items(), $ctx, $opts);
        self::$bar = count($items) >= 2 ? $items : [];

        return self::$bar;
    }

    /**
     * Forget the memoised tabs (tests render several pages per run).
     */
    public static function flush_bar(): void
    {
        self::$bar = null;
    }

    /**
     * Whether WoodMart's own sticky toolbar steps aside on this page:
     * the admin chose to hide it and our bar is shown here instead.
     */
    private static function hides_theme_toolbar(): bool
    {
        return function_exists('did_action') && did_action('wp')
            && 'hide' === VKL_Dash_Options::get()['theme_toolbar']
            && [] !== self::current_bar();
    }

    /**
     * WoodMart reads its options through woodmart_get_opt(), filtered by
     * "woodmart_option": answer "off" for the sticky toolbar so the theme
     * does not print it at all where our bar is the mobile navigation.
     *
     * @param mixed  $value Option value.
     * @param string $slug  Option slug.
     *
     * @return mixed
     */
    public function theme_toolbar_option($value, $slug = '')
    {
        if ('sticky_toolbar' === $slug && self::hides_theme_toolbar()) {
            return false;
        }

        return $value;
    }

    /**
     * Body classes: vkl-has-tabbar where the bar shows; WoodMart's
     * sticky-toolbar-on is dropped where its toolbar steps aside.
     *
     * @param array<int, string> $classes Body classes.
     *
     * @return array<int, string>
     */
    public function tabbar_body_class($classes): array
    {
        $classes = is_array($classes) ? $classes : [];

        if (!function_exists('did_action') || !did_action('wp') || [] === self::current_bar()) {
            return $classes;
        }

        $classes[] = 'vkl-has-tabbar';

        if (self::hides_theme_toolbar()) {
            $classes = array_values(array_diff($classes, ['sticky-toolbar-on']));
        }

        return $classes;
    }

    /**
     * Cart badge markup (always present so AJAX fragments can replace it).
     */
    private static function cart_badge(int $count): string
    {
        return sprintf(
            '<b class="vkl-tabbar-count" aria-hidden="true"%s>%s</b>',
            $count > 0 ? '' : ' hidden',
            esc_html(function_exists('number_format_i18n') ? number_format_i18n($count) : (string) $count)
        );
    }

    private static function cart_count(): int
    {
        return function_exists('WC') && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
    }

    /**
     * Keep the cart badge current after AJAX add-to-cart.
     *
     * @param mixed $fragments WooCommerce cart fragments.
     *
     * @return mixed
     */
    public function cart_fragment($fragments)
    {
        if (is_array($fragments) && !empty(VKL_Dash_Options::get()['cart_badge'])) {
            $fragments['b.vkl-tabbar-count'] = self::cart_badge(self::cart_count());
        }

        return $fragments;
    }

    /**
     * Whether the shop chatbot widget is shown to this (logged-in) customer.
     */
    public static function chatbot_on(): bool
    {
        try {
            return function_exists('vkl') && vkl()->has('config') && (bool) vkl('config')->get_bool('chatbot_enabled', false);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The shop's main navigation on phones: a floating (or docked) tab
     * bar on every page, or only in the account area, as set under
     * Settings › Mobile tab bar. WoodMart's own sticky toolbar is turned
     * off where this bar shows, unless the admin keeps it — then the bar
     * floats above it. Holds the chatbot button: where the bar shows,
     * the chat opens from it instead of its separate round button.
     */
    public function account_tabbar(): void
    {
        $items = self::current_bar();

        if ([] === $items) {
            return;
        }

        $opts   = VKL_Dash_Options::get();
        $chat   = self::chatbot_on();
        $badge  = !empty($opts['cart_badge']) && in_array('cart', array_column($items, 'key'), true);
        $bp     = (int) $opts['bar_breakpoint'];
        $color  = (string) $opts['bar_color'];
        $docked = 'docked' === $opts['bar_style'];
        $theme  = 'keep' === $opts['theme_toolbar'];
        $icons  = [
            'home'      => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
            'shop'      => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
            'cart'      => '<circle cx="9" cy="20" r="1.3"/><circle cx="18" cy="20" r="1.3"/><path d="M2.5 3h2.6l2.3 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.1L21 7H6"/>',
            'dashboard' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'purchases' => '<path d="M6 7h12l-1 13H7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
            'wallet'    => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="16.5" cy="14.5" r="1.2"/>',
            'support'   => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.5h8M8 12.5h5"/>',
            'account'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16a3.4 3.4 0 0 1 6.4 0M14 10h4M14 13.5h3"/>',
            'chat'      => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/><circle cx="8.5" cy="12" r=".6"/><circle cx="12" cy="12" r=".6"/><circle cx="15.5" cy="12" r=".6"/>',
            'star'      => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
            'heart'     => '<path d="M12 20s-7-4.4-9-8.6C1.6 8.3 3.6 5 7 5c2 0 3.3 1.1 5 3 1.7-1.9 3-3 5-3 3.4 0 5.4 3.3 4 6.4C19 15.6 12 20 12 20z"/>',
            'gift'      => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8S10.5 3.5 8 4.5 9 8 12 8zm0 0s1.5-4.5 4-3.5S15 8 12 8z"/>',
            'tag'       => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.3"/>',
            'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
            'phone'     => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
        ];
        $svg = static fn(string $key): string => '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$key] ?? $icons['star']) . '</svg>';
        $mq  = '(max-width:' . $bp . 'px)';
        ?>
        <nav class="vkl-tabbar<?php echo $docked ? ' vkl-tabbar--docked' : ''; ?>" aria-label="<?php esc_attr_e('Main menu', 'vereskala-login'); ?>">
            <?php foreach ($items as $item) : ?>
                <?php $vkl_icon = 'custom' === $item['key'] ? (string) $opts['custom_icon'] : $item['key']; ?>
                <a href="<?php echo esc_url(self::tab_url($item, $opts)); ?>"
                   class="<?php echo $item['active'] ? 'is-active' : ''; ?>"<?php echo $item['active'] ? ' aria-current="page"' : ''; ?>>
                    <i class="vkl-tabbar-ico"><?php echo $svg($vkl_icon); // phpcs:ignore -- static markup. ?><?php echo 'cart' === $item['key'] && $badge ? self::cart_badge(self::cart_count()) : ''; // phpcs:ignore -- escaped inside. ?></i>
                    <span><?php echo esc_html($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($chat) : ?>
                <button type="button" class="vkl-tabbar-chat" aria-expanded="false" aria-controls="vkl-chat-panel" hidden>
                    <i class="vkl-tabbar-ico vkl-tabbar-avatar"><img src="<?php echo esc_url(VKL_URL . 'assets/chat-assistant.svg?ver=' . VKL_VERSION); ?>" width="32" height="32" alt="" decoding="async"></i>
                    <span><?php echo esc_html_x('Chat', 'account tab bar', 'vereskala-login'); ?></span>
                </button>
            <?php endif; ?>
        </nav>
        <style id="vkl-tabbar">
            .vkl-tabbar{display:none}
            @media <?php echo $mq; // phpcs:ignore -- integer. ?>{
                /* Floating bar: lifted off the edge<?php echo $theme ? ', above the theme\'s sticky toolbar when that one is on' : ''; ?>. */
                .vkl-tabbar{--vkl-tb-c:<?php echo esc_html($color); ?>;--vkl-tb-gap:10px;--vkl-tb-base:env(safe-area-inset-bottom,0px);
                    position:fixed;z-index:351;display:flex;gap:2px;
                    inset-inline:max(12px,env(safe-area-inset-left,0px));bottom:calc(var(--vkl-tb-base) + var(--vkl-tb-gap));
                    padding:4px;background:rgba(255,255,255,.96);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);
                    border:1px solid rgba(0,0,0,.06);border-radius:20px;box-shadow:0 8px 28px rgba(16,42,34,.16)}
                /* Docked: full width on the bottom edge, safe area inside. */
                .vkl-tabbar--docked{inset-inline:0;bottom:0;border-radius:18px 18px 0 0;border-width:1px 0 0;
                    padding:4px max(6px,env(safe-area-inset-right,0px)) calc(4px + env(safe-area-inset-bottom,0px)) max(6px,env(safe-area-inset-left,0px));
                    box-shadow:0 -4px 20px rgba(16,42,34,.10)}
                <?php if ($theme) : ?>
                body.sticky-toolbar-on .vkl-tabbar{--vkl-tb-base:55px}
                body.sticky-toolbar-on .vkl-tabbar--docked{bottom:55px;border-radius:18px}
                <?php else : ?>
                /* This bar is the mobile navigation: the theme's toolbar steps aside. */
                body.vkl-has-tabbar .wd-toolbar{display:none !important}
                <?php endif; ?>
                .vkl-tabbar a,.vkl-tabbar button{flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 2px 5px;
                    color:#7a7a7a;text-decoration:none;font:inherit;font-size:11px;font-weight:600;line-height:1.2;border:0;background:none;
                    border-radius:16px;cursor:pointer;-webkit-tap-highlight-color:transparent}
                .vkl-tabbar button[hidden]{display:none}
                .vkl-tabbar-ico{position:relative;display:inline-flex;font-style:normal}
                .vkl-tabbar-count{position:absolute;top:-5px;inset-inline-end:-9px;min-width:17px;height:17px;padding:0 4px;border-radius:9px;
                    background:#e5262c;color:#fff;font-size:10px;font-weight:700;line-height:17px;text-align:center;box-shadow:0 0 0 2px #fff}
                .vkl-tabbar-count[hidden]{display:none}
                .vkl-tabbar span{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
                .vkl-tabbar .is-active{color:var(--vkl-tb-c);background:#eef8f1;background:color-mix(in srgb,var(--vkl-tb-c) 9%,#fff)}
                .vkl-tabbar .is-active svg{stroke-width:2.2}
                /* Chat tab: the assistant's avatar, lifted slightly out of the bar. */
                .vkl-tabbar-avatar img{display:block;width:32px;height:32px;margin-top:-12px;border-radius:50%;
                    border:2px solid #fff;box-shadow:0 3px 10px rgba(16,42,34,.25);transition:transform .15s ease}
                .vkl-tabbar .vkl-tabbar-chat{padding-bottom:3px}
                .vkl-tabbar .vkl-tabbar-chat[aria-expanded="true"]{color:var(--vkl-tb-c);background:#eef8f1;background:color-mix(in srgb,var(--vkl-tb-c) 9%,#fff)}
                .vkl-tabbar .vkl-tabbar-chat[aria-expanded="true"] .vkl-tabbar-avatar img{transform:scale(1.08);box-shadow:0 0 0 2px var(--vkl-tb-c),0 3px 10px rgba(16,42,34,.25)}
                @media (prefers-reduced-motion:reduce){.vkl-tabbar-avatar img{transition:none}}
                .vkl-tabbar a:focus-visible,.vkl-tabbar button:focus-visible{outline:2px solid var(--vkl-tb-c);outline-offset:-2px}
                /* Room under the content for the bar (≈60px) and its gap. */
                body.vkl-has-tabbar{padding-bottom:calc(<?php echo $docked ? '64' : '80'; ?>px + env(safe-area-inset-bottom,0px)) !important}
                body.vkl-has-tabbar .scrollToTop,
                body.vkl-has-tabbar .vkl-pwa-card{bottom:calc(<?php echo $docked ? '72' : '84'; ?>px + env(safe-area-inset-bottom,0px)) !important}
                /* Product page: the theme's sticky "add to cart" bar sits right
                   above this bar instead of under it, and slides fully out of
                   sight when hidden (the theme only moves it down by its own
                   height, which would leave it peeking out behind this bar). */
                body.vkl-has-tabbar .wd-sticky-btn{--vkl-sb-lift:calc(<?php echo $docked ? '60' : '76'; ?>px + env(safe-area-inset-bottom,0px));bottom:var(--vkl-sb-lift) !important}
                body.vkl-has-tabbar .wd-sticky-btn:not(.wd-sticky-btn-shown){transform:translate3d(0,calc(100% + var(--vkl-sb-lift) + 20px),0) !important}
                body.vkl-has-tabbar.wd-sticky-btn-on-mb{padding-bottom:calc(<?php echo $docked ? '64' : '80'; ?>px + var(--wd-sticky-btn-height,42px) + env(safe-area-inset-bottom,0px)) !important}
                body.vkl-has-tabbar .wd-sticky-btn-shown.scrollToTop{bottom:calc(<?php echo $docked ? '72' : '84'; ?>px + var(--wd-sticky-btn-height,42px) + env(safe-area-inset-bottom,0px)) !important}
                <?php if ($theme) : ?>
                body.vkl-has-tabbar.sticky-toolbar-on{padding-bottom:calc(<?php echo $docked ? '119' : '135'; ?>px + env(safe-area-inset-bottom,0px)) !important}
                body.vkl-has-tabbar.sticky-toolbar-on .scrollToTop,
                body.vkl-has-tabbar.sticky-toolbar-on .vkl-pwa-card{bottom:calc(<?php echo $docked ? '127' : '139'; ?>px + env(safe-area-inset-bottom,0px)) !important}
                body.vkl-has-tabbar.sticky-toolbar-on .wd-sticky-btn{--vkl-sb-lift:calc(<?php echo $docked ? '115' : '131'; ?>px + env(safe-area-inset-bottom,0px))}
                body.vkl-has-tabbar.sticky-toolbar-on.wd-sticky-btn-on-mb{padding-bottom:calc(<?php echo $docked ? '119' : '135'; ?>px + var(--wd-sticky-btn-height,42px) + env(safe-area-inset-bottom,0px)) !important}
                body.vkl-has-tabbar.sticky-toolbar-on .wd-sticky-btn-shown.scrollToTop{bottom:calc(<?php echo $docked ? '127' : '139'; ?>px + var(--wd-sticky-btn-height,42px) + env(safe-area-inset-bottom,0px)) !important}
                <?php endif; ?>
                /* The chat lives in the bar here: its round button steps aside
                   and the panel opens right above the bar (see script). */
                body.vkl-chat-in-bar #vkl-chat-toggle{display:none !important}
                body.vkl-chat-in-bar #vkl-chat-panel{bottom:0 !important}
            }
        </style>
        <?php if ($chat) : ?>
        <script>
        (function () {
            var bar = document.querySelector('.vkl-tabbar');
            var btn = bar && bar.querySelector('.vkl-tabbar-chat');
            if (!btn) { return; }
            var mq = window.matchMedia(<?php echo wp_json_encode($mq); ?>);

            function ready() {
                var root   = document.getElementById('vkl-chat');
                var toggle = document.getElementById('vkl-chat-toggle');
                var panel  = document.getElementById('vkl-chat-panel');
                if (!root || !toggle || !panel) { return; } // Widget not on this page.

                btn.hidden = false;
                var saved = null; // The bubble's own (possibly dragged) position.

                // Open the chat right above the floating bar.
                function place() {
                    if (!mq.matches) {
                        document.body.classList.remove('vkl-chat-in-bar');
                        if (null !== saved) { root.style.setProperty('bottom', saved, 'important'); saved = null; }
                        return;
                    }
                    document.body.classList.add('vkl-chat-in-bar');
                    if (null === saved) { saved = root.style.getPropertyValue('bottom'); }
                    var top = bar.getBoundingClientRect().top;
                    root.style.setProperty('bottom', Math.round(window.innerHeight - top + 8) + 'px', 'important');
                }

                function sync() {
                    var open = 'none' !== panel.style.display && '' !== panel.style.display;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                }

                btn.addEventListener('click', function () {
                    place();
                    toggle.click(); // Same open/close path as the round button.
                    sync();
                });

                new MutationObserver(sync).observe(panel, {attributes: true, attributeFilter: ['style']});
                window.addEventListener('resize', place);
                if (mq.addEventListener) { mq.addEventListener('change', place); }
                place();
                sync();
            }

            if ('loading' === document.readyState) {
                document.addEventListener('DOMContentLoaded', ready);
            } else {
                ready();
            }
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * App-style account chrome: the horizontal endpoint toolbar is
     * removed everywhere (the dashboard's own menu rows replace it) and
     * inner pages get a back arrow to the dashboard instead.
     */
    public function account_styles(): void
    {
        if (!function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        ?>
        <style>
            .woocommerce-account .page-title,
            .woocommerce-account .entry-title,
            .woocommerce-account .page-title-default,
            .woocommerce-account .woodmart-page-title,
            .woocommerce-account header.entry-header{display:none !important}
            .woocommerce-account .woocommerce-MyAccount-navigation{display:none !important}
            .woocommerce-account .woocommerce-MyAccount-content{width:100% !important;max-width:100% !important;float:none !important;flex:0 0 100% !important}
            /* The theme's grid keeps a column reserved for the (hidden)
               navigation — collapse it and stretch the content column so
               nothing sits against an empty gutter. */
            .woocommerce-account [class*="col-"]:has(> .woocommerce-MyAccount-navigation),
            .woocommerce-account [class*="col"]:has(> .woocommerce-MyAccount-navigation){display:none !important}
            .woocommerce-account [class*="col-"]:has(.woocommerce-MyAccount-content),
            .woocommerce-account [class*="col"]:has(.woocommerce-MyAccount-content){width:100% !important;max-width:100% !important;flex:0 0 100% !important}
            /* Tablet/desktop: the dashboard reads as a centered app column. */
            .vkl-dash{max-width:860px;margin-inline:auto}
            .woocommerce-account .vkl-back-bar{max-width:860px;margin-inline:auto;margin-bottom:14px}
            .vkl-back-bar{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #ececec;border-radius:14px;padding:12px 16px;margin:0 0 14px;text-decoration:none;color:#157f3a;font-weight:700}
            .vkl-back-bar:hover{background:#f1faf4;color:#0e5f29}
            .vkl-acc-tabs{display:flex;gap:6px;max-width:860px;margin:0 auto 16px;padding:4px;background:#f3f4f3;border-radius:12px;overflow-x:auto;scrollbar-width:none}
            .vkl-acc-tabs a{flex:1 0 auto;text-align:center;padding:9px 14px;border-radius:9px;color:#444;text-decoration:none;font-size:14px;white-space:nowrap}
            .vkl-acc-tabs a:hover{color:#157f3a}
            .vkl-acc-tabs a.is-active{background:#fff;color:#157f3a;font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,.08)}
            .vkl-acc-tabs a:focus-visible{outline:2px solid #157f3a;outline-offset:1px}
            .vkl-back-bar .arr{font-size:19px;line-height:1}
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Fallback for browsers without :has(): collapse the nav's
            // grid column and stretch the content's column directly.
            try {
                if (window.CSS && CSS.supports && CSS.supports('selector(:has(a))')) { return; }
            } catch (e) {}
            var nav = document.querySelector('.woocommerce-MyAccount-navigation');
            if (nav && nav.parentElement && /col/.test(nav.parentElement.className)) {
                nav.parentElement.style.display = 'none';
            }
            var content = document.querySelector('.woocommerce-MyAccount-content');
            if (content) {
                var col = content.closest('[class*="col"]');
                if (col) {
                    col.style.setProperty('width', '100%', 'important');
                    col.style.setProperty('max-width', '100%', 'important');
                    col.style.setProperty('flex', '0 0 100%', 'important');
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Back-to-dashboard arrow above every inner account page.
     */
    public function account_back_bar(): void
    {
        // The dashboard itself needs no back arrow.
        if (!is_wc_endpoint_url() || is_wc_endpoint_url('customer-logout')) {
            return;
        }

        printf(
            '<a class="vkl-back-bar" href="%s"><span class="arr">&#8594;</span>%s</a>',
            esc_url(wc_get_page_permalink('myaccount')),
            esc_html__('Back to dashboard', 'vereskala-login')
        );

        $this->account_group_tabs();
    }

    /**
     * Tabs across the endpoints of one account group (e.g. Wallet |
     * Purchase credit & installments), so related pages read as one
     * section. Shown only when the group has two or more tabs.
     */
    private function account_group_tabs(): void
    {
        if (!function_exists('WC') || !WC()->query) {
            return;
        }

        $current = (string) WC()->query->get_current_endpoint();
        $tabs    = self::group_tabs_for($current, wc_get_account_menu_items());

        if (count($tabs) < 2) {
            return;
        }

        echo '<nav class="vkl-acc-tabs" aria-label="' . esc_attr__('Sections', 'vereskala-login') . '">';

        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
                esc_url(wc_get_account_endpoint_url($slug)),
                $slug === $current ? 'is-active' : '',
                $slug === $current ? ' aria-current="page"' : '',
                esc_html($label)
            );
        }

        echo '</nav>';
    }

    /**
     * The tabs (slug => label) of the group that contains $endpoint,
     * limited to items present in the account menu.
     *
     * @param array<string, string> $menu wc_get_account_menu_items().
     *
     * @return array<string, string>
     */
    public static function group_tabs_for(string $endpoint, array $menu): array
    {
        foreach (self::account_groups() as $group) {
            if (!in_array($endpoint, $group['items'], true)) {
                continue;
            }

            $tabs = [];

            foreach ($group['items'] as $slug) {
                if (isset($menu[$slug])) {
                    $tabs[$slug] = $menu[$slug];
                }
            }

            return $tabs;
        }

        return [];
    }

    /**
     * Swap the default My Account dashboard for the app-style layout
     * (name header, balance tiles, order statuses, menu rows).
     *
     * @param string $template      Located template path.
     * @param string $template_name Relative template name.
     */
    public function dashboard_template(string $template, string $template_name): string
    {
        if ('myaccount/dashboard.php' === $template_name) {
            $custom = VKL_PATH . 'templates/account-dashboard.php';

            if (is_readable($custom)) {
                return $custom;
            }
        }

        return $template;
    }

    /**
     * The saved-payment-methods tab is meaningless with Iranian gateways
     * (no tokenised cards) and always renders empty — remove it.
     *
     * @param array<string, string> $items Menu items.
     *
     * @return array<string, string>
     */
    public function remove_payment_methods(array $items): array
    {
        // Persian wording for core rows (سپارش به‌جای سفارش، نشانی‌ها و…).
        foreach ([
            'orders'          => __('Orders', 'vereskala-login'),
            'downloads'       => __('Downloads', 'vereskala-login'),
            'edit-address'    => __('Addresses', 'vereskala-login'),
            'edit-account'    => __('Account details', 'vereskala-login'),
            'customer-logout' => __('Log out', 'vereskala-login'),
        ] as $vkl_slug => $vkl_label) {
            if (isset($items[$vkl_slug])) {
                $items[$vkl_slug] = $vkl_label;
            }
        }

        unset($items['payment-methods']);

        return self::order_items($items);
    }

    /**
     * Customer-facing groups for the My Account menu, in display order.
     * The single place that decides where an account tab appears: each
     * module only adds its endpoint, so the order no longer depends on
     * which module happened to load first.
     *
     * @return array<int, array{label: string, items: array<int, string>}>
     */
    public static function account_groups(): array
    {
        return [
            ['label' => __('My purchases', 'vereskala-login'), 'items' => ['orders', 'returns', 'downloads']],
            ['label' => __('Wallet & credit', 'vereskala-login'), 'items' => ['wallet', 'credit']],
            ['label' => __('Customer club', 'vereskala-login'), 'items' => ['club', 'surveys']],
            ['label' => __('Support', 'vereskala-login'), 'items' => ['tickets']],
            ['label' => __('My account', 'vereskala-login'), 'items' => ['edit-account', 'edit-address']],
        ];
    }

    /**
     * Reorder menu items by account_groups(): dashboard first, grouped
     * items next, anything unknown (other plugins) after them, logout last.
     *
     * @param array<string, string> $items Menu items.
     *
     * @return array<string, string>
     */
    private static function order_items(array $items): array
    {
        $ordered = [];

        if (isset($items['dashboard'])) {
            $ordered['dashboard'] = $items['dashboard'];
        }

        foreach (self::account_groups() as $group) {
            foreach ($group['items'] as $slug) {
                if (isset($items[$slug])) {
                    $ordered[$slug] = $items[$slug];
                }
            }
        }

        foreach ($items as $slug => $label) {
            if (!isset($ordered[$slug]) && 'customer-logout' !== $slug) {
                $ordered[$slug] = $label;
            }
        }

        if (isset($items['customer-logout'])) {
            $ordered['customer-logout'] = $items['customer-logout'];
        }

        return $ordered;
    }


    /**
     * Tiny site-wide fixes: product-card captions (title/price/brand)
     * must read right-to-left under the images on an RTL shop — the
     * theme leaves some of them left-aligned in carousels.
     */
    public function global_styles(): void
    {
        ?>
        <style id="vkl-global">
            [dir="rtl"] .products .product .woocommerce-loop-product__title,
            [dir="rtl"] .products .product .wd-entities-title,
            [dir="rtl"] .products .product .price,
            [dir="rtl"] .product-grid-item .wd-entities-title,
            [dir="rtl"] .product-grid-item .price,
            [dir="rtl"] .product-grid-item .wd-product-brands-links,
            [dir="rtl"] .wd-carousel .wd-entities-title,
            [dir="rtl"] .wd-carousel .price{
                text-align:right !important;direction:rtl;
            }
        </style>
        <?php
    }

    /**
     * Whether to print the stylesheet: every My Account view, so all tabs
     * (WooCommerce's own and Vereskonto's) share one polished look.
     */
    private function is_vkl_endpoint(): bool
    {
        return function_exists('is_account_page') && is_account_page();
    }

    /**
     * Print the scoped stylesheet.
     */
    public function styles(): void
    {
        if (!$this->is_vkl_endpoint()) {
            return;
        }
        ?>
        <style id="vkl-account-ui">
            :root{--vkl-brand:#157f3a;--vkl-brand-dark:#0e5f29;--vkl-accent:#f5a623;--vkl-line:#e6e6e6}
            .woocommerce-MyAccount-content h3{border-inline-start:4px solid var(--vkl-brand);padding-inline-start:10px;margin:26px 0 14px;font-size:1.1em}
            .woocommerce-MyAccount-content table{border-collapse:separate;border-spacing:0;width:100%;border:1px solid var(--vkl-line);border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04)}
            .woocommerce-MyAccount-content table th{background:#f1faf4;color:#333;font-weight:600;padding:10px 12px}
            .woocommerce-MyAccount-content table td{padding:10px 12px;border-top:1px solid #f0f0f0}
            .woocommerce-MyAccount-content table tr:nth-child(even) td{background:#fafbfb}
            .woocommerce-MyAccount-content form{background:#fff}
            .woocommerce-MyAccount-content .button,
            .woocommerce-MyAccount-content button[type=submit]{background:var(--vkl-brand);color:#fff;border:0;border-radius:8px;padding:9px 20px;transition:background .15s}
            .woocommerce-MyAccount-content .button:hover,
            .woocommerce-MyAccount-content button[type=submit]:hover{background:var(--vkl-brand-dark);color:#fff}
            .woocommerce-MyAccount-content input[type=text],
            .woocommerce-MyAccount-content input[type=number],
            .woocommerce-MyAccount-content input[type=tel],
            .woocommerce-MyAccount-content textarea,
            .woocommerce-MyAccount-content select{border:1px solid var(--vkl-line);border-radius:8px;padding:8px 10px}
            .woocommerce-MyAccount-content input:focus,
            .woocommerce-MyAccount-content textarea:focus,
            .woocommerce-MyAccount-content select:focus{border-color:var(--vkl-brand);box-shadow:0 0 0 2px rgba(30,111,92,.12);outline:none}
            /* Generic cards the tabs already render (inline-styled divs get upgraded borders). */
            .woocommerce-MyAccount-content div[style*="border:1px solid #e2e2e2"]{border-color:var(--vkl-line) !important;box-shadow:0 1px 4px rgba(0,0,0,.05);transition:box-shadow .15s}
            .woocommerce-MyAccount-content div[style*="border:1px solid #e2e2e2"]:hover{box-shadow:0 3px 10px rgba(0,0,0,.09)}
            /* Status words used across the tabs, as pills. */
            .vkl-badge{display:inline-block;border-radius:12px;padding:2px 10px;font-size:.82em;background:#eaf9ef;color:var(--vkl-brand)}
            .vkl-badge--warn{background:#fdf3e2;color:#9a6a10}
            /* Notices on our tabs. */
            .woocommerce-message{border-top-color:var(--vkl-brand)}
            /* Survey stars. */
            .vkl-stars{direction:ltr;display:inline-flex;gap:2px}
            .vkl-stars input{position:absolute;opacity:0;width:0}
            .vkl-stars label{cursor:pointer;font-size:22px;color:#d8d8d8;transition:color .1s}
            .vkl-stars label.vkl-on{color:var(--vkl-accent)}
            /* Account navigation — gentle pills that keep theme colors readable. */
            .woocommerce-MyAccount-navigation ul{border:1px solid var(--vkl-line);border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.04)}
            .woocommerce-MyAccount-navigation li{border-bottom:1px solid #f0f0f0;list-style:none;margin:0}
            .woocommerce-MyAccount-navigation li:last-child{border-bottom:0}
            .woocommerce-MyAccount-navigation li a{display:block;padding:11px 16px;transition:background .12s,padding-inline-start .12s}
            .woocommerce-MyAccount-navigation li a:hover{background:#f1faf4}
            .woocommerce-MyAccount-navigation li.is-active a{background:#eaf9ef;color:var(--vkl-brand);font-weight:600;border-inline-start:4px solid var(--vkl-brand)}
            /* One recognisable icon per tab. */
            .woocommerce-MyAccount-navigation li a::before{margin-inline-end:8px}
            .woocommerce-MyAccount-navigation-link--dashboard a::before{content:'🏠'}
            .woocommerce-MyAccount-navigation-link--orders a::before{content:'📦'}
            .woocommerce-MyAccount-navigation-link--downloads a::before{content:'⬇️'}
            .woocommerce-MyAccount-navigation-link--edit-address a::before{content:'📍'}
            .woocommerce-MyAccount-navigation-link--edit-account a::before{content:'👤'}
            .woocommerce-MyAccount-navigation-link--wallet a::before{content:'💰'}
            .woocommerce-MyAccount-navigation-link--credit a::before{content:'🛒'}
            .woocommerce-MyAccount-navigation-link--tickets a::before{content:'🎫'}
            .woocommerce-MyAccount-navigation-link--club a::before{content:'⭐'}
            .woocommerce-MyAccount-navigation-link--surveys a::before{content:'📝'}
            .woocommerce-MyAccount-navigation-link--customer-logout a::before{content:'🚪'}
            /* Orders table + status pills. */
            .woocommerce-MyAccount-content .woocommerce-orders-table .button{padding:6px 14px;font-size:.9em}
            .woocommerce-MyAccount-content mark{background:#eaf9ef;color:var(--vkl-brand);border-radius:10px;padding:1px 8px}
            /* Address / dashboard cards. */
            .woocommerce-MyAccount-content .woocommerce-Address,
            .woocommerce-MyAccount-content .col-1 address,
            .woocommerce-MyAccount-content .col-2 address{border:1px dashed var(--vkl-line);border-radius:12px;padding:12px 16px}
            .woocommerce-MyAccount-content fieldset{border:1px solid var(--vkl-line);border-radius:12px;padding:12px 16px}
            /* Dashboard shortcut grid: replace the theme's generic gears
               with the same icons as the side navigation. */
            .woocommerce-MyAccount-content [class*="wallet-link"] a::before{content:'💰';font-size:26px;display:block}
            .woocommerce-MyAccount-content [class*="tickets-link"] a::before{content:'🎫';font-size:26px;display:block}
            .woocommerce-MyAccount-content [class*="club-link"] a::before{content:'⭐';font-size:26px;display:block}
            .woocommerce-MyAccount-content [class*="surveys-link"] a::before{content:'📝';font-size:26px;display:block}
            .woocommerce-MyAccount-content [class*="wallet-link"] a > span[class*="icon"],
            .woocommerce-MyAccount-content [class*="tickets-link"] a > span[class*="icon"],
            .woocommerce-MyAccount-content [class*="club-link"] a > span[class*="icon"],
            .woocommerce-MyAccount-content [class*="surveys-link"] a > span[class*="icon"]{display:none}
            /* Insights box containment (iPad Safari flex overflow). */
            .vkl-insights{max-width:100% !important;box-sizing:border-box;overflow-wrap:anywhere;direction:rtl}
            .vkl-insights > div{max-width:100%;min-width:0}
            @media (max-width:600px){
                /* Scroll wide tables without breaking table semantics: the
                   header keeps stretching edge-to-edge (the previous
                   display:block left it hanging half-width). */
                .woocommerce-MyAccount-content table{display:block;overflow-x:auto;white-space:nowrap}
                .woocommerce-MyAccount-content table thead,
                .woocommerce-MyAccount-content table tbody{display:table;width:100%;table-layout:auto}
            }
            @media (max-width:782px){
                /* Compact horizontal tab bar instead of a tall list. */
                .woocommerce-MyAccount-navigation ul{display:flex;overflow-x:auto;white-space:nowrap;border-radius:12px;-webkit-overflow-scrolling:touch;scrollbar-width:thin}
                .woocommerce-MyAccount-navigation li{border-bottom:0;border-inline-end:1px solid #f0f0f0;flex:0 0 auto}
                .woocommerce-MyAccount-navigation li a{padding:10px 14px}
                .woocommerce-MyAccount-navigation li.is-active a{border-inline-start:0;border-bottom:3px solid var(--vkl-brand)}
            }
        </style>
        <?php
    }
}
