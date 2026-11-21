<?php
/**
 * Settings for the customer area: the My Account dashboard and the
 * mobile tab bar. Everything lives in ONE array option (vkl_dash) so
 * the two admin tabs that edit it can never blank each other: each form
 * posts a "_part" marker and sanitize() only replaces that part.
 *
 * @package Vereskonto\Includes
 * @since   2.15.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Dash_Options
 */
final class VKL_Dash_Options
{
    public const OPTION = 'vkl_dash';
    public const GROUP  = 'vkl_dash_settings';

    /**
     * Most tabs the bar holds (the chat button comes on top).
     */
    public const MAX_TABS = 6;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cache = null;

    /**
     * Every tab the bar can show, in catalog order.
     *
     * @return array<int, string>
     */
    public static function tab_keys(): array
    {
        return ['home', 'shop', 'cart', 'dashboard', 'purchases', 'wallet', 'support', 'account', 'custom'];
    }

    /**
     * Label a tab shows when the admin left its label empty.
     */
    public static function tab_default_label(string $key): string
    {
        switch ($key) {
            case 'home':
                return _x('Home', 'account tab bar', 'vereskala-login');
            case 'shop':
                return __('Shop', 'vereskala-login');
            case 'cart':
                return __('Cart', 'vereskala-login');
            case 'dashboard':
                return __('My account', 'vereskala-login');
            case 'purchases':
                return __('Purchases', 'vereskala-login');
            case 'wallet':
                return __('Wallet', 'vereskala-login');
            case 'support':
                return __('Support', 'vereskala-login');
            case 'account':
                return __('Account', 'vereskala-login');
        }

        return '';
    }

    /**
     * Dashboard sections whose visibility and (mobile) order are set.
     *
     * @return array<int, string>
     */
    public static function sections(): array
    {
        return ['notice', 'head', 'orders', 'banner', 'menu', 'repeat'];
    }

    /**
     * Account menu slugs whose row icon can be replaced or row hidden.
     *
     * @return array<int, string>
     */
    public static function row_slugs(): array
    {
        return ['orders', 'returns', 'downloads', 'wallet', 'credit', 'club', 'surveys', 'tickets', 'edit-account', 'edit-address'];
    }

