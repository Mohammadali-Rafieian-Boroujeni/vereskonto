<?php
/**
 * Contacts list table (WordPress's own WP_List_Table: sorting, paging,
 * bulk actions, responsive rows, screen options).
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class VKL_Crm_Contacts_Table
 */
final class VKL_Crm_Contacts_Table extends WP_List_Table
{
    /** Filter keys read from the query string. */
    public const FILTERS = ['q', 'lifecycle', 'owner_id', 'source', 'tag_id', 'city', 'min_spent', 'max_spent', 'min_orders', 'created_from', 'created_to', 'active_from', 'active_to', 'has_user'];

    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Tags $tags;
    private VKL_Crm_Access $access;

    /** @var array<string, mixed> */
    private array $filters = [];

    private bool $show_deleted = false;

    /** @var array<int, array<int, array<string, mixed>>> contact id => tags */
    private array $row_tags = [];

    public function __construct(VKL_Crm_Contacts $contacts, VKL_Crm_Tags $tags, VKL_Crm_Access $access)
    {
        $this->contacts = $contacts;
        $this->tags     = $tags;
        $this->access   = $access;

        parent::__construct([
            'singular' => 'contact',
            'plural'   => 'contacts',
            'ajax'     => false,
            'screen'   => get_current_screen(),
        ]);
    }

    /**
     * Filters from the request, normalised. Jalali dates become Y-m-d.
     *
     * @return array<string, mixed>
     */
    public static function read_filters(): array
    {
        $f = [];

        foreach (self::FILTERS as $key) {
            if (!isset($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                continue;
            }

            $value = trim(sanitize_text_field(wp_unslash((string) $_GET[$key]))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if ('' === $value) {
                continue;
            }

            if (str_ends_with($key, '_from') || str_ends_with($key, '_to')) {
                $value = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && (int) substr($value, 0, 4) > 1700 ? $value : VKL_Jalali::parse($value);

                if ('' === $value) {
                    continue;
                }
            }

            $f[$key] = $value;
        }

        return $f;
    }

    public function get_columns(): array
    {
        return [
            'cb'               => '<input type="checkbox" />',
            'name'             => __('Name', 'vereskala-login'),
            'mobile'           => __('Mobile', 'vereskala-login'),
            'lifecycle'        => __('Stage', 'vereskala-login'),
            'owner'            => __('Owner', 'vereskala-login'),
            'tags'             => __('Tags', 'vereskala-login'),
            'orders_count'     => __('Orders', 'vereskala-login'),
            'total_spent'      => __('Total spent', 'vereskala-login'),
            'source'           => __('Source', 'vereskala-login'),
            'last_activity_at' => __('Last activity', 'vereskala-login'),
            'created_at'       => __('Added', 'vereskala-login'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'name'             => ['last_name', false],
            'orders_count'     => ['orders_count', true],
            'total_spent'      => ['total_spent', true],
            'last_activity_at' => ['last_activity_at', true],
            'created_at'       => ['created_at', true],
        ];
    }

    protected function get_default_primary_column_name(): string
    {
        return 'name';
    }

    /**
     * Columns hidden until the user turns them on in Screen Options.
     */
    public const DEFAULT_HIDDEN = ['source', 'created_at'];

    protected function get_bulk_actions(): array
    {
        if ($this->show_deleted) {
            return $this->access->can(VKL_Crm_Access::CAP_DELETE) ? ['restore' => __('Restore', 'vereskala-login')] : [];
        }

        $actions = [];

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            $actions['claim'] = __('Assign to me', 'vereskala-login');
        }

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
            $actions['assign'] = __('Assign to…', 'vereskala-login');
        }

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            $actions['lifecycle'] = __('Change stage to…', 'vereskala-login');
            $actions['tag']       = __('Add tag…', 'vereskala-login');
            $actions['untag']     = __('Remove tag…', 'vereskala-login');
        }

        if ($this->access->can(VKL_Crm_Access::CAP_DELETE)) {
            $actions['delete'] = __('Delete', 'vereskala-login');
        }

        return $actions;
    }

