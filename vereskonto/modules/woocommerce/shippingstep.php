<?php
/**
 * "Address and shipping" step between the cart and the checkout.
 *
 *   cart  →  /shipping/ (this step)  →  checkout
 *
 * The customer picks one of their saved addresses (VKL_Wc_Addresses,
 * each with a map pin), sees the real shipping options for the cart and
 * that address, picks one, and goes on to pay.
 *
 * Nothing new is stored for the order here. The step writes into the
 * same places the WooCommerce checkout reads:
 *
 *   - the address    → WC()->customer (billing/shipping address fields;
 *                      recipient name/phone in the shipping fields only:
 *                      billing name and phone stay the account holder's);
 *   - the method     → WC()->session 'chosen_shipping_methods';
 *   - which address  → WC()->session 'vkl_address_id' (the checkout picker
 *                      preselects it and the order gets its map location).
 *
 * The prices are never computed here: the cart is recalculated and the
 * rates are read back from WooCommerce's shipping packages, i.e. from the
 * same woocommerce_package_rates code (the Vereskala Checkout plugin) the
 * checkout runs. The estimate and the final price are the same number.
 *
 * Off by default (setting 'shipping_step_enabled' + the page in
 * 'shipping_step_page'). Carts that need no shipping (wallet top-up,
 * virtual products) skip the step.
 *
 * @package Vereskonto\Modules\WooCommerce
 * @since   2.23.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wc_Shipping_Step
 */
final class VKL_Wc_Shipping_Step
{
    public const SHORTCODE = 'vereskonto_shipping';

    /** Session keys. */
    public const S_ADDRESS = 'vkl_address_id';
    public const S_DONE    = 'vkl_ship_step_done';
    public const S_PIN     = 'vkl_ship_pin';

    private const NONCE = 'vkl_ship_step';

