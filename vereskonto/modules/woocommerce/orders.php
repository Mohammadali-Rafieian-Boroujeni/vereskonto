<?php
/**
 * Order dispatch — tracking code + dispatch method on the order edit
 * screen (legacy and HPOS compatible) plus display in the customer's
 * order view. Saving a new code dispatches 'order.tracking_added' with
 * the human-readable method name so listeners (SMS/WhatsApp) can tell
 * the customer *how* the order ships, not just the code.
 *
 * Dispatch methods (پست، پیک، تیپاکس، …) are managed by the shop admin
 * as a one-per-line list in the notifications settings ('shipping_methods').
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Orders
 */
final class VKL_Wc_Orders
{
    private const META_KEY        = '_vkl_tracking_code';
    private const META_KEY_METHOD = '_vkl_shipping_method';

    private VKL_Wc_Hooks $hooks;

    private VKL_Security $security;

    private VKL_Config $config;

    public function __construct(VKL_Wc_Hooks $hooks, VKL_Security $security, VKL_Config $config)
    {
        $this->hooks    = $hooks;
        $this->security = $security;
        $this->config   = $config;
    }

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'render_field']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_field'], 50, 1);
        add_action('woocommerce_order_details_after_order_table', [$this, 'render_customer_view']);

        // Record HOW an order was cancelled so the shop can tell a
        // customer-initiated cancellation from an admin one.
        add_action('woocommerce_cancelled_order', [$this, 'mark_customer_cancel'], 5, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'stamp_cancel_source'], 5, 1);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_cancelled_menu'], 30);
        }
    }

    /**
     * Submenu: cancelled orders and which products they contained.
     */
    public function add_cancelled_menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Cancelled orders', 'vereskala-login'),
            __('Cancelled orders', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-cancelled',
            [$this, 'render_cancelled']
        );
    }

    /**
     * Human label for who cancelled.
     */
    private function cancel_by_label(string $by): string
    {
        switch ($by) {
            case 'customer':
                return __('By the customer', 'vereskala-login');
            case 'admin':
                return __('By the admin', 'vereskala-login');
            case 'system':
                return __('Automatic / system', 'vereskala-login');
            default:
                return __('Unknown', 'vereskala-login');
        }
    }

    /**
     * List cancelled orders, each with its line items and how it was
     * cancelled. Focuses on customer cancellations (the common question:
     * "what did people cancel, and how?") but shows all with a filter.
     */
    public function render_cancelled(): void
    {
        if (!$this->security->current_user_can_admin() || !function_exists('wc_get_orders')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $who   = isset($_GET['vkl_who']) ? sanitize_key($_GET['vkl_who']) : 'customer';
        $paged = isset($_GET['vkl_cpage']) ? max(1, absint($_GET['vkl_cpage'])) : 1;
        // phpcs:enable

        if (!in_array($who, ['customer', 'admin', 'all'], true)) {
            $who = 'customer';
        }

        $per    = 20;
        $orders = wc_get_orders([
            'status'   => ['wc-cancelled'],
            'limit'    => $per,
            'paged'    => $paged,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
        ]);
        ?>
        <div class="wrap">
            <h1>🚫 <?php esc_html_e('Cancelled orders', 'vereskala-login'); ?></h1>
            <p class="description"><?php esc_html_e('Which products were in each cancelled order, and how the cancellation happened.', 'vereskala-login'); ?></p>

            <ul class="subsubsub">
                <?php
                $tabs = [
                    'customer' => __('Cancelled by customers', 'vereskala-login'),
                    'admin'    => __('Cancelled by admin', 'vereskala-login'),
                    'all'      => __('All cancelled', 'vereskala-login'),
                ];
                $i = 0;
                foreach ($tabs as $key => $label) :
                    $i++;
                    $url = esc_url(add_query_arg(['page' => 'vkl-cancelled', 'vkl_who' => $key], admin_url('admin.php')));
                    ?>
                    <li>
                        <a href="<?php echo $url; // phpcs:ignore ?>" class="<?php echo $who === $key ? 'current' : ''; ?>"><?php echo esc_html($label); ?></a><?php echo $i < count($tabs) ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="widefat striped" style="max-width:1100px;margin-top:12px">
                <thead><tr>
                    <th><?php esc_html_e('Order', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Cancelled products', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('How', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('When', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php
                $shown = 0;
                foreach ($orders as $order) :
                    if (!$order instanceof WC_Order) { continue; }
                    $by = (string) $order->get_meta('_vkl_cancel_by');
                    $by = '' !== $by ? $by : 'unknown';

                    if ('all' !== $who && $by !== $who) {
                        // 'admin' tab also shows system/unknown as non-customer.
                        if ('admin' === $who && in_array($by, ['admin', 'system', 'unknown'], true)) {
                            // allow
                        } else {
                            continue;
                        }
                    }
                    $shown++;
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url((string) $order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                        </td>
                        <td>
                            <?php echo esc_html(trim($order->get_formatted_billing_full_name()) ?: ('#' . $order->get_customer_id())); ?>
                            <br><span class="description" dir="ltr" style="font-size:11px"><?php echo esc_html((string) $order->get_billing_phone()); ?></span>
                        </td>
                        <td>
                            <?php foreach ($order->get_items() as $item) : ?>
                                <?php if (!$item instanceof WC_Order_Item_Product) { continue; } ?>
                                <div>• <?php echo esc_html($item->get_name()); ?> <span class="description">×<?php echo esc_html(number_format_i18n((int) $item->get_quantity())); ?></span></div>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php
                            $badge = 'customer' === $by ? '#e5262c' : ('admin' === $by ? '#157f3a' : '#8a8a8a');
                            ?>
                            <span style="display:inline-block;background:<?php echo esc_attr($badge); ?>;color:#fff;border-radius:999px;padding:1px 10px;font-size:12px"><?php echo esc_html($this->cancel_by_label($by)); ?></span>
                        </td>
                        <td dir="ltr" style="font-size:12px">
                            <?php
                            $when = (string) $order->get_meta('_vkl_cancel_at');
                            echo esc_html('' !== $when ? mysql2date(get_option('date_format') . ' H:i', $when) : ($order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i') : '—'));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (0 === $shown) : ?>
                    <tr><td colspan="5"><p class="description" style="margin:8px 0"><?php esc_html_e('No cancelled orders in this view.', 'vereskala-login'); ?></p></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div style="display:flex;gap:6px;margin-top:12px">
                <?php if ($paged > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'vkl-cancelled', 'vkl_who' => $who, 'vkl_cpage' => $paged - 1], admin_url('admin.php'))); ?>">‹ <?php esc_html_e('Newer', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <?php if (count($orders) === $per) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'vkl-cancelled', 'vkl_who' => $who, 'vkl_cpage' => $paged + 1], admin_url('admin.php'))); ?>"><?php esc_html_e('Older', 'vereskala-login'); ?> ›</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * The customer's own "Cancel" link in My Account fires this hook
     * (WooCommerce does not fire it for admin status changes), so it is a
     * reliable marker of a customer-initiated cancellation.
     */
    public function mark_customer_cancel(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if ($order instanceof WC_Order) {
            $order->update_meta_data('_vkl_cancel_by', 'customer');
            $order->update_meta_data('_vkl_cancel_at', gmdate('Y-m-d H:i:s'));
            $order->save();
        }
    }

    /**
     * When an order reaches "cancelled" without the customer marker, it
     * was cancelled from the admin (or by the system).
     */
    public function stamp_cancel_source(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        if ('' === (string) $order->get_meta('_vkl_cancel_by')) {
            $by = is_admin() ? 'admin' : 'system';
            $order->update_meta_data('_vkl_cancel_by', $by);
            $order->update_meta_data('_vkl_cancel_at', gmdate('Y-m-d H:i:s'));
            $order->save();
        }
    }

    /**
     * Admin-defined dispatch methods, one per line.
     *
     * @return array<int, string>
     */
    public function methods(): array
    {
        $raw   = $this->config->get_string('shipping_methods');
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        return array_values(array_unique($lines));
    }

    /**
     * Fields on the admin order screen.
     *
     * @param WC_Order $order Order.
     */
    public function render_field(WC_Order $order): void
    {
        $methods = $this->methods();
        $current = (string) $order->get_meta(self::META_KEY_METHOD);
        ?>
        <div class="address">
            <?php if ([] !== $methods) : ?>
                <p>
                    <strong><?php esc_html_e('Dispatch method', 'vereskala-login'); ?></strong>
                    <select name="vkl_shipping_method" style="width:100%">
                        <option value=""><?php esc_html_e('— Select —', 'vereskala-login'); ?></option>
                        <?php foreach ($methods as $method) : ?>
                            <option value="<?php echo esc_attr($method); ?>" <?php selected($current, $method); ?>>
                                <?php echo esc_html($method); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
            <p>
                <strong><?php esc_html_e('Tracking code', 'vereskala-login'); ?></strong>
                <input type="text" style="width:100%" name="vkl_tracking_code"
                       value="<?php echo esc_attr((string) $order->get_meta(self::META_KEY)); ?>">
                <?php wp_nonce_field(VKL_Security::ADMIN_NONCE, '_vkl_nonce'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Save method + tracking code; dispatch the event only when the
     * code or the method changed and a code is present.
     *
     * @param mixed $order_id Order id.
     */
    public function save_field(mixed $order_id): void
    {
        if (!isset($_POST['vkl_tracking_code'])) {
            return;
        }

        $nonce = isset($_POST['_vkl_nonce']) ? sanitize_text_field(wp_unslash($_POST['_vkl_nonce'])) : '';

        if (!wp_verify_nonce($nonce, VKL_Security::ADMIN_NONCE) || !$this->security->current_user_can_admin()) {
            return;
        }

        $order = wc_get_order((int) $order_id);

        if (!$order) {
            return;
        }

        $code   = sanitize_text_field(wp_unslash($_POST['vkl_tracking_code']));
        $method = isset($_POST['vkl_shipping_method'])
            ? sanitize_text_field(wp_unslash($_POST['vkl_shipping_method']))
            : '';

        // Only admin-defined methods are accepted.
        if ('' !== $method && !in_array($method, $this->methods(), true)) {
            $method = '';
        }

        $old_code   = (string) $order->get_meta(self::META_KEY);
        $old_method = (string) $order->get_meta(self::META_KEY_METHOD);

        if ($code === $old_code && $method === $old_method) {
            return;
        }

        $order->update_meta_data(self::META_KEY, $code);
        $order->update_meta_data(self::META_KEY_METHOD, $method);
        $order->save();

        if ('' !== $code) {
            vkl_dispatch('order.tracking_added', $this->hooks->payload($order, [
                'tracking_code'   => $code,
                'shipping_method' => $method,
            ]));
        }
    }

    /**
     * Show dispatch method + tracking code to the customer.
     *
     * @param WC_Order $order Order.
     */
    public function render_customer_view(WC_Order $order): void
    {
        $code   = (string) $order->get_meta(self::META_KEY);
        $method = (string) $order->get_meta(self::META_KEY_METHOD);

        if ('' === $code && '' === $method) {
            return;
        }

        if ('' !== $method) {
            echo '<p><strong>' . esc_html__('Dispatch method', 'vereskala-login') . ':</strong> ' . esc_html($method) . '</p>';
        }

        if ('' !== $code) {
            echo '<p><strong>' . esc_html__('Tracking code', 'vereskala-login') . ':</strong> ' . esc_html($code) . '</p>';
        }
    }
}
