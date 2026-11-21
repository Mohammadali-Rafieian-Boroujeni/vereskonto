<?php
/**
 * My Account dashboard — app-style redesign.
 *
 * Replaces WooCommerce's default dashboard with a mobile-app layout:
 * name header, three balance tiles, order-status tiles, a smart promo
 * banner, the endpoint menu as tappable rows, and a "your repeat
 * purchases" strip. Every section, its mobile order, colours, banner,
 * icons and texts are set under Settings › Customer dashboard
 * (VKL_Dash_Options).
 *
 * @package Vereskonto\Templates
 * @since   1.35.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$vkl_user_id = get_current_user_id();
$vkl_user    = wp_get_current_user();
$vkl_mobile  = (string) get_user_meta($vkl_user_id, 'billing_phone', true);
$vkl_opts    = VKL_Dash_Options::get();
$vkl_show    = $vkl_opts['show'];

$vkl_wallet = vkl()->has('wallet') ? (float) vkl('wallet')->balance($vkl_user_id) : 0.0;
$vkl_points = vkl()->has('loyalty.repository') ? (int) vkl('loyalty.repository')->balance($vkl_user_id) : 0;
$vkl_promo  = vkl()->has('wallet.promos') ? (float) vkl('wallet.promos')->active_balance($vkl_user_id) : 0.0;
$vkl_expiry = $vkl_promo > 0 && vkl()->has('wallet.promos') ? (string) vkl('wallet.promos')->next_expiry($vkl_user_id) : '';

$vkl_price = static function (float $amount): string {
    return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(number_format_i18n((int) $amount));
};

$vkl_count = static function (array $statuses) use ($vkl_user_id): int {
    if (!function_exists('wc_get_orders')) {
        return 0;
    }

    $result = wc_get_orders([
        'customer_id' => $vkl_user_id,
        'status'      => $statuses,
        'limit'       => 1,
        'paginate'    => true,
        'return'      => 'ids',
    ]);

    return (int) ($result->total ?? 0);
};

$vkl_orders_on = !empty($vkl_show['orders']);
$vkl_current   = $vkl_orders_on ? $vkl_count(['wc-pending', 'wc-processing', 'wc-on-hold']) : 0;
$vkl_delivered = $vkl_orders_on ? $vkl_count(['wc-completed']) : 0;
$vkl_returned  = $vkl_orders_on ? $vkl_count(['wc-refunded', 'wc-cancelled']) : 0;

$vkl_orders_url = wc_get_account_endpoint_url('orders');

// Endpoint menu rows (dashboard itself excluded; logout styled apart;
// rows the admin hid left out).
$vkl_menu     = function_exists('wc_get_account_menu_items') ? wc_get_account_menu_items() : [];
$vkl_all_menu = $vkl_menu;
$vkl_logout   = $vkl_menu['customer-logout'] ?? __('Log out', 'vereskala-login');
unset($vkl_menu['dashboard'], $vkl_menu['customer-logout']);

foreach ((array) $vkl_opts['hidden_rows'] as $vkl_slug) {
    unset($vkl_menu[$vkl_slug]);
}

// Balance tiles: each one only when switched on and its page exists.
$vkl_chev  = '<svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$vkl_tiles = [];

if (!empty($vkl_show['tile_wallet']) && class_exists('VKL_Wallet_Account') && isset($vkl_all_menu[VKL_Wallet_Account::ENDPOINT])) {
    $vkl_tiles[] = ['ico' => '👛', 'val' => $vkl_price($vkl_wallet), 'url' => wc_get_account_endpoint_url(VKL_Wallet_Account::ENDPOINT), 'link' => __('Top up', 'vereskala-login')];
}

if (!empty($vkl_show['tile_points']) && class_exists('VKL_Loyalty_Account') && isset($vkl_all_menu[VKL_Loyalty_Account::ENDPOINT])) {
    $vkl_tiles[] = ['ico' => '🏆', 'val' => esc_html(number_format_i18n($vkl_points)), 'url' => wc_get_account_endpoint_url(VKL_Loyalty_Account::ENDPOINT), 'link' => __('Customer club', 'vereskala-login')];
}

if (!empty($vkl_show['tile_credit']) && class_exists('VKL_Wallet_Account') && isset($vkl_all_menu[VKL_Wallet_Account::CREDIT_ENDPOINT])) {
    $vkl_tiles[] = ['ico' => '🛒', 'val' => esc_html__('Purchase credit', 'vereskala-login'), 'url' => wc_get_account_endpoint_url(VKL_Wallet_Account::CREDIT_ENDPOINT), 'link' => __('View & request', 'vereskala-login')];
}

if (empty($vkl_show['tiles'])) {
    $vkl_tiles = [];
}

$vkl_brand = (string) $vkl_opts['brand_color'];
$vkl_flag  = !empty($vkl_opts['flag']);
$vkl_two   = 'two' === $vkl_opts['layout'];
$vkl_order = (array) $vkl_opts['order'];

// Banner: the automatic one (festival credit, else referral) or the admin's own.
$vkl_banner_auto = !empty($vkl_show['banner']) && 'auto' === $vkl_opts['banner_mode'];

$vkl_icons = [
    'orders'          => '🛍',
    'returns'         => '↩️',
    'downloads'       => '⬇️',
    'edit-address'    => '📍',
    'edit-account'    => '👤',
    'wallet'          => '👛',
    'credit'          => '🛒',
    'club'            => '🏆',
    'surveys'         => '📋',
    'tickets'         => '🎫',
    'payment-methods' => '💳',
];
$vkl_icons = array_merge($vkl_icons, (array) $vkl_opts['icons']);

// Repeat purchases: this customer's most re-ordered products.
global $wpdb;

$vkl_repeat = empty($vkl_show['repeat']) ? [] : $wpdb->get_results($wpdb->prepare( // phpcs:ignore
    "SELECT pl.product_id, COUNT(DISTINCT pl.order_id) AS orders_count
     FROM {$wpdb->prefix}wc_order_product_lookup pl
     INNER JOIN {$wpdb->prefix}wc_customer_lookup cl ON cl.customer_id = pl.customer_id
     WHERE cl.user_id = %d
     GROUP BY pl.product_id
     ORDER BY orders_count DESC, MAX(pl.date_created) DESC
     LIMIT %d",
    $vkl_user_id,
    (int) $vkl_opts['repeat_limit']
), ARRAY_A);
?>
<div class="vkl-dash">
    <style>
        .vkl-dash{--vkl-green:<?php echo esc_html($vkl_brand); ?>;--vkl-line:#ececec;font-size:14px}
        .vkl-dash{display:flex;flex-direction:column}
        .vkl-side,.vkl-main{display:contents}
        .vkl-c-notice{order:<?php echo (int) $vkl_order['notice']; ?>}
        .vkl-c-head{order:<?php echo (int) $vkl_order['head']; ?>}
        .vkl-c-orders{order:<?php echo (int) $vkl_order['orders']; ?>}
        .vkl-banner{order:<?php echo (int) $vkl_order['banner']; ?>}
        .vkl-menu{order:<?php echo (int) $vkl_order['menu']; ?>}
        .vkl-c-repeat{order:<?php echo (int) $vkl_order['repeat']; ?>}
        <?php if ($vkl_two) : ?>
        @media (min-width:1025px){
            .vkl-dash{display:grid;grid-template-columns:390px minmax(0,1fr);gap:0 18px;max-width:1200px;align-items:start}
            .vkl-side{display:block;grid-column:1;grid-row:1;min-width:0}
            .vkl-main{display:block;grid-column:2;grid-row:1;min-width:0}
        }
        <?php endif; ?>
        .vkl-dash .vkl-c-notice{padding:14px 18px;line-height:1.9;border-inline-start:4px solid var(--vkl-green)}
        .vkl-dash .vkl-c-notice p{margin:0 0 6px}
        .vkl-dash .vkl-c-notice p:last-child{margin:0}
        .vkl-dash .vkl-head-greet{margin:0 0 2px;color:#8a8a8a;font-size:12.5px}
        .vkl-dash *{box-sizing:border-box}
        .vkl-dash .vkl-chev{vertical-align:middle;display:inline-block}
        .vkl-dash .vkl-card{background:#fff;border:1px solid var(--vkl-line);border-radius:16px;margin:0 0 14px;overflow:hidden}
        /* Flag on the profile card: green above, white field, red below.
           The .vkl-card shorthand would reset a plain .vkl-c-head border,
           so the stripe is declared at matching specificity here. */
        .vkl-dash .vkl-card.vkl-c-head{border-top:2px solid var(--vkl-green)}
        .vkl-dash .vkl-head{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:<?php echo $vkl_flag ? '2px solid #e5262c' : '1px solid var(--vkl-line)'; ?>}
        .vkl-dash .vkl-head-name{font-size:17px;font-weight:800;margin:0}
        .vkl-dash .vkl-head-mobile{color:#8a8a8a;font-size:12.5px;direction:ltr;display:inline-block}
        .vkl-dash .vkl-head-edit{margin-inline-start:auto;text-decoration:none;font-size:18px}
        .vkl-dash .vkl-tiles{display:grid;grid-template-columns:repeat(<?php echo max(1, count($vkl_tiles)); ?>,1fr)}
        .vkl-dash .vkl-tile{padding:14px 10px;text-align:center;border-inline-start:1px solid var(--vkl-line)}
        .vkl-dash .vkl-tile:first-child{border-inline-start:0}
        .vkl-dash .vkl-tile-ico{font-size:26px;line-height:1}
        .vkl-dash .vkl-tile-val{font-weight:800;margin:6px 0 2px;color:#222}
        .vkl-dash .vkl-tile-val .woocommerce-Price-amount{font-weight:800}
        .vkl-dash .vkl-tile a{color:var(--vkl-green);font-size:12px;text-decoration:none;font-weight:600;white-space:nowrap;display:inline-flex;align-items:center;gap:4px}
        .vkl-dash .vkl-sec{display:flex;align-items:center;justify-content:space-between;padding:14px 18px 4px}
        /* Flag rule under each heading: green segment, a clear white gap,
           then red — and the theme's vertical accent bar is neutralised. */
        .vkl-dash .vkl-sec h3{margin:0;font-size:15px;padding:0 0 10px;position:relative;border:0 !important;background:none !important}
        .vkl-dash .vkl-sec h3::before{content:'';position:absolute;bottom:0;inset-inline-start:0;width:36%;height:3px;background:var(--vkl-green);border-radius:3px}
        <?php if ($vkl_flag) : ?>
        .vkl-dash .vkl-sec h3::after{content:'';position:absolute;bottom:0;inset-inline-start:calc(36% + 7px);inset-inline-end:0;height:3px;background:#e5262c;border-radius:3px}
        <?php endif; ?>
        .vkl-dash .vkl-sec a{color:var(--vkl-green);text-decoration:none;font-size:13px;font-weight:600}
        .vkl-dash .vkl-status{display:grid;grid-template-columns:repeat(3,1fr);padding:8px 0 14px}
        .vkl-dash .vkl-status a{display:flex;flex-direction:column;align-items:center;gap:6px;text-decoration:none;color:#333;border-inline-start:1px solid var(--vkl-line);padding:10px 6px;position:relative}
        .vkl-dash .vkl-status a:first-child{border-inline-start:0}
        .vkl-dash .vkl-status .ico{font-size:30px;line-height:1;position:relative}
        .vkl-dash .vkl-status .badge{position:absolute;top:-6px;inset-inline-start:-12px;background:#fff;border:1px solid var(--vkl-line);border-radius:8px;font-size:10.5px;padding:0 5px;font-weight:700;color:#444;box-shadow:0 1px 3px rgba(0,0,0,.08)}
        .vkl-dash .vkl-banner{display:flex;align-items:center;gap:12px;background:#f4f1fb;border:0;padding:14px 18px}
        .vkl-dash .vkl-banner .em{font-size:30px}
        .vkl-dash .vkl-banner p{margin:0;font-size:13px;line-height:1.9}
        .vkl-dash .vkl-banner a{color:#7b3fa9;font-weight:700;text-decoration:none;font-size:13px;white-space:nowrap}
        .vkl-dash .vkl-menu a{display:flex;align-items:center;gap:12px;padding:14px 18px;color:#222;text-decoration:none;border-top:1px solid #f2f2f2;transition:background .12s}
        .vkl-dash .vkl-menu a:first-child{border-top:0}
        .vkl-dash .vkl-menu a:hover{background:#fafafa}
        .vkl-dash .vkl-menu .ico{font-size:19px;width:24px;text-align:center}
        .vkl-dash .vkl-menu .chev{margin-inline-start:auto;color:#bbb;font-size:16px}
        .vkl-dash .vkl-menu .out{color:#d92b31}
        .vkl-dash .vkl-menu .txt{display:flex;flex-direction:column;line-height:1.5}
        .vkl-dash .vkl-menu .txt small{font-size:12px;color:#8a8a8a;font-weight:400}
        .vkl-dash .vkl-repeat{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;gap:10px;padding:4px 14px 14px;scrollbar-width:thin}
        .vkl-dash .vkl-repeat::-webkit-scrollbar{height:6px}
        .vkl-dash .vkl-repeat::-webkit-scrollbar-thumb{background:#c9edd5;border-radius:99px}
        .vkl-dash .vkl-repeat .item{flex:0 0 160px !important;width:160px !important;max-width:160px !important;scroll-snap-align:start;background:#fff;border:1px solid #f0f0f0;border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:8px;text-align:center;box-sizing:border-box}
        .vkl-dash .vkl-rep-nav{display:flex;gap:6px;direction:ltr}
        .vkl-dash .vkl-rep-nav button{width:32px;height:32px;border-radius:50%;border:1px solid var(--vkl-line);background:#fff;color:var(--vkl-green);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0}
        .vkl-dash .vkl-rep-nav button:hover{background:#f1faf4}
        .vkl-dash .vkl-repeat .item img{width:100% !important;height:110px !important;max-height:110px !important;object-fit:contain;display:block}
        .vkl-dash .vkl-repeat .t{font-size:12.5px;line-height:1.7;color:#333;text-decoration:none;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:40px}
        .vkl-dash .vkl-repeat .p{font-weight:800;font-size:13px}
        .vkl-dash .vkl-repeat .add{border:1px solid var(--vkl-green);color:var(--vkl-green);border-radius:9px;padding:7px 0;font-size:12.5px;font-weight:700;text-decoration:none}
        .vkl-dash .vkl-repeat .add:hover{background:var(--vkl-green);color:#fff}
    </style>

    <div class="vkl-side">
    <?php if (!empty($vkl_show['notice']) && '' !== (string) $vkl_opts['notice']) : ?>
        <div class="vkl-card vkl-c-notice"><?php echo wp_kses_post(wpautop((string) $vkl_opts['notice'])); ?></div>
    <?php endif; ?>

    <?php if (!empty($vkl_show['head'])) : ?>
    <div class="vkl-card vkl-c-head">
        <div class="vkl-head">
            <div>
                <?php if ('' !== (string) $vkl_opts['greeting']) : ?>
                    <p class="vkl-head-greet"><?php echo esc_html(str_replace('{name}', $vkl_user->display_name, (string) $vkl_opts['greeting'])); ?></p>
                <?php endif; ?>
                <p class="vkl-head-name"><?php echo esc_html($vkl_user->display_name); ?></p>
                <?php if ('' !== $vkl_mobile) : ?>
                    <span class="vkl-head-mobile"><?php echo esc_html($vkl_mobile); ?></span>
                <?php endif; ?>
            </div>
            <a class="vkl-head-edit" href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>" title="<?php esc_attr_e('Edit account', 'vereskala-login'); ?>">✏️</a>
        </div>

        <?php if ($vkl_tiles) : ?>
        <div class="vkl-tiles" style="border-top:1px solid var(--vkl-line)">
            <?php foreach ($vkl_tiles as $vkl_tile) : ?>
            <div class="vkl-tile">
                <div class="vkl-tile-ico"><?php echo esc_html($vkl_tile['ico']); ?></div>
                <div class="vkl-tile-val"><?php echo $vkl_tile['val']; // phpcs:ignore -- escaped when built. ?></div>
                <a href="<?php echo esc_url($vkl_tile['url']); ?>"><?php echo esc_html($vkl_tile['link']); ?> <?php echo $vkl_chev; // phpcs:ignore -- static markup. ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($vkl_show['banner']) && 'custom' === $vkl_opts['banner_mode'] && '' !== (string) $vkl_opts['banner_text']) : ?>
        <div class="vkl-card vkl-banner">
            <?php if ('' !== (string) $vkl_opts['banner_emoji']) : ?><span class="em"><?php echo esc_html((string) $vkl_opts['banner_emoji']); ?></span><?php endif; ?>
            <p><?php echo esc_html((string) $vkl_opts['banner_text']); ?></p>
            <?php if ('' !== (string) $vkl_opts['banner_url'] && '' !== (string) $vkl_opts['banner_label']) : ?>
                <a href="<?php echo esc_url((string) $vkl_opts['banner_url']); ?>"><?php echo esc_html((string) $vkl_opts['banner_label']); ?> <?php echo $vkl_chev; // phpcs:ignore -- static markup. ?></a>
            <?php endif; ?>
        </div>
    <?php elseif ($vkl_banner_auto && $vkl_promo > 0) : ?>
        <div class="vkl-card vkl-banner">
            <span class="em">🎁</span>
            <p>
                <?php
                printf(
                    /* translators: 1: amount, 2: date. */
                    esc_html__('You have %1$s festival credit — it is spent automatically on your next order%2$s.', 'vereskala-login'),
                    '<strong>' . $vkl_price($vkl_promo) . '</strong>', // phpcs:ignore
                    '' !== $vkl_expiry ? esc_html(sprintf(/* translators: %s: date. */ __(' and expires on %s', 'vereskala-login'), $vkl_expiry)) : ''
                );
                ?>
            </p>
            <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Shop now', 'vereskala-login'); ?> <svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </div>
    <?php elseif ($vkl_banner_auto && vkl()->has('loyalty.referral') && vkl('loyalty.referral')->enabled()) : ?>
        <div class="vkl-card vkl-banner">
            <span class="em">🤝</span>
            <p><?php esc_html_e('Invite friends with your personal link — after their first purchase, your reward lands in your wallet automatically.', 'vereskala-login'); ?></p>
            <a href="<?php echo esc_url(wc_get_account_endpoint_url(VKL_Loyalty_Account::ENDPOINT)); ?>"><?php esc_html_e('Invite friends', 'vereskala-login'); ?> <svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </div>
    <?php endif; ?>

    <?php
    // One row per group (خریدهای من، کیف پول، …) that opens its first tab;
    // the group's other tabs are named under it and reached from the tab
    // bar at the top of each page. Rows from other plugins that are in no
    // group keep a row of their own.
    $vkl_rows = [];

    foreach (VKL_Frontend_Ui::account_groups() as $vkl_group) {
        $vkl_present = array_values(array_filter($vkl_group['items'], static fn($s) => isset($vkl_menu[$s])));

        if (!$vkl_present) {
            continue;
        }

        $vkl_rows[] = [
            'slug'  => $vkl_present[0],
            'label' => $vkl_group['label'],
            'sub'   => count($vkl_present) > 1 ? implode('، ', array_map(static fn($s) => $vkl_menu[$s], $vkl_present)) : '',
        ];

        foreach ($vkl_present as $vkl_slug) {
            unset($vkl_menu[$vkl_slug]);
        }
    }

    foreach ($vkl_menu as $vkl_slug => $vkl_label) {
        $vkl_rows[] = ['slug' => $vkl_slug, 'label' => $vkl_label, 'sub' => ''];
    }
    ?>
    <?php if (!empty($vkl_show['menu']) && ($vkl_rows || !empty($vkl_show['logout']))) : ?>
    <div class="vkl-card vkl-menu">
        <?php foreach ($vkl_rows as $vkl_row) : ?>
            <a href="<?php echo esc_url(wc_get_account_endpoint_url($vkl_row['slug'])); ?>">
                <span class="ico"><?php echo esc_html($vkl_icons[$vkl_row['slug']] ?? '📄'); ?></span>
                <span class="txt"><?php echo esc_html($vkl_row['label']); ?>
                    <?php if ('' !== $vkl_row['sub']) : ?><small><?php echo esc_html($vkl_row['sub']); ?></small><?php endif; ?></span>
                <span class="chev"><svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            </a>
        <?php endforeach; ?>
        <?php if (!empty($vkl_show['logout'])) : ?>
        <a class="out" href="<?php echo esc_url(wc_logout_url()); ?>">
            <span class="ico">🚪</span>
            <span class="out"><?php echo esc_html($vkl_logout); ?></span>
            <span class="chev"><svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div><!-- /side -->

    <div class="vkl-main">
    <?php if ($vkl_orders_on) : ?>
    <div class="vkl-card vkl-c-orders">
        <div class="vkl-sec">
            <h3><?php esc_html_e('My orders', 'vereskala-login'); ?></h3>
            <a href="<?php echo esc_url($vkl_orders_url); ?>"><?php esc_html_e('View all', 'vereskala-login'); ?> <svg class="vkl-chev" viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>
        </div>
        <div class="vkl-status">
            <a href="<?php echo esc_url($vkl_orders_url); ?>">
                <span class="ico">📦<?php if ($vkl_current > 0) : ?><span class="badge"><?php echo esc_html(number_format_i18n($vkl_current)); ?></span><?php endif; ?></span>
                <?php esc_html_e('In progress', 'vereskala-login'); ?>
            </a>
            <a href="<?php echo esc_url($vkl_orders_url); ?>">
                <span class="ico">✅<?php if ($vkl_delivered > 0) : ?><span class="badge"><?php echo esc_html(number_format_i18n($vkl_delivered)); ?></span><?php endif; ?></span>
                <?php esc_html_e('Delivered', 'vereskala-login'); ?>
            </a>
            <a href="<?php echo esc_url($vkl_orders_url); ?>">
                <span class="ico">↩️<?php if ($vkl_returned > 0) : ?><span class="badge"><?php echo esc_html(number_format_i18n($vkl_returned)); ?></span><?php endif; ?></span>
                <?php esc_html_e('Returned', 'vereskala-login'); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

<?php if (!empty($vkl_repeat)) : ?>
        <div class="vkl-card vkl-c-repeat">
            <div class="vkl-sec">
                <h3><?php echo '' !== (string) $vkl_opts['repeat_title'] ? esc_html((string) $vkl_opts['repeat_title']) : esc_html__('Your repeat purchases', 'vereskala-login'); ?></h3>
                <span class="vkl-rep-nav">
                    <button type="button" data-vkl-dir="start" aria-label="<?php esc_attr_e('Previous', 'vereskala-login'); ?>"><svg viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M7 1 2 6l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    <button type="button" data-vkl-dir="end" aria-label="<?php esc_attr_e('Next', 'vereskala-login'); ?>"><svg viewBox="0 0 8 12" width="9" height="12" aria-hidden="true"><path d="M1 1 6 6l-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                </span>
            </div>
            <div class="vkl-repeat" id="vkl-repeat-strip">
                <?php foreach ($vkl_repeat as $vkl_row) : ?>
                    <?php
                    $vkl_product = function_exists('wc_get_product') ? wc_get_product((int) $vkl_row['product_id']) : null;

                    if (!$vkl_product || !$vkl_product->is_purchasable()) {
                        continue;
                    }
                    ?>
                    <div class="item">
                        <a href="<?php echo esc_url((string) $vkl_product->get_permalink()); ?>">
                            <?php echo $vkl_product->get_image('woocommerce_thumbnail'); // phpcs:ignore ?>
                        </a>
                        <a class="t" href="<?php echo esc_url((string) $vkl_product->get_permalink()); ?>"><?php echo esc_html($vkl_product->get_name()); ?></a>
                        <span class="p"><?php echo wp_kses_post((string) $vkl_product->get_price_html()); ?></span>
                        <?php if ($vkl_product->is_in_stock() && $vkl_product->is_type('simple')) : ?>
                            <a class="add" href="<?php echo esc_url(add_query_arg('add-to-cart', $vkl_product->get_id(), wc_get_cart_url())); ?>">🛒 <?php esc_html_e('Add to cart', 'vereskala-login'); ?></a>
                        <?php else : ?>
                            <a class="add" href="<?php echo esc_url((string) $vkl_product->get_permalink()); ?>"><?php esc_html_e('View product', 'vereskala-login'); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <script>
            (function () {
                var strip = document.getElementById('vkl-repeat-strip');
                if (!strip) { return; }
                document.querySelectorAll('[data-vkl-dir]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var dir = btn.getAttribute('data-vkl-dir'); // 'start' = left arrow, 'end' = right arrow
                        // scrollBy() is defined in PHYSICAL pixels, so a
                        // negative value moves the view left in both LTR and
                        // RTL. No direction flip needed — that flip was the bug.
                        var sign = (dir === 'start') ? -1 : 1;
                        var step = strip.clientWidth * 0.8 * sign;
                        strip.scrollBy({ left: step, behavior: 'smooth' });
                    });
                });
            })();
            </script>
        </div>
    <?php endif; ?>
</div><!-- /main2 -->
</div>
