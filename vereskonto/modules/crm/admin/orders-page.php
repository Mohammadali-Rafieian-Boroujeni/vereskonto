<?php
/**
 * CRM → Orders: WooCommerce orders seen from the CRM — who the customer
 * is, which agent owns them, which deal an order belongs to — with
 * status changes and "create a deal from this order".
 *
 * Orders are never copied: everything is read through wc_get_orders()
 * (HPOS and legacy storage alike). Wallet top-ups and instalment
 * repayments are labelled "not a sale".
 *
 * Who sees what: CRM managers see every order; sales agents see the
 * orders of customers (with a site account) assigned to them.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.18.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Orders_Page
 */
final class VKL_Crm_Orders_Page
{
    public const PAGE = 'vkl-crm-orders';

    private const PER_PAGE = 20;

    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Contact_Service $contact_service;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Deal_Service $deal_service;
    private VKL_Crm_Access $access;

    public function __construct(VKL_Crm_Contacts $contacts, VKL_Crm_Contact_Service $contact_service, VKL_Crm_Deals $deals, VKL_Crm_Deal_Service $deal_service, VKL_Crm_Access $access)
    {
        $this->contacts        = $contacts;
        $this->contact_service = $contact_service;
        $this->deals           = $deals;
        $this->deal_service    = $deal_service;
        $this->access          = $access;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_vkl_crm_order_status', [$this, 'handle_status']);
        add_action('admin_post_vkl_crm_order_deal', [$this, 'handle_deal']);
    }

