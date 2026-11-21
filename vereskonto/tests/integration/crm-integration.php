<?php
/**
 * Integration test for the CRM foundation — needs a real WordPress +
 * WooCommerce + MySQL site with Vereskonto active. DESTRUCTIVE: creates
 * and deletes users, orders and all CRM data. Never run it on a live
 * site; use a throwaway copy.
 *
 * Run:  VKL_WP_LOAD=/path/to/wp-load.php php tests/integration/crm-integration.php
 * Without VKL_WP_LOAD it is skipped (exit 0).
 */

declare(strict_types=1);

$wp_load = getenv('VKL_WP_LOAD');

if (!$wp_load || !is_readable($wp_load)) {
    echo "skipped (set VKL_WP_LOAD to a throwaway site's wp-load.php)\n";
    exit(0);
}

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $wp_load;

if ('1' !== getenv('VKL_I_KNOW_THIS_DELETES_DATA')) {
    echo "refusing: set VKL_I_KNOW_THIS_DELETES_DATA=1 (throwaway sites only)\n";
    exit(2);
}

global $wpdb;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

$wpdb->suppress_errors(true); // expected duplicate-key failures stay quiet
add_filter('send_auth_cookies', '__return_false'); // vkl_login_user() in a CLI run

$schema   = vkl('crm.schema');
$contacts = vkl('crm.contacts');
$service  = vkl('crm.contact_service');
$tags     = vkl('crm.tags');
$pipes    = vkl('crm.pipelines');
$deals    = vkl('crm.deals');
$acts     = vkl('crm.activities');
$tasks    = vkl('crm.tasks');
$access   = vkl('crm.access');
$backfill = vkl('crm.backfill');
$prefix   = $wpdb->prefix . 'vkl_';

$vkl_tables = static fn(): array => $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix) . '%'));
$crm_tables = static fn(): array => $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($prefix . 'crm_') . '%'));

// ---------------------------------------------------------------- schema
$check(VKL_Crm_Module::installed(), 'CRM installed by the first admin request');
$check(9 === count($crm_tables()), '9 CRM tables exist');
$check(VKL_Crm_Schema::VERSION === get_option(VKL_Crm_Schema::VERSION_OPTION), 'CRM schema version recorded');
$check('2.9.21' === get_option('vkl_db_version'), 'plugin-wide schema version untouched');
$before_tables = count($vkl_tables());
$check(37 === $before_tables, '28 existing + 9 CRM tables');

$pipe = $pipes->default_pipeline();
$check(null !== $pipe && 7 === count($pipes->stages((int) $pipe['id'])), 'default pipeline with 7 stages');
$schema->install();
$check(1 === count($pipes->all()) && 7 === count($pipes->stages((int) $pipe['id'])), 'install() again: no duplicate pipeline/stages');
$check(37 === count($vkl_tables()), 'install() again: same tables');

// ------------------------------------------------------------------ roles
$check(null !== get_role('vkl_sales_agent') && null !== get_role('vkl_sales_manager'), 'agent and manager roles created');
$check(get_role('administrator')->has_cap('vkl_crm_settings'), 'administrator has CRM settings');
$check(get_role('shop_manager')->has_cap('vkl_crm_edit_all') && !get_role('shop_manager')->has_cap('vkl_crm_settings'), 'shop manager: edit all, no settings');

$agent   = wp_insert_user(['user_login' => 'crm_agent', 'user_pass' => wp_generate_password(), 'role' => 'vkl_sales_agent', 'user_email' => 'agent@example.test']);
$agent2  = wp_insert_user(['user_login' => 'crm_agent2', 'user_pass' => wp_generate_password(), 'role' => 'vkl_sales_agent', 'user_email' => 'agent2@example.test']);
$manager = wp_insert_user(['user_login' => 'crm_manager', 'user_pass' => wp_generate_password(), 'role' => 'vkl_sales_manager', 'user_email' => 'manager@example.test']);
$service->flush_pending();
$check(null === $contacts->find_by_user($agent), 'staff accounts do not become contacts');
$check(array_key_exists($agent, $access->assignable_users()) && array_key_exists($manager, $access->assignable_users()), 'staff are assignable');

