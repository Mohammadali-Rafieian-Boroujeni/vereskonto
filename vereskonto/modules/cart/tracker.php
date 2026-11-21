<?php
/**
 * Cart tracker.
 *
 * Builds snapshots of the WooCommerce cart and records actions. Writes
 * are buffered and flushed once per request on shutdown, so a request
 * that fires five cart hooks still costs one snapshot write.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Tracker
 */
final class VKL_Cart_Tracker
{
    /**
     * Repository.
     */
    private VKL_Cart_Repository $repository;

    /**
     * Event manager.
     */
    private VKL_EventManager $events;

    /**
     * Validator.
     */
    private VKL_Validator $validator;

    /**
     * Buffered history actions for this request.
     *
     * @var array<int, array{action: string, product_id: int, quantity: int}>
     */
    private array $pending_actions = [];

    /**
     * Whether a flush has been scheduled for this request.
     */
    private bool $flush_scheduled = false;

    /**
     * @param VKL_Cart_Repository $repository Repository.
     * @param VKL_EventManager    $events     Event manager.
     * @param VKL_Validator       $validator  Validator.
     */
    public function __construct(
        VKL_Cart_Repository $repository,
        VKL_EventManager $events,
        VKL_Validator $validator
    ) {
        $this->repository = $repository;
        $this->events     = $events;
        $this->validator  = $validator;
    }

    /**
     * Record a cart action and schedule the end-of-request flush.
     *
     * @param string $action     Action: add|update|remove|restore|empty.
     * @param int    $product_id Product id (0 when not item-specific).
     * @param int    $quantity   Quantity involved.
     */
    /**
     * Layered bot detection — the industry-standard trio:
     *
     * 1. USER AGENT: empty UA or a known crawler/monitor/script signature
     *    (Googlebot, Ahrefs, curl, python, headless Chrome, uptime
     *    monitors, link previews, ...).
     * 2. COOKIE PERSISTENCE: real browsers return cookies; most bots
     *    fire single stateless requests. A guest request carrying no
     *    cookies at all has no recoverable session anyway.
     * 3. JS PROOF: a tiny footer script sets `vkl_human=1` — crawlers
     *    that never execute JavaScript can't obtain it. Required for
     *    guests only; logged-in customers are always tracked.
     */
    private function is_probably_bot(): bool
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : ''; // phpcs:ignore

