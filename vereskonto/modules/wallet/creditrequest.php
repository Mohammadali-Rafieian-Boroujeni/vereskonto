<?php
/**
 * Installment-credit requests (درخواست خرید اقساطی).
 *
 * Customers apply for purchase credit from their Wallet tab: requested
 * amount, preferred installment count, acceptance of the shop's terms
 * (required checkbox, timestamped), and the national ID card (front and
 * back) uploaded as files. The admin reviews the application on the
 * Wallet screen, opens the documents (capability-gated streaming — the
 * folder itself is blocked from the web), and approves (which creates
 * the purchase-credit plan) or rejects with a note.
 *
 * Documents requested: national ID card front + back only. Proof of
 * income and a guarantee cheque/promissory note used to be required as
 * well; the shop chose to keep the application to identity documents
 * only.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.15.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Credit_Requests
 */
final class VKL_Credit_Requests extends VKL_Repository
{
    private const NONCE = 'vkl_credit_request';

    /**
     * Allowed upload types.
     */
    private const MIMES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    /**
     * Max size per file (bytes).
     */
    private const MAX_SIZE = 5242880;

    /**
     * Document slots: key => label callback (resolved at render time).
     * Only the national ID card — front and back are the two sides of
     * the SAME document, not two separate ones. Proof of income and a
     * guarantee cheque/promissory note used to be required too; dropped
     * on the shop's decision to keep the application to identity only.
     *
     * @return array<string, string>
     */
    public static function doc_slots(): array
    {
        return [
            'id_front' => 'تصویر کارت ملی — روی کارت',
            'id_back'  => 'تصویر کارت ملی — پشت کارت',
        ];
    }

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Installments repository.
     */
    private VKL_Wallet_Installments $installments;

    /**
     * Security helper.
     */
    private VKL_Security $security;

    /**
     * @param VKL_Database            $db           Database.
     * @param VKL_Config              $config       Config.
     * @param VKL_Wallet_Installments $installments Installments.
     * @param VKL_Security            $security     Security helper.
     */
    public function __construct(VKL_Database $db, VKL_Config $config, VKL_Wallet_Installments $installments, VKL_Security $security)
    {
        parent::__construct($db);
        $this->config       = $config;
        $this->installments = $installments;
        $this->security     = $security;
    }

    /**
     * Table base name.
     */
    protected function table_name(): string
    {
        return 'credit_requests';
    }

    /**
     * Register schema.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.15.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_credit_requests (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    amount DECIMAL(18,4) NOT NULL,
                    months INT UNSIGNED NOT NULL DEFAULT 4,
                    status VARCHAR(12) NOT NULL DEFAULT 'pending',
                    docs TEXT NOT NULL,
                    note TEXT NOT NULL,
                    terms_at DATETIME NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY status (status)
                ) {$charset};"
            );
        });
    }

    /**
     * Register hooks (frontend + admin actions).
     */
    public function register(): void
    {
        add_action('vkl_wallet_account_render_credit', [$this, 'render_customer'], 15);
        add_action('template_redirect', [$this, 'handle_submit']);
        add_action('admin_post_vkl_credit_doc', [$this, 'stream_doc']);
        add_action('admin_post_vkl_credit_decide', [$this, 'handle_decide']);
        add_action('wp_ajax_vkl_cr_identity', [$this, 'ajax_identity']);
    }

    /** Most mobile/national-ID inquiries a customer may start per hour. */
    private const IDENTITY_CHECKS_PER_HOUR = 6;

    /**
     * Iranian IBAN (Sheba): IR + 24 digits with a valid ISO 13616 mod-97
     * check, so a mistyped digit is caught before the shop sees it.
     */
    public static function is_valid_iban(string $iban): bool
    {
        $iban = self::normalize_iban($iban);

        if (!preg_match('/^IR\d{24}$/', $iban)) {
            return false;
        }

        // Move "IR" + check digits to the end, letters → numbers (I=18, R=27).
        $digits = substr($iban, 4) . '1827' . substr($iban, 2, 2);
        $mod    = 0;

        foreach (str_split($digits) as $d) {
            $mod = ($mod * 10 + (int) $d) % 97;
        }

        return 1 === $mod;
    }

    /**
     * Upper-case, Latin digits, no spaces; "IR" added when only digits.
     */
    public static function normalize_iban(string $raw): string
    {
        $raw = strtr($raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $raw = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $raw));

