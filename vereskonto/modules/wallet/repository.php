<?php
/**
 * Wallet repository — append-only ledger in wp_vkl_wallet.
 * Balance is SUM(amount) per user (indexed); rows are never mutated.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Repository
 */
final class VKL_Wallet_Repository extends VKL_Repository
{
    protected function table_name(): string
    {
        return 'wallet';
    }

    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.5.0', static function (VKL_Database $db): void {

            $charset = $db->charset_collate();

            dbDelta(
                'CREATE TABLE ' . $db->table('wallet') . " (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    amount DECIMAL(18,4) NOT NULL,
                    reason VARCHAR(50) NOT NULL,
                    reference VARCHAR(100) NOT NULL DEFAULT '',
                    note TEXT NULL,
                    created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY reason (reason),
                    KEY created_at (created_at)
                ) {$charset};"
            );
        });
    }

    /**
     * Current balance for a user.
     */
    public function balance(int $user_id): float
    {
        return (float) ($this->db->get_var(
            'SELECT COALESCE(SUM(amount), 0) FROM {wallet} WHERE user_id = %d',
            $user_id
        ) ?? 0);
    }

    /**
     * Append a ledger entry. Positive amount = credit, negative = debit.
     */
    public function append(int $user_id, float $amount, string $reason, string $reference = '', string $note = ''): int
    {
        return $this->insert_row(
            [
                'user_id'    => $user_id,
                'amount'     => $amount,
                'reason'     => $reason,
                'reference'  => $reference,
                'note'       => $note,
                'created_by' => get_current_user_id(),
                'created_at' => $this->now(),
            ],
            ['%d', '%f', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Whether a ledger entry with this reason+reference already exists
     * (idempotency guard for order debits/refunds).
     */
    public function exists(string $reason, string $reference): bool
    {
        return null !== $this->db->get_var(
            'SELECT id FROM {wallet} WHERE reason = %s AND reference = %s LIMIT 1',
            $reason,
            $reference
        );
    }

    /**
     * Latest transactions for one user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest_for_user(int $user_id, int $limit = 20): array
    {
        return $this->db->get_results(
            'SELECT * FROM {wallet} WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            $user_id,
            $limit
        );
    }

    /**
     * Latest transactions across all users (admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 30): array
    {
        return $this->db->get_results('SELECT * FROM {wallet} ORDER BY id DESC LIMIT %d', $limit);
    }
}
