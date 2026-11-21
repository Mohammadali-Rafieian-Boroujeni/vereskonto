<?php
/**
 * Wallet SMS listeners — notify customers on wallet credit/debit.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Sms_Wallet
 */
final class VKL_Sms_Wallet
{
    private VKL_Sms_Sender $sender;

    private VKL_Config $config;

    public function __construct(VKL_Sms_Sender $sender, VKL_Config $config)
    {
        $this->sender = $sender;
        $this->config = $config;
    }

    public function register(): void
    {
        vkl_listen('wallet.charged', [$this, 'on_charged']);
    }

    /**
     * @param array<string, mixed> $payload {user_id, mobile, amount, balance}
     */
    public function on_charged(array $payload): void
    {
        $pattern = $this->config->get_string('wallet_pattern_charged');

        if (!$this->config->get_bool('wallet_sms_enabled', false) || '' === $pattern) {
            return;
        }

        $this->sender->send(
            (string) ($payload['mobile'] ?? ''),
            'wallet.charged',
            $pattern,
            [
                'name'   => $this->sender->resolve_name((int) ($payload['user_id'] ?? 0)),
                'amount' => number_format((float) ($payload['amount'] ?? 0)),
            ]
        );
    }
}
