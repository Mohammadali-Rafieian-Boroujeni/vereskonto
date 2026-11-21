<?php
/**
 * Review-request SMS — closes the review loop with correct timing.
 *
 * CRM timing: the request goes out a configurable number of days AFTER
 * the order completes (the customer has actually lived with the product),
 * via a one-shot scheduled event. Sent at most once per order.
 * Pattern variables: name, order, link (the account Surveys/Reviews tab,
 * where reviews earn club points).
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.13.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Review
 */
final class VKL_Sms_Review
{
    public const HOOK = 'vkl_review_request';

    private const META_SENT = '_vkl_review_sms';

    private VKL_Config $config;

    private VKL_Sms_Sender $sender;

    private VKL_Validator $validator;

    public function __construct(VKL_Config $config, VKL_Sms_Sender $sender, VKL_Validator $validator)
    {
        $this->config    = $config;
        $this->sender    = $sender;
        $this->validator = $validator;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_order_status_processing', [$this, 'schedule'], 30, 1);
        add_action('woocommerce_order_status_completed', [$this, 'schedule'], 30, 1);
        add_action(self::HOOK, [$this, 'send'], 10, 1);
        add_filter('woocommerce_order_actions', [$this, 'add_order_action'], 10, 1);
        add_action('woocommerce_order_action_vkl_resend_review_sms', [$this, 'handle_manual_send'], 10, 1);
    }

    /**
     * Queue the one-shot request when an order completes.
     *
     * @param mixed $order_id Order id.
     */
    public function schedule(mixed $order_id): void
    {
        if (!$this->config->get_bool('review_sms_enabled', false)
            || '' === $this->config->get_string('review_pattern')
        ) {
            return;
        }

        $order = wc_get_order((int) $order_id);

        // Carrier orders (top-ups, installments) have nothing to review.
        // vkl_is_carrier_order() also checks the vkl-repay status and
        // the top-up product in the cart, so a re-classified order cannot
        // sneak through.
        if (!$order instanceof WC_Order
            || vkl_is_carrier_order((int) $order_id)
            || '' !== (string) $order->get_meta(self::META_SENT)
        ) {
            return;
        }

        $days = max(1, $this->config->get_int('review_delay_days', 3));

        if (!wp_next_scheduled(self::HOOK, [(int) $order_id])) {
            wp_schedule_single_event(time() + $days * DAY_IN_SECONDS, self::HOOK, [(int) $order_id]);
        }
    }

    /**
     * Send the request (idempotent per order).
     *
     * @param mixed $order_id Order id.
     */
    public function send(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order || '' !== (string) $order->get_meta(self::META_SENT)) {
            return;
        }

        // Re-check at send time: settings may have changed since scheduling.
        if (!$this->config->get_bool('review_sms_enabled', false)
            || '' === $this->config->get_string('review_pattern')
        ) {
            return;
        }

        $mobile = $this->validator->normalize_mobile((string) $order->get_billing_phone());

        if (!$this->validator->is_mobile($mobile)) {
            // Log so the shop can spot orders that will never get a review
            // request — the most common cause is a missing phone number.
            vkl('logger')->info('Review SMS skipped: no valid mobile.', [
                'order' => (int) $order_id,
                'phone' => (string) $order->get_billing_phone(),
            ]);

            return;
        }

        $ok = $this->sender->send($mobile, 'review.request', $this->config->get_string('review_pattern'), [
            'name'  => $this->sender->resolve_name((int) $order->get_customer_id()),
            // No link: the SMS provider rejects URLs inside an approved
            // pattern, so the review request carries name and order only.
            'order' => (string) $order->get_order_number(),
        ]);

        // Only remember it as sent once it actually went out. Marking it
        // BEFORE the gateway call meant a single transient failure (rate
        // limit, no credit, timeout, WP-Cron running late) silently buried
        // the request forever — this one-shot event had already fired and
        // nothing would ever retry it. Leaving the meta empty on failure is
        // what lets the manual "send now" order action pick it back up.
        if ($ok) {
            $order->update_meta_data(self::META_SENT, gmdate('Y-m-d H:i:s'));
            $order->save();

            return;
        }

        $order->add_order_note(
            __('Vereskonto: the automatic review-request SMS failed to send. Use the order action "Vereskonto: send review-request SMS now" to retry, or check the SMS log for the gateway response.', 'vereskala-login')
        );
    }

    /**
     * Offer a manual "send now" action in the order's Actions box — the
     * fallback for when the automatic, WP-Cron-driven send (schedule() +
     * send() above) never fired or failed.
     *
     * @param array<string, string> $actions Existing order actions.
     *
     * @return array<string, string>
     */
    public function add_order_action(array $actions): array
    {
        global $theorder;

        $order = $theorder instanceof WC_Order ? $theorder : null;

        if (!$order instanceof WC_Order || vkl_is_carrier_order($order->get_id())) {
            return $actions;
        }

        $actions['vkl_resend_review_sms'] = __('Vereskonto: send review-request SMS now', 'vereskala-login');

        return $actions;
    }

    /**
     * Handle the manual "send now" order action. Deliberately ignores the
     * review_sms_enabled toggle and the META_SENT guard — an admin
     * explicitly asking for this one order is an override, not a retry of
     * the same automatic decision.
     */
    public function handle_manual_send(WC_Order $order): void
    {
        if (vkl_is_carrier_order($order->get_id())) {
            $order->add_order_note(__('Vereskonto: review-request SMS not sent — this order is a repayment/top-up carrier, not a sale.', 'vereskala-login'));

            return;
        }

        if ('' === $this->config->get_string('review_pattern')) {
            $order->add_order_note(__('Vereskonto: could not send the review-request SMS — no pattern is configured under Vereskonto settings.', 'vereskala-login'));

            return;
        }

        $mobile = $this->validator->normalize_mobile((string) $order->get_billing_phone());

        if (!$this->validator->is_mobile($mobile)) {
            $order->add_order_note(__('Vereskonto: could not send the review-request SMS — no valid mobile number on this order.', 'vereskala-login'));

            return;
        }

        $ok = $this->sender->send($mobile, 'review.request', $this->config->get_string('review_pattern'), [
            'name'  => $this->sender->resolve_name((int) $order->get_customer_id()),
            'order' => (string) $order->get_order_number(),
        ]);

        if ($ok) {
            $order->update_meta_data(self::META_SENT, gmdate('Y-m-d H:i:s'));
        }

        $order->add_order_note(
            $ok
                ? __('Vereskonto: review-request SMS sent manually by an admin.', 'vereskala-login')
                : __('Vereskonto: manual review-request SMS attempt failed — check the SMS log for the gateway response.', 'vereskala-login')
        );
        $order->save();
    }
}
