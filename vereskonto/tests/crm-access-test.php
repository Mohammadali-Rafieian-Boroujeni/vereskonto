<?php
/**
 * Plain-PHP test (no WordPress): CRM access rules, contact validation
 * and tag slugs.
 *
 * Run: php tests/crm-access-test.php  — exits 1 on failure.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

// ---- WordPress stand-ins -------------------------------------------------
$GLOBALS['caps']    = [];   // user id => caps
$GLOBALS['current'] = 0;
$GLOBALS['opts']    = [];

function get_current_user_id() { return $GLOBALS['current']; }
function user_can($user_id, $cap) { return in_array($cap, $GLOBALS['caps'][$user_id] ?? [], true); }
function current_user_can($cap) { return user_can($GLOBALS['current'], $cap); }
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function apply_filters($t, $v, ...$a) { return $v; }
function __($t, $d = '') { return $t; }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_email($e) { return trim((string) $e); }
function is_email($e) { return false !== filter_var($e, FILTER_VALIDATE_EMAIL); }
function is_wp_error($v) { return $v instanceof WP_Error; }

// WordPress's own fallback (wp-includes/compat.php) when mbstring is off.
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null) {
        preg_match_all('/./us', (string) $s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}

class WP_Error
{
    public array $data;
    public function __construct(public string $code = '', public string $message = '', $data = []) { $this->data = (array) $data; }
}

/** In-memory contacts repository with just what the service needs. */
class VKL_Crm_Contacts
{
    public const LIFECYCLES = ['lead', 'prospect', 'customer', 'inactive', 'lost'];
    public array $rows = [];
    private int $next = 1;

    public function find_by_mobile(string $m): ?array
    {
        foreach ($this->rows as $r) { if (($r['mobile'] ?? null) === $m) return $r; }
        return null;
    }
    public function create(array $d): int { $id = $this->next++; $this->rows[$id] = $d + ['id' => $id, 'deleted_at' => null]; return $id; }
    public function update(int $id, array $d): bool { $this->rows[$id] = $d + $this->rows[$id]; return true; }
    public function restore(int $id): bool { $this->rows[$id]['deleted_at'] = null; return true; }
}

require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../core/jalali.php';
require __DIR__ . '/../modules/crm/access.php';
require __DIR__ . '/../modules/crm/services/contacts.php';

// Tags::slugify without loading the repository base classes.
$tags_src = (string) file_get_contents(__DIR__ . '/../modules/crm/repositories/tags.php');
preg_match('/public static function slugify.*?\n    }\n/s', $tags_src, $m);
eval('final class Slug { ' . $m[0] . ' }');

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

// --- Role matrix ----------------------------------------------------------
$matrix = VKL_Crm_Access::matrix();
$check($matrix['vkl_sales_agent'] === ['vkl_crm_access', 'vkl_crm_edit_own'], 'agent: access + edit own only');
$check(!in_array('vkl_crm_settings', $matrix['vkl_sales_manager'], true), 'manager: no CRM settings');
$check(in_array('vkl_crm_edit_all', $matrix['vkl_sales_manager'], true), 'manager: edits everyone\'s records');
$check($matrix['shop_manager'] === $matrix['vkl_sales_manager'], 'shop manager = sales manager');
$check(count($matrix['administrator']) === 7, 'administrator: all 7 capabilities');

// --- Record-level rules ---------------------------------------------------
const AGENT = 10, AGENT2 = 11, MANAGER = 20, CUSTOMER = 30;
$GLOBALS['caps'] = [
    AGENT    => $matrix['vkl_sales_agent'],
    AGENT2   => $matrix['vkl_sales_agent'],
    MANAGER  => $matrix['vkl_sales_manager'],
    CUSTOMER => [],
];

$a     = new VKL_Crm_Access();
$mine  = ['owner_id' => AGENT];
$other = ['owner_id' => AGENT2];
$free  = ['owner_id' => 0];
$task  = ['assignee_id' => AGENT];

$check($a->can_view($mine, AGENT) && $a->can_edit($mine, AGENT), 'agent sees and edits own record');
$check(!$a->can_view($other, AGENT) && !$a->can_edit($other, AGENT), 'agent cannot see or edit a colleague\'s record');
$check($a->can_view($free, AGENT), 'agent sees unassigned records (default)');
$check(!$a->can_edit($free, AGENT), 'agent must claim before editing an unassigned record');
$check($a->can_claim($free, AGENT) && !$a->can_claim($other, AGENT), 'agent may claim unassigned, not a colleague\'s');
$check($a->can_assign($free, AGENT, AGENT), 'claim = assign to self');
$check(!$a->can_assign($free, AGENT2, AGENT), 'agent cannot hand records to others');
$check($a->can_assign($other, AGENT, MANAGER), 'manager reassigns anything');
$check(!$a->can_assign($mine, CUSTOMER, MANAGER), 'nobody can assign to a user without CRM access');
$check($a->can_view($task, AGENT) && !$a->can_view($task, AGENT2), 'tasks use assignee_id as owner');
$check(!$a->can_delete($mine, AGENT) && $a->can_delete($other, MANAGER), 'only managers delete');
$check(!$a->can_view($mine, CUSTOMER), 'customer sees nothing');
$check($a->can_view($other, MANAGER) && $a->can_edit($other, MANAGER), 'manager sees and edits everything');

$GLOBALS['opts']['vkl_crm_agents_see_unassigned'] = '0';
// Stand-in config: VKL_Crm_Access reads it via vkl('config') when available.
function vkl($id = null) { return new class { public function get_bool($k, $d) { return ($GLOBALS['opts']['vkl_' . $k] ?? ($d ? '1' : '0')) === '1'; } }; }
$check(!$a->can_view($free, AGENT), 'setting off: agents no longer see unassigned');
$GLOBALS['opts']['vkl_crm_agents_see_unassigned'] = '1';

