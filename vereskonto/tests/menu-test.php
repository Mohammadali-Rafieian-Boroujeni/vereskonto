<?php
/**
 * Plain-PHP test (no WordPress): the shared admin menu order and the
 * customer account menu order.
 *
 * Run: php tests/menu-test.php  — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

function __($text, $domain = '') { return $text; }
function _x($text, $context, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function add_action(...$args) {}
function add_filter(...$args) {}
$GLOBALS['test_caps'] = ['manage_options', 'vkl_crm_access', 'vkl_crm_settings'];
function current_user_can($cap) { return in_array($cap, $GLOBALS['test_caps'], true); }

require __DIR__ . '/../core/menu.php';
require __DIR__ . '/../modules/woocommerce/frontendui.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

// --- Admin sidebar: registration order is the module load order. ---
global $submenu;
$submenu = ['vereskala-login' => [
    ['Vereskonto', 'manage_options', 'vereskala-login', 'Vereskonto'],
    ['Cart Tracking', 'manage_options', 'vkl-cart', 'Cart Tracking'],
    ['Orders', 'manage_options', 'vkl-history', 'Orders'],
    ['درخواست‌ها <span class="awaiting-mod">3</span>', 'manage_options', 'vkl-returns', 'x'],
    ['Some addon', 'manage_options', 'vkl-addon', 'Some addon'],
    ['Tickets', 'manage_options', 'vkl-tickets', 'Tickets'],
    ['Status', 'manage_options', 'vkl-status', 'Status'],
]];

(new VKL_Admin_Menu())->reorder();
$slugs = array_column($submenu['vereskala-login'], 2);

$check($slugs === ['vkl-tickets', 'vkl-returns', 'vkl-cart', 'vkl-history', 'vereskala-login', 'vkl-status', 'vkl-addon'], 'sidebar follows group order, unlisted page last');
$check($submenu['vereskala-login'][1][0] === 'Return requests <span class="awaiting-mod">3</span>', 'pending badge carried over');
$check($submenu['vereskala-login'][3][0] === 'Sales report', '"Orders" renamed');
$check($submenu['vereskala-login'][6][0] === 'Some addon', 'unlisted page keeps its own label');
$check(array_column($submenu['vereskala-login'][0], null) === ['Tickets', 'manage_options', 'vkl-tickets', 'Tickets'], 'capability and page title untouched');

$wpBadge = 'Tickets <span class="awaiting-mod count-2"><span class="pending-count">2</span></span>';
$submenu = ['vereskala-login' => [[$wpBadge, 'manage_options', 'vkl-tickets', 'Tickets']]];
(new VKL_Admin_Menu())->reorder();
$check($submenu['vereskala-login'][0][0] === 'Tickets <span class="awaiting-mod count-2"><span class="pending-count">2</span></span>', 'nested WordPress badge carried over whole');

$all = [];
foreach (VKL_Admin_Menu::groups() as $g) { $all = array_merge($all, array_keys($g['items'])); }
$check(count($all) === count(array_unique($all)), 'no screen listed twice');

// --- Customer account menu. ---
$items = [
    'dashboard' => 'Dashboard', 'orders' => 'Orders', 'downloads' => 'Downloads',
    'edit-address' => 'Addresses', 'payment-methods' => 'Payment methods', 'edit-account' => 'Account details',
    'club' => 'Club', 'surveys' => 'Surveys', 'gift-cards' => 'Gift cards (other plugin)',
    'returns' => 'Returns', 'tickets' => 'Tickets', 'wallet' => 'Wallet', 'credit' => 'Credit',
    'customer-logout' => 'Log out',
];
$out = array_keys((new VKL_Frontend_Ui())->remove_payment_methods($items));

$check($out === ['dashboard', 'orders', 'returns', 'downloads', 'wallet', 'credit', 'club', 'surveys', 'tickets', 'edit-account', 'edit-address', 'gift-cards', 'customer-logout'], 'account menu grouped, foreign item before logout');
$check(!in_array('payment-methods', $out, true), 'payment methods still removed');

// --- Customer account tabs within a group. ---
$menu = ['orders' => 'Orders', 'returns' => 'Returns', 'wallet' => 'Wallet', 'credit' => 'Credit', 'tickets' => 'Tickets'];
$check(VKL_Frontend_Ui::group_tabs_for('credit', $menu) === ['wallet' => 'Wallet', 'credit' => 'Credit'], 'credit page shows wallet | credit tabs');
$check(array_keys(VKL_Frontend_Ui::group_tabs_for('orders', $menu)) === ['orders', 'returns'], 'orders page shows orders | returns (downloads absent)');
$check(1 === count(VKL_Frontend_Ui::group_tabs_for('tickets', $menu)), 'single-page group has one tab (bar hidden)');
$check([] === VKL_Frontend_Ui::group_tabs_for('view-order', $menu), 'pages outside groups get no tabs');

// --- Header tab bar actually renders (a lint pass alone missed a
// runtime "Undefined constant" fatal here in 2.10.0). ---
define('VKL_VERSION', 'test');
function esc_url($u) { return (string) $u; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function sanitize_key($k) { return strtolower((string) $k); }
function get_current_screen() { return (object) ['id' => 'toplevel_page_vereskala-login']; }
require __DIR__ . '/../core/adminui.php';

$_GET['page'] = 'vkl-wallet';
ob_start();
(new VKL_Admin_Ui())->header();
$html = (string) ob_get_clean();
preg_match_all('~admin\.php\?page=([a-z-]+)~', $html, $m);

$check($m[1] === $all, 'tab bar links every screen in group order');
$check(1 === preg_match('~page=vkl-wallet"\s*class="vkl-tab-active"~', $html), 'tab bar marks the current screen');

// --- A sales agent (CRM access only) is offered only the CRM screen. ---
$submenu = ['vereskala-login' => [
    ['Contacts', 'vkl_crm_access', 'vkl-crm-contacts', 'Contacts'],
    ['Wallet', 'manage_options', 'vkl-wallet', 'Wallet'],
    ['CRM settings', 'vkl_crm_settings', 'vkl-crm-settings', 'CRM settings'],
]];
$GLOBALS['test_caps'] = ['read', 'vkl_crm_access'];
$_GET['page'] = 'vkl-crm-contacts';
ob_start();
(new VKL_Admin_Ui())->header();
preg_match_all('~admin\.php\?page=([a-z-]+)~', (string) ob_get_clean(), $m);
$check(['vkl-crm-contacts'] === $m[1], 'agent tab bar: only the CRM contacts screen');

exit($failures > 0 ? 1 : 0);
