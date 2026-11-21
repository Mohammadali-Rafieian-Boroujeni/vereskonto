<?php
/**
 * Wallet service — the only writer of the wallet ledger. Enforces the
 * no-overdraft rule and dispatches wallet events.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Service
 */
final class VKL_Wallet_Service
{
    private VKL_Wallet_Repository $repository;

    private VKL_Validator $validator;

    public function __construct(VKL_Wallet_Repository $repository, VKL_Validator $validator)
    {
        $this->repository = $repository;
        $this->validator  = $validator;
    }

    public function balance(int $user_id): float
    {
        return $this->repository->balance($user_id);
    }

    /**
     * Credit a user's wallet.
     */
    public function credit(int $user_id, float $amount, string $reason, string $reference = '', string $note = ''): bool
    {
        if ($user_id <= 0 || $amount <= 0) {
            return false;
        }

        $this->repository->append($user_id, $amount, $reason, $reference, $note);

        vkl_dispatch('wallet.charged', [
            'user_id' => $user_id,
            'mobile'  => $this->user_mobile($user_id),
            'amount'  => $amount,
            'reason'  => $reason,
            'balance' => $this->repository->balance($user_id),
        ]);

        return true;
    }

    /**
     * Debit a user's wallet (fails on insufficient balance).
     */
    public function debit(int $user_id, float $amount, string $reason, string $reference = '', string $note = ''): bool
    {
        if ($user_id <= 0 || $amount <= 0 || $this->repository->balance($user_id) < $amount) {
            return false;
        }

        $this->repository->append($user_id, -$amount, $reason, $reference, $note);

        vkl_dispatch('wallet.debited', [
            'user_id' => $user_id,
            'mobile'  => $this->user_mobile($user_id),
            'amount'  => $amount,
            'reason'  => $reason,
            'balance' => $this->repository->balance($user_id),
        ]);

        return true;
    }

    /**
     * Idempotency guard, exposed for order flows.
     */
    public function already_processed(string $reason, string $reference): bool
    {
        return $this->repository->exists($reason, $reference);
    }

    private function user_mobile(int $user_id): string
    {
        $mobile = $this->validator->normalize_mobile(
            (string) get_user_meta($user_id, 'billing_phone', true)
        );

        return $this->validator->is_mobile($mobile) ? $mobile : '';
    }
}
