<?php
/**
 * Cart hooks.
 *
 * The single place where WooCommerce hooks are bound to the cart tracker.
 * No persistence, no business rules — translation from WooCommerce hook
 * signatures to tracker calls only.
 *
 * @package Vereskonto\Modules\Cart
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Cart_Hooks
 */
final class VKL_Cart_Hooks
{
    /**
     * Tracker.
     */
    private VKL_Cart_Tracker $tracker;

    /**
     * @param VKL_Cart_Tracker $tracker Tracker.
     */
    public function __construct(VKL_Cart_Tracker $tracker)
    {
        $this->tracker = $tracker;
    }

    /**
     * Attach all WooCommerce hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_add_to_cart', [$this, 'on_add'], 20, 4);
        add_action('woocommerce_after_cart_item_quantity_update', [$this, 'on_update'], 20, 3);
        add_action('woocommerce_cart_item_removed', [$this, 'on_remove'], 20, 2);
        add_action('woocommerce_cart_item_restored', [$this, 'on_restore'], 20, 2);
        add_action('woocommerce_cart_emptied', [$this, 'on_empty'], 20);
        add_action('woocommerce_checkout_order_processed', [$this, 'on_checkout'], 20, 1);
    }

    /**
     * Item added to cart.
     *
     * @param string $cart_item_key Cart item key.
     * @param mixed  $product_id    Product id.
     * @param mixed  $quantity      Quantity.
     * @param mixed  $variation_id  Variation id.
     */
    public function on_add(string $cart_item_key, mixed $product_id, mixed $quantity, mixed $variation_id): void
    {
        unset($cart_item_key, $variation_id);

        $this->tracker->record('add', (int) $product_id, (int) $quantity);
    }

    /**
     * Item quantity changed.
     *
     * @param string $cart_item_key Cart item key.
     * @param mixed  $quantity      New quantity.
     * @param mixed  $old_quantity  Old quantity.
     */
    public function on_update(string $cart_item_key, mixed $quantity, mixed $old_quantity): void
    {
        unset($old_quantity);

        $product_id = 0;

        if (function_exists('WC') && null !== WC()->cart) {
            $item       = WC()->cart->get_cart_item($cart_item_key);
            $product_id = (int) ($item['product_id'] ?? 0);
        }

        $this->tracker->record('update', $product_id, (int) $quantity);
    }

    /**
     * Item removed from cart.
     *
     * @param string $cart_item_key Cart item key.
     * @param mixed  $cart          WC_Cart instance.
     */
    public function on_remove(string $cart_item_key, mixed $cart): void
    {
        $product_id = 0;

        if ($cart instanceof WC_Cart) {
            $item       = $cart->removed_cart_contents[$cart_item_key] ?? [];
            $product_id = (int) ($item['product_id'] ?? 0);
        }

        $this->tracker->record('remove', $product_id);
    }

    /**
     * Removed item restored ("undo").
     *
     * @param string $cart_item_key Cart item key.
     * @param mixed  $cart          WC_Cart instance.
     */
    public function on_restore(string $cart_item_key, mixed $cart): void
    {
        $product_id = 0;

        if ($cart instanceof WC_Cart) {
            $item       = $cart->get_cart_item($cart_item_key);
            $product_id = (int) ($item['product_id'] ?? 0);
        }

        $this->tracker->record('restore', $product_id);
    }

    /**
     * Cart emptied.
     */
    public function on_empty(): void
    {
        $this->tracker->record('empty');
    }

    /**
     * Order placed at checkout.
     *
     * @param mixed $order_id Order id.
     */
    public function on_checkout(mixed $order_id): void
    {
        $this->tracker->convert((int) $order_id);
    }
}
