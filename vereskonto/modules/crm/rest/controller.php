<?php
/**
 * CRM REST API for the staff mobile app (Android / iOS):
 * namespace vereskonto/v1, routes under /crm.
 *
 * Authentication (every route except the two sign-in steps):
 *   - "Authorization: Bearer <token>" with a STAFF token from
 *     POST /crm/auth/verify-otp (shop-app customer tokens are refused);
 *   - or a normal WordPress login: cookie + X-WP-Nonce, or an
 *     Application Password (Basic auth over HTTPS).
 * Authorization: the same capabilities and record rules as the admin
 * screens (VKL_Crm_Access) — an agent reaches only their own records.
 * Validation: the same services the screens use; errors come back as
 * {code, message, data: {status, field}} with a proper HTTP status.
 * Records the caller may not see answer 404, so ids cannot be probed.
 *
 * Dates in responses are ISO 8601 UTC ("2026-11-21T14:05:00Z"). List
 * endpoints page with ?page=&per_page= (max 100) and send X-WP-Total /
 * X-WP-TotalPages; ?updated_since=<ISO> (with deleted records flagged)
 * supports offline sync. X-VKL-Server-Time is the time to use as the
 * next updated_since.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.20.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Rest
 */
final class VKL_Crm_Rest
{
    public const NS = 'vereskonto/v1';

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private VKL_Config $config,
        private VKL_Api_Tokens $tokens,
        private VKL_Crm_Access $access,
        private VKL_Crm_Contacts $contacts,
        private VKL_Crm_Contact_Service $contact_service,
        private VKL_Crm_Tags $tags,
        private VKL_Crm_Activities $activities,
        private VKL_Crm_Timeline $timeline,
        private VKL_Crm_Pipelines $pipelines,
        private VKL_Crm_Deals $deals,
        private VKL_Crm_Deal_Service $deal_service,
        private VKL_Crm_Tasks $tasks,
        private VKL_Crm_Task_Service $task_service,
        private VKL_Crm_Reports $reports
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        if (!$this->config->get_bool('api_enabled', false)) {
            return;
        }

        // Labels and option lists live with the admin screens, which are
        // not loaded on REST requests.
        vkl_require('modules/crm/admin/ui.php');
        vkl_require('modules/crm/admin/timeline-view.php');
        vkl_require('modules/crm/admin/task-view.php');
        vkl_require('modules/crm/admin/deals-page.php');

        $id   = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        $page = [
            'page'          => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page'      => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE],
            'updated_since' => ['type' => 'string', 'format' => 'date-time'],
        ];

        // --- sign-in -----------------------------------------------------
        $this->route('/crm/auth/request-otp', 'POST', 'request_otp', ['mobile' => ['type' => 'string', 'required' => true]], '__return_true');
        $this->route('/crm/auth/verify-otp', 'POST', 'verify_otp', [
            'mobile' => ['type' => 'string', 'required' => true],
            'code'   => ['type' => 'string', 'required' => true],
            'device' => ['type' => 'string', 'maxLength' => 190],
        ], '__return_true');
        $this->route('/crm/auth/logout', 'POST', 'logout', []);

        // --- session ----------------------------------------------------
        $this->route('/crm/me', 'GET', 'me', []);
        $this->route('/crm/meta', 'GET', 'meta', []);
        $this->route('/crm/reports/summary', 'GET', 'summary', [
            'period' => ['type' => 'string', 'enum' => VKL_Crm_Reports::PERIODS, 'default' => 'this_month'],
            'from'   => ['type' => 'string'],
            'to'     => ['type' => 'string'],
        ]);

