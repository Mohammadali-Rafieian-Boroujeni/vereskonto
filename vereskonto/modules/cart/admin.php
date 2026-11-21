<?php
/**
 * Cart admin screen.
 *
 * Submenu under the main plugin menu: cart module settings (including
 * the WooCommerce coupon attached to recovery messages and the WhatsApp
 * template) plus an overview of recent carts with visible line items
 * and per-cart manual send buttons (SMS / WhatsApp). Presentation
 * only — data access goes through the repository, delivery through the
 * shared notifier.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Admin
 */
final class VKL_Cart_Admin
{
    /**
     * Admin page slug.
     */
    private const PAGE_SLUG = 'vkl-cart';

    /**
     * Settings group.
     */
    private const SETTINGS_GROUP = 'vkl_cart_settings';

    /**
     * Manual-send admin-post actions.
     */
    private const ACTION_SEND_SMS = 'vkl_cart_send_sms';
    private const ACTION_SEND_WA  = 'vkl_cart_send_wa';

    /**
     * Repository.
     */
    private VKL_Cart_Repository $repository;

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Security.
     */
    private VKL_Security $security;

    /**
     * Abandoned-cart notifier (SMS + WhatsApp).
     */
    private VKL_Cart_Sms $notifier;

    /**
     * @param VKL_Cart_Repository $repository Repository.
     * @param VKL_Config          $config     Config.
     * @param VKL_Security        $security   Security.
     * @param VKL_Cart_Sms        $notifier   Shared notifier.
     */
    public function __construct(
        VKL_Cart_Repository $repository,
        VKL_Config $config,
        VKL_Security $security,
        VKL_Cart_Sms $notifier
    ) {
        $this->repository = $repository;
        $this->config     = $config;
        $this->security   = $security;
        $this->notifier   = $notifier;
    }

    /**
     * Register menu, settings and manual-send handlers.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_' . self::ACTION_SEND_SMS, [$this, 'handle_send_sms']);
        add_action('admin_post_' . self::ACTION_SEND_WA, [$this, 'handle_send_wa']);
        add_action('admin_post_vkl_cart_purge', [$this, 'handle_purge']);
    }

    /**
     * Add the submenu under the existing menu.
     */
    public function add_menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Cart Tracking', 'vereskala-login'),
            __('Cart Tracking', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Register module settings with sanitisation.
     */
    public function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_tracking_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '1',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_sms_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_wa_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_abandon_minutes'), [
            'type'              => 'integer',
            'sanitize_callback' => static fn(mixed $v): int => max(5, min(10080, (int) $v)),
            'default'           => 60,
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_pattern_code'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_wa_template'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_retention_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_purge_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        foreach (['cart_coupon_auto' => 'sanitize_text_field', 'cart_coupon_amount' => 'absint', 'cart_coupon_days' => 'absint'] as $auto_key => $auto_sanitize) {
            register_setting(self::SETTINGS_GROUP, $this->config->option_name($auto_key), [
                'type'              => 'string',
                'sanitize_callback' => $auto_sanitize,
            ]);
        }

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('cart_coupon_code'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);
    }

