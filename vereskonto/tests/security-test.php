<?php
/**
 * Plain-PHP test (no WordPress): 2.15.2 security fixes.
 *  - strict, timing-safe OTP comparison (no "012345" == "12345")
 *  - OTP rate limit per number AND per real client IP
 *  - CF-Connecting-IP trusted only from Cloudflare's own ranges
 *  - REST verify-otp honours the wrong-code lockout
 *  - shared Jalali calendar
 *
 * Run: php tests/security-test.php  — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);

// ---- WordPress stand-ins -------------------------------------------------
$GLOBALS['vkl_t'] = [];
$GLOBALS['vkl_o'] = [];
function get_transient($k) { return $GLOBALS['vkl_t'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['vkl_t'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['vkl_t'][$k]); return true; }
function get_option($k, $d = false) { return $GLOBALS['vkl_o'][$k] ?? $d; }
function apply_filters($tag, $value, ...$args) { return $value; }
function __($t, $d = '') { return $t; }
function sanitize_text_field($t) { return trim((string) $t); }
function is_wp_error($v) { return $v instanceof WP_Error; }

class WP_Error
{
    public array $data;
    public function __construct(public string $code = '', public string $message = '', $data = []) { $this->data = (array) $data; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}

class WP_REST_Response
{
    public function __construct(public $data = null) {}
}

class WP_REST_Request
{
    public function __construct(private array $params = []) {}
    public function get_param(string $k) { return $this->params[$k] ?? null; }
    public function get_header(string $k) { return ''; }
}

require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/otp.php';
require __DIR__ . '/../core/jalali.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

// --- OTP comparison -------------------------------------------------------
$check(vkl_otp_matches('12345', '12345'), 'exact code matches');
$check(vkl_otp_matches('12345', ' 12345 '), 'surrounding spaces ignored');
$check(vkl_otp_matches('01234', '۰۱۲۳۴'), 'Persian digits accepted');
$check(vkl_otp_matches('56789', '٥٦٧٨٩'), 'Arabic digits accepted');
$check(!vkl_otp_matches('012345', '12345'), 'leading zero is significant (old loose != said equal)');
$check(!vkl_otp_matches('12345', '12345.0'), 'numeric look-alike rejected');
$check(!vkl_otp_matches('12345', '1e4'), 'scientific notation rejected');
$check(!vkl_otp_matches('', ''), 'empty never matches');
$check(!vkl_otp_matches(false, '12345'), 'missing stored code never matches');

// --- CIDR + client IP -----------------------------------------------------
$check(vkl_ip_in_cidr('104.16.5.9', '104.16.0.0/13'), 'IPv4 inside /13');
$check(!vkl_ip_in_cidr('104.24.0.1', '104.16.0.0/13'), 'IPv4 just outside /13');
$check(vkl_ip_in_cidr('2606:4700:10::1', '2606:4700::/32'), 'IPv6 inside /32');
$check(vkl_ip_in_cidr('2a06:98c7::1', '2a06:98c0::/29'), 'IPv6 inside /29 (partial byte)');
$check(!vkl_ip_in_cidr('2a06:98c8::1', '2a06:98c0::/29'), 'IPv6 just outside /29');
$check(!vkl_ip_in_cidr('104.16.5.9', '2606:4700::/32'), 'v4 never matches a v6 range');
$check(!vkl_ip_in_cidr('not-an-ip', '104.16.0.0/13'), 'garbage is not in any range');

$_SERVER = ['REMOTE_ADDR' => '5.160.10.20'];
$check('5.160.10.20' === vkl_client_ip(), 'direct visitor: REMOTE_ADDR');

$_SERVER = ['REMOTE_ADDR' => '5.160.10.20', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4'];
$check('5.160.10.20' === vkl_client_ip(), 'forged CF header from a non-Cloudflare address is ignored');

$_SERVER = ['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => '5.160.10.20'];
$check('5.160.10.20' === vkl_client_ip(), 'via Cloudflare edge: real visitor IP');

$_SERVER = ['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => 'junk'];
$check('172.68.1.1' === vkl_client_ip(), 'invalid CF header falls back to REMOTE_ADDR');

$_SERVER = [];
$check('' === vkl_client_ip(), 'no address known: empty');

// --- Rate limit: per number and per IP ------------------------------------
$GLOBALS['vkl_o'] = ['vkl_otp_max_requests' => 5, 'vkl_otp_max_requests_ip' => 7];
$GLOBALS['vkl_t'] = [];
$_SERVER = ['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => '5.160.10.20'];

$sent = 0;
for ($i = 0; $i < 8; $i++) {
    $sent += vkl_can_send_otp('0912000000' . $i % 10) ? 1 : 0;
}
$check(7 === $sent, 'one IP, many numbers: stops at the IP limit (7)');

$GLOBALS['vkl_t'] = [];
$sent = 0;
for ($i = 0; $i < 6; $i++) {
    $sent += vkl_can_send_otp('09120000001') ? 1 : 0;
}
$check(5 === $sent, 'one number: stops at the per-number limit (5)');
$check(5 === (int) get_transient('vkl_rate_ip_' . md5('5.160.10.20')), 'refused request did not count against the IP');

$_SERVER = ['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => '5.160.10.99'];
$check(vkl_can_send_otp('09120000002'), 'another visitor behind the same Cloudflare edge is not blocked');

$GLOBALS['vkl_o']['vkl_otp_max_requests_ip'] = 0;
$GLOBALS['vkl_t'] = [];
$_SERVER = ['REMOTE_ADDR' => '5.160.10.20'];
$sent = 0;
for ($i = 0; $i < 40; $i++) {
    $sent += vkl_can_send_otp('0913' . str_pad((string) $i, 7, '0', STR_PAD_LEFT)) ? 1 : 0;
}
$check(40 === $sent, 'IP limit 0 = off (old behaviour)');

// --- Lockout --------------------------------------------------------------
$GLOBALS['vkl_o'] = ['vkl_otp_max_fail' => 3];
$GLOBALS['vkl_t'] = [];
vkl_add_failed_attempt('09121111111');
vkl_add_failed_attempt('09121111111');
$check(!vkl_otp_locked('09121111111'), 'not locked below the limit');
vkl_add_failed_attempt('09121111111');
$check(vkl_otp_locked('09121111111'), 'locked at the limit');
$check(!vkl_otp_locked('09122222222'), 'lockout is per number');

// --- REST verify-otp honours the lockout ----------------------------------
// The class is loaded with just enough stand-ins to call verify_otp().
class VKL_Validator
{
    public function normalize_mobile(string $m): string { return vkl_normalize_mobile($m); }
    public function is_mobile(string $m): bool { return vkl_is_mobile($m); }
}
class VKL_Config
{
    public function get_int(string $k, int $d = 0): int { return $d; }
    public function get_bool(string $k, bool $d = false): bool { return $d; }
}
class VKL_Api_Tokens
{
    public int $issued = 0;
    public function issue(int $u, string $d, int $t): string { $this->issued++; return 'tok'; }
}
function add_action(...$a) {}
function get_userdata($id) { return false; }

$rest_src = (string) file_get_contents(__DIR__ . '/../modules/api/rest.php');
// Replace the user-provisioning call (needs WordPress) with a fixed id.
$rest_src = str_replace('$user_id = $this->provision_user($mobile);', '$user_id = 7;', $rest_src);
$rest_src = str_replace("'user'       => \$this->user_payload(\$user_id),", "'user' => [],", $rest_src);
$tmp = tempnam(sys_get_temp_dir(), 'vklrest');
file_put_contents($tmp, $rest_src);
require $tmp;
unlink($tmp);

$tokens = new VKL_Api_Tokens();
$api    = new VKL_Api_Rest(new VKL_Config(), new VKL_Validator(), $tokens);

$GLOBALS['vkl_o'] = ['vkl_otp_max_fail' => 3];
$GLOBALS['vkl_t'] = [];
$mobile = '09123334444';
vkl_save_otp($mobile, '24680');

$codes = 0;
for ($i = 0; $i < 3; $i++) {
    $r = $api->verify_otp(new WP_REST_Request(['mobile' => $mobile, 'code' => '1111' . $i]));
    $codes += ($r instanceof WP_Error && 'vkl_bad_code' === $r->code) ? 1 : 0;
}
$check(3 === $codes, 'REST: wrong codes rejected and counted');

$r = $api->verify_otp(new WP_REST_Request(['mobile' => $mobile, 'code' => '24680']));
$check($r instanceof WP_Error && 'vkl_locked' === $r->code && 429 === ($r->data['status'] ?? 0), 'REST: correct code refused while locked (429)');
$check(0 === $tokens->issued, 'REST: no token issued while locked');

$GLOBALS['vkl_t'] = [];
vkl_save_otp($mobile, '24680');
vkl_add_failed_attempt($mobile);
$r = $api->verify_otp(new WP_REST_Request(['mobile' => $mobile, 'code' => '۲۴۶۸۰']));
$check($r instanceof WP_REST_Response && 'tok' === ($r->data['token'] ?? ''), 'REST: correct code (Persian digits) signs in');
$check(0 === vkl_get_failed_attempts($mobile), 'REST: success resets the wrong-code counter');
$check(false === vkl_get_otp($mobile), 'REST: code is single-use');

// --- Jalali ---------------------------------------------------------------
$check([1405, 9, 1] === VKL_Jalali::g2j(2026, 11, 22), 'g2j 2026-11-22 = 1405/09/01');
$check([1403, 1, 1] === VKL_Jalali::g2j(2024, 3, 20), 'g2j Nowruz 1403');
$check([2026, 11, 22] === VKL_Jalali::j2g(1405, 9, 1), 'j2g round trip');
$check('2026-11-22' === VKL_Jalali::parse('۱۴۰۵/۰۹/۰۱'), 'parse Persian-digit date');
$check('' === VKL_Jalali::parse('1404/12/30'), 'parse rejects Esfand 30 in a non-leap year');
$check('2025-03-20' === VKL_Jalali::parse('1403/12/30'), 'parse accepts Esfand 30 in leap year 1403');
$check('' === VKL_Jalali::parse('1405/07/31'), 'parse rejects day 31 in the second half-year');
$check('1 آذر 1405' === VKL_Jalali::format('2026-11-22 08:00:00'), 'format long');
$check('1405/09/01' === VKL_Jalali::format('2026-11-22 08:00:00', 'short'), 'format short');
$check('—' === VKL_Jalali::format('0000-00-00 00:00:00'), 'format empty date');

$ok = true;
for ($ts = strtotime('2015-01-01'); $ts < strtotime('2035-01-01'); $ts += DAY_IN_SECONDS) {
    [$y, $m, $d] = array_map('intval', explode('-', gmdate('Y-n-j', $ts)));
    if (VKL_Jalali::j2g(...VKL_Jalali::g2j($y, $m, $d)) !== [$y, $m, $d]) { $ok = false; break; }
}
$check($ok, 'g2j/j2g round trip for every day 2015–2034');

echo $failures ? "\n{$failures} FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
