<?php
/**
 * CRM → Deals: kanban board, list, add/edit form, deal page, and the
 * handlers behind them (admin-post forms plus two AJAX endpoints: moving
 * a card and looking up contacts).
 *
 * Every handler: nonce → capability → access to the specific deal or
 * contact (VKL_Crm_Access). All rules about stages and statuses live in
 * VKL_Crm_Deal_Service.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.17.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Deals_Page
 */
final class VKL_Crm_Deals_Page
{
    public const PAGE = 'vkl-crm-deals';

    private const FORM_STASH = 'vkl_crm_deal_form_';

    private VKL_Crm_Deals $deals;
    private VKL_Crm_Deal_Service $service;
    private VKL_Crm_Pipelines $pipelines;
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Access $access;
    private VKL_Crm_Timeline_View $view;
    private VKL_Crm_Task_View $task_view;

    public function __construct(
        VKL_Crm_Deals $deals,
        VKL_Crm_Deal_Service $service,
        VKL_Crm_Pipelines $pipelines,
        VKL_Crm_Contacts $contacts,
        VKL_Crm_Activities $activities,
        VKL_Crm_Access $access,
        VKL_Crm_Timeline_View $view,
        VKL_Crm_Task_View $task_view
    ) {
        $this->deals      = $deals;
        $this->service    = $service;
        $this->pipelines  = $pipelines;
        $this->contacts   = $contacts;
        $this->activities = $activities;
        $this->access     = $access;
        $this->view       = $view;
        $this->task_view  = $task_view;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);

        foreach (['save', 'move', 'close', 'delete'] as $action) {
            add_action('admin_post_vkl_crm_deal_' . $action, [$this, 'handle_' . $action]);
        }

