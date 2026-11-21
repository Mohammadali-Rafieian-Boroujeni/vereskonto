<?php
/**
 * Task service: validation, the task life cycle (create, edit, done,
 * reopen, delete), timeline entries, reminders and the menu badge.
 *
 * Dates are entered in shop time (Jalali or Gregorian date + HH:MM) and
 * stored in UTC. "Today" always means the shop's calendar day.
 *
 * Reminders: a 5-minute WP-Cron job (plus an admin-page fallback) emails
 * the assignee when a task's reminder time passes, once per task.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.18.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Task_Service
 */
final class VKL_Crm_Task_Service
{
    public const HOOK     = 'vkl_crm_task_reminders';
    public const SCHEDULE = 'vkl_crm_five_minutes';

    /** Reminder offsets before the due time, in minutes. */
    public const REMIND_OFFSETS = [0, 15, 60, 1440];

    private VKL_Crm_Tasks $tasks;
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Access $access;
    private VKL_Config $config;

    public function __construct(VKL_Crm_Tasks $tasks, VKL_Crm_Contacts $contacts, VKL_Crm_Deals $deals, VKL_Crm_Activities $activities, VKL_Crm_Access $access, VKL_Config $config)
    {
        $this->tasks      = $tasks;
        $this->contacts   = $contacts;
        $this->deals      = $deals;
        $this->activities = $activities;
        $this->access     = $access;
        $this->config     = $config;
    }

