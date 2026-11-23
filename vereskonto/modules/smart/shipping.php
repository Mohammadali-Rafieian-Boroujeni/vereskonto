<?php
/**
 * Shipping facts for the chatbot, read from where the real numbers live.
 *
 * The bot used to quote two numbers typed into its own settings page, so
 * every change made in WooCommerce or in the checkout logic left it telling
 * customers the old price. This class asks the sources instead:
 *
 *  - the price rules → the Vereskala Checkout plugin (VPC_Settings::cfg():
 *    weight tiers, the extra-step rule, the weight ceiling, the Isfahan
 *    courier base and per-km rate, ...), and the SAME decision code it runs
 *    at checkout (VPC_Core::plan_rates / weight_cost) for what a given cart
 *    costs. Older setups fall back to the retired child-theme functions,
 *    and finally to the first flat-rate method of WooCommerce;
 *  - the free-shipping threshold → WooCommerce shipping zones (the enabled
 *    "Free shipping" methods and their minimum order amount);
 *  - the payment methods that do not get free shipping → the checkout
 *    plugin's own list.
 *
 * The two numbers in Smart shop settings remain only as a last-resort
 * fallback when none of the sources says anything (default 0 = unused).
 *
 * @package Vereskonto\Modules\Smart
 * @since   2.9.34
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Shipping
 */
final class VKL_Smart_Shipping
{
    private VKL_Config $config;

    /**
     * Per-request memo of the WooCommerce zone scan.
     *
     * @var array{free: int, flat: int}|null
     */
    private ?array $zones = null;

    public function __construct(VKL_Config $config)
    {
        $this->config = $config;
    }

    /**
     * Is the Vereskala Checkout plugin (the source of the price rules) active?
     */
    public function has_checkout_plugin(): bool
    {
        return class_exists('VPC_Settings') && class_exists('VPC_Core');
    }

    /**
     * Enabled shipping methods across all zones: the smallest minimum
     * order that unlocks free shipping, and the first flat-rate cost.
     *
     * @return array{free: int, flat: int}
     */
    private function zone_facts(): array
    {
        if (null !== $this->zones) {
            return $this->zones;
        }

        $free = 0;
        $flat = 0;

        if (class_exists('WC_Shipping_Zones') && class_exists('WC_Shipping_Zone')) {
            $ids = [0]; // "Locations not covered by your other zones"

            foreach ((array) WC_Shipping_Zones::get_zones() as $row) {
                if (isset($row['id'])) {
                    $ids[] = (int) $row['id'];
                }
            }

            foreach ($ids as $zone_id) {
                try {
                    $zone = new WC_Shipping_Zone($zone_id);
                } catch (Throwable $e) {
                    continue;
                }

                foreach ($zone->get_shipping_methods(true) as $method) { // true = enabled only
                    if (!is_object($method)) {
                        continue;
                    }

                    if ('free_shipping' === $method->id) {
                        $requires = (string) ($method->requires ?? '');
                        $min      = (int) round((float) ($method->min_amount ?? 0));

                        // Only "minimum order" style rules say a threshold.
                        if (in_array($requires, ['min_amount', 'either', 'both'], true) && $min > 0) {
                            $free = 0 === $free ? $min : min($free, $min);
                        }
                    }

                    if ('flat_rate' === $method->id && 0 === $flat) {
                        $cost = (string) ($method->cost ?? '');

                        if (is_numeric($cost) && (float) $cost > 0) {
                            $flat = (int) round((float) $cost);
                        }
                    }
                }
            }
        }

        $this->zones = ['free' => $free, 'flat' => $flat];

        return $this->zones;
    }

    /**
     * Minimum order for free shipping (0 = none known).
     */
    public function free_threshold(): int
    {
        $free = $this->zone_facts()['free'];

        return max(0, $free);
    }