// --------------------------------------------------------------- contacts
// Start from an empty CRM (the site may already hold imported contacts).
foreach (['crm_contacts', 'crm_contact_meta', 'crm_contact_tag', 'crm_activities', 'crm_tasks', 'crm_deals'] as $tbl) {
    $wpdb->query("TRUNCATE {$prefix}{$tbl}");
}
$c1 = $service->create(['first_name' => 'مریم', 'last_name' => 'احمدی', 'mobile' => '09351112233', 'owner_id' => $agent, 'city' => 'تهران']);
$c2 = $service->create(['first_name' => 'Reza', 'last_name' => 'Karimi', 'mobile' => '09124445566', 'owner_id' => $agent2]);
$c3 = $service->create(['company' => 'شرکت آلفا', 'email' => 'info@alpha.test']);
$check(is_int($c1) && is_int($c2) && is_int($c3), 'three contacts created');

$raw = $contacts->create(['first_name' => 'dup', 'mobile' => '09351112233']);
$check(0 === $raw, 'database UNIQUE key blocks a duplicate mobile even past the service');

$all = static fn(array $f, int $uid = 0, int $pp = 20, int $page = 1) => $contacts->search($f, $access->scope_sql('t.owner_id', $uid ?: $GLOBALS['vkl_test_manager']), $page, $pp);
$GLOBALS['vkl_test_manager'] = $manager;

$check(1 === $all(['q' => 'مریم'])['total'], 'search Persian first name');
$check(1 === $all(['q' => 'مریم احمدی'])['total'], 'search full name');
$check(1 === $all(['q' => '+98 935 111 2233'])['total'], 'search mobile in +98 form');
$check(1 === $all(['q' => '2233'])['total'], 'search last digits of mobile');
$check(1 === $all(['q' => '۲۲۳۳'])['total'], 'search Persian digits');
$check(1 === $all(['q' => 'alpha.test'])['total'], 'search email');
$check(1 === $all(['q' => 'آلفا'])['total'], 'search company');
$check(0 === $all(['q' => "x' OR '1'='1"])['total'] && 3 === $all([])['total'], 'SQL in search is just text');
$check(1 === $all(['city' => 'تهران'])['total'], 'filter by city');

$agent_view = $all([], $agent);
$ids        = array_column($agent_view['rows'], 'id');
$check(in_array($c1, $ids, true) && in_array($c3, $ids, true) && !in_array($c2, $ids, true), 'agent list: own + unassigned, not a colleague\'s');
$check(3 === $all([])['total'] && 3 === count($all([])['rows']), 'manager list: everyone');
$check(1 === $all(['owner_id' => -1])['total'], 'filter unassigned');

// paging
for ($i = 0; $i < 22; $i++) {
    $service->create(['first_name' => 'پیج' . $i, 'mobile' => sprintf('0901%07d', $i), 'source' => 'paging']);
}
$p1 = $all(['source' => 'paging'], 0, 10, 1);
$p3 = $all(['source' => 'paging'], 0, 10, 3);
$seen = [];
for ($p = 1; $p <= 3; $p++) {
    $seen = array_merge($seen, array_column($all(['source' => 'paging'], 0, 10, $p)['rows'], 'id'));
}
$check(22 === $p1['total'] && 3 === $p1['pages'] && 2 === count($p3['rows']), 'paging: 22 rows → 3 pages of 10');
$check(22 === count(array_unique($seen)), 'paging: no row on two pages');
$check(VKL_Crm_Repository::MAX_PER_PAGE === $contacts->search([], ['1=1', []], 1, 100000)['per_page'], 'page size is capped at 200');
$check(is_int($all(['source' => 'paging'])['rows'][0]['id']), 'numeric columns come back as ints');

