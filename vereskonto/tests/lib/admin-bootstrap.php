<?php
/**
 * Shared WordPress stand-ins for the plain-PHP admin tests (no WordPress).
 * Collaborators return empty data; tests check structure, not figures.
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('VKL_VERSION', 'test');
define('VKL_PATH', dirname(__DIR__, 2) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// ---- WordPress stand-ins -------------------------------------------------
function __($t, $d = '') { return $t; }
function _x($t, $c, $d = '') { return $t; }
function esc_html__($t, $d = '') { return htmlspecialchars((string) $t); }
function esc_attr__($t, $d = '') { return htmlspecialchars((string) $t); }
function esc_html_e($t, $d = '') { echo htmlspecialchars((string) $t); }
function esc_attr_e($t, $d = '') { echo htmlspecialchars((string) $t); }
function _e($t, $d = '') { echo $t; }
function _n($s, $p, $n, $d = '') { return 1 === $n ? $s : $p; }
function esc_html($t) { return htmlspecialchars((string) $t); }
function esc_attr($t) { return htmlspecialchars((string) $t); }
function esc_textarea($t) { return htmlspecialchars((string) $t); }
function esc_url($t) { return (string) $t; }
function esc_url_raw($t) { return (string) $t; }
function esc_js($t) { return addslashes((string) $t); }
function wp_kses_post($t) { return (string) $t; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_text_field($t) { return trim((string) $t); }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, (int) $d); }
function admin_url($p = '') { return 'https://shop.test/wp-admin/' . $p; }
function add_query_arg(...$args) {
    if (is_array($args[0])) { [$params, $url] = [$args[0], $args[1] ?? '']; } else { [$params, $url] = [[$args[0] => $args[1]], $args[2] ?? '']; }
    [$base, $q] = array_pad(explode('?', (string) $url, 2), 2, '');
    parse_str($q, $cur);
    return $base . '?' . http_build_query(array_merge($cur, $params));
}
function remove_query_arg($k, $u) { return $u; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function current_user_can($c) { return true; }
function settings_fields($g) { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
function submit_button($t = null, $type = 'primary', $name = 'submit', $wrap = true) { echo $wrap ? '<p class="submit"><input type="submit"></p>' : '<input type="submit">'; }
function checked($a, $b = true, $e = true) { return ''; }
function wp_dropdown_pages($a = []) { $h = '<select name="' . ($a['name'] ?? '') . '"></select>'; if (!empty($a['echo'])) { echo $h; } return $h; }
function selected($a, $b = true, $e = true) { return ''; }
function disabled($a, $b = true, $e = true) { return ''; }
function get_option($k, $d = false) { return $GLOBALS['test_options'][$k] ?? $d; }
function get_transient($k) { return false; }
function get_userdata($id) { return $GLOBALS['test_users'][$id] ?? false; }
function get_users($a = []) { return []; }
function get_current_user_id() { return 1; }
function wp_nonce_field(...$a) {}
function wp_get_referer() { return $GLOBALS['test_referer'] ?? false; }
function get_current_screen() { return (object) ['id' => 'vereskonto_page_' . ($_GET['page'] ?? '')]; }
function wc_price($p) { return (string) $p; }
function wc_get_orders($a = []) { return $GLOBALS['test_orders'] ?? []; }
function add_action(...$a) {}
function add_filter(...$a) {}
function apply_filters($h, $v) { return $v; }
function do_action(...$a) {}
function mysql2date($f, $d) { return (string) $d; }
function date_i18n($f, $t = 0) { return ''; }
function wp_date($f, $t = null) { return ''; }
function wp_remote_get($u, $a = []) { return ['response' => ['code' => 0], 'body' => '']; }
function wp_remote_post($u, $a = []) { return ['response' => ['code' => 0], 'body' => '']; }
function wp_remote_retrieve_response_code($r) { return 0; }
function wp_remote_retrieve_body($r) { return ''; }
function is_wp_error($v) { return false; }
function set_transient($k, $v, $e = 0) { return true; }

/** Returns empty data for anything asked of it. */
class VKL_Test_Stub
{
    public const ADMIN_CAP = 'manage_options';
    public function __call($name, $args)
    {
        if (str_starts_with($name, 'current_user_can')) { return true; }
        if ('stats' === $name) { return ['users' => 0, 'total' => 0, 'rewarded' => 0, 'paid' => 0, 'sent' => 0, 'failed' => 0]; }
        if (str_starts_with($name, 'get_bool') || str_starts_with($name, 'has') || str_starts_with($name, 'is_') || str_starts_with($name, 'enabled')) { return false; }
        if (str_starts_with($name, 'get_int') || str_ends_with($name, 'count') || str_starts_with($name, 'count')) { return 0; }
        if (str_starts_with($name, 'get_float')) { return 0.0; }
        if (in_array($name, ['get', 'option_name', 'get_string', 'admin_nonce_field'], true)) { return 'admin_nonce_field' === $name ? null : (string) ($args[0] ?? ''); }
        return [];
    }
}
foreach (['VKL_Config', 'VKL_Security', 'VKL_Wallet_Service', 'VKL_Wallet_Repository', 'VKL_Wallet_Installments', 'VKL_Loyalty_Repository', 'VKL_Survey_Repository', 'VKL_Smart_Chatlog'] as $vkl_cls) {
    eval("class $vkl_cls extends VKL_Test_Stub {}");
}

/** Minimal container: services exist and return stubs. */
class VKL_Test_Container
{
    public function has(string $id): bool { return true; }
}
function vkl(?string $service = null)
{
    static $c = null;
    $c = $c ?: new VKL_Test_Container();
    return null === $service ? $c : new VKL_Test_Stub();
}

require VKL_PATH . 'core/menu.php';
require VKL_PATH . 'core/admintabs.php';
