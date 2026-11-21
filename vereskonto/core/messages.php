<?php
/**
 * "All messages" — one table of every message the shop sends: what
 * triggers it, who receives it, its on/off switch, its pattern code, and
 * how it fared over the last 30 days.
 *
 * Before, these settings were scattered over seven screens (SMS
 * notifications, installments, returns, cart tracking, customer club,
 * smart shop, festival credit). The table edits the SAME options in
 * place — nothing is migrated or duplicated, and the original screens
 * keep working. Saving writes only the options listed here, through its
 * own handler: posting a subset of a settings group to options.php would
 * reset the group's other options.
 *
 * @package Vereskonto\Core
 * @since   2.12.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Messages_Admin
 */
final class VKL_Messages_Admin
{
    public const PAGE = 'vkl-messages';

    private const ACTION = 'vkl_messages_save';

    private VKL_Config $config;

    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_save']);
    }

    public function menu(): void
    {
        add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('All messages', 'vereskala-login'),
            __('All messages', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            self::PAGE,
            [$this, 'render']
        );
    }

    /**
     * Every message, grouped by topic. A group's 'toggle' is the switch
     * that turns all its messages on or off (null = none; a message then
     * goes out whenever its pattern is filled in). 'to' is the recipient;
     * 'vars' the pattern variables in order; 'page' where the topic's
     * other settings live.
     *
     * @return array<int, array{label: string, toggle: ?string, page: string, rows: array<int, array{key: string, label: string, to: string, vars: string}>}>
     */
    public static function definitions(): array
    {
        $c = 'customer';
        $a = 'admin';

        $orders = [];

        foreach (['processing', 'completed', 'cancelled', 'refunded', 'on-hold'] as $status) {
            $orders[] = [
                'key'   => 'order_pattern_' . str_replace('-', '_', $status),
                'label' => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : $status,
                'to'    => $c,
                'vars'  => 'name, order',
            ];
        }

        return [
            [
                'label'  => __('Orders', 'vereskala-login'),
                'toggle' => 'order_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => array_merge($orders, [
                    ['key' => 'order_pattern_tracking', 'label' => __('Tracking code', 'vereskala-login'), 'to' => $c, 'vars' => 'name, order, code, method'],
                    ['key' => 'order_pattern_tracking_courier', 'label' => __('Dispatched by courier', 'vereskala-login'), 'to' => $c, 'vars' => 'name, order, code, method'],
                    ['key' => 'admin_pattern_order_placed', 'label' => __('New order placed', 'vereskala-login'), 'to' => $a, 'vars' => 'order'],
                    ['key' => 'admin_pattern_new_order', 'label' => __('Order paid', 'vereskala-login'), 'to' => $a, 'vars' => 'order'],
                ]),
            ],
            [
                'label'  => __('Order cancelled — alert to the shop', 'vereskala-login'),
                'toggle' => 'order_admin_cancel_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'order_pattern_admin_cancelled', 'label' => __('Order cancelled', 'vereskala-login'), 'to' => $a, 'vars' => 'name, order'],
                ],
            ],
            [
                'label'  => __('Installment payments', 'vereskala-login'),
                'toggle' => 'installment_paid_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'installment_paid_pattern', 'label' => __('Installment received', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount'],
                    ['key' => 'installment_settled_pattern', 'label' => __('Final installment — plan finished', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount'],
                    ['key' => 'installment_paid_admin_pattern', 'label' => __('Installment received', 'vereskala-login'), 'to' => $a, 'vars' => 'name, amount'],
                ],
            ],
            [
                'label'  => __('Installment reminders and credit', 'vereskala-login'),
                'toggle' => null,
                'page'   => 'vkl-credit-customers&view=settings',
                'rows'   => [
                    ['key' => 'installment_notice_pattern', 'label' => __('Monthly reminder', 'vereskala-login'), 'to' => $c, 'vars' => 'name, title, amount, due'],
                    ['key' => 'installment_due_pattern', 'label' => __('Overdue', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount, penalty'],
                    ['key' => 'admin_pattern_credit_request', 'label' => __('New credit request', 'vereskala-login'), 'to' => $a, 'vars' => 'name, amount'],
                    ['key' => 'installment_approved_pattern', 'label' => __('Credit approved', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount'],
                    ['key' => 'installment_rejected_pattern', 'label' => __('Credit rejected', 'vereskala-login'), 'to' => $c, 'vars' => 'name'],
                ],
            ],
            [
                'label'  => __('Tickets', 'vereskala-login'),
                'toggle' => 'ticket_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'ticket_pattern_replied', 'label' => __('Support replied', 'vereskala-login'), 'to' => $c, 'vars' => 'name, ticket'],
                    ['key' => 'ticket_pattern_admin', 'label' => __('New ticket', 'vereskala-login'), 'to' => $a, 'vars' => 'ticket'],
                ],
            ],
            [
                'label'  => __('Wallet', 'vereskala-login'),
                'toggle' => 'wallet_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'wallet_pattern_charged', 'label' => __('Wallet charged', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount'],
                ],
            ],
            [
                'label'  => __('Festival credit', 'vereskala-login'),
                'toggle' => 'promo_auto_enabled',
                'page'   => 'vkl-wallet&view=festival',
                'rows'   => [
                    ['key' => 'promo_auto_pattern', 'label' => __('Automatic festival credit granted', 'vereskala-login'), 'to' => $c, 'vars' => 'name, amount, min, days'],
                ],
            ],
            [
                'label'  => __('Return requests', 'vereskala-login'),
                'toggle' => 'returns_sms_enabled',
                'page'   => 'vkl-returns',
                'rows'   => [
                    ['key' => 'returns_pattern_decided', 'label' => __('Request reviewed', 'vereskala-login'), 'to' => $c, 'vars' => 'name, order, status'],
                    ['key' => 'returns_pattern_admin', 'label' => __('New return request', 'vereskala-login'), 'to' => $a, 'vars' => 'order'],
                ],
            ],
            [
                'label'  => __('Abandoned carts', 'vereskala-login'),
                'toggle' => 'cart_sms_enabled',
                'page'   => 'vkl-cart',
                'rows'   => [
                    ['key' => 'cart_pattern_code', 'label' => __('Cart reminder', 'vereskala-login'), 'to' => $c, 'vars' => 'name, coupon'],
                ],
            ],
            [
                'label'  => __('Refer-a-friend', 'vereskala-login'),
                'toggle' => 'loyalty_ref_enabled',
                'page'   => 'vkl-loyalty&view=settings',
                'rows'   => [
                    ['key' => 'loyalty_ref_pattern', 'label' => __('Referral reward deposited', 'vereskala-login'), 'to' => $c, 'vars' => ''],
                ],
            ],
            [
                'label'  => __('Birthday greetings', 'vereskala-login'),
                'toggle' => 'birthday_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'birthday_pattern', 'label' => __('Happy birthday', 'vereskala-login'), 'to' => $c, 'vars' => 'name, coupon'],
                ],
            ],
            [
                'label'  => __('Review requests', 'vereskala-login'),
                'toggle' => 'review_sms_enabled',
                'page'   => 'vkl-notifications',
                'rows'   => [
                    ['key' => 'review_pattern', 'label' => __('Ask for a review', 'vereskala-login'), 'to' => $c, 'vars' => 'name, order'],
                ],
            ],
            [
                'label'  => __('Chatbot — alerts to staff', 'vereskala-login'),
                'toggle' => 'chatbot_notify_staff',
                'page'   => 'vkl-smart',
                'rows'   => [
                    ['key' => 'chatbot_pattern_notify', 'label' => __('New message in a chat', 'vereskala-login'), 'to' => $a, 'vars' => 'staff_name, name, session, message'],
                    ['key' => 'chatbot_pattern_escalate', 'label' => __('Customer asks for a person', 'vereskala-login'), 'to' => $a, 'vars' => 'staff_name, name, session, message'],
                ],
            ],
            [
                'label'  => __('Chatbot — reply to the customer', 'vereskala-login'),
                'toggle' => 'chatbot_reply_sms',
                'page'   => 'vkl-smart',
                'rows'   => [
                    ['key' => 'chatbot_pattern_reply', 'label' => __('Staff replied in a chat', 'vereskala-login'), 'to' => $c, 'vars' => 'name, reply, session'],
                ],
            ],
        ];
    }

    /**
     * Option keys this screen may write: [toggles, patterns].
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public static function writable_keys(): array
    {
        $toggles  = [];
        $patterns = [];

        foreach (self::definitions() as $group) {
            if (null !== $group['toggle']) {
                $toggles[] = $group['toggle'];
            }

            foreach ($group['rows'] as $row) {
                $patterns[] = $row['key'];
            }
        }

        return [array_values(array_unique($toggles)), array_values(array_unique($patterns))];
    }

    /**
     * Save: only the listed options, and only the ones that changed.
     */
    public function handle_save(): void
    {
        vkl('security')->verify_admin_request();

        [$toggles, $patterns] = self::writable_keys();
        $changed              = 0;

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $posted_toggles  = isset($_POST['vkl_toggle']) && is_array($_POST['vkl_toggle']) ? wp_unslash($_POST['vkl_toggle']) : [];
        $posted_patterns = isset($_POST['vkl_pattern']) && is_array($_POST['vkl_pattern']) ? wp_unslash($_POST['vkl_pattern']) : [];
        // phpcs:enable

        foreach ($toggles as $key) {
            if (!array_key_exists($key, $posted_toggles)) {
                continue; // Not on the form: leave untouched.
            }

            $new = '1' === (string) $posted_toggles[$key] ? '1' : '0';

            if ($new !== ($this->config->get_bool($key) ? '1' : '0')) {
                update_option($this->config->option_name($key), $new);
                $changed++;
            }
        }

        foreach ($patterns as $key) {
            if (!array_key_exists($key, $posted_patterns)) {
                continue;
            }

            $new = sanitize_text_field((string) $posted_patterns[$key]);

            if ($new !== $this->config->get_string($key)) {
                update_option($this->config->option_name($key), $new);
                $changed++;
            }
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'vkl_saved' => $changed], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }

        $stats = [];

        try {
            if (vkl()->has('sms.log')) {
                $stats = (array) vkl('sms.log')->pattern_stats(30);
            }
        } catch (Throwable $e) {
            $stats = [];
        }

        $to_label = [
            'customer' => __('Customer', 'vereskala-login'),
            'admin'    => __('Shop', 'vereskala-login'),
        ];
        ?>
        <div class="wrap vkl-messages">
            <h1><?php esc_html_e('All messages', 'vereskala-login'); ?></h1>
            <p class="description" style="max-width:860px">
                <?php esc_html_e('Every text message the shop sends, in one table. A message goes out when its topic is switched on and it has a pattern code; an empty code means that message is not sent. Changes here are the same settings as on each topic’s own screen.', 'vereskala-login'); ?>
            </p>

            <?php if (isset($_GET['vkl_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php /* translators: %s: number of changed settings. */ echo esc_html(sprintf(__('Saved. Settings changed: %s', 'vereskala-login'), number_format_i18n(absint($_GET['vkl_saved'])))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                </p></div>
            <?php endif; ?>

            <style>
                .vkl-messages table.vkl-msg{max-width:1180px;margin-bottom:20px}
                .vkl-messages .vkl-msg th.grp{background:#f6f7f7;font-size:13.5px}
                .vkl-messages .vkl-msg th.grp .opts{float:inline-end;font-weight:400;display:flex;gap:14px;align-items:center}
                .vkl-messages .vkl-msg input[type=text]{width:130px;font-family:monospace}
                .vkl-messages .vkl-to{display:inline-block;padding:0 8px;border-radius:999px;font-size:12px;background:#eef4fd;color:#1d4ed8}
                .vkl-messages .vkl-to.admin{background:#fdf4e7;color:#8a4b00}
                .vkl-messages .vkl-state{display:inline-block;padding:0 8px;border-radius:999px;font-size:12px}
                .vkl-messages .vkl-state.on{background:#e6f4ea;color:#157f3a}
                .vkl-messages .vkl-state.off{background:#f0f0f1;color:#50575e}
                .vkl-messages .vkl-state.warn{background:#fcf0f1;color:#b32d2e}
                .vkl-messages code.v{font-size:11.5px}
                .vkl-messages .num{font-variant-numeric:tabular-nums;white-space:nowrap}
                .vkl-messages .bad{color:#b32d2e}
                .vkl-messages .vkl-save{position:sticky;bottom:0;background:#f0f0f1;padding:10px 0;border-top:1px solid #dcdcde;z-index:5}
            </style>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php vkl('security')->admin_nonce_field(); ?>

                <?php foreach (self::definitions() as $gi => $group) : ?>
                    <?php $on = null === $group['toggle'] || $this->config->get_bool($group['toggle']); ?>
                    <table class="widefat vkl-msg">
                        <thead>
                            <tr>
                                <th colspan="5" class="grp" scope="colgroup">
                                    <?php echo esc_html($group['label']); ?>
                                    <span class="opts">
                                        <?php if (null !== $group['toggle']) : ?>
                                            <label>
                                                <input type="hidden" name="vkl_toggle[<?php echo esc_attr($group['toggle']); ?>]" value="0">
                                                <input type="checkbox" name="vkl_toggle[<?php echo esc_attr($group['toggle']); ?>]" value="1" <?php checked($on); ?>>
                                                <?php esc_html_e('On', 'vereskala-login'); ?>
                                            </label>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $group['page'])); ?>"><?php esc_html_e('More settings', 'vereskala-login'); ?> ↗</a>
                                    </span>
                                </th>
                            </tr>
                            <tr>
                                <th scope="col"><?php esc_html_e('Message', 'vereskala-login'); ?></th>
                                <th scope="col" style="width:90px"><?php esc_html_e('To', 'vereskala-login'); ?></th>
                                <th scope="col" style="width:150px"><?php esc_html_e('Pattern code', 'vereskala-login'); ?></th>
                                <th scope="col"><?php esc_html_e('Variables', 'vereskala-login'); ?></th>
                                <th scope="col" style="width:190px"><?php esc_html_e('Last 30 days', 'vereskala-login'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($group['rows'] as $row) : ?>
                            <?php
                            $code  = $this->config->get_string($row['key']);
                            $state = !$on ? ['off', __('Off', 'vereskala-login')] : ('' === $code ? ['warn', __('No pattern — not sent', 'vereskala-login')] : ['on', __('Active', 'vereskala-login')]);
                            $s     = '' !== $code ? ($stats[$code] ?? ['sent' => 0, 'failed' => 0]) : null;
                            $id    = 'vkl-p-' . $row['key'];
                            ?>
                            <tr>
                                <td><label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($row['label']); ?></label>
                                    <br><span class="vkl-state <?php echo esc_attr($state[0]); ?>"><?php echo esc_html($state[1]); ?></span></td>
                                <td><span class="vkl-to <?php echo esc_attr($row['to']); ?>"><?php echo esc_html($to_label[$row['to']] ?? $row['to']); ?></span></td>
                                <td><input type="text" dir="ltr" inputmode="numeric" id="<?php echo esc_attr($id); ?>" name="vkl_pattern[<?php echo esc_attr($row['key']); ?>]" value="<?php echo esc_attr($code); ?>"></td>
                                <td><?php echo '' !== $row['vars'] ? '<code class="v" dir="ltr">' . esc_html($row['vars']) . '</code>' : '—'; ?></td>
                                <td class="num">
                                    <?php if (null === $s) : ?>
                                        —
                                    <?php else : ?>
                                        <?php /* translators: %s: count. */ echo esc_html(sprintf(__('%s sent', 'vereskala-login'), number_format_i18n($s['sent']))); ?>
                                        <?php if ($s['failed'] > 0) : ?>
                                            · <span class="bad"><?php /* translators: %s: count. */ echo esc_html(sprintf(__('%s failed', 'vereskala-login'), number_format_i18n($s['failed']))); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>

                <div class="vkl-save"><?php submit_button(__('Save changes', 'vereskala-login'), 'primary', 'submit', false); ?></div>
            </form>

            <p class="description" style="max-width:860px">
                <?php esc_html_e('Bale, WhatsApp and browser notifications for invoices and wallet credit are switched on the Notifications & Bale settings tab. Messages whose pattern is chosen per send (campaigns, surveys, festival batches) are set where they are sent.', 'vereskala-login'); ?>
            </p>
        </div>
        <?php
    }
}