        add_action('wp_ajax_vkl_crm_deal_move', [$this, 'ajax_move']);
        add_action('wp_ajax_vkl_crm_contact_lookup', [$this, 'ajax_lookup']);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Deals', 'vereskala-login'),
            __('Deals', 'vereskala-login'),
            VKL_Crm_Access::CAP_ACCESS,
            self::PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [VKL_Crm_Ui::class, 'enqueue']);
        }
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    public static function deal_url(int $id, array $args = []): string
    {
        return self::url(array_merge(['deal' => $id], $args));
    }

    /**
     * Configured reasons for losing a deal (one per line in settings).
     *
     * @return string[]
     */
    public static function lost_reasons(): array
    {
        $raw = (string) vkl('config')->get('crm_lost_reasons', '');

        if ('' === trim($raw)) {
            $raw = implode("\n", [
                __('Price too high', 'vereskala-login'),
                __('Bought from a competitor', 'vereskala-login'),
                __('No response', 'vereskala-login'),
                __('No longer needed', 'vereskala-login'),
                __('Out of stock', 'vereskala-login'),
            ]);
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        $id     = isset($_GET['deal']) ? absint($_GET['deal']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap vkl-crm">';

        if ('new' === $action) {
            $this->render_form(null);
        } elseif ($id > 0) {
            $deal = $this->deals->find($id);

            if (null === $deal || !$this->access->can_view($deal)) {
                echo '<h1>' . esc_html__('Deals', 'vereskala-login') . '</h1>';
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(null === $deal ? __('That deal no longer exists.', 'vereskala-login') : __('You are not allowed to see this deal.', 'vereskala-login')));
            } elseif ('edit' === $action && $this->access->can_edit($deal)) {
                $this->render_form($deal);
            } else {
                $this->render_deal($deal);
            }
        } else {
            $this->render_board();
        }

        echo '</div>';
    }

    /* ================================================================== *
     *  Board and list
     * ================================================================== */

    private function current_pipeline(): array
    {
        $pid  = isset($_GET['pipeline']) ? absint($_GET['pipeline']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $pipe = $pid > 0 ? $this->pipelines->find($pid) : null;

        return $pipe ?? (array) $this->pipelines->default_pipeline();
    }

    private function render_board(): void
    {
        $pipe   = $this->current_pipeline();
        $pid    = (int) ($pipe['id'] ?? 0);
        $mode   = isset($_GET['view']) && 'list' === $_GET['view'] ? 'list' : 'board'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $q      = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $owner  = isset($_GET['owner_id']) ? sanitize_text_field(wp_unslash((string) $_GET['owner_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $scope  = $this->access->scope_sql('t.owner_id');
        $totals = $this->deals->open_totals($scope, $pid);
        ?>
        <div class="vkl-crm-head">
            <h1 class="wp-heading-inline"><?php esc_html_e('Deals', 'vereskala-login'); ?></h1>
            <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) : ?>
                <a href="<?php echo esc_url(self::url(['action' => 'new', 'pipeline' => $pid])); ?>" class="page-title-action"><?php esc_html_e('Add deal', 'vereskala-login'); ?></a>
            <?php endif; ?>
        </div>
        <hr class="wp-header-end">
        <?php VKL_Crm_Ui::notices(); ?>

        <div class="vkl-crm-kpis">
            <div><strong><?php echo esc_html(number_format_i18n($totals['count'])); ?></strong><span><?php esc_html_e('Open deals', 'vereskala-login'); ?></span></div>
            <div><strong><?php echo VKL_Crm_Ui::money($totals['value']); // phpcs:ignore ?></strong><span><?php esc_html_e('Pipeline value', 'vereskala-login'); ?></span></div>
            <div><strong><?php echo VKL_Crm_Ui::money($totals['weighted']); // phpcs:ignore ?></strong><span><?php esc_html_e('Weighted by stage probability', 'vereskala-login'); ?></span></div>
        </div>

        <form method="get" class="vkl-crm-board-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <input type="hidden" name="view" value="<?php echo esc_attr($mode); ?>">
            <?php
            $pipes = $this->pipelines->all();
            if (count($pipes) > 1) {
                $opts = [];
                foreach ($pipes as $p) {
                    $opts[(string) $p['id']] = $p['name'];
                }
                echo '<label class="screen-reader-text" for="vkl-crm-pipe">' . esc_html__('Pipeline', 'vereskala-login') . '</label>';
                echo VKL_Crm_Ui::select('pipeline', $opts, (string) $pid, '', 'vkl-crm-pipe'); // phpcs:ignore
            } else {
                echo '<input type="hidden" name="pipeline" value="' . esc_attr((string) $pid) . '">';
            }
            ?>
            <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Search deal titles…', 'vereskala-login'); ?>">
            <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) :
                $owners = ['' => __('Everyone', 'vereskala-login'), (string) get_current_user_id() => __('My deals', 'vereskala-login'), '-1' => __('Unassigned', 'vereskala-login')];
                foreach ($this->access->assignable_users() as $uid => $name) {
                    $owners[(string) $uid] ??= $name;
                }
                echo VKL_Crm_Ui::select('owner_id', $owners, $owner); // phpcs:ignore
            endif; ?>
            <button class="button"><?php esc_html_e('Filter', 'vereskala-login'); ?></button>
            <span class="vkl-crm-viewswitch">
                <a class="<?php echo 'board' === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::url(['pipeline' => $pid, 'q' => $q ?: null, 'owner_id' => '' !== $owner ? $owner : null])); ?>"><span class="dashicons dashicons-columns" aria-hidden="true"></span><?php esc_html_e('Board', 'vereskala-login'); ?></a>
                <a class="<?php echo 'list' === $mode ? 'is-active' : ''; ?>" href="<?php echo esc_url(self::url(['pipeline' => $pid, 'view' => 'list', 'q' => $q ?: null, 'owner_id' => '' !== $owner ? $owner : null])); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e('List', 'vereskala-login'); ?></a>
            </span>
        </form>
        <?php

        if ($pid <= 0) {
            echo '<p>' . esc_html__('No pipeline yet. Create one in CRM settings.', 'vereskala-login') . '</p>';

            return;
        }

        $filters = ['pipeline_id' => $pid, 'q' => $q];

        if ('' !== $owner) {
            $filters['owner_id'] = $owner;
        }

        'list' === $mode ? $this->render_list($filters, $scope) : $this->render_columns($pid, $filters, $scope);
    }

    /**
     * @param array<string, mixed>                 $filters
     * @param array{0: string, 1: array<int, int>} $scope
     */
    private function render_columns(int $pid, array $filters, array $scope): void
    {
        $stages = $this->pipelines->stages($pid);

        // Board data honours the search/owner filters through search().
        $rows    = $this->deals->search($filters, $scope, 1, VKL_Crm_Repository::MAX_PER_PAGE, 'position', 'ASC')['rows'];
        $columns = [];

        foreach ($rows as $row) {
            $columns[(int) $row['stage_id']][] = $row;
        }

        $names = [];

        foreach (array_unique(array_column($rows, 'contact_id')) as $cid) {
            $c            = $this->contacts->find((int) $cid, true);
            $names[$cid] = null === $c ? '—' : VKL_Crm_Ui::contact_name($c);
        }

        $can_move = $this->access->can(VKL_Crm_Access::CAP_EDIT_OWN);
        ?>
        <div class="vkl-crm-board" data-vkl-board data-nonce="<?php echo esc_attr(wp_create_nonce('vkl_crm_deal_move')); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-money="<?php echo esc_attr((string) wp_json_encode(self::money_format())); ?>">
            <?php foreach ($stages as $stage) :
                $cards = $columns[(int) $stage['id']] ?? [];
                $sum   = array_sum(array_map(static fn(array $d): float => (float) $d['value'], $cards));
                ?>
                <section class="vkl-crm-col is-<?php echo esc_attr($stage['kind']); ?>" data-stage="<?php echo esc_attr((string) $stage['id']); ?>" data-kind="<?php echo esc_attr($stage['kind']); ?>" style="--stage:<?php echo esc_attr('' !== $stage['color'] ? $stage['color'] : '#8c8f94'); ?>">
                    <header>
                        <h2><?php echo esc_html($stage['name']); ?></h2>
                        <span class="vkl-crm-col-meta"><span data-count><?php echo esc_html(number_format_i18n(count($cards))); ?></span> · <span data-sum><?php echo VKL_Crm_Ui::money($sum); // phpcs:ignore ?></span><?php if ('open' === $stage['kind']) : ?> · <?php echo esc_html(number_format_i18n((int) $stage['probability'])); ?>٪<?php endif; ?></span>
                    </header>
                    <ol class="vkl-crm-cards" data-dropzone>
                        <?php foreach ($cards as $d) {
                            $this->card($d, $names[$d['contact_id']] ?? '—', $stages, $can_move && $this->access->can_edit($d));
                        } ?>
                    </ol>
                </section>
            <?php endforeach; ?>
        </div>
        <?php $this->lost_dialog(); ?>
        <?php
    }

    /**
     * @param array<string, mixed>             $d
     * @param array<int, array<string, mixed>> $stages
     */
    private function card(array $d, string $contact_name, array $stages, bool $movable): void
    {
        $overdue = 'open' === $d['status'] && !empty($d['expected_close']) && $d['expected_close'] < wp_date('Y-m-d');
        ?>
        <li class="vkl-crm-card-deal" data-deal="<?php echo esc_attr((string) $d['id']); ?>" data-value="<?php echo esc_attr((string) (float) $d['value']); ?>" <?php echo $movable ? 'draggable="true"' : ''; ?>>
            <a class="vkl-crm-deal-title" href="<?php echo esc_url(self::deal_url((int) $d['id'])); ?>"><?php echo esc_html((string) $d['title']); ?></a>
            <div class="vkl-crm-deal-contact"><?php echo esc_html($contact_name); ?></div>
            <div class="vkl-crm-deal-foot">
                <strong><?php echo (float) $d['value'] > 0 ? VKL_Crm_Ui::money((float) $d['value']) : '—'; // phpcs:ignore ?></strong>
                <?php if (!empty($d['expected_close'])) : ?>
                    <span class="<?php echo $overdue ? 'is-overdue' : ''; ?>" title="<?php esc_attr_e('Expected close', 'vereskala-login'); ?>"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><?php echo esc_html(VKL_Jalali::format($d['expected_close'] . ' 12:00:00', 'short')); ?></span>
                <?php endif; ?>
                <span class="vkl-crm-avatar is-small" title="<?php echo esc_attr(VKL_Crm_Ui::user_name((int) $d['owner_id'])); ?>"><?php echo esc_html(function_exists('mb_substr') ? mb_substr(VKL_Crm_Ui::user_name((int) $d['owner_id']), 0, 1) : ''); ?></span>
            </div>
            <?php if ($movable) : ?>
                <details class="vkl-crm-move">
                    <summary><?php esc_html_e('Move to…', 'vereskala-login'); ?></summary>
                    <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>">
                        <input type="hidden" name="action" value="vkl_crm_deal_move">
                        <input type="hidden" name="deal" value="<?php echo esc_attr((string) $d['id']); ?>">
                        <?php wp_nonce_field('vkl_crm_deal_move_' . (int) $d['id']); ?>
                        <select name="stage_id" aria-label="<?php esc_attr_e('Stage', 'vereskala-login'); ?>">
                            <?php foreach ($stages as $s) : ?>
                                <option value="<?php echo esc_attr((string) $s['id']); ?>" <?php selected((int) $s['id'], (int) $d['stage_id']); ?>><?php echo esc_html($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="lost_reason" placeholder="<?php esc_attr_e('Reason (if lost)', 'vereskala-login'); ?>">
                        <button class="button button-small"><?php esc_html_e('Move', 'vereskala-login'); ?></button>
                    </form>
                </details>
            <?php endif; ?>
        </li>
        <?php
    }

    /**
     * WooCommerce price settings, so the board can re-total a column in
     * the browser exactly as wc_price() would print it.
     *
     * @return array{symbol: string, format: string, decimals: int, dec: string, thousand: string}
     */
    private static function money_format(): array
    {
        if (!function_exists('get_woocommerce_currency_symbol')) {
            return ['symbol' => '', 'format' => '%1$s%2$s', 'decimals' => 0, 'dec' => '.', 'thousand' => ','];
        }

        return [
            'symbol'   => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES),
            'format'   => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES),
            'decimals' => wc_get_price_decimals(),
            'dec'      => wc_get_price_decimal_separator(),
            'thousand' => wc_get_price_thousand_separator(),
        ];
    }

    /**
     * Dialog asking why a deal was lost (used by the board's drag & drop).
     */
    private function lost_dialog(): void
    {
        ?>
        <dialog class="vkl-crm-dialog" data-vkl-lost>
            <form method="dialog">
                <h2><?php esc_html_e('Why was this deal lost?', 'vereskala-login'); ?></h2>
                <?php foreach (self::lost_reasons() as $i => $reason) : ?>
                    <label class="vkl-crm-radio"><input type="radio" name="reason" value="<?php echo esc_attr($reason); ?>" <?php checked(0, $i); ?>> <?php echo esc_html($reason); ?></label>
                <?php endforeach; ?>
                <label class="vkl-crm-radio"><input type="radio" name="reason" value="__other"> <?php esc_html_e('Other:', 'vereskala-login'); ?> <input type="text" name="other" maxlength="190"></label>
                <p class="vkl-crm-dialog-actions">
                    <button class="button button-primary" value="ok"><?php esc_html_e('Mark as lost', 'vereskala-login'); ?></button>
                    <button class="button" value="cancel"><?php esc_html_e('Cancel', 'vereskala-login'); ?></button>
                </p>
            </form>
        </dialog>
        <?php
    }

    /**
     * @param array<string, mixed>                 $filters
     * @param array{0: string, 1: array<int, int>} $scope
     */
    private function render_list(array $filters, array $scope): void
    {
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (in_array($status, VKL_Crm_Deals::STATUSES, true)) {
            $filters['status'] = $status;
        }

        $res    = $this->deals->search($filters, $scope, $paged, 30, 'created_at', 'DESC');
        $labels = ['' => __('All', 'vereskala-login'), 'open' => __('Open', 'vereskala-login'), 'won' => __('Won', 'vereskala-login'), 'lost' => __('Lost', 'vereskala-login')];
        $base   = remove_query_arg(['status', 'paged']);

        echo '<ul class="subsubsub">';
        $links = [];
        foreach ($labels as $key => $label) {
            $links[] = sprintf('<li><a href="%1$s"%2$s>%3$s</a></li>', esc_url('' === $key ? $base : add_query_arg('status', $key, $base)), $status === $key ? ' class="current"' : '', esc_html($label));
        }
        echo implode(' | ', $links) . '</ul>'; // phpcs:ignore -- built from escaped parts.
        ?>
        <table class="widefat striped vkl-crm-deal-table">
            <thead><tr>
                <th><?php esc_html_e('Deal', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Contact', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Stage', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Value', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Owner', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Expected close', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Added', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ([] === $res['rows']) : ?>
                <tr><td colspan="7"><?php esc_html_e('No deals match.', 'vereskala-login'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($res['rows'] as $d) :
                $stage = $this->pipelines->stage((int) $d['stage_id']);
                $c     = $this->contacts->find((int) $d['contact_id'], true);
                ?>
                <tr>
                    <td><a class="row-title" href="<?php echo esc_url(self::deal_url((int) $d['id'])); ?>"><?php echo esc_html((string) $d['title']); ?></a></td>
                    <td><?php echo null === $c ? '—' : '<a href="' . esc_url(VKL_Crm_Ui::contact_url((int) $c['id'])) . '">' . esc_html(VKL_Crm_Ui::contact_name($c)) . '</a>'; ?></td>
                    <td><?php echo $this->stage_pill($stage, (string) $d['status']); // phpcs:ignore ?></td>
                    <td><?php echo VKL_Crm_Ui::money((float) $d['value']); // phpcs:ignore ?></td>
                    <td><?php echo esc_html(VKL_Crm_Ui::user_name((int) $d['owner_id'])); ?></td>
                    <td><?php echo empty($d['expected_close']) ? '—' : esc_html(VKL_Jalali::format($d['expected_close'] . ' 12:00:00', 'short')); ?></td>
                    <td><?php echo VKL_Crm_Ui::date((string) $d['created_at']); // phpcs:ignore ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($res['pages'] > 1) : ?>
            <p class="vkl-crm-pages">
                <?php for ($p = 1; $p <= $res['pages']; $p++) : ?>
                    <a class="button<?php echo $p === $res['page'] ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged', $p)); ?>"><?php echo esc_html(number_format_i18n($p)); ?></a>
                <?php endfor; ?>
            </p>
        <?php endif;
    }

    /**
     * @param array<string, mixed>|null $stage
     */
    private function stage_pill(?array $stage, string $status): string
    {
        return sprintf(
            '<span class="vkl-crm-pill is-deal-%1$s" style="--stage:%2$s">%3$s</span>',
            esc_attr($status),
            esc_attr((string) ($stage['color'] ?? '#8c8f94') ?: '#8c8f94'),
            esc_html((string) ($stage['name'] ?? '—'))
        );
    }

    /* ================================================================== *
     *  Form
     * ================================================================== */

    /**
     * @param array<string, mixed>|null $deal
     */
    private function render_form(?array $deal): void
    {
        $id         = null === $deal ? 0 : (int) $deal['id'];
        $contact_id = (int) ($deal['contact_id'] ?? (isset($_GET['contact']) ? absint($_GET['contact']) : 0)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $stash      = get_transient(self::FORM_STASH . get_current_user_id());
        $error      = '';

        if (is_array($stash) && (int) ($stash['id'] ?? -1) === $id && isset($_GET['vkl_err'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $deal       = array_merge((array) $deal, (array) $stash['input']);
            $contact_id = (int) ($deal['contact_id'] ?? $contact_id);
            $error      = (string) $stash['error'];
            delete_transient(self::FORM_STASH . get_current_user_id());
        }

        $contact = $contact_id > 0 ? $this->contacts->find($contact_id) : null;

        if (null !== $contact && 0 === $id && !$this->access->can_edit($contact)) {
            echo '<p>' . esc_html__('You can only add deals for contacts assigned to you.', 'vereskala-login') . '</p>';

            return;
        }

        $v        = static fn(string $k): string => (string) ($deal[$k] ?? '');
        $pipe     = null !== $deal && !empty($deal['pipeline_id']) ? $this->pipelines->find((int) $deal['pipeline_id']) : $this->current_pipeline();
        $pid      = (int) ($pipe['id'] ?? 0);
        $back     = $id > 0 ? self::deal_url($id) : (null !== $contact ? VKL_Crm_Ui::contact_url((int) $contact['id']) : self::url());
        $close    = $v('expected_close');
        $close    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $close) ? VKL_Jalali::format($close . ' 12:00:00', 'short') : $close;
        $order    = (int) ($deal['order_id'] ?? 0) > 0 ? (string) (int) $deal['order_id'] : $v('order');
        ?>
        <a class="vkl-crm-back" href="<?php echo esc_url($back); ?>">→ <?php esc_html_e('Back', 'vereskala-login'); ?></a>
        <h1><?php echo esc_html($id > 0 ? sprintf(/* translators: %s: deal title */ __('Edit deal: %s', 'vereskala-login'), $v('title')) : __('Add deal', 'vereskala-login')); ?></h1>
        <hr class="wp-header-end">
        <?php if ('' !== $error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-form" novalidate>
            <input type="hidden" name="action" value="vkl_crm_deal_save">
            <input type="hidden" name="deal" value="<?php echo esc_attr((string) $id); ?>">
            <?php wp_nonce_field('vkl_crm_deal_save_' . $id); ?>

            <div class="vkl-crm-card">
                <div class="vkl-crm-fields">
                    <label class="vkl-crm-span2"><span><?php esc_html_e('Title', 'vereskala-login'); ?></span><input type="text" name="title" maxlength="190" required value="<?php echo esc_attr($v('title')); ?>" placeholder="<?php esc_attr_e('e.g. 200 winter coats for Aria store', 'vereskala-login'); ?>"></label>
                    <label class="vkl-crm-span2"><span><?php esc_html_e('Contact', 'vereskala-login'); ?></span>
                        <?php if ($id > 0 || null !== $contact) : ?>
                            <input type="hidden" name="contact_id" value="<?php echo esc_attr((string) $contact_id); ?>">
                            <input type="text" readonly value="<?php echo esc_attr(null !== $contact ? VKL_Crm_Ui::contact_name($contact) : ''); ?>">
                        <?php else : ?>
                            <span class="vkl-crm-lookup" data-vkl-lookup data-nonce="<?php echo esc_attr(wp_create_nonce('vkl_crm_lookup')); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
                                <input type="hidden" name="contact_id" value="">
                                <input type="search" autocomplete="off" placeholder="<?php esc_attr_e('Type a name or mobile…', 'vereskala-login'); ?>" aria-autocomplete="list">
                                <ul class="vkl-crm-lookup-list" role="listbox" hidden></ul>
                            </span>
                        <?php endif; ?>
                    </label>
                    <label><span><?php esc_html_e('Value', 'vereskala-login'); ?></span><input type="text" name="value" inputmode="decimal" dir="ltr" value="<?php echo esc_attr('' !== $v('value') && 0.0 !== (float) $v('value') ? (string) (float) $v('value') : ''); ?>"></label>
                    <label><span><?php esc_html_e('Stage', 'vereskala-login'); ?></span>
                        <input type="hidden" name="pipeline_id" value="<?php echo esc_attr((string) $pid); ?>">
                        <select name="stage_id">
                            <?php foreach ($this->pipelines->stages($pid) as $s) : ?>
                                <option value="<?php echo esc_attr((string) $s['id']); ?>" <?php selected((int) $s['id'], (int) ($deal['stage_id'] ?? 0)); ?>><?php echo esc_html($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><span><?php esc_html_e('Expected close', 'vereskala-login'); ?></span><input type="text" name="expected_close" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($close); ?>" placeholder="1405/10/01"></label>
                    <label><span><?php esc_html_e('Owner', 'vereskala-login'); ?></span>
                        <?php
                        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
                            echo VKL_Crm_Ui::owner_select('owner_id', (int) ($deal['owner_id'] ?? ($contact['owner_id'] ?? get_current_user_id()))); // phpcs:ignore
                        } else {
                            echo '<input type="text" readonly value="' . esc_attr(VKL_Crm_Ui::user_name($id > 0 ? (int) $deal['owner_id'] : get_current_user_id())) . '">';
                        }
                        ?>
                    </label>
                    <label><span><?php esc_html_e('Source', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('source', VKL_Crm_Ui::sources(), '' !== $v('source') ? $v('source') : (string) ($contact['source'] ?? 'manual')); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Linked order number', 'vereskala-login'); ?></span><input type="text" name="order" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($order); ?>" placeholder="#"></label>
                </div>
                <p class="description"><?php esc_html_e('When the linked order is paid, the deal is marked as won automatically.', 'vereskala-login'); ?></p>
            </div>

            <p class="submit">
                <button class="button button-primary button-large"><?php echo esc_html($id > 0 ? __('Save changes', 'vereskala-login') : __('Add deal', 'vereskala-login')); ?></button>
                <a class="button button-large" href="<?php echo esc_url($back); ?>"><?php esc_html_e('Cancel', 'vereskala-login'); ?></a>
            </p>
        </form>
        <?php
    }

    public function handle_save(): void
    {
        $id = isset($_POST['deal']) ? absint($_POST['deal']) : 0;
        check_admin_referer('vkl_crm_deal_save_' . $id);

        $me       = get_current_user_id();
        $existing = $id > 0 ? $this->deals->find($id) : null;

        if ($id > 0 && (null === $existing || !$this->access->can_edit($existing))) {
            VKL_Crm_Ui::redirect(self::url(), null === $existing ? 'missing' : 'denied');
        }

        $input = [];

        foreach (['title', 'value', 'expected_close', 'source', 'order', 'pipeline_id', 'stage_id', 'lost_reason'] as $field) {
            if (isset($_POST[$field])) {
                $input[$field] = wp_unslash((string) $_POST[$field]);
            }
        }

        if (0 === $id) {
            $input['contact_id'] = isset($_POST['contact_id']) ? absint($_POST['contact_id']) : 0;
            $contact             = $this->contacts->find((int) $input['contact_id']);

            if (null !== $contact && !$this->access->can_edit($contact)) {
                VKL_Crm_Ui::redirect(self::url(), 'denied');
            }
        }

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && isset($_POST['owner_id'])) {
            $owner = absint($_POST['owner_id']);

            if ($this->access->can_assign((array) $existing, $owner)) {
                $input['owner_id'] = $owner;
            }
        } elseif (0 === $id) {
            $input['owner_id'] = $me;
        }

        $result = 0 === $id ? $this->service->create($input, $me) : $this->service->update($id, $input, $me);

        if (is_wp_error($result)) {
            set_transient(self::FORM_STASH . $me, ['id' => $id, 'input' => $input, 'error' => $result->get_error_message()], 300);
            $form = 0 === $id ? self::url(['action' => 'new']) : self::deal_url($id, ['action' => 'edit']);
            wp_safe_redirect(add_query_arg('vkl_err', '1', $form));
            exit;
        }

        VKL_Crm_Ui::redirect(self::deal_url(0 === $id ? (int) $result : $id), 0 === $id ? 'deal_created' : 'deal_saved');
    }

    /* ================================================================== *
     *  Deal page
     * ================================================================== */

    /**
     * @param array<string, mixed> $d
     */
    private function render_deal(array $d): void
    {
        $id       = (int) $d['id'];
        $contact  = $this->contacts->find((int) $d['contact_id'], true);
        $stages   = $this->pipelines->stages((int) $d['pipeline_id']);
        $current  = $this->pipelines->stage((int) $d['stage_id']);
        $can_edit = $this->access->can_edit($d);
        $back     = self::deal_url($id);
        $order    = (int) $d['order_id'] > 0 && function_exists('wc_get_order') ? wc_get_order((int) $d['order_id']) : null;
        ?>
        <a class="vkl-crm-back" href="<?php echo esc_url(self::url(['pipeline' => (int) $d['pipeline_id']])); ?>">→ <?php esc_html_e('All deals', 'vereskala-login'); ?></a>
        <hr class="wp-header-end">
        <?php VKL_Crm_Ui::notices(); ?>

        <header class="vkl-crm-profile-head">
            <span class="vkl-crm-avatar is-large is-deal" aria-hidden="true"><span class="dashicons dashicons-portfolio"></span></span>
            <div class="vkl-crm-who">
                <h1><?php echo esc_html((string) $d['title']); ?></h1>
                <div class="vkl-crm-meta-line">
                    <?php echo $this->stage_pill($current, (string) $d['status']); // phpcs:ignore ?>
                    <strong><?php echo VKL_Crm_Ui::money((float) $d['value']); // phpcs:ignore ?></strong>
                    <?php if (null !== $contact) : ?>
                        <a href="<?php echo esc_url(VKL_Crm_Ui::contact_url((int) $contact['id'])); ?>"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo esc_html(VKL_Crm_Ui::contact_name($contact)); ?></a>
                    <?php endif; ?>
                    <span class="vkl-crm-owner"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><?php echo esc_html(VKL_Crm_Ui::user_name((int) $d['owner_id'])); ?></span>
                </div>
            </div>
            <div class="vkl-crm-actions">
                <?php if ($can_edit && 'open' === $d['status']) : ?>
                    <a class="button vkl-crm-won" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'vkl_crm_deal_close', 'result' => 'won', 'id' => $id], VKL_Crm_Ui::post_url()), 'vkl_crm_won_' . $id)); ?>"><?php esc_html_e('Won', 'vereskala-login'); ?></a>
                    <a class="button vkl-crm-danger" href="#vkl-crm-lose"><?php esc_html_e('Lost', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <?php if ($can_edit) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(self::deal_url($id, ['action' => 'edit'])); ?>"><?php esc_html_e('Edit', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <?php if ($this->access->can_delete($d)) : ?>
                    <a class="button vkl-crm-danger" data-confirm="deal" href="<?php echo esc_url(VKL_Crm_Ui::action_url('delete', $id, 'vkl_crm_deal_')); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </div>
        </header>

        <nav class="vkl-crm-stagebar" aria-label="<?php esc_attr_e('Sales stage', 'vereskala-login'); ?>">
            <?php
            $reached = true;
            foreach ($stages as $s) :
                $is_current = (int) $s['id'] === (int) $d['stage_id'];
                $class      = 'is-' . $s['kind'] . ($is_current ? ' is-current' : '') . ($reached && !$is_current && 'open' === $s['kind'] ? ' is-done' : '');
                if ($is_current) {
                    $reached = false;
                }
                ?>
                <?php if ($can_edit && !$is_current && 'lost' !== $s['kind']) : ?>
                    <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="<?php echo esc_attr($class); ?>">
                        <input type="hidden" name="action" value="vkl_crm_deal_move">
                        <input type="hidden" name="deal" value="<?php echo esc_attr((string) $id); ?>">
                        <input type="hidden" name="stage_id" value="<?php echo esc_attr((string) $s['id']); ?>">
                        <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
                        <?php wp_nonce_field('vkl_crm_deal_move_' . $id); ?>
                        <button title="<?php echo esc_attr(sprintf(/* translators: %s: stage */ __('Move to %s', 'vereskala-login'), $s['name'])); ?>"><?php echo esc_html($s['name']); ?></button>
                    </form>
                <?php else : ?>
                    <span class="<?php echo esc_attr($class); ?>" <?php echo $is_current ? 'aria-current="step"' : ''; ?>><?php echo esc_html($s['name']); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="vkl-crm-profile">
            <aside class="vkl-crm-side">
                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Details', 'vereskala-login'); ?></h2>
                    <dl class="vkl-crm-dl">
                        <dt><?php esc_html_e('Value', 'vereskala-login'); ?></dt><dd><?php echo VKL_Crm_Ui::money((float) $d['value']); // phpcs:ignore ?></dd>
                        <dt><?php esc_html_e('Probability', 'vereskala-login'); ?></dt><dd><?php echo esc_html(number_format_i18n((int) ($current['probability'] ?? 0))); ?>٪</dd>
                        <?php if (!empty($d['expected_close'])) : ?><dt><?php esc_html_e('Expected close', 'vereskala-login'); ?></dt><dd><?php echo esc_html(VKL_Jalali::format($d['expected_close'] . ' 12:00:00')); ?></dd><?php endif; ?>
                        <dt><?php esc_html_e('Source', 'vereskala-login'); ?></dt><dd><?php echo esc_html(VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), (string) $d['source'])); ?></dd>
                        <dt><?php esc_html_e('Added', 'vereskala-login'); ?></dt><dd><?php echo VKL_Crm_Ui::date((string) $d['created_at']); // phpcs:ignore ?></dd>
                        <?php if (!empty($d['closed_at'])) : ?><dt><?php esc_html_e('Closed', 'vereskala-login'); ?></dt><dd><?php echo VKL_Crm_Ui::date((string) $d['closed_at']); // phpcs:ignore ?></dd><?php endif; ?>
                        <?php if ('lost' === $d['status'] && '' !== (string) $d['lost_reason']) : ?><dt><?php esc_html_e('Lost because', 'vereskala-login'); ?></dt><dd><?php echo esc_html((string) $d['lost_reason']); ?></dd><?php endif; ?>
                        <?php if ($order instanceof WC_Order) : ?>
                            <dt><?php esc_html_e('Order', 'vereskala-login'); ?></dt>
                            <dd><?php echo current_user_can('edit_shop_orders') ? '<a href="' . esc_url((string) $order->get_edit_order_url()) . '">#' . esc_html((string) $order->get_order_number()) . '</a>' : '#' . esc_html((string) $order->get_order_number()); ?> — <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></dd>
                        <?php endif; ?>
                    </dl>
                </section>

                <?php $this->task_view->card((int) $d['contact_id'], $id, $back, $can_edit); ?>

                <?php if ($can_edit && 'open' === $d['status']) : ?>
                    <section class="vkl-crm-card" id="vkl-crm-lose">
                        <h2><?php esc_html_e('Mark as lost', 'vereskala-login'); ?></h2>
                        <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>">
                            <input type="hidden" name="action" value="vkl_crm_deal_close">
                            <input type="hidden" name="id" value="<?php echo esc_attr((string) $id); ?>">
                            <input type="hidden" name="result" value="lost">
                            <?php wp_nonce_field('vkl_crm_lost_' . $id); ?>
                            <?php foreach (self::lost_reasons() as $i => $reason) : ?>
                                <label class="vkl-crm-radio"><input type="radio" name="lost_reason" value="<?php echo esc_attr($reason); ?>" <?php checked(0, $i); ?>> <?php echo esc_html($reason); ?></label>
                            <?php endforeach; ?>
                            <label class="vkl-crm-wide"><span><?php esc_html_e('Other reason', 'vereskala-login'); ?></span><input type="text" name="lost_other" maxlength="190"></label>
                            <p><button class="button vkl-crm-danger"><?php esc_html_e('Mark as lost', 'vereskala-login'); ?></button></p>
                        </form>
                    </section>
                <?php endif; ?>
            </aside>

            <main class="vkl-crm-main">
                <?php if ($can_edit && null !== $contact) : ?>
                    <?php $this->view->composer((int) $contact['id'], $back, $id); ?>
                <?php endif; ?>
                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Deal history', 'vereskala-login'); ?></h2>
                    <?php $this->view->timeline_list($this->activities->for_deal($id, 100), (array) $contact, $back); ?>
                </section>
            </main>
        </div>
        <?php
    }

    /* ================================================================== *
     *  Handlers
     * ================================================================== */

    public function handle_move(): void
    {
        $id = isset($_POST['deal']) ? absint($_POST['deal']) : 0;
        check_admin_referer('vkl_crm_deal_move_' . $id);

        $deal = $this->deals->find($id);

        if (null === $deal || !$this->access->can_edit($deal)) {
            VKL_Crm_Ui::redirect(self::url(), null === $deal ? 'missing' : 'denied');
        }

        $result = $this->service->move($id, isset($_POST['stage_id']) ? absint($_POST['stage_id']) : 0, isset($_POST['lost_reason']) ? wp_unslash((string) $_POST['lost_reason']) : '');

        // From the deal page's stage bar → back there; from a board card → the board.
        $back = isset($_POST['_vkl_back']) ? VKL_Crm_Ui::back_url() : self::url(['pipeline' => (int) $deal['pipeline_id']]);
        VKL_Crm_Ui::redirect($back, is_wp_error($result) ? 'invalid' : 'deal_moved');
    }

    /**
     * Won (GET link) or lost (POST form with a reason).
     */
    public function handle_close(): void
    {
        $id     = isset($_REQUEST['id']) ? absint($_REQUEST['id']) : 0;
        $result = isset($_REQUEST['result']) ? sanitize_key((string) $_REQUEST['result']) : '';
        $won    = 'won' === $result;

        check_admin_referer('vkl_crm_' . ($won ? 'won' : 'lost') . '_' . $id);

        $deal = $this->deals->find($id);

        if (null === $deal || !$this->access->can_edit($deal)) {
            VKL_Crm_Ui::redirect(self::url(), null === $deal ? 'missing' : 'denied');
        }

        $reason = '';

        if (!$won) {
            $other  = isset($_POST['lost_other']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['lost_other']))) : '';
            $reason = '' !== $other ? $other : (isset($_POST['lost_reason']) ? sanitize_text_field(wp_unslash((string) $_POST['lost_reason'])) : '');
        }

        $res = $this->service->close($id, $won, $reason);
        VKL_Crm_Ui::redirect(self::deal_url($id), is_wp_error($res) ? 'invalid' : ($won ? 'deal_won' : 'deal_lost'));
    }

    public function handle_delete(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('vkl_crm_delete_' . $id);

        $deal = $this->deals->find($id);

        if (null === $deal || !$this->access->can_delete($deal)) {
            VKL_Crm_Ui::redirect(self::url(), null === $deal ? 'missing' : 'denied');
        }

        $this->deals->soft_delete($id);
        VKL_Crm_Ui::redirect(self::url(['pipeline' => (int) $deal['pipeline_id']]), 'deal_deleted');
    }

    /**
     * Board drag & drop. Returns the new column totals so the board can
     * update without a reload.
     */
    public function ajax_move(): void
    {
        check_ajax_referer('vkl_crm_deal_move', 'nonce');

        $id   = isset($_POST['deal']) ? absint($_POST['deal']) : 0;
        $deal = $this->deals->find($id);

        if (null === $deal || !$this->access->can_edit($deal)) {
            wp_send_json_error(['message' => __('You are not allowed to move this deal.', 'vereskala-login')], 403);
        }

        $result = $this->service->move($id, isset($_POST['stage']) ? absint($_POST['stage']) : 0, isset($_POST['reason']) ? wp_unslash((string) $_POST['reason']) : '');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 422);
        }

        wp_send_json_success(['deal' => $id]);
    }

    /**
     * Contact search for the deal form (only contacts the user may edit).
     */
    public function ajax_lookup(): void
    {
        check_ajax_referer('vkl_crm_lookup', 'nonce');

        if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            wp_send_json_error([], 403);
        }

        $q    = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
        $rows = '' === trim($q) ? [] : $this->contacts->search(['q' => $q], $this->access->scope_sql('t.owner_id'), 1, 10, 'last_activity_at')['rows'];
        $out  = [];

        foreach ($rows as $c) {
            if ($this->access->can_edit($c)) {
                $out[] = ['id' => (int) $c['id'], 'label' => VKL_Crm_Ui::contact_name($c), 'hint' => (string) ($c['mobile'] ?? $c['company'] ?? '')];
            }
        }

        wp_send_json_success($out);
    }
}
