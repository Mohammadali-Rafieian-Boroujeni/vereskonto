<?php
/**
 * API admin page.
 *
 * Turns the mobile API on/off, sets the token lifetime, shows how many
 * app sessions are active, offers a "revoke everything" button, and lists
 * the endpoints so the app developer has a reference in one place.
 *
 * @package Vereskonto\Modules\Api
 * @since   1.32.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Api_Admin
 */
final class VKL_Api_Admin
{
    private const SETTINGS_GROUP = 'vkl_api_settings';

    private VKL_Config $config;

    private VKL_Security $security;

    private VKL_Api_Tokens $tokens;

    /**
     * Constructor.
     */
    public function __construct(VKL_Config $config, VKL_Security $security, VKL_Api_Tokens $tokens)
    {
        $this->config   = $config;
        $this->security = $security;
        $this->tokens   = $tokens;
    }

    /**
     * Hooks.
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vkl_api_revoke_all', [$this, 'handle_revoke_all']);
    }


    /**
     * Settings.
     */
    public function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('api_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('api_token_ttl_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);
    }

    /**
     * Revoke every token (admin-post).
     */
    public function handle_revoke_all(): void
    {
        $this->security->verify_admin_request();

        global $wpdb;

        $removed = (int) $wpdb->query("DELETE FROM {$wpdb->prefix}vkl_api_tokens"); // phpcs:ignore

        wp_safe_redirect(admin_url('admin.php?page=vereskala-login&tab=api&vkl_revoked=' . $removed));
        exit;
    }

    /**
     * Render.
     */
    public function render_inner(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $stats   = $this->tokens->stats();
        $enabled = $this->config->get_bool('api_enabled', false);
        $base    = esc_url_raw(rest_url(VKL_Api_Rest::NAMESPACE));
        ?>
        <div>

            <?php if (isset($_GET['vkl_revoked'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf(/* translators: %s: count. */ esc_html__('%s app sessions signed out.', 'vereskala-login'), esc_html(number_format_i18n(absint($_GET['vkl_revoked'])))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                </p></div>
            <?php endif; ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:6px 0 16px">
                <div style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 18px">
                    <div style="font-size:12px;color:#666">📶 <?php esc_html_e('API status', 'vereskala-login'); ?></div>
                    <div style="font-size:20px;font-weight:800;color:<?php echo $enabled ? '#157f3a' : '#d92b31'; ?>">
                        <?php echo $enabled ? esc_html__('On', 'vereskala-login') : esc_html__('Off', 'vereskala-login'); ?>
                    </div>
                </div>
                <div style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 18px">
                    <div style="font-size:12px;color:#666">📱 <?php esc_html_e('Active app sessions', 'vereskala-login'); ?></div>
                    <div style="font-size:20px;font-weight:800;color:#157f3a"><?php echo esc_html(number_format_i18n($stats['tokens'])); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:12px 18px">
                    <div style="font-size:12px;color:#666">👤 <?php esc_html_e('Signed-in customers', 'vereskala-login'); ?></div>
                    <div style="font-size:20px;font-weight:800;color:#157f3a"><?php echo esc_html(number_format_i18n($stats['users'])); ?></div>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable the mobile API', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('api_enabled')); ?>"
                                       value="1" <?php checked($enabled); ?>>
                                <?php esc_html_e('Expose the versioned REST endpoints for the Android/mobile app.', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Token lifetime (days)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="1" max="365" style="width:90px"
                                   name="<?php echo esc_attr($this->config->option_name('api_token_ttl_days')); ?>"
                                   value="<?php echo (int) $this->config->get_int('api_token_ttl_days', 90); ?>">
                            <p class="description"><?php esc_html_e('How long an app stays signed in after verifying an OTP. Expired tokens are cleaned up automatically.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2 class="title">🔐 <?php esc_html_e('Sessions', 'vereskala-login'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('<?php echo esc_js(__('Sign out every app on every device? Users will need to log in again.', 'vereskala-login')); ?>');">
                <input type="hidden" name="action" value="vkl_api_revoke_all">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Sign out all app sessions', 'vereskala-login'), 'delete', 'submit', false); ?>
            </form>

            <h2 class="title">📚 <?php esc_html_e('Endpoint reference', 'vereskala-login'); ?></h2>
            <p class="description"><?php esc_html_e('Base URL — send the token as an Authorization: Bearer header on authenticated calls.', 'vereskala-login'); ?></p>
            <p><code dir="ltr"><?php echo esc_html($base); ?></code></p>
            <table class="widefat striped" style="max-width:920px">
                <thead><tr>
                    <th><?php esc_html_e('Method', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Path', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Auth', 'vereskala-login'); ?></th>
                    <th><?php esc_html_e('Purpose', 'vereskala-login'); ?></th>
                </tr></thead>
                <tbody>
                    <?php
                    $rows = [
                        ['GET',  '/ping',             __('No', 'vereskala-login'),  __('Liveness & version', 'vereskala-login')],
                        ['POST', '/auth/request-otp', __('No', 'vereskala-login'),  __('Send login code by SMS', 'vereskala-login')],
                        ['POST', '/auth/verify-otp',  __('No', 'vereskala-login'),  __('Verify code → returns Bearer token', 'vereskala-login')],
                        ['POST', '/auth/logout',      __('Yes', 'vereskala-login'), __('Revoke the current token', 'vereskala-login')],
                        ['GET',  '/me',               __('Yes', 'vereskala-login'), __('Own profile', 'vereskala-login')],
                        ['GET',  '/wallet',           __('Yes', 'vereskala-login'), __('Wallet & festival credit', 'vereskala-login')],
                        ['GET',  '/loyalty',          __('Yes', 'vereskala-login'), __('Club points', 'vereskala-login')],
                        ['GET',  '/orders',           __('Yes', 'vereskala-login'), __('Own orders (paged)', 'vereskala-login')],
                    ];

                    foreach ($rows as $r) :
                        ?>
                        <tr>
                            <td><code dir="ltr"><?php echo esc_html($r[0]); ?></code></td>
                            <td><code dir="ltr"><?php echo esc_html($r[1]); ?></code></td>
                            <td><?php echo esc_html($r[2]); ?></td>
                            <td><?php echo esc_html($r[3]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
