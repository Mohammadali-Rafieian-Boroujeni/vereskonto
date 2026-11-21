<?php
/**
 * Customer-facing return requests — a "Request return" form on the
 * single order view (My Account and the order-received page), letting
 * the customer pick which received items — and how many of each — they
 * want to send back, instead of only being able to act on the whole
 * order.
 *
 * @package Vereskonto\Modules\Returns
 * @since   2.9.20
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Returns_Account
 */
final class VKL_Returns_Account
{
    public const ENDPOINT = 'returns';

    private const NONCE = 'vkl_return_front';

    private VKL_Returns_Repository $repository;

    private VKL_Config $config;

    public function __construct(VKL_Returns_Repository $repository, VKL_Config $config)
    {
        $this->repository = $repository;
        $this->config      = $config;
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
        });
        add_filter('woocommerce_get_query_vars', [$this, 'query_var']);
        add_filter('woocommerce_account_menu_items', [$this, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render_account_tab']);

        add_action('woocommerce_order_details_after_order_table', [$this, 'render'], 20);
        add_action('template_redirect', [$this, 'handle_post']);
    }

    /** @param array<string, string> $vars */
    public function query_var(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /**
     * Add the "Return requests" tab to My Account, right before Logout —
     * same placement every other custom tab in this plugin uses.
     *
     * @param array<string, string> $items
     *
     * @return array<string, string>
     */
    public function menu_item(array $items): array
    {
        if (!$this->config->get_bool('returns_enabled', false)) {
            return $items;
        }

        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT] = 'درخواست برگشت از خرید';

        if (null !== $logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /**
     * My Account overview: every return request the customer has ever
     * opened, across all their orders, each linking back to its order.
     */
    public function render_account_tab(): void
    {
        if (!$this->config->get_bool('returns_enabled', false)) {
            echo '<p>این امکان در حال حاضر فعال نیست.</p>';

            return;
        }

        $requests = $this->repository->for_user(get_current_user_id());

        echo '<h3>درخواست‌های برگشت از خرید</h3>';

        if ([] === $requests) {
            echo '<p>هنوز درخواست برگشت از خریدی ثبت نکرده‌اید.</p>';

            return;
        }

        $this->render_history($requests, true);
    }

    /**
     * Returnable items on this order for the current viewer: purchased
     * qty minus whatever is already covered by a pending/approved
     * request, only for products (not fees/shipping), only above zero.
     *
     * @return array<int, array{item: WC_Order_Item_Product, purchased: int, already: int, remaining: int}>
     */
    private function returnable_items(WC_Order $order): array
    {
        $rows = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }

            $purchased = (int) $item->get_quantity();
            $already   = $this->repository->requested_qty_for_item($item->get_id());
            $remaining = max(0, $purchased - $already);

            if ($remaining <= 0) {
                continue;
            }

            $rows[] = [
                'item'      => $item,
                'purchased' => $purchased,
                'already'   => $already,
                'remaining' => $remaining,
            ];
        }

        return $rows;
    }

    /**
     * Whether the order is still inside the admin-set return window.
     * 0 days = no limit.
     */
    private function within_window(WC_Order $order): bool
    {
        $days = $this->config->get_int('returns_window_days', 7);

        if ($days <= 0) {
            return true;
        }

        $since = $order->get_date_completed() ?: $order->get_date_paid() ?: $order->get_date_created();

        if (!$since) {
            return true;
        }

        return $since->getTimestamp() >= (time() - $days * DAY_IN_SECONDS);
    }

    /**
     * The "Request return" form + this order's request history.
     */
    public function render(WC_Order $order): void
    {
        if (!$this->config->get_bool('returns_enabled', false)) {
            return;
        }

        if (get_current_user_id() <= 0 || (int) $order->get_customer_id() !== get_current_user_id()) {
            return;
        }

        $requests = $this->repository->for_order($order->get_id());

        if ([] !== $requests) {
            $this->render_history($requests);
        }

        if (VKL_Returns_Repository::STATUS_PENDING === ($requests[0]['status'] ?? '')) {
            // One open request at a time — a second one would just
            // duplicate items the shop is already reviewing.
            return;
        }

        if ('completed' !== $order->get_status() || !$this->within_window($order)) {
            return;
        }

        $returnable = $this->returnable_items($order);

        if ([] === $returnable) {
            return;
        }
        ?>
        <h3>درخواست برگشت از خرید</h3>
        <p class="description">کالاهایی که می‌خواهید برگردانید را با تعداد مشخص کنید — نیازی به برگرداندن کل سفارش نیست.</p>

        <form method="post">
            <input type="hidden" name="vkl_return_action" value="request">
            <input type="hidden" name="vkl_order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>">
            <?php wp_nonce_field(self::NONCE, '_vkl_return_nonce'); ?>

            <table class="woocommerce-table shop_table" style="margin-bottom:12px">
                <thead><tr>
                    <th>کالا</th>
                    <th style="width:90px">تعداد خریداری‌شده</th>
                    <th style="width:140px">تعداد برای برگشت از خرید</th>
                </tr></thead>
                <tbody>
                <?php foreach ($returnable as $row) : ?>
                    <?php $vkl_item = $row['item']; ?>
                    <tr>
                        <td><?php echo esc_html($vkl_item->get_name()); ?></td>
                        <td><?php echo esc_html((string) $row['purchased']); ?></td>
                        <td>
                            <input type="number" min="0" max="<?php echo (int) $row['remaining']; ?>" step="1"
                                   style="width:80px"
                                   name="vkl_return_qty[<?php echo (int) $vkl_item->get_id(); ?>]" value="0">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="form-row">
                <label>دلیل برگشت از خرید (اختیاری)</label>
                <textarea class="input-text" name="vkl_return_reason" rows="3"></textarea>
            </p>
            <p class="form-row">
                <button type="submit" class="button">ثبت درخواست برگشت از خرید</button>
            </p>
        </form>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     * @param bool                              $show_order Show which order each request belongs to (the
     *                                                       cross-order My Account tab needs it; the
     *                                                       per-order view does not).
     */
    private function render_history(array $requests, bool $show_order = false): void
    {
        $labels = [
            VKL_Returns_Repository::STATUS_PENDING  => 'در انتظار بررسی',
            VKL_Returns_Repository::STATUS_APPROVED => 'تأیید شده',
            VKL_Returns_Repository::STATUS_REJECTED => 'رد شده',
        ];
        $colors = [
            VKL_Returns_Repository::STATUS_PENDING  => '#9a6a10',
            VKL_Returns_Repository::STATUS_APPROVED => '#157f3a',
            VKL_Returns_Repository::STATUS_REJECTED => '#b3282e',
        ];

        if (!$show_order) {
            echo '<h3>درخواست‌های برگشت از خرید این سفارش</h3>';
        }
        ?>
        <?php foreach ($requests as $request) : ?>
            <?php $status = (string) $request['status']; ?>
            <div style="border:1px solid #ddd;border-radius:6px;padding:10px 14px;margin-bottom:10px">
                <strong style="color:<?php echo esc_attr($colors[$status] ?? '#556'); ?>">
                    <?php echo esc_html($labels[$status] ?? $status); ?>
                </strong>
                <?php if ($show_order) : ?>
                    <?php $vkl_ro = function_exists('wc_get_order') ? wc_get_order((int) $request['order_id']) : false; ?>
                    <?php if ($vkl_ro instanceof WC_Order) : ?>
                        <a href="<?php echo esc_url($vkl_ro->get_view_order_url()); ?>" class="description" style="margin-inline-start:8px">
                            سفارش #<?php echo esc_html((string) $vkl_ro->get_order_number()); ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="description" style="margin-inline-start:8px">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $request['created_at']))); ?>
                </span>
                <ul style="margin:8px 0 0">
                    <?php foreach ((array) $request['items'] as $vkl_ri) : ?>
                        <?php $vkl_product = wc_get_product((int) $vkl_ri['product_id']); ?>
                        <li>• <?php echo esc_html($vkl_product ? $vkl_product->get_name() : ('#' . (int) $vkl_ri['product_id'])); ?>
                            × <?php echo esc_html((string) (int) $vkl_ri['qty']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ('' !== (string) $request['reason']) : ?>
                    <p style="margin:8px 0 0"><strong>دلیل شما:</strong> <?php echo esc_html((string) $request['reason']); ?></p>
                <?php endif; ?>
                <?php if (VKL_Returns_Repository::STATUS_REJECTED === $status && '' !== (string) $request['admin_note']) : ?>
                    <p style="margin:8px 0 0"><strong>توضیح فروشگاه:</strong> <?php echo esc_html((string) $request['admin_note']); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Handle the "request return" POST, revalidating every quantity
     * server-side against what is actually still returnable — the
     * client-side max= attribute is a convenience, not a guarantee.
     */
    public function handle_post(): void
    {
        if (!is_user_logged_in() || empty($_POST['vkl_return_action']) || 'request' !== $_POST['vkl_return_action']) {
            return;
        }

        $nonce = isset($_POST['_vkl_return_nonce']) ? sanitize_text_field(wp_unslash($_POST['_vkl_return_nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }

        if (!$this->config->get_bool('returns_enabled', false)) {
            return;
        }

        $user_id  = get_current_user_id();
        $order_id = (int) ($_POST['vkl_order_id'] ?? 0);
        $order    = function_exists('wc_get_order') ? wc_get_order($order_id) : false;

        if (!$order instanceof WC_Order
            || (int) $order->get_customer_id() !== $user_id
            || 'completed' !== $order->get_status()
            || !$this->within_window($order)
        ) {
            return;
        }

        // A second request cannot open while one is still pending.
        $existing = $this->repository->for_order($order_id);

        if (VKL_Returns_Repository::STATUS_PENDING === ($existing[0]['status'] ?? '')) {
            return;
        }

        $reason  = isset($_POST['vkl_return_reason']) ? sanitize_textarea_field(wp_unslash($_POST['vkl_return_reason'])) : '';
        $posted  = isset($_POST['vkl_return_qty']) && is_array($_POST['vkl_return_qty']) ? wp_unslash($_POST['vkl_return_qty']) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

        $returnable = [];

        foreach ($this->returnable_items($order) as $row) {
            $returnable[$row['item']->get_id()] = $row;
        }

        $items = [];

        foreach ($posted as $item_id => $qty) {
            $item_id = (int) $item_id;
            $qty     = absint($qty);

            if ($qty <= 0 || !isset($returnable[$item_id])) {
                continue;
            }

            // Never trust the posted quantity past what is genuinely
            // still returnable for this item.
            $qty = min($qty, $returnable[$item_id]['remaining']);

            $items[] = [
                'order_item_id' => $item_id,
                'product_id'    => $returnable[$item_id]['item']->get_product_id(),
                'qty'           => $qty,
            ];
        }

        if ([] === $items) {
            wc_add_notice('حداقل یک کالا را با تعداد بیشتر از صفر انتخاب کنید.', 'error');
            wp_safe_redirect($order->get_view_order_url());
            exit;
        }

        $return_id = $this->repository->create($order_id, $user_id, $reason, $items);

        if ($return_id > 0) {
            vkl_dispatch('return.requested', [
                'return_id' => $return_id,
                'order_id'  => $order_id,
                'user_id'   => $user_id,
            ]);

            wc_add_notice('درخواست برگشت از خرید شما ثبت شد و به‌زودی بررسی می‌شود.');
        }

        wp_safe_redirect($order->get_view_order_url());
        exit;
    }
}
