<?php
/**
 * Customers — one place to find a customer and see everything about
 * them: orders, wallet, installments, club points, tickets, returns and
 * the messages they were sent. Before, each of these lived on its own
 * screen and had to be searched separately.
 *
 * Read-only except for the wallet credit/debit modal, which posts to the
 * existing vkl_wallet_adjust handler.
 *
 * @package Vereskonto\Core
 * @since   2.12.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Customers_Admin
 */
final class VKL_Customers_Admin
{
    public const PAGE = 'vkl-customers';

    private const PER_PAGE = 20;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
    }

    public function menu(): void
    {
        add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Customers', 'vereskala-login'),
            __('Customers', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            self::PAGE,
            [$this, 'render']
        );
    }

    private static function service(string $id): ?object
    {
        if (!function_exists('vkl') || !vkl()->has($id)) {
            return null;
        }

        $service = vkl($id);

        return is_object($service) ? $service : null;
    }

    /**
     * Call a service method; $fallback when the module is off or it fails.
     *
     * @param mixed $fallback
     * @return mixed
     */
    private static function ask(string $service, string $method, array $args, $fallback)
    {
        try {
            $s = self::service($service);

            return $s && is_callable([$s, $method]) ? $s->{$method}(...$args) : $fallback;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    public static function profile_url(int $user_id, string $view = ''): string
    {
        return add_query_arg(array_filter(['page' => self::PAGE, 'customer' => $user_id, 'view' => $view]), admin_url('admin.php'));
    }

    private static function price(float $amount): string
    {
        return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(number_format_i18n($amount));
    }

    private static function date(string $mysql): string
    {
        return '' === $mysql ? '—' : esc_html(mysql2date((string) get_option('date_format', 'Y-m-d'), $mysql));
    }

    /**
     * Customers matching a search (name, email, login or mobile).
     *
     * @return array{users: array<int, WP_User>, total: int}
     */
    public function search(string $q, int $page): array
    {
        $args = [
            'number'       => self::PER_PAGE,
            'paged'        => max(1, $page),
            'orderby'      => 'registered',
            'order'        => 'DESC',
            'role__not_in' => ['administrator'],
            'count_total'  => true,
        ];

        if ('' !== $q) {
            $digits = preg_replace('/\D+/', '', $q);

            if (preg_match('/^[\d\s+()\-]+$/', $q) && strlen((string) $digits) >= 4) {
                // Looks like a phone number: match the stored mobile.
                $args['meta_query'] = [['key' => 'billing_phone', 'value' => ltrim((string) $digits, '0'), 'compare' => 'LIKE']]; // phpcs:ignore WordPress.DB.SlowDBQuery
            } else {
                $args['search']         = '*' . $q . '*';
                $args['search_columns'] = ['user_login', 'user_email', 'display_name', 'user_nicename'];
            }
        }

        $query = new WP_User_Query($args);

        return ['users' => (array) $query->get_results(), 'total' => (int) $query->get_total()];
    }

    /**
     * Headline figures for one customer.
     *
     * @return array<string, float|int|null>
     */
    public function summary(int $user_id): array
    {
        $debt = null;
        $plans = self::ask('wallet.installments', 'open_plans', [$user_id], null);

        if (is_array($plans)) {
            $debt = 0.0;

            foreach ($plans as $plan) {
                $debt += (float) self::ask('wallet.installments', 'remaining', [$plan], 0.0);
            }
        }

        return [
            'wallet'  => self::ask('wallet', 'balance', [$user_id], null),
            'festival'=> self::ask('wallet.promos', 'total_active', [$user_id], null),
            'points'  => self::ask('loyalty.repository', 'balance', [$user_id], null),
            'credit'  => self::ask('wallet.installments', 'available_credit', [$user_id], null),
            'debt'    => $debt,
            'orders'  => function_exists('wc_get_customer_order_count') ? (int) wc_get_customer_order_count($user_id) : null,
            'spent'   => function_exists('wc_get_customer_total_spent') ? (float) wc_get_customer_total_spent($user_id) : null,
        ];
    }

    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }

        $customer = isset($_GET['customer']) ? absint($_GET['customer']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $user     = $customer > 0 ? get_userdata($customer) : false;

        echo '<div class="wrap vkl-customers">';
        $this->styles();

        if ($user instanceof WP_User) {
            $this->render_profile($user);
        } else {
            $this->render_list();
        }

        echo '</div>';
    }

    private function styles(): void
    {
        ?>
        <style>
            .vkl-customers .vkl-search{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 14px}
            .vkl-customers .vkl-search input[type=search]{min-width:300px}
            .vkl-customers .vkl-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin:12px 0 4px;max-width:1100px}
            .vkl-customers .vkl-tile{background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px}
            .vkl-customers .vkl-tile .n{display:block;font-size:18px;font-weight:700;font-variant-numeric:tabular-nums}
            .vkl-customers .vkl-tile .l{font-size:12px;color:#50575e}
            .vkl-customers .vkl-tile.is-alert{border-inline-start:4px solid #d63638}
            .vkl-customers .vkl-who{display:flex;flex-wrap:wrap;gap:6px 18px;align-items:baseline;color:#50575e}
            .vkl-customers .vkl-who strong{color:#1d2327;font-size:15px}
            .vkl-customers .vkl-back{display:inline-block;margin:8px 0 2px;text-decoration:none}
            .vkl-customers td.num{font-variant-numeric:tabular-nums}
            .vkl-customers .vkl-pill{display:inline-block;padding:0 8px;border-radius:999px;background:#f0f0f1;font-size:12px}
        </style>
        <?php
    }

    private function render_list(): void
    {
        $q    = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $res  = $this->search($q, $page);
        $pages = (int) ceil($res['total'] / self::PER_PAGE);
        ?>
        <h1><?php esc_html_e('Customers', 'vereskala-login'); ?></h1>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="vkl-search" role="search">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label class="screen-reader-text" for="vkl-customer-q"><?php esc_html_e('Search customers', 'vereskala-login'); ?></label>
            <input type="search" id="vkl-customer-q" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Name, mobile or email…', 'vereskala-login'); ?>">
            <button class="button button-primary"><?php esc_html_e('Search', 'vereskala-login'); ?></button>
            <?php if ('' !== $q) : ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>"><?php esc_html_e('Clear', 'vereskala-login'); ?></a>
            <?php endif; ?>
        </form>

        <table class="widefat striped" style="max-width:1100px">
            <thead><tr>
                <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Mobile', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Orders', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Wallet', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Points', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Member since', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php if ([] === $res['users']) : ?>
                <tr><td colspan="6"><?php echo '' !== $q ? esc_html__('No customer matches this search.', 'vereskala-login') : esc_html__('No customers yet.', 'vereskala-login'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($res['users'] as $u) : ?>
                <?php
                $uid    = (int) $u->ID;
                $wallet = self::ask('wallet', 'balance', [$uid], null);
                $points = self::ask('loyalty.repository', 'balance', [$uid], null);
                ?>
                <tr>
                    <td><a href="<?php echo esc_url(self::profile_url($uid)); ?>"><strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong></a></td>
                    <td dir="ltr"><?php echo esc_html((string) get_user_meta($uid, 'billing_phone', true)); ?></td>
                    <td class="num"><?php echo function_exists('wc_get_customer_order_count') ? esc_html(number_format_i18n((int) wc_get_customer_order_count($uid))) : '—'; ?></td>
                    <td class="num"><?php echo null !== $wallet ? self::price((float) $wallet) : '—'; // phpcs:ignore ?></td>
                    <td class="num"><?php echo null !== $points ? esc_html(number_format_i18n((int) $points)) : '—'; ?></td>
                    <td><?php echo self::date((string) $u->user_registered); // phpcs:ignore ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($pages > 1) : ?>
            <p class="vkl-pages" style="display:flex;gap:6px;align-items:center;margin-top:10px">
                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::PAGE, 's' => $q, 'paged' => $page - 1], admin_url('admin.php'))); ?>">‹ <?php esc_html_e('Previous', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <span class="description"><?php echo esc_html(sprintf('%s / %s', number_format_i18n($page), number_format_i18n($pages))); ?></span>
                <?php if ($page < $pages) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => self::PAGE, 's' => $q, 'paged' => $page + 1], admin_url('admin.php'))); ?>"><?php esc_html_e('Next', 'vereskala-login'); ?> ›</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php
    }

    private function render_profile(WP_User $user): void
    {
        $uid    = (int) $user->ID;
        $mobile = (string) get_user_meta($uid, 'billing_phone', true);
        $sum    = $this->summary($uid);
        $views  = [
            'overview' => __('Overview', 'vereskala-login'),
            'orders'   => __('Orders', 'vereskala-login'),
            'wallet'   => __('Wallet', 'vereskala-login'),
            'credit'   => __('Installments & credit', 'vereskala-login'),
            'club'     => __('Customer club', 'vereskala-login'),
            'support'  => __('Tickets & returns', 'vereskala-login'),
            'messages' => __('Messages', 'vereskala-login'),
        ];
        $view = VKL_Admin_Tabs::current_view($views);
        ?>
        <a class="vkl-back" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>">→ <?php esc_html_e('All customers', 'vereskala-login'); ?></a>
        <h1><?php echo esc_html($user->display_name ?: $user->user_login); ?></h1>
        <p class="vkl-who">
            <?php if ('' !== $mobile) : ?><span dir="ltr"><?php echo esc_html($mobile); ?></span><?php endif; ?>
            <span><?php echo esc_html($user->user_email); ?></span>
            <span><?php esc_html_e('Member since', 'vereskala-login'); ?> <?php echo self::date((string) $user->user_registered); // phpcs:ignore ?></span>
            <a href="<?php echo esc_url(get_edit_user_link($uid)); ?>"><?php esc_html_e('Edit user', 'vereskala-login'); ?></a>
        </p>

        <div class="vkl-tiles">
            <?php
            $tiles = [
                ['wallet', __('Wallet balance', 'vereskala-login'), 'price', false],
                ['festival', __('Festival credit', 'vereskala-login'), 'price', false],
                ['credit', __('Available purchase credit', 'vereskala-login'), 'price', false],
                ['debt', __('Open installment debt', 'vereskala-login'), 'price', (float) ($sum['debt'] ?? 0) > 0],
                ['points', __('Club points', 'vereskala-login'), 'int', false],
                ['orders', __('Orders', 'vereskala-login'), 'int', false],
                ['spent', __('Total spent', 'vereskala-login'), 'price', false],
            ];

            foreach ($tiles as [$key, $label, $kind, $alert]) :
                if (null === $sum[$key]) {
                    continue;
                }
                ?>
                <div class="vkl-tile<?php echo $alert ? ' is-alert' : ''; ?>">
                    <span class="n"><?php echo 'price' === $kind ? self::price((float) $sum[$key]) : esc_html(number_format_i18n((int) $sum[$key])); // phpcs:ignore ?></span>
                    <span class="l"><?php echo esc_html($label); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="vkl-toolbar" style="margin-top:12px">
            <?php if (null !== $sum['wallet']) : ?>
                <?php VKL_Admin_Tabs::modal_button('vkl-modal-cust-adjust', __('Credit / debit wallet', 'vereskala-login'), 'button'); ?>
            <?php endif; ?>
            <a class="button" href="<?php echo esc_url(add_query_arg(['_customer_user' => $uid], VKL_Today_Admin::orders_url())); ?>"><?php esc_html_e('Orders in WooCommerce', 'vereskala-login'); ?></a>
        </div>

        <?php if (null !== $sum['wallet']) : ?>
            <?php VKL_Admin_Tabs::modal_start('vkl-modal-cust-adjust', __('Credit / debit wallet', 'vereskala-login')); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_wallet_adjust">
                <input type="hidden" name="vkl_user_id" value="<?php echo esc_attr((string) $uid); ?>">
                <?php vkl('security')->admin_nonce_field(); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="vkl-ca-amount"><?php esc_html_e('Amount', 'vereskala-login'); ?></label></th>
                        <td><input type="number" min="0" step="any" id="vkl-ca-amount" name="vkl_amount" required></td></tr>
                    <tr><th scope="row"><label for="vkl-ca-dir"><?php esc_html_e('Direction', 'vereskala-login'); ?></label></th>
                        <td><select id="vkl-ca-dir" name="vkl_direction">
                            <option value="credit"><?php esc_html_e('Credit (add funds)', 'vereskala-login'); ?></option>
                            <option value="debit"><?php esc_html_e('Debit (remove funds)', 'vereskala-login'); ?></option>
                        </select></td></tr>
                    <tr><th scope="row"><label for="vkl-ca-note"><?php esc_html_e('Note', 'vereskala-login'); ?></label></th>
                        <td><input type="text" class="regular-text" id="vkl-ca-note" name="vkl_note"></td></tr>
                </table>
                <?php submit_button(__('Apply', 'vereskala-login')); ?>
            </form>
            <?php VKL_Admin_Tabs::modal_end(); ?>
        <?php endif; ?>

        <?php if (isset($_GET['done'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('Wallet updated.', 'vereskala-login'); ?></p></div>
        <?php elseif (isset($_GET['failed'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Wallet update failed (check user id and balance).', 'vereskala-login'); ?></p></div>
        <?php endif; ?>

        <?php
        VKL_Admin_Tabs::render_views(self::PAGE, $views, $view, ['customer' => $uid]);

        switch ($view) {
            case 'orders':
                $this->orders_table($uid, 30);
                break;
            case 'wallet':
                $this->wallet_table($uid, 50);
                break;
            case 'credit':
                $this->plans_table($uid);
                break;
            case 'club':
                $this->points_table($uid, 50);
                break;
            case 'support':
                $this->tickets_table($uid);
                $this->returns_table($uid);
                break;
            case 'messages':
                $this->messages_table($mobile);
                break;
            default:
                echo '<h2 class="title">' . esc_html__('Latest orders', 'vereskala-login') . '</h2>';
                $this->orders_table($uid, 5);
                echo '<h2 class="title">' . esc_html__('Latest wallet transactions', 'vereskala-login') . '</h2>';
                $this->wallet_table($uid, 5);
        }
    }

    /** Small helper: an empty-state row or the rows. */
    private static function table(array $head, array $rows, string $empty): void
    {
        echo '<table class="widefat striped" style="max-width:1100px;margin-bottom:16px"><thead><tr>';

        foreach ($head as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }

        echo '</tr></thead><tbody>';

        if ([] === $rows) {
            echo '<tr><td colspan="' . count($head) . '">' . esc_html($empty) . '</td></tr>';
        }

        foreach ($rows as $cells) {
            echo '<tr>';

            foreach ($cells as $cell) {
                echo '<td>' . $cell . '</td>'; // phpcs:ignore -- cells are escaped by the callers.
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function orders_table(int $uid, int $limit): void
    {
        $orders = function_exists('wc_get_orders') ? (array) wc_get_orders(['customer_id' => $uid, 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC']) : [];
        $rows   = [];

        foreach ($orders as $o) {
            if (!$o instanceof WC_Order) {
                continue;
            }

            $rows[] = [
                '<a href="' . esc_url((string) $o->get_edit_order_url()) . '">#' . esc_html((string) $o->get_order_number()) . '</a>',
                $o->get_date_created() ? esc_html($o->get_date_created()->date_i18n((string) get_option('date_format', 'Y-m-d'))) : '—',
                '<span class="vkl-pill">' . esc_html(wc_get_order_status_name($o->get_status())) . '</span>',
                self::price((float) $o->get_total()),
            ];
        }

        self::table([__('Order', 'vereskala-login'), __('Date', 'vereskala-login'), __('Status', 'vereskala-login'), __('Total', 'vereskala-login')], $rows, __('No orders yet.', 'vereskala-login'));
    }

    private function wallet_table(int $uid, int $limit): void
    {
        $rows = [];

        foreach ((array) self::ask('wallet.repository', 'latest_for_user', [$uid, $limit], []) as $r) {
            $rows[] = [
                self::price((float) ($r['amount'] ?? 0)),
                esc_html((string) ($r['reason'] ?? '')),
                esc_html((string) ($r['note'] ?? $r['reference'] ?? '')),
                self::date((string) ($r['created_at'] ?? '')),
            ];
        }

        self::table([__('Amount', 'vereskala-login'), __('Reason', 'vereskala-login'), __('Note', 'vereskala-login'), __('Date', 'vereskala-login')], $rows, __('No wallet transactions.', 'vereskala-login'));
    }

    private function plans_table(int $uid): void
    {
        $rows = [];

        foreach ((array) self::ask('wallet.installments', 'all_plans', [$uid], []) as $p) {
            $rows[] = [
                esc_html((string) ($p['title'] ?? '')),
                '<span class="vkl-pill">' . esc_html((string) ($p['status'] ?? '')) . '</span>',
                self::price((float) ($p['total'] ?? 0)),
                self::price((float) ($p['paid'] ?? 0)),
                self::price((float) self::ask('wallet.installments', 'remaining', [$p], 0.0)),
            ];
        }

        self::table([__('Plan', 'vereskala-login'), __('Status', 'vereskala-login'), __('Total amount', 'vereskala-login'), __('Paid', 'vereskala-login'), __('Remaining', 'vereskala-login')], $rows, __('No installment plans.', 'vereskala-login'));
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=vkl-credit-customers&view=customers')) . '">' . esc_html__('Manage plans on the installments screen', 'vereskala-login') . ' ←</a></p>';
    }

    private function points_table(int $uid, int $limit): void
    {
        $rows = [];

        foreach ((array) self::ask('loyalty.repository', 'latest_for_user', [$uid, $limit], []) as $r) {
            $rows[] = [
                esc_html(number_format_i18n((int) ($r['points'] ?? 0))),
                esc_html((string) ($r['reason'] ?? '')),
                self::date((string) ($r['created_at'] ?? '')),
            ];
        }

        self::table([__('Points', 'vereskala-login'), __('Reason', 'vereskala-login'), __('Date', 'vereskala-login')], $rows, __('No club points yet.', 'vereskala-login'));
    }

    private function tickets_table(int $uid): void
    {
        $rows = [];

        foreach ((array) self::ask('ticket.repository', 'for_user', [$uid, 30], []) as $t) {
            $rows[] = [
                '<a href="' . esc_url(admin_url('admin.php?page=vkl-tickets&ticket=' . (int) ($t['id'] ?? 0))) . '">' . esc_html((string) ($t['subject'] ?? '')) . '</a>',
                '<span class="vkl-pill">' . esc_html((string) ($t['status'] ?? '')) . '</span>',
                self::date((string) ($t['updated_at'] ?? '')),
            ];
        }

        echo '<h2 class="title">' . esc_html__('Tickets', 'vereskala-login') . '</h2>';
        self::table([__('Subject', 'vereskala-login'), __('Status', 'vereskala-login'), __('Updated', 'vereskala-login')], $rows, __('No tickets.', 'vereskala-login'));
    }

    private function returns_table(int $uid): void
    {
        $rows = [];

        foreach ((array) self::ask('returns.repository', 'for_user', [$uid, 30], []) as $r) {
            $rows[] = [
                '#' . esc_html((string) ($r['order_id'] ?? '')),
                '<span class="vkl-pill">' . esc_html((string) ($r['status'] ?? '')) . '</span>',
                esc_html(wp_trim_words((string) ($r['reason'] ?? ''), 12)),
                self::date((string) ($r['created_at'] ?? '')),
            ];
        }

        echo '<h2 class="title">' . esc_html__('Return requests', 'vereskala-login') . '</h2>';
        self::table([__('Order', 'vereskala-login'), __('Status', 'vereskala-login'), __('Reason', 'vereskala-login'), __('Date', 'vereskala-login')], $rows, __('No return requests.', 'vereskala-login'));
    }

    private function messages_table(string $mobile): void
    {
        $digits = ltrim((string) preg_replace('/\D+/', '', $mobile), '0');

        if ('' === $digits) {
            echo '<p class="description">' . esc_html__('This customer has no mobile number on file.', 'vereskala-login') . '</p>';

            return;
        }

        $res  = (array) self::ask('sms.log', 'paged', [1, 30, '', '', $digits], ['rows' => []]);
        $rows = [];

        foreach ((array) ($res['rows'] ?? []) as $r) {
            $ok     = 'sent' === (string) ($r['status'] ?? '');
            $rows[] = [
                esc_html((string) ($r['type'] ?? '')),
                esc_html((string) ($r['channel'] ?? 'sms')),
                '<span class="vkl-pill" style="' . ($ok ? '' : 'background:#fcf0f1;color:#b32d2e') . '">' . esc_html($ok ? __('Sent', 'vereskala-login') : __('Failed', 'vereskala-login')) . '</span>',
                esc_html((string) ($r['created_at'] ?? '')) . ' UTC',
            ];
        }

        self::table([__('Message', 'vereskala-login'), __('Channel', 'vereskala-login'), __('Status', 'vereskala-login'), __('Date', 'vereskala-login')], $rows, __('No messages sent to this number.', 'vereskala-login'));
    }
}