    /**
     * Icons offered for the custom tab.
     *
     * @return array<int, string>
     */
    public static function custom_icons(): array
    {
        return ['star', 'heart', 'gift', 'tag', 'search', 'phone'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        // The bar is the shop's main mobile navigation: home, shop, cart,
        // wallet and the account dashboard out of the box.
        $on   = ['home' => 1, 'shop' => 2, 'cart' => 3, 'wallet' => 4, 'dashboard' => 5];
        $tabs = [];

        foreach (self::tab_keys() as $i => $key) {
            $tabs[$key] = ['on' => isset($on[$key]), 'label' => '', 'pos' => $on[$key] ?? 10 + $i];
        }

        return [
            // Mobile tab bar.
            'bar_enabled'    => true,
            'bar_scope'      => 'site',
            'bar_guests'     => true,
            'bar_style'      => 'floating',
            'theme_toolbar'  => 'hide',
            'home_target'    => 'site',
            'cart_badge'     => true,
            'hide_checkout'  => true,
            'bar_color'      => '#157f3a',
            'bar_breakpoint' => 768,
            'tabs'           => $tabs,
            'custom_url'     => '',
            'custom_icon'    => 'star',
            // Dashboard.
            'brand_color'    => '#157f3a',
            'flag'           => true,
            'layout'         => 'two',
            'greeting'       => '',
            'notice'         => '',
            'show'           => [
                'notice'      => true,
                'head'        => true,
                'tiles'       => true,
                'tile_wallet' => true,
                'tile_points' => true,
                'tile_credit' => true,
                'orders'      => true,
                'banner'      => true,
                'menu'        => true,
                'logout'      => true,
                'repeat'      => true,
            ],
            'order'          => ['notice' => 0, 'head' => 1, 'orders' => 2, 'banner' => 3, 'menu' => 4, 'repeat' => 5],
            'banner_mode'    => 'auto',
            'banner_emoji'   => '🎁',
            'banner_text'    => '',
            'banner_label'   => '',
            'banner_url'     => '',
            'hidden_rows'    => [],
            'icons'          => [],
            'repeat_title'   => '',
            'repeat_limit'   => 10,
        ];
    }

    /**
     * Saved settings over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (null === self::$cache) {
            $stored      = function_exists('get_option') ? get_option(self::OPTION, []) : [];
            self::$cache = self::merge(is_array($stored) ? $stored : []);
        }

        return self::$cache;
    }

    /**
     * Forget the per-request copy (after a save, or between tests).
     */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function merge(array $values): array
    {
        $out = array_replace_recursive(self::defaults(), $values);

        // Lists replace, they never merge by index.
        $out['hidden_rows'] = isset($values['hidden_rows']) && is_array($values['hidden_rows']) ? array_values($values['hidden_rows']) : [];

        return $out;
    }

    /**
     * register_setting() callback. A form posts one part ("bar" or
     * "dash"); the other part is kept as stored. Input without a part is
     * an already-sanitized array (WordPress runs the callback twice when
     * the option is first created) and is only normalised.
     *
     * @param mixed $input Posted value.
     *
     * @return array<string, mixed>
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $part  = isset($input['_part']) ? (string) $input['_part'] : '';

        if ('' === $part) {
            return self::merge($input);
        }

        self::flush();
        $out = self::get();

        if ('bar' === $part) {
            $out = self::sanitize_bar($input, $out);
        } elseif ('dash' === $part) {
            $out = self::sanitize_dash($input, $out);
        }

        self::flush();

        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $out
     *
     * @return array<string, mixed>
     */
    private static function sanitize_bar(array $in, array $out): array
    {
        $out['bar_enabled']    = !empty($in['bar_enabled']);
        $out['bar_scope']      = self::pick($in['bar_scope'] ?? '', ['site', 'account'], 'site');
        $out['bar_guests']     = !empty($in['bar_guests']);
        $out['bar_style']      = self::pick($in['bar_style'] ?? '', ['floating', 'docked'], 'floating');
        $out['theme_toolbar']  = self::pick($in['theme_toolbar'] ?? '', ['hide', 'keep'], 'hide');
        $out['home_target']    = self::pick($in['home_target'] ?? '', ['site', 'dashboard'], 'site');
        $out['cart_badge']     = !empty($in['cart_badge']);
        $out['hide_checkout']  = !empty($in['hide_checkout']);
        $out['bar_color']      = self::color($in['bar_color'] ?? '', '#157f3a');
        $out['bar_breakpoint'] = self::clamp($in['bar_breakpoint'] ?? 768, 480, 1200, 768);
        $out['custom_url']     = self::url($in['custom_url'] ?? '');
        $out['custom_icon']    = self::pick($in['custom_icon'] ?? '', self::custom_icons(), 'star');

        $tabs = is_array($in['tabs'] ?? null) ? $in['tabs'] : [];
        $on   = 0;

        foreach (self::tab_keys() as $i => $key) {
            $row  = is_array($tabs[$key] ?? null) ? $tabs[$key] : [];
            $want = !empty($row['on']) && $on < self::MAX_TABS;
            $on  += $want ? 1 : 0;

            $out['tabs'][$key] = [
                'on'    => $want,
                'label' => self::text($row['label'] ?? '', 24),
                'pos'   => self::clamp($row['pos'] ?? (10 + $i), 0, 99, 10 + $i),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, mixed> $out
     *
     * @return array<string, mixed>
     */
    private static function sanitize_dash(array $in, array $out): array
    {
        $out['brand_color']  = self::color($in['brand_color'] ?? '', '#157f3a');
        $out['flag']         = !empty($in['flag']);
        $out['layout']       = self::pick($in['layout'] ?? '', ['two', 'one'], 'two');
        $out['greeting']     = self::text($in['greeting'] ?? '', 80);
        $out['notice']       = function_exists('wp_kses_post') ? trim(wp_kses_post((string) ($in['notice'] ?? ''))) : '';
        $out['banner_mode']  = self::pick($in['banner_mode'] ?? '', ['auto', 'custom', 'off'], 'auto');
        $out['banner_emoji'] = self::text($in['banner_emoji'] ?? '', 8);
        $out['banner_text']  = self::text($in['banner_text'] ?? '', 300);
        $out['banner_label'] = self::text($in['banner_label'] ?? '', 30);
        $out['banner_url']   = self::url($in['banner_url'] ?? '');
        $out['repeat_title'] = self::text($in['repeat_title'] ?? '', 60);
        $out['repeat_limit'] = self::clamp($in['repeat_limit'] ?? 10, 1, 20, 10);

        $show = is_array($in['show'] ?? null) ? $in['show'] : [];

        foreach (array_keys(self::defaults()['show']) as $key) {
            $out['show'][$key] = !empty($show[$key]);
        }

        $order = is_array($in['order'] ?? null) ? $in['order'] : [];

        foreach (self::sections() as $i => $key) {
            $out['order'][$key] = self::clamp($order[$key] ?? $i, 0, 20, $i);
        }

        $hidden = is_array($in['hidden_rows'] ?? null) ? array_map('strval', $in['hidden_rows']) : [];
        $icons  = is_array($in['icons'] ?? null) ? $in['icons'] : [];

        $out['hidden_rows'] = array_values(array_intersect(self::row_slugs(), $hidden));
        $out['icons']       = [];

        foreach (self::row_slugs() as $slug) {
            $icon = self::text($icons[$slug] ?? '', 8);

            if ('' !== $icon) {
                $out['icons'][$slug] = $icon;
            }
        }

        return $out;
    }

    /**
     * @param mixed              $v
     * @param array<int, string> $allowed
     */
    private static function pick($v, array $allowed, string $default): string
    {
        return in_array((string) $v, $allowed, true) ? (string) $v : $default;
    }

    /**
     * @param mixed $v
     */
    private static function clamp($v, int $min, int $max, int $default): int
    {
        if (!is_numeric($v)) {
            return $default;
        }

        return max($min, min($max, (int) $v));
    }

    /**
     * @param mixed $v
     */
    private static function color($v, string $default): string
    {
        $v = strtolower(trim((string) $v));

        return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $default;
    }

    /**
     * @param mixed $v
     */
    private static function text($v, int $max): string
    {
        $v = function_exists('sanitize_text_field') ? sanitize_text_field((string) $v) : trim(strip_tags((string) $v));

        return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    }

    /**
     * @param mixed $v
     */
    private static function url($v): string
    {
        $v = trim((string) $v);

        if ('' === $v) {
            return '';
        }

        return function_exists('esc_url_raw') ? esc_url_raw($v) : (string) filter_var($v, FILTER_SANITIZE_URL);
    }
}