$contacts->soft_delete($c3);
$check(0 === $all(['q' => 'آلفا'])['total'] && 1 === $all(['q' => 'آلفا', 'deleted' => true])['total'], 'soft-deleted contact hidden, listable as deleted');
$contacts->restore($c3);
$check(1 === $all(['q' => 'آلفا'])['total'], 'restored');

$lc = $contacts->count_by_lifecycle($access->scope_sql('t.owner_id', $manager));
$check(25 === $lc['lead'], 'count by lifecycle');

$contacts->set_meta($c1, 'instagram', '@maryam');
$check('@maryam' === $contacts->get_meta($c1, 'instagram') && ['instagram' => '@maryam'] === $contacts->all_meta($c1), 'contact meta');

// ------------------------------------------------------------------- tags
$vip  = $tags->ensure('مشتری ویژه', 'tag', '#ff0000');
$vip2 = $tags->ensure(' مشتری  ویژه ', 'tag');
$cat  = $tags->ensure('عمده‌فروش', 'category');
$check($vip > 0 && $vip === $vip2, 'ensure() returns the existing tag for the same name');
$tags->attach($c1, $vip);
$tags->attach($c1, $vip);
$tags->attach($c2, $cat);
$check(1 === count($tags->for_contact($c1)), 'attach twice = once');
$check(1 === $all(['tag_id' => $vip])['total'], 'filter contacts by tag');
$tags->set_for_contact($c1, [$cat]);
$check([$cat] === array_column($tags->for_contact($c1), 'id'), 'set_for_contact replaces tags');
$tags->delete_tag($cat);
$check([] === $tags->for_contact($c1) && 0 === $all(['tag_id' => $cat])['total'], 'deleting a tag detaches it');

// ------------------------------------------------------------- activities
$a1 = $acts->log(['contact_id' => $c1, 'type' => 'note', 'body' => 'اولین یادداشت', 'author_id' => $agent]);
$a2 = $acts->log(['contact_id' => $c1, 'type' => 'call', 'direction' => 'out', 'duration' => 120, 'author_id' => $agent]);
$a3 = $acts->log(['contact_id' => $c1, 'type' => 'order', 'subject' => 'سفارش #1', 'dedupe_key' => 'order:1:completed']);
$a4 = $acts->log(['contact_id' => $c1, 'type' => 'order', 'subject' => 'سفارش #1', 'dedupe_key' => 'order:1:completed']);
$check($a1 > 0 && $a2 > $a1 && $a3 > $a2, 'activities logged');
$check($a3 === $a4, 'same dedupe_key → stored once');
$check(0 === $acts->log(['contact_id' => $c1, 'type' => 'hack']), 'unknown activity type rejected');
$tl = $acts->timeline($c1);
$check([$a3, $a2, $a1] === array_column($tl, 'id'), 'timeline newest first');
$check([$a1] === array_column($acts->timeline($c1, 10, $a2), 'id'), 'timeline keyset paging');
$check([$a2] === array_column($acts->timeline($c1, 10, 0, ['call']), 'id'), 'timeline type filter');
$check(null !== $contacts->find($c1)['last_activity_at'], 'logging touches last_activity_at');