    /**
     * Shop-day bounds in UTC: [start, end] of today (or $day, Y-m-d).
     *
     * @return array{0: string, 1: string}
     */
    public static function day_bounds(string $day = ''): array
    {
        $tz    = wp_timezone();
        $start = new DateTimeImmutable(('' !== $day ? $day : 'today') . ' 00:00:00', $tz);
        $utc   = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $start->modify('+1 day -1 second')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Jalali or Gregorian date (+ optional HH:MM, default 09:00) in shop
     * time → UTC 'Y-m-d H:i:s'. '' → null; invalid → false.
     *
     * @return string|null|false
     */
    public static function local_to_utc(string $date, string $time = '')
    {
        $date = trim(vkl_latin_digits($date));

        if ('' === $date) {
            return null;
        }

        $g = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && (int) substr($date, 0, 4) > 1700 ? $date : VKL_Jalali::parse($date);
        $t = trim(vkl_latin_digits($time));
        $t = preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t) ? $t : '09:00';

        if ('' === $g) {
            return false;
        }

        try {
            return (new DateTimeImmutable($g . ' ' . $t . ':00', wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Validate task input (keys present only).
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>|WP_Error
     */
    public function sanitize(array $input, ?array $existing = null)
    {
        $out = [];

        if (array_key_exists('title', $input)) {
            $out['title'] = mb_substr(trim(sanitize_text_field((string) $input['title'])), 0, 190);

            if ('' === $out['title']) {
                return $this->error(__('Give the task a title.', 'vereskala-login'), 'title');
            }
        }

        if (array_key_exists('description', $input)) {
            $out['description'] = mb_substr(sanitize_textarea_field((string) $input['description']), 0, 5000);
        }

        if (array_key_exists('priority', $input)) {
            $out['priority'] = in_array($input['priority'], VKL_Crm_Tasks::PRIORITIES, true) ? $input['priority'] : 'normal';
        }

        if (array_key_exists('due_date', $input)) {
            $due = self::local_to_utc((string) $input['due_date'], (string) ($input['due_time'] ?? ''));

            if (false === $due) {
                return $this->error(__('The due date is not valid.', 'vereskala-login'), 'due_date');
            }

            $out['due_at'] = $due;
        }

        if (array_key_exists('remind', $input)) {
            $due    = array_key_exists('due_at', $out) ? $out['due_at'] : ($existing['due_at'] ?? null);
            $remind = (string) $input['remind'];

            if ('' === $remind || null === $due) {
                $out['remind_at'] = null;
            } elseif (in_array((int) $remind, self::REMIND_OFFSETS, true) && ctype_digit($remind)) {
                $out['remind_at'] = gmdate('Y-m-d H:i:s', (int) strtotime($due . ' UTC') - (int) $remind * MINUTE_IN_SECONDS);
            } else {
                return $this->error(__('Unknown reminder option.', 'vereskala-login'), 'remind');
            }

            // A changed reminder time is a new reminder.
            if (($existing['remind_at'] ?? null) !== $out['remind_at']) {
                $out['reminded_at'] = null;
            }
        }

        if (array_key_exists('assignee_id', $input)) {
            $assignee = max(0, (int) $input['assignee_id']);

            if ($assignee > 0 && !user_can($assignee, VKL_Crm_Access::CAP_ACCESS)) {
                return $this->error(__('Tasks can only be given to CRM staff.', 'vereskala-login'), 'assignee_id');
            }

            $out['assignee_id'] = $assignee;
        }

        if (array_key_exists('contact_id', $input)) {
            $cid = (int) $input['contact_id'];

            if ($cid > 0 && null === $this->contacts->find($cid)) {
                return $this->error(__('That contact no longer exists.', 'vereskala-login'), 'contact_id');
            }

            $out['contact_id'] = max(0, $cid);
        }

        if (array_key_exists('deal_id', $input)) {
            $did  = (int) $input['deal_id'];
            $deal = $did > 0 ? $this->deals->find($did) : null;

            if ($did > 0 && null === $deal) {
                return $this->error(__('That deal no longer exists.', 'vereskala-login'), 'deal_id');
            }

            $out['deal_id'] = max(0, $did);

            // A deal's task also belongs to the deal's contact.
            if (null !== $deal) {
                $out['contact_id'] = (int) $deal['contact_id'];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return int|WP_Error
     */
    public function create(array $input, int $author_id)
    {
        $data = $this->sanitize($input + ['priority' => 'normal']);

        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data['title'])) {
            return $this->error(__('Give the task a title.', 'vereskala-login'), 'title');
        }

        $data += ['assignee_id' => $author_id, 'status' => 'open'];
        $data['created_by'] = $author_id;

        $id = $this->tasks->create($data);

        if ($id <= 0) {
            return $this->error(__('The task could not be saved.', 'vereskala-login'), '', 500);
        }

        if (!empty($data['contact_id'])) {
            $this->log((int) $data['contact_id'], (int) ($data['deal_id'] ?? 0), sprintf(/* translators: %s: task title */ __('Task added: %s', 'vereskala-login'), $data['title']), $author_id);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @return true|WP_Error
     */
    public function update(int $id, array $input)
    {
        $task = $this->tasks->find($id);

        if (null === $task) {
            return $this->error(__('That task no longer exists.', 'vereskala-login'), '', 404);
        }

        $data = $this->sanitize($input, $task);

        if (is_wp_error($data)) {
            return $data;
        }

        return [] === $data || $this->tasks->update($id, $data) ? true : $this->error(__('The task could not be saved.', 'vereskala-login'), '', 500);
    }

    public function set_done(int $id, bool $done, int $user_id): bool
    {
        $task = $this->tasks->find($id);

        if (null === $task || ($done === ('done' === $task['status']))) {
            return null !== $task;
        }

        $ok = $done ? $this->tasks->complete($id) : $this->tasks->reopen($id);

        if ($ok && $done && (int) $task['contact_id'] > 0) {
            $this->log((int) $task['contact_id'], (int) $task['deal_id'], sprintf(/* translators: %s: task title */ __('Task done: %s', 'vereskala-login'), $task['title']), $user_id);
        }

        return $ok;
    }

    /**
     * Open tasks due today or overdue for the current user: the number on
     * the CRM → Tasks menu item.
     */
    public function badge_count(): int
    {
        $me = get_current_user_id();

        if ($me <= 0 || !current_user_can(VKL_Crm_Access::CAP_ACCESS)) {
            return 0;
        }

        [, $end] = self::day_bounds();

        return (int) vkl('db')->get_var(
            "SELECT COUNT(*) FROM {crm_tasks} WHERE assignee_id = %d AND status = 'open' AND deleted_at IS NULL AND due_at IS NOT NULL AND due_at <= %s",
            $me,
            $end
        );
    }

    /* ------------------------------------------------------------------ *
     *  Reminders
     * ------------------------------------------------------------------ */

    public function register(): void
    {
        // Plain label on purpose: schedule lists can be read before init,
        // and a translated label there triggers "translation too early".
        add_filter('cron_schedules', static function (array $s): array {
            $s[self::SCHEDULE] ??= ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Vereskonto CRM)'];

            return $s;
        });

        add_action(self::HOOK, [$this, 'send_reminders']);
        add_action('admin_init', [$this, 'reminders_on_admin'], 30);

        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK);
            }
        }, 20);
    }

    /**
     * Fallback when WP-Cron does not fire: at most once a minute.
     */
    public function reminders_on_admin(): void
    {
        if (wp_doing_ajax() || get_transient('vkl_crm_remind_tick') || !current_user_can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        set_transient('vkl_crm_remind_tick', 1, MINUTE_IN_SECONDS);
        $this->send_reminders();
    }

    /**
     * Email every assignee whose reminder time has passed (once per task).
     *
     * @return int Reminders sent.
     */
    public function send_reminders(): int
    {
        if (!$this->config->get_bool('crm_task_email', true)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->tasks->due_reminders(100) as $task) {
            // Claim first, so two runners never mail the same task.
            $claimed = vkl('db')->query(
                'UPDATE {crm_tasks} SET reminded_at = %s WHERE id = %d AND reminded_at IS NULL',
                gmdate('Y-m-d H:i:s'),
                (int) $task['id']
            );

            if (1 !== $claimed) {
                continue;
            }

            $user = get_userdata((int) $task['assignee_id']);

            if (!$user instanceof WP_User || '' === (string) $user->user_email || !user_can($user, VKL_Crm_Access::CAP_ACCESS)) {
                continue;
            }

            if ($this->mail($user, $task)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function mail(WP_User $user, array $task): bool
    {
        $contact = (int) $task['contact_id'] > 0 ? $this->contacts->find((int) $task['contact_id'], true) : null;
        $url     = add_query_arg(['page' => 'vkl-crm-tasks', 'task' => (int) $task['id']], admin_url('admin.php'));
        $site    = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $lines   = [
            sprintf(/* translators: %s: task title */ __('Reminder: %s', 'vereskala-login'), $task['title']),
            '',
        ];

        if (!empty($task['due_at'])) {
            $lines[] = sprintf(/* translators: %s: date and time */ __('Due: %s', 'vereskala-login'), VKL_Jalali::format((string) $task['due_at'], 'long', true));
        }

        if (null !== $contact) {
            $lines[] = sprintf(/* translators: %s: contact */ __('Contact: %s', 'vereskala-login'), class_exists('VKL_Crm_Ui') ? VKL_Crm_Ui::contact_name($contact) : (string) $contact['first_name']);

            if (!empty($contact['mobile'])) {
                $lines[] = sprintf(/* translators: %s: mobile */ __('Mobile: %s', 'vereskala-login'), $contact['mobile']);
            }
        }

        if ('' !== trim((string) $task['description'])) {
            $lines[] = '';
            $lines[] = (string) $task['description'];
        }

        $lines[] = '';
        $lines[] = $url;

        return (bool) wp_mail(
            $user->user_email,
            sprintf('[%1$s] %2$s', $site, sprintf(/* translators: %s: task title */ __('Reminder: %s', 'vereskala-login'), $task['title'])),
            implode("\n", $lines)
        );
    }

    /* ------------------------------------------------------------------ */

    private function log(int $contact_id, int $deal_id, string $text, int $author_id): void
    {
        $this->activities->log([
            'contact_id' => $contact_id,
            'deal_id'    => $deal_id,
            'type'       => 'task',
            'subject'    => mb_substr($text, 0, 190),
            'author_id'  => $author_id,
            'done_at'    => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function error(string $message, string $field = '', int $status = 422): WP_Error
    {
        return new WP_Error('vkl_crm_task', $message, ['status' => $status, 'field' => $field]);
    }
}