    /**
     * The value pickers that go with the bulk actions; admin.js shows
     * only the one matching the chosen action.
     */
    protected function bulk_actions($which = ''): void
    {
        parent::bulk_actions($which);

        if ('top' !== $which || $this->show_deleted) {
            return;
        }

        echo '<span class="vkl-crm-bulk-values">';

        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
            echo '<span data-for="assign">' . VKL_Crm_Ui::owner_select('bulk_owner', 0) . '</span>'; // phpcs:ignore -- escaped in owner_select.
        }

        echo '<span data-for="lifecycle">' . VKL_Crm_Ui::select('bulk_lifecycle', VKL_Crm_Ui::lifecycles(), '') . '</span>'; // phpcs:ignore

        $tag_opts = [];

        foreach ($this->tags->all() as $t) {
            $tag_opts[(string) $t['id']] = $t['name'];
        }

        echo '<span data-for="tag untag">' . VKL_Crm_Ui::select('bulk_tag', $tag_opts, '') . '</span>'; // phpcs:ignore
        echo '</span>';
    }

    public function prepare_items(): void
    {
        $this->filters      = self::read_filters();
        $this->show_deleted = !empty($_GET['deleted']) && $this->access->can(VKL_Crm_Access::CAP_DELETE); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $per_page = $this->get_items_per_page('vkl_crm_contacts_per_page', 20);
        $orderby  = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order    = isset($_GET['order']) ? sanitize_key((string) $_GET['order']) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $result = $this->contacts->search(
            $this->filters + ['deleted' => $this->show_deleted],
            $this->access->scope_sql('t.owner_id'),
            $this->get_pagenum(),
            $per_page,
            $orderby,
            $order
        );

        $this->items = $result['rows'];

        foreach ($this->items as $row) {
            $this->row_tags[(int) $row['id']] = $this->tags->for_contact((int) $row['id']);
        }

        $this->_column_headers = [$this->get_columns(), get_hidden_columns($this->screen), $this->get_sortable_columns(), 'name'];

        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => $result['per_page'],
            'total_pages' => $result['pages'],
        ]);
    }

    /**
     * Lifecycle views above the table, with counts in the user's scope.
     */
    protected function get_views(): array
    {
        $counts  = $this->contacts->count_by_lifecycle($this->access->scope_sql('t.owner_id'));
        $current = (string) ($this->filters['lifecycle'] ?? '');
        $base    = VKL_Crm_Ui::contacts_url();
        $views   = [];

        $views['all'] = sprintf(
            '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
            esc_url($base),
            '' === $current && !$this->show_deleted ? ' class="current" aria-current="page"' : '',
            esc_html__('All', 'vereskala-login'),
            esc_html(number_format_i18n(array_sum($counts)))
        );

        foreach (VKL_Crm_Ui::lifecycles() as $key => $label) {
            $views[$key] = sprintf(
                '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
                esc_url(add_query_arg('lifecycle', $key, $base)),
                $current === $key ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                esc_html(number_format_i18n($counts[$key] ?? 0))
            );
        }

        if ($this->access->can(VKL_Crm_Access::CAP_DELETE)) {
            $views['deleted'] = sprintf(
                '<a href="%1$s"%2$s>%3$s</a>',
                esc_url(add_query_arg('deleted', '1', $base)),
                $this->show_deleted ? ' class="current" aria-current="page"' : '',
                esc_html__('Deleted', 'vereskala-login')
            );
        }

        return $views;
    }

    public function no_items(): void
    {
        echo [] !== $this->filters
            ? esc_html__('No contact matches these filters.', 'vereskala-login')
            : esc_html__('No contacts yet. Existing customers are imported automatically in the background.', 'vereskala-login');
    }

    protected function column_cb($item): string
    {
        return sprintf(
            '<label class="screen-reader-text" for="vkl-c-%1$d">%2$s</label><input type="checkbox" id="vkl-c-%1$d" name="ids[]" value="%1$d" />',
            (int) $item['id'],
            esc_html(sprintf(/* translators: %s: contact name */ __('Select %s', 'vereskala-login'), VKL_Crm_Ui::contact_name($item)))
        );
    }

    protected function column_name(array $item): string
    {
        $id   = (int) $item['id'];
        $url  = VKL_Crm_Ui::contact_url($id);
        $html = sprintf(
            '<span class="vkl-crm-avatar" aria-hidden="true">%1$s</span><a class="row-title" href="%2$s">%3$s</a>',
            esc_html(VKL_Crm_Ui::initial($item)),
            esc_url($url),
            esc_html(VKL_Crm_Ui::contact_name($item))
        );

        if ('' !== (string) $item['company'] && '' !== trim($item['first_name'] . $item['last_name'])) {
            $html .= '<br><span class="description">' . esc_html((string) $item['company']) . '</span>';
        }

        $actions = ['view' => sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Open', 'vereskala-login'))];

        if ($this->access->can_edit($item)) {
            $actions['edit'] = sprintf('<a href="%s">%s</a>', esc_url(VKL_Crm_Ui::contact_url($id, ['action' => 'edit'])), esc_html__('Edit', 'vereskala-login'));
        } elseif ($this->access->can_claim($item)) {
            $actions['claim'] = sprintf('<a href="%s">%s</a>', esc_url($this->action_url('claim', $id)), esc_html__('Assign to me', 'vereskala-login'));
        }

        if (!empty($item['mobile'])) {
            $actions['call'] = sprintf('<a href="tel:%1$s">%2$s</a>', esc_attr((string) $item['mobile']), esc_html__('Call', 'vereskala-login'));
        }

        return $html . $this->row_actions($actions);
    }

    protected function column_mobile(array $item): string
    {
        return empty($item['mobile']) ? '—' : '<span dir="ltr">' . esc_html((string) $item['mobile']) . '</span>';
    }

    protected function column_lifecycle(array $item): string
    {
        return VKL_Crm_Ui::lifecycle_pill((string) $item['lifecycle']);
    }

    protected function column_owner(array $item): string
    {
        $owner = (int) $item['owner_id'];

        return $owner > 0 ? esc_html(VKL_Crm_Ui::user_name($owner)) : '<span class="description">' . esc_html__('Unassigned', 'vereskala-login') . '</span>';
    }

    protected function column_tags(array $item): string
    {
        return VKL_Crm_Ui::tag_chips($this->row_tags[(int) $item['id']] ?? []);
    }

    protected function column_orders_count(array $item): string
    {
        return esc_html(number_format_i18n((int) $item['orders_count']));
    }

    protected function column_total_spent(array $item): string
    {
        return (float) $item['total_spent'] > 0 ? VKL_Crm_Ui::money((float) $item['total_spent']) : '—';
    }

    protected function column_source(array $item): string
    {
        return esc_html(VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), (string) $item['source']));
    }

    protected function column_last_activity_at(array $item): string
    {
        return empty($item['last_activity_at'])
            ? '—'
            : '<span title="' . esc_attr(VKL_Jalali::format((string) $item['last_activity_at'], 'long', true)) . '">' . esc_html(VKL_Crm_Ui::ago((string) $item['last_activity_at'])) . '</span>';
    }

    protected function column_created_at(array $item): string
    {
        return VKL_Crm_Ui::date((string) $item['created_at']);
    }

    /**
     * Single-row action link through admin-post with its own nonce.
     */
    private function action_url(string $action, int $id): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => 'vkl_crm_contact_' . $action, 'contact' => $id], VKL_Crm_Ui::post_url()),
            'vkl_crm_' . $action . '_' . $id
        );
    }
}
