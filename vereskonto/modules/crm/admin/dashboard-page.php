<?php
/**
 * CRM → Dashboard: the figures that matter this period, the pipeline,
 * weekly trends, where leads come from, why deals are lost, my tasks
 * and the latest activity. Managers see the team; agents their own.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.19.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Dashboard_Page
 */
final class VKL_Crm_Dashboard_Page
{
    public const PAGE = 'vkl-crm';

    private VKL_Crm_Reports $reports;
    private VKL_Crm_Pipelines $pipelines;
    private VKL_Crm_Tasks $tasks;
    private VKL_Crm_Access $access;
    private VKL_Crm_Task_View $task_view;

    public function __construct(VKL_Crm_Reports $reports, VKL_Crm_Pipelines $pipelines, VKL_Crm_Tasks $tasks, VKL_Crm_Access $access, VKL_Crm_Task_View $task_view)
    {
        $this->reports   = $reports;
        $this->pipelines = $pipelines;
        $this->tasks     = $tasks;
        $this->access    = $access;
        $this->task_view = $task_view;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 19);
        add_action('wp_dashboard_setup', [$this, 'wp_widget']);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('CRM dashboard', 'vereskala-login'),
            __('CRM dashboard', 'vereskala-login'),
            VKL_Crm_Access::CAP_ACCESS,
            self::PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [VKL_Crm_Ui::class, 'enqueue']);
        }
    }

    /**
     * Period picker shared by the dashboard and the reports page.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [from, to, label, key]
     */
    public static function period_from_request(): array
    {
        $key  = isset($_GET['period']) && in_array($_GET['period'], VKL_Crm_Reports::PERIODS, true) ? (string) $_GET['period'] : 'this_month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash((string) $_GET['from'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash((string) $_GET['to'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        [$f, $t, $label] = VKL_Crm_Reports::period($key, $from, $to);

        return [$f, $t, $label, $key];
    }

    public static function period_form(string $page, string $key): void
    {
        $labels = [
            'this_month' => __('This month', 'vereskala-login'),
            'last_month' => __('Last month', 'vereskala-login'),
            'last_30'    => __('Last 30 days', 'vereskala-login'),
            'last_90'    => __('Last 90 days', 'vereskala-login'),
            'this_year'  => __('This year', 'vereskala-login'),
            'custom'     => __('Custom range', 'vereskala-login'),
        ];
        ?>
        <form method="get" class="vkl-crm-board-bar vkl-crm-period">
            <input type="hidden" name="page" value="<?php echo esc_attr($page); ?>">
            <label class="screen-reader-text" for="vkl-crm-period"><?php esc_html_e('Period', 'vereskala-login'); ?></label>
            <?php echo VKL_Crm_Ui::select('period', $labels, $key, '', 'vkl-crm-period'); // phpcs:ignore ?>
            <span data-show-custom>
                <input type="text" name="from" dir="ltr" inputmode="numeric" placeholder="1405/07/01" value="<?php echo esc_attr(isset($_GET['from']) ? sanitize_text_field(wp_unslash((string) $_GET['from'])) : ''); // phpcs:ignore ?>" aria-label="<?php esc_attr_e('From date', 'vereskala-login'); ?>">
                <input type="text" name="to" dir="ltr" inputmode="numeric" placeholder="1405/09/30" value="<?php echo esc_attr(isset($_GET['to']) ? sanitize_text_field(wp_unslash((string) $_GET['to'])) : ''); // phpcs:ignore ?>" aria-label="<?php esc_attr_e('To date', 'vereskala-login'); ?>">
            </span>
            <button class="button"><?php esc_html_e('Show', 'vereskala-login'); ?></button>
        </form>
        <?php
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        [$from, $to, $label, $key] = self::period_from_request();
        $scope  = $this->access->scope_sql('t.owner_id');
        $team   = $this->access->can(VKL_Crm_Access::CAP_EDIT_ALL);
        $k      = $this->reports->kpis($scope, $from, $to);
        $pipe   = $this->pipelines->default_pipeline();
        $funnel = null === $pipe ? [] : $this->reports->funnel((int) $pipe['id'], $scope);
        $weekly = $this->reports->weekly($scope, 12);
        [$ds, $de] = VKL_Crm_Task_Service::day_bounds();
        $mytasks = $this->tasks->counts(['t.assignee_id = %d', [get_current_user_id()]], $ds, $de); // always the viewer's own
        ?>
        <div class="wrap vkl-crm vkl-crm-dash">
            <div class="vkl-crm-head">
                <h1 class="wp-heading-inline"><?php esc_html_e('CRM dashboard', 'vereskala-login'); ?></h1>
                <span class="vkl-crm-scope"><?php echo esc_html($team ? __('Whole team', 'vereskala-login') : __('Your contacts and deals', 'vereskala-login')); ?> · <?php echo esc_html($label); ?></span>
            </div>
            <hr class="wp-header-end">
            <?php self::period_form(self::PAGE, $key); ?>

            <div class="vkl-crm-kpis is-wide">
                <?php
                $tiles = [
                    [number_format_i18n($k['new_contacts']), __('New contacts', 'vereskala-login'), VKL_Crm_Ui::contacts_url(['created_from' => VKL_Jalali::format($from, 'short')]), ''],
                    [number_format_i18n($k['won']), __('Deals won', 'vereskala-login'), VKL_Crm_Deals_Page::url(['view' => 'list', 'status' => 'won']), ''],
                    [VKL_Crm_Charts::plain_money($k['won_value']), __('Won value', 'vereskala-login'), '', ''],
                    [number_format_i18n($k['win_rate'], 1) . '٪', __('Win rate (won ÷ closed)', 'vereskala-login'), '', ''],
                    [VKL_Crm_Charts::plain_money($k['open_value']), sprintf(/* translators: %s: number of open deals */ __('Open pipeline (%s deals)', 'vereskala-login'), number_format_i18n($k['open_count'])), VKL_Crm_Deals_Page::url(), ''],
                    [number_format_i18n($k['activities']), __('Notes, calls and meetings', 'vereskala-login'), '', ''],
                    [number_format_i18n($mytasks['today'] + $mytasks['overdue']), $mytasks['overdue'] > 0 ? sprintf(/* translators: %s: overdue tasks */ __('My tasks today (%s overdue)', 'vereskala-login'), number_format_i18n($mytasks['overdue'])) : __('My tasks today', 'vereskala-login'), VKL_Crm_Task_View::url(), $mytasks['overdue'] > 0 ? 'is-alert' : ''],
                ];
                foreach ($tiles as [$value, $text, $url, $class]) :
                    $tag = '' !== $url ? 'a' : 'div';
                    ?>
                    <<?php echo $tag; // phpcs:ignore ?> class="vkl-crm-kpi <?php echo esc_attr($class); ?>"<?php echo '' !== $url ? ' href="' . esc_url($url) . '"' : ''; ?>>
                        <strong><?php echo esc_html($value); ?></strong><span><?php echo esc_html($text); ?></span>
                    </<?php echo $tag; // phpcs:ignore ?>>
                <?php endforeach; ?>
            </div>

            <div class="vkl-crm-dash-grid">
                <section class="vkl-crm-card">
                    <h2><?php echo esc_html(sprintf(/* translators: %s: pipeline name */ __('Open deals by stage — %s', 'vereskala-login'), (string) ($pipe['name'] ?? ''))); ?></h2>
                    <?php
                    $rows = array_map(static fn(array $s): array => [
                        'label'   => $s['name'],
                        'value'   => $s['value'] > 0 ? $s['value'] : (float) $s['count'],
                        'display' => sprintf('%s · %s', number_format_i18n($s['count']), VKL_Crm_Charts::plain_money($s['value'])),
                        'swatch'  => '' !== $s['color'] ? $s['color'] : '#8c8f94',
                        'url'     => VKL_Crm_Deals_Page::url(['pipeline' => (int) ($pipe['id'] ?? 0)]),
                    ], $funnel);
                    VKL_Crm_Charts::bars($rows, __('Open deals by stage', 'vereskala-login'), __('No open deals.', 'vereskala-login'));
                    ?>
                    <p class="description"><?php echo esc_html(sprintf(/* translators: %s: weighted value */ __('Weighted by stage probability: %s', 'vereskala-login'), VKL_Crm_Charts::plain_money($k['weighted']))); ?></p>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('New contacts per week', 'vereskala-login'); ?></h2>
                    <?php VKL_Crm_Charts::line(array_map(static fn(array $w): array => ['label' => $w['label'], 'value' => (float) $w['contacts'], 'display' => number_format_i18n($w['contacts'])], $weekly), __('New contacts per week', 'vereskala-login'), [VKL_Crm_Charts::class, 'compact']); ?>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Won value per week', 'vereskala-login'); ?></h2>
                    <?php VKL_Crm_Charts::line(array_map(static fn(array $w): array => ['label' => $w['label'], 'value' => $w['won_value'], 'display' => VKL_Crm_Charts::plain_money($w['won_value']) . ' (' . number_format_i18n($w['won']) . ')'], $weekly), __('Won value per week', 'vereskala-login'), [VKL_Crm_Charts::class, 'compact']); ?>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('New contacts by source', 'vereskala-login'); ?></h2>
                    <?php
                    VKL_Crm_Charts::bars(array_map(static fn(array $s): array => [
                        'label'   => VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), $s['source'] ?: '—'),
                        'value'   => (float) $s['contacts'],
                        'display' => number_format_i18n($s['contacts']),
                        'url'     => VKL_Crm_Ui::contacts_url(['source' => $s['source']]),
                    ], array_slice(array_filter($this->reports->sources($scope, $from, $to), static fn(array $s): bool => $s['contacts'] > 0), 0, 8)), __('New contacts by source', 'vereskala-login'));
                    ?>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Why deals were lost', 'vereskala-login'); ?></h2>
                    <?php
                    VKL_Crm_Charts::bars(array_map(static fn(array $r): array => [
                        'label'   => '' !== $r['reason'] ? $r['reason'] : __('No reason given', 'vereskala-login'),
                        'value'   => (float) $r['count'],
                        'display' => number_format_i18n($r['count']),
                    ], array_slice($this->reports->lost_reasons($scope, $from, $to), 0, 8)), __('Why deals were lost', 'vereskala-login'), __('No deals were lost in this period.', 'vereskala-login'));
                    ?>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('My tasks for today', 'vereskala-login'); ?></h2>
                    <?php
                    $mine = $this->tasks->search(['view' => 'overdue', 'assignee_id' => (string) get_current_user_id()], ['1=1', []], $ds, $de, 1, 10)['rows'];
                    $mine = array_merge($mine, $this->tasks->search(['view' => 'today', 'assignee_id' => (string) get_current_user_id()], ['1=1', []], $ds, $de, 1, 10)['rows']);
                    if ([] === $mine) {
                        echo '<p class="vkl-crm-empty">' . esc_html__('Nothing due today. 🎉', 'vereskala-login') . '</p>';
                    } else {
                        echo '<ul class="vkl-crm-tasks is-compact">';
                        foreach (array_slice($mine, 0, 10) as $t) {
                            $this->task_view->row($t, admin_url('admin.php?page=' . self::PAGE));
                        }
                        echo '</ul>';
                    }
                    ?>
                    <p><a href="<?php echo esc_url(VKL_Crm_Task_View::url()); ?>"><?php esc_html_e('All tasks', 'vereskala-login'); ?> ←</a></p>
                </section>

                <section class="vkl-crm-card vkl-crm-span-all">
                    <h2><?php esc_html_e('Latest activity', 'vereskala-login'); ?></h2>
                    <?php $this->feed($scope); ?>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * @param array{0: string, 1: array<int, int>} $scope
     */
    private function feed(array $scope): void
    {
        $rows = $this->reports->recent($scope, 12);

        if ([] === $rows) {
            echo '<p class="vkl-crm-empty">' . esc_html__('Nothing here yet.', 'vereskala-login') . '</p>';

            return;
        }

        $types = VKL_Crm_Ui::activity_types();
        echo '<ul class="vkl-crm-feed">';

        foreach ($rows as $a) {
            $text = (string) $a['subject'];

            if ('' === $text) {
                $text = wp_trim_words((string) $a['body'], 14, '…');
            }

            if ('' === $text) {
                $text = VKL_Crm_Ui::label($types, (string) $a['type']);
            }

            printf(
                '<li><span class="dashicons %1$s" aria-hidden="true"></span><a href="%2$s">%3$s</a><span class="vkl-crm-feed-text">%4$s</span><span class="vkl-crm-feed-meta">%5$s · %6$s</span></li>',
                esc_attr(VKL_Crm_Ui::activity_icon((string) $a['type'])),
                esc_url(VKL_Crm_Ui::contact_url((int) $a['contact_id'])),
                esc_html(VKL_Crm_Ui::contact_name($a + ['id' => $a['contact_id']])),
                esc_html($text),
                esc_html((int) $a['author_id'] > 0 ? VKL_Crm_Ui::user_name((int) $a['author_id']) : __('Automatic', 'vereskala-login')),
                esc_html(VKL_Crm_Ui::ago((string) $a['created_at']))
            );
        }

        echo '</ul>';
    }

    /**
     * "CRM today" widget on the main WordPress dashboard for CRM staff.
     */
    public function wp_widget(): void
    {
        if (!current_user_can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        wp_add_dashboard_widget('vkl_crm_today', __('CRM today', 'vereskala-login'), function (): void {
            [$ds, $de] = VKL_Crm_Task_Service::day_bounds();
            $c = $this->tasks->counts(['t.assignee_id = %d', [get_current_user_id()]], $ds, $de);
            $k = $this->reports->kpis($this->access->scope_sql('t.owner_id'), $ds, $de);
            printf(
                '<p><a href="%1$s"><strong>%2$s</strong></a> %3$s</p><p>%4$s</p><p><a class="button button-primary" href="%5$s">%6$s</a></p>',
                esc_url(VKL_Crm_Task_View::url()),
                esc_html(number_format_i18n($c['today'] + $c['overdue'])),
                esc_html($c['overdue'] > 0 ? sprintf(/* translators: %s: overdue */ __('tasks for today, %s overdue', 'vereskala-login'), number_format_i18n($c['overdue'])) : __('tasks for today', 'vereskala-login')),
                esc_html(sprintf(/* translators: %s: number */ __('New contacts today: %s', 'vereskala-login'), number_format_i18n($k['new_contacts']))),
                esc_url(admin_url('admin.php?page=' . self::PAGE)),
                esc_html__('Open the CRM dashboard', 'vereskala-login')
            );
        });
    }
}
