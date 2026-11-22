<?php
/**
 * Task rows, the "Tasks" card (contact profile and deal page), the quick
 * add form, and the task form handlers — shared by every screen that
 * shows tasks.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.18.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Task_View
 */
final class VKL_Crm_Task_View
{
    public const PAGE = 'vkl-crm-tasks';

    private const FORM_STASH = 'vkl_crm_task_form_';

    private VKL_Crm_Tasks $tasks;
    private VKL_Crm_Task_Service $service;
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Access $access;

    public function __construct(VKL_Crm_Tasks $tasks, VKL_Crm_Task_Service $service, VKL_Crm_Contacts $contacts, VKL_Crm_Deals $deals, VKL_Crm_Access $access)
    {
        $this->tasks    = $tasks;
        $this->service  = $service;
        $this->contacts = $contacts;
        $this->deals    = $deals;
        $this->access   = $access;
    }

    public function register(): void
    {
        foreach (['save', 'toggle', 'delete'] as $action) {
            add_action('admin_post_vkl_crm_task_' . $action, [$this, 'handle_' . $action]);
        }
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    /** @return array<string, string> */
    public static function priorities(): array
    {
        return [
            'low'    => __('Low', 'vereskala-login'),
            'normal' => __('Normal', 'vereskala-login'),
            'high'   => __('High', 'vereskala-login'),
            'urgent' => __('Urgent', 'vereskala-login'),
        ];
    }

    /** @return array<string, string> '' = no reminder; minutes before due otherwise. */
    public static function reminders(): array
    {
        return [
            ''     => __('No reminder', 'vereskala-login'),
            '0'    => __('At the due time', 'vereskala-login'),
            '15'   => __('15 minutes before', 'vereskala-login'),
            '60'   => __('1 hour before', 'vereskala-login'),
            '1440' => __('1 day before', 'vereskala-login'),
        ];
    }

    /**
     * The reminder option a task currently has ('' when none / custom).
     *
     * @param array<string, mixed> $t
     */
    public static function remind_option(array $t): string
    {
        if (empty($t['remind_at']) || empty($t['due_at'])) {
            return '';
        }

        $min = (int) round((strtotime($t['due_at'] . ' UTC') - strtotime($t['remind_at'] . ' UTC')) / 60);

        return in_array($min, VKL_Crm_Task_Service::REMIND_OFFSETS, true) ? (string) $min : '';
    }

    /**
     * Due date as shop-time parts for form fields.
     *
     * @return array{0: string, 1: string} [Jalali date, HH:MM]
     */
    public static function due_parts(?string $utc): array
    {
        if (empty($utc)) {
            return ['', ''];
        }

        $ts = (int) strtotime($utc . ' UTC');

        return [VKL_Jalali::format($utc, 'short'), (string) wp_date('H:i', $ts)];
    }

    /**
     * One task row (list page and cards).
     *
     * @param array<string, mixed> $t
     */
    public function row(array $t, string $back, bool $show_links = true): void
    {
        $done      = 'done' === $t['status'];
        $can_edit  = $this->access->can_edit($t);
        $now       = gmdate('Y-m-d H:i:s');
        [, $today] = VKL_Crm_Task_Service::day_bounds();
        $overdue   = !$done && !empty($t['due_at']) && $t['due_at'] < $now;
        $is_today  = !$done && !$overdue && !empty($t['due_at']) && $t['due_at'] <= $today;
        $contact   = $show_links && (int) $t['contact_id'] > 0 ? $this->contacts->find((int) $t['contact_id'], true) : null;
        $deal      = $show_links && (int) $t['deal_id'] > 0 ? $this->deals->find((int) $t['deal_id']) : null;
        ?>
        <li class="vkl-crm-task<?php echo $done ? ' is-done' : ''; ?><?php echo $overdue ? ' is-overdue' : ''; ?> is-<?php echo esc_attr((string) $t['priority']); ?>">
            <?php if ($can_edit) : ?>
                <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-task-check">
                    <input type="hidden" name="action" value="vkl_crm_task_toggle">
                    <input type="hidden" name="task" value="<?php echo esc_attr((string) $t['id']); ?>">
                    <input type="hidden" name="done" value="<?php echo $done ? '0' : '1'; ?>">
                    <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
                    <?php wp_nonce_field('vkl_crm_task_toggle_' . (int) $t['id']); ?>
                    <button class="vkl-crm-checkbox" aria-pressed="<?php echo $done ? 'true' : 'false'; ?>" title="<?php echo esc_attr($done ? __('Mark as not done', 'vereskala-login') : __('Mark as done', 'vereskala-login')); ?>"><span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html($done ? __('Mark as not done', 'vereskala-login') : __('Mark as done', 'vereskala-login')); ?></span></button>
                </form>
            <?php else : ?>
                <span class="vkl-crm-checkbox is-static" aria-hidden="true"></span>
            <?php endif; ?>
            <div class="vkl-crm-task-body">
                <div class="vkl-crm-task-title">
                    <?php if ($can_edit) : ?>
                        <a href="<?php echo esc_url(self::url(['task' => (int) $t['id']])); ?>"><?php echo esc_html((string) $t['title']); ?></a>
                    <?php else : ?>
                        <?php echo esc_html((string) $t['title']); ?>
                    <?php endif; ?>
                    <?php if (in_array($t['priority'], ['high', 'urgent'], true)) : ?>
                        <span class="vkl-crm-pill is-prio-<?php echo esc_attr((string) $t['priority']); ?>"><?php echo esc_html(self::priorities()[$t['priority']]); ?></span>
                    <?php endif; ?>
                </div>
                <div class="vkl-crm-task-meta">
                    <?php if (!empty($t['due_at'])) : ?>
                        <span class="vkl-crm-task-due<?php echo $overdue ? ' is-overdue' : ($is_today ? ' is-today' : ''); ?>"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php echo esc_html(VKL_Jalali::format((string) $t['due_at'], 'long', true)); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($t['remind_at']) && !$done) : ?>
                        <span title="<?php esc_attr_e('Reminder', 'vereskala-login'); ?>"><span class="dashicons dashicons-bell" aria-hidden="true"></span></span>
                    <?php endif; ?>
                    <?php if (null !== $contact) : ?>
                        <a href="<?php echo esc_url(VKL_Crm_Ui::contact_url((int) $contact['id'])); ?>"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo esc_html(VKL_Crm_Ui::contact_name($contact)); ?></a>
                    <?php endif; ?>
                    <?php if (null !== $deal) : ?>
                        <a href="<?php echo esc_url(VKL_Crm_Deals_Page::deal_url((int) $deal['id'])); ?>"><span class="dashicons dashicons-portfolio" aria-hidden="true"></span><?php echo esc_html((string) $deal['title']); ?></a>
                    <?php endif; ?>
                    <span class="vkl-crm-owner"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><?php echo esc_html(VKL_Crm_Ui::user_name((int) $t['assignee_id'])); ?></span>
                </div>
            </div>
        </li>
        <?php
    }

