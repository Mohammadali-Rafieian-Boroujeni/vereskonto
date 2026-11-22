<?php
/**
 * Plain-PHP test (no WordPress): the Today dashboard, the Customers list
 * and profile, the All-messages table (render + save) and the settings
 * search index all run without PHP errors and with balanced markup.
 *
 * Run: php tests/phase3-test.php — exits 1 on failure.
 */

declare(strict_types=1);

require __DIR__ . '/lib/admin-bootstrap.php';

// ---- Extra stand-ins for these screens ---------------------------------
class WP_User
{
    public $ID; public $display_name; public $user_login; public $user_email; public $user_registered;
    public function __construct(int $id) { $this->ID = $id; $this->display_name = 'مریم احمدی'; $this->user_login = 'u' . $id; $this->user_email = "u$id@example.test"; $this->user_registered = '2026-01-02 10:00:00'; }
}
class WP_User_Query
{
    public static $last = [];
    private $args;
    public function __construct(array $args) { $this->args = $args; self::$last = $args; }
    public function get_results() { return [new WP_User(5), new WP_User(6)]; }
    public function get_total() { return 45; }
}
class VKL_Test_Redirect extends Exception {}
function wp_safe_redirect($url) { throw new VKL_Test_Redirect((string) $url); }
function update_option($k, $v, $a = null) { $GLOBALS['test_options'][$k] = $v; $GLOBALS['test_writes'][] = $k; return true; }
function get_user_meta($id, $k, $s = false) { return 'billing_phone' === $k ? '09121234567' : ''; }
function get_edit_user_link($id) { return admin_url('user-edit.php?user_id=' . $id); }
function wc_get_customer_order_count($id) { return 3; }
function wc_get_customer_total_spent($id) { return 1250000.0; }
function wc_get_order_status_name($s) { return 'status:' . $s; }
function wc_get_order($id) { return false; }
function wp_next_scheduled($h) { return time() + 3600; }
function wp_timezone_string() { return 'Asia/Tehran'; }
function wp_trim_words($t, $n = 55) { return (string) $t; }

/** Config whose values live in $GLOBALS['test_options'] under the vkl_ prefix. */
class VKL_Test_Config extends VKL_Config
{
    public function option_name(string $k): string { return 'vkl_' . $k; }
    public function get_bool(string $k, bool $d = false): bool { return in_array($GLOBALS['test_options']['vkl_' . $k] ?? $d, [true, 1, '1'], true); }
    public function get_string(string $k, string $d = ''): string { return trim((string) ($GLOBALS['test_options']['vkl_' . $k] ?? $d)); }
}

