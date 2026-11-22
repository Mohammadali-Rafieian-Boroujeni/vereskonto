<?php
/**
 * CRM → Tasks: the staff to-do list (today, overdue, upcoming, no date,
 * done) with quick add, and the task form.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.18.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Tasks_Page
 */
final class VKL_Crm_Tasks_Page
{
    private VKL_Crm_Tasks $tasks;
    private VKL_Crm_Access $access;
    private VKL_Crm_Task_View $view;

    public function __construct(VKL_Crm_Tasks $tasks, VKL_Crm_Access $access, VKL_Crm_Task_View $view)
    {
        $this->tasks  = $tasks;
        $this->access = $access;
        $this->view   = $view;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Tasks', 'vereskala-login'),
            __('Tasks', 'vereskala-login'),
            VKL_Crm_Access::CAP_ACCESS,
            VKL_Crm_Task_View::PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [VKL_Crm_Ui::class, 'enqueue']);
        }
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        $id     = isset($_GET['task']) ? absint($_GET['task']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap vkl-crm">';

        if ('new' === $action) {
            $this->view->form(null, isset($_GET['contact']) ? absint($_GET['contact']) : 0, isset($_GET['deal']) ? absint($_GET['deal']) : 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif ($id > 0) {
            $task = $this->tasks->find($id);

            if (null === $task || !$this->access->can_view($task)) {
                echo '<h1>' . esc_html__('Tasks', 'vereskala-login') . '</h1>';
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(null === $task ? __('That task no longer exists.', 'vereskala-login') : __('You are not allowed to see this task.', 'vereskala-login')));
            } else {
                $this->view->form($task);
            }
        } else {
            $this->render_list();
        }

        echo '</div>';
    }

    private function render_list(): void
    {
        $view     = isset($_GET['view']) && in_array($_GET['view'], VKL_Crm_Tasks::VIEWS, true) ? (string) $_GET['view'] : 'today'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $assignee = isset($_GET['assignee_id']) ? sanitize_text_field(wp_unslash((string) $_GET['assignee_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $priority = isset($_GET['priority']) ? sanitize_key((string) $_GET['priority']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $q        = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $managers = $this->access->can(VKL_Crm_Access::CAP_EDIT_ALL);

        // Agents see their own list; managers default to their own too and
        // can switch to someone else or everyone.
        if ('' === $assignee) {
            $assignee = (string) get_current_user_id();
        }

        $filters = ['view' => $view, 'priority' => $priority, 'q' => $q];

        if ('all' !== $assignee) {
            $filters['assignee_id'] = $managers ? $assignee : (string) get_current_user_id();
        }

        $scope   = $this->access->scope_sql('t.assignee_id');
        [$s, $e] = VKL_Crm_Task_Service::day_bounds();
        $res     = $this->tasks->search($filters, $scope, $s, $e, $paged, 50);
        $base    = VKL_Crm_Task_View::url(array_filter(['assignee_id' => $assignee !== (string) get_current_user_id() ? $assignee : null, 'priority' => $priority ?: null, 'q' => $q ?: null]));
        $labels  = [
            'today'    => __('Today', 'vereskala-login'),
            'overdue'  => __('Overdue', 'vereskala-login'),
            'upcoming' => __('Upcoming', 'vereskala-login'),
            'no_date'  => __('No date', 'vereskala-login'),
            'done'     => __('Done', 'vereskala-login'),
            'all'      => __('All', 'vereskala-login'),
        ];
        ?>
        <div class="vkl-crm-head">
            <h1 class="wp-heading-inline"><?php esc_html_e('Tasks', 'vereskala-login'); ?></h1>
            <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) : ?>
                <a href="<?php echo esc_url(VKL_Crm_Task_View::url(['action' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Add task', 'vereskala-login'); ?></a>
            <?php endif; ?>
        </div>
        <hr class="wp-header-end">
        <?php VKL_Crm_Ui::notices(); ?>

        <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) : ?>
            <div class="vkl-crm-card"><?php $this->view->quick_add(VKL_Crm_Task_View::url()); ?></div>
        <?php endif; ?>

        <nav class="vkl-crm-chips vkl-crm-task-views" aria-label="<?php esc_attr_e('Task views', 'vereskala-login'); ?>">
            <?php foreach ($labels as $key => $label) :
                $count = 'all' === $key || 'done' === $key ? null : $this->tasks->search(['view' => $key] + array_diff_key($filters, ['view' => 1]), $scope, $s, $e, 1, 1)['total'];
                ?>
                <a href="<?php echo esc_url(add_query_arg('view', $key, $base)); ?>" class="<?php echo $view === $key ? 'is-active' : ''; ?><?php echo 'overdue' === $key && $count > 0 ? ' is-alert' : ''; ?>"><?php echo esc_html($label); ?><?php if (null !== $count) : ?> <span class="count"><?php echo esc_html(number_format_i18n($count)); ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="vkl-crm-board-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr(VKL_Crm_Task_View::PAGE); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>">
            <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Search tasks…', 'vereskala-login'); ?>">
            <?php if ($managers) :
                $who = [(string) get_current_user_id() => __('My tasks', 'vereskala-login'), 'all' => __('Everyone', 'vereskala-login')];
                foreach ($this->access->assignable_users() as $uid => $name) {
                    $who[(string) $uid] ??= $name;
                }
                echo VKL_Crm_Ui::select('assignee_id', $who, $assignee); // phpcs:ignore
            endif; ?>
            <?php echo VKL_Crm_Ui::select('priority', VKL_Crm_Task_View::priorities(), $priority, __('Any priority', 'vereskala-login')); // phpcs:ignore ?>
            <button class="button"><?php esc_html_e('Filter', 'vereskala-login'); ?></button>
        </form>

        <section class="vkl-crm-card">
            <?php if ([] === $res['rows']) : ?>
                <p class="vkl-crm-empty"><?php echo esc_html('today' === $view ? __('Nothing due today. 🎉', 'vereskala-login') : __('No tasks here.', 'vereskala-login')); ?></p>
            <?php else : ?>
                <ul class="vkl-crm-tasks">
                    <?php foreach ($res['rows'] as $t) {
                        $this->view->row($t, add_query_arg('view', $view, $base));
                    } ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($res['pages'] > 1) : ?>
            <p class="vkl-crm-pages">
                <?php for ($p = 1; $p <= $res['pages']; $p++) : ?>
                    <a class="button<?php echo $p === $res['page'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(['view' => $view, 'paged' => $p], $base)); ?>"><?php echo esc_html(number_format_i18n($p)); ?></a>
                <?php endfor; ?>
            </p>
        <?php endif;
    }
}
