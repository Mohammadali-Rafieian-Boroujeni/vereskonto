<?php
/**
 * Account details extensions — mobile number and birthday.
 *
 * The mobile field reads/writes the same 'billing_phone' user meta the
 * SMS login uses to find accounts, so a customer who edits their number
 * here keeps being able to log in with it. Because that lookup assumes
 * one account per number, saving enforces both format (via the stable
 * vkl_is_mobile()/vkl_normalize_mobile() helpers) and uniqueness across
 * users.
 *
 * The birthday is stored as user meta 'vkl_birthday' (Y-m-d), ready for
 * future birthday campaigns.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   1.8.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Account
 */
final class VKL_Wc_Account
{
    /**
     * Birthday meta key.
     */
    public const META_BIRTHDAY = 'vkl_birthday';

    /**
     * Validator.
     */
    private VKL_Validator $validator;

    /**
     * @param VKL_Validator $validator Platform validator.
     */
    public function __construct(VKL_Validator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_edit_account_form', [$this, 'render_fields']);
        add_action('woocommerce_save_account_details_errors', [$this, 'validate'], 10, 1);
        add_action('woocommerce_save_account_details', [$this, 'save'], 10, 1);
    }

    /**
     * Render the two extra fields on the Account details form.
     */
    public function render_fields(): void
    {
        $user_id  = get_current_user_id();
        $mobile   = (string) get_user_meta($user_id, 'billing_phone', true);
        $birthday = (string) get_user_meta($user_id, self::META_BIRTHDAY, true);
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
            <label for="vkl_account_mobile"><?php esc_html_e('Mobile number', 'vereskala-login'); ?>&nbsp;<span class="required">*</span></label>
            <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" dir="ltr"
                   name="vkl_account_mobile" id="vkl_account_mobile"
                   value="<?php echo esc_attr($mobile); ?>" placeholder="09xxxxxxxxx">
            <span class="description"><?php esc_html_e('Used for SMS login and order notifications.', 'vereskala-login'); ?></span>
        </p>
        <p class="woocommerce-form-row form-row form-row-wide" style="clear:both">
            <label><?php esc_html_e('Birthday', 'vereskala-login'); ?></label>
            <?php
            // Jalali (Iranian solar) picker with year shown in both the
            // Zoroastrian and Solar-Hijri eras. Stored as Gregorian Y-m-d
            // (converted server-side), so everything else keeps working.
            $jy = $jm = $jd = 0;

            if ('' !== $birthday && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $g_parts)) {
                [$jy, $jm, $jd] = self::g2j((int) $g_parts[1], (int) $g_parts[2], (int) $g_parts[3]);
            }