        return preg_match('/^\d{24}$/', $raw) ? 'IR' . $raw : $raw;
    }

    /**
     * Profile name parts used by the credit file.
     *
     * @return array{0: string, 1: string}
     */
    private static function profile_name(int $user_id): array
    {
        $first = trim((string) (get_user_meta($user_id, 'billing_first_name', true) ?: get_user_meta($user_id, 'first_name', true)));
        $last  = trim((string) (get_user_meta($user_id, 'billing_last_name', true) ?: get_user_meta($user_id, 'last_name', true)));

        return [$first, $last];
    }

    /**
     * AJAX: does the customer's mobile belong to the owner of the national
     * ID typed on the request form? The (checksum-valid) code is saved to
     * the profile — the same field "Account details" edits — so the
     * verdict and the stored identity never disagree.
     */
    public function ajax_identity(): void
    {
        check_ajax_referer('vkl_cr_identity', 'nonce');

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            wp_send_json_error(['message' => 'ابتدا وارد حساب خود شوید.'], 403);
        }

        $national = VKL_Wc_Account::normalize_national_id((string) wp_unslash($_POST['national_id'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if (!VKL_Wc_Account::is_valid_national_id($national)) {
            wp_send_json_error(['status' => 'invalid', 'message' => 'کد ملی معتبر نیست؛ ارقام را بررسی کنید.']);
        }

        if (!class_exists('VKL_Identity') || !VKL_Identity::enabled()) {
            update_user_meta($user_id, 'vkl_national_id', $national);
            wp_send_json_success(['status' => 'off', 'message' => '']);
        }

        // A pair already answered is free; a new pair costs one inquiry.
        $mobile = (string) get_user_meta($user_id, 'billing_phone', true);
        $hash   = md5((function_exists('vkl_normalize_mobile') ? vkl_normalize_mobile($mobile) : $mobile) . '|' . $national);
        $known  = $hash === (string) get_user_meta($user_id, VKL_Identity::META_HASH, true)
            && in_array(VKL_Identity::stored_status($user_id), ['match', 'mismatch'], true);

        if (!$known) {
            $key   = 'vkl_idchk_' . $user_id;
            $count = (int) get_transient($key);

            if ($count >= self::IDENTITY_CHECKS_PER_HOUR) {
                wp_send_json_error(['status' => 'limited', 'message' => 'تعداد استعلام‌ها زیاد شده است؛ یک ساعت دیگر دوباره تلاش کنید.']);
            }

            set_transient($key, $count + 1, HOUR_IN_SECONDS);
        }

        update_user_meta($user_id, 'vkl_national_id', $national);
        $verdict = VKL_Identity::verify_user($user_id);

        wp_send_json_success(['status' => $verdict['status'], 'message' => self::identity_text($verdict['status'])]);
    }

    /**
     * Customer-facing sentence for a matching verdict.
     */
    private static function identity_text(string $status): string
    {
        switch ($status) {
            case 'match':
                return '✓ شمارهٔ همراه شما به نام صاحب این کد ملی ثبت شده است.';
            case 'mismatch':
                return '✗ شمارهٔ همراه شما به نام صاحب این کد ملی نیست. شماره باید به نام خود متقاضی باشد.';
            case 'error':
                return '؟ سرویس استعلام فعلاً پاسخ نمی‌دهد؛ چند دقیقهٔ دیگر دوباره بررسی کنید.';
            default:
                return '';
        }
    }

    /**
     * Latest request of a user (any status).
     *
     * @return array<string, mixed>|null
     */
    public function latest_for_user(int $user_id): ?array
    {
        return $this->db->get_row(
            'SELECT * FROM {credit_requests} WHERE user_id = %d ORDER BY id DESC LIMIT 1',
            $user_id
        );
    }

    /**
     * Pending requests (admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(int $limit = 20): array
    {
        return $this->db->get_results(
            "SELECT * FROM {credit_requests} WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
            $limit
        );
    }

    /**
     * Default terms & conditions (filterable via the settings textarea).
     */
    public function terms(): string
    {
        $custom = $this->config->get_string('wallet_credit_terms');

        if ('' !== $custom) {
            return $custom;
        }

        return implode("\n", [
            '۱. اعطای اعتبار خرید اقساطی منوط به احراز هویت با کارت ملی و بررسی و تأیید فروشگاه است؛ ثبت درخواست هیچ تعهدی برای تأیید آن ایجاد نمی‌کند.',
            '۲. سقف نهایی اعتبار، تعداد اقساط و روز سررسید ماهانه را فروشگاه در زمان تأیید تعیین می‌کند و پیش از فعال شدن اعتبار به اطلاع مشتری می‌رساند.',
            '۳. بازپرداخت اقساط از همان لحظه‌ای که اعتبار در یک خرید مصرف می‌شود آغاز می‌شود؛ هر قسط در روز سررسید تعیین‌شده از همان ماه محاسبه و از طریق درگاه پرداخت شارژ می‌شود.',
            '۴. تأخیر در پرداخت هر قسط مشمول جریمهٔ دیرکرد طبق نرخ اعلام‌شدهٔ فروشگاه می‌شود؛ تا زمان تسویهٔ کامل تأخیر، امکان استفاده از باقیماندهٔ اعتبار یا ثبت درخواست اعتبار جدید غیرفعال می‌ماند.',
            '۵. سفارش‌هایی که با اعتبار اقساطی پرداخت می‌شوند در همان سفارش قابل ترکیب با موجودی کیف پول یا اعتبار جشنواره نیستند.',
            '۶. تصویر کارت ملی ارائه‌شده صرفاً برای احراز هویت و بررسی درخواست استفاده، به‌صورت محرمانه نگهداری و تنها در دسترس مدیریت فروشگاه است؛ پس از تصمیم‌گیری دربارهٔ درخواست، بدون درخواست جداگانه حذف نمی‌شود.',
            '۷. تسویهٔ زودهنگام تمام یا بخشی از بدهی، در هر زمان و بدون جریمه، از تب کیف پول امکان‌پذیر است.',
            '۸. فروشگاه در تأیید، رد یا تعیین سقف نهاییِ کمتر از مبلغ درخواستی — با در نظر گرفتن سابقهٔ خرید و بازپرداخت مشتری — مختار است.',
        ]);
    }

    /**
     * Customer block inside the wallet tab.
     */
    public function render_customer(): void
    {
        $user_id = get_current_user_id();
        $credit  = $this->installments->available_credit($user_id);

        if ($credit > 0) {
            // Visible confirmation that credit exists — also the fastest way
            // to see why the checkout method would or would not show.
            echo '<p><strong>' . esc_html__('Your installment credit:', 'vereskala-login') . '</strong> '
                . wp_kses_post(wc_price($credit)) . ' — '
                . esc_html__('choose "Installment purchase" as the payment method at checkout to use it. Repayment installments start only after you use the credit.', 'vereskala-login')
                . '</p>';

            return;
        }

        $request = $this->latest_for_user($user_id);

        if (null !== $request && 'pending' === (string) $request['status']) {
            echo '<div style="border:1px solid #f5a623;background:#fdf8ef;border-radius:10px;padding:12px 16px;margin:12px 0">'
                . esc_html__('Your installment-credit request is under review. We will inform you of the result.', 'vereskala-login')
                . '</div>';

            return;
        }
        ?>
        <h3><?php esc_html_e('Request installment credit', 'vereskala-login'); ?></h3>
        <?php if (null !== $request && 'rejected' === (string) $request['status']) : ?>
            <div style="border:1px solid #d63638;background:#fdf0f0;border-radius:10px;padding:12px 16px;margin:12px 0">
                <?php esc_html_e('Your previous request was not approved.', 'vereskala-login'); ?>
                <?php if ('' !== (string) $request['note']) : ?>
                    <br><?php echo esc_html((string) $request['note']); ?>
                <?php endif; ?>
                <br><?php esc_html_e('You may submit a new request below.', 'vereskala-login'); ?>
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="border:1px solid #e2e2e2;border-radius:10px;padding:14px 16px">
            <p style="display:flex;gap:14px;flex-wrap:wrap">
                <label><?php esc_html_e('Requested credit amount', 'vereskala-login'); ?><br>
                    <input type="number" min="1" step="1" name="vkl_cr_amount" id="vkl_cr_amount" required style="width:160px"
                           max="<?php echo esc_attr((string) max(1, $this->config->get_int('credit_max_amount', 10000000))); ?>"></label>
                <span class="description" style="align-self:end">
                    <?php
                    printf(
                        /* translators: %s: maximum credit amount. */
                        esc_html__('Maximum: %s', 'vereskala-login'),
                        esc_html(function_exists('wc_price') ? wp_strip_all_tags(wc_price(max(1, $this->config->get_int('credit_max_amount', 10000000)))) : number_format(10000000))
                    );
                    ?>
                </span>
                <label><?php esc_html_e('Preferred number of installments', 'vereskala-login'); ?><br>
                    <select name="vkl_cr_months">
                        <?php foreach ([3, 4, 6, 9, 12] as $months) : ?>
                            <option value="<?php echo (int) $months; ?>" <?php selected(4, $months); ?>><?php echo esc_html(number_format_i18n($months)); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            </p>

            <?php $this->render_identity_fields($user_id); ?>

            <p style="font-weight:600;margin-bottom:4px">مدارک هویتی</p>
            <p class="description" style="margin-top:0">تصویر کارت ملی — JPG، PNG یا PDF، حداکثر ۵ مگابایت هر فایل. مدارک محرمانه نگهداری می‌شوند و فقط مدیریت فروشگاه به آن‌ها دسترسی دارد.</p>
            <?php foreach (self::doc_slots() as $slot => $label) : ?>
                <p>
                    <label style="display:block"><?php echo esc_html($label); ?></label>
                    <input type="file" name="vkl_doc_<?php echo esc_attr($slot); ?>" accept=".jpg,.jpeg,.png,.pdf" required>
                </p>
            <?php endforeach; ?>

            <p style="font-weight:600;margin-bottom:4px"><?php esc_html_e('Terms and conditions', 'vereskala-login'); ?></p>
            <div style="border:1px solid #e2e2e2;border-radius:8px;padding:10px 12px;max-height:180px;overflow-y:auto;background:#fafafa;white-space:pre-line;font-size:.92em;line-height:1.9"><?php echo esc_html($this->terms()); ?></div>
            <p>
                <label>
                    <input type="checkbox" name="vkl_cr_terms" value="1" required>
                    <?php esc_html_e('I have read and accept the installment purchase terms and conditions.', 'vereskala-login'); ?>
                </label>
            </p>

            <?php wp_nonce_field(self::NONCE, '_vkl_cr_nonce'); ?>
            <button type="submit" name="vkl_cr_submit" value="1" class="button"><?php esc_html_e('Submit credit request', 'vereskala-login'); ?></button>
        </form>
        <?php
    }

    /**
     * Applicant identity on the request form itself: name, mobile,
     * national ID with the mobile-ownership check, and IBAN. Before
     * 2.22.0 the form only said "save them in Account details first",
     * so customers left the page halfway through applying.
     */
    private function render_identity_fields(int $user_id): void
    {
        [$first, $last] = self::profile_name($user_id);
        $mobile   = (string) get_user_meta($user_id, 'billing_phone', true);
        $national = (string) get_user_meta($user_id, 'vkl_national_id', true);
        $iban     = (string) get_user_meta($user_id, 'vkl_iban', true);
        $id_on    = class_exists('VKL_Identity') && VKL_Identity::enabled();
        $require  = $id_on && 'require' === VKL_Identity::mode();
        $state    = $id_on ? VKL_Identity::stored_status($user_id) : '';
        $fresh    = $id_on && '' !== $national && 'none' !== $state && '' !== $state
            && md5((function_exists('vkl_normalize_mobile') ? vkl_normalize_mobile($mobile) : $mobile) . '|' . $national) === (string) get_user_meta($user_id, VKL_Identity::META_HASH, true);
        $config   = [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('vkl_cr_identity'),
            'enabled' => $id_on,
            'require' => $require,
            'state'   => $fresh ? $state : '',
        ];
        ?>
        <fieldset class="vkl-cr-identity">
            <legend>اطلاعات متقاضی</legend>
            <p class="description" style="margin-top:0">این اطلاعات در پروندهٔ حساب شما ذخیره می‌شود و در «جزئیات حساب» هم همین‌ها را می‌بینید.</p>
            <div class="vkl-cr-grid">
                <label>نام<br><input type="text" name="vkl_cr_first" value="<?php echo esc_attr($first); ?>" required autocomplete="given-name"></label>
                <label>نام خانوادگی<br><input type="text" name="vkl_cr_last" value="<?php echo esc_attr($last); ?>" required autocomplete="family-name"></label>
                <label>شمارهٔ همراه<br>
                    <input type="tel" dir="ltr" value="<?php echo esc_attr($mobile); ?>" readonly aria-describedby="vkl-cr-mobile-hint">
                    <small id="vkl-cr-mobile-hint"><?php echo '' === $mobile ? 'شماره ثبت نشده است — ' : 'برای تغییر: '; ?><a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-account')); ?>">جزئیات حساب</a></small>
                </label>
                <label>کد ملی (۱۰ رقم)<br>
                    <input type="text" name="vkl_cr_national" id="vkl_cr_national" dir="ltr" maxlength="10" inputmode="numeric" autocomplete="off" required value="<?php echo esc_attr($national); ?>" aria-describedby="vkl_cr_national_msg vkl_cr_identity_msg">
                    <small id="vkl_cr_national_msg"></small>
                </label>
                <label class="vkl-cr-wide">شمارهٔ شبای حساب متقاضی<br>
                    <input type="text" name="vkl_cr_iban" id="vkl_cr_iban" dir="ltr" maxlength="34" required placeholder="IR000000000000000000000000" value="<?php echo esc_attr($iban); ?>" aria-describedby="vkl_cr_iban_msg">
                    <small id="vkl_cr_iban_msg"></small>
                </label>
            </div>
            <?php if ($id_on) : ?>
                <div class="vkl-cr-match" id="vkl_cr_identity_box" aria-live="polite">
                    <strong>تطبیق شمارهٔ همراه با کد ملی</strong>
                    <span id="vkl_cr_identity_msg"><?php echo esc_html($fresh ? self::identity_text($state) : 'پس از وارد کردن کد ملی، بررسی می‌شود که شمارهٔ همراه به نام صاحب آن باشد.'); ?></span>
                    <button type="button" class="button" id="vkl_cr_identity_btn">بررسی تطبیق</button>
                    <?php if ($require) : ?><small>درخواست فقط وقتی پذیرفته می‌شود که تطبیق تأیید شود.</small><?php endif; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <style>
            .vkl-cr-identity{border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px 14px;margin:6px 0 14px}
            .vkl-cr-identity legend{font-weight:600;padding:0 6px}
            .vkl-cr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px}
            .vkl-cr-grid label{display:block}
            .vkl-cr-grid input{width:100%;box-sizing:border-box}
            .vkl-cr-grid input[readonly]{background:#f6f7f7}
            .vkl-cr-grid small{display:block;margin-top:3px;min-height:1em}
            .vkl-cr-wide{grid-column:1/-1}
            .vkl-cr-match{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center;margin-top:12px;padding:10px 12px;border-radius:8px;background:#f6f7f7;border:1px solid #e2e2e2}
            .vkl-cr-match.is-match{background:#edf7f0;border-color:#b5dcc2;color:#0f5d2b}
            .vkl-cr-match.is-mismatch{background:#fdf0f0;border-color:#f0c4c4;color:#8a1f1f}
            .vkl-cr-match.is-error{background:#fdf8ef;border-color:#f0d9a8;color:#7a4b00}
            .vkl-cr-match small{flex-basis:100%;color:#50575e}
            .vkl-ok{color:#157f3a}.vkl-bad{color:#b32d2e}
        </style>
        <script>
        (function () {
            var cfg = <?php echo wp_json_encode($config); ?>;
            var nat = document.getElementById('vkl_cr_national'), natMsg = document.getElementById('vkl_cr_national_msg');
            var iban = document.getElementById('vkl_cr_iban'), ibanMsg = document.getElementById('vkl_cr_iban_msg');
            var box = document.getElementById('vkl_cr_identity_box'), idMsg = document.getElementById('vkl_cr_identity_msg'), btn = document.getElementById('vkl_cr_identity_btn');
            var form = nat ? nat.form : null, submit = form ? form.querySelector('[name="vkl_cr_submit"]') : null;
            var state = cfg.state, checked = state ? nat.value : '';
            if (!nat || !iban) { return; }
            function latin(s) { return s.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; }).replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; }); }
            function nationalOk(c) {
                if (!/^\d{10}$/.test(c) || /^(\d)\1{9}$/.test(c)) { return false; }
                var s = 0, i; for (i = 0; i < 9; i++) { s += +c[i] * (10 - i); }
                var r = s % 11; return r < 2 ? +c[9] === r : +c[9] === 11 - r;
            }
            function ibanOk(v) {
                if (!/^IR\d{24}$/.test(v)) { return false; }
                var d = v.slice(4) + '1827' + v.slice(2, 4), m = 0, i;
                for (i = 0; i < d.length; i++) { m = (m * 10 + +d[i]) % 97; }
                return m === 1;
            }
            function say(el, ok, text) { el.textContent = text; el.className = ok === null ? '' : (ok ? 'vkl-ok' : 'vkl-bad'); }
            function setState(s, text) {
                state = s;
                if (box) { box.className = 'vkl-cr-match' + (s ? ' is-' + s : ''); }
                if (idMsg && text) { idMsg.textContent = text; }
                gate();
            }
            function gate() {
                if (!submit || !cfg.require) { return; }
                var ready = state === 'match' && checked === nat.value;
                submit.disabled = !ready;
                submit.title = ready ? '' : 'ابتدا تطبیق شمارهٔ همراه با کد ملی باید تأیید شود.';
            }
            function checkNational() {
                var c = latin(nat.value).replace(/\D/g, '');
                if (nat.value !== c) { nat.value = c; }
                if (c === '') { say(natMsg, null, ''); nat.setCustomValidity(''); }
                else if (c.length < 10) { say(natMsg, null, ''); nat.setCustomValidity('کد ملی باید ۱۰ رقم باشد.'); }
                else { var ok = nationalOk(c); say(natMsg, ok, ok ? '✓ کد ملی معتبر است' : '✗ کد ملی معتبر نیست؛ ارقام را بررسی کنید'); nat.setCustomValidity(ok ? '' : 'کد ملی معتبر نیست.'); }
                if (checked && checked !== c) { setState('', 'کد ملی تغییر کرد؛ دوباره بررسی کنید.'); checked = ''; }
                return c.length === 10 && nationalOk(c);
            }
            function checkIban() {
                var v = latin(iban.value).toUpperCase().replace(/[^0-9A-Z]/g, '');
                if (/^\d{24}$/.test(v)) { v = 'IR' + v; }
                if (iban.value !== v) { iban.value = v; }
                if (v === '') { say(ibanMsg, null, ''); iban.setCustomValidity(''); return; }
                if (v.length < 26) { say(ibanMsg, null, ''); iban.setCustomValidity('شبا باید IR و ۲۴ رقم باشد.'); return; }
                var ok = ibanOk(v);
                say(ibanMsg, ok, ok ? '✓ شمارهٔ شبا معتبر است' : '✗ شمارهٔ شبا معتبر نیست؛ ارقام را بررسی کنید');
                iban.setCustomValidity(ok ? '' : 'شمارهٔ شبا معتبر نیست.');
            }
            function inquire() {
                if (!cfg.enabled || !checkNational()) { return; }
                var value = nat.value, body = new FormData();
                body.append('action', 'vkl_cr_identity'); body.append('nonce', cfg.nonce); body.append('national_id', value);
                btn.disabled = true; setState('', 'در حال بررسی…');
                fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        var d = r && r.data ? r.data : {};
                        if (value !== nat.value) { return; }
                        checked = r && r.success ? value : '';
                        setState(r && r.success && d.status !== 'none' ? d.status : (d.status === 'limited' ? 'error' : ''), d.message || 'بررسی ممکن نشد؛ دوباره تلاش کنید.');
                    })
                    .catch(function () { setState('error', 'ارتباط برقرار نشد؛ دوباره تلاش کنید.'); })
                    .then(function () { btn.disabled = false; });
            }
            nat.addEventListener('input', function () { if (checkNational() && cfg.enabled && checked !== nat.value) { inquire(); } });
            iban.addEventListener('input', checkIban);
            if (btn) { btn.addEventListener('click', inquire); }
            checkNational(); checkIban(); setState(state, '');
        })();
        </script>
        <?php
    }

    /**
     * Handle the customer submission.
     */
    public function handle_submit(): void
    {
        if (!is_user_logged_in()
            || !isset($_POST['vkl_cr_submit'], $_POST['_vkl_cr_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_cr_nonce'])), self::NONCE)
        ) {
            return;
        }

        $user_id = get_current_user_id();

        // One open request at a time.
        $existing = $this->latest_for_user($user_id);

        if (null !== $existing && 'pending' === (string) $existing['status']) {
            return;
        }

        $amount = isset($_POST['vkl_cr_amount']) ? (float) $_POST['vkl_cr_amount'] : 0.0;
        $months = isset($_POST['vkl_cr_months']) ? absint($_POST['vkl_cr_months']) : 4;
        $terms  = isset($_POST['vkl_cr_terms']) && '1' === (string) $_POST['vkl_cr_terms'];

        if ($amount <= 0 || !$terms) {
            wc_add_notice(__('Please fill in the amount and accept the terms.', 'vereskala-login'), 'error');

            return;
        }

        // A credit file needs a complete identity: name, mobile, a
        // checksum-valid national ID and the applicant's own IBAN. Since
        // 2.22.0 they are filled in on this form; they are stored in the
        // same profile fields "Account details" edits (one source of truth).
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $posted_first = isset($_POST['vkl_cr_first']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_cr_first'])) : null;
        $posted_last  = isset($_POST['vkl_cr_last']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_cr_last'])) : null;
        $national     = isset($_POST['vkl_cr_national']) ? VKL_Wc_Account::normalize_national_id((string) wp_unslash($_POST['vkl_cr_national'])) : (string) get_user_meta($user_id, 'vkl_national_id', true);
        $iban         = isset($_POST['vkl_cr_iban']) ? self::normalize_iban((string) wp_unslash($_POST['vkl_cr_iban'])) : (string) get_user_meta($user_id, 'vkl_iban', true);
        // phpcs:enable

        foreach (['first' => $posted_first, 'last' => $posted_last] as $part => $value) {
            if (null !== $value && '' !== trim($value)) {
                update_user_meta($user_id, 'billing_' . $part . '_name', trim($value));

                if ('' === trim((string) get_user_meta($user_id, $part . '_name', true))) {
                    update_user_meta($user_id, $part . '_name', trim($value));
                }
            }
        }

        [$first, $last] = self::profile_name($user_id);
        $phone          = trim((string) get_user_meta($user_id, 'billing_phone', true));

        if ('' === $first || '' === $last) {
            wc_add_notice('نام و نام خانوادگی متقاضی را وارد کنید.', 'error');

            return;
        }

        if ('' === $phone) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: account details URL. */
                    __('Please complete your first name, last name and mobile in Account details first: %s', 'vereskala-login'),
                    esc_url(wc_get_account_endpoint_url('edit-account'))
                ),
                'error'
            );

            return;
        }

        if (!VKL_Wc_Account::is_valid_national_id($national)) {
            wc_add_notice('کد ملی واردشده معتبر نیست؛ لطفاً ارقام را دوباره بررسی کنید.', 'error');

            return;
        }

        if (!self::is_valid_iban($iban)) {
            wc_add_notice('شمارهٔ شبا معتبر نیست. شبا با IR و ۲۴ رقم نوشته می‌شود و باید به نام خود متقاضی باشد.', 'error');

            return;
        }

        update_user_meta($user_id, 'vkl_national_id', $national);
        update_user_meta($user_id, 'vkl_iban', $iban);

        // When the shop requires it, the mobile must be registered in the
        // name of the owner of the national ID (inquiry service).
        if (class_exists('VKL_Identity') && 'require' === VKL_Identity::mode() && VKL_Identity::enabled()) {
            $verdict = VKL_Identity::verify_user($user_id)['status'];

            if ('match' !== $verdict) {
                wc_add_notice(
                    'mismatch' === $verdict
                        ? 'شمارهٔ همراه شما به نام صاحب این کد ملی ثبت نشده است؛ درخواست اعتبار فقط با شمارهٔ ثبت‌شده به نام خودتان پذیرفته می‌شود. کد ملی را در همین فرم یا شمارهٔ همراه را در «جزئیات حساب» اصلاح کنید.'
                        : 'در حال حاضر تطبیق شمارهٔ همراه با کد ملی ممکن نیست. چند دقیقهٔ دیگر دوباره تلاش کنید.',
                    'error'
                );

                return;
            }
        }

        // Configurable ceiling (default 100 million).
        $max_credit = max(1, (float) $this->config->get_int('credit_max_amount', 10000000));

        if ($amount > $max_credit) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: maximum credit amount. */
                    __('The requested credit may not exceed %s.', 'vereskala-login'),
                    function_exists('wc_price') ? wp_strip_all_tags(wc_price($max_credit)) : number_format($max_credit)
                ),
                'error'
            );

            return;
        }

        $docs = [];

        foreach (array_keys(self::doc_slots()) as $slot) {
            $stored = $this->store_upload('vkl_doc_' . $slot, $user_id);

            if ('' === $stored) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: document label. */
                        __('Upload failed for: %s (JPG/PNG/PDF, max 5 MB).', 'vereskala-login'),
                        self::doc_slots()[$slot]
                    ),
                    'error'
                );

                return;
            }

            $docs[$slot] = $stored;
        }

        $this->insert_row([
            'user_id'    => $user_id,
            'amount'     => $amount,
            'months'     => max(1, min(36, $months)),
            'status'     => 'pending',
            'docs'       => (string) wp_json_encode($docs),
            'note'       => '',
            'terms_at'   => $this->now(),
            'created_at' => $this->now(),
        ]);

        // Tell the admin immediately — a credit request is time-sensitive.
        $admin_mobile  = $this->config->get_string('admin_mobile');
        $admin_pattern = $this->config->get_string('admin_pattern_credit_request');

        if ('' !== $admin_mobile && '' !== $admin_pattern) {
            vkl('sms.sender')->send($admin_mobile, 'admin.credit_request', $admin_pattern, [
                'name'   => vkl('sms.sender')->resolve_name($user_id),
                'amount' => number_format($amount),
            ]);
        }

        vkl_dispatch('credit_request.created', ['user_id' => $user_id, 'amount' => $amount]);

        wc_add_notice(__('Your credit request and documents were received. We will review them and get back to you.', 'vereskala-login'), 'success');
        wp_safe_redirect(wc_get_account_endpoint_url(VKL_Wallet_Account::ENDPOINT));
        exit;
    }

    /**
     * Store one uploaded document in the protected folder.
     *
     * @return string Relative path inside uploads ('' on failure).
     */
    private function store_upload(string $field, int $user_id): string
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || UPLOAD_ERR_OK !== (int) ($_FILES[$field]['error'] ?? 1)) {
            return '';
        }

        if ((int) ($_FILES[$field]['size'] ?? 0) > self::MAX_SIZE) {
            return '';
        }

        $check = wp_check_filetype_and_ext(
            (string) $_FILES[$field]['tmp_name'],
            (string) $_FILES[$field]['name'],
            self::MIMES
        );

        if (empty($check['ext']) || empty($check['type'])) {
            return '';
        }

        $uploads = wp_get_upload_dir();
        $subdir  = 'vereskonto-docs';
        $dir     = trailingslashit($uploads['basedir']) . $subdir;

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Block direct web access; files are streamed via admin-post.
            file_put_contents($dir . '/.htaccess', "Deny from all\n"); // phpcs:ignore
            file_put_contents($dir . '/index.php', "<?php // Silence.\n"); // phpcs:ignore
        }

        $name = sprintf('%d-%s-%s.%s', $user_id, gmdate('Ymd'), strtolower(wp_generate_password(10, false, false)), $check['ext']);

        if (!move_uploaded_file((string) $_FILES[$field]['tmp_name'], $dir . '/' . $name)) { // phpcs:ignore
            return '';
        }

        return $subdir . '/' . $name;
    }

    /**
     * Stream a document to an administrator.
     */
    public function stream_doc(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)
            || !isset($_GET['file'], $_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])), 'vkl_credit_doc')
        ) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $relative = sanitize_text_field(wp_unslash((string) $_GET['file']));

        // Whitelist: only our folder, no traversal.
        if (!preg_match('#^vereskonto-docs/[A-Za-z0-9\-]+\.(jpg|jpeg|png|pdf)$#', $relative)) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $path = trailingslashit(wp_get_upload_dir()['basedir']) . $relative;

        if (!is_file($path)) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        nocache_headers();
        header('Content-Type: ' . (string) (self::MIMES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename=' . basename($path));
        readfile($path); // phpcs:ignore
        exit;
    }

    /**
     * Approve / reject (admin-post).
     */
    public function handle_decide(): void
    {
        $this->security->verify_admin_request();

        $request_id = isset($_POST['vkl_request_id']) ? absint($_POST['vkl_request_id']) : 0;
        $decision   = isset($_POST['vkl_decision']) ? sanitize_key((string) $_POST['vkl_decision']) : '';
        $note       = isset($_POST['vkl_note']) ? sanitize_textarea_field(wp_unslash((string) $_POST['vkl_note'])) : '';

        $request = $this->db->get_row('SELECT * FROM {credit_requests} WHERE id = %d', $request_id);

        if (null === $request || 'pending' !== (string) $request['status'] || !in_array($decision, ['approve', 'reject'], true)) {
            wp_safe_redirect(admin_url('admin.php?page=vkl-credit-customers&view=requests'));
            exit;
        }

        if ('approve' === $decision) {
            $amount = isset($_POST['vkl_amount']) ? (float) $_POST['vkl_amount'] : (float) $request['amount'];
            $count  = isset($_POST['vkl_count']) ? max(1, absint($_POST['vkl_count'])) : (int) $request['months'];
            $due    = isset($_POST['vkl_due_day']) ? absint($_POST['vkl_due_day']) : 5;

            $this->installments->create(
                (int) $request['user_id'],
                sprintf(
                    /* translators: %d: request id. */
                    __('Purchase credit (request #%d)', 'vereskala-login'),
                    $request_id
                ),
                $amount,
                $count,
                $due,
                'credit'
            );
        }

        $this->db->update(
            'credit_requests',
            ['status' => 'approve' === $decision ? 'approved' : 'rejected', 'note' => $note],
            ['id' => $request_id]
        );

        // Tell the customer the outcome right away.
        $pattern = 'approve' === $decision
            ? $this->config->get_string('installment_approved_pattern')
            : $this->config->get_string('installment_rejected_pattern');

        $mobile = vkl('validator')->normalize_mobile((string) get_user_meta((int) $request['user_id'], 'billing_phone', true));

        if ('' !== $pattern && vkl('validator')->is_mobile($mobile)) {
            $params = ['name' => vkl('sms.sender')->resolve_name((int) $request['user_id'])];

            if ('approve' === $decision) {
                $params['amount'] = number_format($amount);
            }

            vkl('sms.sender')->send(
                $mobile,
                'approve' === $decision ? 'credit.approved' : 'credit.rejected',
                $pattern,
                $params
            );
        }

        vkl_dispatch('credit_request.decided', [
            'user_id'  => (int) $request['user_id'],
            'approved' => 'approve' === $decision,
            'amount'   => (float) $request['amount'],
        ]);

        wp_safe_redirect(admin_url('admin.php?page=vkl-credit-customers&view=requests'));
        exit;
    }

    /**
     * Admin block: pending requests with documents and decision forms.
     */
    public function render_admin(): void
    {
        $requests = $this->pending();

        if ([] === $requests) {
            return;
        }
        ?>
        <h2 class="title vkl-sec-credit"><?php esc_html_e('Installment-credit requests', 'vereskala-login'); ?></h2>
        <?php foreach ($requests as $request) : ?>
            <?php
            $applicant = get_userdata((int) $request['user_id']);
            $docs      = json_decode((string) $request['docs'], true);
            $docs      = is_array($docs) ? $docs : [];
            ?>
            <details style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:10px 14px;margin-bottom:8px;max-width:760px" open>
                <summary>
                    <strong><?php echo esc_html($applicant ? $applicant->display_name : ('#' . (int) $request['user_id'])); ?></strong>
                    <?php echo class_exists('VKL_Identity') ? wp_kses_post(VKL_Identity::badge((int) $request['user_id'])) : ''; ?>
                    — <?php echo wp_kses_post(wc_price((float) $request['amount'])); ?>
                    · <?php echo esc_html(sprintf(/* translators: %d: months. */ __('%d installments', 'vereskala-login'), (int) $request['months'])); ?>
                    · <?php echo esc_html((string) $request['created_at']); ?>
                </summary>
                <div style="margin-top:8px">
                    <p>
                        <?php foreach (self::doc_slots() as $slot => $label) : ?>
                            <?php if (isset($docs[$slot])) : ?>
                                <a class="button button-small" target="_blank" rel="noopener"
                                   href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=vkl_credit_doc&file=' . rawurlencode((string) $docs[$slot])), 'vkl_credit_doc')); ?>">
                                    <?php echo esc_html($label); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                        <input type="hidden" name="action" value="vkl_credit_decide">
                        <input type="hidden" name="vkl_request_id" value="<?php echo (int) $request['id']; ?>">
                        <?php $this->security->admin_nonce_field(); ?>
                        <label><?php esc_html_e('Total amount', 'vereskala-login'); ?><br>
                            <input type="number" min="1" step="1" name="vkl_amount" value="<?php echo esc_attr((string) (float) $request['amount']); ?>" style="width:140px"></label>
                        <label><?php esc_html_e('Number of installments', 'vereskala-login'); ?><br>
                            <input type="number" min="1" max="36" name="vkl_count" value="<?php echo (int) $request['months']; ?>" style="width:80px"></label>
                        <label><?php esc_html_e('Due day of month (1–28)', 'vereskala-login'); ?><br>
                            <input type="number" min="1" max="28" name="vkl_due_day" value="5" style="width:80px"></label>
                        <label style="flex:1;min-width:200px"><?php esc_html_e('Note to customer (for rejection)', 'vereskala-login'); ?><br>
                            <input type="text" name="vkl_note" class="regular-text" style="width:100%"></label>
                        <button class="button button-primary" name="vkl_decision" value="approve"><?php esc_html_e('Approve & grant credit', 'vereskala-login'); ?></button>
                        <button class="button" name="vkl_decision" value="reject"
                                onclick="return confirm('<?php echo esc_js(__('Reject this request?', 'vereskala-login')); ?>');"><?php esc_html_e('Reject', 'vereskala-login'); ?></button>
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
        <?php
    }
}