    private VKL_Config $config;

    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);

        if (!$this->enabled()) {
            return;
        }

        add_action('template_redirect', [$this, 'route'], 9); // after the checkout login guard (8)
        add_action('template_redirect', [$this, 'handle_post'], 10);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_filter('the_content', [$this, 'content']);
        add_action('wp_ajax_vkl_ship_quote', [$this, 'ajax_quote']);

        // "Proceed" buttons on the cart page and in the mini cart.
        add_action('init', [$this, 'swap_buttons'], 20);

        // A finished or emptied cart starts the next order at this step again.
        add_action('woocommerce_checkout_order_processed', [$this, 'reset']);
        add_action('woocommerce_cart_emptied', [$this, 'reset']);

        add_filter('vkl_address_saved_redirect', [$this, 'back_after_address_save']);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function enabled(): bool
    {
        return $this->config->get_bool('shipping_step_enabled', false) && $this->page_id() > 0;
    }

    public function page_id(): int
    {
        $id = $this->config->get_int('shipping_step_page', 0);

        if ($id <= 0 || !function_exists('get_post_status') || 'publish' !== get_post_status($id)) {
            return 0;
        }

        return $id;
    }

    public function url(): string
    {
        $id = $this->page_id();

        return $id > 0 ? (string) get_permalink($id) : '';
    }

    private function on_step_page(): bool
    {
        $id = $this->page_id();

        return $id > 0 && function_exists('is_page') && is_page($id);
    }

    // ------------------------------------------------------------------
    // Routing
    // ------------------------------------------------------------------

    /**
     * Where a request should go. Pure: every input is in $s, so the
     * decision is testable without WordPress.
     *
     * @param array{page: string, logged_in: bool, login_required: bool, cart_empty: bool, needs_shipping: bool, done: bool, endpoint: bool} $s
     *        page: 'step' | 'checkout' | 'other'; endpoint: order-pay / order-received.
     *
     * @return string 'stay' | 'login' | 'cart' | 'checkout' | 'step'
     */
    public static function decide(array $s): string
    {
        if ('step' === $s['page']) {
            if ($s['cart_empty']) {
                return 'cart';
            }

            if (!$s['needs_shipping']) {
                return 'checkout';
            }

            if (!$s['logged_in']) {
                // The address book belongs to an account. A shop that
                // sells to guests lets them type the address at checkout.
                return $s['login_required'] ? 'login' : 'checkout';
            }

            return 'stay';
        }

        if ('checkout' === $s['page']) {
            if ($s['endpoint'] || !$s['logged_in'] || $s['cart_empty'] || !$s['needs_shipping'] || $s['done']) {
                return 'stay';
            }

            return 'step';
        }

        return 'stay';
    }

    public function route(): void
    {
        if (!function_exists('WC') || null === WC()->cart) {
            return;
        }

        $step     = $this->on_step_page();
        $checkout = !$step && function_exists('is_checkout') && is_checkout();

        if (!$step && !$checkout) {
            return;
        }

        $cart = WC()->cart;

        $where = self::decide([
            'page'           => $step ? 'step' : 'checkout',
            'logged_in'      => is_user_logged_in(),
            'login_required' => $this->config->get_bool('checkout_require_login', false),
            'cart_empty'     => $cart->is_empty(),
            'needs_shipping' => $cart->needs_shipping(),
            'done'           => (bool) $this->session_get(self::S_DONE),
            'endpoint'       => is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'),
        ]);

        switch ($where) {
            case 'cart':
                wp_safe_redirect(wc_get_cart_url());
                exit;

            case 'checkout':
                wp_safe_redirect(wc_get_checkout_url());
                exit;

            case 'login':
                VKL_Wc_Checkout::remember_return(); // back at checkout, route() sends them here
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                exit;

            case 'step':
                wp_safe_redirect($this->url());
                exit;
        }
    }

    /**
     * Cart page and mini-cart buttons lead to this step instead of
     * bouncing through the checkout redirect.
     */
    public function swap_buttons(): void
    {
        if (remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20)) {
            add_action('woocommerce_proceed_to_checkout', [$this, 'cart_button'], 20);
        }

        if (remove_action('woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20)) {
            add_action('woocommerce_widget_shopping_cart_buttons', [$this, 'mini_cart_button'], 20);
        }
    }

    /**
     * Where "proceed" points: the step for a logged-in customer whose cart
     * ships, the checkout otherwise (decide() would send them there anyway).
     */
    private function next_url(): string
    {
        $cart = function_exists('WC') ? WC()->cart : null;

        if (null !== $cart && is_user_logged_in() && $cart->needs_shipping()) {
            return $this->url();
        }

        return wc_get_checkout_url();
    }

    public function cart_button(): void
    {
        printf(
            '<a href="%s" class="checkout-button button alt wc-forward">%s</a>',
            esc_url($this->next_url()),
            esc_html('ادامه: نشانی و ارسال')
        );
    }

    public function mini_cart_button(): void
    {
        printf(
            '<a href="%s" class="button checkout wc-forward">%s</a>',
            esc_url($this->next_url()),
            esc_html('ادامهٔ خرید')
        );
    }

    /**
     * After "add address" from this step, come back here.
     */
    public function back_after_address_save(string $url): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the address form's own nonce was checked.
        return isset($_POST['vkl_back']) && 'shipping' === $_POST['vkl_back'] ? $this->url() : $url;
    }

    public function reset(): void
    {
        $this->session_set(self::S_DONE, null);
        $this->session_set(self::S_ADDRESS, null);
        $this->session_set(self::S_PIN, null);
    }

    // ------------------------------------------------------------------
    // Session
    // ------------------------------------------------------------------

    private function session_get(string $key): mixed
    {
        return function_exists('WC') && WC()->session ? WC()->session->get($key) : null;
    }

    private function session_set(string $key, mixed $value): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set($key, $value);
        }
    }

    // ------------------------------------------------------------------
    // Address → customer → rates
    // ------------------------------------------------------------------

    /**
     * Put a saved address on the WooCommerce customer, the way the
     * checkout's own update_order_review does, and remember it.
     *
     * @param array<string, string> $a
     */
    private function apply_address(array $a): void
    {
        $customer = WC()->customer;

        if (!$customer) {
            return;
        }

        foreach (['billing', 'shipping'] as $type) {
            $customer->{"set_{$type}_country"}('IR');
            $customer->{"set_{$type}_state"}((string) $a['state']);
            $customer->{"set_{$type}_city"}((string) $a['city']);
            $customer->{"set_{$type}_address_1"}((string) $a['address_1']);
            $customer->{"set_{$type}_address_2"}((string) $a['address_2']);
            $customer->{"set_{$type}_postcode"}((string) $a['postcode']);
        }

        $customer->set_shipping_first_name((string) $a['first_name']);
        $customer->set_shipping_last_name((string) $a['last_name']);

        if (method_exists($customer, 'set_shipping_phone')) {
            $customer->set_shipping_phone((string) $a['phone']);
        }

        $customer->set_calculated_shipping(true);
        $customer->save();

        $this->session_set(self::S_ADDRESS, (string) $a['id']);
        $this->session_set(self::S_PIN, '' !== (string) $a['lat'] ? ['lat' => (string) $a['lat'], 'lng' => (string) $a['lng']] : null);

        /**
         * A saved address was chosen for shipping. A checkout plugin that
         * prices by distance can take the map pin from here.
         *
         * @param array<string, string> $a       The address, with 'lat'/'lng'.
         * @param int                   $user_id
         */
        do_action('vkl_shipping_address_chosen', $a, get_current_user_id());
    }

    /**
     * Rates of the (first) shipping package after a recalculation.
     *
     * @return array<int, array{id: string, label: string, cost: float}>
     */
    private function current_rates(): array
    {
        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        $packages = WC()->shipping()->get_packages();

        return self::rates_from_packages(is_array($packages) ? $packages : []);
    }

    /**
     * @param array<int|string, mixed> $packages WooCommerce shipping packages.
     *
     * @return array<int, array{id: string, label: string, cost: float}>
     */
    public static function rates_from_packages(array $packages): array
    {
        $first = reset($packages);
        $out   = [];

        if (!is_array($first) || empty($first['rates']) || !is_array($first['rates'])) {
            return [];
        }

        foreach ($first['rates'] as $id => $rate) {
            if (!is_object($rate)) {
                continue;
            }

            $cost = method_exists($rate, 'get_cost') ? (float) $rate->get_cost() : (float) ($rate->cost ?? 0);
            $tax  = method_exists($rate, 'get_shipping_tax') ? (float) $rate->get_shipping_tax() : 0.0;

            $out[] = [
                'id'    => (string) $id,
                'label' => method_exists($rate, 'get_label') ? (string) $rate->get_label() : (string) ($rate->label ?? $id),
                'cost'  => $cost + $tax,
            ];
        }

        return $out;
    }

    /**
     * The chosen rate when it is still offered, else the first one.
     *
     * @param array<int, array{id: string}> $rates
     */
    public static function pick_rate(array $rates, string $wanted): string
    {
        foreach ($rates as $r) {
            if ($wanted === $r['id']) {
                return $wanted;
            }
        }

        return (string) ($rates[0]['id'] ?? '');
    }

    /**
     * Short notes under the rates: how far free shipping is, and which
     * payment methods do not get it. Read from VKL_Smart_Shipping, the
     * same facts the chatbot quotes.
     *
     * @return string[]
     */
    private function notes(): array
    {
        if (!class_exists('VKL_Smart_Shipping')) {
            return [];
        }

        $ship  = new VKL_Smart_Shipping($this->config);
        $quote = $ship->cart_quote();
        $notes = [];

        if (is_array($quote) && 'over_weight' === $quote['kind']) {
            $notes[] = 'وزن سبد بیش از سقف یک سفارش است؛ لطفاً کالاها را در چند سفارش ثبت کنید.';
        } elseif (is_array($quote) && $quote['missing'] > 0) {
            $notes[] = 'با ' . $ship->money($quote['missing']) . ' خرید بیشتر، ارسال رایگان می‌شود.';
        }

        $nofree = $ship->nofree_titles();

        if ([] !== $nofree && $ship->free_threshold() > 0) {
            $notes[] = 'ارسال رایگان با پرداخت از راه «' . implode('»، «', $nofree) . '» اعمال نمی‌شود؛ روش پرداخت را در گام بعد انتخاب می‌کنید.';
        }

        return $notes;
    }

    /**
     * @param array<int, array{id: string, label: string, cost: float}> $rates
     */
    private function rates_html(array $rates, string $chosen): string
    {
        if ([] === $rates) {
            return '<p class="vkl-ship-none">برای این نشانی روش ارسالی پیدا نشد. نشانی دیگری را انتخاب کنید یا با پشتیبانی تماس بگیرید.</p>';
        }

        $html = '<ul class="vkl-ship-rates">';

        foreach ($rates as $i => $r) {
            $html .= sprintf(
                '<li><label><input type="radio" name="vkl_ship_rate" value="%1$s"%2$s> <span class="vkl-ship-label">%3$s</span> <span class="vkl-ship-cost">%4$s</span></label></li>',
                esc_attr($r['id']),
                $r['id'] === $chosen ? ' checked' : '',
                esc_html($r['label']),
                $r['cost'] > 0 ? wp_kses_post(wc_price($r['cost'])) : esc_html('رایگان')
            );
        }

        $html .= '</ul>';

        foreach ($this->notes() as $note) {
            $html .= '<p class="vkl-ship-note">' . esc_html($note) . '</p>';
        }

        return $html;
    }

    private function chosen_session_rate(): string
    {
        $chosen = $this->session_get('chosen_shipping_methods');

        return is_array($chosen) ? (string) ($chosen[0] ?? '') : '';
    }

    // ------------------------------------------------------------------
    // AJAX: rates for an address
    // ------------------------------------------------------------------

    public function ajax_quote(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        // admin-ajax does not always load the cart and session.
        if (function_exists('WC') && null === WC()->cart && function_exists('wc_load_cart')) {
            wc_load_cart();
        }

        $id = isset($_POST['address_id']) ? sanitize_key((string) wp_unslash($_POST['address_id'])) : '';
        $a  = '' !== $id ? VKL_Wc_Addresses::find(get_current_user_id(), $id) : null;

        if (null === $a || !function_exists('WC') || null === WC()->cart || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => 'نشانی یا سبد خرید پیدا نشد؛ صفحه را دوباره باز کنید.']);
        }

        $this->apply_address($a);
        $rates  = $this->current_rates();
        $chosen = self::pick_rate($rates, $this->chosen_session_rate());

        wp_send_json_success(['html' => $this->rates_html($rates, $chosen)]);
    }

    // ------------------------------------------------------------------
    // Submit: address + method → checkout
    // ------------------------------------------------------------------

    public function handle_post(): void
    {
        if (!$this->on_step_page() || empty($_POST['vkl_ship_submit']) || !is_user_logged_in()) {
            return;
        }

        if (!isset($_POST['_vkl_ship_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_ship_nonce'])), self::NONCE)) {
            wc_add_notice('نشست منقضی شده است؛ دوباره انتخاب کنید.', 'error');

            return;
        }

        $id   = isset($_POST['vkl_address_id']) ? sanitize_key((string) wp_unslash($_POST['vkl_address_id'])) : '';
        $rate = isset($_POST['vkl_ship_rate']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_ship_rate'])) : '';
        $a    = '' !== $id ? VKL_Wc_Addresses::find(get_current_user_id(), $id) : null; // only the customer's own addresses

        if (null === $a) {
            wc_add_notice('یک نشانی انتخاب کنید.', 'error');

            return;
        }

        $this->apply_address($a);
        $rates = $this->current_rates();

        // The rate must be one WooCommerce offers for this cart and address now.
        if ('' === $rate || $rate !== self::pick_rate($rates, $rate)) {
            wc_add_notice([] === $rates ? 'برای این نشانی روش ارسالی وجود ندارد.' : 'روش ارسال را انتخاب کنید.', 'error');

            return;
        }

        $this->session_set('chosen_shipping_methods', [0 => $rate]);
        $this->session_set(self::S_DONE, true);
        WC()->cart->calculate_totals();

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    // ------------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------------

    public function assets(): void
    {
        if (!$this->on_step_page()) {
            return;
        }

        wp_enqueue_style('vkl-addresses', VKL_URL . 'assets/addresses.css', [], VKL_VERSION);
        wp_enqueue_style('vkl-shipping-step', VKL_URL . 'assets/shipping-step.css', ['vkl-addresses'], VKL_VERSION);
        wp_enqueue_script('vkl-shipping-step', VKL_URL . 'assets/shipping-step.js', [], VKL_VERSION, true);
        wp_localize_script('vkl-shipping-step', 'vklShip', [
            'ajax'    => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'loading' => 'در حال محاسبهٔ هزینهٔ ارسال…',
            'failed'  => 'محاسبهٔ هزینهٔ ارسال انجام نشد؛ دوباره تلاش کنید.',
        ]);
    }

    /**
     * The step on its page even when the page has no shortcode.
     */
    public function content(string $content): string
    {
        if (!$this->on_step_page() || !in_the_loop() || !is_main_query() || has_shortcode($content, self::SHORTCODE)) {
            return $content;
        }

        return $content . $this->shortcode();
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode($atts = []): string
    {
        if (!$this->enabled() || !function_exists('WC') || null === WC()->cart || !is_user_logged_in()) {
            return '';
        }

        $user_id = get_current_user_id();
        $rows    = VKL_Wc_Addresses::all($user_id);
        $add_url = add_query_arg(['addr' => 'new', 'back' => 'shipping'], wc_get_account_endpoint_url('edit-address'));

        ob_start();
        ?>
        <div class="vkl-ship">
            <?php if (function_exists('wc_print_notices')) { wc_print_notices(); } ?>
            <ol class="vkl-ship-steps" aria-label="مراحل خرید">
                <li class="is-done"><a href="<?php echo esc_url(wc_get_cart_url()); ?>">سبد خرید</a></li>
                <li class="is-current" aria-current="step">نشانی و ارسال</li>
                <li>پرداخت</li>
            </ol>

            <?php if ([] === $rows) : ?>
                <div class="vkl-ship-empty">
                    <p>📍 هنوز نشانی‌ای ذخیره نکرده‌اید. نشانی را یک بار با محل روی نقشه ثبت کنید تا هزینهٔ ارسال دقیق حساب شود.</p>
                    <a class="button alt" href="<?php echo esc_url($add_url); ?>">افزودن نشانی</a>
                </div>
            <?php else :
                $chosen_addr = (string) $this->session_get(self::S_ADDRESS);
                $chosen_addr = null !== VKL_Wc_Addresses::find($user_id, $chosen_addr) ? $chosen_addr : VKL_Wc_Addresses::default_id($user_id, $rows);
                $current     = VKL_Wc_Addresses::find($user_id, $chosen_addr);

                if (null !== $current) {
                    $this->apply_address($current);
                }

                $rates = $this->current_rates();
                $rate  = self::pick_rate($rates, $this->chosen_session_rate());
                ?>
                <form method="post" class="vkl-ship-form">
                    <fieldset class="vkl-ship-addresses">
                        <legend>ارسال به کجا؟</legend>
                        <?php foreach ($rows as $a) : ?>
                            <label class="vkl-ship-address">
                                <input type="radio" name="vkl_address_id" value="<?php echo esc_attr((string) $a['id']); ?>" <?php checked((string) $a['id'], $chosen_addr); ?>>
                                <span class="vkl-ship-address-body">
                                    <strong><?php echo esc_html((string) $a['label']); ?></strong>
                                    <span><?php echo esc_html(VKL_Wc_Addresses::one_line($a)); ?></span>
                                    <span class="vkl-ship-recipient"><?php echo esc_html(trim($a['first_name'] . ' ' . $a['last_name']) . ' · ' . $a['phone']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <p class="vkl-ship-address-links">
                            <a href="<?php echo esc_url($add_url); ?>">+ نشانی تازه</a>
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address')); ?>">مدیریت نشانی‌ها</a>
                        </p>
                    </fieldset>

                    <fieldset class="vkl-ship-methods">
                        <legend>روش ارسال</legend>
                        <div id="vkl-ship-rates" aria-live="polite"><?php echo $this->rates_html($rates, $rate); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in rates_html(). ?></div>
                    </fieldset>

                    <?php wp_nonce_field(self::NONCE, '_vkl_ship_nonce'); ?>
                    <p class="vkl-ship-submit">
                        <a class="vkl-ship-back" href="<?php echo esc_url(wc_get_cart_url()); ?>">→ بازگشت به سبد</a>
                        <button type="submit" name="vkl_ship_submit" value="1" class="button alt">ادامه: پرداخت</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