    public function menu(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Customer orders', 'vereskala-login'),
            __('Customer orders', 'vereskala-login'),
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

    /**
     * The query, built from the filters and the user's scope. null when
     * the user can see no orders at all (an agent with no customers).
     *
     * @param array<string, string> $f
     * @return array<string, mixed>|null
     */
    private function query(array $f, int $paged): ?array
    {
        $args = [
            'type'     => 'shop_order',
            'limit'    => self::PER_PAGE,
            'paged'    => $paged,
            'paginate' => true,
            'orderby'  => 'date',
            'order'    => 'DESC',
        ];

        if ('' !== $f['status'] && array_key_exists('wc-' . $f['status'], wc_get_order_statuses())) {
            $args['status'] = [$f['status']];
        }

        $from = '' !== $f['from'] ? VKL_Crm_Task_Service::local_to_utc($f['from'], '00:00') : null;
        $to   = '' !== $f['to'] ? VKL_Crm_Task_Service::local_to_utc($f['to'], '23:59') : null;

        if (is_string($from) || is_string($to)) {
            $args['date_created'] = (is_string($from) ? strtotime($from . ' UTC') : 0) . '...' . (is_string($to) ? strtotime($to . ' UTC') + 59 : time() + DAY_IN_SECONDS);
        }

        $customers = null;

        // Search: an order number, or a contact (name / mobile / email).
        $q = trim(vkl_latin_digits($f['q']));

        if ('' !== $q) {
            $num = ltrim($q, '#');

            if (ctype_digit($num) && strlen($num) <= 9) {
                $args['include'] = [(int) $num];
            } else {
                $found     = $this->contacts->search(['q' => $q], $this->access->scope_sql('t.owner_id'), 1, 200)['rows'];
                $customers = array_values(array_filter(array_map(static fn(array $c): int => (int) $c['user_id'], $found)));

                if ([] === $customers) {
                    return null;
                }
            }
        }

        // Agents: only their own customers' orders.
        if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
            $mine      = $this->contacts->search(['owner_id' => (string) get_current_user_id(), 'has_user' => '1'], ['1=1', []], 1, VKL_Crm_Repository::MAX_PER_PAGE)['rows'];
            $mine      = array_map(static fn(array $c): int => (int) $c['user_id'], $mine);
            $customers = null === $customers ? $mine : array_values(array_intersect($customers, $mine));

            if ([] === $customers) {
                return null;
            }
        }

        if (null !== $customers) {
            $args['customer'] = $customers;
        }

        return $args;
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        $f = [];

        foreach (['status', 'q', 'from', 'to'] as $key) {
            $f[$key] = isset($_GET[$key]) ? sanitize_text_field(wp_unslash((string) $_GET[$key])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        $f['status'] = sanitize_key($f['status']);
        $paged       = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args        = $this->query($f, $paged);
        $result      = null === $args ? null : wc_get_orders($args);
        $orders      = null === $result ? [] : (array) $result->orders;
        $total       = null === $result ? 0 : (int) $result->total;
        $pages       = null === $result ? 0 : (int) $result->max_num_pages;
        $can_status  = current_user_can('edit_shop_orders');
        $statuses    = [];

        foreach (wc_get_order_statuses() as $key => $label) {
            $statuses[substr($key, 3)] = $label;
        }
        ?>
        <div class="wrap vkl-crm">
            <h1><?php esc_html_e('Customer orders', 'vereskala-login'); ?></h1>
            <hr class="wp-header-end">
            <?php VKL_Crm_Ui::notices(); ?>
            <?php if (!$this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) : ?>
                <p class="description"><?php esc_html_e('Orders of the customers assigned to you (customers with a site account).', 'vereskala-login'); ?></p>
            <?php endif; ?>

            <form method="get" class="vkl-crm-board-bar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="<?php esc_attr_e('Order number, customer name or mobile…', 'vereskala-login'); ?>">
                <?php echo VKL_Crm_Ui::select('status', $statuses, $f['status'], __('Any status', 'vereskala-login')); // phpcs:ignore ?>
                <input type="text" name="from" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($f['from']); ?>" placeholder="<?php esc_attr_e('From 1405/09/01', 'vereskala-login'); ?>" aria-label="<?php esc_attr_e('From date', 'vereskala-login'); ?>">
                <input type="text" name="to" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($f['to']); ?>" placeholder="<?php esc_attr_e('To 1405/09/30', 'vereskala-login'); ?>" aria-label="<?php esc_attr_e('To date', 'vereskala-login'); ?>">
                <button class="button"><?php esc_html_e('Filter', 'vereskala-login'); ?></button>
                <span class="description"><?php echo esc_html(sprintf(/* translators: %s: number of orders */ _n('%s order', '%s orders', $total, 'vereskala-login'), number_format_i18n($total))); ?></span>
            </form>

            <table class="widefat striped vkl-crm-orders-table">
                <thead><tr>
                    <th><?php esc_html_e('Order', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Date', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Owner', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Total', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Deal', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ([] === $orders) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No orders match.', 'vereskala-login'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o) {
                    if ($o instanceof WC_Order) {
                        $this->row($o, $statuses, $can_status);
                    }
                } ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <p class="vkl-crm-pages">
                    <?php for ($p = 1; $p <= min($pages, 50); $p++) : ?>
                        <a class="button<?php echo $p === $paged ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged', $p)); ?>"><?php echo esc_html(number_format_i18n($p)); ?></a>
                    <?php endfor; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array<string, string> $statuses
     */
    private function row(WC_Order $o, array $statuses, bool $can_status): void
    {
        $id      = $o->get_id();
        $carrier = vkl_is_carrier_order($id);
        $contact = $this->contact_of($o);
        $deal    = $this->deals->search(['order_id' => $id], ['1=1', []], 1, 1)['rows'][0] ?? null;
        $back    = remove_query_arg(['vkl_msg', 'vkl_count']);
        $created = $o->get_date_created();
        ?>
        <tr>
            <td>
                <?php echo current_user_can('edit_shop_orders') ? '<a class="row-title" href="' . esc_url((string) $o->get_edit_order_url()) . '">#' . esc_html((string) $o->get_order_number()) . '</a>' : '<strong>#' . esc_html((string) $o->get_order_number()) . '</strong>'; ?>
                <?php if ($carrier) : ?><br><span class="vkl-crm-pill" title="<?php esc_attr_e('Wallet top-up or installment repayment', 'vereskala-login'); ?>"><?php esc_html_e('Not a sale', 'vereskala-login'); ?></span><?php endif; ?>
            </td>
            <td><?php echo $created ? esc_html(VKL_Jalali::format(gmdate('Y-m-d H:i:s', $created->getTimestamp()), 'short', true)) : '—'; ?></td>
            <td>
                <?php if (null !== $contact) : ?>
                    <a href="<?php echo esc_url(VKL_Crm_Ui::contact_url((int) $contact['id'])); ?>"><?php echo esc_html(VKL_Crm_Ui::contact_name($contact)); ?></a>
                <?php else : ?>
                    <?php echo esc_html(trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: '—'); ?>
                <?php endif; ?>
                <?php if ('' !== (string) $o->get_billing_phone()) : ?><br><span class="description" dir="ltr"><?php echo esc_html((string) $o->get_billing_phone()); ?></span><?php endif; ?>
            </td>
            <td><?php echo null !== $contact ? esc_html(VKL_Crm_Ui::user_name((int) $contact['owner_id'])) : '—'; ?></td>
            <td>
                <?php if ($can_status) : ?>
                    <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-order-status">
                        <input type="hidden" name="action" value="vkl_crm_order_status">
                        <input type="hidden" name="order" value="<?php echo esc_attr((string) $id); ?>">
                        <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
                        <?php wp_nonce_field('vkl_crm_order_status_' . $id); ?>
                        <select name="status" aria-label="<?php esc_attr_e('Order status', 'vereskala-login'); ?>">
                            <?php foreach ($statuses as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $o->get_status()); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="button button-small"><?php esc_html_e('Change', 'vereskala-login'); ?></button>
                    </form>
                <?php else : ?>
                    <span class="vkl-crm-pill is-order"><?php echo esc_html(wc_get_order_status_name($o->get_status())); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo VKL_Crm_Ui::money((float) $o->get_total()); // phpcs:ignore ?></td>
            <td>
                <?php if (null !== $deal && $this->access->can_view($deal)) : ?>
                    <a href="<?php echo esc_url(VKL_Crm_Deals_Page::deal_url((int) $deal['id'])); ?>"><?php echo esc_html((string) $deal['title']); ?></a>
                <?php elseif (null === $deal && !$carrier && null !== $contact && $this->access->can_edit($contact)) : ?>
                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'vkl_crm_order_deal', 'id' => $id, '_vkl_back' => rawurlencode($back)], VKL_Crm_Ui::post_url()), 'vkl_crm_order_deal_' . $id)); ?>"><?php esc_html_e('Create deal', 'vereskala-login'); ?></a>
                <?php else : ?>
                    —
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * The order's contact without creating one (listing must not write).
     *
     * @return array<string, mixed>|null
     */
    private function contact_of(WC_Order $o): ?array
    {
        $uid = (int) $o->get_customer_id();

        if ($uid > 0) {
            return $this->contacts->find_by_user($uid);
        }

        $stamp = (int) $o->get_meta(VKL_Crm_Contact_Service::ORDER_META);

        return $stamp > 0 ? $this->contacts->find($stamp, true) : $this->contacts->find_by_mobile(vkl_normalize_mobile(vkl_latin_digits((string) $o->get_billing_phone())));
    }

