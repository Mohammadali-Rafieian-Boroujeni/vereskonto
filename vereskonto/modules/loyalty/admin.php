<?php
/**
 * Loyalty admin settings.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Loyalty_Admin
 */
final class VKL_Loyalty_Admin
{
    private const GROUP = 'vkl_loyalty_settings';

    private VKL_Config $config;

    private VKL_Security $security;

    private VKL_Loyalty_Repository $repository;

    private VKL_Survey_Repository $surveys;

    public function __construct(VKL_Config $config, VKL_Security $security, VKL_Loyalty_Repository $repository, VKL_Survey_Repository $surveys)
    {
        $this->config     = $config;
        $this->security   = $security;
        $this->repository = $repository;
        $this->surveys    = $surveys;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vkl_survey_create', [$this, 'handle_survey_create']);
        add_action('admin_post_vkl_loyalty_backfill', [$this, 'handle_backfill']);
        add_action('admin_post_vkl_loyalty_groupsms', [$this, 'handle_groupsms']);
        add_action('admin_post_vkl_survey_close', [$this, 'handle_survey_close']);
        add_action('admin_post_vkl_loyalty_iban_sms', [$this, 'handle_iban_sms']);
    }

    /**
     * Create a survey from the admin form.
     */
    public function handle_survey_create(): void
    {
        $this->security->verify_admin_request();

        $question = isset($_POST['vkl_question']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_question'])) : '';
        $raw      = isset($_POST['vkl_options']) ? sanitize_textarea_field(wp_unslash((string) $_POST['vkl_options'])) : '';
        $points   = isset($_POST['vkl_points']) ? absint($_POST['vkl_points']) : 0;

        $options = array_values(array_filter(array_map('trim', explode("\n", $raw))));

        if ('' !== $question && count($options) >= 2) {
            $survey_id = $this->surveys->create($question, $options, $points);

            // Optional SMS invitation to all club members — queued through
            // the campaign engine so it is batched, logged and throttled
            // exactly like any other bulk send.
            $invite = isset($_POST['vkl_invite_pattern']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_invite_pattern'])) : '';

            if ('' !== $invite && $survey_id > 0) {
                try {
                    /** @var VKL_Sms_Marketing $marketing */
                    $marketing = vkl('sms.marketing');

                    $marketing->create(
                        sprintf(
                            /* translators: %s: survey question. */
                            __('Survey invitation: %s', 'vereskala-login'),
                            mb_substr($question, 0, 60)
                        ),
                        $invite,
                        [
                            'points' => (string) $points,
                            'link'   => wc_get_account_endpoint_url(VKL_Survey_Account::ENDPOINT),
                        ],
                        'club',
                        '',
                        0,
                        'campaign.survey'
                    );
                } catch (Throwable $e) {
                    vkl('logger')->error('Survey invitation campaign failed: ' . $e->getMessage());
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-loyalty'));
        exit;
    }

    /**
     * Close a survey.
     */
    public function handle_survey_close(): void
    {
        $this->security->verify_admin_request();

        $this->surveys->close(isset($_POST['vkl_survey_id']) ? absint($_POST['vkl_survey_id']) : 0);
        wp_safe_redirect(admin_url('admin.php?page=vkl-loyalty'));
        exit;
    }

    /**
     * Send a single customer the "please add your Sheba number" SMS
     * (admin-post) — one click from the Top members row.
     */
    public function handle_iban_sms(): void
    {
        $this->security->verify_admin_request();

        $user_id = isset($_POST['vkl_user_id']) ? absint($_POST['vkl_user_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $pattern = $this->config->get_string('loyalty_iban_request_pattern');
        $user    = $user_id > 0 ? get_userdata($user_id) : false;

        $ok = false;

        if ($user && '' !== $pattern) {
            $mobile = vkl('validator')->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

            if (vkl('validator')->is_mobile($mobile)) {
                $ok = vkl('sms.sender')->send(
                    $mobile,
                    'loyalty.iban_request',
                    $pattern,
                    ['name' => vkl('sms.sender')->resolve_name($user_id)],
                    24
                );
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-loyalty&vkl_iban_sms=' . ($ok ? 'ok' : 'fail')));
        exit;
    }

    /**
     * Tier label from this year's points.
     */
    private function tier(int $year_points): string
    {
        if ($year_points >= $this->config->get_int('loyalty_tier_diamond', 500)) {
            return __('Diamond', 'vereskala-login');
        }

        if ($year_points >= $this->config->get_int('loyalty_tier_gold', 200)) {
            return __('Gold', 'vereskala-login');
        }

        if ($year_points >= $this->config->get_int('loyalty_tier_silver', 50)) {
            return __('Silver', 'vereskala-login');
        }

        return __('Bronze', 'vereskala-login');
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Customer club', 'vereskala-login'),
            __('Customer club', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-loyalty',
            [$this, 'render']
        );

        add_submenu_page(
            'vereskala-login',
            __('Sales report', 'vereskala-login'),
            __('Sales report', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-history',
            [$this, 'render_history']
        );
    }

    public function register_settings(): void
    {
        register_setting(self::GROUP, $this->config->option_name('survey_review_points'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        foreach (['loyalty_perks_bronze', 'loyalty_perks_silver', 'loyalty_perks_gold', 'loyalty_perks_diamond'] as $perks_key) {
            register_setting(self::GROUP, $this->config->option_name($perks_key), [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ]);
        }

        foreach (['loyalty_tier_silver', 'loyalty_tier_gold', 'loyalty_tier_diamond'] as $tier_key) {
            register_setting(self::GROUP, $this->config->option_name($tier_key), [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ]);
        }

        register_setting(self::GROUP, $this->config->option_name('loyalty_ref_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_ref_reward'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_ref_min_order'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_ref_pattern'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '0',
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_amount_per_point'), [
            'type'              => 'integer',
            'sanitize_callback' => static fn(mixed $v): int => max(1, (int) $v),
            'default'           => 100000,
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_point_value'), [
            'type'              => 'integer',
            'sanitize_callback' => static fn(mixed $v): int => max(0, (int) $v),
            'default'           => 0,
        ]);

        register_setting(self::GROUP, $this->config->option_name('loyalty_iban_request_pattern'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function render(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Customer club', 'vereskala-login'); ?></h1>

            <?php
            $vkl_views = [
                'members'  => __('Top members', 'vereskala-login'),
                'surveys'  => __('Surveys', 'vereskala-login'),
                'settings' => __('Settings', 'vereskala-login'),
            ];
            $vkl_view = VKL_Admin_Tabs::current_view($vkl_views);
            VKL_Admin_Tabs::render_views('vkl-loyalty', $vkl_views, $vkl_view);
            ?>

            <?php if ('settings' === $vkl_view) : ?>
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable customer club', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('loyalty_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('loyalty_enabled')); ?>" value="1"
                                    <?php checked($this->config->get_bool('loyalty_enabled', false)); ?>>
                                <?php esc_html_e('Customers earn points on completed orders.', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Order amount per point', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="1" name="<?php echo esc_attr($this->config->option_name('loyalty_amount_per_point')); ?>"
                                   value="<?php echo esc_attr((string) $this->config->get_int('loyalty_amount_per_point', 100000)); ?>">
                            <p class="description"><?php esc_html_e('One point per this order amount (shop currency).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Point value for conversion', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="0" name="<?php echo esc_attr($this->config->option_name('loyalty_point_value')); ?>"
                                   value="<?php echo esc_attr((string) $this->config->get_int('loyalty_point_value', 0)); ?>">
                            <p class="description"><?php esc_html_e('Wallet credit per point (0 disables conversion).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Points per product review', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="0" step="1" style="width:90px"
                                   name="<?php echo esc_attr($this->config->option_name('survey_review_points')); ?>"
                                   value="<?php echo esc_attr((string) $this->config->get_int('survey_review_points', 5)); ?>">
                            <p class="description"><?php esc_html_e('Reward for reviewing a purchased product on the account Surveys tab. Reviews are moderated like normal WooCommerce reviews.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tier thresholds (points earned this year)', 'vereskala-login'); ?></th>
                        <td>
                            <?php
                            foreach ([
                                'loyalty_tier_silver'  => [__('Silver', 'vereskala-login'), 50],
                                'loyalty_tier_gold'    => [__('Gold', 'vereskala-login'), 200],
                                'loyalty_tier_diamond' => [__('Diamond', 'vereskala-login'), 500],
                            ] as $tier_key => [$tier_label, $tier_default]) {
                                printf(
                                    '<label style="margin-inline-end:14px">%s <input type="number" min="1" step="1" style="width:90px" name="%s" value="%d"></label>',
                                    esc_html($tier_label),
                                    esc_attr($this->config->option_name($tier_key)),
                                    (int) $this->config->get_int($tier_key, $tier_default)
                                );
                            }
                            ?>
                            <p class="description"><?php esc_html_e('Below Silver counts as Bronze. Yearly points mirror yearly purchases (one point per the order amount set above).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Tier benefits (shown to customers, one per line)', 'vereskala-login'); ?></th>
                        <td>
                            <?php
                            foreach ([
                                'loyalty_perks_bronze'  => __('Bronze', 'vereskala-login'),
                                'loyalty_perks_silver'  => __('Silver', 'vereskala-login'),
                                'loyalty_perks_gold'    => __('Gold', 'vereskala-login'),
                                'loyalty_perks_diamond' => __('Diamond', 'vereskala-login'),
                            ] as $perks_key => $perks_label) {
                                printf(
                                    '<p><label style="font-weight:600">%s</label><br><textarea class="large-text" rows="3" name="%s">%s</textarea></p>',
                                    esc_html($perks_label),
                                    esc_attr($this->config->option_name($perks_key)),
                                    esc_textarea($this->config->get_string($perks_key))
                                );
                            }
                            ?>
                            <p class="description"><?php esc_html_e('Leave empty to use the built-in defaults (e.g. free dispatch for Gold and above). These lists appear on the customer club tab under each tier.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">🤝 <?php esc_html_e('Refer-a-friend', 'vereskala-login'); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px">
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('loyalty_ref_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('loyalty_ref_enabled')); ?>" value="1" <?php checked($this->config->get_bool('loyalty_ref_enabled', false)); ?>>
                                <?php esc_html_e('Enable invitations: every customer gets a unique code & link on their club page.', 'vereskala-login'); ?>
                            </label>
                            <p style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;margin:8px 0">
                                <label><?php esc_html_e('Reward per referral (deposited to wallet)', 'vereskala-login'); ?><br>
                                    <input type="number" min="0" step="1000" style="width:150px"
                                           name="<?php echo esc_attr($this->config->option_name('loyalty_ref_reward')); ?>"
                                           value="<?php echo (int) $this->config->get_int('loyalty_ref_reward', 0); ?>"></label>
                                <label><?php esc_html_e('Minimum first order of the invited friend', 'vereskala-login'); ?><br>
                                    <input type="number" min="0" step="1000" style="width:150px"
                                           name="<?php echo esc_attr($this->config->option_name('loyalty_ref_min_order')); ?>"
                                           value="<?php echo (int) $this->config->get_int('loyalty_ref_min_order', 0); ?>"></label>
                                <label><?php esc_html_e('Deposit SMS pattern', 'vereskala-login'); ?><br>
                                    <input type="text" dir="ltr" style="width:170px"
                                           name="<?php echo esc_attr($this->config->option_name('loyalty_ref_pattern')); ?>"
                                           value="<?php echo esc_attr($this->config->get_string('loyalty_ref_pattern')); ?>"
                                           placeholder="wmw7nfeze5unxh9"></label>
                            </p>
                            <p class="description">
                                <?php esc_html_e('The reward lands automatically after the invited friend\'s FIRST order at or above the minimum — once per friend. The SMS pattern receives name (auto-filled) and amount as variables; leave it empty to skip the SMS.', 'vereskala-login'); ?>
                                <?php $vkl_ref_stats = vkl('loyalty.referral')->stats(); ?>
                                <strong><?php printf(/* translators: 1: rewarded, 2: total, 3: paid sum. */ esc_html__('So far: %1$s of %2$s invitees purchased — %3$s deposited.', 'vereskala-login'), esc_html(number_format_i18n($vkl_ref_stats['rewarded'])), esc_html(number_format_i18n($vkl_ref_stats['total'])), esc_html(number_format_i18n($vkl_ref_stats['paid']))); ?></strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">📨 درخواست شماره شبا با پیامک</th>
                        <td>
                            <input type="text" dir="ltr" style="width:220px"
                                   name="<?php echo esc_attr($this->config->option_name('loyalty_iban_request_pattern')); ?>"
                                   value="<?php echo esc_attr($this->config->get_string('loyalty_iban_request_pattern')); ?>"
                                   placeholder="wmw7nfeze5unxh9">
                            <p class="description">کد پترن پیامکی که با کلیک روی «درخواست شبا» کنار هر عضو ارسال می‌شود. متغیر پترن: name. برای غیرفعال کردن دکمه، خالی بگذارید.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <?php endif; // settings view ?>

            <?php if ('members' === $vkl_view) : ?>
            <h2 class="title"><?php esc_html_e('Top members', 'vereskala-login'); ?></h2>

            <?php if (isset($_GET['vkl_iban_sms'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-<?php echo 'ok' === $_GET['vkl_iban_sms'] ? 'success' : 'error'; ?> is-dismissible"><p> <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                    <?php echo 'ok' === $_GET['vkl_iban_sms'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        ? 'پیامک ارسال شد.'
                        : 'ارسال نشد: کد پترن و شماره موبایل مشتری را بررسی کنید.'; ?>
                </p></div>
            <?php endif; ?>

            <?php
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter.
            $vkl_m_q     = isset($_GET['vkl_mq']) ? sanitize_text_field(wp_unslash($_GET['vkl_mq'])) : '';
            $vkl_m_paged = isset($_GET['vkl_mpage']) ? max(1, absint($_GET['vkl_mpage'])) : 1;
            // phpcs:enable
            $vkl_m_per   = 30;
            $vkl_m_ids   = [];

            if ('' !== $vkl_m_q) {
                // Resolve the query to user ids: mobile, name, or email.
                $vkl_found = get_users([
                    'search'         => '*' . $vkl_m_q . '*',
                    'search_columns' => ['user_login', 'user_email', 'display_name', 'user_nicename'],
                    'fields'         => 'ID',
                    'number'         => 200,
                ]);

                $vkl_by_phone = get_users([
                    'meta_key'     => 'billing_phone',
                    'meta_value'   => $vkl_m_q,
                    'meta_compare' => 'LIKE',
                    'fields'       => 'ID',
                    'number'       => 200,
                ]);

                $vkl_m_ids = array_map('intval', array_unique(array_merge($vkl_found, $vkl_by_phone)));

                if ([] === $vkl_m_ids) {
                    $vkl_m_ids = [0]; // force an empty result set
                }
            }

            $vkl_m_total = '' !== $vkl_m_q ? count(array_filter($vkl_m_ids)) : $this->repository->members_count();
            $members     = $this->repository->members($vkl_m_per, 0, ($vkl_m_paged - 1) * $vkl_m_per, $vkl_m_ids);
            $vkl_m_pos   = ($vkl_m_paged - 1) * $vkl_m_per;
            ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:6px;margin:0 0 12px;max-width:520px">
                <input type="hidden" name="page" value="vkl-loyalty">
                <input type="search" name="vkl_mq" value="<?php echo esc_attr($vkl_m_q); ?>"
                       placeholder="<?php esc_attr_e('Search members by mobile, name or email…', 'vereskala-login'); ?>" style="flex:1">
                <button class="button"><?php esc_html_e('Search', 'vereskala-login'); ?></button>
                <?php if ('' !== $vkl_m_q) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'vkl-loyalty'], admin_url('admin.php'))); ?>">✕</a>
                <?php endif; ?>
            </form>
            <?php if ([] === $members) : ?>
                <p><?php esc_html_e('No members with points yet.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:960px">
                    <thead>
                    <tr>
                        <th style="width:52px">#</th>
                        <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Points balance', 'vereskala-login'); ?></th>
                        <th><?php echo esc_html(sprintf(/* translators: %d: year. */ __('Points earned in %d', 'vereskala-login'), (int) gmdate('Y'))); ?></th>
                        <th><?php esc_html_e('Estimated purchases this year', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Orders (year / total)', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Member since', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Tier', 'vereskala-login'); ?></th>
                        <th style="width:110px">کد ملی</th>
                        <th style="width:180px">شماره شبا</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $vkl_iban_pattern = $this->config->get_string('loyalty_iban_request_pattern'); ?>
                    <?php foreach ($members as $member) : ?>
                        <?php
                        $member_user = get_userdata((int) ($member['user_id'] ?? 0));
                        $year_points = (int) ($member['year_points'] ?? 0);
                        $per_point   = max(1, $this->config->get_int('loyalty_amount_per_point', 100000));
                        ?>
                        <tr>
                            <td style="width:52px;color:#157f3a;font-weight:700"><?php echo esc_html(number_format_i18n(++$vkl_m_pos)); ?></td>
                            <td><?php echo esc_html($member_user ? $member_user->display_name : ('#' . (int) $member['user_id'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) ($member['balance'] ?? 0))); ?></td>
                            <td><?php echo esc_html(number_format_i18n($year_points)); ?></td>
                            <td><?php echo function_exists('wc_price') ? wp_kses_post(wc_price($year_points * $per_point)) : esc_html(number_format_i18n($year_points * $per_point)); ?></td>
                            <?php
                            $vkl_act   = $this->repository->member_activity((int) $member['user_id']);
                            $vkl_since = $this->repository->member_since((int) $member['user_id']);
                            ?>
                            <td dir="ltr"><?php echo esc_html(number_format_i18n($vkl_act['year_orders']) . ' / ' . number_format_i18n($vkl_act['orders'])); ?></td>
                            <td dir="ltr" style="font-size:12px"><?php echo esc_html('' !== $vkl_since ? mysql2date(get_option('date_format'), $vkl_since) : '—'); ?></td>
                            <td><strong><?php echo esc_html($this->tier($year_points)); ?></strong></td>
                            <td dir="ltr" style="font-size:12.5px">
                                <?php
                                $vkl_m_nid = (string) get_user_meta((int) $member['user_id'], 'vkl_national_id', true);
                                echo '' !== $vkl_m_nid ? esc_html($vkl_m_nid) : '—';
                                ?>
                            </td>
                            <td dir="ltr" style="font-size:12.5px">
                                <?php $vkl_m_iban = (string) get_user_meta((int) $member['user_id'], 'vkl_iban', true); ?>
                                <?php if ('' !== $vkl_m_iban) : ?>
                                    <?php echo esc_html($vkl_m_iban); ?>
                                <?php elseif ('' !== $vkl_iban_pattern) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <input type="hidden" name="action" value="vkl_loyalty_iban_sms">
                                        <input type="hidden" name="vkl_user_id" value="<?php echo (int) $member['user_id']; ?>">
                                        <?php $this->security->admin_nonce_field(); ?>
                                        <?php submit_button('درخواست شبا', 'secondary small', 'submit', false); ?>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php $vkl_m_pages = (int) ceil($vkl_m_total / $vkl_m_per); ?>
                <?php if ($vkl_m_pages > 1) : ?>
                    <div style="display:flex;gap:6px;align-items:center;margin:12px 0;flex-wrap:wrap">
                        <?php for ($vkl_mp = 1; $vkl_mp <= min($vkl_m_pages, 40); $vkl_mp++) : ?>
                            <a class="button <?php echo $vkl_mp === $vkl_m_paged ? 'button-primary' : ''; ?>"
                               href="<?php echo esc_url(add_query_arg(array_filter(['page' => 'vkl-loyalty', 'vkl_mpage' => $vkl_mp, 'vkl_mq' => '' !== $vkl_m_q ? $vkl_m_q : null]), admin_url('admin.php'))); ?>"><?php echo esc_html(number_format_i18n($vkl_mp)); ?></a>
                        <?php endfor; ?>
                        <span class="description"><?php printf(/* translators: %s: members count. */ esc_html__('%s members with points', 'vereskala-login'), esc_html(number_format_i18n($vkl_m_total))); ?></span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; // members view ?>

            <?php if ('surveys' === $vkl_view) : ?>
            <h2 class="title"><?php esc_html_e('Surveys', 'vereskala-login'); ?></h2>
            <p class="description"><?php esc_html_e('Customers answer in the "Surveys" tab of My Account; the reward lands in their club points and converts to wallet credit like any other points.', 'vereskala-login'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:12px 16px;max-width:640px;margin-bottom:16px">
                <input type="hidden" name="action" value="vkl_survey_create">
                <?php $this->security->admin_nonce_field(); ?>
                <p>
                    <label style="display:block;font-weight:600"><?php esc_html_e('Question', 'vereskala-login'); ?></label>
                    <input type="text" class="large-text" name="vkl_question" required>
                </p>
                <p>
                    <label style="display:block;font-weight:600"><?php esc_html_e('Options (one per line, at least two)', 'vereskala-login'); ?></label>
                    <textarea class="large-text" rows="4" name="vkl_options" required></textarea>
                </p>
                <p>
                    <label style="font-weight:600"><?php esc_html_e('Reward points', 'vereskala-login'); ?></label>
                    <input type="number" min="0" step="1" name="vkl_points" value="10" style="width:90px">
                </p>
                <p>
                    <label style="font-weight:600"><?php esc_html_e('SMS invitation pattern (optional)', 'vereskala-login'); ?></label>
                    <input type="text" name="vkl_invite_pattern" class="regular-text" dir="ltr">
                </p>
                <p class="description">
                    <?php esc_html_e('Filled in = every club member is invited by SMS through the campaign queue (batched, logged, respectful of the daily scheduler). CRM-style structure — pattern variables: points, link. Example pattern text:', 'vereskala-login'); ?><br>
                    «ورسکالایی گرامی، با پاسخ به یک پرسش کوتاه %points% امتیاز بگیرید (قابل تبدیل به کیف پول): %link%»
                </p>
                <?php submit_button(__('Create survey', 'vereskala-login'), 'primary', 'submit', false); ?>
            </form>

            <?php foreach ($this->surveys->all() as $survey) : ?>
                <details style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px;margin-bottom:8px;max-width:640px">
                    <summary>
                        <?php echo esc_html((string) $survey['question']); ?>
                        — <?php echo 'active' === (string) $survey['status'] ? esc_html__('Open', 'vereskala-login') : esc_html__('Closed', 'vereskala-login'); ?>
                        (<?php echo (int) $survey['points']; ?> <?php esc_html_e('Points', 'vereskala-login'); ?>)
                    </summary>
                    <div style="margin-top:8px">
                        <?php
                        $results = $this->surveys->results((int) $survey['id']);
                        $total   = array_sum($results);

                        if ([] === $results) {
                            echo '<p>' . esc_html__('No answers yet.', 'vereskala-login') . '</p>';
                        }

                        foreach ($results as $option => $count) {
                            $pct = $total > 0 ? (int) round($count * 100 / $total) : 0;
                            echo '<div style="display:flex;align-items:center;gap:8px;margin:4px 0">'
                                . '<span style="min-width:160px">' . esc_html($option) . '</span>'
                                . '<span style="flex:1;background:#eee;border-radius:3px;height:9px;overflow:hidden"><span style="display:block;height:100%;width:' . esc_attr((string) $pct) . '%;background:#157f3a"></span></span>'
                                . '<span style="min-width:60px">' . esc_html((string) $count) . ' (' . esc_html((string) $pct) . '%)</span>'
                                . '</div>';
                        }
                        ?>
                        <?php if ('active' === (string) $survey['status']) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                                <input type="hidden" name="action" value="vkl_survey_close">
                                <input type="hidden" name="vkl_survey_id" value="<?php echo (int) $survey['id']; ?>">
                                <?php $this->security->admin_nonce_field(); ?>
                                <?php submit_button(__('Close survey', 'vereskala-login'), 'secondary small', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            <?php endif; // surveys view ?>
        </div>
        <?php
    }

    /**
     * Dedicated "Purchase history" page: import past orders into the
     * club ledger, with progress and a clear explanation.
     */
    public function render_history(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $offset = (int) get_option('vkl_loyalty_backfill_offset', 0);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Sales report', 'vereskala-login'); ?></h1>

            <?php if (isset($_GET['vkl_backfill'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    printf(
                        /* translators: 1: orders processed, 2: orders granted points. */
                        esc_html__('Purchase-history import: %1$s orders scanned, %2$s received points.', 'vereskala-login'),
                        esc_html((string) absint($_GET['vkl_processed'] ?? 0)), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        esc_html((string) absint($_GET['vkl_granted'] ?? 0)) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    );

                    if ('more' === (string) ($_GET['vkl_backfill'] ?? '')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        echo ' <strong>' . esc_html__('There are more orders — click the import button again to continue.', 'vereskala-login') . '</strong>';
                    } else {
                        echo ' ' . esc_html__('All done — tiers, Top members and the customer club tab now include the full history.', 'vereskala-login');
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <?php
            // ---- Sales dashboard: range filter + categorised best-sellers. ----
            global $wpdb;

            $vkl_range  = isset($_GET['vkl_range']) ? sanitize_key($_GET['vkl_range']) : 'month'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $vkl_ranges = [
                'day'   => [__('Today', 'vereskala-login'), '-1 day'],
                'week'  => [__('This week', 'vereskala-login'), '-7 days'],
                'month' => [__('This month', 'vereskala-login'), '-30 days'],
                'year'  => [__('This year', 'vereskala-login'), '-365 days'],
            ];

            if (!isset($vkl_ranges[$vkl_range])) {
                $vkl_range = 'month';
            }

            $vkl_since  = gmdate('Y-m-d H:i:s', (int) strtotime($vkl_ranges[$vkl_range][1]));
            $vkl_lookup = $wpdb->prefix . 'wc_order_product_lookup';
            $vkl_stats  = $wpdb->prefix . 'wc_order_stats';
            $vkl_has_lookup = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $vkl_lookup)) === $vkl_lookup; // phpcs:ignore
            ?>

            <h2 class="title" style="margin-top:24px"><?php esc_html_e('Sales overview', 'vereskala-login'); ?></h2>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 14px">
                <?php foreach ($vkl_ranges as $vkl_key => $vkl_def) : ?>
                    <a class="button <?php echo $vkl_key === $vkl_range ? 'button-primary' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg(['page' => 'vkl-history', 'vkl_range' => $vkl_key], admin_url('admin.php'))); ?>">
                        <?php echo esc_html($vkl_def[0]); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (!$vkl_has_lookup) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('WooCommerce analytics tables are not available yet — they fill automatically as WooCommerce Analytics runs.', 'vereskala-login'); ?></p></div>
            <?php else : ?>
                <?php
                $vkl_summary = $wpdb->get_row($wpdb->prepare( // phpcs:ignore
                    "SELECT COUNT(*) AS orders_count, COALESCE(SUM(net_total), 0) AS revenue
                     FROM {$vkl_stats}
                     WHERE status IN ('wc-completed', 'wc-processing') AND date_created_gmt >= %s",
                    $vkl_since
                ), ARRAY_A);

                // Cancellations for the same window, so the two sit side by
                // side instead of living on a page of their own.
                $vkl_cancelled = $wpdb->get_row($wpdb->prepare( // phpcs:ignore
                    "SELECT COUNT(*) AS orders_count, COALESCE(SUM(net_total), 0) AS lost
                     FROM {$vkl_stats}
                     WHERE status = 'wc-cancelled' AND date_created_gmt >= %s",
                    $vkl_since
                ), ARRAY_A);

                // Cancelled quantity per product, keyed by product id.
                $vkl_cancel_rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
                    "SELECT l.product_id, SUM(l.product_qty) AS qty
                     FROM {$vkl_lookup} l
                     INNER JOIN {$vkl_stats} o ON o.order_id = l.order_id
                     WHERE o.status = 'wc-cancelled' AND o.date_created_gmt >= %s
                     GROUP BY l.product_id",
                    $vkl_since
                ), ARRAY_A);

                $vkl_cancel_qty = [];

                foreach ((array) $vkl_cancel_rows as $vkl_cr) {
                    $vkl_cancel_qty[(int) $vkl_cr['product_id']] = (int) $vkl_cr['qty'];
                }

                $vkl_products = $wpdb->get_results($wpdb->prepare( // phpcs:ignore
                    "SELECT l.product_id, SUM(l.product_qty) AS qty, SUM(l.product_net_revenue) AS revenue
                     FROM {$vkl_lookup} l
                     INNER JOIN {$vkl_stats} s ON s.order_id = l.order_id
                     WHERE s.status IN ('wc-completed', 'wc-processing') AND s.date_created_gmt >= %s
                     GROUP BY l.product_id
                     ORDER BY revenue DESC
                     LIMIT 60",
                    $vkl_since
                ), ARRAY_A);

                // Standard grouping: by the product's primary category.
                $vkl_groups = [];

                foreach ((array) $vkl_products as $vkl_p) {
                    $vkl_terms = get_the_terms((int) $vkl_p['product_id'], 'product_cat');
                    $vkl_cat   = is_array($vkl_terms) && [] !== $vkl_terms ? $vkl_terms[0]->name : __('Uncategorised', 'vereskala-login');

                    $vkl_groups[$vkl_cat][] = $vkl_p;
                }
                ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:0 0 16px;max-width:640px">
                    <div style="background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:12px 16px">
                        <div style="font-size:12px;color:#666">🧾 <?php esc_html_e('Paid orders', 'vereskala-login'); ?></div>
                        <div style="font-size:24px;font-weight:700;color:#157f3a"><?php echo esc_html(number_format_i18n((int) ($vkl_summary['orders_count'] ?? 0))); ?></div>
                    </div>
                    <div style="background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:12px 16px">
                        <div style="font-size:12px;color:#666">💵 <?php esc_html_e('Revenue', 'vereskala-login'); ?></div>
                        <div style="font-size:24px;font-weight:700;color:#157f3a"><?php echo wp_kses_post(wc_price((float) ($vkl_summary['revenue'] ?? 0))); ?></div>
                    <div style="flex:1;min-width:150px;border:1px solid #f0c9c9;border-radius:12px;padding:10px 14px;background:#fdf7f7">
                        <div style="font-size:12px;color:#666">🚫 <?php esc_html_e('Cancelled orders', 'vereskala-login'); ?></div>
                        <div style="font-size:20px;font-weight:800;color:#b3282e"><?php echo esc_html(number_format_i18n((int) ($vkl_cancelled['orders_count'] ?? 0))); ?></div>
                        <div style="font-size:11px;color:#8a5b5b">
                            <?php
                            printf(
                                /* translators: %s: formatted money. */
                                esc_html__('%s not realised', 'vereskala-login'),
                                wp_kses_post(wc_price((float) ($vkl_cancelled['lost'] ?? 0)))
                            );
                            ?>
                        </div>
                    </div>
                    </div>
                </div>

                <?php if ([] === $vkl_groups) : ?>
                    <p class="description"><?php esc_html_e('No sales in this range yet.', 'vereskala-login'); ?></p>
                <?php endif; ?>

                <?php foreach ($vkl_groups as $vkl_cat_name => $vkl_rows) : ?>
                    <h3 style="margin:18px 0 6px">🏷️ <?php echo esc_html($vkl_cat_name); ?></h3>
                    <table class="widefat striped" style="max-width:760px">
                        <thead><tr>
                            <th><?php esc_html_e('Product', 'vereskala-login'); ?></th>
                            <th style="width:110px"><?php esc_html_e('Qty sold', 'vereskala-login'); ?></th>
                            <th style="width:160px"><?php esc_html_e('Revenue', 'vereskala-login'); ?></th>
                            <th style="width:120px"><?php esc_html_e('Cancelled', 'vereskala-login'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($vkl_rows as $vkl_row) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url((string) get_edit_post_link((int) $vkl_row['product_id'])); ?>"><?php echo esc_html(get_the_title((int) $vkl_row['product_id']) ?: ('#' . (int) $vkl_row['product_id'])); ?></a></td>
                                <td><?php echo esc_html(number_format_i18n((int) $vkl_row['qty'])); ?></td>
                                <td><?php echo wp_kses_post(wc_price((float) $vkl_row['revenue'])); ?></td>
                                <?php $vkl_c = (int) ($vkl_cancel_qty[(int) $vkl_row['product_id']] ?? 0); ?>
                                <td>
                                    <?php if ($vkl_c > 0) : ?>
                                        <span style="background:#fdecec;color:#b3282e;border:1px solid #f3cfcf;border-radius:999px;padding:1px 10px;font-size:12px">
                                            −<?php echo esc_html(number_format_i18n($vkl_c)); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color:#9aa">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php // Cancelled orders used to be listed here too (unpaged); they now live on one page, vkl-cancelled. ?>
            <p style="margin-top:22px">
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=vkl-cancelled&vkl_who=all')); ?>">🚫 <?php esc_html_e('Open the cancelled orders report', 'vereskala-login'); ?></a>
            </p>

            <h2 class="title" style="margin-top:26px"><?php esc_html_e('Import purchase history', 'vereskala-login'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:14px 18px;max-width:420px">
                <input type="hidden" name="action" value="vkl_loyalty_backfill">
                <?php $this->security->admin_nonce_field(); ?>
                <p style="margin-top:0">
                    <?php
                    printf(
                        /* translators: %s: current offset. */
                        esc_html__('Progress so far: %s orders scanned.', 'vereskala-login'),
                        esc_html(number_format_i18n($offset))
                    );
                    ?>
                </p>
                <?php submit_button($offset > 0 ? __('Import next batch', 'vereskala-login') : __('Start import', 'vereskala-login'), 'primary', 'submit', false); ?>
            </form>


            <p class="description" style="max-width:640px">
                <?php esc_html_e('Grants club points for PAST completed orders from the database, 100 orders per click. Safe to repeat — every order counts at most once.', 'vereskala-login'); ?>
                <?php esc_html_e('Tiers, Top members and the customer club tab update automatically, because they all read this ledger.', 'vereskala-login'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Backfill: import points for PAST completed orders, one batch per
     * click. Idempotent — the same reason/reference the live listener
     * writes ('order_completed' / 'order:{id}'), so nothing is granted
     * twice no matter how often it runs. Tiers, Top members and the club
     * tab update automatically since they all read this ledger.
     */
    public function handle_backfill(): void
    {
        $this->security->verify_admin_request();

        $per    = max(1, $this->config->get_int('loyalty_amount_per_point', 100000));
        $offset = (int) get_option('vkl_loyalty_backfill_offset', 0);
        $orders = wc_get_orders([
            'status'  => ['completed'],
            'limit'   => 100,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);

        $granted = 0;
        $repo    = vkl('loyalty.repository');

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $user_id = (int) $order->get_customer_id();

            if ($user_id <= 0 || '' !== (string) $order->get_meta('_vkl_wallet_topup')) {
                continue;
            }

            $reference = 'order:' . $order->get_id();

            if ($repo->exists('order_completed', $reference)) {
                continue;
            }

            $points = (int) floor((float) $order->get_total() / $per);

            if ($points > 0) {
                $repo->append($user_id, $points, 'order_completed', $reference);
                ++$granted;
            }
        }

        $processed = count($orders);
        $done      = $processed < 100;

        update_option('vkl_loyalty_backfill_offset', $done ? 0 : $offset + $processed, false);

        wp_safe_redirect(add_query_arg([
            'page'          => 'vkl-history',
            'vkl_backfill'  => $done ? 'done' : 'more',
            'vkl_processed' => $offset + $processed,
            'vkl_granted'   => $granted,
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Queue a group SMS for club members / a tier (admin-post) —
     * delegates to the campaign engine so batching, logging, and the
     * campaigns UI all apply.
     */
    public function handle_groupsms(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $audience = sanitize_text_field(wp_unslash($_POST['vkl_audience'] ?? 'club'));
        $pattern  = sanitize_text_field(wp_unslash($_POST['vkl_pattern'] ?? ''));
        $raw      = sanitize_textarea_field(wp_unslash($_POST['vkl_params'] ?? ''));
        // phpcs:enable

        $params = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = explode('=', $line, 2);

            if (2 === count($parts) && '' !== trim($parts[0])) {
                $params[trim($parts[0])] = trim($parts[1]);
            }
        }

        if ('' !== $pattern) {
            vkl('sms.marketing')->create(
                __('Club group SMS', 'vereskala-login') . ' — ' . gmdate('Y-m-d H:i'),
                $pattern,
                $params,
                $audience
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-loyalty&vkl_groupsms=1'));
        exit;
    }
}
