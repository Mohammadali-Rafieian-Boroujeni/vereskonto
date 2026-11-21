<?php

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'vkl_admin_menu');

function vkl_admin_menu()
{
    add_menu_page(
        'Vereskonto',
        'Vereskonto',
        'manage_options',
        'vereskala-login',
        'vkl_settings_page',
        'dashicons-smartphone',
        58
    );
}

add_action('admin_init', 'vkl_register_settings');

function vkl_register_settings()
{
    register_setting('vkl_settings', 'vkl_api_key');
    register_setting('vkl_settings', 'vkl_pattern_code');
    register_setting('vkl_settings', 'vkl_sender');
    register_setting('vkl_settings', 'vkl_meli_username', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_settings', 'vkl_meli_password', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_settings', 'vkl_sms_bind_ip', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_settings', 'vkl_sms_proxy_host', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_settings', 'vkl_sms_proxy_port', ['sanitize_callback' => 'absint']);
    register_setting('vkl_settings', 'vkl_sms_proxy_type', [
        'sanitize_callback' => static fn($v): string => in_array((string) $v, ['http', 'socks5'], true) ? (string) $v : 'http',
    ]);
    register_setting('vkl_settings', 'vkl_sms_proxy_user', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_settings', 'vkl_sms_proxy_pass', ['sanitize_callback' => 'sanitize_text_field']);

    // Sign-in page customisation: its own group, saved from one form.
    $clamp = static fn(int $min, int $max, int $def): callable => static function ($v) use ($min, $max, $def): int {
        $v = (int) $v;

        return $v < $min || $v > $max ? $def : $v;
    };

    register_setting('vkl_login_settings', 'vkl_login_logo', ['sanitize_callback' => 'absint']);
    register_setting('vkl_login_settings', 'vkl_otp_length', ['sanitize_callback' => $clamp(4, 8, 5)]);
    register_setting('vkl_login_settings', 'vkl_otp_expire', ['sanitize_callback' => $clamp(30, 900, 120)]);
    register_setting('vkl_login_settings', 'vkl_otp_max_requests', ['sanitize_callback' => $clamp(1, 30, 5)]);
    register_setting('vkl_login_settings', 'vkl_otp_max_requests_ip', ['sanitize_callback' => $clamp(0, 500, 30)]);
    register_setting('vkl_login_settings', 'vkl_otp_rate_minutes', ['sanitize_callback' => $clamp(1, 240, 15)]);
    register_setting('vkl_login_settings', 'vkl_otp_max_fail', ['sanitize_callback' => $clamp(1, 20, 5)]);
    register_setting('vkl_login_settings', 'vkl_login_accent', [
        'sanitize_callback' => static fn($v): string => preg_match('/^#[0-9a-fA-F]{6}$/', trim((string) $v)) ? strtolower(trim((string) $v)) : '',
    ]);

    // Mobile <-> national-ID matching service.
    register_setting('vkl_identity_settings', 'vkl_id_mode', [
        'sanitize_callback' => static fn($v): string => in_array((string) $v, ['record', 'require'], true) ? (string) $v : 'off',
    ]);
    register_setting('vkl_identity_settings', 'vkl_id_url', ['sanitize_callback' => 'esc_url_raw']);
    register_setting('vkl_identity_settings', 'vkl_id_method', [
        'sanitize_callback' => static fn($v): string => 'GET' === strtoupper((string) $v) ? 'GET' : 'POST',
    ]);
    register_setting('vkl_identity_settings', 'vkl_id_auth_header', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_identity_settings', 'vkl_id_token', [
        // Write-only: blank keeps the stored secret, the tick removes it.
        'sanitize_callback' => static function ($v): string {
            if (!empty($_POST['vkl_id_token_clear'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return '';
            }

            $new = trim(sanitize_text_field((string) $v));

            return '' !== $new ? $new : (string) get_option('vkl_id_token', '');
        },
    ]);
    register_setting('vkl_identity_settings', 'vkl_id_body', ['sanitize_callback' => 'sanitize_textarea_field']);
    register_setting('vkl_identity_settings', 'vkl_id_mobile_format', [
        'sanitize_callback' => static fn($v): string => in_array((string) $v, ['intl', 'bare'], true) ? (string) $v : 'local',
    ]);
    register_setting('vkl_identity_settings', 'vkl_id_path', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_identity_settings', 'vkl_id_ok_value', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('vkl_identity_settings', 'vkl_id_timeout', [
        'sanitize_callback' => static fn($v): int => max(3, min(30, (int) $v)),
    ]);

    foreach (['subtitle', 'send', 'hint', 'verify', 'footer'] as $text_key) {
        register_setting('vkl_login_settings', 'vkl_login_' . $text_key, ['sanitize_callback' => 'sanitize_text_field']);
    }

    // Customer area: the dashboard and the mobile tab bar share one array
    // option; each of the two tabs saves only its own part.
    register_setting(VKL_Dash_Options::GROUP, VKL_Dash_Options::OPTION, [
        'type'              => 'array',
        'sanitize_callback' => [VKL_Dash_Options::class, 'sanitize'],
        'default'           => [],
    ]);
}

function vkl_settings_page()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- tab switch only.
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'sms';
    // phpcs:enable

    if (!in_array($tab, ['sms', 'login', 'identity', 'bale', 'api', 'dash', 'tabbar'], true)) {
        $tab = 'sms';
    }

    $base = admin_url('admin.php?page=vereskala-login');

    // Vertical, grouped navigation instead of a row of horizontal tabs.
    // In-page sections keep their old ?tab= URLs (bookmarks keep working);
    // related settings that live on their own screen are linked from here
    // so every setting is reachable from one place.
    $vkl_nav = [
        __('Sign-in & identity', 'vereskala-login') => [
            ['tab' => 'login', 'label' => __('Sign-in page', 'vereskala-login')],
            ['tab' => 'identity', 'label' => __('National ID matching', 'vereskala-login')],
        ],
        'ناحیهٔ مشتری' => [
            ['tab' => 'dash', 'label' => 'پیشخوان مشتری'],
            ['tab' => 'tabbar', 'label' => 'نوار موبایل'],
        ],
        __('Notifications', 'vereskala-login') => [
            ['tab' => 'sms', 'label' => __('SMS settings', 'vereskala-login')],
            ['tab' => 'bale', 'label' => __('Notifications & Bale', 'vereskala-login')],
            ['page' => 'vkl-messages', 'label' => __('All messages', 'vereskala-login')],
            ['page' => 'vkl-notifications', 'label' => __('SMS Notifications', 'vereskala-login')],
        ],
        __('Developer', 'vereskala-login') => [
            ['tab' => 'api', 'label' => __('Mobile API', 'vereskala-login')],
        ],
        __('More settings', 'vereskala-login') => [
            ['page' => 'vkl-smart', 'label' => __('Smart shop', 'vereskala-login')],
            ['page' => 'vkl-status', 'label' => __('System status', 'vereskala-login')],
        ],
    ];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('General settings', 'vereskala-login'); ?></h1>

        <style>
            .vkl-settings{display:grid;grid-template-columns:210px minmax(0,1fr);gap:24px;align-items:start;margin-top:16px}
            .vkl-settings-nav{position:sticky;top:120px;background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:8px}
            .vkl-settings-nav h2{font-size:11px;font-weight:600;color:#8c9399;margin:12px 10px 4px;padding:0;border:0}
            .vkl-settings-nav h2:first-child{margin-top:4px}
            .vkl-settings-nav a{display:flex;justify-content:space-between;gap:6px;padding:7px 10px;border-radius:7px;color:#1f2933;text-decoration:none;font-size:13px}
            .vkl-settings-nav a:hover{background:#f1faf4;color:#157f3a}
            .vkl-settings-nav a.is-active{background:#eaf9ef;color:#157f3a;font-weight:600}
            .vkl-settings-nav a .ext{color:#a0a7ad;font-size:11px}
            .vkl-settings-nav a:focus-visible{outline:2px solid #157f3a;outline-offset:1px}
            @media (max-width:960px){.vkl-settings{grid-template-columns:1fr}.vkl-settings-nav{position:static}}
        </style>

        <div class="vkl-settings">
            <nav class="vkl-settings-nav" aria-label="<?php esc_attr_e('Settings', 'vereskala-login'); ?>">
                <?php foreach ($vkl_nav as $vkl_group => $vkl_links) : ?>
                    <h2><?php echo esc_html($vkl_group); ?></h2>
                    <?php foreach ($vkl_links as $vkl_link) : ?>
                        <?php if (isset($vkl_link['tab'])) : ?>
                            <a href="<?php echo esc_url($base . '&tab=' . $vkl_link['tab']); ?>"
                               class="<?php echo $vkl_link['tab'] === $tab ? 'is-active' : ''; ?>"
                               <?php echo $vkl_link['tab'] === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html($vkl_link['label']); ?></a>
                        <?php else : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $vkl_link['page'])); ?>"><?php echo esc_html($vkl_link['label']); ?> <span class="ext" aria-hidden="true">↗</span></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

        <div style="min-width:0">
            <?php
            if ('bale' === $tab) {
                if (function_exists('vkl') && vkl()->has('notify.admin')) {
                    vkl('notify.admin')->render_inner();
                } else {
                    echo '<p>' . esc_html__('The notifications module is not available.', 'vereskala-login') . '</p>';
                }
            } elseif ('login' === $tab) {
                vkl_settings_login_form();
            } elseif ('identity' === $tab) {
                vkl_settings_identity_form();
            } elseif ('api' === $tab) {
                vkl_settings_api_docs();
            } elseif ('dash' === $tab) {
                vkl_settings_dash_form();
            } elseif ('tabbar' === $tab) {
                vkl_settings_tabbar_form();
            } else {
                vkl_settings_sms_form();
            }
            ?>
        </div>
        </div><!-- /.vkl-settings -->
    </div>
    <?php
}

/**
 * Tab 1 — the classic SMS gateway settings.
 */
function vkl_settings_sms_form()
{
    ?>
    <form method="post" action="options.php">
        <?php settings_fields('vkl_settings'); ?>

        <h3>Melipayamak</h3>
        <p class="description" style="max-width:820px">
            <?php esc_html_e('All messages go out over the shared service line using your own approved pattern, so no dedicated line is needed and messages reach numbers on the operator blacklist too.', 'vereskala-login'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Panel username', 'vereskala-login'); ?></th>
                <td><input type="text" dir="ltr" class="regular-text" name="vkl_meli_username" value="<?php echo esc_attr((string) get_option('vkl_meli_username', '')); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Panel password', 'vereskala-login'); ?></th>
                <td><input type="password" dir="ltr" class="regular-text" name="vkl_meli_password" value="<?php echo esc_attr((string) get_option('vkl_meli_password', '')); ?>"></td>
            </tr>
        </table>
        <p class="description" style="max-width:820px">
            <?php esc_html_e('Put the pattern id (bodyId) from your panel in the Pattern Code field below — for the login code that is the pattern whose text contains your shop name and the code placeholder. Pattern variables are filled in the order the plugin sends them.', 'vereskala-login'); ?>
        </p>

        <table class="form-table">
            <tr>
                <th>API Key</th>
                <td><input type="text" class="regular-text" name="vkl_api_key" value="<?php echo esc_attr(get_option('vkl_api_key')); ?>"></td>
            </tr>
            <tr>
                <th>Pattern Code</th>
                <td><input type="text" class="regular-text" name="vkl_pattern_code" value="<?php echo esc_attr(get_option('vkl_pattern_code')); ?>"></td>
            </tr>
            <tr>
                <th>Sender</th>
                <td><input type="text" class="regular-text" name="vkl_sender" value="<?php echo esc_attr(get_option('vkl_sender')); ?>"></td>
            </tr>
        </table>

        <?php
        $proxy_host = (string) get_option('vkl_sms_proxy_host', '');
        $proxy_on   = '' !== $proxy_host && (int) get_option('vkl_sms_proxy_port', 0) > 0;
        ?>
        <details <?php echo $proxy_on ? 'open' : ''; ?> style="max-width:820px;margin-top:18px">
            <summary style="cursor:pointer;font-weight:600">
                🌐 <?php esc_html_e('Outgoing address for SMS (for gateways that refuse foreign server IPs)', 'vereskala-login'); ?>
                <?php echo $proxy_on ? ' — ' . esc_html__('active', 'vereskala-login') : ''; ?>
            </summary>
            <div style="padding:10px 2px 0">
                <p class="description">
                    <?php esc_html_e('Some Iranian gateways only accept requests from an Iranian IP address. Instead of moving the whole site to an Iranian server, you can put a small relay there and send only the SMS requests through it. Everything else on the site keeps connecting directly.', 'vereskala-login'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Send from this IP', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" dir="ltr" class="regular-text" name="vkl_sms_bind_ip" value="<?php echo esc_attr((string) get_option('vkl_sms_bind_ip', '')); ?>" placeholder="185.x.x.x">
                            <p class="description">
                                <?php esc_html_e('If your host gave you an extra Iranian IP (for example for a bank gateway), enter it here and SMS requests will go out from that address instead of the server’s default one. It must already be bound to this server — otherwise leave it empty and use the relay below.', 'vereskala-login'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Relay address', 'vereskala-login'); ?></th>
                        <td>
                            <input type="text" dir="ltr" class="regular-text" name="vkl_sms_proxy_host" value="<?php echo esc_attr($proxy_host); ?>" placeholder="1.2.3.4">
                            <p class="description"><?php esc_html_e('Leave empty to send directly, as before.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Port', 'vereskala-login'); ?></th>
                        <td><input type="number" min="0" step="1" style="width:110px" name="vkl_sms_proxy_port" value="<?php echo (int) get_option('vkl_sms_proxy_port', 0); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Relay type', 'vereskala-login'); ?></th>
                        <td>
                            <?php $ptype = (string) get_option('vkl_sms_proxy_type', 'http'); ?>
                            <select name="vkl_sms_proxy_type">
                                <option value="http" <?php selected($ptype, 'http'); ?>>HTTP</option>
                                <option value="socks5" <?php selected($ptype, 'socks5'); ?>>SOCKS5</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Username (optional)', 'vereskala-login'); ?></th>
                        <td><input type="text" dir="ltr" name="vkl_sms_proxy_user" value="<?php echo esc_attr((string) get_option('vkl_sms_proxy_user', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Password (optional)', 'vereskala-login'); ?></th>
                        <td><input type="password" dir="ltr" name="vkl_sms_proxy_pass" value="<?php echo esc_attr((string) get_option('vkl_sms_proxy_pass', '')); ?>"></td>
                    </tr>
                </table>
                <p class="description">
                    <?php esc_html_e('The gateway will then see the relay’s IP address — register that one in your panel’s allowed-IP list, not the server’s own.', 'vereskala-login'); ?>
                </p>
            </div>
        </details>

        <?php submit_button(); ?>
    </form>
    <?php
}

/**
 * Tab 3 — how to put the shop in customers' hands, written for a shop
 * owner rather than a developer. The technical settings panel is embedded
 * underneath so everything lives in one place.
 */
function vkl_settings_api_docs()
{
    $enabled = '1' === (string) get_option('vkl_api_enabled', '0');
    ?>
    <div style="max-width:900px">
        <div style="background:<?php echo $enabled ? '#eaf7ee' : '#fdf3f3'; ?>;border:1px solid <?php echo $enabled ? '#bfe6c9' : '#f0c9c9'; ?>;border-radius:10px;padding:12px 16px;margin-bottom:18px">
            <strong><?php echo $enabled ? '✅ ' . esc_html__('The mobile app connection is ON.', 'vereskala-login') : '⛔ ' . esc_html__('The mobile app connection is OFF.', 'vereskala-login'); ?></strong>
            <p style="margin:6px 0 0">
                <?php echo $enabled
                    ? esc_html__('Customers can sign in from the app right now.', 'vereskala-login')
                    : esc_html__('Switch it on in the settings below, otherwise the app cannot connect to your shop.', 'vereskala-login'); ?>
            </p>
        </div>

        <h2>📱 <?php esc_html_e('How to use the mobile app', 'vereskala-login'); ?></h2>
        <p class="description" style="font-size:13.5px">
            <?php esc_html_e('This connection lets your customers use your shop from a phone app: they sign in with their mobile number and see their wallet, points and orders — no password needed.', 'vereskala-login'); ?>
        </p>

        <h3>🛠 <?php esc_html_e('What you do (once)', 'vereskala-login'); ?></h3>
        <ol style="line-height:2.1;font-size:13.5px">
            <li><?php esc_html_e('Switch the connection on in the settings below and save.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Make sure your SMS settings (first tab) are working, because customers sign in with an SMS code.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Copy the shop address shown below and give it to whoever builds or configures your app.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Publish the app, or share its install link with your customers.', 'vereskala-login'); ?></li>
        </ol>

        <h3>🙋 <?php esc_html_e('What your customer does', 'vereskala-login'); ?></h3>
        <ol style="line-height:2.1;font-size:13.5px">
            <li><?php esc_html_e('Installs the Vereskonto app on their phone.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Opens the app and enters your shop address (or it is already built in).', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Types their mobile number and taps “Sign in”.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Receives an SMS code and enters it — that is the whole sign-in.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('From then on they see their wallet balance, festival credit, loyalty points and past orders, and stay signed in until they sign out.', 'vereskala-login'); ?></li>
        </ol>

        <h3>📍 <?php esc_html_e('Your shop address for the app', 'vereskala-login'); ?></h3>
        <p class="description"><?php esc_html_e('Give this line to your app developer — it is the only address the app needs.', 'vereskala-login'); ?></p>
        <p><code dir="ltr" style="user-select:all;font-size:13px;display:inline-block;padding:8px 12px;background:#f6f7f7;border-radius:6px"><?php echo esc_html(rest_url('vereskonto/v1')); ?></code></p>

        <h3>💡 <?php esc_html_e('Good to know', 'vereskala-login'); ?></h3>
        <ul style="line-height:1.95;font-size:13.5px;list-style:disc;padding-inline-start:22px">
            <li><?php esc_html_e('Each sign-in costs one SMS, exactly like signing in on the website.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Customers stay signed in for the number of days you set below; after that the app simply asks for a new SMS code.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('If a customer loses their phone, use “Sign out all app sessions” below — everyone is asked to sign in again.', 'vereskala-login'); ?></li>
            <li><?php esc_html_e('Your site should use HTTPS, which almost all hosts provide today.', 'vereskala-login'); ?></li>
        </ul>

        <hr style="margin:26px 0">

        <?php
        // The real settings panel (on/off, session length, statistics).
        if (function_exists('vkl') && vkl()->has('api.admin')) {
            vkl('api.admin')->render_inner();
        } else {
            echo '<p>' . esc_html__('The mobile API module is not available.', 'vereskala-login') . '</p>';
        }
        ?>
    </div>
    <?php
}

/**
 * Tab: the sign-in page. Logo, code length and timing, wording, colour.
 * One form, one settings group, so saving can never blank another field.
 */
function vkl_settings_login_form()
{
    wp_enqueue_media();

    $logo_id  = (int) get_option('vkl_login_logo', 0);
    $logo_url = vkl_login_logo_url();
    $texts    = vkl_login_text_defaults();
    $labels   = [
        'subtitle' => 'متن زیر نماد',
        'send'     => 'نوشتهٔ دکمهٔ دریافت گذرشماره',
        'hint'     => 'توضیح زیر دکمه',
        'verify'   => 'نوشتهٔ دکمهٔ ورود',
        'footer'   => 'متن پایین کارت',
    ];
    $num = static function (string $name, int $default, int $min, int $max, string $hint): void {
        echo '<input type="number" min="' . (int) $min . '" max="' . (int) $max . '" class="small-text" name="' . esc_attr($name) . '" value="' . (int) get_option($name, $default) . '">';
        echo '<p class="description">' . esc_html($hint) . '</p>';
    };
    ?>
    <form method="post" action="options.php" style="max-width:860px">
        <?php settings_fields('vkl_login_settings'); ?>

        <h3>ظاهر برگهٔ ورود</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">نماد سایت</th>
                <td>
                    <input type="hidden" name="vkl_login_logo" id="vkl_login_logo" value="<?php echo (int) $logo_id; ?>">
                    <img id="vkl_login_logo_preview" src="<?php echo esc_url($logo_url); ?>" alt="" style="max-height:84px;max-width:210px;display:block;margin-bottom:8px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:6px">
                    <button type="button" class="button" id="vkl_login_logo_pick">انتخاب نماد از رسانه‌ها</button>
                    <button type="button" class="button-link" id="vkl_login_logo_reset" style="margin-inline-start:8px;color:#b32d2e">بازگشت به نماد پیش‌فرض</button>
                    <p class="description">تصویری با پس‌زمینهٔ روشن و ارتفاع حدود ۸۴ پیکسل بهترین نتیجه را می‌دهد.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">رنگ اصلی</th>
                <td>
                    <input type="color" name="vkl_login_accent" id="vkl_login_accent" value="<?php echo esc_attr(vkl_login_accent() ?: '#157f3a'); ?>">
                    <label style="margin-inline-start:10px"><input type="checkbox" id="vkl_login_accent_off" <?php checked('' === vkl_login_accent()); ?>> رنگ پیش‌فرض (سبز ورسکالا)</label>
                    <p class="description">رنگ دکمه‌ها، نوار بالای کارت و کادر فیلد.</p>
                </td>
            </tr>
            <?php foreach ($labels as $key => $label) : ?>
                <tr>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td>
                        <input type="text" class="large-text" name="vkl_login_<?php echo esc_attr($key); ?>"
                               value="<?php echo esc_attr((string) get_option('vkl_login_' . $key, '')); ?>"
                               placeholder="<?php echo esc_attr($texts[$key]); ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="description">فیلد خالی = متن پیش‌فرض (نوشتهٔ کم‌رنگ داخل کادر).</p>

        <h3>گذرشمارهٔ ورود</h3>
        <table class="form-table" role="presentation">
            <tr><th scope="row">تعداد ارقام</th><td><?php $num('vkl_otp_length', 5, 4, 8, 'بین ۴ تا ۸ رقم. پترن پیامک فقط یک متغیر کد دارد و طول آن را محدود نمی‌کند.'); ?></td></tr>
            <tr><th scope="row">اعتبار کد (ثانیه)</th><td><?php $num('vkl_otp_expire', 120, 30, 900, 'پس از این مدت کد از کار می‌افتد و شمارندهٔ «فرستادن دوباره» تمام می‌شود.'); ?></td></tr>
            <tr><th scope="row">بیشینهٔ درخواست کد</th><td><?php $num('vkl_otp_max_requests', 5, 1, 30, 'هر شماره در هر بازهٔ زیر حداکثر این‌قدر کد می‌خواهد.'); ?></td></tr>
            <tr><th scope="row">بیشینهٔ درخواست کد از یک IP</th><td><?php $num('vkl_otp_max_requests_ip', 30, 0, 500, 'جلوی فرستادن پیامک انبوه به شماره‌های مختلف از یک دستگاه را می‌گیرد. ۰ = خاموش. نشانی واقعی بازدیدکننده پشت Cloudflare هم درست تشخیص داده می‌شود.'); ?></td></tr>
            <tr><th scope="row">بازهٔ شمارش درخواست‌ها (دقیقه)</th><td><?php $num('vkl_otp_rate_minutes', 15, 1, 240, 'پس از پایان این بازه، شمارنده از صفر شروع می‌شود.'); ?></td></tr>
            <tr><th scope="row">بیشینهٔ کد نادرست</th><td><?php $num('vkl_otp_max_fail', 5, 1, 20, 'پس از این تعداد کد نادرست، ورود آن شماره ۱۵ دقیقه بسته می‌شود.'); ?></td></tr>
        </table>

        <?php submit_button('ذخیرهٔ تنظیمات ورود'); ?>
    </form>
    <script>
    (function () {
        var pick = document.getElementById('vkl_login_logo_pick'), reset = document.getElementById('vkl_login_logo_reset'),
            field = document.getElementById('vkl_login_logo'), prev = document.getElementById('vkl_login_logo_preview'),
            color = document.getElementById('vkl_login_accent'), off = document.getElementById('vkl_login_accent_off');
        var defaultLogo = <?php echo wp_json_encode(VKL_URL . 'assets/logo.png'); ?>, frame;
        if (pick && window.wp && wp.media) {
            pick.addEventListener('click', function () {
                if (!frame) {
                    frame = wp.media({ title: 'نماد برگهٔ ورود', library: { type: 'image' }, multiple: false, button: { text: 'انتخاب' } });
                    frame.on('select', function () {
                        var a = frame.state().get('selection').first().toJSON();
                        field.value = a.id; prev.src = a.url;
                    });
                }
                frame.open();
            });
        }
        if (reset) { reset.addEventListener('click', function () { field.value = 0; prev.src = defaultLogo; }); }
        // A colour input always submits a value; the checkbox turns that into "default".
        var form = color ? color.closest('form') : null;
        if (form && off) {
            form.addEventListener('submit', function () {
                if (off.checked) {
                    color.disabled = true;
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = 'vkl_login_accent'; h.value = '';
                    form.appendChild(h);
                }
            });
            color.addEventListener('input', function () { off.checked = false; });
        }
    })();
    </script>
    <?php
}

/**
 * Admin-post handler: one test inquiry with a mobile and national ID.
 */
add_action('admin_post_vkl_id_test', static function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('403');
    }

    check_admin_referer('vkl_id_test');

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
    $mobile   = isset($_POST['vkl_test_mobile']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_test_mobile'])) : '';
    $national = isset($_POST['vkl_test_nid']) ? VKL_Wc_Account::normalize_national_id((string) wp_unslash($_POST['vkl_test_nid'])) : '';
    // phpcs:enable

    delete_transient('vkl_id_down'); // an explicit test always tries for real

    $result = '' === $mobile || !VKL_Wc_Account::is_valid_national_id($national)
        ? ['status' => 'error', 'message' => 'شمارهٔ همراه یا کد ملی آزمایشی معتبر نیست.', 'raw' => '']
        : VKL_Identity::check($mobile, $national);

    set_transient('vkl_id_test_result', $result, MINUTE_IN_SECONDS);

    wp_safe_redirect(admin_url('admin.php?page=vereskala-login&tab=identity&tested=1'));
    exit;
});

/**
 * Tab: mobile <-> national-ID matching. One settings group, one form.
 */
function vkl_settings_identity_form()
{
    $mode   = (string) get_option('vkl_id_mode', 'off');
    $method = (string) get_option('vkl_id_method', 'POST');
    $format = (string) get_option('vkl_id_mobile_format', 'local');
    $saved  = '' !== trim((string) get_option('vkl_id_token', ''));
    $tested = isset($_GET['tested']) ? get_transient('vkl_id_test_result') : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $labels = ['match' => ['success', '✓ تطبیق دارد'], 'mismatch' => ['warning', '✗ مغایرت (شماره به نام این کد ملی نیست)'], 'error' => ['error', 'خطا']];
    ?>
    <div style="max-width:860px">
        <p class="description" style="font-size:13.5px">
            با این بخش، هنگام ثبت کد ملی بررسی می‌شود که شمارهٔ همراه مشتری به نام صاحب همان کد ملی است. سرویس استعلام (سحاب یا هر سرویس دیگری با API ساده به‌صورت JSON)
            با نشانی، سرآیند احراز و قالب درخواستی که در مستندات آن آمده در همین صفحه تعریف می‌شود. مقدارهای پیش‌فرض این صفحه همان «احراز هویت شاهکار» (POST /api/sw1/Shahkar) در s.api.ir است؛ فقط توکن را وارد کنید، حالت را روشن کنید و آزمایش بزنید.
        </p>

        <?php if (is_array($tested)) : ?>
            <?php $state = $labels[(string) ($tested['status'] ?? 'error')] ?? $labels['error']; ?>
            <div class="notice notice-<?php echo esc_attr($state[0]); ?>" style="padding:10px 14px">
                <strong><?php echo esc_html($state[1]); ?></strong>
                <?php if ('' !== (string) ($tested['message'] ?? '')) : ?><br><?php echo esc_html((string) $tested['message']); ?><?php endif; ?>
                <?php if ('' !== (string) ($tested['raw'] ?? '')) : ?>
                    <details style="margin-top:6px"><summary>پاسخ خام سرویس</summary><pre dir="ltr" style="white-space:pre-wrap;margin:6px 0 0"><?php echo esc_html((string) $tested['raw']); ?></pre></details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('vkl_identity_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">حالت</th>
                    <td>
                        <label><input type="radio" name="vkl_id_mode" value="off" <?php checked('off' === $mode || !in_array($mode, ['record', 'require'], true)); ?>> خاموش</label><br>
                        <label><input type="radio" name="vkl_id_mode" value="record" <?php checked('record', $mode); ?>> فقط بررسی و نمایش نتیجه (به مشتری و مدیر)</label><br>
                        <label><input type="radio" name="vkl_id_mode" value="require" <?php checked('require', $mode); ?>> درخواست وام و اعتبار فقط با تطبیق پذیرفته شود</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">نشانی سرویس</th>
                    <td>
                        <input type="url" dir="ltr" class="large-text" name="vkl_id_url" value="<?php echo esc_attr(VKL_Identity::setting('url')); ?>" placeholder="https://…">
                        <p class="description">در روش GET می‌توانید <code dir="ltr">{mobile}</code> و <code dir="ltr">{national_id}</code> را در نشانی بنویسید.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">روش درخواست</th>
                    <td>
                        <select name="vkl_id_method">
                            <option value="POST" <?php selected('GET' !== strtoupper($method)); ?>>POST (بدنهٔ JSON)</option>
                            <option value="GET" <?php selected('GET', strtoupper($method)); ?>>GET</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">سرآیند احراز</th>
                    <td>
                        <input type="text" dir="ltr" class="regular-text" name="vkl_id_auth_header" value="<?php echo esc_attr(VKL_Identity::setting('header')); ?>" placeholder="Authorization">
                        <input type="password" dir="ltr" class="regular-text" autocomplete="new-password" name="vkl_id_token" value=""
                               placeholder="<?php echo $saved ? '•••••• (ذخیره شده — برای تغییر، مقدار تازه را بنویسید)' : 'توکن سرویس (با یا بدون Bearer)'; ?>">
                        <?php if ($saved) : ?><label style="display:block;margin-top:6px"><input type="checkbox" name="vkl_id_token_clear" value="1"> حذف مقدار ذخیره‌شده</label><?php endif; ?>
                        <p class="description">نام سرآیند و مقدار کامل آن را دقیقاً مطابق مستندات سرویس بنویسید.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">قالب بدنهٔ درخواست (POST)</th>
                    <td>
                        <textarea dir="ltr" class="large-text code" rows="4" name="vkl_id_body" placeholder='{"nationalCode":"{national_id}","mobile":"{mobile}","isCompany":false}'><?php echo esc_textarea(VKL_Identity::setting('body')); ?></textarea>
                        <p class="description">JSON با جاینشین‌های <code dir="ltr">{mobile}</code> و <code dir="ltr">{national_id}</code>. نام فیلدها را مطابق مستندات تغییر دهید.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">قالب شمارهٔ همراه</th>
                    <td>
                        <select name="vkl_id_mobile_format">
                            <option value="local" <?php selected('local', $format); ?>>09123456789</option>
                            <option value="intl" <?php selected('intl', $format); ?>>989123456789</option>
                            <option value="bare" <?php selected('bare', $format); ?>>9123456789</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">مسیر نتیجه در پاسخ</th>
                    <td>
                        <input type="text" dir="ltr" class="regular-text" name="vkl_id_path" value="<?php echo esc_attr(VKL_Identity::setting('path')); ?>" placeholder="data">
                        <p class="description">جایی از پاسخ JSON که نتیجهٔ تطبیق است؛ با نقطه جدا کنید (مثلاً <code dir="ltr">data.matched</code> یا <code dir="ltr">result.0.status</code>).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">مقدارهای «تطبیق دارد»</th>
                    <td>
                        <input type="text" dir="ltr" class="regular-text" name="vkl_id_ok_value" value="<?php echo esc_attr(VKL_Identity::setting('ok')); ?>" placeholder="true,1">
                        <p class="description">مقدارهایی که یعنی تطبیق، با ویرگول جدا (بدون تفاوت حروف بزرگ و کوچک). هر مقدار دیگری یعنی مغایرت.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">مهلت پاسخ (ثانیه)</th>
                    <td><input type="number" min="3" max="30" class="small-text" name="vkl_id_timeout" value="<?php echo (int) get_option('vkl_id_timeout', 10); ?>"></td>
                </tr>
            </table>
            <?php submit_button('ذخیرهٔ تنظیمات تطبیق'); ?>
        </form>

        <h3>آزمایش</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="action" value="vkl_id_test">
            <?php wp_nonce_field('vkl_id_test'); ?>
            <label>شمارهٔ همراه<br><input type="text" dir="ltr" name="vkl_test_mobile" placeholder="09123456789"></label>
            <label>کد ملی<br><input type="text" dir="ltr" name="vkl_test_nid" maxlength="10" inputmode="numeric"></label>
            <button type="submit" class="button">🔌 آزمایش با تنظیمات ذخیره‌شده</button>
        </form>
        <p class="description">ابتدا تنظیمات را ذخیره کنید. پاسخ خام سرویس زیر نتیجه نشان داده می‌شود تا «مسیر نتیجه» را درست بنویسید. هر آزمایش یک استعلام مصرف می‌کند.</p>
    </div>
    <?php
}

/**
 * Tab: the mobile tab bar — the shop's main navigation on phones.
 * Saves only the "bar" part of vkl_dash.
 */
function vkl_settings_tabbar_form()
{
    $o      = VKL_Dash_Options::get();
    $name   = VKL_Dash_Options::OPTION;
    $titles = [
        'home'      => 'خانه (صفحهٔ اول سایت)',
        'shop'      => 'فروشگاه',
        'cart'      => 'سبد خرید (با شمار کالاها)',
        'dashboard' => 'حساب من (پیشخوان)',
        'purchases' => 'خریدها (سفارش‌ها، برگشت، دانلود)',
        'wallet'    => 'کیف پول و اعتبار',
        'support'   => 'پشتیبانی (تیکت‌ها)',
        'account'   => 'جزئیات حساب و نشانی‌ها',
        'custom'    => 'پیوند دلخواه',
    ];
    $icons = ['star' => 'ستاره', 'heart' => 'قلب', 'gift' => 'هدیه', 'tag' => 'برچسب تخفیف', 'search' => 'جست‌وجو', 'phone' => 'تلفن'];
    $radio = static function (string $field, string $value, string $label) use ($o, $name): void {
        printf(
            '<label style="display:block;margin:2px 0"><input type="radio" name="%1$s[%2$s]" value="%3$s" %4$s> %5$s</label>',
            esc_attr($name),
            esc_attr($field),
            esc_attr($value),
            checked((string) $o[$field], $value, false),
            esc_html($label)
        );
    };
    $check = static function (string $field, string $label) use ($o, $name): void {
        printf(
            '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
            esc_attr($name),
            esc_attr($field),
            checked(!empty($o[$field]), true, false),
            esc_html($label)
        );
    };
    ?>
    <form method="post" action="options.php" style="max-width:900px">
        <?php settings_fields(VKL_Dash_Options::GROUP); ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>[_part]" value="bar">

        <p class="description" style="font-size:13.5px">
            نوار شناور پایین صفحه روی موبایل، نوار اصلی فروشگاه است. در صفحه‌هایی که این نوار نمایش داده می‌شود، نوار پایینی قالب وودمارت (Sticky toolbar) خاموش می‌شود تا دو نوار روی هم نیفتند.
        </p>

        <h3>نمایش</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">نوار موبایل</th>
                <td><?php $check('bar_enabled', 'روشن'); ?></td>
            </tr>
            <tr>
                <th scope="row">کجا نمایش داده شود</th>
                <td>
                    <?php $radio('bar_scope', 'site', 'همهٔ صفحه‌های سایت (نوار اصلی موبایل)'); ?>
                    <?php $radio('bar_scope', 'account', 'فقط صفحه‌های حساب کاربری'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">مهمان‌ها</th>
                <td>
                    <?php $check('bar_guests', 'به بازدیدکنندهٔ واردنشده هم نشان بده'); ?>
                    <p class="description">برای مهمان، تب‌های خریدها، کیف پول، پشتیبانی و جزئیات حساب پنهان می‌مانند و «حساب من» به برگهٔ ورود می‌رود.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">نوار وودمارت</th>
                <td>
                    <?php $radio('theme_toolbar', 'hide', 'خاموش شود (این نوار جای آن را می‌گیرد)'); ?>
                    <?php $radio('theme_toolbar', 'keep', 'بماند (این نوار بالای آن شناور می‌شود)'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">برگهٔ پرداخت</th>
                <td><?php $check('hide_checkout', 'در برگهٔ پرداخت و رسید سفارش پنهان شود'); ?></td>
            </tr>
        </table>

        <h3>تب‌ها</h3>
        <p class="description">حداکثر <?php echo (int) VKL_Dash_Options::MAX_TABS; ?> تب (پیشنهاد: ۵ تا). اگر گفبات روشن باشد، دکمهٔ گفبات هم کنار تب‌ها می‌آید. نوشتهٔ خالی = نام پیش‌فرض. تب‌ها به ترتیب عدد «جایگاه» چیده می‌شوند.</p>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr><th style="width:60px">روشن</th><th>تب</th><th>نوشته روی تب</th><th style="width:90px">جایگاه</th></tr>
            </thead>
            <tbody>
                <?php foreach (VKL_Dash_Options::tab_keys() as $key) : ?>
                    <?php $row = $o['tabs'][$key]; ?>
                    <tr>
                        <td><input type="checkbox" name="<?php echo esc_attr($name . '[tabs][' . $key . '][on]'); ?>" value="1" <?php checked(!empty($row['on'])); ?> aria-label="<?php echo esc_attr($titles[$key]); ?>"></td>
                        <td><?php echo esc_html($titles[$key]); ?></td>
                        <td><input type="text" class="regular-text" maxlength="24" name="<?php echo esc_attr($name . '[tabs][' . $key . '][label]'); ?>" value="<?php echo esc_attr((string) $row['label']); ?>" placeholder="<?php echo esc_attr('custom' === $key ? 'مثلاً: تخفیف‌ها' : VKL_Dash_Options::tab_default_label($key)); ?>"></td>
                        <td><input type="number" min="0" max="99" class="small-text" name="<?php echo esc_attr($name . '[tabs][' . $key . '][pos]'); ?>" value="<?php echo (int) $row['pos']; ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">مقصد تب خانه</th>
                <td>
                    <?php $radio('home_target', 'site', 'صفحهٔ اول سایت'); ?>
                    <?php $radio('home_target', 'dashboard', 'پیشخوان حساب کاربری (رفتار نسخه‌های قبل)'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">پیوند دلخواه</th>
                <td>
                    <input type="url" dir="ltr" class="regular-text" name="<?php echo esc_attr($name); ?>[custom_url]" value="<?php echo esc_attr((string) $o['custom_url']); ?>" placeholder="https://…">
                    <select name="<?php echo esc_attr($name); ?>[custom_icon]">
                        <?php foreach ($icons as $icon => $icon_label) : ?>
                            <option value="<?php echo esc_attr($icon); ?>" <?php selected((string) $o['custom_icon'], $icon); ?>><?php echo esc_html($icon_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">تب «پیوند دلخواه» فقط وقتی نمایش داده می‌شود که هم نشانی و هم نوشته داشته باشد.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">شمار کالاهای سبد</th>
                <td><?php $check('cart_badge', 'عدد کالاهای سبد روی تب سبد خرید'); ?></td>
            </tr>
        </table>

        <h3>ظاهر</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">شکل نوار</th>
                <td>
                    <?php $radio('bar_style', 'floating', 'شناور (با فاصله از لبه‌ها و گوشه‌های گرد)'); ?>
                    <?php $radio('bar_style', 'docked', 'چسبیده به پایین صفحه (تمام‌عرض)'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row">رنگ تب فعال</th>
                <td><input type="color" name="<?php echo esc_attr($name); ?>[bar_color]" value="<?php echo esc_attr((string) $o['bar_color']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">بیشینهٔ عرض صفحه (پیکسل)</th>
                <td>
                    <input type="number" min="480" max="1200" class="small-text" name="<?php echo esc_attr($name); ?>[bar_breakpoint]" value="<?php echo (int) $o['bar_breakpoint']; ?>">
                    <p class="description">نوار در صفحه‌هایی به این عرض و کمتر نمایش داده می‌شود (۷۶۸ = گوشی؛ ۱۰۲۴ = گوشی و تبلت).</p>
                </td>
            </tr>
        </table>

        <?php submit_button('ذخیرهٔ تنظیمات نوار موبایل'); ?>
    </form>
    <?php
}

/**
 * Tab: the customer's My Account dashboard. Saves only the "dash" part
 * of vkl_dash.
 */
function vkl_settings_dash_form()
{
    $o        = VKL_Dash_Options::get();
    $name     = VKL_Dash_Options::OPTION;
    $sections = [
        'notice' => 'پیام مدیر (بالای پیشخوان)',
        'head'   => 'کارت نام و موجودی‌ها',
        'orders' => 'سفارش‌های من (جاری، تحویل‌شده، مرجوعی)',
        'banner' => 'بنر',
        'menu'   => 'فهرست بخش‌ها',
        'repeat' => 'خریدهای تکراری شما',
    ];
    $rows = [
        'orders'       => ['سفارش‌ها', '🛍'],
        'returns'      => ['برگشت کالا', '↩️'],
        'downloads'    => ['دانلودها', '⬇️'],
        'wallet'       => ['کیف پول', '👛'],
        'credit'       => ['اعتبار خرید و اقساط', '🛒'],
        'club'         => ['باشگاه مشتریان', '🏆'],
        'surveys'      => ['نظرسنجی‌ها', '📋'],
        'tickets'      => ['تیکت‌ها', '🎫'],
        'edit-account' => ['جزئیات حساب', '👤'],
        'edit-address' => ['نشانی‌ها', '📍'],
    ];
    $field = static fn(string $key): string => $name . '[' . $key . ']';
    $show  = static function (string $key, string $label) use ($o, $name): void {
        printf(
            '<label><input type="checkbox" name="%1$s[show][%2$s]" value="1" %3$s> %4$s</label>',
            esc_attr($name),
            esc_attr($key),
            checked(!empty($o['show'][$key]), true, false),
            esc_html($label)
        );
    };
    ?>
    <form method="post" action="options.php" style="max-width:900px">
        <?php settings_fields(VKL_Dash_Options::GROUP); ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>[_part]" value="dash">

        <p class="description" style="font-size:13.5px">
            برگهٔ پیشخوان حساب کاربری مشتری (حساب من) را از اینجا شخصی‌سازی کنید: کدام بخش‌ها نمایش داده شوند، به چه ترتیبی، با چه رنگ و متنی.
            <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/')); ?>" target="_blank" rel="noopener">دیدن پیشخوان ↗</a>
        </p>

        <h3>بخش‌ها</h3>
        <p class="description">ترتیب روی موبایل با عدد «جایگاه» است. روی دسکتاپ در چیدمان دوستونه، کارت نام، بنر و فهرست در ستون کناری و سفارش‌ها و خریدهای تکراری در ستون اصلی می‌مانند.</p>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th style="width:60px">نمایش</th><th>بخش</th><th style="width:90px">جایگاه</th></tr></thead>
            <tbody>
                <?php foreach ($sections as $key => $label) : ?>
                    <tr>
                        <td><input type="checkbox" name="<?php echo esc_attr($name . '[show][' . $key . ']'); ?>" value="1" <?php checked(!empty($o['show'][$key])); ?> aria-label="<?php echo esc_attr($label); ?>"></td>
                        <td><?php echo esc_html($label); ?></td>
                        <td><input type="number" min="0" max="20" class="small-text" name="<?php echo esc_attr($name . '[order][' . $key . ']'); ?>" value="<?php echo (int) $o['order'][$key]; ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>کارت نام و موجودی‌ها</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">خوش‌آمدگویی</th>
                <td>
                    <input type="text" class="regular-text" maxlength="80" name="<?php echo esc_attr($field('greeting')); ?>" value="<?php echo esc_attr((string) $o['greeting']); ?>" placeholder="مثلاً: سلام {name}، خوش آمدید">
                    <p class="description">یک خط بالای نام مشتری. <code>{name}</code> با نام مشتری جایگزین می‌شود. خالی = بدون خوش‌آمدگویی.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">کاشی‌های موجودی</th>
                <td>
                    <?php $show('tiles', 'نمایش ردیف کاشی‌ها'); ?><br>
                    <?php $show('tile_wallet', 'موجودی کیف پول'); ?><br>
                    <?php $show('tile_points', 'امتیاز باشگاه'); ?><br>
                    <?php $show('tile_credit', 'اعتبار خرید'); ?>
                    <p class="description">کاشی ماژولی که خاموش است، خودبه‌خود پنهان می‌شود.</p>
                </td>
            </tr>
        </table>

        <h3>پیام مدیر</h3>
        <textarea class="large-text" rows="3" name="<?php echo esc_attr($field('notice')); ?>" placeholder="مثلاً: ارسال رایگان برای خریدهای بالای یک میلیون تومان تا پایان ماه"><?php echo esc_textarea((string) $o['notice']); ?></textarea>
        <p class="description">کارتی بالای پیشخوان برای همهٔ مشتریان. پیوند و قالب‌بندی سادهٔ HTML (مثل <code>&lt;strong&gt;</code> و <code>&lt;a&gt;</code>) مجاز است. خالی = بدون پیام.</p>

        <h3>بنر</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">نوع بنر</th>
                <td>
                    <?php foreach (['auto' => 'خودکار (اعتبار جشنواره، وگرنه دعوت از دوستان)', 'custom' => 'بنر دلخواه من', 'off' => 'بدون بنر'] as $mode => $mode_label) : ?>
                        <label style="display:block;margin:2px 0"><input type="radio" name="<?php echo esc_attr($field('banner_mode')); ?>" value="<?php echo esc_attr($mode); ?>" <?php checked((string) $o['banner_mode'], $mode); ?>> <?php echo esc_html($mode_label); ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">بنر دلخواه</th>
                <td>
                    <input type="text" class="small-text" maxlength="8" name="<?php echo esc_attr($field('banner_emoji')); ?>" value="<?php echo esc_attr((string) $o['banner_emoji']); ?>" aria-label="نماد">
                    <input type="text" class="large-text" maxlength="300" name="<?php echo esc_attr($field('banner_text')); ?>" value="<?php echo esc_attr((string) $o['banner_text']); ?>" placeholder="متن بنر" style="margin-top:6px">
                    <input type="text" class="regular-text" maxlength="30" name="<?php echo esc_attr($field('banner_label')); ?>" value="<?php echo esc_attr((string) $o['banner_label']); ?>" placeholder="نوشتهٔ پیوند (مثلاً: ببینید)" style="margin-top:6px">
                    <input type="url" dir="ltr" class="regular-text" name="<?php echo esc_attr($field('banner_url')); ?>" value="<?php echo esc_attr((string) $o['banner_url']); ?>" placeholder="https://…" style="margin-top:6px">
                    <p class="description">نماد (یک ایموجی)، متن، و در صورت نیاز یک پیوند.</p>
                </td>
            </tr>
        </table>

        <h3>فهرست بخش‌ها</h3>
        <p class="description">ردیف‌هایی که نمی‌خواهید در پیشخوان باشند را پنهان کنید (برگه‌هایشان همچنان کار می‌کنند) و در صورت تمایل نماد هر ردیف را با یک ایموجی دیگر عوض کنید.</p>
        <table class="widefat striped" style="max-width:900px">
            <thead><tr><th style="width:60px">پنهان</th><th>ردیف</th><th style="width:140px">نماد</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $slug => [$label, $icon]) : ?>
                    <tr>
                        <td><input type="checkbox" name="<?php echo esc_attr($name); ?>[hidden_rows][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $o['hidden_rows'], true)); ?> aria-label="<?php echo esc_attr('پنهان کردن ' . $label); ?>"></td>
                        <td><?php echo esc_html($label); ?></td>
                        <td><input type="text" class="small-text" maxlength="8" name="<?php echo esc_attr($name . '[icons][' . $slug . ']'); ?>" value="<?php echo esc_attr((string) ($o['icons'][$slug] ?? '')); ?>" placeholder="<?php echo esc_attr($icon); ?>"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php $show('logout', 'ردیف «خروج» در پایین فهرست'); ?></p>

        <h3>خریدهای تکراری</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">عنوان</th>
                <td><input type="text" class="regular-text" maxlength="60" name="<?php echo esc_attr($field('repeat_title')); ?>" value="<?php echo esc_attr((string) $o['repeat_title']); ?>" placeholder="<?php echo esc_attr__('Your repeat purchases', 'vereskala-login'); ?>"></td>
            </tr>
            <tr>
                <th scope="row">تعداد کالا</th>
                <td><input type="number" min="1" max="20" class="small-text" name="<?php echo esc_attr($field('repeat_limit')); ?>" value="<?php echo (int) $o['repeat_limit']; ?>"></td>
            </tr>
        </table>

        <h3>ظاهر</h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">رنگ اصلی</th>
                <td><input type="color" name="<?php echo esc_attr($field('brand_color')); ?>" value="<?php echo esc_attr((string) $o['brand_color']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">نوار پرچم</th>
                <td><label><input type="checkbox" name="<?php echo esc_attr($field('flag')); ?>" value="1" <?php checked(!empty($o['flag'])); ?>> خط سبز و قرمز زیر عنوان‌ها و کارت نام</label></td>
            </tr>
            <tr>
                <th scope="row">چیدمان دسکتاپ</th>
                <td>
                    <label style="display:block;margin:2px 0"><input type="radio" name="<?php echo esc_attr($field('layout')); ?>" value="two" <?php checked((string) $o['layout'], 'two'); ?>> دوستونه</label>
                    <label style="display:block;margin:2px 0"><input type="radio" name="<?php echo esc_attr($field('layout')); ?>" value="one" <?php checked((string) $o['layout'], 'one'); ?>> یک‌ستونه (مثل موبایل، وسط صفحه)</label>
                </td>
            </tr>
        </table>

        <?php submit_button('ذخیرهٔ تنظیمات پیشخوان'); ?>
    </form>
    <?php
}