    /* ------------------------------------------------------------ handlers */

    public function handle_status(): void
    {
        $id = isset($_POST['order']) ? absint($_POST['order']) : 0;
        check_admin_referer('vkl_crm_order_status_' . $id);

        $back   = VKL_Crm_Ui::back_url();
        $order  = wc_get_order($id);
        $status = isset($_POST['status']) ? sanitize_key((string) $_POST['status']) : '';

        if (!$order instanceof WC_Order || !current_user_can('edit_shop_orders') || !array_key_exists('wc-' . $status, wc_get_order_statuses())) {
            VKL_Crm_Ui::redirect($back, 'denied');
        }

        if ($order->get_status() !== $status) {
            $order->update_status($status, sprintf(/* translators: %s: staff name */ __('Status changed from the CRM by %s.', 'vereskala-login'), wp_get_current_user()->display_name));
        }

        VKL_Crm_Ui::redirect($back, 'order_status');
    }

    /**
     * Create a deal for an order: won straight away when the order is
     * already paid, otherwise in the first open stage.
     */
    public function handle_deal(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('vkl_crm_order_deal_' . $id);

        $back  = isset($_GET['_vkl_back']) ? wp_validate_redirect(esc_url_raw(rawurldecode(wp_unslash((string) $_GET['_vkl_back']))), self::url()) : self::url();
        $order = wc_get_order($id);

        if (!$order instanceof WC_Order || vkl_is_carrier_order($id)) {
            VKL_Crm_Ui::redirect($back, 'invalid');
        }

        $contact_id = $this->contact_service->for_order($order);
        $contact    = $contact_id > 0 ? $this->contacts->find($contact_id) : null;

        if (null === $contact || !$this->access->can_edit($contact)) {
            VKL_Crm_Ui::redirect($back, 'denied');
        }

        $existing = $this->deals->search(['order_id' => $id], ['1=1', []], 1, 1)['rows'][0] ?? null;

        if (null !== $existing) {
            wp_safe_redirect(VKL_Crm_Deals_Page::deal_url((int) $existing['id']));
            exit;
        }

        $deal_id = $this->deal_service->create([
            'contact_id' => $contact_id,
            'title'      => sprintf(/* translators: %s: order number */ __('Order #%s', 'vereskala-login'), $order->get_order_number()),
            'value'      => (string) (float) $order->get_total(),
            'order'      => (string) $id,
            'source'     => (string) ($contact['source'] ?? ''),
        ], get_current_user_id());

        if (is_wp_error($deal_id)) {
            VKL_Crm_Ui::redirect($back, 'invalid');
        }

        if ($order->is_paid()) {
            $this->deal_service->close((int) $deal_id, true);
        }

        VKL_Crm_Ui::redirect(VKL_Crm_Deals_Page::deal_url((int) $deal_id), 'deal_created');
    }
}