    /**
     * The price rules, from the checkout plugin when present.
     *
     * @return array{tiers: array<int, int>, extra_step: int, extra_cost: int, max_weight: int, pickup_city: string, courier: array<string, float>|null}
     */
    public function policy(): array
    {
        $policy = [
            'tiers'       => [],
            'extra_step'  => 0,
            'extra_cost'  => 0,
            'max_weight'  => 0,
            'pickup_city' => '',
            'courier'     => null,
        ];

        if ($this->has_checkout_plugin()) {
            $cfg = (array) VPC_Settings::cfg();

            $policy['tiers']       = (array) ($cfg['tiers'] ?? []);
            $policy['extra_step']  = (int) ($cfg['extra_step_g'] ?? 0);
            $policy['extra_cost']  = (int) ($cfg['extra_cost'] ?? 0);
            $policy['max_weight']  = (int) ($cfg['max_weight_g'] ?? 0);
            $policy['pickup_city'] = defined('VPC_Core::ISFAHAN') ? (string) VPC_Core::ISFAHAN : '';
            $policy['courier']     = [
                'base'   => (float) ($cfg['courier_base'] ?? 0),
                'per_km' => (float) ($cfg['courier_per_km'] ?? 0),
                'max_km' => (float) ($cfg['courier_max_km'] ?? 0),
            ];
        } elseif (function_exists('vereskala_shipping_weight_tiers')) { // retired child-theme logic
            $policy['tiers']      = (array) vereskala_shipping_weight_tiers();
            $policy['max_weight'] = defined('VERESKALA_MAX_CART_WEIGHT') ? (int) VERESKALA_MAX_CART_WEIGHT : 0;
        }

        /**
         * Lets any other checkout logic describe its price rules the same way.
         *
         * @param array<string, mixed> $policy
         */
        $policy = (array) apply_filters('vkl_shipping_policy', $policy);

        $tiers = [];

        foreach ((array) $policy['tiers'] as $max => $cost) {
            if ((int) $max > 0 && (int) $cost >= 0) {
                $tiers[(int) $max] = (int) $cost;
            }
        }

        ksort($tiers);
        $policy['tiers'] = $tiers;

        return $policy;
    }

    /**
     * Price of post shipping for a cart weight in grams; null when no source knows.
     */
    public function fee_for_weight(float $grams): ?int
    {
        if ($this->has_checkout_plugin()) {
            $cost = VPC_Core::weight_cost($grams, (array) VPC_Settings::cfg());

            if ($cost > 0) {
                return (int) $cost;
            }
        }

        if (function_exists('vereskala_calculate_shipping_cost')) { // retired child-theme logic
            return (int) vereskala_calculate_shipping_cost($grams);
        }

        foreach ($this->policy()['tiers'] as $max => $cost) {
            if ($grams <= $max) {
                return $cost;
            }
        }

        $flat = $this->zone_facts()['flat'];

        return $flat > 0 ? $flat : null;
    }

    /**
     * "۱٬۲۰۰٬۰۰۰ تومان" in the shop's own currency.
     */
    public function money(int $amount): string
    {
        if (function_exists('wc_price')) {
            return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8'));
        }

        return number_format_i18n($amount) . ' تومان';
    }

    private function weight_label(int $grams): string
    {
        if ($grams < 1000) {
            return number_format_i18n($grams) . ' گرم';
        }

        return number_format_i18n($grams / 1000, 0 === $grams % 1000 ? 0 : 1) . ' کیلو';
    }

