<?php
/**
 * Plain-PHP test (no WordPress): the mobile tab bar — which tabs appear
 * and in what order, which one is highlighted on each page, where Home
 * goes, that WoodMart's toolbar steps aside, and that the admin's
 * settings (vkl_dash) are applied and sanitized.
 *
 * Run: php tests/tabbar-test.php — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('VKL_URL', 'https://shop.test/wp-content/plugins/vereskonto/');
define('VKL_VERSION', 'test');

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

function __($t, $d = '') { return $t; }
function _x($t, $c, $d = '') { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t); }
function esc_attr($t) { return htmlspecialchars((string) $t); }
function esc_url($t) { return (string) $t; }
function esc_attr_e($t, $d = '') { echo htmlspecialchars((string) $t); }
function esc_html_x($t, $c, $d = '') { return htmlspecialchars((string) $t); }
function wp_json_encode($v) { return json_encode($v); }
function add_action(...$a) {}
function add_filter(...$a) {}
function did_action($a) { return 'wp' === $a ? 1 : 0; }
function get_option($k, $d = false) { return 'vkl_dash' === $k ? ($GLOBALS['t_opts'] ?? $d) : $d; }
function is_account_page() { return $GLOBALS['t_account'] ?? true; }
function is_user_logged_in() { return $GLOBALS['t_logged_in'] ?? true; }
function is_front_page() { return $GLOBALS['t_front'] ?? false; }
function is_checkout() { return $GLOBALS['t_checkout'] ?? false; }
function home_url($p = '') { return '/'; }
function wc_get_cart_url() { return '/cart/'; }
function wc_get_page_permalink($p) { return 'shop' === $p ? '/shop/' : '/my-account/'; }
function wc_get_account_endpoint_url($e) { return '/my-account/' . $e . '/'; }
function wc_get_account_menu_items() { return $GLOBALS['t_menu']; }
final class T_Query { public function get_current_endpoint() { return $GLOBALS['t_endpoint'] ?? ''; } }
final class T_Cart { public function get_cart_contents_count() { return $GLOBALS['t_cart'] ?? 0; } }
final class T_WC { public $query; public $cart; public function __construct() { $this->query = new T_Query(); $this->cart = new T_Cart(); } }
function WC() { return new T_WC(); }
final class T_Config { public function get_bool($k, $d = false) { return 'chatbot_enabled' === $k ? ($GLOBALS['t_chat'] ?? false) : $d; } }
final class T_Container { public function has($id) { return 'config' === $id; } }
function vkl($s = null) { return null === $s ? new T_Container() : new T_Config(); }

require __DIR__ . '/../includes/dash-options.php';
require __DIR__ . '/../modules/woocommerce/frontendui.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

$full = ['dashboard' => 'D', 'orders' => 'O', 'returns' => 'R', 'downloads' => 'Dl', 'wallet' => 'W', 'credit' => 'C',
         'club' => 'Cl', 'surveys' => 'S', 'tickets' => 'T', 'edit-account' => 'A', 'edit-address' => 'Ad', 'customer-logout' => 'L'];

// Admin settings: the old five account tabs, Home still to the site.
$classic = VKL_Dash_Options::merge(['tabs' => [
    'home' => ['on' => true, 'pos' => 1], 'shop' => ['on' => false], 'cart' => ['on' => false], 'dashboard' => ['on' => false],
    'purchases' => ['on' => true, 'pos' => 2], 'wallet' => ['on' => true, 'pos' => 3], 'support' => ['on' => true, 'pos' => 4], 'account' => ['on' => true, 'pos' => 5],
]]);
$defaults = VKL_Dash_Options::merge([]);

$keys = static fn(array $items): array => array_column($items, 'key');
$active = static function (string $endpoint, array $menu, array $opts, array $ctx = []): array {
    return array_column(array_filter(VKL_Frontend_Ui::tabbar_items($endpoint, $menu, $ctx, $opts), static fn($i) => $i['active']), 'key');
};

$check($keys(VKL_Frontend_Ui::tabbar_items('', $full, [], $defaults)) === ['home', 'shop', 'cart', 'wallet', 'dashboard'], 'default tabs: home, shop, cart, wallet, my account');
$check($keys(VKL_Frontend_Ui::tabbar_items('', $full, [], $classic)) === ['home', 'purchases', 'wallet', 'support', 'account'], 'admin-chosen tabs in their order');

$cases = [
    'orders'       => 'purchases',
    'view-order'   => 'purchases',
    'returns'      => 'purchases',
    'downloads'    => 'purchases',
    'wallet'       => 'wallet',
    'credit'       => 'wallet',
    'tickets'      => 'support',
    'edit-account' => 'account',
    'edit-address' => 'account',
    'club'         => 'account',
    'surveys'      => 'account',
];
foreach ($cases as $endpoint => $tab) {
    $check($active($endpoint, $full, $classic) === [$tab], "on '$endpoint' the $tab tab is highlighted");
}
$check([] === $active('', $full, $classic), 'Home (to the site) is not lit on the account dashboard');
$check(['home'] === $active('', $full, $classic, ['account' => false, 'front' => true]), 'Home is lit on the front page');
$check(['dashboard'] === $active('', $full, $defaults), 'My account is lit on the dashboard');
$check(['dashboard'] === $active('gift-cards', $full, $defaults), 'My account catches a page from another plugin');
$check(['shop'] === $active('', $full, $defaults, ['account' => false, 'shop' => true]), 'Shop is lit in the catalog');
$check(['cart'] === $active('', $full, $defaults, ['account' => false, 'cart' => true]), 'Cart is lit on the cart page');

$old_home = $classic;
$old_home['home_target'] = 'dashboard';
$check(['home'] === $active('', $full, $old_home), 'Home set to the dashboard is lit there');

$no_wallet = $full;
unset($no_wallet['wallet'], $no_wallet['credit'], $no_wallet['tickets']);
$check($keys(VKL_Frontend_Ui::tabbar_items('', $no_wallet, [], $classic)) === ['home', 'purchases', 'account'], 'tabs of switched-off modules are left out');
$check($keys(VKL_Frontend_Ui::tabbar_items('', $full, ['logged_in' => false, 'account' => false], $defaults)) === ['home', 'shop', 'cart', 'dashboard'], 'guests get no wallet tab');

$custom = VKL_Dash_Options::merge(['tabs' => ['custom' => ['on' => true, 'label' => '', 'pos' => 0]], 'custom_url' => '/sale/']);
$check(!in_array('custom', $keys(VKL_Frontend_Ui::tabbar_items('', $full, [], $custom)), true), 'custom tab without a label is left out');
$custom['tabs']['custom']['label'] = 'Sale';
$check($keys(VKL_Frontend_Ui::tabbar_items('', $full, [], $custom))[0] === 'custom', 'custom tab with a label sits at its position');

// Sanitizing: one part saved, the other kept; tab cap; bad values.
$GLOBALS['t_opts'] = ['greeting' => 'Hi {name}'];
VKL_Dash_Options::flush();
$saved = VKL_Dash_Options::sanitize(['_part' => 'bar', 'bar_enabled' => '1', 'bar_scope' => 'nowhere', 'bar_color' => 'red', 'bar_breakpoint' => '5000',
    'tabs' => array_fill_keys(VKL_Dash_Options::tab_keys(), ['on' => '1', 'label' => 'x', 'pos' => '3'])]);
$check('Hi {name}' === $saved['greeting'], 'saving the bar keeps the dashboard settings');
$check('site' === $saved['bar_scope'] && '#157f3a' === $saved['bar_color'] && 1200 === $saved['bar_breakpoint'], 'bad bar values fall back or are clamped');
$check(VKL_Dash_Options::MAX_TABS === count(array_filter(array_column($saved['tabs'], 'on'))), 'at most ' . VKL_Dash_Options::MAX_TABS . ' tabs are switched on');
$check(false === $saved['cart_badge'] && false === $saved['hide_checkout'], 'unticked boxes save as off');
$dash = VKL_Dash_Options::sanitize(['_part' => 'dash', 'hidden_rows' => ['orders', 'evil'], 'icons' => ['wallet' => '💎', 'nope' => 'x'], 'order' => ['head' => '99'], 'layout' => 'three']);
$check(['orders'] === $dash['hidden_rows'] && ['wallet' => '💎'] === $dash['icons'], 'only known rows can be hidden or re-iconed');
$check(20 === $dash['order']['head'] && 'two' === $dash['layout'] && false === $dash['show']['repeat'], 'dashboard values are clamped');
$check($saved === VKL_Dash_Options::sanitize($saved), 'second sanitize pass (first save) keeps the values');
$GLOBALS['t_opts'] = [];
VKL_Dash_Options::flush();

// Rendering.
$GLOBALS['t_menu'] = $full;
$render = static function (): string {
    VKL_Frontend_Ui::flush_bar();
    ob_start();
    (new VKL_Frontend_Ui())->account_tabbar();
    return (string) ob_get_clean();
};

$GLOBALS['t_endpoint'] = 'credit';
$html = $render();
$check(5 === substr_count($html, '<a href='), 'renders five links');
$check(1 === substr_count($html, 'aria-current="page"') && 1 === preg_match('~href="/my-account/wallet/"\s*class="is-active"~', $html), 'credit page marks the wallet tab current');
$check(1 === preg_match('~<a href="/"~', $html), 'home tab links to the site front page');
$check(str_contains($html, 'href="/cart/"') && str_contains($html, 'href="/shop/"'), 'shop and cart tabs link to their pages');
$check(str_contains($html, 'body.vkl-has-tabbar .wd-toolbar{display:none !important}'), 'WoodMart toolbar is hidden where the bar shows');
$check(str_contains($html, 'class="vkl-tabbar-count" aria-hidden="true" hidden>0<'), 'empty cart badge is present but hidden');
$check(substr_count($html, '<svg') === 5 && substr_count($html, '</svg>') === 5 && substr_count($html, '<nav') === 1 && substr_count($html, '</nav>') === 1, 'markup is balanced');
$check(!str_contains($html, 'class="vkl-tabbar-chat"') && !str_contains($html, '<script'), 'no chat button or script while the chatbot is off');
$check(str_contains($html, 'body.vkl-has-tabbar .wd-sticky-btn{--vkl-sb-lift:calc(76px') && str_contains($html, '.wd-sticky-btn:not(.wd-sticky-btn-shown){transform:translate3d(0,calc(100% + var(--vkl-sb-lift)'), 'product page: sticky add-to-cart sits above the bar and hides fully');
$check(str_contains($html, 'body.vkl-has-tabbar.wd-sticky-btn-on-mb{padding-bottom:calc(80px + var(--wd-sticky-btn-height'), 'page bottom leaves room for both bars');

$ui = new VKL_Frontend_Ui();
$check(false === $ui->theme_toolbar_option(true, 'sticky_toolbar') && true === $ui->theme_toolbar_option(true, 'other'), 'woodmart_option turns off only the sticky toolbar');
$classes = $ui->tabbar_body_class(['home', 'sticky-toolbar-on']);
$check(in_array('vkl-has-tabbar', $classes, true) && !in_array('sticky-toolbar-on', $classes, true), 'body gets vkl-has-tabbar and loses sticky-toolbar-on');

$GLOBALS['t_cart'] = 3;
$check(str_contains($render(), 'class="vkl-tabbar-count" aria-hidden="true">3<'), 'cart badge shows the item count');
$frag = $ui->cart_fragment([]);
$check(isset($frag['b.vkl-tabbar-count']) && str_contains($frag['b.vkl-tabbar-count'], '>3<'), 'cart badge is refreshed by AJAX fragments');
$GLOBALS['t_cart'] = 0;

$GLOBALS['t_opts'] = ['theme_toolbar' => 'keep'];
VKL_Dash_Options::flush();
$html = $render();
$check(!str_contains($html, '.wd-toolbar') && str_contains($html, 'body.sticky-toolbar-on .vkl-tabbar{--vkl-tb-base:55px}'), 'kept WoodMart toolbar: bar floats above it');
$check(str_contains($html, 'body.vkl-has-tabbar.sticky-toolbar-on .wd-sticky-btn{--vkl-sb-lift:calc(131px'), 'kept WoodMart toolbar: add-to-cart sits above both bars');
$check(true === $ui->theme_toolbar_option(true, 'sticky_toolbar'), 'kept WoodMart toolbar stays on');
$GLOBALS['t_opts'] = [];
VKL_Dash_Options::flush();

$GLOBALS['t_chat'] = true;
$html = $render();
$check(1 === substr_count($html, 'class="vkl-tabbar-chat"') && 1 === preg_match('~<button type="button" class="vkl-tabbar-chat"[^>]*aria-controls="vkl-chat-panel"[^>]*hidden>~', $html), 'chatbot on: chat button in the bar, hidden until the widget is found');
$check(str_contains($html, "toggle.click()") && str_contains($html, '#vkl-chat-toggle{display:none'), 'chat button opens the same widget; its round button steps aside here');
$check(5 === substr_count($html, '<svg') && 1 === substr_count($html, '<button') && 1 === substr_count($html, '</button>'), 'markup with chat is balanced');
$check(1 === preg_match('~<i class="vkl-tabbar-ico vkl-tabbar-avatar"><img src="https://shop.test/wp-content/plugins/vereskonto/assets/chat-assistant.svg\?ver=test" width="32" height="32" alt=""~', $html), 'chat tab shows the assistant image (decorative; the label names it)');
$GLOBALS['t_chat'] = false;

// Where the bar shows.
$GLOBALS['t_endpoint'] = '';
$GLOBALS['t_account'] = false;
$check('' !== $render(), 'shown site-wide (main mobile bar)');
$GLOBALS['t_logged_in'] = false;
$check('' !== $render(), 'shown to guests outside the account area');
$GLOBALS['t_account'] = true;
$check('' === $render(), 'hidden on the guest sign-in screen');
$GLOBALS['t_logged_in'] = true;
$GLOBALS['t_endpoint'] = 'customer-logout';
$check('' === $render(), 'hidden on the logout page');
$GLOBALS['t_endpoint'] = '';
$GLOBALS['t_account'] = false;
$GLOBALS['t_checkout'] = true;
$check('' === $render(), 'hidden on checkout by default');
$GLOBALS['t_checkout'] = false;

$GLOBALS['t_opts'] = ['bar_scope' => 'account'];
VKL_Dash_Options::flush();
$check('' === $render(), 'limited to the account area: hidden elsewhere');
$check(true === $ui->theme_toolbar_option(true, 'sticky_toolbar'), 'WoodMart toolbar stays where our bar is not shown');
$GLOBALS['t_opts'] = ['bar_enabled' => false];
VKL_Dash_Options::flush();
$GLOBALS['t_account'] = true;
$check('' === $render(), 'switched off: never shown');

exit($failures > 0 ? 1 : 0);
