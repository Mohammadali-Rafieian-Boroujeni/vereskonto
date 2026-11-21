<?php
/**
 * Active users ("کاربران کارا").
 *
 * Shows the customers who are logged in right now and runs the automatic
 * login reward: some time after a customer logs in, festival credit is
 * deposited in their wallet, an SMS announces it, and further reminder
 * SMS are spread evenly over the hours the credit stays valid.
 *
 * Everything the admin can tune lives in one form on the tab, saved by a
 * single handler, so no setting can wipe another one.
 *
 * @package Vereskonto\Modules\Cart
 * @since   2.9.21
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_ActiveUsers
 */
final class VKL_Cart_ActiveUsers extends VKL_Repository
{
    public const PAGE_SLUG   = 'vkl-cart';
    public const SECTION     = 'active';
    private const SAVE_ACTION = 'vkl_active_save';
    private const META_SEEN   = 'vkl_last_seen';
    private const ONLINE_SECS = 900;

    private VKL_Config $config;

    private VKL_Security $security;

    public function __construct(VKL_Database $db, VKL_Config $config, VKL_Security $security)
    {
        parent::__construct($db);

        $this->config   = $config;
        $this->security = $security;
    }

    protected function table_name(): string
    {
        return 'login_promos';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('2.9.21', static function (VKL_Database $db): void {
            dbDelta(
                'CREATE TABLE ' . $db->table('login_promos') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    login_at DATETIME NOT NULL,
                    due_at DATETIME NOT NULL,
                    status VARCHAR(12) NOT NULL DEFAULT 'pending',
                    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                    granted_at DATETIME NULL DEFAULT NULL,
                    expires_at DATETIME NULL DEFAULT NULL,
                    reminders_total INT UNSIGNED NOT NULL DEFAULT 0,
                    reminders_sent INT UNSIGNED NOT NULL DEFAULT 0,
                    next_reminder_at DATETIME NULL DEFAULT NULL,
                    note VARCHAR(190) NOT NULL DEFAULT '',
                    PRIMARY KEY  (id),
                    KEY status_due (status, due_at),
                    KEY user_login (user_id, login_at)
                ) {$db->charset_collate()};"
            );
        });
    }

    public function register(): void
    {
        $this->config->add_defaults([
            'active_promo_enabled'       => '0',
            'active_promo_delay_min'     => 60,
            'active_promo_amount'        => 0,
            'active_promo_days'          => 3,
            'active_promo_use_min'       => 0,
            'active_promo_cooldown_days' => 1,
            'active_promo_reminders'     => 2,
            'active_promo_title'         => '',
            'active_promo_pattern'       => '',
            'active_promo_remind_pattern' => '',
        ]);

        add_action('init', [$this, 'touch_last_seen']);
        add_action('wp_login', [$this, 'on_login'], 20, 2);
        add_action('vkl_cart_tick', [$this, 'tick']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
    }

    /**
     * Remember when a logged-in visitor was last seen (at most every 2 min).
     */
    public function touch_last_seen(): void
    {
        if (!is_user_logged_in() || wp_doing_cron()) {
            return;
        }

        $user_id = get_current_user_id();
        $seen    = (int) get_user_meta($user_id, self::META_SEEN, true);

        if (time() - $seen >= 120) {
            update_user_meta($user_id, self::META_SEEN, time());
        }
    }

    /**
     * A customer logged in: book the delayed reward (once per cooldown).
     *
     * @param string  $login Login name.
     * @param WP_User $user  User.
     */
    public function on_login(string $login, $user): void
    {
        if (!$user instanceof WP_User) {
            return;
        }

        update_user_meta($user->ID, self::META_SEEN, time());

        if (!$this->config->get_bool('active_promo_enabled', false)
            || $this->config->get_int('active_promo_amount', 0) <= 0
            || user_can($user, 'manage_options')
        ) {
            return;
        }

        $cooldown = max(1, $this->config->get_int('active_promo_cooldown_days', 1));
        $recent   = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {login_promos} WHERE user_id = %d AND status IN ('pending','granting','granted') AND login_at > %s",
            $user->ID,
            gmdate('Y-m-d H:i:s', time() - $cooldown * DAY_IN_SECONDS)
        );

        if ($recent > 0) {
            return;
        }

        $delay = max(0, $this->config->get_int('active_promo_delay_min', 60));

        $this->insert_row([
            'user_id'  => $user->ID,
            'login_at' => $this->now(),
            'due_at'   => gmdate('Y-m-d H:i:s', time() + $delay * MINUTE_IN_SECONDS),
            'status'   => 'pending',
            'amount'   => (float) $this->config->get_int('active_promo_amount', 0),
        ]);
    }

    /**
     * Scheduled tick: deposit due rewards, then send due reminders.
     */
    public function tick(): void
    {
        if (!$this->config->get_bool('active_promo_enabled', false) || !vkl()->has('wallet.promos')) {
            return;
        }

        $now = $this->now();

        $due = $this->db->get_results(
            "SELECT id, user_id FROM {login_promos} WHERE status = 'pending' AND due_at <= %s ORDER BY due_at ASC LIMIT 50",
            $now
        );

        foreach ($due as $row) {
            // Atomic claim: a second overlapping tick cannot grant twice.
            if (1 !== $this->db->query("UPDATE {login_promos} SET status = 'granting' WHERE id = %d AND status = 'pending'", (int) $row['id'])) {
                continue;
            }

            $this->grant((int) $row['id'], (int) $row['user_id']);
        }

        $remind = $this->db->get_results(
            "SELECT * FROM {login_promos}
             WHERE status = 'granted' AND reminders_sent < reminders_total AND next_reminder_at <= %s
             ORDER BY next_reminder_at ASC LIMIT 50",
            $now
        );

        foreach ($remind as $row) {
            $this->remind($row);
        }
    }

    private function mobile_of(int $user_id): string
    {
        $mobile = (string) get_user_meta($user_id, 'billing_phone', true);

        if ('' === $mobile) {
            $user   = get_userdata($user_id);
            $login  = $user ? (string) $user->user_login : '';
            $mobile = preg_match('/^\+?\d{10,14}$/', $login) ? $login : '';
        }

        return $mobile;
    }

    /**
     * The customer's own name, or «ورسکالایی» when none is stored.
     * (`send()` does not expand the literal word `auto` — only bulk sends do.)
     */
    private function name_of(int $user_id): string
    {
        $user = get_userdata($user_id);

        foreach ([
            (string) get_user_meta($user_id, 'first_name', true),
            (string) get_user_meta($user_id, 'billing_first_name', true),
            $user ? (string) $user->display_name : '',
        ] as $name) {
            $name = trim($name);

            // A display name that is just the login (often the mobile number) is not a name.
            if ('' !== $name && !preg_match('/^[\d+\s]+$/', $name) && (!$user || $name !== $user->user_login)) {
                return $name;
            }
        }

        return 'ورسکالایی';
    }

    private function grant(int $row_id, int $user_id): void
    {
        $amount = (float) $this->config->get_int('active_promo_amount', 0);
        $days   = max(1, $this->config->get_int('active_promo_days', 3));
        $use    = (float) $this->config->get_int('active_promo_use_min', 0);
        $title  = $this->config->get_string('active_promo_title');
        $title  = '' !== $title ? $title : 'اعتبار هدیهٔ ورود به ورسکالا';

        if (1 !== vkl('wallet.promos')->grant_many([$user_id], $amount, $days, $title, $use, 'login-' . gmdate('Y-m'))) {
            $this->db->update('login_promos', ['status' => 'skipped', 'note' => 'ثبت اعتبار در پایگاه داده ناموفق بود.'], ['id' => $row_id]);

            return;
        }

        $reminders = max(0, min(10, $this->config->get_int('active_promo_reminders', 2)));
        $granted   = time();
        $name      = $this->name_of($user_id);
        $interval  = (int) floor($days * DAY_IN_SECONDS / ($reminders + 1));
        $mobile    = $this->mobile_of($user_id);
        $pattern   = $this->config->get_string('active_promo_pattern');
        $note      = '';

        if ('' === $mobile || '' === $pattern || !vkl()->has('sms.sender')) {
            $note = '' === $mobile ? 'اعتبار واریز شد؛ مشتری شمارهٔ موبایل ندارد.' : 'اعتبار واریز شد؛ کد پترن پیامک خالی است.';
        } elseif (!vkl('sms.sender')->send($mobile, 'promo.login', $pattern, [
            'name'   => $name,
            'amount' => (string) (int) $amount,
            'min'    => (string) (int) $use,
            'days'   => (string) $days,
        ])) {
            $note = 'اعتبار واریز شد؛ ارسال پیامک ناموفق بود (گزارش پیامک را ببینید).';
        }

        $this->db->update('login_promos', [
            'status'           => 'granted',
            'granted_at'       => gmdate('Y-m-d H:i:s', $granted),
            'expires_at'       => gmdate('Y-m-d H:i:s', $granted + $days * DAY_IN_SECONDS),
            'reminders_total'  => '' === $mobile || '' === $this->config->get_string('active_promo_remind_pattern') ? 0 : $reminders,
            'next_reminder_at' => gmdate('Y-m-d H:i:s', $granted + $interval),
            'note'             => $note,
        ], ['id' => $row_id]);
    }

    /**
     * @param array<string, mixed> $row Grant row.
     */
    private function remind(array $row): void
    {
        $id      = (int) $row['id'];
        $user_id = (int) $row['user_id'];
        $expires = (int) strtotime((string) $row['expires_at'] . ' UTC');
        $left    = $expires - time();

        // Unspent credit only: once it is used up or expired, stop for good.
        if ($left <= 0 || vkl('wallet.promos')->total_active($user_id) <= 0) {
            $this->db->update('login_promos', ['reminders_sent' => (int) $row['reminders_total']], ['id' => $id]);

            return;
        }

        $mobile  = $this->mobile_of($user_id);
        $pattern = $this->config->get_string('active_promo_remind_pattern');

        if ('' !== $mobile && '' !== $pattern && vkl()->has('sms.sender')) {
            vkl('sms.sender')->send($mobile, 'promo.login_remind', $pattern, [
                'name'   => $this->name_of($user_id),
                'amount' => (string) (int) vkl('wallet.promos')->total_active($user_id),
                'hours'  => (string) max(1, (int) ceil($left / HOUR_IN_SECONDS)),
            ]);
        }

        $total    = max(1, (int) $row['reminders_total']);
        $days     = max(1, (int) round(($expires - (int) strtotime((string) $row['granted_at'] . ' UTC')) / DAY_IN_SECONDS));
        $interval = (int) floor($days * DAY_IN_SECONDS / ($total + 1));

        $this->db->update('login_promos', [
            'reminders_sent'   => (int) $row['reminders_sent'] + 1,
            'next_reminder_at' => gmdate('Y-m-d H:i:s', time() + $interval),
        ], ['id' => $id]);
    }

    public function handle_save(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $int = static fn(string $k, int $min, int $max, int $def): int => isset($_POST[$k]) ? max($min, min($max, (int) $_POST[$k])) : $def;
        $txt = static fn(string $k): string => isset($_POST[$k]) ? sanitize_text_field(wp_unslash((string) $_POST[$k])) : '';

        $values = [
            'active_promo_enabled'        => empty($_POST['active_promo_enabled']) ? '0' : '1',
            'active_promo_delay_min'      => $int('active_promo_delay_min', 0, 10080, 60),
            'active_promo_amount'         => $int('active_promo_amount', 0, 1000000000, 0),
            'active_promo_days'           => $int('active_promo_days', 1, 365, 3),
            'active_promo_use_min'        => $int('active_promo_use_min', 0, 1000000000, 0),
            'active_promo_cooldown_days'  => $int('active_promo_cooldown_days', 1, 365, 1),
            'active_promo_reminders'      => $int('active_promo_reminders', 0, 10, 2),
            'active_promo_title'          => $txt('active_promo_title'),
            'active_promo_pattern'        => $txt('active_promo_pattern'),
            'active_promo_remind_pattern' => $txt('active_promo_remind_pattern'),
        ];
        // phpcs:enable

        foreach ($values as $key => $value) {
            update_option($this->config->option_name($key), $value);
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'vkl_section' => self::SECTION, 'vkl_saved_active' => 1], admin_url('admin.php')));
        exit;
    }

    private function field(string $key): string
    {
        return esc_attr($this->config->option_name($key));
    }

    /**
     * Render the whole section (called from the cart admin page).
     */
    public function render(): void
    {
        $online = get_users([
            'meta_key'     => self::META_SEEN,
            'meta_value'   => time() - self::ONLINE_SECS,
            'meta_compare' => '>=',
            'meta_type'    => 'NUMERIC',
            'orderby'      => 'meta_value_num',
            'order'        => 'DESC',
            'number'       => 100,
        ]);

        $stat = $this->db->get_results('SELECT status, COUNT(*) AS c FROM {login_promos} GROUP BY status');
        $cnt  = [];

        foreach ($stat as $s) {
            $cnt[(string) $s['status']] = (int) $s['c'];
        }

        $recent = $this->db->get_results('SELECT * FROM {login_promos} ORDER BY id DESC LIMIT 30');
        $labels = ['pending' => 'در انتظار واریز', 'granting' => 'در حال واریز', 'granted' => 'واریز شد', 'skipped' => 'ناموفق'];

        if (isset($_GET['vkl_saved_active'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات کاربران کارا ذخیره شد.</p></div>';
        }

        $cards = [
            ['🟢', 'هم‌اکنون آنلاین', count($online), '#157f3a'],
            ['⏳', 'در انتظار واریز', $cnt['pending'] ?? 0, '#9a6a10'],
            ['🎁', 'اعتبار واریزشده', $cnt['granted'] ?? 0, '#3b82c4'],
            ['⚠️', 'ناموفق', $cnt['skipped'] ?? 0, '#b32d2e'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:12px 0">
            <?php foreach ($cards as [$icon, $label, $value, $color]) : ?>
                <div style="background:#fff;border:1px solid #e2e2e2;border-radius:12px;padding:12px 16px">
                    <div style="font-size:12px;color:#666"><?php echo esc_html($icon . ' ' . $label); ?></div>
                    <div style="font-size:24px;font-weight:700;color:<?php echo esc_attr($color); ?>"><?php echo esc_html(number_format_i18n((int) $value)); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 class="title">اعتبار خودکار پس از ورود</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <?php $this->security->admin_nonce_field(); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">فعال‌سازی</th>
                    <td><label><input type="checkbox" name="active_promo_enabled" value="1" <?php checked($this->config->get_bool('active_promo_enabled', false)); ?>>
                        پس از ورود مشتری، اعتبار جشنواره به کیف پول او واریز شود.</label></td>
                </tr>
                <tr>
                    <th scope="row">فاصله تا واریز (دقیقه)</th>
                    <td><input type="number" min="0" max="10080" class="small-text" name="active_promo_delay_min" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_delay_min', 60)); ?>">
                        <p class="description">مثلاً ۶۰ = یک ساعت پس از ورود. دقت اجرا برابر با فاصلهٔ کرون سایت (حدود ۵ تا ۱۵ دقیقه) است.</p></td>
                </tr>
                <tr>
                    <th scope="row">مبلغ اعتبار (ریال/تومان مطابق واحد سایت)</th>
                    <td><input type="number" min="0" class="regular-text" name="active_promo_amount" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_amount', 0)); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">مهلت استفاده (روز)</th>
                    <td><input type="number" min="1" max="365" class="small-text" name="active_promo_days" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_days', 3)); ?>">
                        <p class="description">اعتبار استفاده‌نشده پس از این مدت خودکار می‌سوزد.</p></td>
                </tr>
                <tr>
                    <th scope="row">حداقل خرید برای مصرف</th>
                    <td><input type="number" min="0" class="regular-text" name="active_promo_use_min" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_use_min', 0)); ?>"> <span class="description">۰ = بدون شرط</span></td>
                </tr>
                <tr>
                    <th scope="row">دورهٔ تکرار (روز)</th>
                    <td><input type="number" min="1" max="365" class="small-text" name="active_promo_cooldown_days" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_cooldown_days', 1)); ?>">
                        <p class="description">هر مشتری در هر بازه به این تعداد روز فقط یک‌بار اعتبار ورود می‌گیرد، حتی اگر چندبار وارد شود.</p></td>
                </tr>
                <tr>
                    <th scope="row">تعداد پیامک یادآوری</th>
                    <td><input type="number" min="0" max="10" class="small-text" name="active_promo_reminders" value="<?php echo esc_attr((string) $this->config->get_int('active_promo_reminders', 2)); ?>">
                        <p class="description">این تعداد پیامک به‌طور مساوی در ساعت‌های باقی‌ماندهٔ مهلت پخش می‌شود. اگر مشتری اعتبار را مصرف کند، یادآوری‌ها متوقف می‌شوند. ۰ = بدون یادآوری.</p></td>
                </tr>
                <tr>
                    <th scope="row">عنوان اعتبار</th>
                    <td><input type="text" class="regular-text" name="active_promo_title" value="<?php echo esc_attr($this->config->get_string('active_promo_title')); ?>" placeholder="اعتبار هدیهٔ ورود به ورسکالا"></td>
                </tr>
                <tr>
                    <th scope="row">کد پترن پیامک واریز</th>
                    <td><input type="text" class="regular-text" dir="ltr" name="active_promo_pattern" value="<?php echo esc_attr($this->config->get_string('active_promo_pattern')); ?>">
                        <p class="description">متغیرها: name، amount، min، days</p></td>
                </tr>
                <tr>
                    <th scope="row">کد پترن پیامک یادآوری</th>
                    <td><input type="text" class="regular-text" dir="ltr" name="active_promo_remind_pattern" value="<?php echo esc_attr($this->config->get_string('active_promo_remind_pattern')); ?>">
                        <p class="description">متغیرها: name، amount (اعتبار باقی‌مانده)، hours (ساعت مانده تا پایان مهلت)</p></td>
                </tr>
            </table>
            <?php submit_button('ذخیرهٔ تنظیمات'); ?>
        </form>

        <h2 class="title">مشتریان آنلاین</h2>
        <p class="description">کاربرانی که در ۱۵ دقیقهٔ گذشته در سایت فعال بوده‌اند (حداکثر ۱۰۰ نفر).</p>
        <table class="widefat striped">
            <thead><tr><th>مشتری</th><th>موبایل</th><th>آخرین فعالیت</th><th>آخرین اعتبار ورود</th></tr></thead>
            <tbody>
            <?php if ([] === $online) : ?>
                <tr><td colspan="4">اکنون کاربر واردشده‌ای نیست.</td></tr>
            <?php endif; ?>
            <?php foreach ($online as $u) :
                $last = $this->db->get_row('SELECT status, granted_at FROM {login_promos} WHERE user_id = %d ORDER BY id DESC LIMIT 1', (int) $u->ID);
                $seen = (int) get_user_meta($u->ID, self::META_SEEN, true);
                ?>
                <tr>
                    <td><?php echo esc_html($u->display_name); ?></td>
                    <td dir="ltr"><?php echo esc_html($this->security->mask_mobile($this->mobile_of((int) $u->ID))); ?></td>
                    <td><?php echo esc_html(sprintf('%s پیش', human_time_diff($seen, time()))); ?></td>
                    <td><?php echo esc_html(null === $last ? '—' : ($labels[(string) $last['status']] ?? (string) $last['status'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="title">آخرین اعتبارهای ورود</h2>
        <table class="widefat striped">
            <thead><tr><th>مشتری</th><th>ورود (UTC)</th><th>موعد واریز (UTC)</th><th>وضعیت</th><th>مبلغ</th><th>یادآوری</th><th>توضیح</th></tr></thead>
            <tbody>
            <?php if ([] === $recent) : ?>
                <tr><td colspan="7">هنوز موردی ثبت نشده است.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $r) :
                $ru = get_userdata((int) $r['user_id']);
                ?>
                <tr>
                    <td><?php echo esc_html($ru ? $ru->display_name : ('#' . (int) $r['user_id'])); ?></td>
                    <td><?php echo esc_html((string) $r['login_at']); ?></td>
                    <td><?php echo esc_html((string) $r['due_at']); ?></td>
                    <td><?php echo esc_html($labels[(string) $r['status']] ?? (string) $r['status']); ?></td>
                    <td><?php echo esc_html(number_format_i18n((float) $r['amount'])); ?></td>
                    <td><?php echo esc_html((int) $r['reminders_sent'] . ' / ' . (int) $r['reminders_total']); ?></td>
                    <td><?php echo esc_html((string) $r['note']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