    /**
     * Titles of the payment methods that do not get free shipping (from the
     * checkout plugin's list).
     *
     * @return string[]
     */
    public function nofree_titles(): array
    {
        if (!$this->has_checkout_plugin() || !method_exists('VPC_Settings', 'nofree_gateways') || !function_exists('WC') || !WC()->payment_gateways()) {
            return [];
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        $titles   = [];

        foreach ((array) VPC_Settings::nofree_gateways() as $id) {
            if (isset($gateways[$id]) && is_object($gateways[$id])) {
                $title = trim(wp_strip_all_tags((string) $gateways[$id]->get_title()));

                if ('' !== $title) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * Full, current shipping explanation (several lines).
     */
    public function summary(): string
    {
        $policy = $this->policy();
        $lines  = [];

        if ([] !== $policy['tiers']) {
            $lines[] = 'هزینهٔ ارسال پستی بر پایهٔ وزن سبد شما حساب می‌شود:';

            foreach ($policy['tiers'] as $max => $cost) {
                $lines[] = '• تا ' . $this->weight_label($max) . ': ' . $this->money($cost);
            }

            if ($policy['extra_step'] > 0 && $policy['extra_cost'] > 0) {
                $last    = (int) array_key_last($policy['tiers']);
                $lines[] = '• بیش از ' . $this->weight_label($last) . ': ' . $this->money((int) end($policy['tiers']))
                    . ' و برای هر ' . $this->weight_label($policy['extra_step']) . ' (یا کسری از آن) ' . $this->money($policy['extra_cost']) . ' بیشتر';
            }
        } else {
            $fee = $this->fee_for_weight(0.0);

            if (null !== $fee) {
                $lines[] = 'هزینهٔ ارسال ' . $this->money($fee) . ' است.';
            }
        }

        $courier = $policy['courier'];

        if (is_array($courier) && '' !== $policy['pickup_city'] && ($courier['base'] > 0 || $courier['per_km'] > 0)) {
            $lines[] = 'پیک ' . $policy['pickup_city'] . ': ' . $this->money((int) $courier['base'])
                . ' پایه' . ($courier['per_km'] > 0 ? ' به‌اضافهٔ ' . $this->money((int) $courier['per_km']) . ' برای هر کیلومتر (فاصلهٔ جاده‌ای برآوردی تا نقطهٔ تحویل روی نقشه)' : '')
                . ($courier['max_km'] > 0 ? '، تا ' . number_format_i18n($courier['max_km'], 0) . ' کیلومتر؛ بیش‌تر از آن ارسال پستی' : '') . '.';
        }

        if ('' !== $policy['pickup_city']) {
            $lines[] = 'دریافت حضوری از فروشگاه برای ' . $policy['pickup_city'] . ' رایگان است.';
        }

        $free = $this->free_threshold();

        if ($free > 0) {
            $lines[] = 'ارسال رایگان: برای سفارش‌های ' . $this->money($free) . ' و بالاتر'
                . ('' !== $policy['pickup_city'] ? ' (در ' . $policy['pickup_city'] . ' پیک رایگان می‌شود)' : '') . '.';

            $nofree = $this->nofree_titles();

            if ([] !== $nofree) {
                $lines[] = 'ارسال رایگان با پرداخت از راه «' . implode('»، «', $nofree) . '» اعمال نمی‌شود.';
            }
        }

        if ($policy['max_weight'] > 0) {
            $lines[] = 'سقف وزن هر سفارش ' . $this->weight_label($policy['max_weight']) . ' است.';
        }

        return implode("\n", $lines);
    }

    /**
     * One-sentence version for greetings and canned text.
     */
    public function short(): string
    {
        $policy = $this->policy();
        $parts  = [];

        if ([] !== $policy['tiers']) {
            $first = (int) reset($policy['tiers']);

            $parts[] = 'هزینهٔ ارسال پستی بسته به وزن سبد از ' . $this->money($first) . ' شروع می‌شود';
        } else {
            $fee = $this->fee_for_weight(0.0);

            if (null !== $fee) {
                $parts[] = 'هزینهٔ ارسال ' . $this->money($fee) . ' است';
            }
        }

        $free = $this->free_threshold();

        if ($free > 0) {
            $parts[] = 'سفارش‌های ' . $this->money($free) . ' و بالاتر رایگان ارسال می‌شوند';
        }

        return [] === $parts ? '' : implode(' و ', $parts) . '.';
    }

    /**
     * What the customer's current cart would pay for shipping, using the
     * checkout plugin's own decision code when present. null when there is
     * no usable cart or no source knows the price.
     *
     * @return array{kind: string, rates: array<int, array{0: string, 1: int}>, weight: float, total: int, missing: int}|null
     */
    public function cart_quote(): ?array
    {
        if (!function_exists('WC') || null === WC()->cart || WC()->cart->is_empty()) {
            return null;
        }

        $cart  = WC()->cart;
        $total = (float) $cart->get_cart_contents_total();

        if (method_exists($cart, 'display_prices_including_tax') && $cart->display_prices_including_tax()) {
            $total += (float) $cart->get_cart_contents_tax();
        }

        $free     = $this->free_threshold();
        $eligible = $free > 0 && $total >= $free;

        // A payment method on the "no free shipping" list switches it off.
        if ($eligible && $this->has_checkout_plugin() && class_exists('VPC_Shipping') && method_exists('VPC_Shipping', 'gateway_blocks_free') && VPC_Shipping::gateway_blocks_free()) {
            $eligible = false;
        }

        $missing = $free > 0 && $total < $free ? (int) ceil($free - $total) : 0;

        if ($this->has_checkout_plugin() && class_exists('VPC_Shipping')) {
            $weight   = (float) VPC_Shipping::weight_g($cart->get_cart());
            $customer = WC()->customer;
            $city     = $customer ? (string) $customer->get_shipping_city() : '';
            $address  = $customer ? $city . ' ' . $customer->get_shipping_address_1() . ' ' . $customer->get_shipping_address_2() : '';
            $pin      = method_exists('VPC_Shipping', 'pin') ? VPC_Shipping::pin() : null;

            $plan = VPC_Core::plan_rates(
                ['city' => $city, 'address' => $address, 'weight_g' => $weight, 'has_free' => $eligible, 'pin' => $pin],
                (array) VPC_Settings::cfg()
            );

            if ('none' === $plan['mode']) {
                return ['kind' => 'over_weight', 'rates' => [], 'weight' => $weight, 'total' => (int) round($total), 'missing' => $missing];
            }

            if ('keep_free' === $plan['mode']) {
                return ['kind' => 'free', 'rates' => [], 'weight' => $weight, 'total' => (int) round($total), 'missing' => 0];
            }

            $rates = [];

            foreach ((array) $plan['rates'] as $rate) {
                $rates[] = [(string) $rate[1], (int) round((float) $rate[2])];
            }

            return [
                'kind'    => 'rates',
                'rates'   => $rates,
                'weight'  => $weight,
                'total'   => (int) round($total),
                'missing' => $missing,
            ];
        }

        // Without the checkout plugin: weight price table (or flat rate).
        $weight = (float) $cart->get_cart_contents_weight();
        $weight = $weight > 0 ? $weight : 100.0;
        $max    = $this->policy()['max_weight'];

        if ($max > 0 && $weight > $max) {
            return ['kind' => 'over_weight', 'rates' => [], 'weight' => $weight, 'total' => (int) round($total), 'missing' => $missing];
        }

        if ($eligible) {
            return ['kind' => 'free', 'rates' => [], 'weight' => $weight, 'total' => (int) round($total), 'missing' => 0];
        }

        $fee = $this->fee_for_weight($weight);

        if (null === $fee) {
            return null;
        }

        return ['kind' => 'rates', 'rates' => [['ارسال', $fee]], 'weight' => $weight, 'total' => (int) round($total), 'missing' => $missing];
    }

    /**
     * The cart-specific sentence(s), '' when there is no cart to talk about.
     */
    public function cart_sentence(): string
    {
        $quote = $this->cart_quote();

        if (null === $quote) {
            return '';
        }

        if ('over_weight' === $quote['kind']) {
            return 'وزن سبد فعلی شما بیش از سقف مجاز یک سفارش است؛ لطفاً کالاها را در چند سفارش ثبت کنید.';
        }

        if ('free' === $quote['kind']) {
            return 'برای سبد فعلی شما (جمع ' . $this->money($quote['total']) . ') ارسال رایگان است. 🎉';
        }

        $lines = ['برای سبد فعلی شما (وزن حدود ' . $this->weight_label((int) round($quote['weight'])) . ') گزینه‌های ارسال:'];

        foreach ($quote['rates'] as [$label, $cost]) {
            $lines[] = '• ' . $label . ': ' . (0 === $cost ? 'رایگان' : $this->money($cost));
        }

        if ($quote['missing'] > 0) {
            $lines[] = 'با ' . $this->money($quote['missing']) . ' خرید بیشتر، ارسال رایگان می‌شود.';
        }

        $lines[] = '(هزینهٔ نهایی را در صفحهٔ تسویه با نشانی شما می‌بینید.)';

        return implode("\n", $lines);
    }

    /**
     * Placeholder values for canned replies.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $tiers = $this->policy()['tiers'];
        $free  = $this->free_threshold();
        $fee   = [] !== $tiers ? (int) reset($tiers) : (int) ($this->fee_for_weight(0.0) ?? 0);

        return [
            '{free_min}'     => $free > 0 ? $this->money($free) : '—',
            '{ship_fee}'     => $fee > 0 ? $this->money($fee) : '—',
            '{ship_summary}' => $this->summary(),
        ];
    }
}
