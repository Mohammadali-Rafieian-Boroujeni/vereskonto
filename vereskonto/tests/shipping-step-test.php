<?php
/**
 * Plain-PHP test (no WordPress): the address and shipping step between
 * the cart and the checkout — routing decisions, reading rates from the
 * WooCommerce packages, the submit (only the customer's own address and
 * only a rate WooCommerce offers), and the order getting the map location
 * of the address chosen at the step.
 *
 * Run: php tests/shipping-step-test.php — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('VKL_URL', 'https://shop.test/wp-content/plugins/vereskonto/');
define('VKL_VERSION', 'test');

$GLOBALS['meta']    = [];
$GLOBALS['notices'] = [];
$GLOBALS['actions'] = [];
function get_user_meta($u, $k, $s = false) { return $GLOBALS['meta'][$u][$k] ?? ''; }
function update_user_meta($u, $k, $v) { $GLOBALS['meta'][$u][$k] = $v; return true; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function wp_unslash($v) { return $v; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function wp_kses_post($s) { return (string) $s; }
function wc_price($n) { return number_format((float) $n) . ' تومان'; }
function wc_add_notice($m, $t = 'success') { $GLOBALS['notices'][] = [$t, $m]; }
function wc_get_checkout_url() { return 'https://shop.test/checkout/'; }
function wc_get_cart_url() { return 'https://shop.test/cart/'; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function wp_verify_nonce($n, $a) { return 'good' === $n; }
function get_post_status($id) { return 42 === (int) $id ? 'publish' : false; }
function get_permalink($id) { return 'https://shop.test/shipping/'; }
function is_page($id) { return 42 === (int) $id; }
function add_shortcode(...$a) {}
function add_action(...$a) {}
function add_filter(...$a) {}
function do_action($h, ...$a) { $GLOBALS['actions'][] = $h; }
function apply_filters($h, $v) { return $v; }
class VKL_Test_Redirect extends Exception {}
function wp_safe_redirect($u) { throw new VKL_Test_Redirect((string) $u); }

class VKL_Config
{
    public array $v = ['shipping_step_enabled' => '1', 'shipping_step_page' => 42];
    public function get_bool(string $k, bool $d = false): bool { return isset($this->v[$k]) ? '1' === (string) $this->v[$k] : $d; }
    public function get_int(string $k, int $d = 0): int { return isset($this->v[$k]) ? (int) $this->v[$k] : $d; }
}

class VKL_Test_Rate
{
    public function __construct(private string $label, private float $cost) {}
    public function get_label() { return $this->label; }
    public function get_cost() { return $this->cost; }
    public function get_shipping_tax() { return 0.0; }
}
class VKL_Test_Session
{
    public array $d = [];
    public function get($k) { return $this->d[$k] ?? null; }
    public function set($k, $v) { $this->d[$k] = $v; }
}
class VKL_Test_Customer
{
    public array $p = [];
    public bool $saved = false;
    public function __call($m, $a) { if (str_starts_with($m, 'set_')) { $this->p[substr($m, 4)] = $a[0]; } }
    public function set_shipping_phone($v) { $this->p["shipping_phone"] = $v; }
    public function save() { $this->saved = true; }
}
class VKL_Test_Cart
{
    public function calculate_shipping() {}
    public function calculate_totals() {}
    public function is_empty() { return false; }
}
class VKL_Test_Shipping
{
    public function get_packages()
    {
        // Rates depend on the address on the customer, like the real filter.
        $city = WC()->customer->p['shipping_city'] ?? '';
        $rates = ['vpc_post' => new VKL_Test_Rate('پست', 120000)];
        if ('اصفهان' === $city) {
            $rates['vpc_courier'] = new VKL_Test_Rate('پیک اصفهان', 90000);
            $rates['local_pickup:3'] = new VKL_Test_Rate('دریافت حضوری', 0);
        }
        return [0 => ['rates' => $rates]];
    }
}
class VKL_Test_Countries { public function get_states($c) { return ['THR' => 'تهران', 'ESF' => 'اصفهان']; } }
class VKL_Test_WC
{
    public $session; public $customer; public $cart; public $countries; private $shipping;
    public function __construct()
    {
        $this->session = new VKL_Test_Session();
        $this->customer = new VKL_Test_Customer();
        $this->cart = new VKL_Test_Cart();
        $this->countries = new VKL_Test_Countries();
        $this->shipping = new VKL_Test_Shipping();
    }
    public function shipping() { return $this->shipping; }
}
function WC() { static $w = null; return $w ?: ($w = new VKL_Test_WC()); }

require __DIR__ . '/../modules/woocommerce/addresses.php';
require __DIR__ . '/../modules/woocommerce/shippingstep.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

// ---- Routing ---------------------------------------------------------------
$base = ['page' => 'checkout', 'logged_in' => true, 'login_required' => true, 'cart_empty' => false, 'needs_shipping' => true, 'done' => false, 'endpoint' => false];
$d = static fn(array $over): string => VKL_Wc_Shipping_Step::decide($over + $base);

$check('step' === $d([]), 'checkout without the step done → the step');
$check('stay' === $d(['done' => true]), 'checkout after the step → stays');
$check('stay' === $d(['endpoint' => true]), 'order-pay / order-received are never redirected');
$check('stay' === $d(['needs_shipping' => false]), 'a cart without shipping (wallet top-up) pays directly');
$check('stay' === $d(['logged_in' => false]), 'guests at checkout are left to the login guard / guest checkout');
$check('stay' === $d(['page' => 'step']), 'a logged-in customer with a cart sees the step');
$check('cart' === $d(['page' => 'step', 'cart_empty' => true]), 'the step with an empty cart → cart');
$check('checkout' === $d(['page' => 'step', 'needs_shipping' => false]), 'the step for a cart without shipping → checkout');
$check('login' === $d(['page' => 'step', 'logged_in' => false]), 'a guest at the step → login when login is required');
$check('checkout' === $d(['page' => 'step', 'logged_in' => false, 'login_required' => false]), 'a guest at the step → checkout when guests may buy');
$check('stay' === $d(['page' => 'other']), 'other pages are untouched');

// ---- Rates -----------------------------------------------------------------
$rates = VKL_Wc_Shipping_Step::rates_from_packages([0 => ['rates' => ['a' => new VKL_Test_Rate('پست', 120000), 'b' => new VKL_Test_Rate('رایگان', 0)]]]);
$check(2 === count($rates) && 'a' === $rates[0]['id'] && 120000.0 === $rates[0]['cost'], 'rates are read from the first package');
$check([] === VKL_Wc_Shipping_Step::rates_from_packages([]), 'no packages → no rates');
$check('b' === VKL_Wc_Shipping_Step::pick_rate($rates, 'b'), 'a still-offered choice is kept');
$check('a' === VKL_Wc_Shipping_Step::pick_rate($rates, 'gone'), 'a choice no longer offered falls back to the first rate');
$check('' === VKL_Wc_Shipping_Step::pick_rate([], 'a'), 'no rates → nothing chosen');

// ---- Submit ----------------------------------------------------------------
$GLOBALS['meta'][7][VKL_Wc_Addresses::META] = [
    ['id' => 'home', 'label' => 'خانه', 'first_name' => 'مریم', 'last_name' => 'احمدی', 'phone' => '09121234567', 'state' => 'THR', 'city' => 'تهران', 'address_1' => 'خیابان ولیعصر، کوچهٔ نهم', 'address_2' => 'پلاک ۱۲', 'postcode' => '1234567890', 'lat' => '35.700000', 'lng' => '51.400000'],
    ['id' => 'work', 'label' => 'محل کار', 'first_name' => 'علی', 'last_name' => 'رضایی', 'phone' => '09131234567', 'state' => 'ESF', 'city' => 'اصفهان', 'address_1' => 'خیابان چهارباغ، ساختمان نور', 'address_2' => '', 'postcode' => '', 'lat' => '32.650000', 'lng' => '51.670000'],
];
$GLOBALS['meta'][8][VKL_Wc_Addresses::META] = [
    ['id' => 'other', 'label' => 'x', 'first_name' => 'x', 'last_name' => 'x', 'phone' => '09120000000', 'state' => 'THR', 'city' => 'تهران', 'address_1' => 'xxxxxxxxxxxx', 'address_2' => '', 'postcode' => '', 'lat' => '1', 'lng' => '1'],
];

$step = new VKL_Wc_Shipping_Step(new VKL_Config());
$submit = static function (array $p) use ($step): string {
    $_POST = $p + ['vkl_ship_submit' => '1', '_vkl_ship_nonce' => 'good'];
    $GLOBALS['notices'] = [];
    try {
        $step->handle_post();
    } catch (VKL_Test_Redirect $r) {
        return $r->getMessage();
    }
    return '';
};
$session = WC()->session;

$check('' === $submit(['vkl_address_id' => 'home', 'vkl_ship_rate' => 'vpc_post', '_vkl_ship_nonce' => 'bad']) && null === $session->get(VKL_Wc_Shipping_Step::S_DONE), 'a bad nonce changes nothing');
$check('' === $submit(['vkl_address_id' => 'other', 'vkl_ship_rate' => 'vpc_post']) && null === $session->get(VKL_Wc_Shipping_Step::S_DONE), "another customer's address id is refused");
$check('' === $submit(['vkl_address_id' => 'home', 'vkl_ship_rate' => 'vpc_courier']) && null === $session->get(VKL_Wc_Shipping_Step::S_DONE), 'a rate not offered for this address (courier outside Isfahan) is refused');

$to = $submit(['vkl_address_id' => 'work', 'vkl_ship_rate' => 'vpc_courier']);
$check('https://shop.test/checkout/' === $to, 'a valid choice goes on to checkout');
$check([0 => 'vpc_courier'] === $session->get('chosen_shipping_methods'), 'the chosen rate is what checkout will use');
$check(true === $session->get(VKL_Wc_Shipping_Step::S_DONE) && 'work' === $session->get(VKL_Wc_Shipping_Step::S_ADDRESS), 'the step is marked done with the chosen address');
$check(['lat' => '32.650000', 'lng' => '51.670000'] === $session->get(VKL_Wc_Shipping_Step::S_PIN), 'the map pin of the address is in the session');
$c = WC()->customer->p;
$check('اصفهان' === $c['shipping_city'] && 'اصفهان' === $c['billing_city'] && 'IR' === $c['billing_country'] && WC()->customer->saved, 'the address is on the WooCommerce customer');
$check('علی' === $c['shipping_first_name'] && '09131234567' === $c['shipping_phone'], 'the recipient goes to the shipping fields');
$check(!isset($c['billing_first_name']) && !isset($c['billing_phone']), "billing name and phone (the account holder's) are not touched");
$check(in_array('vkl_shipping_address_chosen', $GLOBALS['actions'], true), 'the chosen address is announced for distance-priced shipping');

// ---- Order location from the step's address ------------------------------
class VKL_Test_Order
{
    public array $m = [];
    public function get_customer_id() { return 7; }
    public function update_meta_data($k, $v) { $this->m[$k] = $v; }
}
$_POST = []; // the checkout form posted no picker value
$order = new VKL_Test_Order();
(new VKL_Wc_Addresses())->save_order_location($order);
$check('32.650000' === ($order->m[VKL_Wc_Addresses::ORDER_LAT] ?? '') && 'محل کار' === ($order->m[VKL_Wc_Addresses::ORDER_LABEL] ?? ''), 'the order gets the location of the address chosen at the step');
$check('work' === VKL_Wc_Addresses::session_address_id(), 'the checkout picker preselects the address chosen at the step');

// ---- Reset -----------------------------------------------------------------
$step->reset();
$check(null === $session->get(VKL_Wc_Shipping_Step::S_DONE) && null === $session->get(VKL_Wc_Shipping_Step::S_ADDRESS), 'after the order (or an emptied cart) the next purchase starts at the step');

// ---- Disabled --------------------------------------------------------------
$off = new VKL_Config();
$off->v['shipping_step_page'] = 99; // not a published page
$check(!(new VKL_Wc_Shipping_Step($off))->enabled(), 'without a published page the step stays off');

echo $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
