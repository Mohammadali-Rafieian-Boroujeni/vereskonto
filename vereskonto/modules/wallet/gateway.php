<?php
/**
 * "Installment purchase" payment gateway (BNPL from granted credit).
 *
 * Shows up at checkout only when the logged-in customer's unused
 * installment credit covers the order total. Paying consumes the credit
 * (the repayment plan starts at that moment — never before) and the
 * order is marked paid. Installment orders keep whatever shipping the
 * customer chose, free shipping included.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.13.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('VKL_GATEWAY_HOOKS')) {
    define('VKL_GATEWAY_HOOKS', true);

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
    $gateways[] = 'VKL_Installment_Gateway';

    return $gateways;
});
}

// Module loaders are included before WooCommerce finishes loading, so the
// abstract gateway class may not exist yet — in that case re-include this
// file once WooCommerce is ready (the hooks above are constant-guarded).
if (!class_exists('WC_Payment_Gateway')) {
    // Re-enter this file once WooCommerce has defined its base class.
    // The path is captured now rather than relying on __FILE__ inside the
    // closure, and the file is read directly instead of require'd: a
    // second require of an already-included file was fatal, and twenty of
    // those in eight seconds is enough to exhaust the worker pool.
    $vkl_gateway_file = __FILE__;

    add_action('plugins_loaded', static function () use ($vkl_gateway_file): void {
        if (!class_exists('WC_Payment_Gateway') || class_exists('VKL_Installment_Gateway')) {
            return;
        }

        if (is_readable($vkl_gateway_file)) {
            include $vkl_gateway_file;
        } else {
            error_log('VKL: gateway file missing at ' . $vkl_gateway_file);
        }
    }, 11);

    return;
}

if (!class_exists('VKL_Installment_Gateway')) :

    /**
     * Class VKL_Installment_Gateway
     */
    final class VKL_Installment_Gateway extends WC_Payment_Gateway
    {
        /**
         * Constructor.
         */
        public function __construct()
        {
            $this->id                 = 'vkl_installment';
            $this->method_title       = __('Installment purchase (Vereskonto)', 'vereskala-login');
            $this->method_description = __('Lets customers with granted installment credit buy now and repay in installments from their Wallet tab.', 'vereskala-login');
            $this->has_fields         = false;
            $this->title              = 'خرید اقساطی ورسکالا';

            // Must be a string, never null: WooCommerce passes this to
            // wp_kses_post(), and on PHP 8.1+ a null there prints a
            // deprecation notice into the middle of the checkout page.
            $this->description        = __('Buy now and repay in instalments from your wallet.', 'vereskala-login');

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled', 'yes');

            // Icon beside the option at checkout: the shop's own image if
            // set in the gateway settings, else the bundled mark.
            $icon       = trim((string) $this->get_option('icon_url', ''));
            $this->icon = '' !== $icon ? esc_url_raw($icon) : VKL_URL . 'assets/installment.svg';

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        /**
         * Minimal settings.
         */
        public function init_form_fields(): void
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Enabled', 'vereskala-login'),
                    'type'    => 'checkbox',
                    'label'   => __('Installment purchase (Vereskonto)', 'vereskala-login'),
                    'default' => 'yes',
                ],
                'icon_url' => [
                    'title'       => 'نماد کنار درگاه',
                    'type'        => 'text',
                    'description' => 'نشانی تصویر نماد (از «کتابخانهٔ رسانه» نشانی پرونده را کپی کنید). خالی = نماد پیش‌فرض ورسکالا.',
                    'default'     => '',
                    'desc_tip'    => false,
                ],
            ];
        }

        /**
         * Offered to everyone at checkout (except carts where it makes no
         * sense). A customer without credit used to simply not see it and
         * never learned that it exists; now they see it, and choosing it
         * sends them to the loan-request page (see process_payment()).
         */
        public function is_available(): bool
        {
            if ('yes' !== $this->enabled || !function_exists('WC') || null === WC()->cart) {
                return false;
            }

            // Top-ups and repayments must be paid with real money.
            if (class_exists('VKL_Wallet_Topup') && VKL_Wallet_Topup::cart_has_topup(WC()->cart)) {
                return false;
            }

            if (class_exists('VKL_Wallet_Installments') && VKL_Wallet_Installments::cart_is_installment()) {
                return false;
            }

            // Fills in the title ("... your credit: X") when usable.
            $this->unavailable_reason();

            return true;
        }

        /**
         * Checkout explanation under the option: what will happen if it is chosen.
         */
        public function get_description(): string
        {
            if (is_admin() || !function_exists('WC') || null === WC()->cart) {
                return (string) $this->description;
            }

            $reason = $this->unavailable_reason();

            if ('' === $reason) {
                return 'با اعتبار اقساطی خود همین حالا بخرید و ماهانه از برگهٔ «کیف پول» بازپرداخت کنید.';
            }

            return $reason . ' — اگر این روش را انتخاب کنید، به برگهٔ درخواست وام و اعتبار هدایت می‌شوید.';
        }

        /**
         * Where a customer who cannot pay by credit is sent instead.
         */
        private function fallback_url(bool $overdue): string
        {
            if (!is_user_logged_in()) {
                return wc_get_page_permalink('myaccount');
            }

            return wc_get_account_endpoint_url(
                $overdue ? VKL_Wallet_Account::ENDPOINT : VKL_Wallet_Account::CREDIT_ENDPOINT
            );
        }

        /**
         * Why the option is hidden, or '' when it should show.
         *
         * Five separate conditions used to hide this gateway silently,
         * which made "it just is not there" impossible to diagnose. The
         * reason is returned instead so the checkout can explain itself.
         */
        public function unavailable_reason(): string
        {
            // Checkout asks several times per request (availability, the
            // description, the hint box), and each answer walks every plan
            // of the customer. Remember it for the request, keyed by who is
            // asking and what the cart is worth right now.
            $cart = function_exists('WC') ? WC()->cart : null;
            $key  = get_current_user_id() . '|' . ($cart instanceof WC_Cart
                ? (float) $cart->get_cart_contents_total() . '|' . (float) $cart->get_shipping_total()
                : 'nocart');

            if (null !== $this->memo && $this->memo['key'] === $key) {
                if ('' === $this->memo['reason'] && '' !== $this->memo['title']) {
                    $this->title = $this->memo['title'];
                }

                return $this->memo['reason'];
            }

            $reason     = $this->compute_unavailable_reason();
            $this->memo = ['key' => $key, 'reason' => $reason, 'title' => '' === $reason ? (string) $this->title : ''];

            return $reason;
        }

        /**
         * Per-request memo of unavailable_reason().
         *
         * @var array{key: string, reason: string, title: string}|null
         */
        private ?array $memo = null;

        private function compute_unavailable_reason(): string
        {
            if ('yes' !== $this->enabled) {
                return __('The instalment gateway is switched off in WooCommerce payment settings.', 'vereskala-login');
            }

            if (!is_user_logged_in()) {
                return __('Only signed-in customers can pay in instalments.', 'vereskala-login');
            }

            if (null === WC()->cart) {
                return __('The cart is not available.', 'vereskala-login');
            }

            if (class_exists('VKL_Wallet_Topup') && VKL_Wallet_Topup::cart_has_topup(WC()->cart)) {
                return __('A wallet top-up cannot itself be bought in instalments.', 'vereskala-login');
            }

            if (class_exists('VKL_Wallet_Installments') && VKL_Wallet_Installments::cart_is_installment()) {
                return __('A repayment has to be paid with real money, not with more instalment credit.', 'vereskala-login');
            }

            try {
                /** @var VKL_Wallet_Installments $installments */
                $installments = vkl('wallet.installments');
            } catch (Throwable $e) {
                return __('The instalment module is not available.', 'vereskala-login');
            }

            $user_id = get_current_user_id();

            // Told apart from "no credit at all", because the fix is
            // completely different: pay the arrear, not request credit.
            if (method_exists($installments, 'has_overdue') && $installments->has_overdue($user_id)) {
                // Name the amount and the plan: "you have an arrear" alone
                // leaves the customer with no idea what to pay.
                $detail = method_exists($installments, 'overdue_summary')
                    ? (string) $installments->overdue_summary($user_id)
                    : '';

                return '' !== $detail
                    ? $detail
                    : __('You have an overdue repayment. Settle it first, then instalment purchase becomes available again.', 'vereskala-login');
            }

            $credit = $installments->available_credit($user_id);

            if ($credit <= 0) {
                return __('You have no instalment credit yet — request it from Financial services.', 'vereskala-login');
            }

            // Line items + shipping, not get_total(): on the checkout page
            // the total is mid-calculation and already carries the wallet
            // discount, so comparing against it made the option flicker.
            $cart    = WC()->cart;
            $payable = (float) $cart->get_cart_contents_total() + (float) $cart->get_shipping_total();

            if ($credit < $payable) {
                return sprintf(
                    /* translators: 1: available credit, 2: cart total. */
                    __('Your instalment credit (%1$s) does not cover this cart (%2$s).', 'vereskala-login'),
                    wp_strip_all_tags(wc_price($credit)),
                    wp_strip_all_tags(wc_price($payable))
                );
            }

            $this->title = sprintf(
                /* translators: %s: available credit. */
                __('Installment purchase (your credit: %s)', 'vereskala-login'),
                wp_strip_all_tags(wc_price($credit))
            );

            return '';
        }

        /**
         * Pay by consuming the credit; the repayment plan starts here.
         *
         * @param int $order_id Order id.
         *
         * @return array{result: string, redirect: string}
         */
        public function process_payment($order_id): array
        {
            $order = wc_get_order((int) $order_id);

            if (!$order instanceof WC_Order) {
                return ['result' => 'failure', 'redirect' => ''];
            }

            /** @var VKL_Wallet_Installments $installments */
            $installments = vkl('wallet.installments');

            // Idempotent: if a previous attempt already consumed credit
            // for this order (plan exists), do not consume again — just
            // complete. Without this, a transient WooCommerce error after
            // consume_credit() ate the credit and left the order unpaid;
            // retrying said "no credit" because it was already a plan.
            $existing = (int) $order->get_meta(VKL_Wallet_Installments::ORDER_META);

            if ($existing > 0 && null !== $installments->find($existing)) {
                if (!$order->is_paid()) {
                    try {
                        $order->add_order_note(__('Paid with Vereskonto installment credit; repayment plan activated.', 'vereskala-login'));
                        $order->payment_complete();
                    } catch (Throwable $e) {
                        $this->log_completion_failure($order, $e);

                        return ['result' => 'failure', 'redirect' => ''];
                    }
                }

                WC()->cart->empty_cart();

                return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
            }

            // Cannot pay by credit: do not fail the checkout with a dead-end
            // error — take the customer where the problem is solved. The
            // order stays unpaid (nothing was consumed) and the cart is kept.
            $buyer = (int) $order->get_customer_id();

            if ($buyer <= 0) {
                wc_add_notice('برای خرید اقساطی ابتدا وارد حساب کاربری خود شوید.', 'notice');

                return ['result' => 'success', 'redirect' => $this->fallback_url(false)];
            }

            $overdue = method_exists($installments, 'has_overdue') && $installments->has_overdue($buyer);

            if ($overdue) {
                wc_add_notice('ابتدا اقساط معوق خود را بپردازید؛ سپس خرید اقساطی دوباره فعال می‌شود.', 'notice');

                return ['result' => 'success', 'redirect' => $this->fallback_url(true)];
            }

            $credit = (float) $installments->available_credit($buyer);

            if ($credit < (float) $order->get_total()) {
                wc_add_notice(
                    $credit <= 0
                        ? 'هنوز اعتبار اقساطی ندارید. درخواست وام و اعتبار را ثبت کنید؛ پس از تأیید می‌توانید خرید اقساطی کنید.'
                        : sprintf('اعتبار اقساطی شما (%s) برای این خرید کافی نیست. می‌توانید افزایش اعتبار را درخواست کنید.', wp_strip_all_tags(wc_price($credit))),
                    'notice'
                );

                return ['result' => 'success', 'redirect' => $this->fallback_url(false)];
            }

            // Everything from here on — consume_credit() itself included,
            // not just payment_complete() afterwards — runs under one
            // try/catch. An earlier version only wrapped payment_complete(),
            // which missed a crash inside consume_credit() itself (after it
            // had already written the plan/order_id but before returning):
            // the order was left with a real debt, no order note at all
            // (this method never got far enough to add one), and nothing
            // to explain why in any log.
            try {
                if (!$installments->consume_credit((int) $order->get_customer_id(), (float) $order->get_total(), (int) $order_id)) {
                    wc_add_notice(__('Your installment credit no longer covers this order.', 'vereskala-login'), 'error');

                    return ['result' => 'failure', 'redirect' => ''];
                }

                $order->add_order_note(__('Paid with Vereskonto installment credit; repayment plan activated.', 'vereskala-login'));
                $order->payment_complete();
            } catch (Throwable $e) {
                // consume_credit() may have already created a real debt for
                // this order (that is by design when it succeeds — see its
                // own docblock). Whether the crash happened inside it or
                // after it, the customer must not be left owing money for
                // an order that never actually completed. restore_credit()
                // is a no-op if nothing was consumed yet (it requires the
                // plan meta to already be on the order), so calling it
                // unconditionally here is safe either way.
                $installments->restore_credit($order_id);
                $this->log_completion_failure($order, $e);
                wc_add_notice(__('An unexpected error occurred completing this order. Please try again, or contact support if it keeps happening.', 'vereskala-login'), 'error');

                return ['result' => 'failure', 'redirect' => ''];
            }

            WC()->cart->empty_cart();

            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }

        /**
         * Make a crash impossible to miss: written to the order (visible
         * in wp-admin with no log-file access needed), to error_log()
         * (lands in debug.log regardless of whether the container/logger
         * service is itself the thing that broke), and to Vereskonto's own
         * logger when available.
         */
        private function log_completion_failure(WC_Order $order, Throwable $e): void
        {
            $message = sprintf(
                'Vereskonto: installment payment_complete() crashed for order %d: %s in %s:%d',
                $order->get_id(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

            try {
                vkl('logger')->error($message, ['order' => $order->get_id()]);
            } catch (Throwable $ignored) {
                // Logger itself unavailable — the error_log() call above already covers it.
            }

            $order->add_order_note(
                sprintf(
                    /* translators: %s: exception message. */
                    __('Vereskonto: an unexpected error interrupted payment completion — %s. Check the server error log for the full trace.', 'vereskala-login'),
                    $e->getMessage()
                )
            );
        }
    }

endif;
