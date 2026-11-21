<?php
/**
 * Platform helpers.
 *
 * The only global functions the platform layer adds. Everything else is
 * resolved through the container.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get the platform container (or a resolved service).
 *
 * @param string|null $service Optional service id to resolve directly.
 *
 * @return mixed VKL_Container when $service is null, otherwise the service.
 */
function vkl(?string $service = null): mixed
{
    static $container = null;

    if (null === $container) {
        $container = new VKL_Container();

        /**
         * Fires once when the platform container is created.
         * Core services and modules register themselves here.
         *
         * @param VKL_Container $container The container.
         */
        do_action('vkl_container_init', $container);
    }

    return null === $service ? $container : $container->make($service);
}

/**
 * Dispatch a platform event.
 *
 * @param string               $event   Event name, e.g. 'cart.updated'.
 * @param array<string, mixed> $payload Payload.
 */
function vkl_dispatch(string $event, array $payload = []): void
{
    vkl('events')->dispatch($event, $payload);
}

/**
 * Register a platform event listener.
 *
 * @param string   $event    Event name.
 * @param callable $listener Listener (array $payload, string $event).
 * @param int      $priority Priority, lower runs earlier.
 */
function vkl_listen(string $event, callable $listener, int $priority = 10): void
{
    vkl('events')->listen($event, $listener, $priority);
}

/**
 * Require a plugin file, but never take the whole site down when it is
 * momentarily absent.
 *
 * Uploading a new build over the old one leaves brief windows where a file
 * has been deleted but not yet written; a plain require_once then throws a
 * fatal on every request. This logs the gap and lets the rest of the
 * plugin carry on.
 */
function vkl_require(string $relative): bool
{
    $path = VKL_PATH . $relative;

    if (!is_readable($path)) {
        error_log('VKL: missing plugin file ' . $relative . ' — skipping (re-upload the plugin if this persists).');

        return false;
    }

    require_once $path;

    return true;
}

/**
 * Is this order a carrier for money that is not a sale?
 *
 * Repaying an instalment and topping up the wallet both travel through a
 * WooCommerce order, because that is the only way the payment gateway can
 * be reached. Nothing was sold, though: counting it as revenue books the
 * same purchase twice, and messaging it as a purchase texts the customer
 * about goods they never ordered.
 *
 * One definition for the whole plugin — the SMS module had a private copy
 * of this and the admin-notification module had none, which is exactly
 * why the shop got two messages for one repayment.
 */
function vkl_is_carrier_order(int $order_id): bool
{
    if ($order_id <= 0 || !function_exists('wc_get_order')) {
        return false;
    }

    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order) {
        return false;
    }

    if (class_exists('VKL_Wallet_Installments')) {
        $plan_id = (int) $order->get_meta(VKL_Wallet_Installments::ORDER_META);

        if ($plan_id > 0) {
            // This meta alone does not say WHICH of two things happened:
            // an existing plan being repaid (a carrier order — nothing
            // sold), or credit being consumed to buy real goods, which
            // stamps the very same meta on the SALE order itself so a
            // retried payment does not consume the credit twice. Only the
            // installments table can tell them apart: a plan remembers
            // which order originated it in its own 'order_id' column,
            // never touched by a repayment. If this order is that
            // origin, it is a genuine sale, not a carrier.
            $plan = null;

            try {
                $plan = vkl('wallet.installments')->find($plan_id);
            } catch (Throwable $e) {
                $plan = null;
            }

            if (null === $plan || (int) $plan['order_id'] !== $order_id) {
                return true;
            }
        }
    }

    // A checkout that repays several plans at once carries only this
    // meta (no single-plan id), so it used to look like an ordinary sale:
    // it was listed as an order and texted to the customer and the shop.
    if ('' !== (string) $order->get_meta('_vkl_installment_plans')) {
        return true;
    }

    if (class_exists('VKL_Wallet_Topup')
        && '' !== (string) $order->get_meta(VKL_Wallet_Topup::ORDER_META)
    ) {
        return true;
    }

    // Belt and braces. The metas above are written at checkout, so an
    // order that reached the shop by any other road — an admin creating
    // it by hand, a gateway rebuilding it, a restored draft — would look
    // like an ordinary sale and be messaged as one. The dedicated status
    // and the top-up product itself say the same thing without relying on
    // that one moment at checkout.
    if (class_exists('VKL_Wallet_Carrier_Order')
        && VKL_Wallet_Carrier_Order::STATUS === $order->get_status()
    ) {
        return true;
    }

    // Removed: checking whether the top-up product sits in the order's
    // line items was too broad. The top-up product is a virtual carrier;
    // a customer who somehow adds it to a normal cart would have every
    // ordinary purchase flagged as a repayment. The two metas and the
    // dedicated status are sufficient and precise.

    return false;
}

/**
 * Attachment image URL that stays quiet when the attachment is broken.
 *
 * Asking WordPress for a size of an attachment whose metadata is missing
 * makes it read an array offset on false deep inside media.php, which
 * fills the debug log with warnings on every page view. Checking first
 * costs nothing and keeps our own calls out of that log.
 */
function vkl_image_url(int $attachment_id, string $size = 'thumbnail'): string
{
    if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
        return '';
    }

    $meta = wp_get_attachment_metadata($attachment_id);

    // No metadata means the file or its sizes are gone; fall back to the
    // original URL rather than asking for a size that cannot be resolved.
    if (!is_array($meta) || [] === $meta) {
        $full = wp_get_attachment_url($attachment_id);

        return is_string($full) ? $full : '';
    }

    $url = wp_get_attachment_image_url($attachment_id, $size);

    return is_string($url) ? $url : '';
}