        // --- contacts ---------------------------------------------------
        $this->route('/crm/contacts', 'GET', 'list_contacts', $page + [
            'q'         => ['type' => 'string'],
            'lifecycle' => ['type' => 'string', 'enum' => VKL_Crm_Contacts::LIFECYCLES],
            'owner_id'  => ['type' => 'integer'],
            'source'    => ['type' => 'string'],
            'tag_id'    => ['type' => 'integer'],
            'city'      => ['type' => 'string'],
            'orderby'   => ['type' => 'string', 'enum' => ['created_at', 'updated_at', 'last_activity_at', 'total_spent', 'last_name'], 'default' => 'updated_at'],
            'order'     => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
        ]);
        $this->route('/crm/contacts', 'POST', 'create_contact', $this->contact_args(true));
        $this->route('/crm/contacts/(?P<id>\d+)', 'GET', 'get_contact', $id);
        $this->route('/crm/contacts/(?P<id>\d+)', 'PATCH', 'update_contact', $id + $this->contact_args(false));
        $this->route('/crm/contacts/(?P<id>\d+)', 'DELETE', 'delete_contact', $id);
        $this->route('/crm/contacts/(?P<id>\d+)/claim', 'POST', 'claim_contact', $id);
        $this->route('/crm/contacts/(?P<id>\d+)/timeline', 'GET', 'contact_timeline', $id + [
            'before' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 30],
            'types'  => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_merge(VKL_Crm_Activities::MANUAL_TYPES, VKL_Crm_Activities::SYSTEM_TYPES)]],
        ]);
        $this->route('/crm/contacts/(?P<id>\d+)/activities', 'POST', 'add_activity', $id + [
            'type'           => ['type' => 'string', 'enum' => ['note', 'call', 'meeting'], 'required' => true],
            'deal_id'        => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            'subject'        => ['type' => 'string', 'maxLength' => 190],
            'body'           => ['type' => 'string', 'maxLength' => 20000],
            'direction'      => ['type' => 'string', 'enum' => ['in', 'out']],
            'outcome'        => ['type' => 'string', 'enum' => VKL_Crm_Timeline::CALL_OUTCOMES],
            'minutes'        => ['type' => 'integer', 'minimum' => 0, 'maximum' => 600],
            'meeting_status' => ['type' => 'string', 'enum' => VKL_Crm_Timeline::MEETING_STATES],
            'meeting_date'   => ['type' => 'string'],
            'meeting_time'   => ['type' => 'string', 'pattern' => '^([01]?\d|2[0-3]):[0-5]\d$'],
        ]);

        // --- deals ------------------------------------------------------
        $this->route('/crm/deals', 'GET', 'list_deals', $page + [
            'q'           => ['type' => 'string'],
            'pipeline_id' => ['type' => 'integer'],
            'stage_id'    => ['type' => 'integer'],
            'status'      => ['type' => 'string', 'enum' => VKL_Crm_Deals::STATUSES],
            'owner_id'    => ['type' => 'integer'],
            'contact_id'  => ['type' => 'integer'],
        ]);
        $this->route('/crm/deals', 'POST', 'create_deal', $this->deal_args(true));
        $this->route('/crm/deals/(?P<id>\d+)', 'GET', 'get_deal', $id);
        $this->route('/crm/deals/(?P<id>\d+)', 'PATCH', 'update_deal', $id + $this->deal_args(false));
        $this->route('/crm/deals/(?P<id>\d+)', 'DELETE', 'delete_deal', $id);
        $this->route('/crm/deals/(?P<id>\d+)/move', 'POST', 'move_deal', $id + [
            'stage_id'    => ['type' => 'integer', 'required' => true, 'minimum' => 1],
            'lost_reason' => ['type' => 'string', 'maxLength' => 190],
        ]);

        // --- tasks ------------------------------------------------------
        $this->route('/crm/tasks', 'GET', 'list_tasks', $page + [
            'view'        => ['type' => 'string', 'enum' => VKL_Crm_Tasks::VIEWS, 'default' => 'all'],
            'assignee_id' => ['type' => 'integer'],
            'contact_id'  => ['type' => 'integer'],
            'deal_id'     => ['type' => 'integer'],
            'priority'    => ['type' => 'string', 'enum' => VKL_Crm_Tasks::PRIORITIES],
            'q'           => ['type' => 'string'],
        ]);
        $this->route('/crm/tasks', 'POST', 'create_task', $this->task_args(true));
        $this->route('/crm/tasks/(?P<id>\d+)', 'GET', 'get_task', $id);
        $this->route('/crm/tasks/(?P<id>\d+)', 'PATCH', 'update_task', $id + $this->task_args(false));
        $this->route('/crm/tasks/(?P<id>\d+)', 'DELETE', 'delete_task', $id);
        $this->route('/crm/tasks/(?P<id>\d+)/done', 'POST', 'task_done', $id + ['done' => ['type' => 'boolean', 'default' => true]]);
    }

    /**
     * @param array<string, mixed> $args
     * @param callable|string|null $permission
     */
    private function route(string $path, string $method, string $callback, array $args, $permission = null): void
    {
        register_rest_route(self::NS, $path, [
            'methods'             => $method,
            'callback'            => [$this, $callback],
            'permission_callback' => $permission ?? [$this, 'authenticate'],
            'args'                => $args,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function contact_args(bool $create): array
    {
        $a = [
            'first_name' => ['type' => 'string', 'maxLength' => 100],
            'last_name'  => ['type' => 'string', 'maxLength' => 100],
            'company'    => ['type' => 'string', 'maxLength' => 190],
            'mobile'     => ['type' => 'string', 'maxLength' => 30],
            'email'      => ['type' => 'string', 'maxLength' => 190],
            'city'       => ['type' => 'string', 'maxLength' => 100],
            'birthday'   => ['type' => 'string'],
            'lifecycle'  => ['type' => 'string', 'enum' => VKL_Crm_Contacts::LIFECYCLES],
            'source'     => ['type' => 'string', 'maxLength' => 40],
            'score'      => ['type' => 'integer', 'minimum' => -1000, 'maximum' => 1000],
            'owner_id'   => ['type' => 'integer', 'minimum' => 0],
            'tag_ids'    => ['type' => 'array', 'items' => ['type' => 'integer']],
        ];

        return $a;
    }

    /** @return array<string, array<string, mixed>> */
    private function deal_args(bool $create): array
    {
        return [
            'title'          => ['type' => 'string', 'maxLength' => 190, 'required' => $create],
            'contact_id'     => ['type' => 'integer', 'minimum' => 1, 'required' => $create],
            'value'          => ['type' => ['number', 'string']],
            'pipeline_id'    => ['type' => 'integer', 'minimum' => 0],
            'stage_id'       => ['type' => 'integer', 'minimum' => 0],
            'owner_id'       => ['type' => 'integer', 'minimum' => 0],
            'expected_close' => ['type' => 'string'],
            'source'         => ['type' => 'string', 'maxLength' => 40],
            'order'          => ['type' => ['integer', 'string']],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function task_args(bool $create): array
    {
        return [
            'title'       => ['type' => 'string', 'maxLength' => 190, 'required' => $create],
            'description' => ['type' => 'string', 'maxLength' => 5000],
            'due_at'      => ['type' => 'string', 'format' => 'date-time'],
            'due_date'    => ['type' => 'string'],
            'due_time'    => ['type' => 'string', 'pattern' => '^([01]?\d|2[0-3]):[0-5]\d$'],
            'remind'      => ['type' => 'string', 'enum' => ['', '0', '15', '60', '1440']],
            'priority'    => ['type' => 'string', 'enum' => VKL_Crm_Tasks::PRIORITIES],
            'assignee_id' => ['type' => 'integer', 'minimum' => 0],
            'contact_id'  => ['type' => 'integer', 'minimum' => 0],
            'deal_id'     => ['type' => 'integer', 'minimum' => 0],
        ];
    }

    /* ================================================================== *
     *  Authentication
     * ================================================================== */

    /**
     * Permission callback for every CRM route but sign-in.
     *
     * @return true|WP_Error
     */
    public function authenticate(WP_REST_Request $request)
    {
        $header = (string) $request->get_header('authorization');

        if (1 === preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            $token = $this->tokens->resolve_full($m[1]);

            if (null === $token) {
                return new WP_Error('vkl_bad_token', __('Token is invalid or expired.', 'vereskala-login'), ['status' => 401]);
            }

            if ('staff' !== $token['scope']) {
                return new WP_Error('vkl_crm_scope', __('This token belongs to the shop app and cannot open the CRM. Sign in to the CRM app instead.', 'vereskala-login'), ['status' => 403]);
            }

            wp_set_current_user($token['user_id']);
        }

        if (!is_user_logged_in()) {
            return new WP_Error('vkl_crm_auth', __('Sign in first.', 'vereskala-login'), ['status' => 401]);
        }

        if (!current_user_can(VKL_Crm_Access::CAP_ACCESS)) {
            return new WP_Error('vkl_crm_forbidden', __('This account has no access to the CRM.', 'vereskala-login'), ['status' => 403]);
        }

        return true;
    }

    /**
     * Step 1: text a code — but only to a number that belongs to CRM
     * staff. The answer is the same either way, so the endpoint cannot
     * be used to find out who works at the shop.
     */
    public function request_otp(WP_REST_Request $r)
    {
        $mobile = vkl_normalize_mobile(vkl_latin_digits((string) $r['mobile']));

        if (!vkl_is_mobile($mobile)) {
            return new WP_Error('vkl_bad_mobile', __('The mobile number is not valid.', 'vereskala-login'), ['status' => 422]);
        }

        if (!vkl_can_send_otp($mobile)) {
            return new WP_Error('vkl_rate', __('Too many requests. Please try again later.', 'vereskala-login'), ['status' => 429]);
        }

        if ($this->staff_by_mobile($mobile) > 0) {
            $otp = vkl_generate_otp();
            vkl_save_otp($mobile, $otp);
            $sent = vkl_send_sms($mobile, $otp);

            // Even a failed text gets the same answer as an unknown number —
            // a different reply would tell an outsider this number is staff.
            // The failure is logged for the shop owner instead.
            if (is_wp_error($sent)) {
                vkl('logger')->error('CRM staff sign-in SMS failed.', ['code' => $sent->get_error_code()]);
            }
        }

        return $this->ok(['sent' => true, 'expires_in' => vkl_otp_expire()]);
    }

    /**
     * Step 2: check the code and hand back a staff token. Same lockout as
     * every other sign-in; never creates an account.
     */
    public function verify_otp(WP_REST_Request $r)
    {
        $mobile = vkl_normalize_mobile(vkl_latin_digits((string) $r['mobile']));

        if (!vkl_is_mobile($mobile)) {
            return new WP_Error('vkl_bad_mobile', __('The mobile number is not valid.', 'vereskala-login'), ['status' => 422]);
        }

        if (vkl_otp_locked($mobile)) {
            return new WP_Error('vkl_locked', __('Too many wrong codes. Please try again in 15 minutes.', 'vereskala-login'), ['status' => 429]);
        }

        $expected = vkl_get_otp($mobile);
        $user_id  = $this->staff_by_mobile($mobile);

        if (false === $expected || $user_id <= 0 || !vkl_otp_matches($expected, (string) $r['code'])) {
            vkl_add_failed_attempt($mobile);

            return new WP_Error('vkl_bad_code', __('The code is incorrect or has expired.', 'vereskala-login'), ['status' => 401]);
        }

        vkl_delete_otp($mobile);
        vkl_reset_failed_attempts($mobile);

        $ttl   = max(1, min(365, $this->config->get_int('api_staff_token_ttl_days', 30)));
        $token = $this->tokens->issue($user_id, sanitize_text_field((string) ($r['device'] ?? '')), $ttl, 'staff');

        wp_set_current_user($user_id);

        return $this->ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * DAY_IN_SECONDS,
            'user'       => $this->user_payload($user_id),
        ]);
    }

    public function logout(WP_REST_Request $r): WP_REST_Response
    {
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', (string) $r->get_header('authorization'), $m)) {
            $this->tokens->revoke($m[1]);
        }

        return $this->ok(['logged_out' => true]);
    }

    private function staff_by_mobile(string $mobile): int
    {
        foreach (get_users(['meta_key' => 'billing_phone', 'meta_value' => $mobile, 'fields' => 'ID', 'number' => 5]) as $uid) {
            if (user_can((int) $uid, VKL_Crm_Access::CAP_ACCESS)) {
                return (int) $uid;
            }
        }

        return 0;
    }

    /* ================================================================== *
     *  Session
     * ================================================================== */

    public function me(): WP_REST_Response
    {
        [$s, $e] = VKL_Crm_Task_Service::day_bounds();

        return $this->ok([
            'user'  => $this->user_payload(get_current_user_id()),
            'tasks' => $this->tasks->counts(['t.assignee_id = %d', [get_current_user_id()]], $s, $e),
        ]);
    }

    /**
     * Everything the app needs to draw forms and labels.
     */
    public function meta(): WP_REST_Response
    {
        $pipes = [];

        foreach ($this->pipelines->all() as $p) {
            $pipes[] = [
                'id'         => (int) $p['id'],
                'name'       => $p['name'],
                'is_default' => (bool) $p['is_default'],
                'stages'     => array_map(static fn(array $s): array => [
                    'id' => $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'probability' => $s['probability'], 'color' => $s['color'], 'position' => $s['position'],
                ], $this->pipelines->stages((int) $p['id'])),
            ];
        }

        $users = [];

        foreach ($this->access->assignable_users() as $uid => $name) {
            $users[] = ['id' => $uid, 'name' => $name];
        }

        return $this->ok([
            'lifecycles'     => $this->pairs(VKL_Crm_Ui::lifecycles()),
            'sources'        => $this->pairs(VKL_Crm_Ui::sources()),
            'activity_types' => $this->pairs(VKL_Crm_Ui::activity_types()),
            'call_outcomes'  => $this->pairs(VKL_Crm_Ui::call_outcomes()),
            'priorities'     => $this->pairs(VKL_Crm_Task_View::priorities()),
            'reminders'      => $this->pairs(VKL_Crm_Task_View::reminders()),
            'pipelines'      => $pipes,
            'tags'           => array_map(static fn(array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'type' => $t['type'], 'color' => $t['color']], $this->tags->all()),
            'users'          => $users,
            'lost_reasons'   => VKL_Crm_Deals_Page::lost_reasons(),
            'currency'       => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'timezone'       => wp_timezone_string(),
        ]);
    }

    public function summary(WP_REST_Request $r): WP_REST_Response
    {
        [$from, $to, $label] = VKL_Crm_Reports::period((string) $r['period'], (string) ($r['from'] ?? ''), (string) ($r['to'] ?? ''));
        $scope = $this->access->scope_sql('t.owner_id');
        $pipe  = $this->pipelines->default_pipeline();
        [$s, $e] = VKL_Crm_Task_Service::day_bounds();

        return $this->ok([
            'period' => ['from' => $this->iso($from), 'to' => $this->iso($to), 'label' => $label],
            'kpis'   => $this->reports->kpis($scope, $from, $to),
            'funnel' => null === $pipe ? [] : $this->reports->funnel((int) $pipe['id'], $scope),
            'tasks'  => $this->tasks->counts(['t.assignee_id = %d', [get_current_user_id()]], $s, $e),
            'team'   => $this->access->can(VKL_Crm_Access::CAP_EDIT_ALL),
        ]);
    }

    /* ================================================================== *
     *  Contacts
     * ================================================================== */

    public function list_contacts(WP_REST_Request $r): WP_REST_Response
    {
        $f = array_filter([
            'q'         => (string) ($r['q'] ?? ''),
            'lifecycle' => (string) ($r['lifecycle'] ?? ''),
            'source'    => (string) ($r['source'] ?? ''),
            'tag_id'    => (int) ($r['tag_id'] ?? 0),
            'city'      => (string) ($r['city'] ?? ''),
        ], static fn($v): bool => '' !== $v && 0 !== $v);

        if (null !== $r['owner_id']) {
            $f['owner_id'] = (string) (int) $r['owner_id'];
        }

        $f += $this->sync_filters($r);
        $res = $this->contacts->search($f, $this->access->scope_sql('t.owner_id'), (int) $r['page'], (int) $r['per_page'], (string) $r['orderby'], (string) $r['order']);

        return $this->paged(array_map([$this, 'contact_payload'], $res['rows']), $res);
    }

    public function get_contact(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        return is_wp_error($c) ? $c : $this->ok($this->contact_payload($c));
    }

    public function create_contact(WP_REST_Request $r)
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            return $this->forbidden();
        }

        $input = $this->only($r, ['first_name', 'last_name', 'company', 'mobile', 'email', 'city', 'birthday', 'lifecycle', 'source', 'score']);
        $owner = null !== $r['owner_id'] ? (int) $r['owner_id'] : get_current_user_id();

        // Managers choose any CRM user; agents always own what they create.
        $input['owner_id'] = $this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && $this->access->can_assign([], $owner) ? $owner : get_current_user_id();
        $input += ['source' => 'manual'];

        $id = $this->contact_service->create($input);

        if (is_wp_error($id)) {
            return $id;
        }

        $this->set_tags((int) $id, $r);
        $this->timeline->system((int) $id, __('Contact created', 'vereskala-login'));

        return $this->ok($this->contact_payload((array) $this->contacts->find((int) $id)), 201);
    }

    public function update_contact(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        if (is_wp_error($c)) {
            return $c;
        }

        if (!$this->access->can_edit($c)) {
            return $this->forbidden();
        }

        $input = $this->only($r, ['first_name', 'last_name', 'company', 'mobile', 'email', 'city', 'birthday', 'lifecycle', 'source', 'score']);

        if (null !== $r['owner_id']) {
            if (!$this->access->can_assign($c, (int) $r['owner_id'])) {
                return $this->forbidden();
            }

            $input['owner_id'] = (int) $r['owner_id'];
        }

        $result = $this->contact_service->update((int) $c['id'], $input);

        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($input['owner_id']) && (int) $c['owner_id'] !== (int) $input['owner_id']) {
            $this->timeline->system((int) $c['id'], sprintf(/* translators: %s: staff member */ __('Assigned to %s', 'vereskala-login'), VKL_Crm_Ui::user_name((int) $input['owner_id'])));
        }

        if (isset($input['lifecycle']) && $c['lifecycle'] !== $input['lifecycle']) {
            $labels = VKL_Crm_Ui::lifecycles();
            $this->timeline->system((int) $c['id'], sprintf(/* translators: 1: old stage, 2: new stage */ __('Stage changed: %1$s → %2$s', 'vereskala-login'), VKL_Crm_Ui::label($labels, (string) $c['lifecycle']), VKL_Crm_Ui::label($labels, (string) $input['lifecycle'])));
        }

        $this->set_tags((int) $c['id'], $r);

        return $this->ok($this->contact_payload((array) $this->contacts->find((int) $c['id'])));
    }

    public function delete_contact(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        if (is_wp_error($c)) {
            return $c;
        }

        if (!$this->access->can_delete($c)) {
            return $this->forbidden();
        }

        $this->contacts->soft_delete((int) $c['id']);

        return $this->ok(['deleted' => true, 'id' => (int) $c['id']]);
    }

    public function claim_contact(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        if (is_wp_error($c)) {
            return $c;
        }

        if (!$this->access->can_claim($c)) {
            return $this->forbidden();
        }

        $this->contacts->update((int) $c['id'], ['owner_id' => get_current_user_id()]);
        $this->timeline->system((int) $c['id'], sprintf(/* translators: %s: staff member */ __('Assigned to %s', 'vereskala-login'), VKL_Crm_Ui::user_name(get_current_user_id())));

        return $this->ok($this->contact_payload((array) $this->contacts->find((int) $c['id'])));
    }

    public function contact_timeline(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        if (is_wp_error($c)) {
            return $c;
        }

        $rows = $this->activities->timeline((int) $c['id'], (int) $r['limit'], (int) $r['before'], (array) ($r['types'] ?? []));
        $last = end($rows);

        return $this->ok([
            'items'       => array_map([$this, 'activity_payload'], $rows),
            'next_before' => count($rows) === (int) $r['limit'] && false !== $last ? (int) $last['id'] : null,
        ]);
    }

    public function add_activity(WP_REST_Request $r)
    {
        $c = $this->visible_contact((int) $r['id']);

        if (is_wp_error($c)) {
            return $c;
        }

        if (!$this->access->can_edit($c)) {
            return $this->forbidden();
        }

        $deal_id = (int) $r['deal_id'];

        if ($deal_id > 0) {
            $deal = $this->deals->find($deal_id);

            if (null === $deal || (int) $deal['contact_id'] !== (int) $c['id']) {
                return new WP_Error('vkl_crm_deal', __('That deal does not belong to this contact.', 'vereskala-login'), ['status' => 422, 'field' => 'deal_id']);
            }
        }

        $id = $this->timeline->manual($this->only($r, ['type', 'subject', 'body', 'direction', 'outcome', 'minutes', 'meeting_status', 'meeting_date', 'meeting_time']), (int) $c['id'], $deal_id, get_current_user_id());

        return is_wp_error($id) ? $id : $this->ok($this->activity_payload((array) $this->activities->find((int) $id)), 201);
    }

    /* ================================================================== *
     *  Deals
     * ================================================================== */

    public function list_deals(WP_REST_Request $r): WP_REST_Response
    {
        $f = array_filter([
            'q'           => (string) ($r['q'] ?? ''),
            'pipeline_id' => (int) ($r['pipeline_id'] ?? 0),
            'stage_id'    => (int) ($r['stage_id'] ?? 0),
            'status'      => (string) ($r['status'] ?? ''),
            'contact_id'  => (int) ($r['contact_id'] ?? 0),
        ], static fn($v): bool => '' !== $v && 0 !== $v);

        if (null !== $r['owner_id']) {
            $f['owner_id'] = (string) (int) $r['owner_id'];
        }

        $f  += $this->sync_filters($r);
        $res = $this->deals->search($f, $this->access->scope_sql('t.owner_id'), (int) $r['page'], (int) $r['per_page'], 'updated_at', 'DESC');

        return $this->paged(array_map([$this, 'deal_payload'], $res['rows']), $res);
    }

    public function get_deal(WP_REST_Request $r)
    {
        $d = $this->visible_deal((int) $r['id']);

        return is_wp_error($d) ? $d : $this->ok($this->deal_payload($d));
    }

    public function create_deal(WP_REST_Request $r)
    {
        $contact = $this->contacts->find((int) $r['contact_id']);

        if (null === $contact || !$this->access->can_view($contact)) {
            return $this->not_found();
        }

        if (!$this->access->can_edit($contact)) {
            return $this->forbidden();
        }

        $input = $this->only($r, ['title', 'contact_id', 'value', 'pipeline_id', 'stage_id', 'expected_close', 'source', 'order']);

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && null !== $r['owner_id'] && $this->access->can_assign([], (int) $r['owner_id'])) {
            $input['owner_id'] = (int) $r['owner_id'];
        } elseif (!$this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
            $input['owner_id'] = get_current_user_id();
        }

        $id = $this->deal_service->create($input, get_current_user_id());

        return is_wp_error($id) ? $id : $this->ok($this->deal_payload((array) $this->deals->find((int) $id)), 201);
    }

    public function update_deal(WP_REST_Request $r)
    {
        $d = $this->visible_deal((int) $r['id']);

        if (is_wp_error($d)) {
            return $d;
        }

        if (!$this->access->can_edit($d)) {
            return $this->forbidden();
        }

        $input = $this->only($r, ['title', 'value', 'pipeline_id', 'stage_id', 'expected_close', 'source', 'order']);

        if (null !== $r['owner_id']) {
            if (!$this->access->can_assign($d, (int) $r['owner_id'])) {
                return $this->forbidden();
            }

            $input['owner_id'] = (int) $r['owner_id'];
        }

        $result = $this->deal_service->update((int) $d['id'], $input, get_current_user_id());

        return is_wp_error($result) ? $result : $this->ok($this->deal_payload((array) $this->deals->find((int) $d['id'])));
    }

    public function move_deal(WP_REST_Request $r)
    {
        $d = $this->visible_deal((int) $r['id']);

        if (is_wp_error($d)) {
            return $d;
        }

        if (!$this->access->can_edit($d)) {
            return $this->forbidden();
        }

        $result = $this->deal_service->move((int) $d['id'], (int) $r['stage_id'], (string) ($r['lost_reason'] ?? ''), get_current_user_id());

        return is_wp_error($result) ? $result : $this->ok($this->deal_payload((array) $this->deals->find((int) $d['id'])));
    }

    public function delete_deal(WP_REST_Request $r)
    {
        $d = $this->visible_deal((int) $r['id']);

        if (is_wp_error($d)) {
            return $d;
        }

        if (!$this->access->can_delete($d)) {
            return $this->forbidden();
        }

        $this->deals->soft_delete((int) $d['id']);

        return $this->ok(['deleted' => true, 'id' => (int) $d['id']]);
    }

    /* ================================================================== *
     *  Tasks
     * ================================================================== */

    public function list_tasks(WP_REST_Request $r): WP_REST_Response
    {
        $f = array_filter([
            'view'       => (string) $r['view'],
            'contact_id' => (int) ($r['contact_id'] ?? 0),
            'deal_id'    => (int) ($r['deal_id'] ?? 0),
            'priority'   => (string) ($r['priority'] ?? ''),
            'q'          => (string) ($r['q'] ?? ''),
        ], static fn($v): bool => '' !== $v && 0 !== $v);

        if (null !== $r['assignee_id']) {
            $f['assignee_id'] = (string) (int) $r['assignee_id'];
        }

        $f      += $this->sync_filters($r);
        [$s, $e] = VKL_Crm_Task_Service::day_bounds();
        $res     = $this->tasks->search($f, $this->access->scope_sql('t.assignee_id'), $s, $e, (int) $r['page'], (int) $r['per_page']);

        return $this->paged(array_map([$this, 'task_payload'], $res['rows']), $res);
    }

    public function get_task(WP_REST_Request $r)
    {
        $t = $this->visible_task((int) $r['id']);

        return is_wp_error($t) ? $t : $this->ok($this->task_payload($t));
    }

    public function create_task(WP_REST_Request $r)
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            return $this->forbidden();
        }

        $input = $this->task_input($r);

        if (is_wp_error($input)) {
            return $input;
        }

        $input['assignee_id'] = $this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && null !== $r['assignee_id'] ? (int) $r['assignee_id'] : get_current_user_id();

        $id = $this->task_service->create($input, get_current_user_id());

        return is_wp_error($id) ? $id : $this->ok($this->task_payload((array) $this->tasks->find((int) $id)), 201);
    }

    public function update_task(WP_REST_Request $r)
    {
        $t = $this->visible_task((int) $r['id']);

        if (is_wp_error($t)) {
            return $t;
        }

        if (!$this->access->can_edit($t)) {
            return $this->forbidden();
        }

        $input = $this->task_input($r, $t);

        if (is_wp_error($input)) {
            return $input;
        }

        if (null !== $r['assignee_id']) {
            if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
                return $this->forbidden();
            }

            $input['assignee_id'] = (int) $r['assignee_id'];
        }

        $result = $this->task_service->update((int) $t['id'], $input);

        return is_wp_error($result) ? $result : $this->ok($this->task_payload((array) $this->tasks->find((int) $t['id'])));
    }

    public function task_done(WP_REST_Request $r)
    {
        $t = $this->visible_task((int) $r['id']);

        if (is_wp_error($t)) {
            return $t;
        }

        if (!$this->access->can_edit($t)) {
            return $this->forbidden();
        }

        $this->task_service->set_done((int) $t['id'], (bool) $r['done'], get_current_user_id());

        return $this->ok($this->task_payload((array) $this->tasks->find((int) $t['id'])));
    }

    public function delete_task(WP_REST_Request $r)
    {
        $t = $this->visible_task((int) $r['id']);

        if (is_wp_error($t)) {
            return $t;
        }

        if (!$this->access->can_edit($t)) {
            return $this->forbidden();
        }

        $this->tasks->soft_delete((int) $t['id']);

        return $this->ok(['deleted' => true, 'id' => (int) $t['id']]);
    }

    /**
     * Task fields from the request; linked records must be editable.
     * due_at (ISO, any zone) is converted to the shop's date + time.
     *
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>|WP_Error
     */
    private function task_input(WP_REST_Request $r, ?array $existing = null)
    {
        $input = $this->only($r, ['title', 'description', 'due_date', 'due_time', 'remind', 'priority', 'contact_id', 'deal_id']);

        if (null !== $r['due_at']) {
            $ts = strtotime((string) $r['due_at']);

            if (false === $ts) {
                return new WP_Error('vkl_crm_task', __('The due date is not valid.', 'vereskala-login'), ['status' => 422, 'field' => 'due_at']);
            }

            $input['due_date'] = (string) wp_date('Y-m-d', $ts);
            $input['due_time'] = (string) wp_date('H:i', $ts);
        }

        foreach (['contact_id' => $this->contacts, 'deal_id' => $this->deals] as $field => $repo) {
            $rid = (int) ($input[$field] ?? 0);

            if ($rid > 0 && (int) ($existing[$field] ?? 0) !== $rid) {
                $rec = $repo->find($rid);

                if (null === $rec || !$this->access->can_edit($rec)) {
                    return $this->forbidden();
                }
            }
        }

        return $input;
    }

    /* ================================================================== *
     *  Payloads
     * ================================================================== */

    /** @param array<string, mixed> $c */
    private function contact_payload(array $c): array
    {
        return [
            'id'               => (int) $c['id'],
            'user_id'          => isset($c['user_id']) ? (int) $c['user_id'] : null,
            'name'             => VKL_Crm_Ui::contact_name($c),
            'first_name'       => (string) $c['first_name'],
            'last_name'        => (string) $c['last_name'],
            'company'          => (string) $c['company'],
            'mobile'           => null === $c['mobile'] ? null : (string) $c['mobile'],
            'email'            => (string) $c['email'],
            'city'             => (string) $c['city'],
            'birthday'         => $c['birthday'] ?: null,
            'lifecycle'        => (string) $c['lifecycle'],
            'source'           => (string) $c['source'],
            'owner_id'         => (int) $c['owner_id'],
            'owner_name'       => (int) $c['owner_id'] > 0 ? VKL_Crm_Ui::user_name((int) $c['owner_id']) : null,
            'score'            => (int) $c['score'],
            'orders_count'     => (int) $c['orders_count'],
            'total_spent'      => (float) $c['total_spent'],
            'last_order_at'    => $this->iso($c['last_order_at'] ?? null),
            'last_activity_at' => $this->iso($c['last_activity_at'] ?? null),
            'tags'             => array_map(static fn(array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'type' => $t['type'], 'color' => $t['color']], $this->tags->for_contact((int) $c['id'])),
            'created_at'       => $this->iso($c['created_at']),
            'updated_at'       => $this->iso($c['updated_at']),
            'deleted'          => null !== $c['deleted_at'],
            'can_edit'         => null === $c['deleted_at'] && $this->access->can_edit($c),
        ];
    }

    /** @param array<string, mixed> $d */
    private function deal_payload(array $d): array
    {
        $stage   = $this->pipelines->stage((int) $d['stage_id']);
        $contact = $this->contacts->find((int) $d['contact_id'], true);

        return [
            'id'             => (int) $d['id'],
            'title'          => (string) $d['title'],
            'contact_id'     => (int) $d['contact_id'],
            'contact_name'   => null === $contact ? null : VKL_Crm_Ui::contact_name($contact),
            'pipeline_id'    => (int) $d['pipeline_id'],
            'stage_id'       => (int) $d['stage_id'],
            'stage_name'     => (string) ($stage['name'] ?? ''),
            'status'         => (string) $d['status'],
            'value'          => (float) $d['value'],
            'owner_id'       => (int) $d['owner_id'],
            'owner_name'     => (int) $d['owner_id'] > 0 ? VKL_Crm_Ui::user_name((int) $d['owner_id']) : null,
            'expected_close' => $d['expected_close'] ?: null,
            'lost_reason'    => (string) $d['lost_reason'],
            'order_id'       => (int) $d['order_id'] ?: null,
            'source'         => (string) $d['source'],
            'closed_at'      => $this->iso($d['closed_at'] ?? null),
            'created_at'     => $this->iso($d['created_at']),
            'updated_at'     => $this->iso($d['updated_at']),
            'deleted'        => null !== $d['deleted_at'],
            'can_edit'       => null === $d['deleted_at'] && $this->access->can_edit($d),
        ];
    }

    /** @param array<string, mixed> $t */
    private function task_payload(array $t): array
    {
        return [
            'id'           => (int) $t['id'],
            'title'        => (string) $t['title'],
            'description'  => (string) $t['description'],
            'contact_id'   => (int) $t['contact_id'] ?: null,
            'deal_id'      => (int) $t['deal_id'] ?: null,
            'assignee_id'  => (int) $t['assignee_id'],
            'assignee_name'=> VKL_Crm_Ui::user_name((int) $t['assignee_id']),
            'due_at'       => $this->iso($t['due_at'] ?? null),
            'remind_at'    => $this->iso($t['remind_at'] ?? null),
            'priority'     => (string) $t['priority'],
            'status'       => (string) $t['status'],
            'completed_at' => $this->iso($t['completed_at'] ?? null),
            'created_at'   => $this->iso($t['created_at']),
            'updated_at'   => $this->iso($t['updated_at']),
            'deleted'      => null !== $t['deleted_at'],
            'can_edit'     => null === $t['deleted_at'] && $this->access->can_edit($t),
        ];
    }

    /** @param array<string, mixed> $a */
    private function activity_payload(array $a): array
    {
        return [
            'id'           => (int) $a['id'],
            'type'         => (string) $a['type'],
            'deal_id'      => (int) $a['deal_id'] ?: null,
            'subject'      => (string) $a['subject'],
            'body'         => (string) $a['body'],
            'direction'    => (string) $a['direction'],
            'outcome'      => (string) $a['outcome'],
            'minutes'      => (int) round((int) $a['duration'] / 60),
            'scheduled_at' => $this->iso($a['scheduled_at'] ?? null),
            'done_at'      => $this->iso($a['done_at'] ?? null),
            'author_id'    => (int) $a['author_id'] ?: null,
            'author_name'  => (int) $a['author_id'] > 0 ? VKL_Crm_Ui::user_name((int) $a['author_id']) : null,
            'ref_type'     => (string) $a['ref_type'],
            'ref_id'       => (int) $a['ref_id'] ?: null,
            'created_at'   => $this->iso($a['created_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function user_payload(int $user_id): array
    {
        $u    = get_userdata($user_id);
        $caps = [];

        foreach (VKL_Crm_Access::all_caps() as $cap) {
            $caps[$cap] = user_can($user_id, $cap);
        }

        return [
            'id'           => $user_id,
            'name'         => $u instanceof WP_User ? (string) $u->display_name : '',
            'email'        => $u instanceof WP_User && !str_ends_with((string) $u->user_email, '.local') ? (string) $u->user_email : '',
            'capabilities' => $caps,
        ];
    }

    /* ================================================================== *
     *  Helpers
     * ================================================================== */

    /** @return array<string, mixed>|WP_Error */
    private function visible_contact(int $id)
    {
        $c = $this->contacts->find($id);

        return null === $c || !$this->access->can_view($c) ? $this->not_found() : $c;
    }

    /** @return array<string, mixed>|WP_Error */
    private function visible_deal(int $id)
    {
        $d = $this->deals->find($id);

        return null === $d || !$this->access->can_view($d) ? $this->not_found() : $d;
    }

    /** @return array<string, mixed>|WP_Error */
    private function visible_task(int $id)
    {
        $t = $this->tasks->find($id);

        return null === $t || !$this->access->can_view($t) ? $this->not_found() : $t;
    }

    /**
     * The given keys that are present in the request (null = absent).
     *
     * @param string[] $keys
     * @return array<string, mixed>
     */
    private function only(WP_REST_Request $r, array $keys): array
    {
        $out    = [];
        $params = $r->get_params();

        foreach ($keys as $k) {
            if (array_key_exists($k, $params) && null !== $params[$k] && !in_array($k, ['id'], true)) {
                $out[$k] = is_scalar($params[$k]) ? (string) $params[$k] : $params[$k];
            }
        }

        return $out;
    }

    private function set_tags(int $contact_id, WP_REST_Request $r): void
    {
        if (null === $r['tag_ids']) {
            return;
        }

        $valid = array_map(static fn(array $t): int => (int) $t['id'], $this->tags->all());
        $this->tags->set_for_contact($contact_id, array_values(array_intersect(array_map('intval', (array) $r['tag_ids']), $valid)));
    }

    /**
     * updated_since (ISO) → UTC filter that also returns deleted rows.
     *
     * @return array<string, mixed>
     */
    private function sync_filters(WP_REST_Request $r): array
    {
        if (empty($r['updated_since'])) {
            return [];
        }

        $ts = strtotime((string) $r['updated_since']);

        return false === $ts ? [] : ['updated_since' => gmdate('Y-m-d H:i:s', $ts), 'with_deleted' => true];
    }

    /**
     * @param array<int, array<string, mixed>>                                  $items
     * @param array{total: int, pages: int, page: int, per_page: int, rows: mixed} $res
     */
    private function paged(array $items, array $res): WP_REST_Response
    {
        $response = $this->ok($items);
        $response->header('X-WP-Total', (string) $res['total']);
        $response->header('X-WP-TotalPages', (string) $res['pages']);

        return $response;
    }

    /**
     * @param mixed $data
     */
    private function ok($data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('X-VKL-Server-Time', gmdate('Y-m-d\TH:i:s\Z'));
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private function iso(?string $utc): ?string
    {
        if (empty($utc) || str_starts_with($utc, '0000')) {
            return null;
        }

        $ts = strtotime($utc . ' UTC');

        return false === $ts ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * @param array<string, string> $map
     * @return array<int, array{key: string, label: string}>
     */
    private function pairs(array $map): array
    {
        $out = [];

        foreach ($map as $k => $v) {
            $out[] = ['key' => (string) $k, 'label' => $v];
        }

        return $out;
    }

    private function not_found(): WP_Error
    {
        return new WP_Error('vkl_crm_not_found', __('Not found.', 'vereskala-login'), ['status' => 404]);
    }

    private function forbidden(): WP_Error
    {
        return new WP_Error('vkl_crm_forbidden', __('You are not allowed to do that.', 'vereskala-login'), ['status' => 403]);
    }
}