// ------------------------------------------------------------------ tasks
$tz    = wp_timezone();
$today = new DateTimeImmutable('today', $tz);
$day_s = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$day_e = $today->modify('+1 day -1 second')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$noon  = $today->modify('+12 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$t1 = $tasks->create(['title' => 'تماس امروز', 'assignee_id' => $agent, 'due_at' => $noon, 'contact_id' => $c1, 'created_by' => $manager]);
$t2 = $tasks->create(['title' => 'عقب‌افتاده', 'assignee_id' => $agent, 'due_at' => gmdate('Y-m-d H:i:s', strtotime($day_s) - 86400)]);
$t3 = $tasks->create(['title' => 'آینده', 'assignee_id' => $agent, 'due_at' => gmdate('Y-m-d H:i:s', strtotime($day_e) + 86400)]);
$t4 = $tasks->create(['title' => 'همکار', 'assignee_id' => $agent2, 'due_at' => $noon]);
$t5 = $tasks->create(['title' => 'یادآوری', 'assignee_id' => $agent, 'remind_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

$ascope = $access->scope_sql('t.assignee_id', $agent);
$view   = static fn(string $v) => array_column($tasks->search(['view' => $v], $ascope, $day_s, $day_e)['rows'], 'id');
$check([$t1] === $view('today'), 'tasks: today');
$check([$t2] === $view('overdue'), 'tasks: overdue');
$check([$t3] === $view('upcoming'), 'tasks: upcoming');
$check([$t5] === $view('no_date'), 'tasks: no date');
$check(!in_array($t4, $view('all'), true), 'agent does not see a colleague\'s task');
$check(['today' => 1, 'overdue' => 1, 'open' => 4] === $tasks->counts($ascope, $day_s, $day_e), 'task counts for agent');
$check([$t5] === array_column($tasks->due_reminders(), 'id'), 'due reminders');
$tasks->complete($t1);
$check([] === $view('today') && [$t1] === $view('done'), 'complete moves task to done');
$tasks->reopen($t1);
$check([$t1] === $view('today') && null === $tasks->find($t1)['completed_at'], 'reopen');

// ------------------------------------------------------------------ deals
$pid    = (int) $pipe['id'];
$stages = $pipes->stages($pid);
$first  = $pipes->first_stage($pid);
$d1 = $deals->create(['contact_id' => $c1, 'pipeline_id' => $pid, 'stage_id' => (int) $first['id'], 'title' => 'سفارش عمده', 'value' => 10000000, 'owner_id' => $agent]);
$d2 = $deals->create(['contact_id' => $c2, 'pipeline_id' => $pid, 'stage_id' => (int) $stages[3]['id'], 'title' => 'Proposal', 'value' => 5000000, 'owner_id' => $agent2]);
$board = $deals->board($pid, $access->scope_sql('t.owner_id', $manager));
$check(1 === $board[(int) $first['id']]['count'] && 'مریم' === $board[(int) $first['id']]['deals'][0]['first_name'], 'board groups by stage, joins contact');
$tot = $deals->open_totals($access->scope_sql('t.owner_id', $manager), $pid);
$check(2 === $tot['count'] && 15000000.0 === $tot['value'] && abs($tot['weighted'] - (10000000 * 0.10 + 5000000 * 0.60)) < 0.01, 'open totals and probability-weighted value');
$check(1 === $deals->open_totals($access->scope_sql('t.owner_id', $agent), $pid)['count'], 'agent totals only own deals');
$check(false === $pipes->delete_stage((int) $first['id']), 'stage with deals cannot be deleted');
$new_stage = $pipes->save_stage($pid, ['name' => 'تست', 'probability' => 150, 'kind' => 'weird']);
$ns        = $pipes->stage($new_stage);
$check(100 === $ns['probability'] && 'open' === $ns['kind'] && 7 === $ns['position'], 'new stage: clamped, kind validated, appended');
$check($pipes->delete_stage($new_stage), 'empty stage deleted');

// ------------------------------------------------------- sign-up sync
// SMS sign-in creates the user, THEN stores billing_phone.
$lead = $service->create(['first_name' => 'سرنخ چت‌بات', 'mobile' => '09187776655', 'source' => 'chatbot']);
$u    = vkl_login_user('09187776655');
$service->flush_pending(); // runs at shutdown in a real request
$linked = $contacts->find_by_user((int) $u);
$check(null !== $linked && $linked['id'] === $lead, 'existing lead linked to the new account, not duplicated');
$check('chatbot' === $linked['source'] && 'سرنخ چت‌بات' === $linked['first_name'], 'lead keeps its source and name');

$u2 = vkl_login_user('09189990000');
$service->flush_pending();
$fresh = $contacts->find_by_user((int) $u2);
$check(null !== $fresh && '09189990000' === $fresh['mobile'] && 'signup' === $fresh['source'] && '' === $fresh['email'], 'new sign-up: contact with mobile, no placeholder email');

update_user_meta((int) $u2, 'billing_first_name', 'نگار');
$check('نگار' === $contacts->find($fresh['id'])['first_name'], 'empty name filled from the account later');
update_user_meta((int) $u2, 'billing_first_name', 'نام دیگر');
$check('نگار' === $contacts->find($fresh['id'])['first_name'], 'a filled name is never overwritten');

// ------------------------------------------------------------ order stats
$product = new WC_Product_Simple();
$product->set_name('کالای آزمایشی');
$product->set_regular_price('1000000');
$product->save();

$make_order = static function (int $customer, string $phone = '', array $meta = []) use ($product): WC_Order {
    $o = wc_create_order(['customer_id' => $customer]);
    $o->add_product($product, 2);
    if ('' !== $phone) {
        $o->set_billing_phone($phone);
        $o->set_billing_first_name('مهمان');
    }
    foreach ($meta as $k => $v) {
        $o->update_meta_data($k, $v);
    }
    $o->calculate_totals();
    $o->save();
    return $o;
};

$o1 = $make_order((int) $u2);
$o1->update_status('completed');
$after = $contacts->find($fresh['id']);
$check(1 === $after['orders_count'] && 2000000.0 === $after['total_spent'] && 'customer' === $after['lifecycle'], 'paid order: count, spend, lead → customer');

$topup = $make_order((int) $u2, '', [VKL_Wallet_Topup::ORDER_META => '1']);
$topup->update_status('completed');
$check(1 === $contacts->find($fresh['id'])['orders_count'], 'wallet top-up is not counted as a sale');

wc_create_refund(['order_id' => $o1->get_id(), 'amount' => 500000, 'reason' => 'test']);
$check(1500000.0 === $contacts->find($fresh['id'])['total_spent'], 'refund reduces spend');

$o1->update_status('cancelled');
$cancelled = $contacts->find($fresh['id']);
$check(0 === $cancelled['orders_count'] && 'customer' === $cancelled['lifecycle'], 'cancelled order uncounted; lifecycle never downgraded');

$guest = $make_order(0, '0919 111 2222');
$guest->update_status('processing');
$gc = $contacts->find_by_mobile('09191112222');
$check(null !== $gc && 'guest_order' === $gc['source'] && 1 === $gc['orders_count'] && null === $gc['user_id'], 'guest order creates a contact by billing mobile');

$check((string) $gc['id'] === wc_get_order($guest->get_id())->get_meta(VKL_Crm_Contact_Service::ORDER_META), 'guest order stamped with its contact');
$guest2 = $make_order(0, '+98 919 111 2222');
$guest2->update_status('completed');
$check(2 === $contacts->find((int) $gc['id'])['orders_count'], 'second guest order (phone typed differently) counted on the same contact');
$gu = vkl_login_user('09191112222');
$service->flush_pending();
$service->refresh_stats((int) $gc['id']);
$check((int) $gc['id'] === (int) $contacts->find_by_user((int) $gu)['id'] && 2 === $contacts->find((int) $gc['id'])['orders_count'], 'guest who signs up keeps the same contact and their guest orders');

// ---------------------------------------------------- timeline listeners
$orderActs = static fn(int $cid): array => array_values(array_filter($acts->timeline($cid, 50), static fn(array $a): bool => 'order' === $a['type']));
$gid       = (int) $gc['id'];
$check(count($orderActs($gid)) >= 2, 'order status changes appear on the timeline');
$before    = count($orderActs($gid));
vkl_dispatch('order.status_changed', ['order_id' => $guest2->get_id(), 'order_number' => $guest2->get_order_number(), 'new_status' => 'completed', 'total' => 1]);
$check($before === count($orderActs($gid)), 'same order event delivered twice → one timeline entry');
$tu  = (int) $gu;
vkl_dispatch('wallet.charged', ['user_id' => $tu, 'amount' => 50000, 'reason' => 'test']);
$check('wallet' === ($acts->timeline($gid, 1)[0]['type'] ?? ''), 'wallet event logged on the contact of the account');
vkl_dispatch('wallet.charged', ['user_id' => $agent, 'amount' => 1, 'reason' => 'staff']);
$check(null === $contacts->find_by_user($agent), 'events for staff accounts create no contact');
$carrier = $make_order($tu, '', [VKL_Wallet_Topup::ORDER_META => '1']);
$carrier->update_status('completed');
$check([] === array_filter($orderActs($gid), static fn(array $a): bool => (int) $a['ref_id'] === $carrier->get_id()), 'wallet top-up orders stay off the timeline');

// --------------------------------------------------------------- backfill
// Simulate "CRM installed on a shop that already has customers": wipe
// contacts and run the import from scratch.
$users_before = array_filter(array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users}")), static fn(int $id): bool => !VKL_Crm_Contact_Service::is_staff($id));
$wpdb->query("TRUNCATE {$prefix}crm_contacts");
$wpdb->query("TRUNCATE {$prefix}crm_contact_meta");
delete_option(VKL_Crm_Backfill::STATE_OPTION);
$backfill->start();
$check((bool) wp_next_scheduled(VKL_Crm_Backfill::HOOK), 'backfill scheduled on WP-Cron');
for ($i = 0; $i < 50 && !$backfill->is_done(); $i++) {
    $backfill->run(7, 30.0); // small batches to exercise the cursor
}
$state = $backfill->state();
$check($backfill->is_done(), 'backfill finished');
$check(count($users_before) === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}crm_contacts WHERE user_id IS NOT NULL"), 'every non-staff account has a contact');
$check(null !== $contacts->find_by_mobile('09191112222'), 'guest order contact re-created by backfill');
$check(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}crm_contacts c INNER JOIN {$wpdb->usermeta} m ON m.user_id = c.user_id AND m.meta_key = '{$wpdb->prefix}capabilities' WHERE m.meta_value LIKE '%administrator%'"), 'no administrator imported');
$check(!wp_next_scheduled(VKL_Crm_Backfill::HOOK), 'cron event cleared when done');
$restored = $contacts->find_by_user((int) $u2);
$check(0 === $restored['orders_count'] && 'lead' === $restored['lifecycle'], 'backfill: only cancelled + top-up orders → 0 sales, still a lead');
$n1 = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}crm_contacts");
delete_option(VKL_Crm_Backfill::STATE_OPTION);
$backfill->start();
for ($i = 0; $i < 50 && !$backfill->is_done(); $i++) {
    $backfill->run(100, 30.0);
}
$check($n1 === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}crm_contacts"), 'running the backfill again creates no duplicates');
set_transient('vkl_crm_backfill_lock', 1, 60);
$check(0 === $backfill->run(10, 5.0), 'a second runner backs off while one holds the lock');
delete_transient('vkl_crm_backfill_lock');

// -------------------------------------------------- uninstall / reinstall
$schema->uninstall();
$check([] === $crm_tables() && 28 === count($vkl_tables()), 'uninstall drops only the 9 CRM tables');
$check(null === get_role('vkl_sales_agent') && !get_role('administrator')->has_cap('vkl_crm_access'), 'uninstall removes roles and capabilities');
$demoted = get_userdata($agent);
$check(in_array('customer', $demoted->roles, true), 'former agent keeps a working account (customer)');
$check(false === get_option(VKL_Crm_Schema::VERSION_OPTION), 'uninstall clears the CRM version');
$check('2.9.21' === get_option('vkl_db_version'), 'plugin-wide schema version still untouched');
$check($schema->maybe_install() && 9 === count($crm_tables()) && null !== get_role('vkl_sales_agent'), 'reinstall after uninstall works');

// --------------------------------------------------------------- cleanup
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ([$agent, $agent2, $manager, (int) $u, (int) $u2, (int) $gu] as $uid) {
    wp_delete_user($uid);
}

echo $failures ? "\n{$failures} FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