            $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'امرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
            [$now_jy] = self::g2j((int) gmdate('Y'), (int) gmdate('m'), (int) gmdate('d'));
            ?>
            <span style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center">
                <select name="vkl_bd_d" style="min-width:64px">
                    <option value="0"><?php esc_html_e('Day', 'vereskala-login'); ?></option>
                    <?php for ($d = 1; $d <= 31; $d++) : ?>
                        <option value="<?php echo (int) $d; ?>" <?php selected($jd, $d); ?>><?php echo (int) $d; ?></option>
                    <?php endfor; ?>
                </select>
                <select name="vkl_bd_m" style="min-width:100px">
                    <option value="0"><?php esc_html_e('Month', 'vereskala-login'); ?></option>
                    <?php foreach ($months as $index => $month_name) : ?>
                        <option value="<?php echo (int) ($index + 1); ?>" <?php selected($jm, $index + 1); ?>><?php echo esc_html($month_name); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="vkl_bd_y" style="min-width:150px">
                    <option value="0"><?php esc_html_e('Year (Zoroastrian)', 'vereskala-login'); ?></option>
                    <?php for ($y = $now_jy - 5; $y >= $now_jy - 100; $y--) : ?>
                        <?php /* Zoroastrian year first and prominent; small Solar-Hijri beside it so everyone finds their year. */ ?>
                        <option value="<?php echo (int) $y; ?>" <?php selected($jy, $y); ?>><?php echo (int) ($y + 2359); ?> &nbsp;(<?php echo (int) $y; ?>)</option>
                    <?php endfor; ?>
                </select>
            </span>
            <span class="description" style="display:block;margin-top:4px">
                <?php esc_html_e('Year shown as: Zoroastrian solar (Solar Hijri).', 'vereskala-login'); ?>
            </span>
        </p>
        <p class="woocommerce-form-row form-row form-row-first">
            <label for="vkl_national_id"><?php esc_html_e('National ID (10 digits)', 'vereskala-login'); ?></label>
            <input type="text" class="woocommerce-Input input-text" name="vkl_national_id" id="vkl_national_id"
                   dir="ltr" maxlength="10" inputmode="numeric" autocomplete="off"
                   value="<?php echo esc_attr((string) get_user_meta(get_current_user_id(), 'vkl_national_id', true)); ?>">
            <small id="vkl_national_id_msg" style="display:block;margin-top:4px"></small>
            <?php
            if (class_exists('VKL_Identity') && VKL_Identity::enabled()) {
                $vkl_id_state = VKL_Identity::stored_status(get_current_user_id());

                if ('match' === $vkl_id_state) {
                    echo '<small style="display:block;color:#157f3a">✓ با شمارهٔ همراه شما تطبیق داده شده است.</small>';
                } elseif ('mismatch' === $vkl_id_state) {
                    echo '<small style="display:block;color:#b32d2e">✗ این کد ملی با شمارهٔ همراه شما مطابقت ندارد؛ شماره باید به نام صاحب کد ملی باشد.</small>';
                }
            }
            ?>
        </p>
        <script>
        (function(){
            var f = document.getElementById('vkl_national_id'), m = document.getElementById('vkl_national_id_msg');
            if (!f || !m) { return; }
            function latin(s){ return s.replace(/[۰-۹]/g, function(d){ return d.charCodeAt(0) - 1776; }).replace(/[٠-٩]/g, function(d){ return d.charCodeAt(0) - 1632; }).replace(/\D/g, ''); }
            function ok(c){
                if (c.length !== 10 || /^(\d)\1{9}$/.test(c)) { return false; }
                var s = 0, i; for (i = 0; i < 9; i++) { s += +c[i] * (10 - i); }
                var r = s % 11; return r < 2 ? +c[9] === r : +c[9] === 11 - r;
            }
            function check(){
                var c = latin(f.value);
                if (f.value !== c) { f.value = c; }
                if (c === '') { m.textContent = ''; f.setCustomValidity(''); return; }
                if (c.length < 10) { m.textContent = ''; f.setCustomValidity('کد ملی باید ۱۰ رقم باشد.'); return; }
                var good = ok(c);
                m.textContent = good ? '✓ کد ملی معتبر است' : '✗ کد ملی معتبر نیست؛ ارقام را بررسی کنید';
                m.style.color = good ? '#157f3a' : '#b32d2e';
                f.setCustomValidity(good ? '' : 'کد ملی معتبر نیست.');
            }
            f.addEventListener('input', check); check();
        })();
        </script>
        <p class="woocommerce-form-row form-row form-row-last">
            <label for="vkl_iban"><?php esc_html_e('IBAN (Sheba) of the applicant', 'vereskala-login'); ?></label>
            <input type="text" class="woocommerce-Input input-text" name="vkl_iban" id="vkl_iban"
                   dir="ltr" maxlength="26" placeholder="IR000000000000000000000000"
                   value="<?php echo esc_attr((string) get_user_meta(get_current_user_id(), 'vkl_iban', true)); ?>">
        </p>
        <div class="clear"></div>
        <?php
    }

    /**
     * Validate mobile format, mobile uniqueness, and birthday plausibility.
     *
     * @param WP_Error $errors Errors collector provided by WooCommerce.
     */
    public function validate(WP_Error $errors): void
    {
        $mobile   = $this->posted_mobile();
        $birthday = $this->posted_birthday();

        if ('' === $mobile) {
            $errors->add('vkl_mobile', __('Please enter your mobile number.', 'vereskala-login'));
        } elseif (!$this->validator->is_mobile($mobile)) {
            $errors->add('vkl_mobile', __('Please enter a valid mobile number (e.g. 09123456789).', 'vereskala-login'));
        } elseif ($this->mobile_taken($mobile, get_current_user_id())) {
            $errors->add('vkl_mobile', __('This mobile number is already used by another account.', 'vereskala-login'));
        }

        if (null !== $birthday && false === $this->parse_birthday($birthday)) {
            $errors->add('vkl_birthday', __('Please enter a valid birthday.', 'vereskala-login'));
        }
        // Optional identity fields — validated only when filled in.
        $national = isset($_POST['vkl_national_id']) ? self::normalize_national_id((string) wp_unslash($_POST['vkl_national_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ('' !== $national && !self::is_valid_national_id($national)) {
            $errors->add('vkl_national_id', 'کد ملی واردشده معتبر نیست؛ لطفاً ارقام را دوباره بررسی کنید.');
        }

        $iban = isset($_POST['vkl_iban']) ? strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) wp_unslash($_POST['vkl_iban']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ('' !== $iban && !preg_match('/^IR\d{24}$/', $iban)) {
            $errors->add('vkl_iban', __('The IBAN must start with IR followed by 24 digits, and must belong to the applicant.', 'vereskala-login'));
        }
    }

    /**
     * Persist both fields after WooCommerce saved the core details.
     *
     * @param int $user_id Account being saved.
     */
    public function save(int $user_id): void
    {
        $mobile = $this->posted_mobile();

        if ('' !== $mobile && $this->validator->is_mobile($mobile) && !$this->mobile_taken($mobile, $user_id)) {
            update_user_meta($user_id, 'billing_phone', $this->validator->normalize_mobile($mobile));
        }

        // Identity fields first: they used to sit AFTER the birthday
        // handling, which returns early — so a customer who left the
        // birthday empty never had their national ID or IBAN saved at all.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if (isset($_POST['vkl_national_id'])) {
            $national = self::normalize_national_id((string) wp_unslash($_POST['vkl_national_id']));

            // Only a checksum-valid code (or an empty one, to clear it) is stored.
            if ('' === $national || self::is_valid_national_id($national)) {
                update_user_meta($user_id, 'vkl_national_id', $national);
            }
        }

        if (isset($_POST['vkl_iban'])) {
            update_user_meta($user_id, 'vkl_iban', strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) wp_unslash($_POST['vkl_iban']))));
        }
        // phpcs:enable

        // Does this mobile belong to the owner of this national ID? The
        // inquiry only runs when the pair changed (see VKL_Identity).
        if (class_exists('VKL_Identity') && VKL_Identity::enabled()) {
            $verdict = VKL_Identity::verify_user($user_id);

            if ('match' === $verdict['status']) {
                wc_add_notice('کد ملی شما با شمارهٔ همراه‌تان تطبیق داده شد. ✓', 'success');
            } elseif ('mismatch' === $verdict['status']) {
                wc_add_notice('کد ملی با شمارهٔ همراه شما مطابقت ندارد. شمارهٔ همراه باید به نام خودتان ثبت شده باشد؛ شماره یا کد ملی را اصلاح کنید.', 'error');
            }
        }

        $birthday = $this->posted_birthday();

        if (null === $birthday) {
            return;
        }

        if ('' === $birthday) {
            delete_user_meta($user_id, self::META_BIRTHDAY);

            return;
        }

        $parsed = $this->parse_birthday($birthday);

        if (false !== $parsed) {
            update_user_meta($user_id, self::META_BIRTHDAY, $parsed);
        }
    }

    /**
     * Normalized mobile from the request.
     */
    private function posted_mobile(): string
    {
        if (!isset($_POST['vkl_account_mobile'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its own nonce before these hooks fire.
            return '';
        }

        return $this->validator->normalize_mobile(
            sanitize_text_field(wp_unslash($_POST['vkl_account_mobile'])) // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );
    }

    /**
     * Raw birthday from the request; null when the field was not posted.
     */
    /**
     * Raw input → plain Latin digits. Persian/Arabic-Indic digits are
     * converted (a bare \D strip would delete them and leave nothing), and
     * an 8–9 digit code that lost its leading zeros is padded back to 10.
     */
    public static function normalize_national_id(string $raw): string
    {
        $raw = strtr($raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = (string) preg_replace('/\D/', '', $raw);
        $len    = strlen($digits);

        return $len >= 8 && $len < 10 ? str_pad($digits, 10, '0', STR_PAD_LEFT) : $digits;
    }

    /**
     * Iranian national-ID checksum (standard mod-11 algorithm): the check
     * digit must equal the remainder r of Σ(digit_i × (10 − i)) mod 11 when
     * r < 2, otherwise 11 − r. Ten identical digits are rejected too.
     */
    public static function is_valid_national_id(string $code): bool
    {
        $code = self::normalize_national_id($code);

        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }

        $rem   = $sum % 11;
        $check = (int) $code[9];

        return ($rem < 2 && $check === $rem) || ($rem >= 2 && $check === 11 - $rem);
    }

    /**
     * Gregorian → Jalali. Returns [jy, jm, jd].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function g2j(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
        $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy    = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy   += intdiv($days - 1, 365);
            $days  = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * Jalali → Gregorian. Returns [gy, gm, gd].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function j2g(int $jy, int $jm, int $jd): array
    {
        $jy   += 1595;
        $days  = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy    = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy   += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                ++$days;
            }
        }

        $gy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy   += intdiv($days - 1, 365);
            $days  = ($days - 1) % 365;
        }

        $gd   = $days + 1;
        $leap = ((0 === $gy % 4) && (0 !== $gy % 100)) || (0 === $gy % 400);
        $sal  = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm   = 0;

        while ($gm < 13 && $gd > $sal[$gm]) {
            $gd -= $sal[$gm];
            ++$gm;
        }

        return [$gy, $gm, $gd];
    }

    private function posted_birthday(): ?string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // Jalali picker (day/month/year selects) — converted server-side,
        // so it works with or without JavaScript.
        if (isset($_POST['vkl_bd_y'], $_POST['vkl_bd_m'], $_POST['vkl_bd_d'])) {
            $jy = absint($_POST['vkl_bd_y']);
            $jm = absint($_POST['vkl_bd_m']);
            $jd = absint($_POST['vkl_bd_d']);

            if (0 === $jy && 0 === $jm && 0 === $jd) {
                return ''; // Cleared on purpose.
            }

            if ($jy < 1280 || $jy > 1450 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
                return 'invalid';
            }

            [$gy, $gm, $gd] = self::j2g($jy, $jm, $jd);

            return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        }

        if (!isset($_POST['vkl_account_birthday'])) {
            return null;
        }

        return trim(sanitize_text_field(wp_unslash($_POST['vkl_account_birthday'])));
        // phpcs:enable
    }

    /**
     * Validate 'Y-m-d', not in the future, within a plausible range.
     *
     * @return string|false Canonical Y-m-d or false.
     */
    private function parse_birthday(string $value)
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        if (false === $dt || $dt->format('Y-m-d') !== $value) {
            return false;
        }

        $year = (int) $dt->format('Y');

        if ($dt->getTimestamp() > time() || $year < 1900) {
            return false;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * Whether another user already owns this mobile number. Mirrors the
     * SMS login lookup (billing_phone meta), so uniqueness here protects
     * the login path.
     */
    private function mobile_taken(string $mobile, int $exclude_user_id): bool
    {
        $users = get_users(
            [
                'meta_key'   => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $mobile,         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'exclude'    => [$exclude_user_id],
                'fields'     => 'ID',
                'number'     => 1,
            ]
        );

        return [] !== $users;
    }
}
