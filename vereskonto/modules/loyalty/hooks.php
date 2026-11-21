<?php
/**
 * Loyalty hooks — earn points when an order is completed. Idempotent per
 * order via the ledger reference.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Loyalty_Hooks
 */
final class VKL_Loyalty_Hooks
{
    private VKL_Loyalty_Repository $repository;

    private VKL_Config $config;

    public function __construct(VKL_Loyalty_Repository $repository, VKL_Config $config)
    {
        $this->repository = $repository;
        $this->config     = $config;
    }

    public function register(): void
    {
        vkl_listen('order.status_changed', [$this, 'on_order_status']);
    }

    /**
     * @param array<string, mixed> $payload Order event payload.
     */
    public function on_order_status(array $payload): void
    {
        if ('completed' !== ($payload['new_status'] ?? '') || !$this->config->get_bool('loyalty_enabled', false)) {
            return;
        }

        $user_id = (int) ($payload['user_id'] ?? 0);
        $per     = max(1, $this->config->get_int('loyalty_amount_per_point', 100000));

        if ($user_id <= 0) {
            return;
        }

        // Wallet top-ups and instalment repayments move money, they are
        // not purchases — no points. Awarding points for repaying a loan
        // would pay the customer for settling their own debt.
        if (function_exists('wc_get_order')) {
            $order = wc_get_order((int) ($payload['order_id'] ?? 0));

            if ($order
                && ('' !== (string) $order->get_meta('_vkl_wallet_topup')
                    || 0 !== (int) $order->get_meta('_vkl_installment_plan'))
            ) {
                return;
            }
        }

        $reference = 'order:' . (int) ($payload['order_id'] ?? 0);

        if ($this->repository->exists('order_completed', $reference)) {
            return;
        }

        $points = (int) floor(((float) ($payload['total'] ?? 0)) / $per);

        if ($points <= 0) {
            return;
        }

        $this->repository->append($user_id, $points, 'order_completed', $reference);

        vkl_dispatch('loyalty.points_earned', [
            'user_id' => $user_id,
            'points'  => $points,
            'balance' => $this->repository->balance($user_id),
        ]);
    }
}
