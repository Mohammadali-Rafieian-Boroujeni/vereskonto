<?php
/**
 * Notify admin — Bale bot settings and the one-click webhook setup.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Admin
 */
final class VKL_Notify_Admin
{
    private const GROUP = 'vkl_notify_settings';

    private VKL_Config $config;

    private VKL_Security $security;

    public function __construct(VKL_Config $config, VKL_Security $security)
    {
        $this->config   = $config;
        $this->security = $security;
    }

    public function register(): void
    {
        add_action('admin_post_vkl_build_icon', [$this, 'handle_build_icon']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_vkl_bale_set_webhook', [$this, 'handle_set_webhook']);
    }

    public function settings(): void
    {
        $text = static fn(mixed $v): string => sanitize_text_field((string) $v);
        $bool = static fn(mixed $v): string => '1' === (string) $v ? '1' : '0';

        register_setting(self::GROUP, $this->config->option_name('bale_enabled'), ['type' => 'string', 'sanitize_callback' => $bool]);
        register_setting(self::GROUP, $this->config->option_name('bale_bot_token'), ['type' => 'string', 'sanitize_callback' => $text]);
        register_setting(self::GROUP, $this->config->option_name('bale_bot_username'), ['type' => 'string', 'sanitize_callback' => $text]);
        register_setting(self::GROUP, $this->config->option_name('notify_invoice_enabled'), ['type' => 'string', 'sanitize_callback' => $bool]);
        register_setting(self::GROUP, $this->config->option_name('notify_wallet_enabled'), ['type' => 'string', 'sanitize_callback' => $bool]);
        register_setting(self::GROUP, $this->config->option_name('pwa_enabled'), ['type' => 'string', 'sanitize_callback' => $bool]);
        register_setting(self::GROUP, $this->config->option_name('pwa_name'), ['type' => 'string', 'sanitize_callback' => $text]);
        register_setting(self::GROUP, $this->config->option_name('pwa_short_name'), ['type' => 'string', 'sanitize_callback' => $text]);
        register_setting(self::GROUP, $this->config->option_name('pwa_icon'), ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting(self::GROUP, $this->config->option_name('pwa_theme'), ['type' => 'string', 'sanitize_callback' => 'sanitize_hex_color']);
        register_setting(self::GROUP, $this->config->option_name('push_enabled'), ['type' => 'string', 'sanitize_callback' => $bool]);
    }

    /**
     * Render the Bale panel as a tab body (no page wrap — the settings
     * page provides the wrapper and tab navigation).
     */
    /**
     * Say plainly whether the chosen icon will actually work.
     *
     * A size mismatch is silently fatal: Chrome compares the real image
     * against the manifest and simply never offers installation, with no
     * error shown anywhere.
     */
    /**
     * Generate a padded copy of the chosen icon and switch to it.
     *
     * Launchers draw their own circular frame and no manifest setting
     * prevents it, so the artwork is centred on a larger transparent
     * canvas: the frame then lands on empty space instead of the logo.
     */
    public function handle_build_icon(): void
    {
        $this->security->verify_admin_request();

        $source = $this->config->get_string('pwa_icon');
        $result = VKL_Notify_Icon::build($source);
        $note   = 'fail';

        if ('' !== $result) {
            update_option($this->config->option_name('pwa_icon'), $result);
            $note = 'ok';
        }

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'vereskala-login', 'tab' => 'notify', 'vkl_icon' => $note],
                admin_url('admin.php')
            )
        );