require VKL_PATH . 'core/today.php';
require VKL_PATH . 'core/customers.php';
require VKL_PATH . 'core/messages.php';
require VKL_PATH . 'core/search.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};
$render = static function (callable $fn): array {
    ob_start();
    try {
        $fn();
        return [(string) ob_get_clean(), ''];
    } catch (Throwable $e) {
        ob_end_clean();
        return ['', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
    }
};
$balanced = static function (string $html): string {
    $html = preg_replace('~<script\b.*?</script>~s', '', $html);
    foreach (['div', 'form', 'table', 'ul', 'a', 'nav'] as $tag) {
        $open  = preg_match_all('~<' . $tag . '\b~i', $html);
        $close = preg_match_all('~</' . $tag . '>~i', $html);
        if ($open !== $close) { return "$tag $open/$close"; }
    }
    return '';
};
$screen = static function (string $name, callable $fn) use ($render, $balanced, $check): string {
    [$html, $err] = $render($fn);
    $check('' === $err, "$name renders" . ('' !== $err ? " — $err" : ''));
    if ('' === $err) {
        $b = $balanced($html);
        $check('' === $b, "$name tags balanced $b");
    }
    return $html;
};

// ---- Menu: seven sections (2.21.0 folded "CRM" into "Customers"). -------
$keys = array_column(VKL_Admin_Menu::groups(), 'key');
$check($keys === ['today', 'inbox', 'customers', 'finance', 'marketing', 'reports', 'settings'], 'menu has the seven sections in order');
$groups = array_column(VKL_Admin_Menu::groups(), 'items', 'key');
$check(isset($groups['today']['vkl-crm'], $groups['customers']['vkl-customers'], $groups['customers']['vkl-crm-contacts'], $groups['reports']['vkl-crm-reports']), 'dashboards together, people together, all reports together');

// ---- Today ---------------------------------------------------------------
$today = new VKL_Today_Admin();
$_GET  = ['page' => 'vkl-today'];
$html  = $screen('today', [$today, 'render']);
$check(str_contains($html, 'page=vkl-credit-customers&view=requests') && str_contains($html, 'page=vkl-tickets'), 'today links each waiting card to its screen');
$check(substr_count($html, 'class="vkl-card is-clear"') >= 1, 'today shows a done state when nothing is waiting');
$GLOBALS['test_options']['vkl_meli_username'] = '';
$check(false === $today->health()[0]['ok'], 'health flags a missing SMS gateway');
$GLOBALS['test_options']['vkl_meli_username'] = 'shop';
$GLOBALS['test_options']['vkl_meli_password'] = 'secret';
$check(false === $today->health()[0]['ok'], 'health flags an empty sign-in code pattern');
$GLOBALS['test_options']['vkl_pattern_code'] = '12345';
$check(true === $today->health()[0]['ok'], 'health passes with gateway credentials and pattern');
$GLOBALS['test_options']['vkl_api_key'] = 'legacy';
$GLOBALS['test_options']['vkl_meli_password'] = '';
$check(false === $today->health()[0]['ok'], 'legacy API key alone no longer counts as a gateway');
unset($GLOBALS['test_options']['vkl_api_key']);

// Waiting cards: work first, all-clear after.
$cards = $today->waiting();
$seen_clear = false;
$ordered = true;
foreach ($cards as $c) {
    if ($c['count'] <= 0) { $seen_clear = true; } elseif ($seen_clear) { $ordered = false; }
}
$check($ordered, 'waiting cards list pending work before all-clear ones');

// Figures: seven days, sales only.
$f = $today->figures();
$check(7 === count($f['days']) && 0 === $f['orders'] && null === $f['aov'], 'figures cover seven days; no orders means no average');

// With orders: today vs yesterday by the same hour, the trend and the table.
class VKL_Test_Date { private $ts; public function __construct(int $ts) { $this->ts = $ts; } public function getTimestamp() { return $this->ts; } }
class VKL_Test_Order
{
    public function __construct(private int $id, private int $ts, private float $total, private int $uid = 5) {}
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_date_created() { return new VKL_Test_Date($this->ts); }
    public function get_total() { return $this->total; }
    public function get_customer_id() { return $this->uid; }
    public function get_formatted_billing_full_name() { return 'مریم احمدی'; }
    public function get_status() { return 'processing'; }
}
$tz       = new DateTimeZone('Asia/Tehran');
$midnight = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->getTimestamp();
$GLOBALS['test_orders'] = [
    new VKL_Test_Order(101, time() - 5, 300000.0),
    new VKL_Test_Order(102, time() - 10, 100000.0, 0),
    new VKL_Test_Order(90, $midnight - 86400 + 60, 200000.0),          // yesterday, early: counts "by now"
    new VKL_Test_Order(80, $midnight - 3 * 86400 + 3600, 50000.0),     // within the week
    new VKL_Test_Order(10, $midnight - 30 * 86400, 999999.0),          // outside the week: ignored
];
$f = $today->figures();
$check(2 === $f['orders'] && 400000.0 === $f['revenue'] && 200000.0 === $f['aov'], 'today: two orders, sum and average');
$check(1 === $f['y_orders'] && 200000.0 === $f['y_revenue'], 'yesterday by the same hour');
$check(650000.0 === array_sum(array_column($f['days'], 'revenue')), 'week total ignores older orders');
$html = $screen('today with orders', [$today, 'render']);
$check(str_contains($html, 'vkl-bars') && str_contains($html, 'page=vkl-customers&customer=5') && str_contains($html, '#101'), 'today shows the trend and latest orders linked to the customer');
$check(str_contains($html, '▲ 100٪'), 'today shows the change against yesterday');
unset($GLOBALS['test_orders']);

// ---- Customers -------------------------------------------------------------
$cust = new VKL_Customers_Admin();
$_GET = ['page' => 'vkl-customers'];
$html = $screen('customers list', [$cust, 'render']);
$check(str_contains($html, 'customer=5') && str_contains($html, 'customer=6'), 'list links each customer to a profile');
$check(str_contains($html, 'paged=2'), 'list paginates (45 customers, 20 per page)');

$cust->search('0912 123', 1);
$check(isset(WP_User_Query::$last['meta_query']) && !isset(WP_User_Query::$last['search']), 'a phone-like search matches the mobile number');
$cust->search('maryam', 1);
$check(isset(WP_User_Query::$last['search']) && !isset(WP_User_Query::$last['meta_query']), 'a name search matches name and email');

$GLOBALS['test_users'][5] = new WP_User(5);
foreach (['overview', 'orders', 'wallet', 'credit', 'club', 'support', 'messages'] as $view) {
    $_GET = ['page' => 'vkl-customers', 'customer' => '5', 'view' => $view];
    $html = $screen("profile › $view", [$cust, 'render']);
    if ('overview' === $view) {
        $check(str_contains($html, 'value="vkl_wallet_adjust"') && str_contains($html, 'name="vkl_user_id" value="5"'), 'profile wallet modal is bound to this customer');
        $check(1 === preg_match('~href="[^"]*customer=5[^"]*view=messages"~', $html), 'profile tabs keep the customer id');
    }
}
$_GET = ['page' => 'vkl-customers', 'customer' => '999'];
$html = $screen('unknown customer falls back to the list', [$cust, 'render']);
$check(str_contains($html, 'id="vkl-customer-q"'), 'unknown customer id shows the list');

// ---- All messages ----------------------------------------------------------
$config = new VKL_Test_Config();
$msgs   = new VKL_Messages_Admin($config);
[$toggles, $patterns] = VKL_Messages_Admin::writable_keys();
$check(count($patterns) >= 30 && count($patterns) === count(array_unique($patterns)), 'message table covers 30+ messages, none twice');
$check(!in_array('wa_token', $patterns, true) && !in_array('admin_mobile', $patterns, true), 'only pattern codes are editable here, not gateway secrets');

$GLOBALS['test_options'] = ['vkl_order_sms_enabled' => '1', 'vkl_order_pattern_completed' => '111', 'vkl_ticket_sms_enabled' => '0'];
$_GET = ['page' => 'vkl-messages'];
$html = $screen('all messages', [$msgs, 'render']);
$check(substr_count($html, 'name="vkl_pattern[') === count($patterns), 'every message has a pattern field');
$check(str_contains($html, 'value="111"') && str_contains($html, 'vkl-state on'), 'a filled pattern under an enabled topic shows as active');
$check(str_contains($html, 'vkl-state warn'), 'an empty pattern is flagged as not sent');

// Save: only listed keys, only changes, untouched keys left alone.
$GLOBALS['test_writes'] = [];
$_POST = [
    'vkl_toggle'  => ['order_sms_enabled' => '1', 'ticket_sms_enabled' => '1', 'wa_token' => '1'],
    'vkl_pattern' => ['order_pattern_completed' => '111', 'order_pattern_processing' => ' 222 ', 'admin_mobile' => '0912', 'wa_token' => 'secret'],
];
$redirect = '';
try { $msgs->handle_save(); } catch (VKL_Test_Redirect $r) { $redirect = $r->getMessage(); }
$writes = $GLOBALS['test_writes'];
sort($writes);
$check($writes === ['vkl_order_pattern_processing', 'vkl_ticket_sms_enabled'], 'save writes exactly the two changed settings');
$check('222' === ($GLOBALS['test_options']['vkl_order_pattern_processing'] ?? null), 'pattern codes are trimmed');
$check(!isset($GLOBALS['test_options']['vkl_wa_token']) && !isset($GLOBALS['test_options']['vkl_admin_mobile']), 'save ignores options not in the table');
$check(str_contains($redirect, 'page=vkl-messages') && str_contains($redirect, 'vkl_saved=2'), 'save returns to the table with the change count');
$_POST = [];

// ---- Search index ----------------------------------------------------------
$index = VKL_Admin_Search::index();
$urls  = array_column($index, 'u');
$check(count($index) > 30 && in_array(admin_url('admin.php?page=vereskala-login&tab=login'), $urls, true), 'search index covers screens, settings and messages');
$check([] === array_filter($index, static fn($e) => '' === trim((string) $e['t']) || !str_contains((string) $e['u'], 'admin.php?page=')), 'every search entry has a title and an admin link');
$_GET = ['page' => 'vkl-today'];
[$html, $err] = $render([new VKL_Admin_Search(), 'render']);
$check('' === $err && str_contains($html, 'vkl-find') && str_contains($html, '"t":'), 'search box prints on Vereskonto screens' . ('' !== $err ? " — $err" : ''));
$_GET = ['page' => 'woocommerce'];
[$html] = $render([new VKL_Admin_Search(), 'render']);
$check('' === $html, 'search box stays off other plugins’ screens');

exit($failures > 0 ? 1 : 0);