        if ('' === $ua) {
            return true;
        }

        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python|java\/|httpclient|headless|phantom|lighthouse|pingdom|gtmetrix|uptime|monitor|scan|preview|facebookexternalhit|whatsapp|telegrambot|ahrefs|semrush|mj12|dotbot|petalbot|bytespider/i', $ua)) {
            return true;
        }

        if (0 === get_current_user_id()) {
            if ([] === $_COOKIE || !isset($_COOKIE['vkl_human'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Footer beacon: JS sets the human-proof cookie once. Hooked from
     * the loader on wp_footer.
     */
    public function human_beacon(): void
    {
        if (isset($_COOKIE['vkl_human'])) {
            return;
        }

        echo "<script>document.cookie='vkl_human=1;path=/;max-age=2592000;SameSite=Lax'</script>";
    }

    public function record(string $action, int $product_id = 0, int $quantity = 0): void
    {
        if ($this->is_probably_bot()) {
            return;
        }

        $this->pending_actions[] = [
            'action'     => $action,
            'product_id' => $product_id,
            'quantity'   => $quantity,
        ];

        if (!$this->flush_scheduled) {
            $this->flush_scheduled = true;

            add_action('shutdown', [$this, 'flush'], 5);
        }
    }

    /**
     * Persist the snapshot and buffered actions. Public because it is a
     * shutdown hook callback; never call it directly.
     */
    public function flush(): void
    {
        if (!$this->pending_actions || !function_exists('WC') || null === WC()->cart) {
            $this->pending_actions = [];

            return;
        }

        $snapshot = $this->build_snapshot();

        if ('' === $snapshot['cart_token']) {
            $this->pending_actions = [];

            return;
        }

        // An empty cart is noise, not a lead: storing it is what inflated
        // the stats into the thousands (one eternal row per guest session).
        if (0 === (int) $snapshot['item_count']) {
            $this->repository->purge_token($snapshot['cart_token']);
            $this->pending_actions = [];

            return;
        }

        $previous_status = $this->repository->save_snapshot($snapshot);

        foreach ($this->pending_actions as $entry) {
            $this->repository->add_history(
                $snapshot['cart_token'],
                $snapshot['user_id'],
                $snapshot['mobile'],
                $entry['action'],
                $entry['product_id'],
                $entry['quantity'],
                $snapshot['total']
            );
        }

        $this->pending_actions = [];

        if (VKL_Cart_Repository::STATUS_ABANDONED === $previous_status) {
            $this->repository->add_history(
                $snapshot['cart_token'],
                $snapshot['user_id'],
                $snapshot['mobile'],
                'recovered',
                0,
                0,
                $snapshot['total']
            );

            $this->events->dispatch('cart.recovered', $snapshot);
        }

        $this->events->dispatch('cart.updated', $snapshot);
    }

    /**
     * Mark the current cart as converted after checkout.
     *
     * @param int $order_id WooCommerce order id.
     */
    public function convert(int $order_id): void
    {
        $token = $this->cart_token();

        if ('' === $token) {
            return;
        }

        $order  = wc_get_order($order_id);
        $mobile = $order ? $this->validator->normalize_mobile((string) $order->get_billing_phone()) : '';

        $this->repository->mark_converted($token);

        $this->repository->add_history(
            $token,
            $order ? (int) $order->get_customer_id() : get_current_user_id(),
            $this->validator->is_mobile($mobile) ? $mobile : '',
            'converted',
            0,
            0,
            $order ? (float) $order->get_total() : 0.0
        );

        // Converted carts must not flush an 'active' snapshot afterwards.
        $this->pending_actions = [];

        $this->events->dispatch('cart.converted', [
            'cart_token' => $token,
            'order_id'   => $order_id,
        ]);
    }

    /**
     * Build the current cart snapshot.
     *
     * @return array{
     *     cart_token: string,
     *     user_id: int,
     *     mobile: string,
     *     items: array,
     *     item_count: int,
     *     total: float,
     *     currency: string
     * }
     */
    private function build_snapshot(): array
    {
        $cart  = WC()->cart;
        $items = [];

        foreach ($cart->get_cart() as $item) {
            $product = $item['data'] ?? null;

            $items[] = [
                'product_id'   => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'name'         => $product instanceof WC_Product ? $product->get_name() : '',
                'quantity'     => (int) ($item['quantity'] ?? 0),
                'line_total'   => (float) ($item['line_total'] ?? 0),
            ];
        }

        return [
            'cart_token' => $this->cart_token(),
            'user_id'    => get_current_user_id(),
            'mobile'     => $this->resolve_mobile(),
            'items'      => $items,
            'item_count' => (int) $cart->get_cart_contents_count(),
            'total'      => (float) $cart->get_cart_contents_total(),
            'currency'   => get_woocommerce_currency(),
        ];
    }

    /**
     * Stable identifier for the current cart.
     *
     * Logged-in users: "u{ID}" (stable across devices).
     * Guests: the WooCommerce session customer id.
     */
    private function cart_token(): string
    {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return 'u' . $user_id;
        }

        if (null !== WC()->session) {
            $session_id = (string) WC()->session->get_customer_id();

            if ('' !== $session_id) {
                return 'g' . substr($session_id, 0, 60);
            }
        }

        return '';
    }

    /**
     * Resolve a valid mobile number for the current customer, or ''.
     */
    private function resolve_mobile(): string
    {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            $mobile = $this->validator->normalize_mobile(
                (string) get_user_meta($user_id, 'billing_phone', true)
            );

            if ($this->validator->is_mobile($mobile)) {
                return $mobile;
            }
        }

        if (null !== WC()->customer) {
            $mobile = $this->validator->normalize_mobile(
                (string) WC()->customer->get_billing_phone()
            );

            if ($this->validator->is_mobile($mobile)) {
                return $mobile;
            }
        }

        return '';
    }
}
