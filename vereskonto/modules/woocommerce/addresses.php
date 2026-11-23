<?php
/**
 * Address book with a map pin (My account → Addresses).
 *
 * WooCommerce keeps exactly one billing and one shipping address per
 * customer. Customers who order for home, work or family had to retype
 * an address every time, and the courier had no location — only text.
 * This screen replaces the two fixed addresses with a list:
 *
 *   - any number of addresses (up to MAX), each with a label, recipient,
 *     recipient mobile, province, city, address, plaque/unit, postcode
 *     and a point dropped on a map (Leaflet + OpenStreetMap tiles);
 *   - one default address, copied into the WooCommerce customer address
 *     fields so checkout, invoices and every other plugin keep working
 *     unchanged (billing NAME and billing PHONE are never touched — they
 *     are the account holder's identity and sign-in number);
 *   - at checkout a "saved addresses" picker fills the fields, and the
 *     chosen address's location is stored on the order and shown to the
 *     shop as a map link.
 *
 * Storage: user meta (an array per customer) — a handful of rows per
 * person, read only on these screens, so no table of its own.
 * The old edit-address/billing and edit-address/shipping URLs keep
 * opening WooCommerce's own forms.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   2.22.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Addresses
 */
final class VKL_Wc_Addresses
{
    public const META         = 'vkl_addresses';
    public const META_DEFAULT = 'vkl_address_default';
    public const MAX          = 10;

    private const NONCE    = 'vkl_address';
    private const ENDPOINT = 'edit-address';

    /** Order meta written at checkout. */
    public const ORDER_LAT   = '_vkl_lat';
    public const ORDER_LNG   = '_vkl_lng';
    public const ORDER_LABEL = '_vkl_address_label';

    /** Leaflet, pinned to an exact version. */
    private const LEAFLET = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/';

    public function register(): void
    {
        add_action('init', [$this, 'replace_endpoint'], 20);
        add_action('template_redirect', [$this, 'handle_post']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('woocommerce_before_checkout_billing_form', [$this, 'checkout_picker']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_order_location'], 10, 2);
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this, 'admin_order_location']);
    }

