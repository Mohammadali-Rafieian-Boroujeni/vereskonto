<?php
/**
 * CRM → Reports (managers): team performance, lead sources, lost
 * reasons and weekly trend for a period — and the CSV exports for these
 * reports, the contact list and the deals.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.19.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Reports_Page
 */
final class VKL_Crm_Reports_Page
{
    public const PAGE = 'vkl-crm-reports';

    /** Hard cap on exported rows. */
    private const EXPORT_MAX = 20000;

    private VKL_Crm_Reports $reports;
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Pipelines $pipelines;
    private VKL_Crm_Tags $tags;
    private VKL_Crm_Access $access;

    public function __construct(VKL_Crm_Reports $reports, VKL_Crm_Contacts $contacts, VKL_Crm_Deals $deals, VKL_Crm_Pipelines $pipelines, VKL_Crm_Tags $tags, VKL_Crm_Access $access)
    {
        $this->reports   = $reports;
        $this->contacts  = $contacts;
        $this->deals     = $deals;
        $this->pipelines = $pipelines;
        $this->tags      = $tags;
        $this->access    = $access;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_vkl_crm_export', [$this, 'handle_export']);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('CRM reports', 'vereskala-login'),
            __('CRM reports', 'vereskala-login'),
            VKL_Crm_Access::CAP_REPORTS,
            self::PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [VKL_Crm_Ui::class, 'enqueue']);
        }
    }

    /**
     * Export link carrying the current period/filters.
     *
     * @param array<string, string> $extra
     */
    public static function export_url(string $type, array $extra = []): string
    {
        $keep = [];

        foreach (array_merge(['period', 'from', 'to'], VKL_Crm_Contacts_Table::FILTERS) as $k) {
            if (isset($_GET[$k]) && '' !== $_GET[$k]) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $keep[$k] = sanitize_text_field(wp_unslash((string) $_GET[$k])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }

        return wp_nonce_url(add_query_arg(array_merge($keep, $extra, ['action' => 'vkl_crm_export', 'type' => $type]), admin_url('admin-post.php')), 'vkl_crm_export');
    }

    private function export_button(string $type): void
    {
        if ($this->access->can(VKL_Crm_Access::CAP_EXPORT)) {
            printf('<a class="button button-small vkl-crm-export" href="%1$s"><span class="dashicons dashicons-download" aria-hidden="true"></span>%2$s</a>', esc_url(self::export_url($type)), esc_html__('CSV', 'vereskala-login'));
        }
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_REPORTS)) {
            return;
        }

        [$from, $to, $label, $key] = VKL_Crm_Dashboard_Page::period_from_request();
        $scope   = ['1=1', []];
        $agents  = $this->reports->agents($from, $to);
        $sources = $this->reports->sources($scope, $from, $to);
        $lost    = $this->reports->lost_reasons($scope, $from, $to);
        $weekly  = $this->reports->weekly($scope, 12);
        $k       = $this->reports->kpis($scope, $from, $to);
        ?>
        <div class="wrap vkl-crm">
            <div class="vkl-crm-head">
                <h1 class="wp-heading-inline"><?php esc_html_e('CRM reports', 'vereskala-login'); ?></h1>
                <span class="vkl-crm-scope"><?php echo esc_html($label); ?></span>
                <?php if ($this->access->can(VKL_Crm_Access::CAP_EXPORT)) : ?>
                    <a class="page-title-action" href="<?php echo esc_url(self::export_url('deals')); ?>"><?php esc_html_e('Export all deals (CSV)', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </div>
            <hr class="wp-header-end">
            <?php VKL_Crm_Dashboard_Page::period_form(self::PAGE, $key); ?>

            <div class="vkl-crm-kpis is-wide">
                <div class="vkl-crm-kpi"><strong><?php echo esc_html(number_format_i18n($k['new_contacts'])); ?></strong><span><?php esc_html_e('New contacts', 'vereskala-login'); ?></span></div>
                <div class="vkl-crm-kpi"><strong><?php echo esc_html(number_format_i18n($k['won'])); ?> / <?php echo esc_html(number_format_i18n($k['lost'])); ?></strong><span><?php esc_html_e('Deals won / lost', 'vereskala-login'); ?></span></div>
                <div class="vkl-crm-kpi"><strong><?php echo esc_html(VKL_Crm_Charts::plain_money($k['won_value'])); ?></strong><span><?php esc_html_e('Won value', 'vereskala-login'); ?></span></div>
                <div class="vkl-crm-kpi"><strong><?php echo esc_html(number_format_i18n($k['win_rate'], 1)); ?>٪</strong><span><?php esc_html_e('Win rate (won ÷ closed)', 'vereskala-login'); ?></span></div>
            </div>

            <section class="vkl-crm-card">
                <div class="vkl-crm-card-head"><h2><?php esc_html_e('Team performance', 'vereskala-login'); ?></h2><?php $this->export_button('agents'); ?></div>
                <div class="vkl-crm-scroll">
                <table class="widefat striped vkl-crm-report">
                    <thead><tr>
                        <th><?php esc_html_e('Staff member', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('New contacts', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Calls', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Meetings', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Notes', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Won', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Lost', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Win rate', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Won value', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Tasks done', 'vereskala-login'); ?></th>
                        <th class="num"><?php esc_html_e('Overdue now', 'vereskala-login'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($agents as $a) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(VKL_Crm_Ui::contacts_url(['owner_id' => (int) $a['user_id']])); ?>"><?php echo esc_html($a['name']); ?></a></td>
                            <?php foreach (['contacts', 'calls', 'meetings', 'notes', 'won', 'lost'] as $col) : ?>
                                <td class="num"><?php echo esc_html(number_format_i18n((int) $a[$col])); ?></td>
                            <?php endforeach; ?>
                            <td class="num"><?php echo $a['won'] + $a['lost'] > 0 ? esc_html(number_format_i18n($a['win_rate'], 1)) . '٪' : '—'; ?></td>
                            <td class="num"><?php echo esc_html(VKL_Crm_Charts::plain_money($a['won_value'])); ?></td>
                            <td class="num"><?php echo esc_html(number_format_i18n((int) $a['tasks_done'])); ?></td>
                            <td class="num<?php echo $a['tasks_overdue'] > 0 ? ' is-alert' : ''; ?>"><?php echo esc_html(number_format_i18n((int) $a['tasks_overdue'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </section>

            <div class="vkl-crm-dash-grid">
                <section class="vkl-crm-card">
                    <div class="vkl-crm-card-head"><h2><?php esc_html_e('Lead sources', 'vereskala-login'); ?></h2><?php $this->export_button('sources'); ?></div>
                    <table class="widefat striped vkl-crm-report">
                        <thead><tr>
                            <th><?php esc_html_e('Source', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('New contacts', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('Now customers', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('Conversion', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('Won value', 'vereskala-login'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if ([] === $sources) : ?>
                            <tr><td colspan="5"><?php esc_html_e('No data for this period yet.', 'vereskala-login'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($sources as $s) : ?>
                            <tr>
                                <td><?php echo esc_html(VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), $s['source'] ?: '—')); ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n($s['contacts'])); ?></td>
                                <td class="num"><?php echo esc_html(number_format_i18n($s['customers'])); ?></td>
                                <td class="num"><?php echo $s['contacts'] > 0 ? esc_html(number_format_i18n(100 * $s['customers'] / $s['contacts'], 1)) . '٪' : '—'; ?></td>
                                <td class="num"><?php echo esc_html(VKL_Crm_Charts::plain_money($s['won_value'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="vkl-crm-card">
                    <div class="vkl-crm-card-head"><h2><?php esc_html_e('Why deals were lost', 'vereskala-login'); ?></h2><?php $this->export_button('lost'); ?></div>
                    <?php
                    VKL_Crm_Charts::bars(array_map(static fn(array $r): array => [
                        'label'   => '' !== $r['reason'] ? $r['reason'] : __('No reason given', 'vereskala-login'),
                        'value'   => (float) $r['count'],
                        'display' => sprintf('%s · %s', number_format_i18n($r['count']), VKL_Crm_Charts::plain_money($r['value'])),
                    ], $lost), __('Why deals were lost', 'vereskala-login'), __('No deals were lost in this period.', 'vereskala-login'));
                    ?>
                </section>

                <section class="vkl-crm-card vkl-crm-span-all">
                    <div class="vkl-crm-card-head"><h2><?php esc_html_e('Last 12 weeks', 'vereskala-login'); ?></h2><?php $this->export_button('weekly'); ?></div>
                    <div class="vkl-crm-scroll">
                    <table class="widefat striped vkl-crm-report">
                        <thead><tr>
                            <th><?php esc_html_e('Week starting', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('New contacts', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('Deals won', 'vereskala-login'); ?></th>
                            <th class="num"><?php esc_html_e('Won value', 'vereskala-login'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($weekly) as $w) : ?>
                            <tr><td><?php echo esc_html($w['label']); ?></td><td class="num"><?php echo esc_html(number_format_i18n($w['contacts'])); ?></td><td class="num"><?php echo esc_html(number_format_i18n($w['won'])); ?></td><td class="num"><?php echo esc_html(VKL_Crm_Charts::plain_money($w['won_value'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    /* ------------------------------------------------------------ export */

    /**
     * Stream a CSV (UTF-8 with BOM so Excel shows Persian correctly).
     */
    public function handle_export(): void
    {
        check_admin_referer('vkl_crm_export');

        $type = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : '';

        if (!$this->access->can(VKL_Crm_Access::CAP_EXPORT)
            || (in_array($type, ['agents', 'sources', 'lost', 'weekly'], true) && !$this->access->can(VKL_Crm_Access::CAP_REPORTS))
        ) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'), '', ['response' => 403]);
        }

        [$from, $to] = VKL_Crm_Dashboard_Page::period_from_request();
        $rows        = $this->export_rows($type, $from, $to);

        if (null === $rows) {
            wp_die(esc_html__('Unknown export.', 'vereskala-login'), '', ['response' => 400]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="crm-' . $type . '-' . wp_date('Y-m-d') . '.csv"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row));
        }

        fclose($out);
        exit;
    }

    /**
     * Header row + data rows for an export type; null when unknown.
     *
     * @return array<int, array<int, mixed>>|null
     */
    public function export_rows(string $type, string $from, string $to): ?array
    {
        $scope = $this->access->scope_sql('t.owner_id');

        switch ($type) {
            case 'contacts':
                $rows = [[
                    __('ID', 'vereskala-login'), __('First name', 'vereskala-login'), __('Last name', 'vereskala-login'), __('Company', 'vereskala-login'),
                    __('Mobile', 'vereskala-login'), __('Email', 'vereskala-login'), __('City', 'vereskala-login'), __('Stage', 'vereskala-login'),
                    __('Source', 'vereskala-login'), __('Owner', 'vereskala-login'), __('Tags', 'vereskala-login'), __('Orders', 'vereskala-login'),
                    __('Total spent', 'vereskala-login'), __('Last order', 'vereskala-login'), __('Last activity', 'vereskala-login'), __('Added', 'vereskala-login'),
                ]];

                $filters = VKL_Crm_Contacts_Table::read_filters();

                foreach (array_chunk($this->contacts->search_ids($filters, $scope, self::EXPORT_MAX), 200) as $chunk) {
                    foreach ($chunk as $id) {
                        $c = $this->contacts->find($id);

                        if (null === $c) {
                            continue;
                        }

                        $rows[] = [
                            $c['id'], $c['first_name'], $c['last_name'], $c['company'], (string) $c['mobile'], $c['email'], $c['city'],
                            VKL_Crm_Ui::label(VKL_Crm_Ui::lifecycles(), (string) $c['lifecycle']),
                            VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), (string) $c['source']),
                            VKL_Crm_Ui::user_name((int) $c['owner_id']),
                            implode('، ', array_column($this->tags->for_contact($id), 'name')),
                            $c['orders_count'], $c['total_spent'],
                            empty($c['last_order_at']) ? '' : VKL_Jalali::format((string) $c['last_order_at'], 'short'),
                            empty($c['last_activity_at']) ? '' : VKL_Jalali::format((string) $c['last_activity_at'], 'short', true),
                            VKL_Jalali::format((string) $c['created_at'], 'short'),
                        ];
                    }
                }

                return $rows;

            case 'deals':
                $rows  = [[__('ID', 'vereskala-login'), __('Deal', 'vereskala-login'), __('Contact', 'vereskala-login'), __('Mobile', 'vereskala-login'), __('Pipeline', 'vereskala-login'), __('Stage', 'vereskala-login'), __('Status', 'vereskala-login'), __('Value', 'vereskala-login'), __('Owner', 'vereskala-login'), __('Expected close', 'vereskala-login'), __('Lost because', 'vereskala-login'), __('Order', 'vereskala-login'), __('Added', 'vereskala-login'), __('Closed', 'vereskala-login')]];
                $pipes = [];

                foreach ($this->pipelines->all() as $p) {
                    $pipes[(int) $p['id']] = $p['name'];
                }

                for ($page = 1; $page <= (int) ceil(self::EXPORT_MAX / 200); $page++) {
                    $res = $this->deals->search([], $scope, $page, 200, 'id', 'ASC');

                    foreach ($res['rows'] as $d) {
                        $c      = $this->contacts->find((int) $d['contact_id'], true);
                        $stage  = $this->pipelines->stage((int) $d['stage_id']);
                        $rows[] = [
                            $d['id'], $d['title'], null === $c ? '' : VKL_Crm_Ui::contact_name($c), (string) ($c['mobile'] ?? ''),
                            $pipes[(int) $d['pipeline_id']] ?? '', (string) ($stage['name'] ?? ''), $d['status'], $d['value'],
                            VKL_Crm_Ui::user_name((int) $d['owner_id']),
                            empty($d['expected_close']) ? '' : VKL_Jalali::format($d['expected_close'] . ' 12:00:00', 'short'),
                            $d['lost_reason'], (int) $d['order_id'] > 0 ? (int) $d['order_id'] : '',
                            VKL_Jalali::format((string) $d['created_at'], 'short'),
                            empty($d['closed_at']) ? '' : VKL_Jalali::format((string) $d['closed_at'], 'short'),
                        ];
                    }

                    if ($page >= $res['pages']) {
                        break;
                    }
                }

                return $rows;

            case 'agents':
                $rows = [[__('Staff member', 'vereskala-login'), __('New contacts', 'vereskala-login'), __('Calls', 'vereskala-login'), __('Meetings', 'vereskala-login'), __('Notes', 'vereskala-login'), __('Won', 'vereskala-login'), __('Lost', 'vereskala-login'), __('Win rate', 'vereskala-login'), __('Won value', 'vereskala-login'), __('Tasks done', 'vereskala-login'), __('Overdue now', 'vereskala-login')]];

                foreach ($this->reports->agents($from, $to) as $a) {
                    $rows[] = [$a['name'], $a['contacts'], $a['calls'], $a['meetings'], $a['notes'], $a['won'], $a['lost'], $a['win_rate'], $a['won_value'], $a['tasks_done'], $a['tasks_overdue']];
                }

                return $rows;

            case 'sources':
                $rows = [[__('Source', 'vereskala-login'), __('New contacts', 'vereskala-login'), __('Now customers', 'vereskala-login'), __('Won value', 'vereskala-login')]];

                foreach ($this->reports->sources(['1=1', []], $from, $to) as $s) {
                    $rows[] = [VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), $s['source'] ?: '—'), $s['contacts'], $s['customers'], $s['won_value']];
                }

                return $rows;

            case 'lost':
                $rows = [[__('Reason', 'vereskala-login'), __('Deals', 'vereskala-login'), __('Value', 'vereskala-login')]];

                foreach ($this->reports->lost_reasons(['1=1', []], $from, $to) as $r) {
                    $rows[] = [$r['reason'], $r['count'], $r['value']];
                }

                return $rows;

            case 'weekly':
                $rows = [[__('Week starting', 'vereskala-login'), __('New contacts', 'vereskala-login'), __('Deals won', 'vereskala-login'), __('Won value', 'vereskala-login')]];

                foreach ($this->reports->weekly(['1=1', []], 12) as $w) {
                    $rows[] = [$w['label'], $w['contacts'], $w['won'], $w['won_value']];
                }

                return $rows;
        }

        return null;
    }

    /**
     * A cell Excel will not run as a formula (CSV injection): text that
     * starts with = + - @ or a control character gets a leading quote.
     *
     * @param mixed $v
     */
    public static function cell($v): string
    {
        $v = (string) $v;

        if ('' !== $v && !is_numeric($v) && 1 === preg_match('/^[=+\-@\t\r]/', $v)) {
            return "'" . $v;
        }

        return $v;
    }
}
