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

        // A credit file needs a complete identity: profile name + mobile,
        // a checksum-valid national ID, and the applicant's own IBAN.
        $first = trim((string) get_user_meta($user_id, 'billing_first_name', true) ?: get_user_meta($user_id, 'first_name', true));
        $last  = trim((string) get_user_meta($user_id, 'billing_last_name', true) ?: get_user_meta($user_id, 'last_name', true));
        $phone = trim((string) get_user_meta($user_id, 'billing_phone', true));

        if ('' === $first || '' === $last || '' === $phone) {
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

        // Identity lives in Account details (single source of truth):
        // the request only checks that it is complete and valid there.
        $national = (string) get_user_meta($user_id, 'vkl_national_id', true);
        $iban     = (string) get_user_meta($user_id, 'vkl_iban', true);

        if (!VKL_Wc_Account::is_valid_national_id($national) || !preg_match('/^IR\d{24}$/', $iban)) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: account details URL. */
                    __('Please save a valid national ID and IBAN in Account details first: %s', 'vereskala-login'),
                    esc_url(wc_get_account_endpoint_url('edit-account'))
                ),
                'error'
            );

            return;
        }

        // When the shop requires it, the mobile must be registered in the
        // name of the owner of the national ID (inquiry service).
        if (class_exists('VKL_Identity') && 'require' === VKL_Identity::mode() && VKL_Identity::enabled()) {
            $verdict = VKL_Identity::verify_user($user_id)['status'];

            if ('match' !== $verdict) {
                wc_add_notice(
                    'mismatch' === $verdict
                        ? 'شمارهٔ همراه شما به نام صاحب این کد ملی ثبت نشده است؛ درخواست اعتبار فقط با شمارهٔ ثبت‌شده به نام خودتان پذیرفته می‌شود. شماره یا کد ملی را در «جزئیات حساب» اصلاح کنید.'
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