    /**
     * Our list instead of WooCommerce's two-address overview. The old
     * per-type forms (edit-address/billing) are still served by WooCommerce.
     */
    public function replace_endpoint(): void
    {
        remove_action('woocommerce_account_edit-address_endpoint', 'woocommerce_account_edit_address');
        add_action('woocommerce_account_edit-address_endpoint', [$this, 'render']);
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * Saved addresses of a customer, default first.
     *
     * @return array<int, array<string, string>>
     */
    public static function all(int $user_id): array
    {
        $rows = get_user_meta($user_id, self::META, true);
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $def  = self::default_id($user_id, $rows);

        usort($rows, static fn(array $a, array $b): int => ((string) ($b['id'] ?? '') === $def) <=> ((string) ($a['id'] ?? '') === $def));

        return $rows;
    }

    /**
     * Default address id; the first address when none was chosen.
     *
     * @param array<int, array<string, string>>|null $rows
     */
    public static function default_id(int $user_id, ?array $rows = null): string
    {
        $rows = $rows ?? (array) get_user_meta($user_id, self::META, true);
        $ids  = array_map(static fn($r): string => (string) ($r['id'] ?? ''), array_filter($rows, 'is_array'));
        $id   = (string) get_user_meta($user_id, self::META_DEFAULT, true);

        return in_array($id, $ids, true) ? $id : (string) ($ids[0] ?? '');
    }

    /**
     * @return array<string, string>|null
     */
    public static function find(int $user_id, string $id): ?array
    {
        foreach (self::all($user_id) as $row) {
            if ($id === (string) ($row['id'] ?? '')) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Provinces of Iran as WooCommerce knows them (code => name).
     *
     * @return array<string, string>
     */
    public static function states(): array
    {
        if (function_exists('WC') && WC()->countries) {
            return (array) WC()->countries->get_states('IR');
        }

        return [];
    }

    private static function digits(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    /**
     * Validate and clean one posted address.
     *
     * @param array<string, mixed> $in
     * @return array{0: array<string, string>, 1: array<int, string>} [address, errors]
     */
    public static function clean(array $in): array
    {
        $text = static fn(string $k, int $max = 120): string => mb_substr(trim(sanitize_text_field((string) ($in[$k] ?? ''))), 0, $max);

        $a = [
            'label'      => $text('label', 40),
            'first_name' => $text('first_name', 60),
            'last_name'  => $text('last_name', 60),
            'phone'      => preg_replace('/\D+/', '', self::digits($text('phone', 20))),
            'state'      => strtoupper($text('state', 10)),
            'city'       => $text('city', 60),
            'address_1'  => $text('address_1', 250),
            'address_2'  => $text('address_2', 80),
            'postcode'   => preg_replace('/\D+/', '', self::digits($text('postcode', 20))),
            'lat'        => '',
            'lng'        => '',
        ];
        $errors = [];

        if ('' === $a['label']) {
            $a['label'] = 'نشانی';
        }

        if ('' === $a['first_name'] || '' === $a['last_name']) {
            $errors[] = 'نام و نام خانوادگی گیرنده را وارد کنید.';
        }

        if (str_starts_with($a['phone'], '98') && 12 === strlen($a['phone'])) {
            $a['phone'] = '0' . substr($a['phone'], 2);
        } elseif (str_starts_with($a['phone'], '9') && 10 === strlen($a['phone'])) {
            $a['phone'] = '0' . $a['phone'];
        }

        if (!preg_match('/^09\d{9}$/', $a['phone'])) {
            $errors[] = 'شمارهٔ همراه گیرنده باید ۱۱ رقم و با ۰۹ شروع شود.';
        }

        $states = self::states();

        if ([] !== $states && !isset($states[$a['state']])) {
            $errors[] = 'استان را انتخاب کنید.';
        }

        if ('' === $a['city']) {
            $errors[] = 'شهر را وارد کنید.';
        }

        if (mb_strlen($a['address_1']) < 10) {
            $errors[] = 'نشانی را کامل بنویسید (خیابان، کوچه، …).';
        }

        if ('' !== $a['postcode'] && !preg_match('/^\d{10}$/', $a['postcode'])) {
            $errors[] = 'کد پستی باید ۱۰ رقم باشد.';
        }

        $lat = is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null;
        $lng = is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null;

        if (null === $lat || null === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || (0.0 === $lat && 0.0 === $lng)) {
            $errors[] = 'محل نشانی را روی نقشه مشخص کنید.';
        } else {
            $a['lat'] = number_format($lat, 6, '.', '');
            $a['lng'] = number_format($lng, 6, '.', '');
        }

        return [$a, $errors];
    }

    /**
     * Copy the default address into the WooCommerce customer fields.
     * Billing name and billing phone stay the account holder's own.
     */
    public static function sync_default(int $user_id): void
    {
        $id  = self::default_id($user_id);
        $row = '' !== $id ? self::find($user_id, $id) : null;

        if (null === $row) {
            return;
        }

        foreach (['address_1', 'address_2', 'city', 'state', 'postcode'] as $f) {
            update_user_meta($user_id, 'billing_' . $f, (string) $row[$f]);
            update_user_meta($user_id, 'shipping_' . $f, (string) $row[$f]);
        }

        update_user_meta($user_id, 'billing_country', 'IR');
        update_user_meta($user_id, 'shipping_country', 'IR');
        update_user_meta($user_id, 'shipping_first_name', (string) $row['first_name']);
        update_user_meta($user_id, 'shipping_last_name', (string) $row['last_name']);
        update_user_meta($user_id, 'shipping_phone', (string) $row['phone']);
    }

    /**
     * One-line address for lists and the checkout picker.
     *
     * @param array<string, string> $a
     */
    public static function one_line(array $a): string
    {
        $state = self::states()[(string) ($a['state'] ?? '')] ?? '';
        $parts = array_filter([$state, (string) ($a['city'] ?? ''), (string) ($a['address_1'] ?? ''), (string) ($a['address_2'] ?? '')], static fn($p): bool => '' !== trim((string) $p));

        return implode('، ', $parts);
    }

    public static function map_link(string $lat, string $lng): string
    {
        return sprintf('https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=17/%1$s/%2$s', rawurlencode($lat), rawurlencode($lng));
    }

    private static function url(array $args = []): string
    {
        $base = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(self::ENDPOINT) : '';

        return [] === $args ? $base : add_query_arg($args, $base);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function handle_post(): void
    {
        if (!is_user_logged_in() || empty($_POST['vkl_addr_action']) || !isset($_POST['_vkl_addr_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_addr_nonce'])), self::NONCE)) {
            wc_add_notice('نشست منقضی شده است؛ صفحه را دوباره باز کنید.', 'error');

            return;
        }

        $user_id = get_current_user_id();
        $action  = sanitize_key((string) $_POST['vkl_addr_action']);
        $id      = isset($_POST['vkl_addr_id']) ? sanitize_key((string) $_POST['vkl_addr_id']) : '';
        $rows    = self::all($user_id);

        switch ($action) {
            case 'save':
                $in = isset($_POST['vkl_addr']) && is_array($_POST['vkl_addr']) ? wp_unslash($_POST['vkl_addr']) : []; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- cleaned in clean().
                [$addr, $errors] = self::clean((array) $in);

                if ([] !== $errors) {
                    foreach ($errors as $e) {
                        wc_add_notice($e, 'error');
                    }

                    return; // Form re-renders with the posted values.
                }

                $found = false;

                foreach ($rows as $i => $row) {
                    if ('' !== $id && $id === (string) $row['id']) {
                        $rows[$i] = ['id' => $id] + $addr;
                        $found    = true;
                    }
                }

                if (!$found) {
                    if (count($rows) >= self::MAX) {
                        wc_add_notice(sprintf('حداکثر %s نشانی می‌توانید ذخیره کنید.', number_format_i18n(self::MAX)), 'error');

                        return;
                    }

                    $id     = substr(md5(uniqid((string) $user_id, true)), 0, 10);
                    $rows[] = ['id' => $id] + $addr;
                }

                update_user_meta($user_id, self::META, array_values($rows));

                if (!empty($_POST['vkl_addr_default']) || 1 === count($rows)) {
                    update_user_meta($user_id, self::META_DEFAULT, $id);
                }

                self::sync_default($user_id);
                wc_add_notice($found ? 'نشانی به‌روز شد.' : 'نشانی تازه ذخیره شد.', 'success');
                break;

            case 'delete':
                $rows = array_values(array_filter($rows, static fn(array $r): bool => $id !== (string) $r['id']));
                update_user_meta($user_id, self::META, $rows);

                if ($id === (string) get_user_meta($user_id, self::META_DEFAULT, true)) {
                    delete_user_meta($user_id, self::META_DEFAULT);
                }

                self::sync_default($user_id);
                wc_add_notice('نشانی حذف شد.', 'success');
                break;

            case 'default':
                if (null !== self::find($user_id, $id)) {
                    update_user_meta($user_id, self::META_DEFAULT, $id);
                    self::sync_default($user_id);
                    wc_add_notice('نشانی پیش‌فرض تغییر کرد.', 'success');
                }
                break;

            default:
                return;
        }

        /**
         * Where to go after a save/delete/default action (the shipping step
         * sends its customers back to itself).
         *
         * @param string $url
         */
        wp_safe_redirect((string) apply_filters('vkl_address_saved_redirect', self::url()));
        exit;
    }

    // ------------------------------------------------------------------
    // Screens
    // ------------------------------------------------------------------

    /**
     * Whether the current page is the address book (list or form).
     */
    private static function on_book(): bool
    {
        if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url(self::ENDPOINT)) {
            return false;
        }

        global $wp;

        return '' === (string) ($wp->query_vars[self::ENDPOINT] ?? '');
    }

    public function assets(): void
    {
        $book     = self::on_book();
        $checkout = function_exists('is_checkout') && is_checkout() && !is_wc_endpoint_url('order-received') && is_user_logged_in();

        if (!$book && !$checkout) {
            return;
        }

        wp_enqueue_style('vkl-addresses', VKL_URL . 'assets/addresses.css', [], VKL_VERSION);

        if ($book) {
            wp_enqueue_style('leaflet', self::LEAFLET . 'leaflet.min.css', [], '1.9.4');
            wp_enqueue_script('leaflet', self::LEAFLET . 'leaflet.min.js', [], '1.9.4', true);
        }

        wp_enqueue_script('vkl-addresses', VKL_URL . 'assets/addresses.js', $book ? ['leaflet'] : ['jquery'], VKL_VERSION, true);
        wp_localize_script('vkl-addresses', 'vklAddr', [
            /**
             * Map tiles. Swap for a local provider (Neshan, Map.ir …) if
             * OpenStreetMap is slow for your customers.
             */
            'tiles'       => (string) apply_filters('vkl_map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
            'attribution' => (string) apply_filters('vkl_map_attribution', '© OpenStreetMap'),
            /** Reverse geocoding (pin → suggested city/street); '' turns it off. */
            'geocode'     => (string) apply_filters('vkl_map_reverse_geocode_url', 'https://nominatim.openstreetmap.org/reverse'),
            'center'      => (array) apply_filters('vkl_map_default_center', [35.6997, 51.3380]),
            'saved'       => $checkout ? self::picker_data(get_current_user_id()) : [],
            'i18n'        => [
                'locate'    => 'موقعیت فعلی من',
                'locating'  => 'در حال یافتن موقعیت…',
                'noLocate'  => 'موقعیت شما پیدا نشد؛ نقطه را روی نقشه بگذارید.',
                'picked'    => 'محل نشانی روی نقشه ثبت شد. برای جابه‌جایی، نشانگر را بکشید یا جای دیگری را بزنید.',
                'pick'      => 'روی نقشه بزنید تا محل دقیق نشانی ثبت شود.',
                'suggested' => 'نشانی پیشنهادی از روی نقشه:',
                'use'       => 'استفاده',
                'pinFrom'   => 'نقطهٔ تحویل از روی نقشهٔ نشانی «%s» گرفته شد.',
                'pinChange' => 'تغییر روی نقشه',
            ],
        ]);
    }

    /**
     * @param int|string $value Sub-endpoint value (billing / shipping).
     */
    public function render($value = ''): void
    {
        if ('' !== (string) $value && function_exists('woocommerce_account_edit_address')) {
            woocommerce_account_edit_address((string) $value); // Old forms keep working.

            return;
        }

        $user_id = get_current_user_id();
        $rows    = self::all($user_id);
        $def     = self::default_id($user_id, $rows);
        $edit    = isset($_GET['addr']) ? sanitize_key((string) $_GET['addr']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $posted  = isset($_POST['vkl_addr_action']) && 'save' === $_POST['vkl_addr_action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-show after a failed save.

        if ($posted || 'new' === $edit || ('' !== $edit && null !== self::find($user_id, $edit))) {
            $this->render_form($user_id, $posted ? sanitize_key((string) ($_POST['vkl_addr_id'] ?? '')) : ('new' === $edit ? '' : $edit), $posted, $def, count($rows)); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            return;
        }

        $this->render_list($rows, $def);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function render_list(array $rows, string $def): void
    {
        ?>
        <div class="vkl-addr">
            <div class="vkl-addr-head">
                <h3>نشانی‌های من</h3>
                <?php if (count($rows) < self::MAX) : ?>
                    <a class="button vkl-addr-add" href="<?php echo esc_url(self::url(['addr' => 'new'])); ?>">+ افزودن نشانی</a>
                <?php endif; ?>
            </div>
            <p class="vkl-addr-intro">نشانی پیش‌فرض هنگام خرید خودکار پر می‌شود؛ در صفحهٔ پرداخت می‌توانید هر نشانی دیگری را انتخاب کنید.</p>

            <?php if ([] === $rows) : ?>
                <div class="vkl-addr-empty">
                    <span aria-hidden="true">📍</span>
                    <p>هنوز نشانی‌ای ذخیره نکرده‌اید. نشانی را بنویسید و محلش را روی نقشه مشخص کنید تا ارسال سریع‌تر و دقیق‌تر انجام شود.</p>
                    <a class="button" href="<?php echo esc_url(self::url(['addr' => 'new'])); ?>">افزودن نخستین نشانی</a>
                </div>
            <?php else : ?>
                <ul class="vkl-addr-list">
                    <?php foreach ($rows as $a) : ?>
                        <?php $is_def = (string) $a['id'] === $def; ?>
                        <li class="vkl-addr-card<?php echo $is_def ? ' is-default' : ''; ?>">
                            <div class="vkl-addr-title">
                                <strong><?php echo esc_html((string) $a['label']); ?></strong>
                                <?php if ($is_def) : ?><span class="vkl-addr-badge">پیش‌فرض</span><?php endif; ?>
                            </div>
                            <p class="vkl-addr-line"><?php echo esc_html(self::one_line($a)); ?></p>
                            <p class="vkl-addr-meta">
                                <span><?php echo esc_html(trim($a['first_name'] . ' ' . $a['last_name'])); ?></span>
                                <span dir="ltr"><?php echo esc_html((string) $a['phone']); ?></span>
                                <?php if ('' !== (string) $a['postcode']) : ?><span>کد پستی <span dir="ltr"><?php echo esc_html((string) $a['postcode']); ?></span></span><?php endif; ?>
                                <?php if ('' !== (string) $a['lat']) : ?><a href="<?php echo esc_url(self::map_link((string) $a['lat'], (string) $a['lng'])); ?>" target="_blank" rel="noopener">📍 روی نقشه</a><?php endif; ?>
                            </p>
                            <div class="vkl-addr-actions">
                                <a class="button" href="<?php echo esc_url(self::url(['addr' => (string) $a['id']])); ?>">ویرایش</a>
                                <?php if (!$is_def) : ?>
                                    <?php $this->action_button('default', (string) $a['id'], 'پیش‌فرض شود'); ?>
                                <?php endif; ?>
                                <?php $this->action_button('delete', (string) $a['id'], 'حذف', 'این نشانی حذف شود؟'); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    private function action_button(string $action, string $id, string $label, string $confirm = ''): void
    {
        ?>
        <form method="post" class="vkl-addr-inline"<?php echo '' !== $confirm ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\')"' : ''; ?>>
            <input type="hidden" name="vkl_addr_action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="vkl_addr_id" value="<?php echo esc_attr($id); ?>">
            <?php wp_nonce_field(self::NONCE, '_vkl_addr_nonce'); ?>
            <button type="submit" class="button<?php echo 'delete' === $action ? ' vkl-addr-danger' : ''; ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function render_form(int $user_id, string $id, bool $posted, string $def, int $count): void
    {
        $a = '' !== $id ? (self::find($user_id, $id) ?? []) : [];

        if ($posted) {
            $in = isset($_POST['vkl_addr']) && is_array($_POST['vkl_addr']) ? wp_unslash($_POST['vkl_addr']) : []; // phpcs:ignore
            $a  = array_map(static fn($v): string => sanitize_text_field((string) $v), (array) $in) + $a;
        } elseif ([] === $a) {
            // New address: suggest the account holder as recipient.
            $a = [
                'first_name' => (string) (get_user_meta($user_id, 'billing_first_name', true) ?: get_user_meta($user_id, 'first_name', true)),
                'last_name'  => (string) (get_user_meta($user_id, 'billing_last_name', true) ?: get_user_meta($user_id, 'last_name', true)),
                'phone'      => (string) get_user_meta($user_id, 'billing_phone', true),
                'label'      => 0 === $count ? 'خانه' : '',
            ];
        }

        $v      = static fn(string $k): string => (string) ($a[$k] ?? '');
        $states = self::states();
        // phpcs:ignore WordPress.Security.NonceVerification -- only picks where the form returns to.
        $back = 'shipping' === ($_POST['vkl_back'] ?? $_GET['back'] ?? '') ? 'shipping' : '';
        ?>
        <div class="vkl-addr">
            <a class="vkl-addr-back" href="<?php echo esc_url(self::url()); ?>">→ همهٔ نشانی‌ها</a>
            <h3><?php echo '' === $id ? 'نشانی تازه' : 'ویرایش نشانی'; ?></h3>

            <form method="post" class="vkl-addr-form" novalidate>
                <div class="vkl-addr-map-wrap">
                    <div id="vkl-addr-map" class="vkl-addr-map" role="application" aria-label="نقشه برای انتخاب محل نشانی"></div>
                    <button type="button" class="button vkl-addr-locate" id="vkl-addr-locate">موقعیت فعلی من</button>
                    <p class="vkl-addr-hint" id="vkl-addr-hint" aria-live="polite"><?php echo '' !== $v('lat') ? 'محل نشانی روی نقشه ثبت شد. برای جابه‌جایی، نشانگر را بکشید یا جای دیگری را بزنید.' : 'روی نقشه بزنید تا محل دقیق نشانی ثبت شود.'; ?></p>
                    <p class="vkl-addr-suggest" id="vkl-addr-suggest" hidden></p>
                </div>
                <input type="hidden" name="vkl_addr[lat]" id="vkl-addr-lat" value="<?php echo esc_attr($v('lat')); ?>">
                <input type="hidden" name="vkl_addr[lng]" id="vkl-addr-lng" value="<?php echo esc_attr($v('lng')); ?>">

                <div class="vkl-addr-fields">
                    <p class="vkl-addr-chips">
                        <label for="vkl-addr-label">عنوان</label>
                        <input type="text" id="vkl-addr-label" name="vkl_addr[label]" maxlength="40" value="<?php echo esc_attr($v('label')); ?>" placeholder="مثلاً خانه، محل کار" list="vkl-addr-labels">
                        <datalist id="vkl-addr-labels"><option value="خانه"><option value="محل کار"><option value="خانهٔ پدری"></datalist>
                    </p>
                    <p>
                        <label for="vkl-addr-state">استان <span class="required">*</span></label>
                        <select id="vkl-addr-state" name="vkl_addr[state]" required>
                            <option value="">انتخاب استان</option>
                            <?php foreach ($states as $code => $name) : ?>
                                <option value="<?php echo esc_attr((string) $code); ?>" <?php selected($v('state'), (string) $code); ?>><?php echo esc_html((string) $name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label for="vkl-addr-city">شهر <span class="required">*</span></label>
                        <input type="text" id="vkl-addr-city" name="vkl_addr[city]" required maxlength="60" value="<?php echo esc_attr($v('city')); ?>">
                    </p>
                    <p class="vkl-addr-wide">
                        <label for="vkl-addr-line">نشانی <span class="required">*</span></label>
                        <textarea id="vkl-addr-line" name="vkl_addr[address_1]" rows="2" required maxlength="250" placeholder="خیابان، کوچه، ساختمان"><?php echo esc_textarea($v('address_1')); ?></textarea>
                    </p>
                    <p>
                        <label for="vkl-addr-unit">پلاک و واحد</label>
                        <input type="text" id="vkl-addr-unit" name="vkl_addr[address_2]" maxlength="80" value="<?php echo esc_attr($v('address_2')); ?>" placeholder="پلاک ۱۲، واحد ۳">
                    </p>
                    <p>
                        <label for="vkl-addr-postcode">کد پستی</label>
                        <input type="text" id="vkl-addr-postcode" name="vkl_addr[postcode]" dir="ltr" inputmode="numeric" maxlength="10" value="<?php echo esc_attr($v('postcode')); ?>" placeholder="۱۰ رقم">
                    </p>
                    <p>
                        <label for="vkl-addr-first">نام گیرنده <span class="required">*</span></label>
                        <input type="text" id="vkl-addr-first" name="vkl_addr[first_name]" required maxlength="60" value="<?php echo esc_attr($v('first_name')); ?>">
                    </p>
                    <p>
                        <label for="vkl-addr-last">نام خانوادگی گیرنده <span class="required">*</span></label>
                        <input type="text" id="vkl-addr-last" name="vkl_addr[last_name]" required maxlength="60" value="<?php echo esc_attr($v('last_name')); ?>">
                    </p>
                    <p>
                        <label for="vkl-addr-phone">همراه گیرنده <span class="required">*</span></label>
                        <input type="tel" id="vkl-addr-phone" name="vkl_addr[phone]" dir="ltr" required maxlength="13" value="<?php echo esc_attr($v('phone')); ?>" placeholder="09xxxxxxxxx">
                    </p>
                    <?php if ($count > 0 && $id !== $def) : ?>
                        <p class="vkl-addr-wide">
                            <label><input type="checkbox" name="vkl_addr_default" value="1"> نشانی پیش‌فرض من باشد</label>
                        </p>
                    <?php endif; ?>
                </div>

                <input type="hidden" name="vkl_addr_action" value="save">
                <?php if ('shipping' === $back) : ?>
                    <input type="hidden" name="vkl_back" value="shipping">
                <?php endif; ?>
                <input type="hidden" name="vkl_addr_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field(self::NONCE, '_vkl_addr_nonce'); ?>
                <p class="vkl-addr-submit">
                    <button type="submit" class="button alt">ذخیرهٔ نشانی</button>
                    <a href="<?php echo esc_url(self::url()); ?>">انصراف</a>
                </p>
            </form>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Checkout and orders
    // ------------------------------------------------------------------

    /**
     * Saved addresses as the checkout picker needs them.
     *
     * @return array<int, array<string, string>>
     */
    public static function picker_data(int $user_id): array
    {
        $def = self::default_id($user_id);

        return array_map(static fn(array $a): array => [
            'id'         => (string) $a['id'],
            'title'      => (string) $a['label'] . ' — ' . self::one_line($a),
            'default'    => (string) $a['id'] === $def ? '1' : '',
            'first_name' => (string) $a['first_name'],
            'last_name'  => (string) $a['last_name'],
            'phone'      => (string) $a['phone'],
            'state'      => (string) $a['state'],
            'city'       => (string) $a['city'],
            'address_1'  => (string) $a['address_1'],
            'address_2'  => (string) $a['address_2'],
            'postcode'   => (string) $a['postcode'],
            // The map pin, so checkout can hand it to the courier map (VPC).
            'lat'        => (string) ($a['lat'] ?? ''),
            'lng'        => (string) ($a['lng'] ?? ''),
            'label'      => (string) $a['label'],
        ], self::all($user_id));
    }

    /**
     * Address chosen at the shipping step (VKL_Wc_Shipping_Step), '' if none.
     */
    public static function session_address_id(): string
    {
        $id = function_exists('WC') && WC()->session ? WC()->session->get('vkl_address_id') : '';

        return is_string($id) ? sanitize_key($id) : '';
    }

    public function checkout_picker(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $rows = self::all(get_current_user_id());
        $pick = self::session_address_id();
        ?>
        <div class="vkl-addr-pick">
            <?php if ([] === $rows) : ?>
                <p class="vkl-addr-pick-empty">📍 نشانی را یک بار در <a href="<?php echo esc_url(self::url(['addr' => 'new'])); ?>">نشانی‌های من</a> با محل روی نقشه ذخیره کنید تا دفعهٔ بعد فقط انتخابش کنید.</p>
            <?php else : ?>
                <label for="vkl-address-id">ارسال به نشانی ذخیره‌شده</label>
                <select id="vkl-address-id" name="vkl_address_id">
                    <?php foreach ($rows as $a) : ?>
                        <option value="<?php echo esc_attr((string) $a['id']); ?>"<?php echo (string) $a['id'] === $pick ? ' selected' : ''; ?>><?php echo esc_html((string) $a['label'] . ' — ' . self::one_line($a)); ?></option>
                    <?php endforeach; ?>
                    <option value="">نشانی دیگری وارد می‌کنم</option>
                </select>
                <a class="vkl-addr-manage" href="<?php echo esc_url(self::url()); ?>">مدیریت نشانی‌ها</a>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Store the chosen address's location on the order. Coordinates come
     * from the customer's own saved address, never from the request.
     *
     * @param WC_Order             $order
     * @param array<string, mixed> $data
     */
    public function save_order_location($order, $data = []): void
    {
        // The picker on the checkout form; else the address chosen at the shipping step.
        $id = isset($_POST['vkl_address_id']) ? sanitize_key((string) wp_unslash($_POST['vkl_address_id'])) : self::session_address_id(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
        $uid = is_object($order) && method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;

        if ('' === $id || $uid <= 0) {
            return;
        }

        $a = self::find($uid, $id);

        if (null === $a || '' === (string) $a['lat']) {
            return;
        }

        $order->update_meta_data(self::ORDER_LAT, (string) $a['lat']);
        $order->update_meta_data(self::ORDER_LNG, (string) $a['lng']);
        $order->update_meta_data(self::ORDER_LABEL, (string) $a['label']);
    }

    /**
     * @param WC_Order $order
     */
    public function admin_order_location($order): void
    {
        $lat = (string) $order->get_meta(self::ORDER_LAT);
        $lng = (string) $order->get_meta(self::ORDER_LNG);

        if ('' === $lat || '' === $lng) {
            return;
        }

        $label = (string) $order->get_meta(self::ORDER_LABEL);

        printf(
            '<p><strong>%1$s</strong><br><a href="%2$s" target="_blank" rel="noopener">📍 %3$s</a> <span dir="ltr" style="color:#646970">%4$s, %5$s</span></p>',
            esc_html('محل نشانی' . ('' !== $label ? ' (' . $label . ')' : '')),
            esc_url(self::map_link($lat, $lng)),
            esc_html('نمایش روی نقشه'),
            esc_html($lat),
            esc_html($lng)
        );
    }
}
