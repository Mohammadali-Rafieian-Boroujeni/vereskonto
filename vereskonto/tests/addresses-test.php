<?php
/**
 * Plain-PHP test (no WordPress): the address book (validation, save,
 * edit, default, delete, sync into the WooCommerce customer fields,
 * checkout location on the order) and the identity block of the
 * installment-credit request form (IBAN checksum, rendering).
 *
 * Run: php tests/addresses-test.php — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('VKL_URL', 'https://shop.test/wp-content/plugins/vereskonto/');
define('VKL_VERSION', 'test');

$GLOBALS['meta']    = [];
$GLOBALS['notices'] = [];
function get_user_meta($u, $k, $s = false) { return $GLOBALS['meta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['meta'][$u][$k] = $v; return true; }
function delete_user_meta($u, $k) { unset($GLOBALS['meta'][$u][$k]); return true; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function wp_unslash($v) { return $v; }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, (int) $d); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_js($s) { return addslashes((string) $s); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function selected($a, $b = true, $e = true) { return $a === $b ? ' selected' : ''; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wc_get_account_endpoint_url($e) { return 'https://shop.test/my-account/' . $e . '/'; }
function wc_add_notice($m, $t = 'success') { $GLOBALS['notices'][] = [$t, $m]; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function wp_verify_nonce($n, $a) { return 'good' === $n; }
function wp_nonce_field(...$a) { echo '<input type="hidden" name="_vkl_addr_nonce" value="good">'; }
function wp_create_nonce($a) { return 'nonce'; }
function admin_url($p = '') { return 'https://shop.test/wp-admin/' . $p; }
function wp_json_encode($v) { return json_encode($v); }
function add_action(...$a) {}
function apply_filters($h, $v) { return $v; }
class VKL_Test_Redirect extends Exception {}
function wp_safe_redirect($u) { throw new VKL_Test_Redirect((string) $u); }

class VKL_Test_Countries { public function get_states($c) { return ['THR' => 'تهران', 'ESF' => 'اصفهان']; } }
class VKL_Test_WC { public $countries; public function __construct() { $this->countries = new VKL_Test_Countries(); } }
function WC() { static $w = null; return $w ?: ($w = new VKL_Test_WC()); }

require __DIR__ . '/../modules/woocommerce/addresses.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};
$post = static function (array $p): string {
    $_POST = $p + ['_vkl_addr_nonce' => 'good'];
    try {
        (new VKL_Wc_Addresses())->handle_post();
    } catch (VKL_Test_Redirect $r) {
        return $r->getMessage();
    }
    return '';
};
$home = [
    'label' => 'خانه', 'first_name' => 'مریم', 'last_name' => 'احمدی', 'phone' => '۰۹۱۲۱۲۳۴۵۶۷',
    'state' => 'THR', 'city' => 'تهران', 'address_1' => 'خیابان ولیعصر، کوچهٔ نهم، ساختمان سرو',
    'address_2' => 'پلاک ۱۲', 'postcode' => '1234567890', 'lat' => '35.7', 'lng' => '51.4',
];

// ---- Validation -----------------------------------------------------------
[$a, $e] = VKL_Wc_Addresses::clean($home);
$check([] === $e, 'a complete address is accepted');
$check('09121234567' === $a['phone'] && '35.700000' === $a['lat'], 'Persian digits become Latin; coordinates normalised');
[, $e] = VKL_Wc_Addresses::clean(['lat' => '', 'lng' => ''] + $home);
$check(in_array('محل نشانی را روی نقشه مشخص کنید.', $e, true), 'the map pin is required');
[, $e] = VKL_Wc_Addresses::clean(['state' => 'XXX', 'postcode' => '123', 'phone' => '0212222'] + $home);
$check(3 === count($e), 'bad province, postcode and mobile are each reported');

// ---- Save, second address, default, edit, delete ---------------------------
$GLOBALS['meta'][7] = ['billing_first_name' => 'مریم', 'billing_phone' => '09120000000'];
$r = $post(['vkl_addr_action' => 'save', 'vkl_addr' => $home]);
$rows = VKL_Wc_Addresses::all(7);
$check('' !== $r && 1 === count($rows), 'first address saved and page redirects');
$check($rows[0]['id'] === VKL_Wc_Addresses::default_id(7), 'first address becomes the default');
$check('THR' === get_user_meta(7, 'billing_state') && 'IR' === get_user_meta(7, 'shipping_country') && '09121234567' === get_user_meta(7, 'shipping_phone'), 'default copied into WooCommerce address fields');
$check('09120000000' === get_user_meta(7, 'billing_phone') && 'مریم' === get_user_meta(7, 'billing_first_name'), 'billing phone and name (identity) untouched');

$work = ['label' => 'محل کار', 'city' => 'اصفهان', 'state' => 'ESF', 'first_name' => 'علی'] + $home;
$post(['vkl_addr_action' => 'save', 'vkl_addr' => $work, 'vkl_addr_default' => '1']);
$rows = VKL_Wc_Addresses::all(7);
$check(2 === count($rows) && 'محل کار' === $rows[0]['label'], 'second address saved as default and listed first');
$check('ESF' === get_user_meta(7, 'billing_state') && 'علی' === get_user_meta(7, 'shipping_first_name'), 'new default synced');

$home_id = $rows[1]['id'];
$post(['vkl_addr_action' => 'save', 'vkl_addr_id' => $home_id, 'vkl_addr' => ['address_2' => 'واحد ۵'] + $home]);
$check('واحد ۵' === VKL_Wc_Addresses::find(7, $home_id)['address_2'] && 2 === count(VKL_Wc_Addresses::all(7)), 'editing keeps the id and the count');

$post(['vkl_addr_action' => 'default', 'vkl_addr_id' => $home_id]);
$check($home_id === VKL_Wc_Addresses::default_id(7) && 'THR' === get_user_meta(7, 'billing_state'), 'make default switches the synced address');

$post(['vkl_addr_action' => 'delete', 'vkl_addr_id' => $home_id]);
$rows = VKL_Wc_Addresses::all(7);
$check(1 === count($rows) && $rows[0]['id'] === VKL_Wc_Addresses::default_id(7) && 'ESF' === get_user_meta(7, 'billing_state'), 'deleting the default falls back to the remaining address');

$GLOBALS['notices'] = [];
$r = $post(['vkl_addr_action' => 'save', 'vkl_addr' => ['lat' => ''] + $home]);
$check('' === $r && 'error' === ($GLOBALS['notices'][0][0] ?? ''), 'invalid save stays on the form with an error');

$_POST = ['vkl_addr_action' => 'delete', 'vkl_addr_id' => $rows[0]['id'], '_vkl_addr_nonce' => 'bad'];
(new VKL_Wc_Addresses())->handle_post();
$check(1 === count(VKL_Wc_Addresses::all(7)), 'a bad nonce changes nothing');

// ---- Screens render with balanced markup ------------------------------------
$balanced = static function (string $html): bool {
    foreach (['div', 'form', 'ul', 'li', 'p', 'a', 'select'] as $tag) {
        if (preg_match_all('~<' . $tag . '\b~', $html) !== preg_match_all('~</' . $tag . '>~', $html)) { return false; }
    }
    return true;
};
$_POST = [];
ob_start(); (new VKL_Wc_Addresses())->render(); $list = (string) ob_get_clean();
$check(str_contains($list, 'vkl-addr-card is-default') && str_contains($list, 'openstreetmap.org/?mlat=') && $balanced($list), 'list shows the default card with a map link');
$_GET = ['addr' => 'new'];
ob_start(); (new VKL_Wc_Addresses())->render(); $form = (string) ob_get_clean();
$check(str_contains($form, 'id="vkl-addr-map"') && str_contains($form, 'value="THR"') && $balanced($form), 'new-address form has the map and provinces');
$_GET = [];

// ---- Checkout: location comes from the saved address, not the request ------
class VKL_Test_Order { public $meta = []; public function get_customer_id() { return 7; } public function update_meta_data($k, $v) { $this->meta[$k] = $v; } public function get_meta($k) { return $this->meta[$k] ?? ''; } }
$order = new VKL_Test_Order();
$_POST = ['vkl_address_id' => $rows[0]['id'], 'lat' => '1', 'lng' => '1'];
(new VKL_Wc_Addresses())->save_order_location($order);
$check('35.700000' === $order->meta['_vkl_lat'] && 'محل کار' === $order->meta['_vkl_address_label'], 'order gets the saved address location');
$order2 = new VKL_Test_Order();
$_POST = ['vkl_address_id' => 'someone-else'];
(new VKL_Wc_Addresses())->save_order_location($order2);
$check([] === $order2->meta, 'unknown address id stores nothing');
ob_start(); (new VKL_Wc_Addresses())->admin_order_location($order); $html = (string) ob_get_clean();
$check(str_contains($html, 'mlat=35.700000'), 'order screen links the location');
$check(1 === count(VKL_Wc_Addresses::picker_data(7)) && '1' === VKL_Wc_Addresses::picker_data(7)[0]['default'], 'checkout picker data marks the default');
$check('' !== VKL_Wc_Addresses::picker_data(7)[0]['lat'] && '' !== VKL_Wc_Addresses::picker_data(7)[0]['lng'], 'checkout picker data carries the map pin for the courier map');

// ---- Credit request: IBAN and identity block --------------------------------
class VKL_Repository { public function __construct($db = null) {} }
class VKL_Wc_Account
{
    public static function normalize_national_id(string $r): string { return preg_replace('/\D/', '', $r); }
    public static function is_valid_national_id(string $c): bool { return '0084575948' === $c; }
}
class VKL_Identity
{
    public const META_HASH = 'vkl_identity_hash';
    public static function enabled(): bool { return true; }
    public static function mode(): string { return 'require'; }
    public static function stored_status(int $u): string { return 'match'; }
}
function vkl_normalize_mobile($m) { return $m; }
require __DIR__ . '/../modules/wallet/creditrequest.php';

$check(VKL_Credit_Requests::is_valid_iban('IR062960000000100324200001'), 'valid IBAN accepted');
$check(!VKL_Credit_Requests::is_valid_iban('IR062960000000100324200002'), 'one wrong digit rejected by the checksum');
$check('IR062960000000100324200001' === VKL_Credit_Requests::normalize_iban('۰۶۲۹ ۶۰۰۰ ۰۰۰۰ ۱۰۰۳ ۲۴۲۰ ۰۰۰۱'), 'Persian digits, spaces and missing IR normalised');

$GLOBALS['meta'][7] += ['vkl_national_id' => '0084575948', 'vkl_iban' => 'IR062960000000100324200001', 'vkl_identity_hash' => md5('09120000000|0084575948')];
$ref = new ReflectionMethod(VKL_Credit_Requests::class, 'render_identity_fields');
$ref->setAccessible(true);
$cr = (new ReflectionClass(VKL_Credit_Requests::class))->newInstanceWithoutConstructor();
ob_start(); $ref->invoke($cr, 7); $html = (string) ob_get_clean();
$check(str_contains($html, 'name="vkl_cr_national"') && str_contains($html, 'name="vkl_cr_iban"') && str_contains($html, 'value="0084575948"'), 'request form carries national ID and IBAN, prefilled');
$check(str_contains($html, 'vkl_cr_identity_btn') && str_contains($html, 'به نام صاحب این کد ملی ثبت شده است'), 'matching status shown on the same form');
$check(str_contains($html, '"require":true') && str_contains($html, '"state":"match"'), 'require mode and the stored verdict reach the page script');

// Inline script is valid JavaScript.
preg_match('~<script>(.*?)</script>~s', $html, $m);
$tmp = sys_get_temp_dir() . '/vkl-cr-' . getmypid() . '.js';
file_put_contents($tmp, $m[1] ?? '');
exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
@unlink($tmp);
$check(0 === $code || 127 === $code, 'credit form script parses' . (0 !== $code ? ' — ' . implode(' ', $out) : ''));

exit($failures > 0 ? 1 : 0);
