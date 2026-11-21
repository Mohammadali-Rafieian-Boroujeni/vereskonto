<?php
/**
 * Order invoice (فاکتور) + post-purchase feedback bar.
 *
 * Invoice: a clean printable RTL HTML invoice for any order, reachable
 * from a "Invoice" button on the My Account orders table (owner only,
 * validated by login + order key) and from a meta box on the admin order
 * screen (capability-gated). Dates are shown in both the Iranian solar
 * and Gregorian calendars.
 *
 * Feedback bar: a friendly strip on the order-received page inviting the
 * customer to review their purchase for club points (the "turn your
 * opinion into money" loop), which also primes them for the scheduled
 * review-request SMS.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.15.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Invoice
 */
final class VKL_Wc_Invoice
{
    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * @param VKL_Config $config Config.
     */
    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'account_button'], 10, 2);
        add_action('template_redirect', [$this, 'maybe_render']);
        add_action('add_meta_boxes', [$this, 'admin_metabox'], 10, 2);
        add_action('woocommerce_thankyou', [$this, 'feedback_bar'], 4);
    }

    /**
     * Invoice URL for an order.
     */
    public static function url(WC_Order $order): string
    {
        return add_query_arg(
            ['vkl_invoice' => $order->get_id(), 'key' => $order->get_order_key()],
            home_url('/')
        );
    }

    /**
     * "Invoice" button on the My Account orders table.
     *
     * @param array<string, array<string, string>> $actions Actions.
     * @param WC_Order                             $order   Order.
     *
     * @return array<string, array<string, string>>
     */
    public function account_button(array $actions, WC_Order $order): array
    {
        $actions['vkl-invoice'] = [
            'url'  => self::url($order),
            'name' => __('Invoice', 'vereskala-login'),
        ];

        return $actions;
    }

    /**
     * Meta box link on the admin order screen (classic and HPOS).
     *
     * @param string $screen Screen id.
     * @param mixed  $object Post or order object.
     */
    public function admin_metabox(string $screen, mixed $object): void
    {
        if (!in_array($screen, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        add_meta_box(
            'vkl_invoice_box',
            __('Vereskonto invoice', 'vereskala-login'),
            static function (mixed $box_object): void {
                $order = $box_object instanceof WC_Order ? $box_object : wc_get_order(is_object($box_object) ? (int) ($box_object->ID ?? 0) : 0);

                if (!$order instanceof WC_Order) {
                    return;
                }

                printf(
                    '<a class="button button-primary" target="_blank" rel="noopener" href="%s">%s</a>',
                    esc_url(self::url($order)),
                    esc_html__('Open printable invoice', 'vereskala-login')
                );
            },
            $screen,
            'side'
        );
    }

    /**
     * Render the invoice when requested and authorised.
     */
    public function maybe_render(): void
    {
        if (!isset($_GET['vkl_invoice'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $order = wc_get_order(absint($_GET['vkl_invoice'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$order instanceof WC_Order) {
            return;
        }

        $key     = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $is_own  = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
        $is_admin = current_user_can(VKL_Security::ADMIN_CAP);

        if (!$is_admin && !($is_own && hash_equals($order->get_order_key(), $key))) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $this->render($order);
        exit;
    }

    /**
     * Date in both calendars: "1405/01/01 — 2026-03-21".
     */
    private function dual_date(?WC_DateTime $date): string
    {
        if (null === $date) {
            return '—';
        }

        [$jy, $jm, $jd] = VKL_Wc_Account::g2j((int) $date->date('Y'), (int) $date->date('m'), (int) $date->date('d'));

        return sprintf('%04d/%02d/%02d — %s', $jy, $jm, $jd, $date->date('Y-m-d'));
    }

    /**
     * Output the printable invoice document.
     */
    private function render(WC_Order $order): void
    {
        $logo_id = (int) get_theme_mod('custom_logo');
        $logo    = vkl_image_url($logo_id, 'medium');
        ?>
<!DOCTYPE html>
<html dir="rtl" lang="fa-IR">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(/* translators: %s: order number. */ __('Invoice %s', 'vereskala-login'), $order->get_order_number())); ?></title>
    <style>
        body{font-family:Tahoma,'Segoe UI',sans-serif;color:#222;margin:0;background:#f2f2f2}
        .sheet{max-width:820px;margin:20px auto;background:#fff;border-radius:12px;padding:28px 32px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
        .head{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #157f3a;padding-bottom:14px;margin-bottom:18px}
        .head img{max-height:56px}
        h1{font-size:20px;color:#157f3a;margin:0}
        .meta,.parties{display:flex;flex-wrap:wrap;gap:18px;margin-bottom:16px;font-size:13px}
        .meta div,.parties div{background:#f4fbf6;border:1px solid #dceee2;border-radius:8px;padding:10px 14px;flex:1;min-width:200px;line-height:2}
        table{width:100%;border-collapse:collapse;font-size:13px;margin:10px 0}
        th{background:#157f3a;color:#fff;padding:9px 10px;text-align:right}
        td{border-bottom:1px solid #eee;padding:8px 10px}
        tbody tr:nth-child(even){background:#fafbfb}
        .totals td{border:0;padding:5px 10px}
        .totals tr:last-child td{border-top:2px solid #157f3a;font-weight:700;font-size:14px}
        .foot{margin-top:18px;font-size:12px;color:#666;border-top:1px dashed #ddd;padding-top:10px;display:flex;justify-content:space-between}
        .print{position:fixed;top:14px;inset-inline-start:14px}
        .print button{background:#157f3a;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:14px;cursor:pointer}
        @media print{.print{display:none}body{background:#fff}.sheet{box-shadow:none;margin:0;border-radius:0}}
    </style>
</head>
<body>
<div class="print"><button onclick="window.print()"><?php esc_html_e('Print', 'vereskala-login'); ?></button></div>
<div class="sheet">
    <div class="head">
        <div>
            <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
            <div style="font-size:12px;color:#666"><?php echo esc_html((string) home_url()); ?></div>
        </div>
        <?php if ('' !== $logo) : ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php endif; ?>
        <div style="text-align:left;font-size:13px">
            <strong><?php esc_html_e('Invoice', 'vereskala-login'); ?> #<?php echo esc_html($order->get_order_number()); ?></strong><br>
            <?php echo esc_html($this->dual_date($order->get_date_created())); ?>
        </div>
    </div>

    <div class="parties">
        <div>
            <strong><?php esc_html_e('Customer', 'vereskala-login'); ?></strong><br>
            <?php echo esc_html($order->get_formatted_billing_full_name()); ?><br>
            <?php echo esc_html((string) $order->get_billing_phone()); ?><br>
            <?php echo esc_html(trim($order->get_billing_state() . ' ' . $order->get_billing_city() . ' — ' . $order->get_billing_address_1())); ?>
        </div>
        <div>
            <strong><?php esc_html_e('Order details', 'vereskala-login'); ?></strong><br>
            <?php esc_html_e('Status', 'vereskala-login'); ?>: <?php echo esc_html(wc_get_order_status_name($order->get_status())); ?><br>
            <?php esc_html_e('Payment method', 'vereskala-login'); ?>: <?php echo esc_html((string) $order->get_payment_method_title()); ?><br>
            <?php $tracking = (string) $order->get_meta('_vkl_tracking_code'); ?>
            <?php if ('' !== $tracking) : ?>
                <?php esc_html_e('Tracking code', 'vereskala-login'); ?>: <?php echo esc_html($tracking); ?>
            <?php endif; ?>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th style="width:44%"><?php esc_html_e('Product', 'vereskala-login'); ?></th>
            <th><?php esc_html_e('Unit price', 'vereskala-login'); ?></th>
            <th><?php esc_html_e('Quantity', 'vereskala-login'); ?></th>
            <th><?php esc_html_e('Line total', 'vereskala-login'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($order->get_items() as $item) : ?>
            <?php
            $quantity = max(1, (int) $item->get_quantity());
            $line     = (float) $item->get_total();
            ?>
            <tr>
                <td><?php echo esc_html($item->get_name()); ?></td>
                <td><?php echo wp_kses_post(wc_price($line / $quantity, ['currency' => $order->get_currency()])); ?></td>
                <td><?php echo esc_html(number_format_i18n($quantity)); ?></td>
                <td><?php echo wp_kses_post(wc_price($line, ['currency' => $order->get_currency()])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <table class="totals" style="max-width:340px;margin-inline-start:auto">
        <?php foreach ($order->get_order_item_totals() as $total_row) : ?>
            <tr>
                <td><?php echo esc_html((string) $total_row['label']); ?></td>
                <td style="text-align:left"><?php echo wp_kses_post((string) $total_row['value']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <div class="foot">
        <span><?php esc_html_e('Thank you for shopping with us — free dispatch, genuine products, easy returns.', 'vereskala-login'); ?></span>
        <span>Vereskonto</span>
    </div>
</div>
</body>
</html>
        <?php
    }

    /**
     * Feedback bar on the order-received page.
     *
     * @param mixed $order_id Order id.
     */
    public function feedback_bar(mixed $order_id): void
    {
        if (!$this->config->get_bool('feedback_bar_enabled', true)) {
            return;
        }

        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order) {
            return;
        }
        ?>
        <div dir="rtl" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;background:linear-gradient(135deg,#157f3a,#0e5f29);color:#fff;border-radius:12px;padding:16px 20px;margin:0 0 20px">
            <span style="font-size:22px">🎉</span>
            <span style="flex:1;min-width:220px;line-height:1.9">
                <strong><?php esc_html_e('Thanks for your order!', 'vereskala-login'); ?></strong><br>
                <?php esc_html_e('Once it arrives, turn your opinion into money: review your purchase and earn club points convertible to wallet credit. We will also text you a reminder.', 'vereskala-login'); ?>
            </span>
            <span style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?php echo esc_url(wc_get_account_endpoint_url(VKL_Survey_Account::ENDPOINT)); ?>"
                   style="background:#f5a623;color:#124e27;padding:9px 18px;border-radius:8px;font-weight:700;text-decoration:none">
                    <?php esc_html_e('Turn your opinion into money!', 'vereskala-login'); ?>
                </a>
                <a href="<?php echo esc_url(self::url($order)); ?>" target="_blank" rel="noopener"
                   style="background:rgba(255,255,255,.15);color:#fff;padding:9px 18px;border-radius:8px;text-decoration:none">
                    <?php esc_html_e('Invoice', 'vereskala-login'); ?>
                </a>
            </span>
        </div>
        <?php
    }
}