        exit;
    }

    private function icon_health(): void
    {
        $url = $this->config->get_string('pwa_icon');

        if ('' === $url) {
            return;
        }

        $id   = attachment_url_to_postid($url);
        $meta = $id > 0 ? wp_get_attachment_metadata($id) : null;
        $w    = is_array($meta) ? (int) ($meta['width'] ?? 0) : 0;
        $h    = is_array($meta) ? (int) ($meta['height'] ?? 0) : 0;

        if ($w <= 0) {
            printf(
                '<p class="description" style="color:#7a5b12">%s</p>',
                esc_html__('This address is not in the media library, so the size cannot be checked. Upload the icon here to be certain browsers accept it.', 'vereskala-login')
            );

            return;
        }

        if ($w !== $h) {
            printf(
                '<p class="description" style="color:#b32d2e">%s</p>',
                esc_html(sprintf(
                    /* translators: 1: width, 2: height. */
                    __('This image is %1$d×%2$d. It has to be square, or the icon will look stretched.', 'vereskala-login'),
                    $w,
                    $h
                ))
            );

            return;
        }

        if ($w < 192) {
            printf(
                '<p class="description" style="color:#b32d2e">%s</p>',
                esc_html(sprintf(
                    /* translators: %d: icon width in pixels. */
                    __('This image is only %d px. Browsers need at least 192 px before they will offer to install the app.', 'vereskala-login'),
                    $w
                ))
            );

            return;
        }

        printf(
            '<p class="description" style="color:#157f3a">%s</p>',
            esc_html(sprintf(
                /* translators: %d: icon width in pixels. */
                __('Checked: %d px square — browsers will accept this.', 'vereskala-login'),
                $w
            ))
        );
    }

    public function render_inner(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        // Ensure a webhook secret exists.
        if ('' === $this->config->get_string('bale_webhook_secret')) {
            update_option($this->config->option_name('bale_webhook_secret'), 'wh' . wp_generate_password(20, false));
        }

        $secret      = $this->config->get_string('bale_webhook_secret');
        $webhook_url = rest_url('vereskonto/v1/bale/webhook/' . $secret);
        ?>
        <div>
            <?php if (isset($_GET['vkl_wh'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <?php $ok = '1' === $_GET['vkl_wh']; // phpcs:ignore ?>
                <div class="notice notice-<?php echo $ok ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo $ok ? esc_html__('Webhook registered with Bale.', 'vereskala-login') : esc_html__('Could not register the webhook — check the bot token.', 'vereskala-login'); ?></p>
                </div>
            <?php endif; ?>

            <p class="description" style="max-width:820px">
                <?php esc_html_e('Bale lets you send invoices and order updates to customers for free. Create a bot in Bale via @BotFather, paste its token below, then register the webhook so customers can connect by tapping “Start”.', 'vereskala-login'); ?>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Bale notifications', 'vereskala-login'); ?></th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr($this->config->option_name('bale_enabled')); ?>" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr($this->config->option_name('bale_enabled')); ?>" value="1" <?php checked($this->config->get_bool('bale_enabled', false)); ?>>
                            <?php esc_html_e('On', 'vereskala-login'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bot token', 'vereskala-login'); ?></th>
                        <td><input type="text" dir="ltr" class="regular-text" name="<?php echo esc_attr($this->config->option_name('bale_bot_token')); ?>" value="<?php echo esc_attr($this->config->get_string('bale_bot_token')); ?>" placeholder="123456:ABC…"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bot username (without @)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" dir="ltr" class="regular-text" name="<?php echo esc_attr($this->config->option_name('bale_bot_username')); ?>" value="<?php echo esc_attr($this->config->get_string('bale_bot_username')); ?>" placeholder="my_shop_bot">
                            <p class="description"><?php esc_html_e('Used to build each customer’s connect link (ble.ir/your_bot?start=…).', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>

            <hr style="margin:26px 0">

            <h3>🔔 <?php esc_html_e('Which events notify the customer', 'vereskala-login'); ?></h3>
            <p class="description" style="max-width:820px">
                <?php esc_html_e('These apply to every channel below — Bale, WhatsApp, SMS and browser notifications. Switching one off stops that message everywhere at once.', 'vereskala-login'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Order paid — send the invoice', 'vereskala-login'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="<?php echo esc_attr($this->config->option_name('notify_invoice_enabled')); ?>" value="0">
                            <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('notify_invoice_enabled')); ?>" value="1" <?php checked($this->config->get_bool('notify_invoice_enabled', true)); ?>>
                            <?php esc_html_e('On', 'vereskala-login'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Wallet or festival credit added', 'vereskala-login'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="<?php echo esc_attr($this->config->option_name('notify_wallet_enabled')); ?>" value="0">
                            <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('notify_wallet_enabled')); ?>" value="1" <?php checked($this->config->get_bool('notify_wallet_enabled', true)); ?>>
                            <?php esc_html_e('On', 'vereskala-login'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Covers festival credit too — the customer is told as soon as the credit lands, whichever channel they can be reached on.', 'vereskala-login'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="margin:26px 0">

            <h3>📱 <?php esc_html_e('Installable app and browser notifications', 'vereskala-login'); ?></h3>
            <p class="description" style="max-width:820px">
                <?php esc_html_e('Lets customers add the shop to their home screen and receive notifications about orders, wallet and instalments — even when the site is closed. Requires HTTPS. On iPhone, notifications only work after the customer has installed the app from Safari’s share menu; Android and desktop Chrome show an install prompt automatically.', 'vereskala-login'); ?>
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Installable app', 'vereskala-login'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="<?php echo esc_attr($this->config->option_name('pwa_enabled')); ?>" value="0">
                            <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('pwa_enabled')); ?>" value="1" <?php checked($this->config->get_bool('pwa_enabled', false)); ?>>
                            <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                        </label>
                        <?php if (!is_ssl()) : ?>
                            <p class="description" style="color:#b32d2e"><?php esc_html_e('This site is not served over HTTPS, so browsers will ignore it.', 'vereskala-login'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('App name', 'vereskala-login'); ?></th>
                    <td>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($this->config->option_name('pwa_name')); ?>" value="<?php echo esc_attr($this->config->get_string('pwa_name')); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                        <p class="description"><?php esc_html_e('Leave empty to use the site title.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Short name', 'vereskala-login'); ?></th>
                    <td>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($this->config->option_name('pwa_short_name')); ?>" value="<?php echo esc_attr($this->config->get_string('pwa_short_name')); ?>">
                        <p class="description"><?php esc_html_e('Shown under the icon on the home screen — keep it to about 12 characters.', 'vereskala-login'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('App icon', 'vereskala-login'); ?></th>
                    <td>
                        <input type="url" dir="ltr" class="regular-text" name="<?php echo esc_attr($this->config->option_name('pwa_icon')); ?>" value="<?php echo esc_attr($this->config->get_string('pwa_icon')); ?>">
                        <p class="description"><?php esc_html_e('URL of a square PNG, 512×512. Upload it to the media library and paste the address here.', 'vereskala-login'); ?></p>
                        <?php
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        $vkl_icon_note = isset($_GET['vkl_icon']) ? sanitize_key(wp_unslash($_GET['vkl_icon'])) : '';

                        if ('ok' === $vkl_icon_note) {
                            printf(
                                '<p class="description" style="color:#157f3a">%s</p>',
                                esc_html__('A padded copy was created and is now in use. Reinstall the app on your phone to see it.', 'vereskala-login')
                            );
                        } elseif ('fail' === $vkl_icon_note) {
                            printf(
                                '<p class="description" style="color:#b32d2e">%s</p>',
                                esc_html__('The copy could not be created. Make sure the icon was uploaded to this site’s media library.', 'vereskala-login')
                            );
                        }
                        ?>
                        <?php $this->icon_health(); ?>
                        <?php if ('' !== $this->config->get_string('pwa_icon') && VKL_Notify_Icon::can_build()) : ?>
                            <p style="margin:8px 0 0">
                                <?php
                                // A submit button bound by form= rather than a
                                // link: verify_admin_request() reads its nonce
                                // from $_POST, and a form cannot be nested
                                // inside the settings form.
                                ?>
                                <button type="submit" class="button" form="vkl-build-icon">
                                    🪄 <?php esc_html_e('Make a safe version of this icon', 'vereskala-login'); ?>
                                </button>
                            </p>
                            <p class="description">
                                <?php esc_html_e('Phones draw their own round frame around the home-screen icon, which clips artwork that reaches the edges. This creates a 512 px copy with the logo centred and space around it, so the whole design stays visible whatever shape the phone uses. The new file replaces the address above; the original is left untouched.', 'vereskala-login'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Theme colour', 'vereskala-login'); ?></th>
                    <td><input type="text" dir="ltr" style="width:120px" name="<?php echo esc_attr($this->config->option_name('pwa_theme')); ?>" value="<?php echo esc_attr($this->config->get_string('pwa_theme') ?: '#42D968'); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Browser notifications', 'vereskala-login'); ?></th>
                    <td>
                        <label>
                            <input type="hidden" name="<?php echo esc_attr($this->config->option_name('push_enabled')); ?>" value="0">
                            <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('push_enabled')); ?>" value="1" <?php checked($this->config->get_bool('push_enabled', false)); ?>>
                            <?php esc_html_e('Enabled', 'vereskala-login'); ?>
                        </label>
                        <p class="description">
                            <?php
                            if (VKL_Notify_Vapid::has_keys()) {
                                $vkl_devices = 0;

                                try {
                                    $vkl_devices = vkl('notify.push')->device_count();
                                } catch (Throwable $e) { // phpcs:ignore
                                    $vkl_devices = 0;
                                }

                                printf(
                                    /* translators: %s: number of subscribed devices. */
                                    esc_html__('Signing keys are in place. Devices currently subscribed: %s', 'vereskala-login'),
                                    esc_html(number_format_i18n($vkl_devices))
                                );
                            } else {
                                esc_html_e('Signing keys will be created automatically the first time this is switched on.', 'vereskala-login');
                            }
                            ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e('Customers are asked once, about half a minute after arriving, and never again if they decline. Notifications go out when an order changes status, when wallet credit is added, and when an instalment is received.', 'vereskala-login'); ?>
                        </p>
                    </td>
                </tr>
            </table>

                <?php submit_button(); ?>
            </form>

            <?php // Target of the icon button above — kept outside the settings form. ?>
            <form method="post" id="vkl-build-icon" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_build_icon">
                <?php $this->security->admin_nonce_field(); ?>
            </form>

            <h2 class="title"><?php esc_html_e('Webhook', 'vereskala-login'); ?></h2>
            <p class="description" style="max-width:820px"><?php esc_html_e('This is the address Bale calls when a customer taps Start. Save your token above first, then register it.', 'vereskala-login'); ?></p>
            <p><code dir="ltr" style="user-select:all"><?php echo esc_html($webhook_url); ?></code></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_bale_set_webhook">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Register webhook with Bale', 'vereskala-login'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Ask Bale to send updates to our webhook URL.
     */
    public function handle_set_webhook(): void
    {
        $this->security->verify_admin_request();

        $token  = $this->config->get_string('bale_bot_token');
        $secret = $this->config->get_string('bale_webhook_secret');
        $ok     = false;

        if ('' !== $token && '' !== $secret) {
            $url      = rest_url('vereskonto/v1/bale/webhook/' . $secret);
            $response = wp_remote_post('https://tapi.bale.ai/bot' . $token . '/setWebhook', [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => (string) wp_json_encode(['url' => $url]),
            ]);

            $ok = !is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response);
        }

        wp_safe_redirect(add_query_arg(['page' => 'vereskala-login', 'tab' => 'bale', 'vkl_wh' => $ok ? '1' : '0'], admin_url('admin.php')));
        exit;
    }
}