    /**
     * Published WooCommerce coupon codes for the settings dropdown.
     *
     * @return array<int, string>
     */
    private function coupon_codes(): array
    {
        if (!class_exists('WooCommerce')) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $codes = [];

        foreach ($posts as $post_id) {
            $code = strtolower((string) get_the_title((int) $post_id));

            if ('' !== $code) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Manual SMS send for one cart row.
     */
    public function handle_send_sms(): void
    {
        $this->handle_manual_send('sms');
    }

    /**
     * Manual WhatsApp send for one cart row.
     */
    public function handle_send_wa(): void
    {
        $this->handle_manual_send('whatsapp');
    }

    /**
     * Shared manual-send handler: verify, load the cart, deliver, redirect.
     *
     * @param string $channel 'sms' or 'whatsapp'.
     */
    private function handle_manual_send(string $channel): void
    {
        $this->security->verify_admin_request();

        $cart_id = isset($_POST['cart_id']) ? absint(wp_unslash($_POST['cart_id'])) : 0;
        $cart    = $cart_id > 0 ? $this->repository->find($cart_id) : null;
        $sent    = false;

        if (null !== $cart && '' !== (string) $cart['mobile']) {
            $mobile  = (string) $cart['mobile'];
            $user_id = (int) $cart['user_id'];
            $payload = [
                'cart_token' => (string) $cart['cart_token'],
                'user_id'    => $user_id,
                'mobile'     => $mobile,
                'total'      => (float) $cart['total'],
                'manual'     => true,
            ];

            $sent = 'whatsapp' === $channel
                ? $this->notifier->notify_whatsapp($mobile, $user_id, $payload)
                : $this->notifier->notify_sms($mobile, $user_id, $payload);

            if ($sent) {
                $this->repository->mark_notified((string) $cart['cart_token']);
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'vkl_sent' => $sent ? '1' : '0'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Render one cart's items as an expandable list.
     *
     * @param array<string, mixed> $cart Cart row.
     */
    private function render_items(array $cart): void
    {
        $items = json_decode((string) ($cart['items'] ?? ''), true);
        $count = (int) $cart['item_count'];

        if (!is_array($items) || [] === $items) {
            echo esc_html((string) $count);

            return;
        }

        echo '<details><summary>' . esc_html((string) $count) . '</summary><ul style="margin:4px 0 0 1em">';

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');

            if ('' === $name) {
                $name = get_the_title((int) ($item['product_id'] ?? 0));
            }

            printf(
                '<li>%s × %d</li>',
                esc_html('' !== $name ? $name : ('#' . (int) ($item['product_id'] ?? 0))),
                (int) ($item['quantity'] ?? 0)
            );
        }

        echo '</ul></details>';
    }

    /**
     * Section switcher: cart overview vs. active users.
     */
    private function render_section_tabs(string $current): void
    {
        $tabs = [
            ''                             => 'سبدها',
            VKL_Cart_ActiveUsers::SECTION => 'کاربران کارا',
        ];

        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:12px">';

        foreach ($tabs as $key => $label) {
            $url = add_query_arg(
                array_filter(['page' => self::PAGE_SLUG, 'vkl_section' => $key]),
                admin_url('admin.php')
            );
            printf(
                '<a class="nav-tab%s" href="%s">%s</a>',
                $key === $current ? ' nav-tab-active' : '',
                esc_url($url),
                esc_html($label)
            );
        }

        echo '</h2>';
    }

    /**
     * Render the admin page.
     */
    public function render(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $section = isset($_GET['vkl_section']) ? sanitize_key((string) $_GET['vkl_section']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (VKL_Cart_ActiveUsers::SECTION === $section && vkl()->has('cart.active')) {
            echo '<div class="wrap"><h1>ردیابی سبد خرید</h1>';
            $this->render_section_tabs($section);
            vkl('cart.active')->render();
            echo '</div>';

            return;
        }

        $counts   = $this->repository->count_by_status();
        $notified = $this->repository->count_notified();

        // Status filter tabs (?vkl_view=...). "notified" is its own tab so
        // customers who already received the recovery message stay findable.
        $view  = isset($_GET['vkl_view']) ? sanitize_key((string) $_GET['vkl_view']) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $views = [
            'all'       => [__('All', 'vereskala-login'), array_sum($counts), '', null],
            'active'    => [__('Active', 'vereskala-login'), (int) ($counts['active'] ?? 0), 'active', null],
            'abandoned' => [__('Abandoned — not messaged', 'vereskala-login'), max(0, (int) ($counts['abandoned'] ?? 0) - $notified), 'abandoned', false],
            'notified'  => [__('Abandoned — messaged', 'vereskala-login'), $notified, 'abandoned', true],
            'recovered' => [__('Recovered', 'vereskala-login'), (int) ($counts['recovered'] ?? 0), 'recovered', null],
            'converted' => [__('Converted', 'vereskala-login'), (int) ($counts['converted'] ?? 0), 'converted', null],
        ];

        if (!isset($views[$view])) {
            $view = 'all';
        }

        $filter_product = isset($_GET['vkl_product']) ? absint($_GET['vkl_product']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter_mobile  = isset($_GET['vkl_mobile']) ? sanitize_text_field(wp_unslash((string) $_GET['vkl_mobile'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $vkl_per   = 30;
        $vkl_paged = isset($_GET['vkl_paged']) ? max(1, absint($_GET['vkl_paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $carts   = $this->repository->latest($vkl_per, ($vkl_paged - 1) * $vkl_per, $views[$view][2], $views[$view][3], $filter_product, $filter_mobile);
        $coupons = $this->coupon_codes();
        $chosen  = strtolower($this->config->get_string('cart_coupon_code'));

        if (isset($_GET['vkl_sent'])) {
            $ok = '1' === sanitize_text_field(wp_unslash($_GET['vkl_sent']));
            printf(
                '<div class="notice %s is-dismissible"><p>%s</p></div>',
                $ok ? 'notice-success' : 'notice-error',
                $ok
                    ? esc_html__('Message sent.', 'vereskala-login')
                    : esc_html__('Message could not be sent (check gateway settings and the log).', 'vereskala-login')
            );
        }
        ?>
        <div class="wrap">

            <h1><?php esc_html_e('Cart Tracking', 'vereskala-login'); ?></h1>
            <?php $this->render_section_tabs(''); ?>

            <h2 class="title"><?php esc_html_e('Settings', 'vereskala-login'); ?></h2>

            <form method="post" action="options.php">

                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row"><?php esc_html_e('Enable cart tracking', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden"
                                       name="<?php echo esc_attr($this->config->option_name('cart_tracking_enabled')); ?>"
                                       value="0">
                                <input type="checkbox"
                                       name="<?php echo esc_attr($this->config->option_name('cart_tracking_enabled')); ?>"
                                       value="1"
                                    <?php checked($this->config->get_bool('cart_tracking_enabled', true)); ?>>
                                <?php esc_html_e('Track cart activity and history.', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Abandonment delay (minutes)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="5" max="10080" class="small-text"
                                   name="<?php echo esc_attr($this->config->option_name('cart_abandon_minutes')); ?>"
                                   value="<?php echo esc_attr((string) $this->config->get_int('cart_abandon_minutes', 60)); ?>">
                            <p class="description">
                                <?php esc_html_e('A cart with no activity for this long is considered abandoned.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Enable abandoned-cart SMS', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden"
                                       name="<?php echo esc_attr($this->config->option_name('cart_sms_enabled')); ?>"
                                       value="0">
                                <input type="checkbox"
                                       name="<?php echo esc_attr($this->config->option_name('cart_sms_enabled')); ?>"
                                       value="1"
                                    <?php checked($this->config->get_bool('cart_sms_enabled', false)); ?>>
                                <?php esc_html_e('Send one SMS per abandoned cart (max one per customer per day).', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Enable abandoned-cart WhatsApp', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden"
                                       name="<?php echo esc_attr($this->config->option_name('cart_wa_enabled')); ?>"
                                       value="0">
                                <input type="checkbox"
                                       name="<?php echo esc_attr($this->config->option_name('cart_wa_enabled')); ?>"
                                       value="1"
                                    <?php checked($this->config->get_bool('cart_wa_enabled', false)); ?>>
                                <?php esc_html_e('Send the WhatsApp template to abandoned carts as well (uses the WhatsApp settings from the notifications page).', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('IPPanel pattern code', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" class="regular-text"
                                   name="<?php echo esc_attr($this->config->option_name('cart_pattern_code')); ?>"
                                   value="<?php echo esc_attr($this->config->get_string('cart_pattern_code')); ?>">
                            <p class="description">
                                <?php esc_html_e('Pattern variables: name, coupon. Uses the API key and sender from the login settings.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('WhatsApp template name', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" class="regular-text"
                                   name="<?php echo esc_attr($this->config->option_name('cart_wa_template')); ?>"
                                   value="<?php echo esc_attr($this->config->get_string('cart_wa_template')); ?>">
                            <p class="description">
                                <?php esc_html_e('Approved Meta template. Placeholders: {{1}} = name, {{2}} = coupon.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Statistics window (days)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="7" step="1" style="width:90px"
                                   name="<?php echo esc_attr($this->config->option_name('cart_retention_days')); ?>"
                                   value="<?php echo (int) $this->config->get_int('cart_retention_days', 45); ?>">
                            <p class="description"><?php esc_html_e('Cart rows older than this are removed by the daily housekeeping, so the overview reflects the recent reality instead of an all-time pile.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto personal coupon', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('cart_coupon_auto')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('cart_coupon_auto')); ?>" value="1" <?php checked($this->config->get_bool('cart_coupon_auto', false)); ?>>
                                <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                            </label>
                            &nbsp;
                            <label><?php esc_html_e('Percent', 'vereskala-login'); ?>
                                <input type="number" min="1" max="100" style="width:70px" name="<?php echo esc_attr($this->config->option_name('cart_coupon_amount')); ?>" value="<?php echo (int) $this->config->get_int('cart_coupon_amount', 10); ?>">
                            </label>
                            &nbsp;
                            <label><?php esc_html_e('Valid days', 'vereskala-login'); ?>
                                <input type="number" min="1" style="width:70px" name="<?php echo esc_attr($this->config->option_name('cart_coupon_days')); ?>" value="<?php echo (int) $this->config->get_int('cart_coupon_days', 3); ?>">
                            </label>
                            <p class="description"><?php esc_html_e('Each recovery message carries a unique single-use code for that customer (locked to their e-mail, short expiry = urgency). Overrides the shared coupon below.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Discount coupon', 'vereskala-login'); ?></th>
                        <td>
                            <?php if ([] !== $coupons) : ?>
                                <select name="<?php echo esc_attr($this->config->option_name('cart_coupon_code')); ?>">
                                    <option value=""><?php esc_html_e('— None —', 'vereskala-login'); ?></option>
                                    <?php foreach ($coupons as $code) : ?>
                                        <option value="<?php echo esc_attr($code); ?>" <?php selected($chosen, $code); ?>>
                                            <?php echo esc_html($code); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <input type="text" class="regular-text"
                                       name="<?php echo esc_attr($this->config->option_name('cart_coupon_code')); ?>"
                                       value="<?php echo esc_attr($this->config->get_string('cart_coupon_code')); ?>">
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e('WooCommerce coupon sent as the "coupon" variable in recovery messages.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button(); ?>

            </form>

            <?php if (isset($_GET['vkl_saved'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Cleanup setting saved.', 'vereskala-login'); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['vkl_purged'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf(/* translators: %s: removed rows. */ esc_html__('%s inactive carts deleted.', 'vereskala-login'), esc_html(number_format_i18n(absint($_GET['vkl_purged'])))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                </p></div>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Overview', 'vereskala-login'); ?></h2>

            <?php $vkl_purge_saved = (int) $this->config->get_int('cart_purge_days', 0); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 16px;max-width:640px;margin:10px 0">
                <input type="hidden" name="action" value="vkl_cart_purge">
                <?php $this->security->admin_nonce_field(); ?>
                <strong>🧹 <?php esc_html_e('Inactive-cart cleanup', 'vereskala-login'); ?></strong>
                <p style="display:flex;gap:16px;align-items:end;flex-wrap:wrap;margin:10px 0 6px">
                    <label><?php esc_html_e('Older than (days)', 'vereskala-login'); ?><br>
                        <input type="number" min="1" max="90" name="vkl_purge_days"
                               value="<?php echo max(1, $vkl_purge_saved); ?>" style="width:80px"></label>
                    <label style="display:flex;align-items:center;gap:6px;margin-bottom:6px">
                        <input type="checkbox" name="vkl_purge_auto" value="1" <?php checked($vkl_purge_saved > 0); ?>>
                        <?php esc_html_e('Delete automatically every day', 'vereskala-login'); ?>
                    </label>
                    <span style="display:flex;gap:8px;margin-bottom:2px">
                        <button type="submit" name="vkl_do" value="save" class="button button-primary"><?php esc_html_e('Save setting', 'vereskala-login'); ?></button>
                        <button type="submit" name="vkl_do" value="purge" class="button"
                                onclick="return confirm('<?php echo esc_js(__('Delete all non-active carts older than the given days? This cannot be undone.', 'vereskala-login')); ?>');"><?php esc_html_e('Delete now', 'vereskala-login'); ?></button>
                    </span>
                </p>
                <p class="description">
                    <?php esc_html_e('Save setting: stores the window and the daily on/off — nothing is deleted. Delete now: removes non-active carts (abandoned / recovered / converted) older than the given days, once, without touching the saved setting.', 'vereskala-login'); ?>
                    <?php if ($vkl_purge_saved > 0) : ?>
                        <strong><?php printf(/* translators: %s: days. */ esc_html__('Automatic purge is ON: older than %s days.', 'vereskala-login'), esc_html(number_format_i18n($vkl_purge_saved))); ?></strong>
                    <?php else : ?>
                        <strong><?php esc_html_e('Automatic purge is OFF.', 'vereskala-login'); ?></strong>
                    <?php endif; ?>
                </p>
            </form>

            <p>
                <?php
                printf(
                    /* translators: 1: active count, 2: abandoned count, 3: converted count. */
                    esc_html__('Active: %1$d — Abandoned: %2$d — Converted: %3$d', 'vereskala-login'),
                    (int) ($counts[VKL_Cart_Repository::STATUS_ACTIVE] ?? 0),
                    (int) ($counts[VKL_Cart_Repository::STATUS_ABANDONED] ?? 0),
                    (int) ($counts[VKL_Cart_Repository::STATUS_CONVERTED] ?? 0)
                );
                ?>
            </p>

            <?php
            $vkl_conv_base = (int) ($counts['abandoned'] ?? 0) + (int) ($counts['converted'] ?? 0);
            $vkl_conv_rate = $vkl_conv_base > 0 ? (int) round((int) ($counts['converted'] ?? 0) * 100 / $vkl_conv_base) : 0;
            $vkl_cards     = [
                ['🛒', __('Active carts', 'vereskala-login'), number_format_i18n((int) ($counts['active'] ?? 0)), '#157f3a'],
                ['⏳', __('Abandoned — not messaged', 'vereskala-login'), number_format_i18n(max(0, (int) ($counts['abandoned'] ?? 0) - $notified)), '#9a6a10'],
                ['📨', __('Abandoned — messaged', 'vereskala-login'), number_format_i18n($notified), '#3b82c4'],
                ['🏁', __('Conversion of abandoned carts', 'vereskala-login'), number_format_i18n($vkl_conv_rate) . '٪', '#7a4ab0'],
            ];
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0">
                <?php foreach ($vkl_cards as [$card_icon, $card_label, $card_value, $card_color]) : ?>
                    <div style="background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:12px 16px">
                        <div style="font-size:12px;color:#666"><?php echo esc_html($card_icon . ' ' . $card_label); ?></div>
                        <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr($card_color); ?>"><?php echo esc_html($card_value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: retention days. */
                    esc_html__('Numbers cover the last %d days — empty carts are never stored, bots are filtered out (user-agent + cookie + JavaScript proof), and unmessageable guest rows expire after 2 days.', 'vereskala-login'),
                    (int) $this->config->get_int('cart_retention_days', 45)
                );
                ?>
            </p>

            <form method="get" style="margin:10px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input type="hidden" name="page" value="vkl-cart">
                <input type="hidden" name="vkl_view" value="<?php echo esc_attr($view); ?>">
                <select name="vkl_product">
                    <option value="0"><?php esc_html_e('All products', 'vereskala-login'); ?></option>
                    <?php foreach ($this->repository->abandoned_products() as $group) : ?>
                        <option value="<?php echo (int) $group['product_id']; ?>" <?php selected($filter_product, (int) $group['product_id']); ?>>
                            <?php echo esc_html($group['name'] . ' (' . $group['carts'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="search" name="vkl_mobile" value="<?php echo esc_attr($filter_mobile); ?>"
                       placeholder="<?php esc_attr_e('Search by mobile…', 'vereskala-login'); ?>" dir="ltr">
                <button class="button"><?php esc_html_e('Apply', 'vereskala-login'); ?></button>
            </form>
            <ul class="subsubsub" style="margin-bottom:6px">
                <?php
                $links = [];

                foreach ($views as $key => [$label, $count]) {
                    $url     = esc_url(add_query_arg(['page' => 'vkl-cart', 'vkl_view' => $key], admin_url('admin.php')));
                    $links[] = '<li><a href="' . $url . '"' . ($key === $view ? ' class="current"' : '') . '>'
                        . esc_html($label) . ' <span class="count">(' . (int) $count . ')</span></a></li>';
                }

                echo wp_kses_post(implode(' | ', $links));
                ?>
            </ul>
            <div style="clear:both"></div>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Mobile', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Items', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Total', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Last activity (UTC)', 'vereskala-login'); ?></th>
                        <th><?php esc_html_e('Send message', 'vereskala-login'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$carts) : ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No carts tracked yet.', 'vereskala-login'); ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($carts as $cart) : ?>
                    <tr>
                        <td>
                            <?php
                            $user_id = (int) $cart['user_id'];

                            if ($user_id > 0) {
                                $user = get_userdata($user_id);
                                echo esc_html($user ? $user->display_name : ('#' . $user_id));
                            } else {
                                esc_html_e('Guest', 'vereskala-login');
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($this->security->mask_mobile((string) $cart['mobile'])); ?></td>
                        <td><?php $this->render_items($cart); ?></td>
                        <td>
                            <?php
                            echo wp_kses_post(
                                function_exists('wc_price')
                                    ? wc_price((float) $cart['total'])
                                    : number_format_i18n((float) $cart['total'], 2)
                            );
                            ?>
                        </td>
                        <td>
                            <?php echo esc_html((string) $cart['status']); ?>
                            <?php if (!empty($cart['notified_at'])) : ?>
                                <span style="display:inline-block;background:#eaf9ef;color:#157f3a;border-radius:10px;padding:1px 8px;font-size:11px"
                                      title="<?php echo esc_attr((string) $cart['notified_at']); ?>">
                                    <?php esc_html_e('Messaged', 'vereskala-login'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html((string) $cart['updated_at']); ?></td>
                        <td>
                            <?php if ('' !== (string) $cart['mobile']) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php $this->security->admin_nonce_field(); ?>
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SEND_SMS); ?>">
                                    <input type="hidden" name="cart_id" value="<?php echo esc_attr((string) (int) $cart['id']); ?>">
                                    <button type="submit" class="button button-small"><?php esc_html_e('SMS', 'vereskala-login'); ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                    <?php $this->security->admin_nonce_field(); ?>
                                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SEND_WA); ?>">
                                    <input type="hidden" name="cart_id" value="<?php echo esc_attr((string) (int) $cart['id']); ?>">
                                    <button type="submit" class="button button-small"><?php esc_html_e('WhatsApp', 'vereskala-login'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $vkl_total = 0 === $filter_product && '' === $filter_mobile ? (int) $views[$view][1] : 0;
            $vkl_pages = $vkl_total > 0 ? max(1, (int) ceil($vkl_total / $vkl_per)) : ($vkl_per === count($carts) ? $vkl_paged + 1 : $vkl_paged);
            $vkl_base  = array_filter([
                'page'        => self::PAGE_SLUG,
                'vkl_view'    => $view,
                'vkl_product' => $filter_product ?: null,
                'vkl_mobile'  => '' !== $filter_mobile ? $filter_mobile : null,
            ]);
            ?>
            <?php if ($vkl_pages > 1) : ?>
                <div style="display:flex;gap:10px;align-items:center;justify-content:center;margin:14px 0">
                    <?php if ($vkl_paged > 1) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg($vkl_base + ['vkl_paged' => $vkl_paged - 1], admin_url('admin.php'))); ?>">&rarr; <?php esc_html_e('Previous page', 'vereskala-login'); ?></a>
                    <?php endif; ?>
                    <span style="font-weight:700;color:#157f3a">
                        <?php
                        printf(
                            /* translators: 1: current page, 2: total pages. */
                            esc_html__('Page %1$s of %2$s', 'vereskala-login'),
                            esc_html(number_format_i18n($vkl_paged)),
                            esc_html($vkl_total > 0 ? number_format_i18n($vkl_pages) : '…')
                        );

                        if ($vkl_total > 0) {
                            printf(' · %s', sprintf(/* translators: %s: total rows. */ esc_html__('%s carts in this view', 'vereskala-login'), esc_html(number_format_i18n($vkl_total))));
                        }
                        ?>
                    </span>
                    <?php if ($vkl_paged < $vkl_pages) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg($vkl_base + ['vkl_paged' => $vkl_paged + 1], admin_url('admin.php'))); ?>"><?php esc_html_e('Next page', 'vereskala-login'); ?> &larr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Manual purge of inactive carts older than N days (admin-post).
     */
    public function handle_purge(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $days   = isset($_POST['vkl_purge_days']) ? max(1, absint($_POST['vkl_purge_days'])) : 1;
        $action = isset($_POST['vkl_do']) ? sanitize_key($_POST['vkl_do']) : 'save';

        if ('purge' === $action) {
            $removed = $this->repository->purge_inactive($days);

            wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'vkl_purged' => $removed], admin_url('admin.php')));
            exit;
        }

        // Save setting: window + daily on/off, stored directly (0 = off).
        update_option(
            $this->config->option_name('cart_purge_days'),
            empty($_POST['vkl_purge_auto']) ? 0 : $days
        );
        // phpcs:enable

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'vkl_saved' => 1], admin_url('admin.php')));
        exit;
    }
}
