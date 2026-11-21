<?php
/**
 * SMS module admin — three submenus: Notifications (event→pattern
 * mapping), Campaigns (bulk messaging), SMS Logs.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Admin
 */
final class VKL_Sms_Admin
{
    private const GROUP = 'vkl_sms_settings';

    private VKL_Config $config;

    private VKL_Security $security;

    private VKL_Sms_Marketing $marketing;

    private VKL_Sms_Log_Repository $log;

    public function __construct(
        VKL_Config $config,
        VKL_Security $security,
        VKL_Sms_Marketing $marketing,
        VKL_Sms_Log_Repository $log
    ) {
        $this->config    = $config;
        $this->security  = $security;
        $this->marketing = $marketing;
        $this->log       = $log;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menus']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vkl_create_campaign', [$this, 'handle_create_campaign']);
        add_action('wp_ajax_vkl_audience_size', [$this, 'ajax_audience_size']);
        add_action('admin_post_vkl_log_purge', [$this, 'handle_log_purge']);
        add_action('admin_post_vkl_log_export', [$this, 'handle_log_export']);

        add_action('admin_post_vkl_campaign_update', [$this, 'handle_campaign_update']);
        add_action('admin_post_vkl_campaign_delete', [$this, 'handle_campaign_delete']);
        add_action('admin_post_vkl_campaign_test', [$this, 'handle_campaign_test']);
        add_action('admin_post_vkl_sms_resend', [$this, 'handle_sms_resend']);
        add_action('admin_post_vkl_campaign_run', [$this, 'handle_campaign_run']);
    }

    public function add_menus(): void
    {
        add_submenu_page('vereskala-login', __('SMS Notifications', 'vereskala-login'), __('SMS Notifications', 'vereskala-login'), VKL_Security::ADMIN_CAP, 'vkl-notifications', [$this, 'render_notifications']);
        add_submenu_page('vereskala-login', __('SMS Campaigns', 'vereskala-login'), __('SMS Campaigns', 'vereskala-login'), VKL_Security::ADMIN_CAP, 'vkl-campaigns', [$this, 'render_campaigns']);
        add_submenu_page('vereskala-login', __('SMS Logs', 'vereskala-login'), __('SMS Logs', 'vereskala-login'), VKL_Security::ADMIN_CAP, 'vkl-sms-logs', [$this, 'render_logs']);
    }

    public function register_settings(): void
    {
        $bool = static fn(mixed $v): string => '1' === (string) $v ? '1' : '0';
        $text = 'sanitize_text_field';

        $keys = [
            'order_sms_enabled'          => $bool,
            'default_customer_name'      => $text,
            'ticket_sms_enabled'         => $bool,
            'wallet_sms_enabled'         => $bool,
            'admin_mobile'               => $text,
            'installment_paid_sms_enabled'   => $bool,
            'installment_paid_pattern'       => $text,
            'installment_settled_pattern'    => $text,
            'installment_paid_admin_pattern' => $text,
            'order_admin_cancel_enabled' => $bool,
            'order_pattern_admin_cancelled' => $text,
            'admin_pattern_new_order'    => $text,
            'admin_pattern_order_placed' => $text,
            'order_pattern_tracking'     => $text,
            'ticket_pattern_replied'     => $text,
            'ticket_pattern_admin'       => $text,
            'wallet_pattern_charged'     => $text,
            'wa_token'                   => $text,
            'wa_phone_id'                => $text,
            'wa_lang'                    => $text,
            'shipping_methods'           => 'sanitize_textarea_field',
            'birthday_sms_enabled'       => $bool,
            'birthday_pattern'           => $text,
            'birthday_coupon'            => $text,
            'birthday_coupon_auto'       => $bool,
            'birthday_coupon_amount'     => 'absint',
            'birthday_coupon_days'       => 'absint',
            'admin_pattern_credit_request' => $text,
            'order_pattern_tracking_courier' => $text,
            'sms_phonebook_id'               => $text,
            'installment_approved_pattern' => $text,
            'installment_rejected_pattern' => $text,
            'review_sms_enabled'         => $bool,
            'review_pattern'             => $text,
            'review_delay_days'          => 'absint',
            'sms_block_links'            => $bool,
        ];

        foreach (VKL_Sms_Order::STATUSES as $status) {
            $keys['order_pattern_' . str_replace('-', '_', $status)] = $text;
        }

        foreach ($keys as $key => $sanitize) {
            register_setting(self::GROUP, $this->config->option_name($key), [
                'type'              => 'string',
                'sanitize_callback' => $sanitize,
                'default'           => '',
            ]);
        }
    }

    /** Notifications settings screen. */
    public function render_notifications(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $toggle = function (string $key, string $label): void {
            $name = $this->config->option_name($key);
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label>';
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
            echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($this->config->get_bool($key, false), true, false) . '> ';
            esc_html_e('Enabled', 'vereskala-login');
            echo '</label></td></tr>';
        };

        $field = function (string $key, string $label, string $hint = ''): void {
            $name = $this->config->option_name($key);
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            echo '<input type="text" class="regular-text" name="' . esc_attr($name) . '" value="' . esc_attr($this->config->get_string($key)) . '">';
            if ($hint) {
                echo '<p class="description">' . esc_html($hint) . '</p>';
            }
            echo '</td></tr>';
        };
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SMS Notifications', 'vereskala-login'); ?></h1>

            <div style="background:#f5faf7;border:1px solid #dceee2;border-radius:10px;padding:12px 16px;max-width:900px;margin:12px 0">
                <strong>📋 <?php esc_html_e('How the pattern fields work', 'vereskala-login'); ?></strong>
                <ol style="margin:8px 0 0;padding-inline-start:20px;line-height:2">
                    <li><?php esc_html_e('Write the message in your SMS panel as a pattern and wait for it to be approved.', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('Copy the pattern id (bodyId) the panel gives you and paste that number — not the text — into the field below.', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('The variables are filled BY POSITION, not by name: the first blank in your pattern gets the first variable listed under the field, the second blank the second, and so on. Define them in the pattern in exactly that order.', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('Leave a field empty to switch that message off.', 'vereskala-login'); ?></li>
                </ol>
                <p class="description" style="margin:8px 0 0">
                    <?php esc_html_e('Example: for a pattern reading «%name% dear, your order %order% was cancelled», the variables listed as “name, order” line up correctly. Swapping them in the pattern would put the order number where the name belongs.', 'vereskala-login'); ?>
                </p>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <h2 class="title"><?php esc_html_e('Order notifications', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $toggle('sms_block_links', __('Hold back messages whose variables contain a web address', 'vereskala-login'));
                    $toggle('order_sms_enabled', __('Order SMS', 'vereskala-login'));

                    foreach (VKL_Sms_Order::STATUSES as $status) {
                        $field(
                            'order_pattern_' . str_replace('-', '_', $status),
                            /* translators: %s: order status. */
                            sprintf(__('Pattern: order %s', 'vereskala-login'), $status),
                            __('Variables, in this exact order: name, order. Leave empty to disable.', 'vereskala-login')
                        );
                    }

                    $toggle('installment_paid_sms_enabled', __('Confirm received instalments by SMS', 'vereskala-login'));
                    $field('installment_paid_pattern', __('Pattern: instalment received', 'vereskala-login'), __('Variables, in this exact order: name, amount. Goes to the customer each time a repayment is received, instead of the generic purchase confirmation.', 'vereskala-login'));
                    $field('installment_settled_pattern', __('Pattern: final instalment (plan finished)', 'vereskala-login'), __('Variables, in this exact order: name, amount. Used for the payment that clears the plan. Leave empty to use the pattern above.', 'vereskala-login'));
                    $field('installment_paid_admin_pattern', __('Pattern: instalment received — alert to the shop', 'vereskala-login'), __('Variables, in this exact order: name, amount. Goes to the admin mobile. Leave empty to disable.', 'vereskala-login'));
                    $toggle('order_admin_cancel_enabled', __('Text the shop when an order is cancelled', 'vereskala-login'));
                    $field('order_pattern_admin_cancelled', __('Pattern: cancellation alert to the shop', 'vereskala-login'), __('Variables, in this exact order: name, order. Goes to the admin mobile above. Leave empty to reuse the customer cancellation pattern.', 'vereskala-login'));
                    $field('order_pattern_tracking', __('Pattern: tracking code', 'vereskala-login'), __('Variables, in this exact order: name, order, code, method.', 'vereskala-login'));
                    $field('order_pattern_tracking_courier', __('Pattern: dispatched by courier (پیک)', 'vereskala-login'), __('Used instead of the tracking pattern when the dispatch method contains «پیک». Pattern variables: name, order, code, method.', 'vereskala-login'));
                    $field('sms_phonebook_id', __('Panel phonebook id (auto-add customers)', 'vereskala-login'), __('Not used with Melipayamak — that gateway has no phonebook endpoint in its web service, so customers are not synced. Leave empty.', 'vereskala-login'));

                    // Dispatch methods, one per line (پست، پیک، تیپاکس، …).
                    $name = $this->config->option_name('shipping_methods');
                    echo '<tr><th scope="row">' . esc_html__('Dispatch methods', 'vereskala-login') . '</th><td>';
                    echo '<textarea class="large-text" rows="4" name="' . esc_attr($name) . '">'
                        . esc_textarea($this->config->get_string('shipping_methods'))
                        . '</textarea>';
                    echo '<p class="description">' . esc_html__('One dispatch method per line (e.g. post, courier, Tipax). Shown as a selector on the order edit screen.', 'vereskala-login') . '</p>';
                    echo '</td></tr>';
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e('Ticket notifications', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $toggle('ticket_sms_enabled', __('Ticket SMS', 'vereskala-login'));
                    $field('ticket_pattern_replied', __('Pattern: support replied', 'vereskala-login'), __('Variables, in this exact order: name, ticket.', 'vereskala-login'));
                    $field('ticket_pattern_admin', __('Pattern: admin alert', 'vereskala-login'), __('Variables, in this exact order: ticket.', 'vereskala-login'));
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e('Wallet notifications', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $toggle('wallet_sms_enabled', __('Wallet SMS', 'vereskala-login'));
                    $field('wallet_pattern_charged', __('Pattern: wallet charged', 'vereskala-login'), __('Variables, in this exact order: name, amount.', 'vereskala-login'));
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e('Shop admin', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $field('default_customer_name', __('Fallback customer name', 'vereskala-login'), __('Used as the "name" variable when the customer has no stored first name.', 'vereskala-login'));
                    $field('admin_mobile', __('Admin mobile', 'vereskala-login'));
                    $field('admin_pattern_credit_request', __('Pattern: admin — new credit request', 'vereskala-login'), __('Variables, in this exact order: name, amount. Sent to the admin mobile the moment a customer submits an installment-credit request.', 'vereskala-login'));
                    $field('installment_approved_pattern', __('Pattern: credit approved (to customer)', 'vereskala-login'), __('Variables, in this exact order: name, amount. Sent to the customer when their credit request is approved.', 'vereskala-login'));
                    $field('installment_rejected_pattern', __('Pattern: credit rejected (to customer)', 'vereskala-login'), __('Pattern variable: name. Sent to the customer when their credit request is rejected.', 'vereskala-login'));
                    $field('admin_pattern_order_placed', __('Pattern: order placed alert', 'vereskala-login'), __('Sent to the admin the moment a customer places an order. Pattern variables: order.', 'vereskala-login'));
                    $field('admin_pattern_new_order', __('Pattern: new order alert', 'vereskala-login'), __('Sent when an order is paid (enters processing). Pattern variables: order. Note: with both this and the alert above filled in, an ordinary order texts the shop twice — once on placement, once on payment. Leave one empty for a single message.', 'vereskala-login'));
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e('Birthday greetings', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Birthday SMS', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('birthday_sms_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('birthday_sms_enabled')); ?>" value="1" <?php checked($this->config->get_bool('birthday_sms_enabled', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Congratulates every customer on the birthday saved in their account details — once a year, via a daily background job.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <?php
                    $field('birthday_pattern', __('Pattern: birthday', 'vereskala-login'), __('Variables, in this exact order: name, coupon.', 'vereskala-login'));
                    $field('birthday_coupon', __('Birthday gift coupon (optional)', 'vereskala-login'), __('Sent as the "coupon" variable. Create the coupon in WooCommerce first.', 'vereskala-login'));
                    ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto personal coupon', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('birthday_coupon_auto')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('birthday_coupon_auto')); ?>" value="1" <?php checked($this->config->get_bool('birthday_coupon_auto', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            &nbsp;
                            <label><?php esc_html_e('Percent', 'vereskala-login'); ?>
                                <input type="number" min="1" max="100" style="width:70px" name="<?php echo esc_attr($this->config->option_name('birthday_coupon_amount')); ?>" value="<?php echo (int) $this->config->get_int('birthday_coupon_amount', 10); ?>">
                            </label>
                            &nbsp;
                            <label><?php esc_html_e('Valid days', 'vereskala-login'); ?>
                                <input type="number" min="1" style="width:70px" name="<?php echo esc_attr($this->config->option_name('birthday_coupon_days')); ?>" value="<?php echo (int) $this->config->get_int('birthday_coupon_days', 7); ?>">
                            </label>
                            <p class="description"><?php esc_html_e('Generates a unique single-use code per customer (locked to their account e-mail, auto-expiring) and sends it as the "coupon" variable — overrides the static coupon above.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <?php ?>
                </table>

                <h2 class="title"><?php esc_html_e('Review requests', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Review-request SMS', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('review_sms_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('review_sms_enabled')); ?>" value="1" <?php checked($this->config->get_bool('review_sms_enabled', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Asks the customer to review their purchase a few days after the order completes — reviews earn club points on the account Surveys tab.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <?php
                    $field('review_pattern', __('Pattern: review request', 'vereskala-login'), __('Variables, in this exact order: name, order. No link — the provider rejects URLs inside an approved pattern.', 'vereskala-login'));
                    ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Days after completion', 'vereskala-login'); ?></th>
                        <td><input type="number" min="1" max="30" step="1" name="<?php echo esc_attr($this->config->option_name('review_delay_days')); ?>" value="<?php echo esc_attr((string) $this->config->get_int('review_delay_days', 3)); ?>" style="width:90px"></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('WhatsApp gateway (Meta Cloud API)', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $field('wa_token', __('Access token', 'vereskala-login'), __('Permanent access token of the Meta app.', 'vereskala-login'));
                    $field('wa_phone_id', __('Phone number ID', 'vereskala-login'), __('From the WhatsApp Business account.', 'vereskala-login'));
                    $field('wa_lang', __('Template language code', 'vereskala-login'), __('e.g. fa, de, en_US. Must match the approved template language.', 'vereskala-login'));
                    ?>
                </table>

                <details style="max-width:800px;margin:8px 0 16px;border:1px solid #c3c4c7;border-radius:4px;padding:12px 16px;background:#fff">
                    <summary style="cursor:pointer;font-weight:600"><?php esc_html_e('WhatsApp setup guide', 'vereskala-login'); ?></summary>
                    <ol style="margin-top:12px">
                        <li><?php esc_html_e('Create a Meta developer account at developers.facebook.com and add a new app of type "Business".', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('In the app dashboard, add the "WhatsApp" product. Meta creates a WhatsApp Business account and a test phone number for you.', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Add and verify your real business phone number under WhatsApp → API Setup. This number must not be registered in the regular WhatsApp app.', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Copy the "Phone number ID" shown under API Setup into the field above (this is the ID, not the phone number itself).', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Create a permanent access token: in Meta Business Settings, add a System User with access to the app and the WhatsApp account, then generate a token with the whatsapp_business_messaging permission. Paste it into the "Access token" field. Temporary dashboard tokens expire after 24 hours.', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Create your message templates under WhatsApp Manager → Message templates and wait for Meta approval. Use numbered placeholders: {{1}}, {{2}}, …', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Set the template language code above exactly as chosen when creating the template (e.g. fa, de, en_US).', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Enter the template name (not its text) wherever a WhatsApp template field appears, e.g. in the cart settings for abandoned carts. Placeholder order there: {{1}} = customer name, {{2}} = coupon code.', 'vereskala-login'); ?></li>
                        <li><?php esc_html_e('Test with a manual send from the Cart Tracking screen and check the SMS Logs page: WhatsApp rows show channel "whatsapp" together with the raw gateway response.', 'vereskala-login'); ?></li>
                    </ol>
                    <p class="description"><?php esc_html_e('Note: Meta only allows approved template messages to be sent outside a 24-hour customer-service window, and messaging is billed per conversation by Meta.', 'vereskala-login'); ?></p>
                </details>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** Campaigns screen. */
    /**
     * Tell the admin how many people a campaign would reach, before they
     * commit to sending — every recipient is a message they pay for.
     */
    public function ajax_audience_size(): void
    {
        if (!$this->security->current_user_can_admin()) {
            wp_send_json_error(['message' => '']);
        }

        check_ajax_referer('vkl_audience_size', 'nonce');

        $audience = isset($_POST['audience']) ? sanitize_key(wp_unslash($_POST['audience'])) : '';

        if (!in_array($audience, VKL_Sms_Marketing::AUDIENCES, true)) {
            wp_send_json_error(['message' => '']);
        }

        $size = $this->marketing->audience_size($audience);

        wp_send_json_success([
            'count'  => (int) $size['count'],
            'capped' => (bool) $size['capped'],
        ]);
    }

    public function render_campaigns(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $audience_labels = [
            'all'          => __('All users with a mobile number', 'vereskala-login'),
            'buyers'       => __('Paying customers', 'vereskala-login'),
            'abandoned'    => __('Abandoned-cart customers', 'vereskala-login'),
            'wallet'       => __('Customers with wallet balance', 'vereskala-login'),
            'club'         => __('Club members (any points)', 'vereskala-login'),
            'tier_bronze'  => __('Club — Bronze tier', 'vereskala-login'),
            'tier_silver'  => __('Club — Silver tier', 'vereskala-login'),
            'tier_gold'    => __('Club — Gold tier', 'vereskala-login'),
            'tier_diamond' => __('Club — Diamond tier', 'vereskala-login'),
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SMS Campaigns', 'vereskala-login'); ?></h1>

            <?php if (isset($_GET['vkl_ran'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Queue processed — check the counters below and the SMS report.', 'vereskala-login'); ?></p></div>
            <?php endif; ?>

            <?php
            $vkl_next = wp_next_scheduled(VKL_Sms_Marketing::CRON_HOOK);
            $vkl_off  = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            ?>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #dceee2;border-radius:12px;padding:10px 16px;margin:8px 0 16px;max-width:960px">
                <strong>⏱ <?php esc_html_e('Queue worker', 'vereskala-login'); ?></strong>
                <span class="description">
                    <?php if ($vkl_next) : ?>
                        <?php printf(/* translators: %s: time. */ esc_html__('Next automatic run: %s', 'vereskala-login'), esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', (int) $vkl_next), 'Y-m-d H:i'))); ?>
                    <?php else : ?>
                        <span style="color:#d92b31"><?php esc_html_e('No automatic run is scheduled — use the button, or check the site cron.', 'vereskala-login'); ?></span>
                    <?php endif; ?>
                    <?php if ($vkl_off) : ?>
                        · <span style="color:#d92b31"><?php esc_html_e('WP-Cron is disabled on this site (DISABLE_WP_CRON), so a server cron job must call wp-cron.php.', 'vereskala-login'); ?></span>
                    <?php endif; ?>
                </span>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-inline-start:auto">
                    <input type="hidden" name="action" value="vkl_campaign_run">
                    <?php $this->security->admin_nonce_field(); ?>
                    <?php submit_button(__('Send the next batch now', 'vereskala-login'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <?php if (isset($_GET['created'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success"><p><?php esc_html_e('Campaign queued. Sending starts within five minutes.', 'vereskala-login'); ?></p></div>
            <?php endif; ?>

            <div style="background:#f5faf7;border:1px solid #dceee2;border-radius:10px;padding:12px 16px;max-width:960px;margin:16px 0">
                <strong>📣 <?php esc_html_e('Sending a campaign, step by step', 'vereskala-login'); ?></strong>
                <ol style="margin:8px 0 0;padding-inline-start:20px;line-height:2">
                    <li><?php esc_html_e('Write the message in your SMS panel as a pattern and wait for approval, then copy its id (bodyId).', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('Choose the audience below — the recipient count appears next to it so you know the cost before sending.', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('Fill the parameters one per line. They are inserted into the pattern BY POSITION, so keep them in the same order as the blanks in your pattern.', 'vereskala-login'); ?></li>
                    <li><?php esc_html_e('Queue it. Sending runs in batches in the background; watch the counters below and the SMS report.', 'vereskala-login'); ?></li>
                </ol>
            </div>

            <h2 class="title"><?php esc_html_e('New campaign', 'vereskala-login'); ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_create_campaign">
                <?php $this->security->admin_nonce_field(); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Title', 'vereskala-login'); ?></th>
                        <td><input type="text" class="regular-text" name="vkl_title" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Audience', 'vereskala-login'); ?></th>
                        <td>
                            <select name="vkl_audience" class="vkl-audience-select" id="vkl-audience-create" data-target="vkl-window-create">
                                <?php foreach ($audience_labels as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="vkl-audience-size" style="margin-inline-start:10px;font-weight:600;color:#157f3a"></span>
                            <p class="description" id="vkl-audience-hint"><?php esc_html_e('Pick an audience to see how many people it reaches.', 'vereskala-login'); ?></p>
                            <script>
                            (function () {
                                var sel = document.getElementById('vkl-audience-create');
                                var out = document.getElementById('vkl-audience-size');
                                var hint = document.getElementById('vkl-audience-hint');
                                if (!sel || !out) { return; }

                                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                                var nonce = <?php echo wp_json_encode(wp_create_nonce('vkl_audience_size')); ?>;
                                var counting = <?php echo wp_json_encode(__('counting…', 'vereskala-login')); ?>;
                                var people = <?php echo wp_json_encode(__('%s recipients', 'vereskala-login')); ?>;
                                var plus = <?php echo wp_json_encode(__('%s+ recipients', 'vereskala-login')); ?>;
                                var failed = <?php echo wp_json_encode(__('could not count', 'vereskala-login')); ?>;
                                var timer = null;

                                function look() {
                                    clearTimeout(timer);
                                    out.textContent = counting;
                                    timer = setTimeout(function () {
                                        var body = new URLSearchParams();
                                        body.append('action', 'vkl_audience_size');
                                        body.append('nonce', nonce);
                                        body.append('audience', sel.value);

                                        fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                                            .then(function (r) { return r.json(); })
                                            .then(function (res) {
                                                if (!res || !res.success) { out.textContent = failed; return; }
                                                var n = res.data.count;
                                                out.textContent = (res.data.capped ? plus : people).replace('%s', n.toLocaleString());
                                                if (hint) { hint.style.display = 'none'; }
                                            })
                                            .catch(function () { out.textContent = failed; });
                                    }, 250);
                                }

                                sel.addEventListener('change', look);
                                look();
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr class="vkl-window-row" id="vkl-window-create" style="display:none">
                        <th scope="row"><?php esc_html_e('Purchase date range (optional)', 'vereskala-login'); ?></th>
                        <td>
                            <label><?php esc_html_e('From', 'vereskala-login'); ?>
                                <input type="date" name="vkl_window_from"></label>
                            &nbsp;
                            <label><?php esc_html_e('To', 'vereskala-login'); ?>
                                <input type="date" name="vkl_window_to"></label>
                            <p class="description"><?php esc_html_e('Limit the audience to customers whose purchase (or wallet/points activity) falls in this range. Leave a side empty for open-ended. Applies to purchasing audiences only.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Pattern code (bodyId)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" class="regular-text" dir="ltr" name="vkl_pattern" required>
                            <p class="description"><?php esc_html_e('The bodyId of an approved pattern in your Melipayamak panel. The promotional wording lives inside that approved pattern; the values you send below only fill its blanks and must stay short (a name, a number, a code).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Pattern parameters', 'vereskala-login'); ?></th>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px">
                                <?php foreach (['name' => __('Customer name', 'vereskala-login'), 'wallet' => __('Wallet balance', 'vereskala-login'), 'points' => __('Club points', 'vereskala-login'), 'cart' => __('Abandoned cart total', 'vereskala-login')] as $vkl_ak => $vkl_al) : ?>
                                    <button type="button" class="button button-small vkl-auto-chip" data-line="<?php echo esc_attr($vkl_ak); ?>=auto"><?php echo esc_html($vkl_al); ?> (<?php echo esc_html($vkl_ak); ?>=auto)</button>
                                <?php endforeach; ?>
                            </div>
                            <textarea name="vkl_params" id="vkl-params-create" rows="3" class="large-text" placeholder="discount=20&#10;code=EID1405"></textarea>
                            <p class="description" id="vkl-params-count" style="font-weight:600"></p>
                            <script>
                            (function () {
                                var area = document.getElementById('vkl-params-create');
                                var out = document.getElementById('vkl-params-count');
                                if (!area || !out) { return; }

                                var one = <?php echo wp_json_encode(__('1 value will be sent — your approved pattern must have exactly 1 blank.', 'vereskala-login')); ?>;
                                var many = <?php echo wp_json_encode(__('%s values will be sent — your approved pattern must have exactly %s blanks, in this order.', 'vereskala-login')); ?>;
                                var none = <?php echo wp_json_encode(__('No values yet. Write one per line as key=value.', 'vereskala-login')); ?>;

                                function count() {
                                    var lines = area.value.split('\n').filter(function (l) {
                                        return l.trim() !== '' && l.indexOf('=') > 0;
                                    });

                                    if (lines.length === 0) { out.textContent = none; return; }
                                    if (lines.length === 1) { out.textContent = one; return; }

                                    out.textContent = many.split('%s').join(String(lines.length));
                                }

                                area.addEventListener('input', count);
                                count();
                            })();
                            </script>
                            <script>
                            (function () {
                                var area = document.querySelector('textarea[name="vkl_params"]');
                                document.querySelectorAll('.vkl-auto-chip').forEach(function (chip) {
                                    chip.addEventListener('click', function () {
                                        if (!area) { return; }
                                        var line = chip.getAttribute('data-line');
                                        if (area.value.indexOf(line) !== -1) { return; }
                                        area.value = (area.value.trim() ? area.value.trim() + '\n' : '') + line;
                                    });
                                });
                            })();
                            </script>

                            <?php
                            $vkl_prod_opts = function_exists('wc_get_products') ? wc_get_products([
                                'status'  => 'publish',
                                'limit'   => 200,
                                'orderby' => 'date',
                                'order'   => 'DESC',
                            ]) : [];
                            ?>
                            <?php if ([] !== $vkl_prod_opts) : ?>
                                <div style="background:#f5faf7;border:1px solid #dceee2;border-radius:10px;padding:10px 12px;margin-top:8px">
                                    <strong>🛍 <?php esc_html_e('Add a product to the campaign', 'vereskala-login'); ?></strong>
                                    <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 4px">
                                        <select id="vkl-camp-product" style="min-width:280px;max-width:100%">
                                            <option value=""><?php esc_html_e('— choose a product —', 'vereskala-login'); ?></option>
                                            <?php foreach ($vkl_prod_opts as $vkl_prod) : ?>
                                                <?php if (!$vkl_prod instanceof WC_Product) { continue; } ?>
                                                <option
                                                    value="<?php echo (int) $vkl_prod->get_id(); ?>"
                                                    data-name="<?php echo esc_attr($vkl_prod->get_name()); ?>"
                                                    data-price="<?php echo esc_attr((string) (int) wc_get_price_to_display($vkl_prod)); ?>"
                                                    data-link="<?php echo esc_attr((string) $vkl_prod->get_permalink()); ?>">
                                                    <?php echo esc_html($vkl_prod->get_name()); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="button" id="vkl-camp-product-add"><?php esc_html_e('Insert product variables', 'vereskala-login'); ?></button>
                                    </p>
                                    <p class="description"><?php esc_html_e('Adds product, price and link lines to the parameters above. Use %product%, %price% and %link% in the matching pattern. These are the same for every recipient.', 'vereskala-login'); ?></p>
                                    <script>
                                    (function () {
                                        var sel = document.getElementById('vkl-camp-product');
                                        var btn = document.getElementById('vkl-camp-product-add');
                                        var area = document.querySelector('textarea[name="vkl_params"]');
                                        if (!sel || !btn || !area) { return; }
                                        btn.addEventListener('click', function () {
                                            var opt = sel.options[sel.selectedIndex];
                                            if (!opt || !opt.value) { return; }
                                            var lines = {
                                                product: opt.getAttribute('data-name') || '',
                                                price: opt.getAttribute('data-price') || '',
                                                link: opt.getAttribute('data-link') || ''
                                            };
                                            var text = area.value;
                                            Object.keys(lines).forEach(function (k) {
                                                // replace an existing key= line, else append
                                                var re = new RegExp('^' + k + '=.*$', 'm');
                                                if (re.test(text)) {
                                                    text = text.replace(re, k + '=' + lines[k]);
                                                } else {
                                                    text = (text.trim() ? text.trim() + '\n' : '') + k + '=' + lines[k];
                                                }
                                            });
                                            area.value = text;
                                        });
                                    })();
                                    </script>
                                </div>
                            <?php endif; ?>

                            <div style="background:#f5faf7;border:1px solid #dceee2;border-radius:8px;padding:10px 12px;margin-top:6px">
                                <strong style="font-size:13px">📋 <?php esc_html_e('Worked example', 'vereskala-login'); ?></strong>
                                <p class="description" style="direction:rtl;margin:6px 0">
                                    <?php esc_html_e('Pattern approved in the panel:', 'vereskala-login'); ?><br>
                                    «ورسکالایی گرامی {0}، {1} درصد تخفیف تا پایان هفته. کد: {2}»
                                </p>
                                <p class="description" style="margin:6px 0">
                                    <?php esc_html_e('What you write here — one key=value per line, in the SAME order as the blanks:', 'vereskala-login'); ?>
                                </p>
                                <code style="display:block;padding:6px 8px;background:#fff;border-radius:6px" dir="ltr">name=auto<br>discount=20<br>code=EID1405</code>
                                <p class="description" style="margin:8px 0 0">
                                    ⚠️ <?php esc_html_e('Melipayamak fills the blanks BY POSITION, not by name: the first line goes into the first blank, the second into the second. The key is only a label for you — change the order here and the values land in the wrong places.', 'vereskala-login'); ?>
                                </p>
                            </div>
                            <p class="description" style="margin-top:6px">
                                <?php esc_html_e('name=auto fills each recipient’s own name (falling back to “Dear customer”), so one campaign can still feel personal.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Schedule (site time)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="date" name="vkl_schedule_date">
                            <input type="time" name="vkl_schedule_time">
                            <p class="description"><?php esc_html_e('Leave empty to start sending within five minutes. With a date set, sending starts at that local site time.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Repeat (optional)', 'vereskala-login'); ?></th>
                        <td>
                            <div id="vkl-repeat-rows"></div>
                            <button type="button" class="button" id="vkl-repeat-add">+ <?php esc_html_e('Add day & time', 'vereskala-login'); ?></button>
                            <input type="hidden" name="vkl_repeat" id="vkl-repeat-json">
                            <p class="description"><?php esc_html_e('After each full send, the campaign re-queues itself for the next selected day & time (site timezone). Leave empty for a one-off campaign.', 'vereskala-login'); ?></p>
                            <script>
                            (function () {
                                var box = document.getElementById('vkl-repeat-rows');
                                var add = document.getElementById('vkl-repeat-add');
                                var out = document.getElementById('vkl-repeat-json');
                                if (!box || !add || !out) { return; }
                                var days = <?php echo wp_json_encode([
                                    '*' => __('Every day', 'vereskala-login'),
                                    '6' => __('Saturday', 'vereskala-login'),
                                    '0' => __('Sunday', 'vereskala-login'),
                                    '1' => __('Monday', 'vereskala-login'),
                                    '2' => __('Tuesday', 'vereskala-login'),
                                    '3' => __('Wednesday', 'vereskala-login'),
                                    '4' => __('Thursday', 'vereskala-login'),
                                    '5' => __('Friday', 'vereskala-login'),
                                ]); ?>;
                                function sync() {
                                    var rows = [];
                                    box.querySelectorAll('.vkl-repeat-row').forEach(function (r) {
                                        var t = r.querySelector('input[type=time]').value;
                                        if (t) { rows.push({ d: r.querySelector('select').value, t: t }); }
                                    });
                                    out.value = rows.length ? JSON.stringify(rows) : '';
                                }
                                add.addEventListener('click', function () {
                                    var row = document.createElement('div');
                                    row.className = 'vkl-repeat-row';
                                    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin:0 0 6px';
                                    var sel = document.createElement('select');
                                    Object.keys(days).forEach(function (k) {
                                        var o = document.createElement('option');
                                        o.value = k; o.textContent = days[k];
                                        sel.appendChild(o);
                                    });
                                    var time = document.createElement('input');
                                    time.type = 'time';
                                    var del = document.createElement('button');
                                    del.type = 'button'; del.className = 'button'; del.textContent = '✕';
                                    del.addEventListener('click', function () { row.remove(); sync(); });
                                    row.appendChild(sel); row.appendChild(time); row.appendChild(del);
                                    box.appendChild(row);
                                    sel.addEventListener('change', sync);
                                    time.addEventListener('change', sync);
                                });
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Max recipients', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="0" step="1" name="vkl_max_recipients" value="0">
                            <p class="description"><?php esc_html_e('Stop after this many messages (0 = send to the whole audience).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Queue campaign', 'vereskala-login')); ?>
            </form>

            <h2 class="title"><?php esc_html_e('Recent campaigns', 'vereskala-login'); ?></h2>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Title', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Audience', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Scheduled for', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Max recipients', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Sent', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Failed', 'vereskala-login'); ?></th>
                    <th style="width:120px"><?php esc_html_e('Actions', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Created (UTC)', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->marketing->latest() as $c) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $c['title']); ?></td>
                        <td>
                            <?php
                            $list_aud = (string) $c['audience'];
                            $list_win = '';
                            if (false !== strpos($list_aud, '@')) {
                                [$list_aud, $list_win] = explode('@', $list_aud, 2);
                            }
                            echo esc_html($audience_labels[$list_aud] ?? $list_aud);
                            if ('' !== $list_win) {
                                $lw = array_map('trim', explode(',', $list_win));
                                echo '<br><span class="description" dir="ltr" style="font-size:11px">' . esc_html(($lw[0] ?: '…') . ' → ' . ($lw[1] ?: '…')) . '</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html((string) $c['status']); ?><?php echo '' !== (string) ($c['repeat_json'] ?? '') ? ' 🔁' : ''; ?></td>
                        <td>
                            <?php
                            $scheduled = (string) ($c['scheduled_at'] ?? '');
                            echo '' !== $scheduled
                                ? esc_html(get_date_from_gmt($scheduled, get_option('date_format') . ' H:i'))
                                : esc_html__('Immediately', 'vereskala-login');
                            ?>
                        </td>
                        <td>
                            <?php
                            $cap = (int) ($c['max_recipients'] ?? 0);
                            echo $cap > 0 ? esc_html((string) $cap) : esc_html__('Unlimited', 'vereskala-login');
                            ?>
                        </td>
                        <td><?php echo esc_html((string) (int) $c['sent']); ?></td>
                        <td>
                            <?php if ((int) $c['failed'] > 0) : ?>
                                <details>
                                    <summary style="cursor:pointer;color:#d92b31;font-weight:700"><?php echo esc_html((string) (int) $c['failed']); ?> ⚠</summary>
                                    <?php foreach (vkl('sms.log')->failures_for('campaign' === (string) ($c['campaign_type'] ?? 'campaign') ? 'campaign.' . (int) $c['id'] : (string) $c['campaign_type'] . '.' . (int) $c['id']) as $vkl_fail) : ?>
                                        <div style="background:#fdeaea;border:1px solid #e8b3b3;border-radius:8px;padding:5px 8px;margin:4px 0;font-size:11px">
                                            <span dir="ltr"><?php echo esc_html((string) $vkl_fail['mobile']); ?></span> —
                                            <span dir="ltr" style="font-family:monospace;overflow-wrap:anywhere"><?php echo esc_html(mb_substr((string) $vkl_fail['response'], 0, 300)); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <p class="description"><?php esc_html_e('Full details per message: SMS Logs → Message details.', 'vereskala-login'); ?></p>
                                </details>
                            <?php else : ?>
                                <?php echo esc_html((string) (int) $c['failed']); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <details>
                                <summary style="cursor:pointer;color:#157f3a"><?php esc_html_e('Edit / actions', 'vereskala-login'); ?></summary>
                                <?php if (VKL_Sms_Marketing::STATUS_PENDING === (string) $c['status']) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0;max-width:340px">
                                        <input type="hidden" name="action" value="vkl_campaign_update">
                                        <input type="hidden" name="vkl_campaign_id" value="<?php echo (int) $c['id']; ?>">
                                        <?php $this->security->admin_nonce_field(); ?>
                                        <input type="text" name="vkl_title" value="<?php echo esc_attr((string) $c['title']); ?>" style="width:100%;margin-bottom:4px">
                                        <input type="text" dir="ltr" name="vkl_pattern" value="<?php echo esc_attr((string) $c['pattern_code']); ?>" style="width:100%;margin-bottom:4px" placeholder="pattern code">
                                        <textarea name="vkl_params" rows="2" style="width:100%;margin-bottom:4px" placeholder="key=value"><?php
                                        $edit_params = json_decode((string) ($c['params'] ?? ''), true) ?: [];

                                        foreach ($edit_params as $pk => $pv) {
                                            echo esc_textarea($pk . '=' . $pv) . "\n";
                                        }
                                        ?></textarea>
                                        <?php
                                        // Split any stored window off the base audience.
                                        $edit_aud  = (string) $c['audience'];
                                        $edit_win  = '';
                                        if (false !== strpos($edit_aud, '@')) {
                                            [$edit_aud, $edit_win] = explode('@', $edit_aud, 2);
                                        }
                                        $edit_wparts = '' !== $edit_win ? explode(',', $edit_win) : ['', ''];
                                        ?>
                                        <select name="vkl_audience" class="vkl-audience-select" data-target="vkl-window-edit-<?php echo (int) $c['id']; ?>" style="width:100%;margin-bottom:4px">
                                            <?php foreach ($audience_labels as $ak => $al) : ?>
                                                <option value="<?php echo esc_attr($ak); ?>" <?php selected($edit_aud, $ak); ?>><?php echo esc_html($al); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="vkl-window-row" id="vkl-window-edit-<?php echo (int) $c['id']; ?>" style="<?php echo in_array($edit_aud, ['buyers', 'all', 'wallet', 'club'], true) ? '' : 'display:none'; ?>margin-bottom:4px">
                                            <input type="date" name="vkl_window_from" value="<?php echo esc_attr(substr((string) ($edit_wparts[0] ?? ''), 0, 10)); ?>" style="width:48%" title="<?php esc_attr_e('From', 'vereskala-login'); ?>">
                                            <input type="date" name="vkl_window_to" value="<?php echo esc_attr(substr((string) ($edit_wparts[1] ?? ''), 0, 10)); ?>" style="width:48%" title="<?php esc_attr_e('To', 'vereskala-login'); ?>">
                                        </div>
                                        <input type="date" name="vkl_schedule_date" style="width:55%">
                                        <input type="time" name="vkl_schedule_time" style="width:40%">
                                        <?php submit_button(__('Save', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                      onsubmit="return confirm('<?php echo esc_js(__('Delete this campaign? Sending stops and it disappears from this list; already-sent messages stay in the log.', 'vereskala-login')); ?>');">
                                    <input type="hidden" name="action" value="vkl_campaign_delete">
                                    <input type="hidden" name="vkl_campaign_id" value="<?php echo (int) $c['id']; ?>">
                                    <?php $this->security->admin_nonce_field(); ?>
                                    <?php submit_button(__('Delete', 'vereskala-login'), 'delete small', 'submit', false); ?>
                                </form>
                            </details>
                        </td>
                        <td><?php echo esc_html((string) $c['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (isset($_GET['vkl_test'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice <?php echo '1' === (string) $_GET['vkl_test'] ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    echo '1' === (string) $_GET['vkl_test'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        ? esc_html__('Test message sent — check the phone and the SMS log.', 'vereskala-login')
                        : esc_html__('Test message FAILED — open SMS Logs → Message details for the gateway reason.', 'vereskala-login');
                ?></p></div>
            <?php endif; ?>

            <h2 class="title">🧪 <?php esc_html_e('Test send (before queueing a campaign)', 'vereskala-login'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:12px 16px;max-width:640px">
                <input type="hidden" name="action" value="vkl_campaign_test">
                <?php $this->security->admin_nonce_field(); ?>
                <p style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
                    <label><?php esc_html_e('Mobile', 'vereskala-login'); ?><br>
                        <input type="text" dir="ltr" name="vkl_test_mobile" style="width:150px" placeholder="0912xxxxxxx" required></label>
                    <label><?php esc_html_e('Pattern code', 'vereskala-login'); ?><br>
                        <input type="text" dir="ltr" name="vkl_pattern" style="width:150px" required></label>
                    <label style="flex:1;min-width:220px"><?php esc_html_e('Variables (key=value per line)', 'vereskala-login'); ?><br>
                        <textarea name="vkl_params" rows="2" style="width:100%"></textarea></label>
                    <?php submit_button(__('Send test', 'vereskala-login'), 'secondary', 'submit', false); ?>
                </p>
                <p class="description"><?php esc_html_e('Sends exactly what the campaign would send, to one number, and logs it — the cheapest way to catch a wrong variable before thousands go out.', 'vereskala-login'); ?></p>
            </form>

            <script>
            (function () {
                // Reveal the purchase-date range only for audiences that
                // have a date dimension (buyers / all / wallet / club).
                var windowed = ['buyers', 'all', 'wallet', 'club'];
                function bind(sel) {
                    var target = document.getElementById(sel.getAttribute('data-target'));
                    if (!target) { return; }
                    function sync() {
                        target.style.display = windowed.indexOf(sel.value) !== -1 ? '' : 'none';
                    }
                    sel.addEventListener('change', sync);
                    sync();
                }
                document.querySelectorAll('.vkl-audience-select').forEach(bind);
            })();
            </script>
        </div>
        <?php
    }

    /** Handle campaign creation. */
    public function handle_create_campaign(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $title    = sanitize_text_field(wp_unslash($_POST['vkl_title'] ?? ''));
        $pattern  = sanitize_text_field(wp_unslash($_POST['vkl_pattern'] ?? ''));
        $audience = sanitize_text_field(wp_unslash($_POST['vkl_audience'] ?? 'buyers'));
        $win_from = sanitize_text_field(wp_unslash($_POST['vkl_window_from'] ?? ''));
        $win_to   = sanitize_text_field(wp_unslash($_POST['vkl_window_to'] ?? ''));

        if ('' !== $win_from || '' !== $win_to) {
            $audience .= '@' . $win_from . ',' . $win_to;
        }
        $raw      = sanitize_textarea_field(wp_unslash($_POST['vkl_params'] ?? ''));
        $date     = sanitize_text_field(wp_unslash($_POST['vkl_schedule_date'] ?? ''));
        $time     = sanitize_text_field(wp_unslash($_POST['vkl_schedule_time'] ?? ''));
        $max      = absint($_POST['vkl_max_recipients'] ?? 0);
        // phpcs:enable

        // Local site time; empty date = send as soon as possible.
        $scheduled_at = '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $scheduled_at = $date . ' ' . (preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '00:00');
        }

        $params = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = array_map('trim', explode('=', $line, 2));

                if ('' !== $k) {
                    $params[$k] = $v;
                }
            }
        }

        if ('' !== $title && '' !== $pattern) {
            $vkl_repeat_raw = isset($_POST['vkl_repeat']) ? (string) wp_unslash($_POST['vkl_repeat']) : ''; // phpcs:ignore
            $vkl_repeat     = '';

            $vkl_rows = json_decode($vkl_repeat_raw, true);

            if (is_array($vkl_rows)) {
                $vkl_clean = [];

                foreach ($vkl_rows as $vkl_row) {
                    $vkl_d = (string) ($vkl_row['d'] ?? '*');
                    $vkl_t = (string) ($vkl_row['t'] ?? '');

                    if (preg_match('/^\d{2}:\d{2}$/', $vkl_t) && ('*' === $vkl_d || preg_match('/^[0-6]$/', $vkl_d))) {
                        $vkl_clean[] = ['d' => $vkl_d, 't' => $vkl_t];
                    }
                }

                $vkl_repeat = [] !== $vkl_clean ? (string) wp_json_encode(array_slice($vkl_clean, 0, 14)) : '';
            }

            $id = $this->marketing->create($title, $pattern, $params, $audience, $scheduled_at, $max, 'campaign', $vkl_repeat);

            vkl_dispatch('campaign.created', ['campaign_id' => $id]);
        }

        wp_safe_redirect(add_query_arg('created', '1', admin_url('admin.php?page=vkl-campaigns')));
        exit;
    }

    /** SMS log screen. */
    public function render_logs(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        // Tabs by message family + a WhatsApp channel tab.
        $tabs = [
            'all'      => [__('All', 'vereskala-login'), '', ''],
            'order'    => [__('Order notifications', 'vereskala-login'), 'order.', ''],
            'cart'     => [__('Cart Tracking', 'vereskala-login'), 'cart.', ''],
            'ticket'   => [__('Ticket notifications', 'vereskala-login'), 'ticket.', ''],
            'wallet'   => [__('Wallet notifications', 'vereskala-login'), 'wallet.', ''],
            'campaign' => [__('SMS Campaigns', 'vereskala-login'), 'campaign', ''],
            'survey'   => [__('Survey invitations', 'vereskala-login'), 'campaign.survey', ''],
            'credit'   => [__('Installment credit', 'vereskala-login'), 'credit.', ''],
            'birthday' => [__('Birthday greetings', 'vereskala-login'), 'birthday', ''],
            'whatsapp' => [__('WhatsApp', 'vereskala-login'), '', 'whatsapp'],
        ];

        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $tab    = isset($_GET['vkl_tab']) && isset($tabs[sanitize_key((string) $_GET['vkl_tab'])]) ? sanitize_key((string) $_GET['vkl_tab']) : 'all';
        $page   = isset($_GET['vkl_paged']) ? max(1, absint($_GET['vkl_paged'])) : 1;
        $search = isset($_GET['vkl_mobile']) ? sanitize_text_field(wp_unslash((string) $_GET['vkl_mobile'])) : '';
        // phpcs:enable

        [$tab_label, $type_prefix, $channel] = $tabs[$tab];

        $result = $this->log->paged($page, 30, $type_prefix, $channel, $search);
        $pages  = max(1, (int) ceil($result['total'] / 30));

        if (isset($_GET['vkl_purged'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %d: removed rows. */
                    __('%d log rows removed.', 'vereskala-login'),
                    absint($_GET['vkl_purged']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ))
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SMS Logs', 'vereskala-login'); ?></h1>

            <?php if (isset($_GET['vkl_resent'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php if ('1' === sanitize_text_field(wp_unslash($_GET['vkl_resent']))) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('SMS resent — the new attempt is logged at the top of the list.', 'vereskala-login'); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Resend failed — check the new log row for the gateway response.', 'vereskala-login'); ?></p></div>
                <?php endif; ?>
            <?php endif; ?>

            <ul class="subsubsub">
                <?php
                $links = [];

                foreach ($tabs as $key => [$label]) {
                    $url     = esc_url(add_query_arg(['page' => 'vkl-sms-logs', 'vkl_tab' => $key], admin_url('admin.php')));
                    $links[] = '<li><a href="' . $url . '"' . ($key === $tab ? ' class="current"' : '') . '>' . esc_html($label) . '</a></li>';
                }

                echo wp_kses_post(implode(' | ', $links));
                ?>
            </ul>
            <div style="clear:both"></div>

            <form method="get" style="margin:8px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="page" value="vkl-sms-logs">
                <input type="hidden" name="vkl_tab" value="<?php echo esc_attr($tab); ?>">
                <input type="search" name="vkl_mobile" value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Search by mobile…', 'vereskala-login'); ?>" dir="ltr">
                <button class="button"><?php esc_html_e('Apply', 'vereskala-login'); ?></button>
                <span class="description">
                    <?php
                    printf(
                        /* translators: %s: total row count. */
                        esc_html__('%s messages in this view.', 'vereskala-login'),
                        esc_html(number_format_i18n($result['total']))
                    );
                    ?>
                </span>
            </form>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Mobile', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Type', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Channel', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Pattern', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Message details', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Date (UTC)', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php if ([] === $result['rows']) : ?>
                    <tr><td colspan="7"><?php esc_html_e('No messages logged yet. If you expected messages here, check the toggles and patterns above first.', 'vereskala-login'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($result['rows'] as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($this->security->mask_mobile((string) $row['mobile'])); ?></td>
                        <td><?php echo esc_html((string) $row['type']); ?></td>
                        <td><?php echo esc_html((string) ($row['channel'] ?? 'sms')); ?></td>
                        <td><?php echo esc_html((string) $row['pattern_code']); ?></td>
                        <?php $vkl_failed = !in_array((string) $row['status'], ['sent', 'ok', 'success'], true); ?>
                        <td>
                            <span style="color:<?php echo $vkl_failed ? '#d92b31' : '#157f3a'; ?>;font-weight:600">
                                <?php echo esc_html((string) $row['status']); ?>
                            </span>
                        </td>
                        <td>
                            <details>
                                <summary style="cursor:pointer;color:#157f3a"><?php esc_html_e('View', 'vereskala-login'); ?></summary>
                                <?php
                                // The message content = pattern variables (the
                                // template body lives at the SMS provider).
                                $vkl_payload = json_decode((string) ($row['payload'] ?? ''), true);

                                if (is_array($vkl_payload) && [] !== $vkl_payload) {
                                    echo '<div style="margin:6px 0;line-height:2">';

                                    foreach ($vkl_payload as $vkl_key => $vkl_value) {
                                        echo '<code style="direction:ltr;display:inline-block;margin-inline-end:6px">'
                                            . esc_html((string) $vkl_key . ' = ' . (is_scalar($vkl_value) ? (string) $vkl_value : wp_json_encode($vkl_value)))
                                            . '</code>';
                                    }

                                    echo '</div>';

                                    // What actually went into the gateway's
                                    // "text" field. The panel answers
                                    // "InvalidData" without naming a field,
                                    // so the number of values and their order
                                    // is the only thing an operator can
                                    // compare against the approved pattern.
                                    if ('sms' === (string) ($row['channel'] ?? 'sms')
                                        && class_exists('VKL_Melipayamak')
                                    ) {
                                        $vkl_wire = VKL_Melipayamak::pattern_text($vkl_payload);

                                        echo '<div class="description" style="margin:2px 0 6px">'
                                            . esc_html(sprintf(
                                                /* translators: %d: number of values sent to the SMS gateway. */
                                                __('Sent to the gateway as one text field, %d values separated by semicolons:', 'vereskala-login'),
                                                count($vkl_payload)
                                            ))
                                            . '<br><code dir="ltr" style="overflow-wrap:anywhere">'
                                            . esc_html($vkl_wire)
                                            . '</code></div>';
                                    }
                                }
                                ?>
                                <?php if ($vkl_failed && '' !== (string) ($row['response'] ?? '')) : ?>
                                    <div style="background:#fdeaea;border:1px solid #e8b3b3;border-radius:8px;padding:6px 10px;margin-top:4px">
                                        <strong><?php esc_html_e('Failure reason (gateway response):', 'vereskala-login'); ?></strong>
                                        <div dir="ltr" style="font-family:monospace;font-size:11px;overflow-wrap:anywhere">
                                            <?php echo esc_html(mb_substr((string) $row['response'], 0, 600)); ?>
                                        </div>
                                        <?php if (false !== stripos((string) $row['response'], 'invaliddata')) : ?>
                                            <p class="description" style="margin:6px 0 0">
                                                <?php esc_html_e('The panel rejected the values without saying which one. It is not sent from here again until something changes at the panel: the approved pattern must expect exactly as many variables as were sent above, in the same order, and shared-line patterns usually refuse a variable that carries a web address or an unapproved word. Try the same pattern ID with the same values in the provider\'s own test form — whichever combination is accepted there tells you what to change.', 'vereskala-login'); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif ('' !== (string) ($row['response'] ?? '')) : ?>
                                    <div dir="ltr" class="description" style="font-family:monospace;font-size:11px;overflow-wrap:anywhere;margin-top:4px">
                                        <?php echo esc_html(mb_substr((string) $row['response'], 0, 200)); ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ('sms' === (string) ($row['channel'] ?? 'sms') && '' !== (string) ($row['pattern_code'] ?? '')) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px"
                                          onsubmit="return confirm('<?php echo esc_js(__('Send this exact SMS to this number again?', 'vereskala-login')); ?>');">
                                        <input type="hidden" name="action" value="vkl_sms_resend">
                                        <input type="hidden" name="vkl_log_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="vkl_tab" value="<?php echo esc_attr(isset($_GET['vkl_tab']) ? sanitize_key($_GET['vkl_tab']) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
                                        <?php $this->security->admin_nonce_field(); ?>
                                        <?php submit_button('🔁 ' . __('Resend', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                            </details>
                        </td>
                        <td><?php echo esc_html((string) $row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'    => esc_url_raw(add_query_arg('vkl_paged', '%#%')),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                    ]) ?? '');
                    ?>
                </div></div>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Maintenance', 'vereskala-login'); ?></h2>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vkl_log_export">
                    <input type="hidden" name="vkl_tab" value="<?php echo esc_attr($tab); ?>">
                    <input type="hidden" name="vkl_mobile" value="<?php echo esc_attr($search); ?>">
                    <?php $this->security->admin_nonce_field(); ?>
                    <?php submit_button(__('Export this view as CSV', 'vereskala-login'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('<?php echo esc_js(__('Remove log rows older than 90 days?', 'vereskala-login')); ?>');">
                    <input type="hidden" name="action" value="vkl_log_purge">
                    <input type="hidden" name="vkl_days" value="90">
                    <?php $this->security->admin_nonce_field(); ?>
                    <?php submit_button(__('Delete older than 90 days', 'vereskala-login'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('<?php echo esc_js(__('Delete the ENTIRE message history? Export a CSV first — this cannot be undone.', 'vereskala-login')); ?>');">
                    <input type="hidden" name="action" value="vkl_log_purge">
                    <input type="hidden" name="vkl_days" value="0">
                    <?php $this->security->admin_nonce_field(); ?>
                    <?php submit_button(__('Delete everything', 'vereskala-login'), 'delete', 'submit', false); ?>
                </form>
            </div>
            <p class="description"><?php esc_html_e('Recommendation: export a CSV archive before purging; keep at least the last 90 days for dispute resolution.', 'vereskala-login'); ?></p>
        </div>
        <?php
    }

    /**
     * Purge log rows (admin-post).
     */
    public function handle_log_purge(): void
    {
        $this->security->verify_admin_request();

        $removed = $this->log->purge(isset($_POST['vkl_days']) ? absint($_POST['vkl_days']) : 90);

        wp_safe_redirect(admin_url('admin.php?page=vkl-sms-logs&vkl_purged=' . max(0, $removed)));
        exit;
    }

    /**
     * Stream the current view as CSV (admin-post).
     */
    public function handle_log_export(): void
    {
        $this->security->verify_admin_request();

        $tabs = [
            'all'      => ['', ''],
            'order'    => ['order.', ''],
            'cart'     => ['cart.', ''],
            'ticket'   => ['ticket.', ''],
            'wallet'   => ['wallet.', ''],
            'campaign' => ['campaign', ''],
            'survey'   => ['campaign.survey', ''],
            'credit'   => ['credit.', ''],
            'birthday' => ['birthday', ''],
            'whatsapp' => ['', 'whatsapp'],
        ];

        $tab    = isset($_POST['vkl_tab']) && isset($tabs[sanitize_key((string) $_POST['vkl_tab'])]) ? sanitize_key((string) $_POST['vkl_tab']) : 'all';
        $search = isset($_POST['vkl_mobile']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_mobile'])) : '';

        [$type_prefix, $channel] = $tabs[$tab];

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=vereskonto-sms-log-' . gmdate('Ymd-His') . '.csv');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel.
        fputcsv($out, ['id', 'mobile', 'type', 'channel', 'pattern', 'status', 'response', 'created_at']);

        foreach ($this->log->iterate($type_prefix, $channel, $search) as $row) {
            fputcsv($out, [
                (string) ($row['id'] ?? ''),
                (string) ($row['mobile'] ?? ''),
                (string) ($row['type'] ?? ''),
                (string) ($row['channel'] ?? 'sms'),
                (string) ($row['pattern_code'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['response'] ?? ''),
                (string) ($row['created_at'] ?? ''),
            ]);
        }

        exit;
    }

    /**
     * Edit a pending/scheduled campaign (admin-post).
     */
    public function handle_campaign_update(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $id       = absint($_POST['vkl_campaign_id'] ?? 0);
        $title    = sanitize_text_field(wp_unslash($_POST['vkl_title'] ?? ''));
        $pattern  = sanitize_text_field(wp_unslash($_POST['vkl_pattern'] ?? ''));
        $audience = sanitize_text_field(wp_unslash($_POST['vkl_audience'] ?? 'buyers'));
        $win_from = sanitize_text_field(wp_unslash($_POST['vkl_window_from'] ?? ''));
        $win_to   = sanitize_text_field(wp_unslash($_POST['vkl_window_to'] ?? ''));

        if ('' !== $win_from || '' !== $win_to) {
            $audience .= '@' . $win_from . ',' . $win_to;
        }
        $raw      = sanitize_textarea_field(wp_unslash($_POST['vkl_params'] ?? ''));
        $date     = sanitize_text_field(wp_unslash($_POST['vkl_schedule_date'] ?? ''));
        $time     = sanitize_text_field(wp_unslash($_POST['vkl_schedule_time'] ?? ''));
        // phpcs:enable

        $scheduled_at = '';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $scheduled_at = $date . ' ' . (preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '00:00');
        }

        $params = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = explode('=', $line, 2);

            if (2 === count($parts) && '' !== trim($parts[0])) {
                $params[trim($parts[0])] = trim($parts[1]);
            }
        }

        if ($id > 0 && '' !== $title && '' !== $pattern) {
            $this->marketing->update_campaign($id, $title, $pattern, $params, $audience, $scheduled_at);
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-campaigns'));
        exit;
    }

    /**
     * Delete a campaign (admin-post).
     */
    public function handle_campaign_delete(): void
    {
        $this->security->verify_admin_request();

        $this->marketing->delete_campaign(absint($_POST['vkl_campaign_id'] ?? 0));

        wp_safe_redirect(admin_url('admin.php?page=vkl-campaigns'));
        exit;
    }

    /**
     * One-number test send (admin-post).
     */
    public function handle_campaign_test(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $mobile  = isset($_POST['vkl_test_mobile']) ? sanitize_text_field(wp_unslash($_POST['vkl_test_mobile'])) : '';
        $pattern = isset($_POST['vkl_pattern']) ? sanitize_text_field(wp_unslash($_POST['vkl_pattern'])) : '';
        $raw     = isset($_POST['vkl_params']) ? sanitize_textarea_field(wp_unslash($_POST['vkl_params'])) : '';
        // phpcs:enable

        $params = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = explode('=', $line, 2);

            if (2 === count($parts) && '' !== trim($parts[0])) {
                $params[trim($parts[0])] = trim($parts[1]);
            }
        }

        $ok = false;

        if ('' !== $mobile && '' !== $pattern) {
            $ok = vkl('sms.sender')->send(vkl('validator')->normalize_mobile($mobile), 'campaign.test', $pattern, $params);
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-campaigns&vkl_test=' . ($ok ? '1' : '0')));
        exit;
    }

    /**
     * Resend one logged SMS: same mobile, same pattern, same variables.
     * The attempt is logged again like any other send, so the report
     * stays a complete history.
     */
    public function handle_sms_resend(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $id  = isset($_POST['vkl_log_id']) ? absint($_POST['vkl_log_id']) : 0;
        $tab = isset($_POST['vkl_tab']) ? sanitize_key($_POST['vkl_tab']) : '';
        // phpcs:enable

        $row = $id > 0 ? vkl('sms.log')->find($id) : null;

        $ok = false;

        if (null !== $row
            && 'sms' === (string) ($row['channel'] ?? 'sms')
            && '' !== (string) ($row['pattern_code'] ?? '')
        ) {
            $params = json_decode((string) ($row['payload'] ?? ''), true);

            $result = vkl('sms.sender')->send_bulk(
                [(string) $row['mobile']],
                (string) $row['type'],
                (string) $row['pattern_code'],
                is_array($params) ? $params : []
            );

            $ok = ($result['sent'] ?? 0) > 0;
        }

        wp_safe_redirect(add_query_arg(array_filter([
            'page'       => 'vkl-sms-logs',
            'vkl_tab'    => '' !== $tab ? $tab : null,
            'vkl_resent' => $ok ? '1' : '0',
        ]), admin_url('admin.php')));
        exit;
    }

    /**
     * Process one queue batch right now. WP-Cron only fires on traffic
     * (and is disabled outright on some hosts), so the admin needs a way
     * to push the queue without waiting.
     */
    public function handle_campaign_run(): void
    {
        $this->security->verify_admin_request();

        $this->marketing->process_now();

        wp_safe_redirect(add_query_arg(['page' => 'vkl-campaigns', 'vkl_ran' => 1], admin_url('admin.php')));
        exit;
    }
}