    /**
     * "Tasks" card with open tasks and a quick add form.
     */
    public function card(int $contact_id, int $deal_id, string $back, bool $can_add): void
    {
        $filters = $deal_id > 0 ? ['deal_id' => $deal_id] : ['contact_id' => $contact_id];
        [$s, $e] = VKL_Crm_Task_Service::day_bounds();
        $open    = $this->tasks->search($filters + ['view' => 'all'], $this->access->scope_sql('t.assignee_id'), $s, $e, 1, 50)['rows'];
        $open    = array_values(array_filter($open, static fn(array $t): bool => 'open' === $t['status']));
        usort($open, static fn(array $a, array $b): int => strcmp((string) ($a['due_at'] ?? '9999'), (string) ($b['due_at'] ?? '9999')));
        ?>
        <section class="vkl-crm-card">
            <h2><?php esc_html_e('Tasks', 'vereskala-login'); ?></h2>
            <?php if ([] === $open) : ?>
                <p class="description"><?php esc_html_e('No open tasks.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <ul class="vkl-crm-tasks is-compact">
                    <?php foreach ($open as $t) {
                        $this->row($t, $back, false);
                    } ?>
                </ul>
            <?php endif; ?>
            <?php if ($can_add) : ?>
                <?php $this->quick_add($back, $contact_id, $deal_id); ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * One-line "what, when" form.
     */
    public function quick_add(string $back, int $contact_id = 0, int $deal_id = 0): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-quick-task">
            <input type="hidden" name="action" value="vkl_crm_task_save">
            <input type="hidden" name="task" value="0">
            <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) $contact_id); ?>">
            <input type="hidden" name="deal_id" value="<?php echo esc_attr((string) $deal_id); ?>">
            <input type="hidden" name="remind" value="0">
            <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
            <?php wp_nonce_field('vkl_crm_task_save_0'); ?>
            <label class="vkl-crm-qt-title"><span class="screen-reader-text"><?php esc_html_e('Task', 'vereskala-login'); ?></span><input type="text" name="title" required maxlength="190" placeholder="<?php esc_attr_e('New task, e.g. call back about the quote', 'vereskala-login'); ?>"></label>
            <label><span class="screen-reader-text"><?php esc_html_e('Due date', 'vereskala-login'); ?></span><input type="text" name="due_date" dir="ltr" inputmode="numeric" placeholder="<?php echo esc_attr(VKL_Jalali::format(gmdate('Y-m-d H:i:s'), 'short')); ?>" value="<?php echo esc_attr(VKL_Jalali::format(gmdate('Y-m-d H:i:s'), 'short')); ?>"></label>
            <label><span class="screen-reader-text"><?php esc_html_e('Time', 'vereskala-login'); ?></span><input type="time" name="due_time" dir="ltr" value="<?php echo esc_attr((string) wp_date('H:00', time() + HOUR_IN_SECONDS)); ?>"></label>
            <button class="button"><?php esc_html_e('Add task', 'vereskala-login'); ?></button>
        </form>
        <?php
    }

    /**
     * Full add/edit form.
     *
     * @param array<string, mixed>|null $task
     */
    public function form(?array $task, int $contact_id = 0, int $deal_id = 0): void
    {
        $id    = (int) ($task['id'] ?? 0);
        $stash = get_transient(self::FORM_STASH . get_current_user_id());
        $error = '';

        if (is_array($stash) && (int) ($stash['id'] ?? -1) === $id && isset($_GET['vkl_err'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $task  = array_merge((array) $task, (array) $stash['input']);
            $error = (string) $stash['error'];
            delete_transient(self::FORM_STASH . get_current_user_id());
        }

        $contact_id = (int) ($task['contact_id'] ?? $contact_id);
        $deal_id    = (int) ($task['deal_id'] ?? $deal_id);
        $contact    = $contact_id > 0 ? $this->contacts->find($contact_id, true) : null;
        $deal       = $deal_id > 0 ? $this->deals->find($deal_id) : null;
        [$date, $time] = isset($task['due_date']) ? [(string) $task['due_date'], (string) ($task['due_time'] ?? '')] : self::due_parts($task['due_at'] ?? null);
        $remind     = isset($task['remind']) ? (string) $task['remind'] : (null === $task ? '0' : self::remind_option($task));
        $back       = self::url();
        ?>
        <a class="vkl-crm-back" href="<?php echo esc_url($back); ?>">→ <?php esc_html_e('All tasks', 'vereskala-login'); ?></a>
        <h1><?php echo esc_html($id > 0 ? __('Edit task', 'vereskala-login') : __('Add task', 'vereskala-login')); ?></h1>
        <hr class="wp-header-end">
        <?php VKL_Crm_Ui::notices(); ?>
        <?php if ('' !== $error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-form" novalidate>
            <input type="hidden" name="action" value="vkl_crm_task_save">
            <input type="hidden" name="task" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
            <?php wp_nonce_field('vkl_crm_task_save_' . $id); ?>
            <div class="vkl-crm-card">
                <div class="vkl-crm-fields">
                    <label class="vkl-crm-span2"><span><?php esc_html_e('Title', 'vereskala-login'); ?></span><input type="text" name="title" required maxlength="190" value="<?php echo esc_attr((string) ($task['title'] ?? '')); ?>"></label>
                    <label><span><?php esc_html_e('Due date', 'vereskala-login'); ?></span><input type="text" name="due_date" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($date); ?>" placeholder="1405/09/10"></label>
                    <label><span><?php esc_html_e('Time', 'vereskala-login'); ?></span><input type="time" name="due_time" dir="ltr" value="<?php echo esc_attr($time); ?>"></label>
                    <label><span><?php esc_html_e('Reminder', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('remind', self::reminders(), $remind); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Priority', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('priority', self::priorities(), (string) ($task['priority'] ?? 'normal')); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Assigned to', 'vereskala-login'); ?></span>
                        <?php
                        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
                            echo VKL_Crm_Ui::owner_select('assignee_id', (int) ($task['assignee_id'] ?? get_current_user_id()), false); // phpcs:ignore
                        } else {
                            echo '<input type="text" readonly value="' . esc_attr(VKL_Crm_Ui::user_name((int) ($task['assignee_id'] ?? get_current_user_id()))) . '">';
                        }
                        ?>
                    </label>
                    <label><span><?php esc_html_e('Contact', 'vereskala-login'); ?></span>
                        <?php if (null !== $contact) : ?>
                            <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) $contact_id); ?>">
                            <input type="text" readonly value="<?php echo esc_attr(VKL_Crm_Ui::contact_name($contact)); ?>">
                        <?php else : ?>
                            <span class="vkl-crm-lookup" data-vkl-lookup data-nonce="<?php echo esc_attr(wp_create_nonce('vkl_crm_lookup')); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                                <input type="hidden" name="contact_id" value="0">
                                <input type="search" autocomplete="off" placeholder="<?php esc_attr_e('Optional — type a name or mobile…', 'vereskala-login'); ?>">
                                <ul class="vkl-crm-lookup-list" role="listbox" hidden></ul>
                            </span>
                        <?php endif; ?>
                    </label>
                    <?php if (null !== $deal) : ?>
                        <label><span><?php esc_html_e('Deal', 'vereskala-login'); ?></span><input type="hidden" name="deal_id" value="<?php echo esc_attr((string) $deal_id); ?>"><input type="text" readonly value="<?php echo esc_attr((string) $deal['title']); ?>"></label>
                    <?php endif; ?>
                    <label class="vkl-crm-span2"><span><?php esc_html_e('Details', 'vereskala-login'); ?></span><textarea name="description" rows="4"><?php echo esc_textarea((string) ($task['description'] ?? '')); ?></textarea></label>
                </div>
                <p class="description"><?php esc_html_e('The reminder is emailed to the assigned person.', 'vereskala-login'); ?></p>
            </div>
            <p class="submit">
                <button class="button button-primary button-large"><?php echo esc_html($id > 0 ? __('Save changes', 'vereskala-login') : __('Add task', 'vereskala-login')); ?></button>
                <?php if ($id > 0 && $this->access->can_edit((array) $task)) : ?>
                    <a class="button button-large vkl-crm-danger" data-confirm="task" href="<?php echo esc_url(VKL_Crm_Ui::action_url('delete', $id, 'vkl_crm_task_')); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </p>
        </form>
        <?php
    }

    /* ------------------------------------------------------------ handlers */

    public function handle_save(): void
    {
        $id = isset($_POST['task']) ? absint($_POST['task']) : 0;
        check_admin_referer('vkl_crm_task_save_' . $id);

        $me       = get_current_user_id();
        $back     = isset($_POST['_vkl_back']) ? VKL_Crm_Ui::back_url() : self::url();
        $existing = $id > 0 ? $this->tasks->find($id) : null;

        if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN) || ($id > 0 && (null === $existing || !$this->access->can_edit($existing)))) {
            VKL_Crm_Ui::redirect(self::url(), 'denied');
        }

        $input = [];

        foreach (['title', 'description', 'due_date', 'due_time', 'remind', 'priority', 'contact_id', 'deal_id'] as $field) {
            if (isset($_POST[$field])) {
                $input[$field] = wp_unslash((string) $_POST[$field]);
            }
        }

        // Linked records must be ones this user may work on.
        foreach (['contact_id' => $this->contacts, 'deal_id' => $this->deals] as $field => $repo) {
            $rid = (int) ($input[$field] ?? 0);

            if ($rid > 0 && ($existing[$field] ?? 0) != $rid) {
                $rec = $repo->find($rid);

                if (null === $rec || !$this->access->can_edit($rec)) {
                    VKL_Crm_Ui::redirect($back, 'denied');
                }
            }
        }

        // Managers assign to anyone with CRM access; agents to themselves.
        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && isset($_POST['assignee_id'])) {
            $input['assignee_id'] = absint($_POST['assignee_id']);
        } elseif (0 === $id) {
            $input['assignee_id'] = $me;
        }

        $result = 0 === $id ? $this->service->create($input, $me) : $this->service->update($id, $input);

        if (is_wp_error($result)) {
            set_transient(self::FORM_STASH . $me, ['id' => $id, 'input' => $input, 'error' => $result->get_error_message()], 300);

            if (isset($_POST['_vkl_back']) && 0 === $id && !str_contains($back, 'page=' . self::PAGE)) {
                VKL_Crm_Ui::redirect($back, 'invalid');
            }

            wp_safe_redirect(add_query_arg('vkl_err', '1', 0 === $id ? self::url(['action' => 'new']) : self::url(['task' => $id])));
            exit;
        }

        VKL_Crm_Ui::redirect($back, 0 === $id ? 'task_created' : 'task_saved');
    }

    public function handle_toggle(): void
    {
        $id = isset($_POST['task']) ? absint($_POST['task']) : 0;
        check_admin_referer('vkl_crm_task_toggle_' . $id);

        $task = $this->tasks->find($id);
        $back = VKL_Crm_Ui::back_url();

        if (null === $task || !$this->access->can_edit($task)) {
            VKL_Crm_Ui::redirect($back, 'denied');
        }

        $done = !empty($_POST['done']);
        $this->service->set_done($id, $done, get_current_user_id());
        VKL_Crm_Ui::redirect($back, $done ? 'task_done' : 'task_reopened');
    }

    public function handle_delete(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('vkl_crm_delete_' . $id);

        $task = $this->tasks->find($id);

        if (null === $task || !$this->access->can_edit($task)) {
            VKL_Crm_Ui::redirect(self::url(), 'denied');
        }

        $this->tasks->soft_delete($id);
        VKL_Crm_Ui::redirect(self::url(), 'task_deleted');
    }
}