// --- scope_sql ------------------------------------------------------------
$check(['1=1', []] === $a->scope_sql('t.owner_id', MANAGER), 'scope: manager unrestricted');
$check(['(t.owner_id = %d OR t.owner_id = 0)', [AGENT]] === $a->scope_sql('t.owner_id', AGENT), 'scope: agent own + unassigned');
$check(['1=0', []] === $a->scope_sql('t.owner_id', CUSTOMER), 'scope: no access → nothing');
$check(['1=0', []] === $a->scope_sql('owner_id; DROP TABLE x', MANAGER), 'scope: column name is validated');

// --- Contact validation ---------------------------------------------------
$repo = new VKL_Crm_Contacts();
$svc  = new VKL_Crm_Contact_Service($repo);

$s = $svc->sanitize(['first_name' => ' <b>علی</b> ', 'mobile' => '+98 912 123 4567', 'email' => 'Ali@Example.COM', 'lifecycle' => 'prospect', 'source' => 'Chat Bot!', 'score' => 5000]);
$check(!is_wp_error($s) && 'علی' === $s['first_name'], 'name trimmed and stripped of tags');
$check('09121234567' === ($s['mobile'] ?? ''), '+98 mobile normalised to 09…');
$check('ali@example.com' === ($s['email'] ?? ''), 'email lower-cased');
$check('chatbot' === ($s['source'] ?? ''), 'source sanitised to a key');
$check(1000 === ($s['score'] ?? 0), 'score clamped');
$check('09121234567' === ($svc->sanitize(['mobile' => '۰۹۱۲۱۲۳۴۵۶۷'])['mobile'] ?? ''), 'Persian-digit mobile accepted');
$check(null === $svc->sanitize(['mobile' => ''])['mobile'], 'empty mobile stored as NULL (keeps UNIQUE key happy)');
$check(is_wp_error($svc->sanitize(['mobile' => '12345'])), 'invalid mobile rejected');
$check(is_wp_error($svc->sanitize(['email' => 'not-an-email'])), 'invalid email rejected');
$check(is_wp_error($svc->sanitize(['lifecycle' => 'vip'])), 'unknown lifecycle rejected');
$check([] === $svc->sanitize(['owner' => 5, 'hack' => 'x']), 'unknown fields dropped');
$check('2026-11-22' === ($svc->sanitize(['birthday' => '1405/09/01'])['birthday'] ?? ''), 'Jalali birthday converted');
$check('1990-02-28' === ($svc->sanitize(['birthday' => '1990-02-28'])['birthday'] ?? ''), 'Gregorian birthday kept');
$check(is_wp_error($svc->sanitize(['birthday' => '1990-02-30'])), 'impossible date rejected');

// --- Create / duplicates --------------------------------------------------
$check(is_wp_error($svc->create([])), 'empty contact rejected');
$id1 = $svc->create(['first_name' => 'سارا', 'mobile' => '09120000001']);
$check(is_int($id1) && 'lead' === $repo->rows[$id1]['lifecycle'], 'new contact defaults to lead');
$dup = $svc->create(['first_name' => 'دیگری', 'mobile' => '0912 000 0001']);
$check(is_wp_error($dup) && 409 === $dup->data['status'] && $id1 === $dup->data['contact_id'], 'duplicate mobile → 409 with existing id');
$repo->rows[$id1]['deleted_at'] = '2026-01-01 00:00:00';
$again = $svc->create(['first_name' => 'سارا ۲', 'mobile' => '09120000001']);
$check($again === $id1 && null === $repo->rows[$id1]['deleted_at'] && 'سارا ۲' === $repo->rows[$id1]['first_name'], 'deleted contact with same mobile is restored, not duplicated');
$id2 = $svc->create(['company' => 'شرکت نمونه']);
$check(is_int($id2) && true === $svc->update($id2, ['city' => 'تهران']), 'company-only contact ok; update ok');
$u = $svc->update($id2, ['mobile' => '09120000001']);
$check(is_wp_error($u) && 409 === $u->data['status'], 'update cannot take another contact\'s mobile');
$check(true === $svc->update($id1, ['mobile' => '09120000001']), 'update keeping own mobile is fine');

// --- Tag slugs ------------------------------------------------------------
$check('مشتری-ویژه' === Slug::slugify('  مشتری ویژه! '), 'Persian slug keeps letters');
$check('vip-2' === Slug::slugify('VIP 2'), 'Latin slug lower-cased');
$check(12 === strlen(Slug::slugify('!!!')), 'symbol-only name gets a hash slug');

// --- CSV export cells (formula injection) ---------------------------------
require __DIR__ . '/../modules/crm/admin/reports-page.php';
$check("'=HYPERLINK(1)" === VKL_Crm_Reports_Page::cell('=HYPERLINK(1)'), 'CSV: leading = neutralised');
$check("'@SUM(A1)" === VKL_Crm_Reports_Page::cell('@SUM(A1)'), 'CSV: leading @ neutralised');
$check("'-2+3" === VKL_Crm_Reports_Page::cell('-2+3'), 'CSV: formula starting with - neutralised');
$check('-1500' === VKL_Crm_Reports_Page::cell(-1500), 'CSV: negative numbers untouched');
$check('مریم' === VKL_Crm_Reports_Page::cell('مریم'), 'CSV: Persian text untouched');

echo $failures ? "\n{$failures} FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
