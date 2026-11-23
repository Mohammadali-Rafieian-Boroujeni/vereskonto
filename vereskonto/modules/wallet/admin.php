<?php
/**
 * Wallet admin — credit/debit a customer, recent transactions.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Admin
 */
final class VKL_Wallet_Admin
{
    /**
     * Settings group for wallet/checkout options.
     */
    private const SETTINGS_GROUP = 'vkl_wallet_settings';
    private const AUTO_GROUP     = 'vkl_promo_auto_settings';

    private VKL_Wallet_Service $wallet;

    private VKL_Wallet_Repository $repository;

    private VKL_Security $security;

    private VKL_Config $config;

    private VKL_Wallet_Installments $installments;

    public function __construct(
        VKL_Wallet_Service $wallet,
        VKL_Wallet_Repository $repository,
        VKL_Security $security,
        VKL_Config $config,
        VKL_Wallet_Installments $installments
    ) {
        $this->wallet     = $wallet;
        $this->repository = $repository;
        $this->security   = $security;
        $this->config       = $config;
        $this->installments = $installments;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vkl_wallet_adjust', [$this, 'handle_adjust']);
        add_action('admin_post_vkl_installment_create', [$this, 'handle_installment_create']);
        add_action('admin_post_vkl_arrears_sms', [$this, 'handle_arrears_sms']);
        add_action('admin_post_vkl_promo_auto_clear', [$this, 'handle_promo_auto_clear']);
        add_action('admin_post_vkl_due_sms', [$this, 'handle_due_sms']);
        add_action('admin_post_vkl_installment_update', [$this, 'handle_installment_update']);
        add_action('admin_post_vkl_raise_credit', [$this, 'handle_raise_credit']);
        add_action('admin_post_vkl_installment_delete', [$this, 'handle_installment_delete']);
        add_action('admin_post_vkl_installment_remind', [$this, 'handle_installment_remind']);
        add_action('admin_post_vkl_carrier_reclassify', [$this, 'handle_carrier_reclassify']);
        add_action('admin_post_vkl_recover_stuck_plans', [$this, 'handle_recover_stuck_plans']);
        add_action('admin_post_vkl_installment_offline', [$this, 'handle_installment_offline']);
        add_action('admin_post_vkl_installment_offline_delete', [$this, 'handle_installment_offline_delete']);
        add_action('admin_post_vkl_promo_grant', [$this, 'handle_promo_grant']);
        add_action('admin_post_vkl_promo_update', [$this, 'handle_promo_update']);
        add_action('admin_post_vkl_promo_delete', [$this, 'handle_promo_delete']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Wallet/checkout settings.
     */
    public function register_settings(): void
    {
        $bool = static fn(mixed $v): string => '1' === (string) $v ? '1' : '0';

        foreach ([
            'installment_penalty_percent' => 'absint',
            'installment_first_due_gap'   => 'absint',
            'installment_due_window'      => 'absint',
            'carrier_order_status'        => 'absint',
            'carrier_order_silent'        => 'absint',
            'installment_notice_pattern'  => 'sanitize_text_field',
            'installment_due_pattern'     => 'sanitize_text_field',
            'installment_notice_day'      => 'absint',
            'installment_chase_day'       => 'absint',
        ] as $inst_key => $inst_sanitize) {
            register_setting('vkl_installment_settings', $this->config->option_name($inst_key), [
                'type'              => 'string',
                'sanitize_callback' => $inst_sanitize,
            ]);
        }

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_amount'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_min_order'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_delay_hours'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_use_min'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_title'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::AUTO_GROUP, $this->config->option_name('promo_auto_pattern'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('wallet_checkout_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => $bool,
            'default'           => '1',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('wallet_topup_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => $bool,
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('checkout_require_login'), [
            'type'              => 'string',
            'sanitize_callback' => $bool,
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('shipping_step_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => $bool,
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('shipping_step_page'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('wallet_topup_min'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('wallet_topup_max'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
    }

    /**
     * Load WooCommerce's enhanced select (select2 + AJAX customer search)
     * on the wallet screen so the user field is a searchable dropdown.
     */
    public function enqueue_assets(string $hook): void
    {
        // The plan form moved to the Loans screen in 1.86.0 but this
        // check did not follow it, so the customer dropdown there stayed
        // an empty box: no select2, no AJAX search, no customers.
        if ((false === strpos($hook, 'vkl-wallet') && false === strpos($hook, 'vkl-credit-customers'))
            || !class_exists('WooCommerce')
        ) {
            return;
        }

        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Wallet', 'vereskala-login'),
            __('Wallet', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-wallet',
            [$this, 'render']
        );

        add_submenu_page(
            'vereskala-login',
            __('Loans', 'vereskala-login'),
            __('Loans', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-credit-customers',
            [$this, 'render_credit_customers']
        );
    }

    /**
     * Credit customers overview: debt, remaining installments, reminders.
     */
    /**
     * Group reminder to everyone whose payment has just fallen due.
     */
    public function handle_due_sms(): void
    {
        $this->security->verify_admin_request();

        $pattern = isset($_POST['vkl_due_pattern']) ? sanitize_text_field(wp_unslash($_POST['vkl_due_pattern'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sent    = $this->installments->remind_due_now($pattern);

        wp_safe_redirect(add_query_arg([
            'page'         => 'vkl-credit-customers',
            'vkl_due_sent' => $sent,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Group reminder to everyone in arrears (admin-post).
     */
    public function handle_arrears_sms(): void
    {
        $this->security->verify_admin_request();

        $pattern = isset($_POST['vkl_arrears_pattern']) ? sanitize_text_field(wp_unslash($_POST['vkl_arrears_pattern'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $sent    = $this->installments->remind_arrears($pattern);

        wp_safe_redirect(add_query_arg([
            'page'             => 'vkl-credit-customers',
            'vkl_arrears_sent' => $sent,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Disable the automatic campaign and reset its settings to defaults.
     */
    public function handle_promo_auto_clear(): void
    {
        $this->security->verify_admin_request();

        foreach (['promo_auto_enabled' => '0', 'promo_auto_amount' => 0, 'promo_auto_min_order' => 0,
                  'promo_auto_delay_hours' => 0, 'promo_auto_days' => 7, 'promo_auto_use_min' => 0,
                  'promo_auto_title' => '', 'promo_auto_pattern' => ''] as $key => $val) {
            update_option($this->config->option_name($key), $val);
        }

        wp_safe_redirect(add_query_arg([
            'page'             => 'vkl-wallet',
            'vkl_auto_cleared' => 1,
        ], admin_url('admin.php')) . '#vkl-auto-form');
        exit;
    }

    /**
     * A cancelled/refunded order behind a still-open PLAN (not a credit
     * row — those already went back to credit automatically whenever
     * nothing had been paid yet) means the order was undone AFTER at
     * least one instalment was collected. restore_credit() deliberately
     * leaves that case alone — a partial repayment cannot be reversed
     * automatically without knowing whether the shop wants to keep the
     * money, refund it, or credit it back — so instead of silently
     * looking like ordinary debt, it needs to visibly ask for a human
     * decision.
     */
    private function plan_order_warning(array $vkl_plan): string
    {
        if ('plan' !== (string) ($vkl_plan['type'] ?? 'plan')) {
            return '';
        }

        $order_id = (int) ($vkl_plan['order_id'] ?? 0);

        if ($order_id <= 0 || !function_exists('wc_get_order')) {
            return '';
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order || !in_array($order->get_status(), ['cancelled', 'refunded'], true)) {
            return '';
        }

        return 'cancelled' === $order->get_status()
            ? 'سفارش این طرح لغو شده — نیاز به بررسی دستی'
            : 'سفارش این طرح مرجوع شده — نیاز به بررسی دستی';
    }

    /**
     * One plan/credit row: title, status, and its Record payment / Edit /
     * Delete controls. Pulled out of render_credit_customers() so the
     * same row markup can be reused across the active/credit/settled
     * groups instead of duplicated three times.
     *
     * @param array<string, mixed>                            $vkl_plan
     * @param array<int, array<int, array<string, mixed>>>    $vkl_pay_map
     */
    private function render_plan_row(array $vkl_plan, array $vkl_pay_map): void
    {
        $vkl_left_on_plan = (float) $vkl_plan['total'] - (float) $vkl_plan['paid'];
        $vkl_warning      = $this->plan_order_warning($vkl_plan);
        ?>
        <div style="display:flex;align-items:center;gap:8px;margin:6px 0;flex-wrap:wrap">
            <span style="flex:1;min-width:140px"><?php echo esc_html((string) $vkl_plan['title']); ?>
                — <?php echo wp_kses_post(wc_price(max(0.0, $vkl_left_on_plan))); ?>
                <?php if ($vkl_left_on_plan <= 0) : ?>
                    <span style="color:#157f3a;font-size:11.5px">✅ <?php esc_html_e('settled', 'vereskala-login'); ?></span>
                <?php endif; ?>
                <?php if ('plan' === (string) ($vkl_plan['type'] ?? 'plan')) : ?>
                    <span class="description" style="font-size:11.5px">📅 <?php
                        printf(
                            /* translators: %s: payment window, e.g. "1 to 5". */
                            esc_html__('payment window: %s', 'vereskala-login'),
                            esc_html($this->installments->window_label($vkl_plan))
                        );
                    ?></span>
                <?php endif; ?>
                <?php if ($this->installments->outstanding_penalty($vkl_plan) > 0) : ?>
                    <span style="color:#8a2b2b;font-size:11.5px">⚠️ <?php
                        printf(
                            /* translators: %s: unpaid late fee. */
                            esc_html__('unpaid late fee %s — added to the next installment', 'vereskala-login'),
                            wp_kses_post(wc_price($this->installments->outstanding_penalty($vkl_plan)))
                        );
                    ?></span>
                <?php endif; ?>
                <?php if ('credit' === (string) ($vkl_plan['type'] ?? 'plan')) : ?>
                    <span style="color:#8a6d1f;font-size:11.5px">💳 <?php esc_html_e('purchase credit', 'vereskala-login'); ?></span>
                <?php endif; ?>
                <?php if ('' !== $vkl_warning) : ?>
                    <span style="display:block;color:#b32d2e;font-weight:700;font-size:11.5px;margin-top:2px">🚫 <?php echo esc_html($vkl_warning); ?></span>
                <?php endif; ?>
            </span>
            <?php if ($vkl_left_on_plan > 0 && 'credit' !== (string) ($vkl_plan['type'] ?? 'plan')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="vkl_installment_remind">
                    <input type="hidden" name="vkl_plan_id" value="<?php echo (int) $vkl_plan['id']; ?>">
                    <?php $this->security->admin_nonce_field(); ?>
                    <?php submit_button(__('Send reminder SMS', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                </form>

                <button type="button" class="button button-small vkl-plan-pay"
                        data-plan="<?php echo (int) $vkl_plan['id']; ?>">
                    💵 <?php esc_html_e('Record a payment', 'vereskala-login'); ?>
                </button>
            <?php endif; ?>

            <?php if ('plan' === (string) ($vkl_plan['type'] ?? 'plan')) : ?>
                <a class="button button-small" target="_blank" rel="noopener"
                   href="<?php echo esc_url(VKL_Wallet_Statement::url((int) $vkl_plan['id'])); ?>">
                    🧾 <?php esc_html_e('Statement', 'vereskala-login'); ?>
                </a>
            <?php endif; ?>

            <button type="button" class="button button-small vkl-plan-edit"
                    data-plan="<?php echo (int) $vkl_plan['id']; ?>">
                <?php esc_html_e('Edit', 'vereskala-login'); ?>
            </button>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete this plan? Any amount already repaid stays recorded in the wallet history, but the plan itself disappears.', 'vereskala-login'))); ?>);">
                <input type="hidden" name="action" value="vkl_installment_delete">
                <input type="hidden" name="vkl_plan_id" value="<?php echo (int) $vkl_plan['id']; ?>">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Delete', 'vereskala-login'), 'small', 'submit', false, ['style' => 'color:#b32d2e']); ?>
            </form>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              id="vkl-plan-pay-<?php echo (int) $vkl_plan['id']; ?>"
              style="display:none;margin:4px 0 12px;padding:10px 12px;background:#f4f9ff;border:1px solid #cfe0f2;border-radius:8px">
            <input type="hidden" name="action" value="vkl_installment_offline">
            <input type="hidden" name="vkl_plan_id" value="<?php echo (int) $vkl_plan['id']; ?>">
            <?php $this->security->admin_nonce_field(); ?>
            <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0">
                <label><?php esc_html_e('Amount received', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_amount" step="1" min="1" required style="width:130px"
                           value="<?php echo esc_attr((string) (int) $this->installments->next_amount($vkl_plan)); ?>"></label>
                <label><?php esc_html_e('How it was paid', 'vereskala-login'); ?><br>
                    <select name="vkl_method">
                        <option value="card"><?php esc_html_e('Card to card', 'vereskala-login'); ?></option>
                        <option value="bank"><?php esc_html_e('Bank transfer', 'vereskala-login'); ?></option>
                        <option value="cash"><?php esc_html_e('Cash', 'vereskala-login'); ?></option>
                        <option value="other"><?php esc_html_e('Other', 'vereskala-login'); ?></option>
                    </select></label>
                <label><?php esc_html_e('Reference / tracking number', 'vereskala-login'); ?><br>
                    <input type="text" dir="ltr" name="vkl_reference" style="width:170px"></label>
                <label style="flex:1;min-width:150px"><?php esc_html_e('Note', 'vereskala-login'); ?><br>
                    <input type="text" name="vkl_note" style="width:100%"></label>
                <?php submit_button(__('Record payment', 'vereskala-login'), 'primary small', 'submit', false); ?>
            </p>
            <p class="description" style="margin:6px 0 0">
                <?php esc_html_e('The amount is deducted from this plan straight away and the customer gets the same confirmation SMS as an online payment. More than what is still owed cannot be recorded.', 'vereskala-login'); ?>
            </p>
        </form>

        <?php $vkl_paid_rows = $vkl_pay_map[(int) $vkl_plan['id']] ?? []; ?>
        <?php if ([] !== $vkl_paid_rows) : ?>
            <div style="margin:0 0 12px;padding:8px 12px;background:#fbfcfb;border:1px solid #e6ece8;border-radius:8px;font-size:12.5px">
                <strong><?php esc_html_e('Payments recorded by hand', 'vereskala-login'); ?></strong>
                <?php foreach ($vkl_paid_rows as $vkl_pay) : ?>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:5px">
                        <span style="flex:1;min-width:150px">
                            <?php echo wp_kses_post(wc_price((float) $vkl_pay['amount'])); ?>
                            — <?php echo esc_html($this->installments->payment_method_label((string) $vkl_pay['method'])); ?>
                            <?php if ('' !== (string) $vkl_pay['reference']) : ?>
                                <span dir="ltr" style="color:#7a8a80">(<?php echo esc_html((string) $vkl_pay['reference']); ?>)</span>
                            <?php endif; ?>
                            <span class="description" dir="ltr" style="font-size:11px"><?php echo esc_html($this->jalali_date((string) $vkl_pay['created_at'])); ?></span>
                            <?php if ('' !== (string) $vkl_pay['note']) : ?>
                                <div class="description" style="font-size:11.5px"><?php echo esc_html((string) $vkl_pay['note']); ?></div>
                            <?php endif; ?>
                        </span>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                              onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Reverse this recorded payment? The amount goes back onto the plan.', 'vereskala-login'))); ?>);">
                            <input type="hidden" name="action" value="vkl_installment_offline_delete">
                            <input type="hidden" name="vkl_payment_id" value="<?php echo (int) $vkl_pay['id']; ?>">
                            <?php $this->security->admin_nonce_field(); ?>
                            <?php submit_button(__('Reverse', 'vereskala-login'), 'small', 'submit', false, ['style' => 'color:#b32d2e']); ?>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              id="vkl-plan-edit-<?php echo (int) $vkl_plan['id']; ?>"
              style="display:none;margin:4px 0 12px;padding:10px 12px;background:#f6faf8;border:1px solid #dceee2;border-radius:8px">
            <input type="hidden" name="action" value="vkl_installment_update">
            <input type="hidden" name="vkl_plan_id" value="<?php echo (int) $vkl_plan['id']; ?>">
            <?php $this->security->admin_nonce_field(); ?>
            <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin:0">
                <label style="flex:2;min-width:150px"><?php esc_html_e('Title', 'vereskala-login'); ?><br>
                    <input type="text" name="vkl_title" class="regular-text" style="width:100%"
                           value="<?php echo esc_attr((string) $vkl_plan['title']); ?>"></label>
                <label><?php esc_html_e('Total', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_total" step="1" min="0" style="width:120px"
                           value="<?php echo esc_attr((string) (int) $vkl_plan['total']); ?>"></label>
                <label><?php esc_html_e('Instalments', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_count" step="1" min="1" style="width:80px"
                           value="<?php echo esc_attr((string) (int) $vkl_plan['installment_count']); ?>"></label>
                <label><?php esc_html_e('Due day (Jalali)', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_due_day" step="1" min="1" max="31" style="width:80px"
                           value="<?php echo esc_attr((string) (int) $vkl_plan['due_day']); ?>"></label>
                <label><?php esc_html_e('Late fee % (empty = shop rate)', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_penalty_percent" step="0.5" min="0" max="100" style="width:110px"
                           value="<?php echo (float) ($vkl_plan['penalty_percent'] ?? -1) >= 0 ? esc_attr((string) (float) $vkl_plan['penalty_percent']) : ''; ?>"></label>
                <label><?php esc_html_e('Late fee accrued', 'vereskala-login'); ?><br>
                    <input type="number" name="vkl_penalty" step="1" min="0" style="width:120px"
                           value="<?php echo esc_attr((string) (int) ($vkl_plan['penalty'] ?? 0)); ?>"></label>
                <?php submit_button(__('Save plan', 'vereskala-login'), 'primary small', 'submit', false); ?>
            </p>
            <p class="description" style="margin:6px 0 0">
                <?php esc_html_e('Amounts already repaid are not touched; changing the total only changes what is still owed. Lowering the accrued late fee lowers the total by the same amount, because every fee was added to both.', 'vereskala-login'); ?>
            </p>
            <p class="description" style="margin:2px 0 0">
                <?php esc_html_e('Leave the late-fee percentage empty to follow the shop-wide rate; enter 0 to charge no fee on this plan at all.', 'vereskala-login'); ?>
            </p>
        </form>
        <?php
    }

    public function render_credit_customers(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        if (isset($_GET['vkl_due_sent'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: message count. */
                    esc_html__('Group SMS queued for %s customers whose payment has fallen due.', 'vereskala-login'),
                    esc_html(number_format_i18n(absint($_GET['vkl_due_sent']))) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                )
            );
        }

        if (isset($_GET['vkl_arrears_sent'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %s: message count. */
                    esc_html__('Group SMS queued for %s customers in arrears.', 'vereskala-login'),
                    esc_html(number_format_i18n(absint($_GET['vkl_arrears_sent']))) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                )
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $vkl_offline_result = isset($_GET['vkl_offline']) ? sanitize_key(wp_unslash((string) $_GET['vkl_offline'])) : '';

        if ('' !== $vkl_offline_result) {
            $vkl_offline_msg = [
                'ok'       => __('Payment recorded — the plan has been reduced by that amount.', 'vereskala-login'),
                'noplan'   => __('Nothing recorded: that plan no longer exists.', 'vereskala-login'),
                'noamount' => __('Nothing recorded: enter an amount greater than zero.', 'vereskala-login'),
                'settled'  => __('Nothing recorded: this plan is already fully repaid.', 'vereskala-login'),
                'credit'   => __('Nothing recorded: this row is purchase credit, not a debt. It becomes repayable once the customer spends it.', 'vereskala-login'),
                'removed'  => __('The recorded payment was reversed and the plan restored.', 'vereskala-login'),
            ];

            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                in_array($vkl_offline_result, ['ok', 'removed'], true) ? 'notice-success' : 'notice-error',
                esc_html($vkl_offline_msg[$vkl_offline_result] ?? '')
            );
        }

        if (isset($_GET['vkl_raised'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                '1' === (string) $_GET['vkl_raised'] ? 'notice-success' : 'notice-error', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                '1' === (string) $_GET['vkl_raised'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    ? esc_html__('The credit ceiling was raised.', 'vereskala-login')
                    : esc_html__('Nothing was changed — the customer has no open plan to raise.', 'vereskala-login')
            );
        }

        if (isset($_GET['vkl_reminded'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                '1' === (string) $_GET['vkl_reminded'] ? 'notice-success' : 'notice-error', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                '1' === (string) $_GET['vkl_reminded'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    ? esc_html__('Reminder SMS sent.', 'vereskala-login')
                    : esc_html__('Reminder not sent — check the reminder pattern and the customer mobile.', 'vereskala-login')
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Installments & credit', 'vereskala-login'); ?></h1>

            <?php
            $vkl_due      = $this->installments->due_now();
            $vkl_arrears  = $this->installments->arrears();
            $vkl_requests = vkl()->has('wallet.credit_requests') ? count(vkl('wallet.credit_requests')->pending()) : 0;
            $vkl_views    = [
                'due'        => [__('Due & in arrears', 'vereskala-login'), count($vkl_due) + count($vkl_arrears)],
                'customers'  => __('All credit customers', 'vereskala-login'),
                'requests'   => [__('Credit requests', 'vereskala-login'), $vkl_requests],
                'accounting' => __('Accounting', 'vereskala-login'),
                'settings'   => __('Settings', 'vereskala-login'),
            ];
            $vkl_view = VKL_Admin_Tabs::current_view($vkl_views);
            VKL_Admin_Tabs::render_views('vkl-credit-customers', $vkl_views, $vkl_view);
            ?>

            <div class="vkl-toolbar">
                <?php VKL_Admin_Tabs::modal_button('vkl-modal-plan', '➕ ' . __('Create a repayment plan / grant purchase credit', 'vereskala-login')); ?>
            </div>
            <?php
            VKL_Admin_Tabs::modal_start('vkl-modal-plan', __('Create a repayment plan / grant purchase credit', 'vereskala-login'));
            $this->render_installments('create');
            VKL_Admin_Tabs::modal_end();
            ?>

            <?php if ('due' === $vkl_view) : ?>
            <h2 class="title">📅 <?php esc_html_e('Payment fallen due', 'vereskala-login'); ?></h2>
            <p class="description" style="max-width:900px"><?php esc_html_e('Their payment window is open right now and this month\'s installment is still unpaid — but they are otherwise on schedule. Anyone already behind from earlier months is listed under "In arrears" instead, so nobody appears twice.', 'vereskala-login'); ?></p>

            <?php if ([] === $vkl_due) : ?>
                <p class="description"><?php esc_html_e('No payment has fallen due today.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1100px;margin-bottom:10px">
                    <thead><tr>
                        <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Plan', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Amount due', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Due day', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Remaining', 'vereskala-login'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($vkl_due as $vkl_row) : ?>
                            <?php $vkl_row_user = get_userdata((int) $vkl_row['user_id']); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($vkl_row_user ? $vkl_row_user->display_name : ('#' . (int) $vkl_row['user_id'])); ?></strong>
                                    <br><span class="description" dir="ltr" style="font-size:11px"><?php echo esc_html((string) get_user_meta((int) $vkl_row['user_id'], 'billing_phone', true)); ?></span>
                                </td>
                                <td><?php echo esc_html((string) $vkl_row['title']); ?></td>
                                <td><strong style="color:#157f3a"><?php echo function_exists('wc_price') ? wp_kses_post(wc_price((float) $vkl_row['due_amount'])) : esc_html(number_format_i18n((float) $vkl_row['due_amount'])); ?></strong></td>
                                <td><?php echo esc_html($this->installments->due_label((int) $vkl_row['due_day'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) $vkl_row['total'] - (float) $vkl_row['paid'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 16px;max-width:640px;margin:0 0 22px"
                      onsubmit="return confirm('<?php echo esc_js(__('Send the reminder SMS to everyone whose payment has fallen due?', 'vereskala-login')); ?>');">
                    <input type="hidden" name="action" value="vkl_due_sms">
                    <?php $this->security->admin_nonce_field(); ?>
                    <strong>📣 <?php esc_html_e('Group SMS to customers whose payment has fallen due', 'vereskala-login'); ?></strong>
                    <p style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:10px 0 6px">
                        <label><?php esc_html_e('Pattern code (empty = the reminder pattern from settings)', 'vereskala-login'); ?><br>
                            <input type="text" dir="ltr" name="vkl_due_pattern" style="width:190px" placeholder="wmw7nfeze5unxh9"></label>
                        <?php submit_button(__('Send to all', 'vereskala-login'), 'primary', 'submit', false); ?>
                    </p>
                    <p class="description"><?php esc_html_e('Variables: name, title, amount (this month), due (day and Jalali month).', 'vereskala-login'); ?></p>
                </form>
            <?php endif; ?>

            <h2 class="title">⚠️ <?php esc_html_e('In arrears', 'vereskala-login'); ?></h2>

            <?php if ([] === $vkl_arrears) : ?>
                <p class="description"><?php esc_html_e('Nobody is behind on an installment right now.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:1100px;margin-bottom:10px">
                    <thead><tr>
                        <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Plan', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Past due', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Due day', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Late fees', 'vereskala-login'); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($vkl_arrears as $vkl_late) : ?>
                            <?php $vkl_late_user = get_userdata((int) $vkl_late['user_id']); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($vkl_late_user ? $vkl_late_user->display_name : ('#' . (int) $vkl_late['user_id'])); ?></strong>
                                    <br><span class="description" dir="ltr" style="font-size:11px"><?php echo esc_html((string) get_user_meta((int) $vkl_late['user_id'], 'billing_phone', true)); ?></span>
                                </td>
                                <td><?php echo esc_html((string) $vkl_late['title']); ?></td>
                                <td><strong style="color:#d92b31"><?php echo function_exists('wc_price') ? wp_kses_post(wc_price((float) $vkl_late['overdue'])) : esc_html(number_format_i18n((float) $vkl_late['overdue'])); ?></strong></td>
                                <td><?php echo esc_html($this->installments->due_label((int) $vkl_late['due_day'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) ($vkl_late['penalty'] ?? 0))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 16px;max-width:640px;margin:0 0 22px"
                      onsubmit="return confirm('<?php echo esc_js(__('Send the reminder SMS to every customer in arrears?', 'vereskala-login')); ?>');">
                    <input type="hidden" name="action" value="vkl_arrears_sms">
                    <?php $this->security->admin_nonce_field(); ?>
                    <strong>📣 <?php esc_html_e('Group SMS to customers in arrears', 'vereskala-login'); ?></strong>
                    <p style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:10px 0 6px">
                        <label><?php esc_html_e('Pattern code (empty = the overdue pattern from settings)', 'vereskala-login'); ?><br>
                            <input type="text" dir="ltr" name="vkl_arrears_pattern" style="width:190px" placeholder="wmw7nfeze5unxh9"></label>
                        <?php submit_button(__('Send to all', 'vereskala-login'), 'primary', 'submit', false); ?>
                    </p>
                    <p class="description"><?php esc_html_e('One message per customer in the list above. Variables: name, title, amount (past due), due (day and Jalali month), penalty.', 'vereskala-login'); ?></p>
                </form>
            <?php endif; ?>
            <?php endif; // due view ?>

            <?php if ('accounting' === $vkl_view) : ?>
            <?php if (isset($_GET['vkl_reclassified'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    printf(
                        /* translators: %d: number of orders moved out of the sales statuses. */
                        esc_html__('%d repayment or top-up orders were moved out of the sales figures.', 'vereskala-login'),
                        (int) $_GET['vkl_reclassified'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    );
                ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['vkl_recovered'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    printf(
                        /* translators: %d: number of plans restored to credit. */
                        esc_html__('%d instalment credit plans were restored — their orders never completed payment.', 'vereskala-login'),
                        (int) $_GET['vkl_recovered'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    );
                ?></p></div>
            <?php endif; ?>

            <h2 class="title">🧾 <?php esc_html_e('Accounting', 'vereskala-login'); ?></h2>
            <p class="description" style="max-width:900px">
                <?php esc_html_e('From this version a paid installment or wallet top-up gets its own order status, so it is not counted as a sale a second time. Orders paid before that are still sitting in the sales statuses — this button moves up to 200 of them at a time.', 'vereskala-login'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:6px 0 18px"
                  onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Move past repayment and top-up orders to the repayment status?', 'vereskala-login'))); ?>);">
                <input type="hidden" name="action" value="vkl_carrier_reclassify">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Reclassify past repayment orders', 'vereskala-login'), 'secondary', 'submit', false); ?>
            </form>

            <p class="description" style="max-width:900px">
                <?php esc_html_e('A customer paying by instalment credit whose order then failed to complete (a crash, a timeout) can be left owing a plan with no matching finished order. This restores credit for any such order older than one hour — orders still genuinely in progress are left untouched.', 'vereskala-login'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:6px 0 18px"
                  onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Restore instalment credit for orders that never completed payment?', 'vereskala-login'))); ?>);">
                <input type="hidden" name="action" value="vkl_recover_stuck_plans">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Recover credit stuck on incomplete orders', 'vereskala-login'), 'secondary', 'submit', false); ?>
            </form>
            <?php endif; // accounting view ?>

            <?php if ('customers' === $vkl_view) : ?>
            <p class="description"><?php esc_html_e('Everyone with an installment file: available credit, open debt, remaining installments and accumulated late fees. Expand a row for per-plan reminder SMS.', 'vereskala-login'); ?></p>
            <h2 class="title">👥 <?php esc_html_e('All credit customers', 'vereskala-login'); ?></h2>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $vkl_q       = isset($_GET['vkl_cust_q']) ? sanitize_text_field(wp_unslash((string) $_GET['vkl_cust_q'])) : '';
            $vkl_only    = [];
            $vkl_no_hits = false;

            if ('' !== $vkl_q) {
                $vkl_found = get_users([
                    'search'         => '*' . $vkl_q . '*',
                    'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
                    'number'         => 50,
                    'fields'         => 'ID',
                ]);

                // Mobile and national ID live in user meta, so they need
                // their own pass — searching by phone is the common case.
                foreach (['billing_phone', 'vkl_national_id'] as $vkl_meta) {
                    $vkl_found = array_merge($vkl_found, get_users([
                        'meta_key'     => $vkl_meta,
                        'meta_value'   => $vkl_q,
                        'meta_compare' => 'LIKE',
                        'number'       => 50,
                        'fields'       => 'ID',
                    ]));
                }

                $vkl_only    = array_values(array_unique(array_map('intval', $vkl_found)));
                $vkl_no_hits = [] === $vkl_only;
            }
            ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0 10px">
                <input type="hidden" name="page" value="vkl-credit-customers">
                <input type="hidden" name="view" value="customers">
                <input type="search" name="vkl_cust_q" value="<?php echo esc_attr($vkl_q); ?>" style="min-width:280px"
                       placeholder="<?php esc_attr_e('Name, mobile, email or national ID…', 'vereskala-login'); ?>">
                <button class="button button-primary"><?php esc_html_e('Search', 'vereskala-login'); ?></button>
                <?php if ('' !== $vkl_q) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'vkl-credit-customers', 'view' => 'customers'], admin_url('admin.php'))); ?>"><?php esc_html_e('Clear', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <span class="description"><?php esc_html_e('Without a search the fifty customers with the largest open debt are listed.', 'vereskala-login'); ?></span>
            </form>

            <?php if ($vkl_no_hits) : ?>
                <p class="description">⛔ <?php esc_html_e('No customer matched that search.', 'vereskala-login'); ?></p>
            <?php endif; ?>

            <table class="widefat striped" style="max-width:1100px">
                <thead><tr>
                    <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                    <th style="width:120px"><?php esc_html_e('National ID', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Available credit', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Open debt', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Installments left', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Late fees', 'vereskala-login'); ?></th>
                    <th style="width:150px"><?php esc_html_e('Credit granted', 'vereskala-login'); ?></th>
                    <th style="width:150px"><?php esc_html_e('Behaviour', 'vereskala-login'); ?></th>
                    <th style="width:280px"><?php esc_html_e('Actions', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php $vkl_rows = $vkl_no_hits ? [] : $this->installments->credit_customers(50, $vkl_only); ?>
                <?php if ([] === $vkl_rows && !$vkl_no_hits) : ?>
                    <tr><td colspan="9"><?php esc_html_e('No installment customers yet.', 'vereskala-login'); ?></td></tr>
                <?php elseif ([] === $vkl_rows) : ?>
                    <tr><td colspan="9"><?php esc_html_e('That customer has no installment file yet.', 'vereskala-login'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($vkl_rows as $vkl_row) : ?>
                    <?php
                    $vkl_user = get_userdata((int) ($vkl_row['user_id'] ?? 0));
                    $vkl_debt = (float) ($vkl_row['debt'] ?? 0);
                    ?>
                    <tr>
                        <?php $vkl_rate = $this->installments->credit_rating($vkl_row); ?>
                        <td>
                            <strong><?php echo esc_html($vkl_user ? $vkl_user->display_name : ('#' . (int) $vkl_row['user_id'])); ?></strong>
                            <?php if ($vkl_user) : ?>
                                <div class="description" dir="ltr"><?php echo esc_html((string) get_user_meta((int) $vkl_row['user_id'], 'billing_phone', true)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td dir="ltr" style="font-size:12.5px">
                            <?php
                            $vkl_nid = (string) get_user_meta((int) $vkl_row['user_id'], 'vkl_national_id', true);
                            echo '' !== $vkl_nid ? esc_html($vkl_nid) : '—';

                            if ('' !== $vkl_nid && class_exists('VKL_Identity')) {
                                echo ' ' . wp_kses_post(VKL_Identity::badge((int) $vkl_row['user_id']));
                            }
                            ?>
                        </td>
                        <td><?php echo wp_kses_post(wc_price((float) ($vkl_row['credit_available'] ?? 0))); ?></td>
                        <td style="font-weight:700;color:<?php echo $vkl_debt > 0 ? '#9a6a10' : '#157f3a'; ?>">
                            <?php echo wp_kses_post(wc_price($vkl_debt)); ?>
                        </td>
                        <td><?php echo esc_html(number_format_i18n((int) ($vkl_row['installments_left'] ?? 0))); ?></td>
                        <td><?php echo (float) ($vkl_row['penalty'] ?? 0) > 0 ? wp_kses_post(wc_price((float) $vkl_row['penalty'])) : '—'; ?></td>
                        <td dir="ltr" style="font-size:12px">
                            <?php echo esc_html($this->jalali_date((string) ($vkl_row['first_plan_at'] ?? ''))); ?>
                            <?php if (!empty($vkl_row['last_plan_at']) && $vkl_row['last_plan_at'] !== $vkl_row['first_plan_at']) : ?>
                                <div style="color:#889;font-size:11px">
                                    <?php
                                    printf(
                                        /* translators: %s: Jalali date of the most recent plan. */
                                        esc_html__('latest: %s', 'vereskala-login'),
                                        esc_html($this->jalali_date((string) $vkl_row['last_plan_at']))
                                    );
                                    ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($vkl_rate['stars'] > 0) : ?>
                                <span title="<?php echo esc_attr($vkl_rate['note']); ?>" style="letter-spacing:1px;color:#e0a800;font-size:15px">
                                    <?php echo esc_html(str_repeat('★', $vkl_rate['stars']) . str_repeat('☆', 5 - $vkl_rate['stars'])); ?>
                                </span>
                            <?php endif; ?>
                            <div style="font-size:11.5px;color:#556"><?php echo esc_html($vkl_rate['label']); ?></div>
                        </td>
                        <td>
                            <?php // Settled plans are listed too: a plan entered by mistake must stay correctable after it is marked paid. ?>
                            <?php $vkl_open = $this->installments->all_plans((int) $vkl_row['user_id']); ?>
                            <?php $vkl_pay_map = $this->installments->offline_payments_map(wp_list_pluck($vkl_open, 'id')); ?>
                            <?php if ([] === $vkl_open) : ?>
                                <span class="description"><?php esc_html_e('No plans on file.', 'vereskala-login'); ?></span>
                            <?php else : ?>
                                <?php
                                // Grouped instead of one flat list: an active-debt row and a
                                // 40-row purchase history used to render identically, so a
                                // customer with years of credit-funded orders was unreadable —
                                // exactly what was reported as "too tangled to make sense of".
                                //
                                // Grouping goes by the ACTUAL remaining amount (total - paid),
                                // not the stored 'status' column: a hand-edited row (total
                                // changed, or the row's own origin order deleted/reclassified
                                // behind its back) can leave 'status' saying settled while real
                                // money is still owed. Trusting the flag alone hid exactly that
                                // case inside the collapsed Settled section, where nobody would
                                // think to look for open debt — the opposite of what settled is
                                // supposed to mean. The row's own badge already flags this
                                // specific mismatch for a human to sort out.
                                $vkl_active_plans  = [];
                                $vkl_active_credit = [];
                                $vkl_settled       = [];

                                foreach ($vkl_open as $vkl_plan) {
                                    $vkl_remaining = (float) $vkl_plan['total'] - (float) $vkl_plan['paid'];

                                    if ('credit' === (string) ($vkl_plan['type'] ?? 'plan')) {
                                        $vkl_active_credit[] = $vkl_plan;
                                    } elseif ($vkl_remaining > 0) {
                                        $vkl_active_plans[] = $vkl_plan;
                                    } else {
                                        $vkl_settled[] = $vkl_plan;
                                    }
                                }
                                ?>
                                <details>
                                    <summary style="cursor:pointer;color:#157f3a">
                                        <?php
                                        printf(
                                            'برنامه‌ها، پرداخت‌ها و پیامک یادآوری (%1$s فعال، %2$s اعتبار، %3$s تسویه‌شده)',
                                            esc_html(number_format_i18n(count($vkl_active_plans))),
                                            esc_html(number_format_i18n(count($vkl_active_credit))),
                                            esc_html(number_format_i18n(count($vkl_settled)))
                                        );
                                        ?>
                                    </summary>

                                    <?php if ([] !== $vkl_active_plans) : ?>
                                        <div style="margin:10px 0 4px;font-weight:700;font-size:12.5px;color:#124e27">📋 اقساط فعال</div>
                                        <?php foreach ($vkl_active_plans as $vkl_plan) : ?>
                                            <?php $this->render_plan_row($vkl_plan, $vkl_pay_map); ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ([] !== $vkl_active_credit) : ?>
                                        <div style="margin:14px 0 4px;font-weight:700;font-size:12.5px;color:#124e27">💳 اعتبار در دسترس</div>
                                        <?php foreach ($vkl_active_credit as $vkl_plan) : ?>
                                            <?php $this->render_plan_row($vkl_plan, $vkl_pay_map); ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ([] !== $vkl_settled) : ?>
                                        <details style="margin-top:14px">
                                            <summary style="cursor:pointer;font-size:12.5px">✅ تسویه‌شده (<?php echo esc_html(number_format_i18n(count($vkl_settled))); ?>) — برای نمایش کلیک کنید</summary>
                                            <?php foreach ($vkl_settled as $vkl_plan) : ?>
                                                <?php $this->render_plan_row($vkl_plan, $vkl_pay_map); ?>
                                            <?php endforeach; ?>
                                        </details>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>

                            <?php // Raising the ceiling used to sit in the lookup box above the table, which sent its search to the wrong screen; the action belongs on the customer's own row. ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px">
                                <input type="hidden" name="action" value="vkl_raise_credit">
                                <input type="hidden" name="user_id" value="<?php echo (int) $vkl_row['user_id']; ?>">
                                <?php $this->security->admin_nonce_field(); ?>
                                <input type="number" name="amount" min="0" step="1000" style="width:120px"
                                       placeholder="<?php esc_attr_e('Amount', 'vereskala-login'); ?>" required>
                                <?php submit_button(__('Increase credit', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px">
                <?php esc_html_e('The reminder uses the "reminder" pattern configured on the Wallet page (variables: name, title, amount, due).', 'vereskala-login'); ?>
            </p>
            <script>
            (function () {
                [['.vkl-plan-edit', 'vkl-plan-edit-'], ['.vkl-plan-pay', 'vkl-plan-pay-']].forEach(function (pair) {
                    document.querySelectorAll(pair[0]).forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var form = document.getElementById(pair[1] + btn.getAttribute('data-plan'));
                            if (!form) { return; }
                            form.style.display = (form.style.display === 'none' || !form.style.display) ? 'block' : 'none';
                        });
                    });
                });
            })();
            </script>
            <?php endif; // customers view ?>

            <?php
            if ('requests' === $vkl_view) {
                $this->render_installments('requests');

                if (0 === $vkl_requests) {
                    echo '<p class="description">' . esc_html__('No credit requests are waiting.', 'vereskala-login') . '</p>';
                }
            } elseif ('settings' === $vkl_view) {
                $this->render_installments('settings');
            }
            ?>
        </div>
        <?php
    }

    public function handle_adjust(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $user_id   = (int) ($_POST['vkl_user_id'] ?? 0);
        $amount    = (float) ($_POST['vkl_amount'] ?? 0);
        $direction = sanitize_text_field(wp_unslash($_POST['vkl_direction'] ?? 'credit'));
        $note      = sanitize_text_field(wp_unslash($_POST['vkl_note'] ?? ''));
        // phpcs:enable

        $ok = false;

        if ($user_id > 0 && $amount > 0 && get_userdata($user_id)) {
            $ok = 'debit' === $direction
                ? $this->wallet->debit($user_id, $amount, 'admin_debit', '', $note)
                : $this->wallet->credit($user_id, $amount, 'admin_credit', '', $note);
        }

        // Submitted from a customer's profile: go back to that profile.
        $back = admin_url('admin.php?page=vkl-wallet');

        if ($user_id > 0 && false !== strpos((string) wp_get_referer(), 'page=vkl-customers')) {
            $back = admin_url('admin.php?page=vkl-customers&customer=' . $user_id . '&view=wallet');
        }

        wp_safe_redirect(add_query_arg($ok ? 'done' : 'failed', '1', $back));
        exit;
    }

    /**
     * Format a stored UTC datetime as a Jalali date for the admin.
     */
    private function jalali_date(string $mysql): string
    {
        if ('' === trim($mysql)) {
            return '—';
        }

        $ts = strtotime($mysql);

        if (!$ts || !class_exists('VKL_Wc_Account')) {
            return '' !== $mysql ? mysql2date(get_option('date_format'), $mysql) : '—';
        }

        [$jy, $jm, $jd] = VKL_Wc_Account::g2j(
            (int) gmdate('Y', $ts),
            (int) gmdate('n', $ts),
            (int) gmdate('j', $ts)
        );

        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'امرداد', 'شهریور',
                   'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        return sprintf('%d %s %d', (int) $jd, $months[max(0, (int) $jm - 1)] ?? '', (int) $jy);
    }

    /**
     * Raise a customer's purchase-credit ceiling.
     *
     * The ceiling is the plan total, so this adds to the newest open plan
     * rather than creating a second one — one customer, one running limit.
     */
    public function handle_raise_credit(): void
    {
        $this->security->verify_admin_request();

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $amount  = isset($_POST['amount']) ? (float) wp_unslash($_POST['amount']) : 0.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $done = false;

        if ($user_id > 0 && $amount > 0) {
            $plans = $this->installments->open_plans($user_id);

            if ([] !== $plans) {
                $plan  = $plans[0];
                $count = (int) ($plan['installment_count'] ?? 0);
                $amt   = (float) ($plan['installment_amount'] ?? 0);

                // Keep the instalment size and stretch the number of
                // instalments, so the customer's monthly burden is unchanged.
                $new_total = (float) $plan['total'] + $amount;
                $new_count = $amt > 0 ? (int) ceil($new_total / $amt) : $count;

                $done = $this->installments->update_plan(
                    (int) $plan['id'],
                    (string) ($plan['title'] ?? ''),
                    $new_total,
                    max(1, $new_count),
                    (int) $plan['due_day']
                );
            }
        }

        $this->back_to_loans(['vkl_raised' => $done ? '1' : '0']);
    }

    public function render(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Wallet', 'vereskala-login'); ?></h1>

            <?php if (isset($_GET['done'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success"><p><?php esc_html_e('Wallet updated.', 'vereskala-login'); ?></p></div>
            <?php elseif (isset($_GET['failed'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-error"><p><?php esc_html_e('Wallet update failed (check user id and balance).', 'vereskala-login'); ?></p></div>
            <?php endif; ?>

            <?php
            $vkl_views = [
                'transactions' => __('Transactions', 'vereskala-login'),
                'festival'     => __('Festival credit', 'vereskala-login'),
                'settings'     => __('Settings', 'vereskala-login'),
            ];
            $vkl_view = VKL_Admin_Tabs::current_view($vkl_views);
            VKL_Admin_Tabs::render_views('vkl-wallet', $vkl_views, $vkl_view);
            ?>

            <?php if ('settings' === $vkl_view) : ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Wallet on the checkout page', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('wallet_checkout_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('wallet_checkout_enabled')); ?>" value="1" <?php checked($this->config->get_bool('wallet_checkout_enabled', true)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Shows the wallet balance box above the payment methods for logged-in customers. Requires the classic checkout shortcode — see the Status page.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Wallet top-up via bank gateway', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('wallet_topup_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('wallet_topup_enabled')); ?>" value="1" <?php checked($this->config->get_bool('wallet_topup_enabled', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Customers charge their wallet from the wallet tab; payment goes through the normal checkout and any active payment gateway.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Minimum top-up amount', 'vereskala-login'); ?></th>
                        <td><input type="number" min="1" step="1" name="<?php echo esc_attr($this->config->option_name('wallet_topup_min')); ?>" value="<?php echo esc_attr((string) $this->config->get_int('wallet_topup_min', 1)); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum top-up amount', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="0" step="1" name="<?php echo esc_attr($this->config->option_name('wallet_topup_max')); ?>" value="<?php echo esc_attr((string) $this->config->get_int('wallet_topup_max', 0)); ?>">
                            <p class="description"><?php esc_html_e('0 = no maximum.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Require login for checkout', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('checkout_require_login')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('checkout_require_login')); ?>" value="1" <?php checked($this->config->get_bool('checkout_require_login', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Guests who open the checkout are sent to the login page and returned to checkout after logging in. Payment links for existing orders keep working.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Address and shipping step', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('shipping_step_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('shipping_step_enabled')); ?>" value="1" <?php checked($this->config->get_bool('shipping_step_enabled', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            <p>
                                <?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup.
                                echo wp_dropdown_pages([
                                    'name'              => $this->config->option_name('shipping_step_page'),
                                    'selected'          => $this->config->get_int('shipping_step_page', 0),
                                    'show_option_none'  => __('— Choose a page —', 'vereskala-login'),
                                    'option_none_value' => '0',
                                    'echo'              => 0,
                                ]);
                                ?>
                            </p>
                            <p class="description"><?php esc_html_e('Between the cart and the checkout, logged-in customers pick a saved address and see the real shipping options before paying. Create an empty page (e.g. "Shipping") and choose it here; the step is shown on it (or wherever the [vereskonto_shipping] shortcode is). Carts that need no shipping go straight to checkout.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php endif; // settings view ?>

            <?php if ('transactions' === $vkl_view) : ?>
            <div class="vkl-toolbar">
                <?php VKL_Admin_Tabs::modal_button('vkl-modal-adjust', __('Credit / debit a customer', 'vereskala-login')); ?>
            </div>

            <?php VKL_Admin_Tabs::modal_start('vkl-modal-adjust', __('Credit / debit a customer', 'vereskala-login')); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_wallet_adjust">
                <?php $this->security->admin_nonce_field(); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                        <td>
                            <?php if (class_exists('WooCommerce')) : ?>
                                <select class="wc-customer-search" name="vkl_user_id" style="min-width:300px"
                                        data-placeholder="<?php esc_attr_e('Search customer by name, email or ID…', 'vereskala-login'); ?>"
                                        data-allow_clear="true" required></select>
                                <p class="description"><?php esc_html_e('Type at least three characters to search.', 'vereskala-login'); ?></p>
                            <?php else : ?>
                                <input type="number" min="1" name="vkl_user_id" required>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
                        <td><input type="number" min="0" step="any" name="vkl_amount" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Direction', 'vereskala-login'); ?></th>
                        <td>
                            <select name="vkl_direction">
                                <option value="credit"><?php esc_html_e('Credit (add funds)', 'vereskala-login'); ?></option>
                                <option value="debit"><?php esc_html_e('Debit (remove funds)', 'vereskala-login'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Note', 'vereskala-login'); ?></th>
                        <td><input type="text" class="regular-text" name="vkl_note"></td>
                    </tr>
                </table>

                <?php submit_button(__('Apply', 'vereskala-login')); ?>
            </form>
            <?php VKL_Admin_Tabs::modal_end(); ?>

            <h2 class="title vkl-sec-wallet"><?php esc_html_e('Recent transactions', 'vereskala-login'); ?></h2>

            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('User', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Reason', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Reference', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Date (UTC)', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->repository->latest() as $row) : ?>
                    <tr>
                        <td>
                            <?php
                            $user = get_userdata((int) $row['user_id']);
                            echo esc_html($user ? $user->display_name : ('#' . (int) $row['user_id']));
                            ?>
                        </td>
                        <td><?php echo wp_kses_post(function_exists('wc_price') ? wc_price((float) $row['amount']) : number_format_i18n((float) $row['amount'], 2)); ?></td>
                        <td><?php echo esc_html((string) $row['reason']); ?></td>
                        <td><?php echo esc_html((string) $row['reference']); ?></td>
                        <td><?php echo esc_html((string) $row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; // transactions view ?>

            <?php
            if ('festival' === $vkl_view) {
                $this->render_promo_notices();
                $this->render_festival();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Create an installment plan (admin-post).
     */
    public function handle_installment_create(): void
    {
        $this->security->verify_admin_request();

        $user_id = isset($_POST['vkl_user_id']) ? absint($_POST['vkl_user_id']) : 0;
        $title   = isset($_POST['vkl_title']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_title'])) : '';
        $total   = isset($_POST['vkl_total']) ? (float) $_POST['vkl_total'] : 0.0;
        $count   = isset($_POST['vkl_count']) ? absint($_POST['vkl_count']) : 0;
        $due     = isset($_POST['vkl_due_day']) ? absint($_POST['vkl_due_day']) : 1;

        $kind = isset($_POST['vkl_kind']) && 'credit' === $_POST['vkl_kind'] ? 'credit' : 'plan'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ($user_id > 0 && '' !== $title && $total > 0 && $count >= 1 && false !== get_userdata($user_id)) {
            $this->installments->create($user_id, $title, $total, $count, $due, $kind);

            // Credit activation SMS — same pattern as request approval, so
            // the customer is informed no matter which path granted it.
            if ('credit' === $kind) {
                $pattern = $this->config->get_string('installment_approved_pattern');
                $mobile  = vkl('validator')->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

                if ('' !== $pattern && vkl('validator')->is_mobile($mobile)) {
                    vkl('sms.sender')->send($mobile, 'credit.approved', $pattern, [
                        'name'   => vkl('sms.sender')->resolve_name($user_id),
                        'amount' => (string) (int) $total,
                    ]);
                }
            }
        }

        $this->back_to_loans();
    }

    /**
     * One part of the installment machinery on the loans screen:
     * 'requests' (pending credit requests), 'settings' (installment
     * settings form) or 'create' (the create-plan form, shown in a modal).
     */
    private function render_installments(string $part): void
    {
        if ('requests' === $part) {
            vkl('wallet.credit_requests')->render_admin();

            return;
        }

        if ('create' === $part) {
            $this->render_create_plan_form();

            return;
        }
        ?>
        <h2 class="title vkl-sec-credit"><?php esc_html_e('Installment plans', 'vereskala-login'); ?></h2>
        <p class="description"><?php esc_html_e('Create a plan for a customer; they pay each installment from their Wallet tab through any active gateway. Amounts are tracked here and every payment/refund adjusts the plan exactly once.', 'vereskala-login'); ?></p>

        <form method="post" action="options.php" style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:12px 16px;max-width:640px;margin-bottom:14px">
            <?php settings_fields('vkl_installment_settings'); ?>
            <p style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
                <label style="display:block;max-width:420px">
                    <input type="hidden" name="<?php echo esc_attr($this->config->option_name('carrier_order_status')); ?>" value="0">
                    <input type="checkbox" value="1"
                           name="<?php echo esc_attr($this->config->option_name('carrier_order_status')); ?>"
                           <?php checked($this->config->get_bool('carrier_order_status', true)); ?>>
                    <?php esc_html_e('Keep repayments out of the sales figures', 'vereskala-login'); ?>
                    <span class="description" style="display:block"><?php esc_html_e('A paid installment or wallet top-up moves to its own order status instead of staying in "processing"/"completed", so the shop books the purchase once and not again with every installment.', 'vereskala-login'); ?></span>
                </label>
                <label style="display:block;max-width:420px">
                    <input type="hidden" name="<?php echo esc_attr($this->config->option_name('carrier_order_silent')); ?>" value="0">
                    <input type="checkbox" value="1"
                           name="<?php echo esc_attr($this->config->option_name('carrier_order_silent')); ?>"
                           <?php checked($this->config->get_bool('carrier_order_silent', true)); ?>>
                    <?php esc_html_e('No purchase emails for repayments', 'vereskala-login'); ?>
                    <span class="description" style="display:block"><?php esc_html_e('WooCommerce would email the customer and the shop that an order is complete — for goods nobody ordered. The repayment confirmation from this plugin stays.', 'vereskala-login'); ?></span>
                </label>
                <label><?php esc_html_e('Payment window (days, ending on the due day)', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="28" step="1" style="width:110px"
                           name="<?php echo esc_attr($this->config->option_name('installment_due_window')); ?>"
                           value="<?php echo (int) $this->config->get_int('installment_due_window', 5); ?>">
                    <span class="description" style="display:block;max-width:320px"><?php esc_html_e('With a due day of 5 and a window of 5, the customer may pay from the 1st to the 5th. The late fee starts the day after the window closes.', 'vereskala-login'); ?></span></label>
                <label><?php esc_html_e('Minimum days between purchase and first installment', 'vereskala-login'); ?><br>
                    <input type="number" min="0" max="31" step="1" style="width:110px"
                           name="<?php echo esc_attr($this->config->option_name('installment_first_due_gap')); ?>"
                           value="<?php echo (int) $this->config->get_int('installment_first_due_gap', 7); ?>">
                    <span class="description" style="display:block;max-width:320px"><?php esc_html_e('Counted from the purchase to the day the next window opens. If that day comes sooner than this, the first installment moves one month further out. 0 switches the rule off.', 'vereskala-login'); ?></span></label>
                <label><?php esc_html_e('Late fee (% per month on the overdue amount)', 'vereskala-login'); ?><br>
                    <input type="number" min="0" max="100" step="0.5" style="width:110px"
                           name="<?php echo esc_attr($this->config->option_name('installment_penalty_percent')); ?>"
                           value="<?php echo (int) $this->config->get_int('installment_penalty_percent', 0); ?>"></label>
                <label><?php esc_html_e('Pattern: reminder (name, title, amount, due)', 'vereskala-login'); ?><br>
                    <input type="text" dir="ltr" style="width:170px" name="<?php echo esc_attr($this->config->option_name('installment_notice_pattern')); ?>"
                           value="<?php echo esc_attr($this->config->get_string('installment_notice_pattern')); ?>"></label>
                <label><?php esc_html_e('Pattern: overdue (name, amount, penalty)', 'vereskala-login'); ?><br>
                    <input type="text" dir="ltr" style="width:170px" name="<?php echo esc_attr($this->config->option_name('installment_due_pattern')); ?>"
                           value="<?php echo esc_attr($this->config->get_string('installment_due_pattern')); ?>"></label>
                <label><?php esc_html_e('Reminder day (Jalali)', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="28" step="1" style="width:90px"
                           name="<?php echo esc_attr($this->config->option_name('installment_notice_day')); ?>"
                           value="<?php echo (int) $this->config->get_int('installment_notice_day', 3); ?>"></label>
                <label><?php esc_html_e('Follow-up day (Jalali)', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="28" step="1" style="width:90px"
                           name="<?php echo esc_attr($this->config->option_name('installment_chase_day')); ?>"
                           value="<?php echo (int) $this->config->get_int('installment_chase_day', 5); ?>"></label>
                <?php submit_button(__('Save', 'vereskala-login'), 'secondary', 'submit', false); ?>
            </p>
            <p class="description"><?php esc_html_e('Two automatic messages go out each Jalali month: the reminder on the day set above, and a follow-up on the later day for anyone still behind. Both use the patterns above, and each customer receives each one only once per month.', 'vereskala-login'); ?></p>
            <p class="description"><?php esc_html_e('A daily job also checks every repayment plan after its monthly due day: the late fee (0 = off) is added once per month to the overdue amount and the overdue SMS goes out. The reminder pattern powers the manual "Send reminder SMS" button.', 'vereskala-login'); ?></p>
        </form>
        <?php
    }

    /**
     * Result notices of a festival credit batch (shown on the festival
     * view, where the batch form is — they used to print on the loans
     * screen, which the batch never redirects to).
     */
    private function render_promo_notices(): void
    {
        ?>
        <?php if (isset($_GET['vkl_promo'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php
                printf(
                    /* translators: %s: number of customers. */
                    esc_html__('Festival credit granted to %s customers.', 'vereskala-login'),
                    esc_html(number_format_i18n(absint($_GET['vkl_promo']))) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                );
                ?>
            </p></div>
        <?php endif; ?>

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $vkl_sms_result = isset($_GET['vkl_sms']) ? sanitize_text_field(wp_unslash($_GET['vkl_sms'])) : '';
        ?>
        <?php if ('' !== $vkl_sms_result) : ?>
            <?php if ('nopattern' === $vkl_sms_result) : ?>
                <div class="notice notice-warning is-dismissible"><p>
                    <?php esc_html_e('No announcement was sent: the pattern field was left empty.', 'vereskala-login'); ?>
                </p></div>
            <?php elseif ('norecipients' === $vkl_sms_result) : ?>
                <div class="notice notice-warning is-dismissible"><p>
                    <?php esc_html_e('No announcement was sent: nobody received credit in this batch.', 'vereskala-login'); ?>
                </p></div>
            <?php elseif (str_starts_with($vkl_sms_result, 'sent:')) : ?>
                <?php
                $vkl_p = explode(':', $vkl_sms_result);
                $vkl_ok = (int) ($vkl_p[1] ?? 0);
                $vkl_no = (int) ($vkl_p[2] ?? 0);
                ?>
                <div class="notice <?php echo $vkl_ok > 0 ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p>
                    <?php
                    printf(
                        /* translators: 1: sent count, 2: failed count. */
                        esc_html__('Announcement queue: %1$s sent, %2$s failed.', 'vereskala-login'),
                        esc_html(number_format_i18n($vkl_ok)),
                        esc_html(number_format_i18n($vkl_no))
                    );
                    ?>
                    <?php if (0 === $vkl_ok) : ?>
                        <br><?php esc_html_e('Nothing left the queue. Check the SMS report for the gateway’s reason, and make sure those customers have a mobile number on file.', 'vereskala-login'); ?>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * The create-plan / grant-credit form (inside a modal).
     */
    private function render_create_plan_form(): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="vkl_installment_create">
            <?php $this->security->admin_nonce_field(); ?>
            <p>
                <label style="display:block;font-weight:600"><?php esc_html_e('Customer', 'vereskala-login'); ?></label>
                <?php if (class_exists('WooCommerce')) : ?>
                    <select class="wc-customer-search" name="vkl_user_id" style="min-width:300px"
                            data-placeholder="<?php esc_attr_e('Search customer by name, email or ID…', 'vereskala-login'); ?>" data-allow_clear="true"></select>
                <?php else : ?>
                    <input type="number" min="1" name="vkl_user_id" required>
                <?php endif; ?>
            </p>
            <p>
                <label style="display:block;font-weight:600"><?php esc_html_e('Plan title', 'vereskala-login'); ?></label>
                <input type="text" class="regular-text" name="vkl_title" required
                       placeholder="<?php esc_attr_e('e.g. order #1234 in 4 installments', 'vereskala-login'); ?>">
            </p>
            <p>
                <label style="display:block;font-weight:600"><?php esc_html_e('Plan type', 'vereskala-login'); ?></label>
                <select name="vkl_kind">
                    <option value="plan"><?php esc_html_e('Direct debt (repayment starts now)', 'vereskala-login'); ?></option>
                    <option value="credit"><?php esc_html_e('Purchase credit (BNPL — repayment starts after the credit is spent at checkout)', 'vereskala-login'); ?></option>
                </select>
                <span class="description" style="display:block"><?php esc_html_e('For purchase credit, "Total amount" is the credit limit; the "Installment purchase" payment method appears at checkout only for this customer while their remaining credit covers the order.', 'vereskala-login'); ?></span>
            </p>
            <p style="display:flex;gap:14px;flex-wrap:wrap">
                <label><?php esc_html_e('Total amount', 'vereskala-login'); ?><br>
                    <input type="number" min="1" step="1" name="vkl_total" required style="width:140px"></label>
                <label><?php esc_html_e('Number of installments', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="36" step="1" name="vkl_count" value="4" style="width:90px"></label>
                <label><?php esc_html_e('Last day of the payment window (1–28)', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="28" step="1" name="vkl_due_day" value="5" style="width:90px"></label>
            </p>
            <?php submit_button(__('Create plan', 'vereskala-login'), 'primary', 'submit', false); ?>
        </form>

<?php
    }

    /**
     * Edit a plan (admin-post).
     */
    public function handle_installment_update(): void
    {
        $this->security->verify_admin_request();

        // An empty percentage field means "follow the shop rate" (-1),
        // which is not the same as a deliberate 0.
        $percent_raw = isset($_POST['vkl_penalty_percent'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['vkl_penalty_percent'])))
            : '';

        $this->installments->update_plan(
            isset($_POST['vkl_plan_id']) ? absint($_POST['vkl_plan_id']) : 0,
            isset($_POST['vkl_title']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_title'])) : '',
            isset($_POST['vkl_total']) ? (float) $_POST['vkl_total'] : 0.0,
            isset($_POST['vkl_count']) ? absint($_POST['vkl_count']) : 1,
            isset($_POST['vkl_due_day']) ? absint($_POST['vkl_due_day']) : 1,
            '' === $percent_raw ? -1.0 : (float) $percent_raw,
            isset($_POST['vkl_penalty']) ? (float) $_POST['vkl_penalty'] : null
        );

        $this->back_to_loans();
    }

    /**
     * Delete a plan (admin-post).
     */
    public function handle_installment_delete(): void
    {
        $this->security->verify_admin_request();

        $this->installments->delete_plan(isset($_POST['vkl_plan_id']) ? absint($_POST['vkl_plan_id']) : 0);

        $this->back_to_loans();
    }

    /**
     * Book a repayment that arrived outside the shop (admin-post).
     */
    public function handle_installment_offline(): void
    {
        $this->security->verify_admin_request();

        $result = $this->installments->record_offline_payment(
            isset($_POST['vkl_plan_id']) ? absint($_POST['vkl_plan_id']) : 0,
            isset($_POST['vkl_amount']) ? (float) $_POST['vkl_amount'] : 0.0,
            isset($_POST['vkl_method']) ? sanitize_key(wp_unslash((string) $_POST['vkl_method'])) : 'card',
            isset($_POST['vkl_reference']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_reference'])) : '',
            isset($_POST['vkl_note']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_note'])) : '',
            get_current_user_id()
        );

        $this->back_to_loans(['vkl_offline' => $result]);
    }

    /**
     * Reverse a recorded offline repayment (admin-post).
     */
    public function handle_installment_offline_delete(): void
    {
        $this->security->verify_admin_request();

        $ok = $this->installments->delete_offline_payment(
            isset($_POST['vkl_payment_id']) ? absint($_POST['vkl_payment_id']) : 0
        );

        $this->back_to_loans(['vkl_offline' => $ok ? 'removed' : 'noplan']);
    }

    /**
     * Send a manual installment reminder SMS (admin-post).
     */
    public function handle_installment_remind(): void
    {
        $this->security->verify_admin_request();

        $sent = $this->installments->remind(isset($_POST['vkl_plan_id']) ? absint($_POST['vkl_plan_id']) : 0);

        $this->back_to_loans(['vkl_reminded' => $sent ? '1' : '0']);
    }

    /**
     * Move already-paid repayment and top-up orders out of the sales
     * statuses (admin-post) — the past bookings this version's rule only
     * fixes going forward.
     */
    public function handle_carrier_reclassify(): void
    {
        $this->security->verify_admin_request();

        $carrier = vkl('wallet.carrier_order');
        $moved   = $carrier instanceof VKL_Wallet_Carrier_Order ? $carrier->convert_past(200) : 0;

        $this->back_to_loans(['vkl_reclassified' => (string) $moved]);
    }

    /**
     * Restore instalment credit that was consumed for an order which then
     * never actually completed payment (a crash between consume_credit()
     * and payment_complete — see VKL_Installment_Gateway::process_payment())
     * and so never fired the normal cancelled/refunded restore path.
     */
    public function handle_recover_stuck_plans(): void
    {
        $this->security->verify_admin_request();

        $recovered = $this->installments->recover_stuck_plans(60);

        $this->back_to_loans(['vkl_recovered' => (string) $recovered]);
    }

    /**
     * Return to the screen the action was started from.
     *
     * Plans live on the Loans screen since 1.86.0, but these handlers
     * still sent the admin back to the Wallet screen — every edit ended
     * on the wrong page, with the result invisible.
     *
     * @param array<string, string> $args Query arguments for the notice.
     */
    private function back_to_loans(array $args = []): void
    {
        $base = wp_get_referer();

        if (!is_string($base) || '' === $base) {
            $base = admin_url('admin.php?page=vkl-credit-customers');
        }

        $base = remove_query_arg(['vkl_offline', 'vkl_reminded', 'vkl_raised', 'vkl_reclassified'], $base);

        wp_safe_redirect([] === $args ? $base : add_query_arg($args, $base));
        exit;
    }

    /**
     * Batch festival grant (admin-post).
     */
    public function handle_promo_grant(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $title    = isset($_POST['vkl_promo_title']) ? sanitize_text_field(wp_unslash($_POST['vkl_promo_title'])) : '';
        $amount   = isset($_POST['vkl_promo_amount']) ? (float) $_POST['vkl_promo_amount'] : 0.0;
        $days     = isset($_POST['vkl_promo_days']) ? absint($_POST['vkl_promo_days']) : 7;
        $audience = isset($_POST['vkl_promo_audience']) && 'all' === $_POST['vkl_promo_audience'] ? 'all' : 'buyers';
        // phpcs:enable

        $granted = 0;

        if ('' !== $title && $amount > 0) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300); // phpcs:ignore
            }

            if ('all' === $audience) {
                $user_ids = get_users(['role__in' => ['customer', 'subscriber'], 'fields' => 'ID', 'number' => 20000]);
            } else {
                global $wpdb;

                $user_ids = $wpdb->get_col( // phpcs:ignore
                    "SELECT DISTINCT customer_id FROM {$wpdb->prefix}wc_order_stats
                     WHERE status IN ('wc-completed', 'wc-processing') AND customer_id > 0"
                );

                // customer_id in wc_order_stats is the analytics customer id;
                // map to WP user ids via the customer lookup table.
                $user_ids = $wpdb->get_col( // phpcs:ignore
                    "SELECT DISTINCT cl.user_id FROM {$wpdb->prefix}wc_customer_lookup cl
                     INNER JOIN {$wpdb->prefix}wc_order_stats os ON os.customer_id = cl.customer_id
                     WHERE os.status IN ('wc-completed', 'wc-processing') AND cl.user_id > 0"
                );
            }

            $min_order = isset($_POST['vkl_promo_min']) ? max(0.0, (float) $_POST['vkl_promo_min']) : 0.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

            $granted = vkl('wallet.promos')->grant_many(array_map('intval', (array) $user_ids), $amount, $days, $title, $min_order);

            // Optional announcement: a campaign targeted at EXACTLY this
            // batch's recipients, with per-recipient %name% auto-filled.
            $announce = isset($_POST['vkl_promo_pattern']) ? sanitize_text_field(wp_unslash($_POST['vkl_promo_pattern'])) : '';

            if ('' !== $announce) {
                update_option($this->config->option_name('promo_announce_pattern'), $announce);
            }

            // Why an announcement did not go out is worth saying out loud:
            // silence here looked like a broken feature when it was really
            // an empty pattern field or a batch with no phone numbers.
            $vkl_sms_note = '';

            if ('' === $announce) {
                $vkl_sms_note = 'nopattern';
            } elseif ($granted < 1) {
                $vkl_sms_note = 'norecipients';
            }

            if ('' !== $announce && $granted > 0) {
                vkl('sms.marketing')->create(
                    sprintf('🎁 %s', $title),
                    $announce,
                    [
                        'name'   => 'auto',
                        'amount' => (string) (int) $amount,
                        'min'    => (string) (int) $min_order,
                        'days'   => (string) $days,
                    ],
                    'promo:' . vkl('wallet.promos')->last_batch
                );

                // Send the first batch immediately instead of waiting for
                // the next cron tick. WP-Cron is disabled on this site, so
                // a queued campaign could otherwise sit untouched — which
                // is exactly why the announcement never went out.
                vkl('sms.marketing')->process_now();

                // Report what the queue actually managed, so a gateway
                // rejection is visible instead of silent.
                $vkl_stats    = vkl('sms.marketing')->batch_stats();
                $vkl_sms_note = 'sent:' . (int) ($vkl_stats['sent'] ?? 0) . ':' . (int) ($vkl_stats['failed'] ?? 0);
            }
        }

        wp_safe_redirect(
            add_query_arg(
                array_filter([
                    'page'      => 'vkl-wallet',
                    'vkl_promo' => (string) $granted,
                    'vkl_sms'   => $vkl_sms_note ?? '',
                ]),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Edit a festival batch (admin-post).
     */
    public function handle_promo_update(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        vkl('wallet.promos')->update_batch(
            isset($_POST['vkl_batch']) ? sanitize_text_field(wp_unslash($_POST['vkl_batch'])) : '',
            isset($_POST['vkl_promo_title']) ? sanitize_text_field(wp_unslash($_POST['vkl_promo_title'])) : '',
            isset($_POST['vkl_promo_expires']) ? sanitize_text_field(wp_unslash($_POST['vkl_promo_expires'])) : '',
            isset($_POST['vkl_promo_min']) ? (float) $_POST['vkl_promo_min'] : 0.0
        );
        // phpcs:enable

        wp_safe_redirect(admin_url('admin.php?page=vkl-wallet'));
        exit;
    }

    /**
     * Delete a festival batch (admin-post).
     */
    public function handle_promo_delete(): void
    {
        $this->security->verify_admin_request();

        vkl('wallet.promos')->delete_batch(isset($_POST['vkl_batch']) ? sanitize_text_field(wp_unslash($_POST['vkl_batch'])) : '');

        wp_safe_redirect(admin_url('admin.php?page=vkl-wallet'));
        exit;
    }
    /**
     * Festival credit: the automatic post-purchase grant and the batch
     * deposit. Wallet business, so it renders on the Wallet screen even
     * though it used to sit inside the instalment markup.
     */
    private function render_festival(): void
    {
        // Fetched here, not inherited from the caller: this block was split
        // out of render_installments() in 1.87.0 and the variable stayed
        // behind, so every Wallet-screen load warned about an undefined
        // variable and printed empty figures.
        $vkl_promo_stats = vkl('wallet.promos')->stats();
        ?>
        <h2 class="title vkl-sec-wallet">🤖 <?php esc_html_e('Automatic festival credit after purchase', 'vereskala-login'); ?></h2>

        <?php if (isset($_GET['vkl_auto_cleared'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Automatic festival credit turned off and its settings cleared.', 'vereskala-login'); ?></p></div>
        <?php endif; ?>

        <?php
        $vkl_auto_on = $this->config->get_bool('promo_auto_enabled', false);
        $vkl_auto_am = (int) $this->config->get_int('promo_auto_amount', 0);
        ?>
        <?php if ($vkl_auto_on && $vkl_auto_am > 0) : ?>
            <?php
            $vkl_auto_delay = (int) $this->config->get_int('promo_auto_delay_hours', 0);
            $vkl_auto_rows  = [
                __('Credit amount', 'vereskala-login')          => number_format_i18n($vkl_auto_am),
                __('Qualifying order total', 'vereskala-login') => number_format_i18n((int) $this->config->get_int('promo_auto_min_order', 0)),
                __('Deposit after (hours)', 'vereskala-login')  => 0 === $vkl_auto_delay ? __('Immediately', 'vereskala-login') : number_format_i18n($vkl_auto_delay),
                __('Valid for (days)', 'vereskala-login')       => number_format_i18n((int) $this->config->get_int('promo_auto_days', 7)),
                __('Minimum order to spend it', 'vereskala-login') => number_format_i18n((int) $this->config->get_int('promo_auto_use_min', 0)),
            ];
            ?>
            <div style="background:#f0fcf4;border:1px solid #bfe9cb;border-radius:12px;padding:14px 18px;max-width:760px;margin-bottom:14px">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <strong style="color:#157f3a">✅ <?php esc_html_e('Active plan', 'vereskala-login'); ?></strong>
                    <?php $vkl_auto_title = $this->config->get_string('promo_auto_title'); ?>
                    <span class="description"><?php echo esc_html('' !== $vkl_auto_title ? $vkl_auto_title : __('Thank-you festival credit', 'vereskala-login')); ?></span>
                </div>
                <table class="widefat" style="background:transparent;border:0;margin-top:8px;max-width:520px">
                    <tbody>
                        <?php foreach ($vkl_auto_rows as $vkl_k => $vkl_v) : ?>
                            <tr style="background:transparent">
                                <td style="border:0;padding:2px 0;color:#556"><?php echo esc_html($vkl_k); ?></td>
                                <td style="border:0;padding:2px 0;font-weight:700" dir="ltr"><?php echo esc_html((string) $vkl_v); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <a class="button" href="#vkl-auto-form"><?php esc_html_e('Edit', 'vereskala-login'); ?></a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                          onsubmit="return confirm('<?php echo esc_js(__('Turn off automatic festival credit and clear its settings?', 'vereskala-login')); ?>');">
                        <input type="hidden" name="action" value="vkl_promo_auto_clear">
                        <?php $this->security->admin_nonce_field(); ?>
                        <?php submit_button(__('Delete', 'vereskala-login'), 'delete', 'submit', false); ?>
                    </form>
                </div>
            </div>
        <?php else : ?>
            <p class="description" style="max-width:760px"><?php esc_html_e('No automatic plan is active. Fill in the form below and enable it to start giving every buyer festival credit.', 'vereskala-login'); ?></p>
        <?php endif; ?>
        <span id="vkl-auto-form"></span>

        <form method="post" action="options.php" style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:14px 18px;max-width:760px;margin-bottom:18px">
            <?php settings_fields(self::AUTO_GROUP); ?>
            <label style="display:block;margin-bottom:10px">
                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('promo_auto_enabled')); ?>" value="0">
                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('promo_auto_enabled')); ?>" value="1"
                    <?php checked($this->config->get_bool('promo_auto_enabled', false)); ?>>
                <strong><?php esc_html_e('Give every buyer festival credit automatically', 'vereskala-login'); ?></strong>
            </label>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Credit amount', 'vereskala-login'); ?></th>
                    <td>
                        <input type="number" min="0" step="1000" style="width:160px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_amount')); ?>"
                               value="<?php echo (int) $this->config->get_int('promo_auto_amount', 0); ?>">
                        <p class="description"><?php esc_html_e('0 = off. This lands in the festival ledger, not the real wallet.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Qualifying order total', 'vereskala-login'); ?></th>
                    <td>
                        <input type="number" min="0" step="1000" style="width:160px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_min_order')); ?>"
                               value="<?php echo (int) $this->config->get_int('promo_auto_min_order', 0); ?>">
                        <p class="description"><?php esc_html_e('Only orders at or above this total earn the credit. 0 = every order.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Deposit after (hours)', 'vereskala-login'); ?></th>
                    <td>
                        <input type="number" min="0" max="720" style="width:110px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_delay_hours')); ?>"
                               value="<?php echo (int) $this->config->get_int('promo_auto_delay_hours', 0); ?>">
                        <p class="description"><?php esc_html_e('0 = the moment the order is paid. 24 = a day later, which usually brings the customer back sooner.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Valid for (days)', 'vereskala-login'); ?></th>
                    <td>
                        <input type="number" min="1" max="365" style="width:110px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_days')); ?>"
                               value="<?php echo (int) $this->config->get_int('promo_auto_days', 7); ?>">
                        <p class="description"><?php esc_html_e('Counted from the deposit, not from the purchase. Whatever is unused simply expires.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Minimum order to spend it', 'vereskala-login'); ?></th>
                    <td>
                        <input type="number" min="0" step="1000" style="width:160px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_use_min')); ?>"
                               value="<?php echo (int) $this->config->get_int('promo_auto_use_min', 0); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Title shown to the customer', 'vereskala-login'); ?></th>
                    <td>
                        <input type="text" class="regular-text"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_title')); ?>"
                               value="<?php echo esc_attr($this->config->get_string('promo_auto_title')); ?>"
                               placeholder="<?php esc_attr_e('Thank-you festival credit', 'vereskala-login'); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Announcement SMS pattern', 'vereskala-login'); ?></th>
                    <td>
                        <input type="text" dir="ltr" style="width:200px"
                               name="<?php echo esc_attr($this->config->option_name('promo_auto_pattern')); ?>"
                               value="<?php echo esc_attr($this->config->get_string('promo_auto_pattern')); ?>"
                               placeholder="wmw7nfeze5unxh9">
                        <p class="description"><?php esc_html_e('Sent the moment the credit lands. Variables: name (auto-filled), amount, min, days. Empty = no SMS.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <?php $vkl_auto_recent = vkl('wallet.promos')->recent_auto(); ?>
        <?php if ([] !== $vkl_auto_recent) : ?>
            <table class="widefat striped" style="max-width:760px;margin-bottom:18px">
                <thead><tr>
                    <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Credit amount', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Remaining', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Expires', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($vkl_auto_recent as $vkl_g) : ?>
                        <?php $vkl_g_user = get_userdata((int) $vkl_g['user_id']); ?>
                        <tr>
                            <td><?php echo esc_html($vkl_g_user ? $vkl_g_user->display_name : ('#' . (int) $vkl_g['user_id'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $vkl_g['amount'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n((float) $vkl_g['remaining'])); ?></td>
                            <td dir="ltr" style="font-size:12px"><?php echo esc_html((string) $vkl_g['expires_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description" style="margin-bottom:18px"><?php esc_html_e('No automatic grant has been made yet. Once a qualifying order is paid, the grants appear here and in the batch list below as one monthly campaign.', 'vereskala-login'); ?></p>
        <?php endif; ?>

        <h2 class="title vkl-sec-wallet">🎁 <?php esc_html_e('Festival wallet deposit (batch)', 'vereskala-login'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:12px 16px;max-width:640px;margin-bottom:14px">
            <input type="hidden" name="action" value="vkl_promo_grant">
            <?php $this->security->admin_nonce_field(); ?>
            <p style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
                <label><?php esc_html_e('Campaign title', 'vereskala-login'); ?><br>
                    <input type="text" name="vkl_promo_title" style="width:180px" required></label>
                <label><?php esc_html_e('Amount per customer', 'vereskala-login'); ?><br>
                    <input type="number" min="1" step="1" name="vkl_promo_amount" style="width:140px" required></label>
                <label><?php esc_html_e('Valid days', 'vereskala-login'); ?><br>
                    <input type="number" min="1" max="365" name="vkl_promo_days" value="7" style="width:90px" required></label>
                <label><?php esc_html_e('Minimum order to use it', 'vereskala-login'); ?><br>
                    <input type="number" min="0" step="1" name="vkl_promo_min" value="0" style="width:140px"></label>
                <label><?php esc_html_e('Announcement SMS pattern (optional)', 'vereskala-login'); ?><br>
                    <input type="text" dir="ltr" name="vkl_promo_pattern" style="width:170px" placeholder="wmw7nfeze5unxh9"
                           value="<?php echo esc_attr($this->config->get_string('promo_announce_pattern')); ?>"></label>
                <label><?php esc_html_e('Audience', 'vereskala-login'); ?><br>
                    <select name="vkl_promo_audience">
                        <option value="buyers"><?php esc_html_e('Customers with at least one paid order', 'vereskala-login'); ?></option>
                        <option value="all"><?php esc_html_e('All registered customers', 'vereskala-login'); ?></option>
                    </select></label>
                <?php submit_button(__('Grant festival credit', 'vereskala-login'), 'primary', 'submit', false); ?>
            </p>
            <p class="description">
                <?php esc_html_e('Festival credit is a separate ledger: it never appears in the customer statement, it is spent automatically BEFORE the real balance at checkout, and whatever is unused vanishes silently when the window ends. The customer sees it as a distinct "festival credit until DATE" line on their Finance tab.', 'vereskala-login'); ?>
                <?php esc_html_e('With a pattern code set, an announcement campaign is queued to exactly these recipients: %name% fills per customer automatically, and amount / min / days are available as variables. Track it on the SMS Campaigns page.', 'vereskala-login'); ?>
                <?php
                printf(
                    /* translators: 1: users count, 2: total amount. */
                    esc_html__('Currently active: %1$s customers holding %2$s.', 'vereskala-login'),
                    esc_html(number_format_i18n($vkl_promo_stats['users'])),
                    wp_kses_post(wc_price($vkl_promo_stats['total']))
                );
                ?>
            </p>
        </form>

        <table class="widefat striped" style="max-width:1100px;margin-top:10px">
            <thead><tr>
                <th><?php esc_html_e('Campaign title', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Amount per customer', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Recipients', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Consumers', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Consumed / remaining', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Expires', 'vereskala-login'); ?></th>
                <th style="width:250px"><?php esc_html_e('Actions', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php $vkl_batches = vkl('wallet.promos')->batches(); ?>
            <?php if ([] === $vkl_batches) : ?>
                <tr><td colspan="7"><?php esc_html_e('No festival grants yet.', 'vereskala-login'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($vkl_batches as $vkl_b) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) $vkl_b['title']); ?></strong>
                        <?php if ((float) $vkl_b['min_order'] > 0) : ?>
                            <div class="description"><?php printf(/* translators: %s: minimum order. */ esc_html__('usable on orders from %s', 'vereskala-login'), wp_kses_post(wc_price((float) $vkl_b['min_order']))); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo wp_kses_post(wc_price((float) $vkl_b['amount'])); ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer;color:#157f3a"><?php echo esc_html(number_format_i18n((int) $vkl_b['recipients'])); ?> 👥</summary>
                            <div style="max-height:220px;overflow:auto;margin-top:6px">
                                <?php foreach (vkl('wallet.promos')->batch_recipients((string) $vkl_b['batch']) as $vkl_r) : ?>
                                    <?php $vkl_u = get_userdata((int) $vkl_r['user_id']); ?>
                                    <div style="display:flex;gap:8px;align-items:center;border-bottom:1px solid #f0f0f0;padding:3px 0;font-size:12px">
                                        <span style="flex:1"><?php echo esc_html($vkl_u ? $vkl_u->display_name : ('#' . (int) $vkl_r['user_id'])); ?></span>
                                        <?php if ((float) $vkl_r['amount'] - (float) $vkl_r['remaining'] > 0) : ?>
                                            <span style="background:#eaf9ef;color:#157f3a;border-radius:10px;padding:0 8px"><?php printf(/* translators: %s: consumed amount. */ esc_html__('used %s', 'vereskala-login'), wp_strip_all_tags(wc_price((float) $vkl_r['amount'] - (float) $vkl_r['remaining']))); ?></span>
                                        <?php else : ?>
                                            <span style="color:#999"><?php esc_html_e('unused', 'vereskala-login'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </td>
                    <td><?php echo esc_html(number_format_i18n((int) $vkl_b['consumers'])); ?></td>
                    <td><?php echo wp_kses_post(wc_price((float) $vkl_b['consumed'])); ?> / <?php echo wp_kses_post(wc_price((float) $vkl_b['remaining_total'])); ?></td>
                    <td dir="ltr"><?php echo esc_html((string) $vkl_b['expires_at']); ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer;color:#157f3a"><?php esc_html_e('Edit / actions', 'vereskala-login'); ?></summary>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0">
                                <input type="hidden" name="action" value="vkl_promo_update">
                                <input type="hidden" name="vkl_batch" value="<?php echo esc_attr((string) $vkl_b['batch']); ?>">
                                <?php $this->security->admin_nonce_field(); ?>
                                <input type="text" name="vkl_promo_title" value="<?php echo esc_attr((string) $vkl_b['title']); ?>" style="width:100%;margin-bottom:4px">
                                <input type="datetime-local" name="vkl_promo_expires" value="<?php echo esc_attr(gmdate('Y-m-d\TH:i', strtotime((string) $vkl_b['expires_at']))); ?>" style="width:58%">
                                <input type="number" min="0" step="1" name="vkl_promo_min" value="<?php echo esc_attr((string) (float) $vkl_b['min_order']); ?>" style="width:38%" title="<?php esc_attr_e('Minimum order to use it', 'vereskala-login'); ?>">
                                <?php submit_button(__('Save', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  onsubmit="return confirm('<?php echo esc_js(__('Delete this festival grant for ALL its recipients? Remaining credit disappears immediately.', 'vereskala-login')); ?>');">
                                <input type="hidden" name="action" value="vkl_promo_delete">
                                <input type="hidden" name="vkl_batch" value="<?php echo esc_attr((string) $vkl_b['batch']); ?>">
                                <?php $this->security->admin_nonce_field(); ?>
                                <?php submit_button(__('Delete', 'vereskala-login'), 'delete small', 'submit', false); ?>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
    }

}
