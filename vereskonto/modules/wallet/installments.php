<?php
/**
 * Installment purchases (خرید اقساطی) — standardised, interest-agnostic.
 *
 * Model (deliberately simple and auditable):
 *  - The admin creates a PLAN for a customer: title, total amount, number
 *    of installments, day-of-month due day. The per-installment amount is
 *    total/count (last one absorbs rounding).
 *  - The customer sees the plan in the Wallet tab with progress and pays
 *    each installment through the normal checkout with ANY active
 *    gateway, using the same hidden carrier product as wallet top-ups.
 *  - Settlement is idempotent (an order settles a plan at most once,
 *    guarded by order meta) and refund-safe (cancelled/refunded
 *    installment orders roll the paid amount back once).
 *
 * No credit scoring or interest maths — those are business decisions the
 * admin encodes in the plan's total. Everything money-related lives in
 * one table and the order records, so accounting stays reconcilable.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   1.12.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Installments
 */
final class VKL_Wallet_Installments extends VKL_Repository
{
    public const ORDER_META = '_vkl_installment_plan';

    private const META_SETTLED = '_vkl_installment_settled';

    private const SESSION_KEY = 'vkl_installment_payment';

    private const SESSION_MULTI = 'vkl_installment_multi_payment';

    private const NONCE = 'vkl_installment_pay';

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Logger.
     */
    private VKL_Logger $logger;

    /**
     * @param VKL_Database $db     Database.
     * @param VKL_Config   $config Config.
     * @param VKL_Logger   $logger Logger.
     */
    public function __construct(VKL_Database $db, VKL_Config $config, VKL_Logger $logger)
    {
        parent::__construct($db);
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Table base name.
     */
    protected function table_name(): string
    {
        return 'installments';
    }

    /**
     * Register schema.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        // 1.13.0 re-declares the table adding 'type' (plan|credit) and
        // 'order_id' for BNPL credit consumed at checkout.
        // 1.15.0 re-declares once more, adding late-fee bookkeeping
        // ('penalty' accumulated amount + 'last_penalty' Y-m marker).
        // 1.45.1 re-declares to add 'last_notice' (monthly-notice stamp),
        // which 1.40.0 added to the schema without a migration to run it.
        foreach (['1.12.0', '1.13.0', '1.15.0', '1.45.1'] as $version) {
            $migrator->register($version, [self::class, 'create_table']);
        }

        // 2.3.0 adds two things that a re-declared CREATE TABLE cannot
        // deliver on a site that already has the table: a ledger for
        // repayments received outside the shop (card-to-card, cash at the
        // counter) and a per-plan late-fee rate. Both idempotent, so a
        // second run and a fresh install behave the same.
        $migrator->register('2.3.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_installment_payments (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    plan_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL,
                    amount DECIMAL(18,4) NOT NULL,
                    method VARCHAR(20) NOT NULL DEFAULT 'card',
                    reference VARCHAR(120) NOT NULL DEFAULT '',
                    note VARCHAR(255) NOT NULL DEFAULT '',
                    admin_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY plan_id (plan_id),
                    KEY user_id (user_id)
                ) {$charset};"
            );

            $table = $wpdb->prefix . 'vkl_installments';
            $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            // -1 means "use the shop-wide rate"; 0 really means no fee.
            if (is_array($cols) && !in_array('penalty_percent', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN penalty_percent DECIMAL(5,2) NOT NULL DEFAULT -1 AFTER last_penalty"); // phpcs:ignore
            }
        });

        // 2.5.2: penalty_paid only exists since 2.4.1, so every fee paid
        // before that upgrade still looked open — the customer was told
        // to pay it again with the next instalment. Reconstruct it from
        // the ledger: money beyond whole instalments can only have been
        // the fee, because a fee is always covered first.
        $migrator->register('2.5.2', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_installments';
            $rows  = $wpdb->get_results("SELECT id, paid, penalty, installment_amount FROM {$table} WHERE penalty > 0 AND penalty_paid = 0", ARRAY_A); // phpcs:ignore

            foreach ((array) $rows as $row) {
                $each = (float) $row['installment_amount'];
                $paid = (float) $row['paid'];

                if ($each <= 0 || $paid <= 0) {
                    continue;
                }

                $leftover = $paid - (floor(($paid + 0.01) / $each) * $each);
                $covered  = min((float) $row['penalty'], max(0.0, $leftover));

                if ($covered > 0) {
                    $wpdb->update($table, ['penalty_paid' => $covered], ['id' => (int) $row['id']]); // phpcs:ignore
                }
            }
        });

        // 2.5.0: repayments and top-ups get their own order status, and
        // Analytics counts every custom status unless it is told not to.
        $migrator->register('2.5.0', static function (): void {
            if (class_exists('VKL_Wallet_Carrier_Order')) {
                VKL_Wallet_Carrier_Order::exclude_from_analytics();
            }
        });

        // 2.4.1: how much of the accrued late fee has actually been paid.
        // Without it the fee could only sit at the tail of the plan, and
        // the customer was never asked for it with the next instalment.
        $migrator->register('2.4.1', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_installments';
            $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            if (is_array($cols) && !in_array('penalty_paid', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN penalty_paid DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER penalty"); // phpcs:ignore
            }
        });

        // 2.9.10: record exactly what each offline payment did, so
        // reversing it (delete_offline_payment()) can undo exactly that —
        // not a guess based on the plan's CURRENT totals. Without this, an
        // admin voiding one payment could blank out penalty_paid built up
        // by a completely different, earlier payment (the fee was already
        // covered by that one, not by the one being deleted), making a
        // customer who was current look freshly overdue.
        $migrator->register('2.9.10', static function (): void {
            global $wpdb;

            $table = $wpdb->prefix . 'vkl_installment_payments';
            $cols  = $wpdb->get_col("SHOW COLUMNS FROM {$table}"); // phpcs:ignore

            if (!is_array($cols)) {
                return;
            }

            if (!in_array('fee_covered', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN fee_covered DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER amount"); // phpcs:ignore
            }

            if (!in_array('credit_row_id', $cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN credit_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER fee_covered"); // phpcs:ignore
            }
        });
    }

    /**
     * Create/upgrade the table.
     */
    public static function create_table(): void
    {
        (static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_installments (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    total DECIMAL(18,4) NOT NULL,
                    paid DECIMAL(18,4) NOT NULL DEFAULT 0,
                    installment_count INT UNSIGNED NOT NULL,
                    installment_amount DECIMAL(18,4) NOT NULL,
                    due_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    penalty DECIMAL(18,4) NOT NULL DEFAULT 0,
                    last_penalty VARCHAR(7) NOT NULL DEFAULT '',
                    penalty_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
                    penalty_percent DECIMAL(5,2) NOT NULL DEFAULT -1,
                    last_notice VARCHAR(20) NOT NULL DEFAULT '',
                    type VARCHAR(10) NOT NULL DEFAULT 'plan',
                    order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    status VARCHAR(12) NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY user_id (user_id),
                    KEY status (status)
                ) {$charset};"
            );
        })();
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('vkl_wallet_account_render_credit', [$this, 'render_customer'], 20);
        add_action('template_redirect', [$this, 'handle_pay']);
        add_action('template_redirect', [$this, 'handle_pay_multi']);
        add_action('init', [$this, 'maybe_schedule']);
        add_action('vkl_installment_check', [$this, 'check_overdue']);
        add_action('vkl_installment_check', [$this, 'monthly_notices']);
        add_action('vkl_installment_check', [$this, 'recover_stuck_plans']);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_price'], 21, 1);
        add_action('woocommerce_checkout_create_order', [$this, 'flag_order'], 10, 1);
        add_action('woocommerce_checkout_create_order', [$this, 'flag_order_multi'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'settle'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'settle'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'settle_multi'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'settle_multi'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'roll_back'], 20, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'roll_back'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'roll_back_multi'], 20, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'roll_back_multi'], 20, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'restore_credit'], 25, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'restore_credit'], 25, 1);
        add_action('woocommerce_order_status_processing', [$this, 'reapply_credit'], 15, 1);
        add_action('woocommerce_order_status_completed', [$this, 'reapply_credit'], 15, 1);
    }

    /**
     * Create a plan.
     */
    public function create(int $user_id, string $title, float $total, int $count, int $due_day, string $type = 'plan'): int
    {
        $count = max(1, $count);

        return $this->insert_row([
            'user_id'            => $user_id,
            'title'              => mb_substr($title, 0, 200),
            'total'              => $total,
            'paid'               => 0,
            'installment_count'  => $count,
            'installment_amount' => round($total / $count, 0),
            'due_day'            => min(28, max(1, $due_day)),
            'type'               => 'credit' === $type ? 'credit' : 'plan',
            'order_id'           => 0,
            'status'             => 'active',
            'created_at'         => $this->now(),
        ]);
    }

    /**
     * Unused installment credit of a user (sum of active credit rows).
     */
    public function available_credit(int $user_id): float
    {
        // Someone already behind on a repayment must not open a new one.
        // Lending more to a customer who has not paid last month is how a
        // small arrear turns into an unrecoverable one.
        if ($this->has_overdue($user_id)) {
            return 0.0;
        }

        return (float) $this->db->get_var(
            "SELECT COALESCE(SUM(total), 0) FROM {installments}
             WHERE user_id = %d AND type = 'credit' AND status = 'active'",
            $user_id
        );
    }

    /**
     * A plain sentence naming what is overdue and by how much.
     *
     * Telling a customer only that "you have an overdue repayment" leaves
     * them stuck: they cannot see which plan, how much, or since when.
     *
     * @return string '' when nothing is overdue.
     */
    public function overdue_summary(int $user_id): string
    {
        $plans = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE user_id = %d AND type = 'plan' AND status = 'active' AND paid < total",
            $user_id
        );

        $parts = [];
        $total = 0.0;

        foreach ($plans as $plan) {
            $due = $this->overdue_amount($plan);

            if ($due <= 0) {
                continue;
            }

            $total += $due;

            $parts[] = sprintf(
                /* translators: 1: plan title, 2: overdue amount. */
                __('%1$s: %2$s overdue', 'vereskala-login'),
                (string) $plan['title'],
                wp_strip_all_tags(wc_price($due))
            );
        }

        if ([] === $parts) {
            return '';
        }

        return sprintf(
            /* translators: 1: total overdue, 2: list of plans. */
            __('You are %1$s behind on repayments (%2$s). Pay it from the instalment plans below, and instalment purchase opens again straight away.', 'vereskala-login'),
            wp_strip_all_tags(wc_price($total)),
            implode(' · ', $parts)
        );
    }

    /**
     * Is this customer behind on any active repayment plan?
     */
    public function has_overdue(int $user_id): bool
    {
        $plans = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE user_id = %d AND type = 'plan' AND status = 'active' AND paid < total",
            $user_id
        );

        foreach ($plans as $plan) {
            // Behind from EARLIER windows only. Counting the current
            // window as arrears locks the customer's credit on the very
            // day they were asked to pay — before they had any chance to.
            // Same bug that emptied the "due today" list in 2.3.2.
            if ($this->overdue_amount($plan, false) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Consume credit for an order: the credit row becomes a repayment
     * plan for the order total; any remainder stays as a fresh credit
     * row. Installments only start now — exactly as required.
     *
     * @return bool Whether enough credit existed and was consumed.
     */
    public function consume_credit(int $user_id, float $amount, int $order_id): bool
    {
        // Already consumed for this exact order? A retry after a transient
        // failure must not eat the credit twice.
        $existing = $this->db->get_var(
            "SELECT id FROM {installments} WHERE order_id = %d AND type = 'plan' LIMIT 1",
            $order_id
        );

        if (null !== $existing && (int) $existing > 0) {
            return true;
        }

        $rows = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE user_id = %d AND type = 'credit' AND status = 'active'
             ORDER BY total DESC",
            $user_id
        );

        foreach ($rows as $row) {
            if ((float) $row['total'] < $amount) {
                continue;
            }

            $remainder = (float) $row['total'] - $amount;
            $count     = max(1, (int) $row['installment_count']);

            $this->db->update('installments', [
                'type'               => 'plan',
                'order_id'           => $order_id,
                'total'              => $amount,
                'installment_amount' => round($amount / $count, 0),
                'created_at'         => $this->now(),
                'title'              => mb_substr(
                    (string) $row['title'] . ' — ' . sprintf(
                        /* translators: %d: order id. */
                        __('order %d', 'vereskala-login'),
                        $order_id
                    ),
                    0,
                    200
                ),
            ], ['id' => (int) $row['id']]);

            // Write the plan id on the order RIGHT NOW, before anything
            // else can fail. process_payment() checks this to avoid
            // double-consuming on a retry.
            $order_obj = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

            if ($order_obj instanceof WC_Order) {
                $order_obj->update_meta_data(self::ORDER_META, (int) $row['id']);
                $order_obj->save();
            }

            if ($remainder > 0) {
                $this->create($user_id, (string) $row['title'], $remainder, $count, (int) $row['due_day'], 'credit');
            }

            // A credit purchase is a SALE, not a repayment — the plan meta
            // was already written on the order above. Both session keys
            // must go to NULL, not to the new plan: leaving SESSION_KEY set
            // is what made the *next* order flag_order() reads look like a
            // repayment of the plan that was just created here, and
            // carrier-order.php then booked an ordinary sale as a refund.
            if (function_exists('WC') && WC()->session) {
                WC()->session->set(self::SESSION_MULTI, null);
                WC()->session->set(self::SESSION_KEY, null);
            }

            return true;
        }

        return false;
    }

    /**
     * One plan row.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->get_row('SELECT * FROM {installments} WHERE id = %d', $id);
    }

    /**
     * Plans of one user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function for_user(int $user_id): array
    {
        return $this->db->get_results(
            "SELECT * FROM {installments} WHERE user_id = %d ORDER BY FIELD(status, 'active', 'settled'), id DESC",
            $user_id
        );
    }

    /**
     * Latest plans (admin).
     *
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20): array
    {
        return $this->db->get_results(
            "SELECT * FROM {installments} ORDER BY FIELD(status, 'active', 'settled'), id DESC LIMIT %d",
            $limit
        );
    }

    /**
     * Amount still owed on a plan.
     *
     * @param array<string, mixed> $plan Plan row.
     */
    public function remaining(array $plan): float
    {
        return max(0.0, (float) $plan['total'] - (float) $plan['paid']);
    }

    /**
     * Next installment amount (never more than the remainder).
     *
     * @param array<string, mixed> $plan Plan row.
     */
    public function next_amount(array $plan): float
    {
        // The late fee is not a separate bill the customer has to
        // remember — it rides on the very next instalment. Reminder SMS,
        // the customer's pay button and the admin form all quote this
        // number, so they agree by construction.
        $due = (float) $plan['installment_amount'] + $this->outstanding_penalty($plan);

        return min($due, $this->remaining($plan));
    }

    /**
     * Accrued late fee that has not been paid yet.
     */
    public function outstanding_penalty(array $plan): float
    {
        return max(0.0, (float) ($plan['penalty'] ?? 0) - (float) ($plan['penalty_paid'] ?? 0));
    }

    /**
     * Split a payment: the outstanding late fee is covered first, the
     * rest goes to the instalments.
     *
     * @return float The part that went to the fee.
     */
    private function apply_to_penalty(array $plan, float $amount): float
    {
        return min(max(0.0, $amount), $this->outstanding_penalty($plan));
    }

    /**
     * Give back, as fresh instalment credit, the principal portion of a
     * repayment made against a plan that itself came from consuming
     * credit at checkout.
     *
     * The credit line is revolving: consume_credit() converts a credit row
     * into a plan when the customer spends it, so repaying that plan must
     * free up the same room again — otherwise a customer's limit only
     * ever shrinks and never recovers.
     *
     * Guarded by `order_id`, not just `type === 'plan'`: consume_credit()
     * and reapply_credit() are the only two places that ever set it, both
     * exclusively when a credit row was converted. A plan the admin
     * creates directly (VKL_Wallet_Installments::create() from the admin
     * screen) leaves `order_id` at its default 0 — that customer never
     * had this money as credit, so repaying it must not manufacture new
     * credit out of nothing.
     *
     * @return int The new credit row's id, so a caller that might later
     *             need to undo this (a refunded/reversed payment) can find
     *             and claw back exactly this row instead of guessing. 0
     *             when nothing was granted.
     */
    private function restore_credit_after_payment(array $plan, float $principal): int
    {
        if ($principal <= 0 || (int) ($plan['order_id'] ?? 0) <= 0) {
            return 0;
        }

        return $this->create(
            (int) $plan['user_id'],
            (string) $plan['title'],
            $principal,
            max(1, (int) $plan['installment_count']),
            (int) $plan['due_day'],
            'credit'
        );
    }

    /**
     * Undo restore_credit_after_payment(): used when the payment that
     * granted it is itself reversed (an offline entry deleted, an online
     * instalment order refunded/cancelled).
     *
     * Only safe to claw back automatically while the row is exactly as
     * restore_credit_after_payment() left it — still spendable credit,
     * never touched. The moment the customer spends any of it,
     * consume_credit() converts the row to a plan (and may spin off a
     * remainder credit row of its own); at that point this specific
     * amount is tangled up with a real purchase and an admin has to sort
     * it out by hand, the same way a partially-paid plan already does in
     * restore_credit() above.
     *
     * @return bool Whether the row was clawed back.
     */
    private function reverse_credit_restore(int $credit_row_id): bool
    {
        if ($credit_row_id <= 0) {
            return true; // Nothing was ever granted for this payment.
        }

        $row = $this->find($credit_row_id);

        if (null === $row || 'credit' !== (string) ($row['type'] ?? '') || 'active' !== (string) ($row['status'] ?? '')) {
            $this->logger->info('Reversed payment had granted credit that could not be auto clawed-back (already spent or gone) — needs manual review', [
                'credit_row' => $credit_row_id,
            ]);

            return false;
        }

        $this->db->delete('installments', ['id' => $credit_row_id]);

        return true;
    }

    /**
     * Customer view inside the wallet tab.
     */
    public function render_customer(): void
    {
        $plans = $this->for_user(get_current_user_id());

        if ([] === $plans) {
            return;
        }
        ?>
        <?php $credit = $this->available_credit(get_current_user_id()); ?>
        <?php if ($credit > 0) : ?>
            <div style="background:#fff7ec;border:1px solid #f5a623;border-radius:12px;padding:14px 18px;margin-bottom:14px">
                🛍️ <strong><?php esc_html_e('Your installment credit:', 'vereskala-login'); ?></strong>
                <?php echo wp_kses_post(wc_price($credit)); ?> —
                <?php esc_html_e('choose "Installment purchase" as the payment method at checkout to use it. Repayment installments start only after you use the credit.', 'vereskala-login'); ?>
            </div>
        <?php endif; ?>
        <h3>📅 <?php esc_html_e('My installment plans', 'vereskala-login'); ?></h3>
        <?php $vkl_arrear = $this->overdue_summary(get_current_user_id()); ?>
        <?php if ('' !== $vkl_arrear) : ?>
            <div style="margin:8px 0 14px;padding:11px 14px;background:#fdf3f3;border:1px solid #f0c9c9;border-radius:10px;color:#8a2b2b;font-size:13.5px">
                ⚠️ <?php echo esc_html($vkl_arrear); ?>
            </div>
        <?php endif; ?>

        <?php
        // A flat, per-INSTALMENT schedule across every plan the customer
        // has — one row per remaining month, not one row per plan. This is
        // the same schedule() the printed loan statement builds from, so
        // the two screens can never disagree about which months are done,
        // due, overdue or still ahead (see the 2.9.10/2.9.12 fixes for what
        // happened when two separate implementations of this drifted).
        $vkl_due_rows      = []; // Must be paid now — due this window or overdue.
        $vkl_upcoming_rows = []; // Not due yet — may be paid ahead.
        $vkl_avatars       = ['#e8f1ff;color:#1f6feb', '#fdf1e3;color:#b5730a', '#e9f8ef;color:#157f3a', '#f6ecff;color:#7a3fc9', '#ffeef2;color:#c23366'];

        foreach ($plans as $vkl_p) {
            if ('credit' === (string) ($vkl_p['type'] ?? 'plan')) {
                continue;
            }

            if ('active' !== (string) $vkl_p['status'] || $this->remaining($vkl_p) <= 0) {
                continue;
            }

            $vkl_category = (int) ($vkl_p['order_id'] ?? 0) > 0 ? 'خرید اقساطی' : 'طرح بازپرداخت';
            $vkl_avatar    = $vkl_avatars[((int) $vkl_p['id']) % count($vkl_avatars)];

            foreach ($this->schedule($vkl_p) as $vkl_row) {
                if ('paid' === $vkl_row['state']) {
                    continue;
                }

                $vkl_row['plan_id']  = (int) $vkl_p['id'];
                $vkl_row['title']    = (string) $vkl_p['title'];
                $vkl_row['category'] = $vkl_category;
                $vkl_row['avatar']   = $vkl_avatar;
                $vkl_row['ordinal']  = 'قسط ' . $this->ordinal_fa((int) $vkl_row['number']);

                if (in_array($vkl_row['state'], ['due', 'overdue'], true)) {
                    $vkl_due_rows[] = $vkl_row;
                } else {
                    $vkl_upcoming_rows[] = $vkl_row;
                }
            }
        }

        // Several overdue/due months on the same plan are ONE payment
        // (through the furthest of them), not several separate ones.
        $vkl_due_through = [];

        foreach ($vkl_due_rows as $vkl_row) {
            $vkl_pid = $vkl_row['plan_id'];
            $vkl_due_through[$vkl_pid] = max($vkl_due_through[$vkl_pid] ?? 0, (int) $vkl_row['number']);
        }

        $vkl_total_due  = 0.0;
        $vkl_due_amount = []; // plan_id => what is already owed (rides along when paying ahead).

        foreach ($vkl_due_through as $vkl_pid => $vkl_through) {
            $vkl_plan_row = $this->find($vkl_pid);

            if (null !== $vkl_plan_row) {
                $vkl_plan_due               = $this->amount_through($vkl_plan_row, $vkl_through);
                // Without the late fee: the live total adds that itself.
                $vkl_due_amount[$vkl_pid]   = max(0.0, $vkl_plan_due - $this->outstanding_penalty($vkl_plan_row));
                $vkl_total_due             += $vkl_plan_due;
            }
        }

        // A single, human deadline for the "this month" tab: the closest
        // still-open window among the due (not yet overdue) rows.
        $vkl_due_deadline = '';

        foreach ($vkl_due_rows as $vkl_row) {
            if ('due' === $vkl_row['state']) {
                $vkl_due_deadline = (string) $vkl_row['label'];
                break;
            }
        }

        // Group upcoming rows by calendar month (year+month), the way a
        // real statement reads, not by plan. A plan with a due/overdue
        // amount is left out here entirely: amount_through() always sums
        // every unpaid month up to the one requested, due/overdue ones
        // included, so checking an "ahead" month on such a plan would
        // silently roll the amount already owed into the charge — a total
        // the live preview (which only ever adds up the boxes actually
        // checked) would then understate. Settle what is due first: the
        // "pay ahead" option for a plan reappears the moment it does.
        $vkl_upcoming_by_month = [];
        $vkl_upcoming_plans    = []; // plan_id => outstanding penalty, for the hidden inputs below.

        foreach ($vkl_upcoming_rows as $vkl_row) {
            // A plan that also has due months can still be paid ahead: the
            // server sums every unpaid month up to the chosen one, so the
            // amount already owed simply rides along (the live total below
            // adds it once per plan).
            $vkl_row['locked'] = false;

            $vkl_mkey = sprintf('%04d-%02d', $vkl_row['year'], $vkl_row['month']);
            $vkl_upcoming_by_month[$vkl_mkey]['label'] ??= 'اقساط ' . $this->jalali_months()[$vkl_row['month'] - 1] . ' ماه';
            $vkl_upcoming_by_month[$vkl_mkey]['rows'][] = $vkl_row;

            if ($vkl_row['locked']) {
                continue;
            }

            if (!isset($vkl_upcoming_plans[$vkl_row['plan_id']])) {
                $vkl_group_plan = $this->find($vkl_row['plan_id']);
                $vkl_upcoming_plans[$vkl_row['plan_id']] = null !== $vkl_group_plan ? $this->outstanding_penalty($vkl_group_plan) : 0.0;
            }
        }

        ksort($vkl_upcoming_by_month);
        ?>
        <style>
            .vkl-tabs{display:flex;gap:6px;background:#f1f3f2;border-radius:12px;padding:4px;margin-bottom:16px;max-width:420px}
            .vkl-tab{flex:1;border:0;background:transparent;padding:9px 6px;border-radius:9px;font-size:13.5px;font-weight:600;color:#5a6b61;cursor:pointer;font-family:inherit}
            .vkl-tab.is-active{background:#fff;color:#157f3a;box-shadow:0 1px 3px rgba(0,0,0,.12)}
            .vkl-tab .vkl-tab-count{display:inline-block;background:#e2e8e4;color:#4a5a50;border-radius:20px;padding:0 7px;font-size:11px;margin-inline-start:5px}
            .vkl-tab.is-active .vkl-tab-count{background:#dceee2;color:#157f3a}
            .vkl-panel{display:none}
            .vkl-panel.is-active{display:block}
            .vkl-deadline{background:#fff8e6;border:1px solid #f0dca8;color:#7a5b12;font-size:12.5px;border-radius:10px;padding:8px 12px;margin-bottom:12px}
            .vkl-month-block{margin-bottom:16px}
            .vkl-month-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
            .vkl-month-head span:first-child{font-size:13px;font-weight:700;color:#3c5a46}
            .vkl-month-total{background:#eef4f0;color:#3c5a46;font-size:11.5px;border-radius:20px;padding:3px 11px}
            .vkl-debt-card{display:flex;align-items:center;gap:12px;padding:11px 12px;border:1px solid #e6e9e7;border-radius:14px;margin-bottom:8px;background:#fff}
            .vkl-debt-card.is-selectable{cursor:pointer}
            .vkl-debt-card.is-selectable:hover{border-color:#c9e3d3}
            .vkl-debt-avatar{flex:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700}
            .vkl-debt-info{flex:1;min-width:0}
            .vkl-debt-amount{font-weight:700;font-size:13.5px;margin-bottom:2px}
            .vkl-debt-title{font-size:12.5px;color:#3a3a3a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px}
            .vkl-debt-tag{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;border-radius:20px;padding:2px 9px;margin-inline-end:5px}
            .vkl-debt-tag.due{color:#8a5a12;background:#fbe3bb}
            .vkl-debt-tag.overdue{color:#8a2b2b;background:#f6cccc}
            .vkl-debt-tag.upcoming{color:#3c5a46;background:#dceee2}
            .vkl-debt-cat{font-size:11.5px;color:#8a8a8a}
            .vkl-cb-locked,.vkl-cb-free{flex:none;width:20px;height:20px}
            .vkl-cb-locked{accent-color:#c7c7c7}
            .vkl-cb-free{accent-color:#157f3a;cursor:pointer}
            .vkl-sticky{position:sticky;bottom:0;background:#fff;border-top:1px solid #e6e9e7;border-radius:0 0 14px 14px;margin-top:6px;padding:14px 4px 4px}
            .vkl-sticky-row{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
            .vkl-sticky-amount{font-size:13px;color:#5a6b61;text-align:left}
            .vkl-sticky-amount strong{display:block;font-size:16px;color:#1a1a1a;margin-top:2px}
        </style>

        <?php if ([] !== $vkl_due_rows && [] !== $vkl_upcoming_by_month) : ?>
            <div class="vkl-tabs" role="tablist">
                <button type="button" class="vkl-tab is-active" data-vkl-tab="this-month">
                    این ماه<span class="vkl-tab-count"><?php echo esc_html(number_format_i18n(count($vkl_due_rows))); ?></span>
                </button>
                <button type="button" class="vkl-tab" data-vkl-tab="upcoming">
                    ماه‌های آینده<span class="vkl-tab-count"><?php echo esc_html(number_format_i18n(count($vkl_upcoming_rows))); ?></span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ([] === $vkl_due_rows && [] === $vkl_upcoming_by_month) : ?>
            <p style="color:#157f3a;font-weight:600">همه‌ی اقساط شما پرداخت شده — ممنون از پرداخت به‌موقع!</p>
        <?php endif; ?>

        <?php if ([] !== $vkl_due_rows) : ?>
            <!-- ========= این ماه: پرداخت الزامی ========= -->
            <div class="vkl-panel is-active" data-vkl-panel="this-month">
                <?php if ('' !== $vkl_due_deadline) : ?>
                    <div class="vkl-deadline">⏰ سررسید پرداخت: تا پایان <?php echo esc_html($vkl_due_deadline); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="vkl_multi_pay" value="1">
                    <?php wp_nonce_field('vkl_multi_pay', '_vkl_multi_nonce'); ?>
                    <?php foreach ($vkl_due_through as $vkl_pid => $vkl_through) : ?>
                        <input type="hidden" name="vkl_plan_ids[]" value="<?php echo (int) $vkl_pid; ?>">
                        <input type="hidden" name="vkl_through[<?php echo (int) $vkl_pid; ?>]" value="<?php echo (int) $vkl_through; ?>">
                    <?php endforeach; ?>

                    <?php foreach ($vkl_due_rows as $vkl_row) : ?>
                        <?php [$vkl_bg, $vkl_fg] = explode(';', (string) $vkl_row['avatar']); ?>
                        <div class="vkl-debt-card">
                            <input type="checkbox" class="vkl-cb-locked" checked disabled title="این قسط باید همراه بقیه‌ی اقساط این ماه پرداخت شود">
                            <div class="vkl-debt-avatar" style="background:<?php echo esc_attr($vkl_bg); ?>;<?php echo esc_attr($vkl_fg); ?>">
                                <?php echo esc_html(mb_substr((string) $vkl_row['title'], 0, 1)); ?>
                            </div>
                            <div class="vkl-debt-info">
                                <div class="vkl-debt-amount"><?php echo wp_kses_post(wc_price((float) $vkl_row['amount'])); ?></div>
                                <div class="vkl-debt-title"><?php echo esc_html((string) $vkl_row['title']); ?></div>
                                <span class="vkl-debt-tag <?php echo 'overdue' === $vkl_row['state'] ? 'overdue' : 'due'; ?>">
                                    <?php echo esc_html((string) $vkl_row['ordinal']); ?><?php echo 'overdue' === $vkl_row['state'] ? ' · معوق' : ''; ?>
                                </span>
                                <span class="vkl-debt-cat"><?php echo esc_html((string) $vkl_row['category']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="vkl-sticky">
                        <div class="vkl-sticky-row">
                            <button type="submit" class="button button-primary" style="font-size:14px;padding:11px 30px;border-radius:10px">پرداخت</button>
                            <span class="vkl-sticky-amount">مبلغ قابل پرداخت<strong><?php echo wp_kses_post(wc_price($vkl_total_due)); ?></strong></span>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ([] !== $vkl_upcoming_by_month) : ?>
            <!-- ========= ماه‌های آینده: پرداخت اختیاری/زودتر ========= -->
            <div class="vkl-panel <?php echo [] === $vkl_due_rows ? 'is-active' : ''; ?>" data-vkl-panel="upcoming">
                <form method="post" id="vkl-upcoming-form">
                    <input type="hidden" name="vkl_multi_pay" value="1">
                    <?php wp_nonce_field('vkl_multi_pay', '_vkl_multi_nonce'); ?>

                    <?php foreach ($vkl_upcoming_by_month as $vkl_month) : ?>
                        <?php $vkl_month_total = array_sum(array_column($vkl_month['rows'], 'amount')); ?>
                        <div class="vkl-month-block">
                            <div class="vkl-month-head">
                                <span>📅 <?php echo esc_html((string) $vkl_month['label']); ?></span>
                                <span class="vkl-month-total">مجموع: <?php echo wp_kses_post(wc_price($vkl_month_total)); ?></span>
                            </div>
                            <?php foreach ($vkl_month['rows'] as $vkl_row) : ?>
                                <?php [$vkl_bg, $vkl_fg] = explode(';', (string) $vkl_row['avatar']); ?>
                                <?php $vkl_locked = !empty($vkl_row['locked']); ?>
                                <label class="vkl-debt-card <?php echo $vkl_locked ? '' : 'is-selectable vkl-upcoming-row'; ?>"
                                       <?php if ($vkl_locked) : ?>style="opacity:.6" title="ابتدا اقساط سررسیدشدهٔ این طرح را بپردازید"<?php endif; ?>
                                       data-plan="<?php echo (int) $vkl_row['plan_id']; ?>"
                                       data-number="<?php echo (int) $vkl_row['number']; ?>"
                                       data-due-base="<?php echo esc_attr(number_format((float) ($vkl_due_amount[$vkl_row['plan_id']] ?? 0), 2, '.', '')); ?>"
                                       data-amount="<?php echo esc_attr(number_format((float) $vkl_row['amount'], 2, '.', '')); ?>">
                                    <?php if ($vkl_locked) : ?>
                                        <input type="checkbox" class="vkl-cb-locked" disabled>
                                    <?php else : ?>
                                        <input type="checkbox" class="vkl-cb-free vkl-upcoming-cb">
                                    <?php endif; ?>
                                    <div class="vkl-debt-avatar" style="background:<?php echo esc_attr($vkl_bg); ?>;<?php echo esc_attr($vkl_fg); ?>">
                                        <?php echo esc_html(mb_substr((string) $vkl_row['title'], 0, 1)); ?>
                                    </div>
                                    <div class="vkl-debt-info">
                                        <div class="vkl-debt-amount"><?php echo wp_kses_post(wc_price((float) $vkl_row['amount'])); ?></div>
                                        <div class="vkl-debt-title"><?php echo esc_html((string) $vkl_row['title']); ?></div>
                                        <span class="vkl-debt-tag upcoming"><?php echo esc_html((string) $vkl_row['ordinal']); ?></span>
                                        <span class="vkl-debt-cat"><?php echo esc_html((string) $vkl_row['category']); ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php foreach ($vkl_upcoming_plans as $vkl_pid => $vkl_penalty) : ?>
                        <input type="hidden" name="vkl_plan_ids[]" class="vkl-plan-id-input" data-plan="<?php echo (int) $vkl_pid; ?>" value="<?php echo (int) $vkl_pid; ?>" disabled>
                        <input type="hidden" name="vkl_through[<?php echo (int) $vkl_pid; ?>]" class="vkl-through-input" data-plan="<?php echo (int) $vkl_pid; ?>" data-penalty="<?php echo esc_attr(number_format((float) $vkl_penalty, 2, '.', '')); ?>" value="0" disabled>
                    <?php endforeach; ?>

                    <div class="vkl-sticky">
                        <div class="vkl-sticky-row">
                            <button type="submit" class="button button-primary" id="vkl-pay-upcoming" disabled style="font-size:14px;padding:11px 30px;border-radius:10px">پرداخت</button>
                            <span class="vkl-sticky-amount">مبلغ قابل پرداخت<strong id="vkl-sel-total"><?php echo wp_kses_post(wc_price(0)); ?></strong></span>
                        </div>
                    </div>
                </form>

                <script>
                (function(){
                    var btn = document.getElementById('vkl-pay-upcoming');
                    var tot = document.getElementById('vkl-sel-total');
                    var sym = <?php echo wp_json_encode(get_woocommerce_currency_symbol()); ?>;

                    function rowsForPlan(planId){
                        return Array.prototype.slice.call(document.querySelectorAll('.vkl-upcoming-row[data-plan="'+planId+'"]'));
                    }

                    function syncPlan(planId, changedRow){
                        var rows = rowsForPlan(planId).sort(function(a,b){ return +a.dataset.number - +b.dataset.number; });
                        var changedIndex = rows.indexOf(changedRow);
                        var checked = changedRow.querySelector('.vkl-upcoming-cb').checked;

                        rows.forEach(function(row, i){
                            var cb = row.querySelector('.vkl-upcoming-cb');
                            // Checking a month implies every earlier month of the same
                            // plan; unchecking a month implies every later one too —
                            // paying instalment 4 without 3 makes no sense here.
                            if (checked && i <= changedIndex) { cb.checked = true; }
                            if (!checked && i >= changedIndex) { cb.checked = false; }
                        });

                        var lastChecked = -1;
                        rows.forEach(function(row, i){ if (row.querySelector('.vkl-upcoming-cb').checked) { lastChecked = i; } });

                        var planInput = document.querySelector('.vkl-plan-id-input[data-plan="'+planId+'"]');
                        var throughInput = document.querySelector('.vkl-through-input[data-plan="'+planId+'"]');

                        if (lastChecked >= 0) {
                            planInput.disabled = false;
                            throughInput.disabled = false;
                            throughInput.value = rows[lastChecked].dataset.number;
                        } else {
                            planInput.disabled = true;
                            throughInput.disabled = true;
                            throughInput.value = 0;
                        }
                    }

                    function updateTotal(){
                        var sum = 0;
                        var seenPlans = {};
                        document.querySelectorAll('.vkl-upcoming-row').forEach(function(row){
                            if (row.querySelector('.vkl-upcoming-cb').checked) {
                                sum += parseFloat(row.dataset.amount || 0);
                                if (!seenPlans[row.dataset.plan]) {
                                    // Already-due months of this plan ride along once.
                                    sum += parseFloat(row.dataset.dueBase || 0);
                                }
                                seenPlans[row.dataset.plan] = true;
                            }
                        });
                        // Same rule as the server's amount_through(): an
                        // outstanding late fee rides on the first payment
                        // made against the plan, whichever month it is.
                        Object.keys(seenPlans).forEach(function(planId){
                            var throughInput = document.querySelector('.vkl-through-input[data-plan="'+planId+'"]');
                            if (throughInput) { sum += parseFloat(throughInput.dataset.penalty || 0); }
                        });
                        btn.disabled = sum <= 0;
                        tot.textContent = new Intl.NumberFormat('fa-IR').format(sum) + ' ' + sym;
                    }

                    document.querySelectorAll('.vkl-upcoming-cb').forEach(function(cb){
                        cb.addEventListener('change', function(){
                            var row = cb.closest('.vkl-upcoming-row');
                            syncPlan(row.dataset.plan, row);
                            updateTotal();
                        });
                    });

                    updateTotal();
                })();
                </script>
            </div>
        <?php endif; ?>

        <?php if ([] !== $vkl_due_rows && [] !== $vkl_upcoming_by_month) : ?>
            <script>
            (function(){
                document.querySelectorAll('[data-vkl-tab]').forEach(function(tabBtn){
                    tabBtn.addEventListener('click', function(){
                        document.querySelectorAll('[data-vkl-tab]').forEach(function(b){ b.classList.remove('is-active'); });
                        document.querySelectorAll('[data-vkl-panel]').forEach(function(p){ p.classList.remove('is-active'); });
                        tabBtn.classList.add('is-active');
                        var panel = document.querySelector('[data-vkl-panel="'+tabBtn.dataset.vklTab+'"]');
                        if (panel) { panel.classList.add('is-active'); }
                    });
                });
            })();
            </script>
        <?php endif; ?>

        <!-- ========= PLAN DETAILS ========= -->
        <h4 style="margin:18px 0 8px"><?php esc_html_e('Plan details', 'vereskala-login'); ?></h4>
        <?php foreach ($plans as $plan) : ?>
            <?php if ('credit' === (string) ($plan['type'] ?? 'plan')) { continue; } ?>
            <?php
            $total     = (float) $plan['total'];
            $paid      = (float) $plan['paid'];
            $remaining = $this->remaining($plan);
            $next      = $this->next_amount($plan);
            $pct       = $total > 0 ? (int) round($paid * 100 / $total) : 0;
            ?>
            <div style="border:1px solid #e2e2e2;border-radius:10px;padding:12px 16px;margin-bottom:12px">
                <strong><?php echo esc_html((string) $plan['title']); ?></strong>
                <div style="margin:6px 0">
                    <?php
                    printf(
                        /* translators: 1: paid amount, 2: total amount. */
                        esc_html__('Paid %1$s of %2$s', 'vereskala-login'),
                        wp_kses_post(wc_price($paid)),
                        wp_kses_post(wc_price($total))
                    );
                    ?>
                </div>
                <div style="background:#eee;border-radius:4px;height:8px;overflow:hidden;max-width:340px">
                    <span style="display:block;height:100%;width:<?php echo esc_attr((string) $pct); ?>%;background:#157f3a"></span>
                </div>
                <?php $vkl_offline = $this->offline_payments((int) $plan['id']); ?>
                <?php if ([] !== $vkl_offline) : ?>
                    <div style="margin:9px 0 0;font-size:12.5px;color:#3c5a46">
                        <strong><?php esc_html_e('Payments recorded by the shop', 'vereskala-login'); ?></strong>
                        <ul style="margin:4px 0 0;padding-inline-start:18px">
                            <?php foreach ($vkl_offline as $vkl_pay) : ?>
                                <li>
                                    <?php echo wp_kses_post(wc_price((float) $vkl_pay['amount'])); ?>
                                    — <?php echo esc_html($this->payment_method_label((string) $vkl_pay['method'])); ?>
                                    <?php if ('' !== (string) $vkl_pay['reference']) : ?>
                                        <span dir="ltr" style="color:#7a8a80">(<?php echo esc_html((string) $vkl_pay['reference']); ?>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php
                // Gated on the actual remaining balance, not the stored
                // 'status' string: a row whose status field is stale or
                // wrong for any reason (a hand-edit, a deleted-and-
                // recreated plan, a reclassify that skipped it) must never
                // tell the customer "fully settled — thank you!" while
                // they still owe real money. The admin's own view already
                // works this way; this one used to trust the label instead
                // of the number.
                ?>
                <?php if ($remaining > 0) : ?>
                    <p style="margin:8px 0 4px">
                        <?php
                        $vkl_due = $this->next_due($plan);

                        // The OPEN fee, not every fee ever charged: this
                        // line kept telling customers to pay a fee they
                        // had already settled months ago.
                        $vkl_penalty = $this->outstanding_penalty($plan);

                        printf(
                            /* translators: 1: amount, 2: which instalment, 3: total instalments, 4: Jalali date. */
                            esc_html__('Instalment %2$s of %3$s: %1$s — due %4$s.', 'vereskala-login'),
                            wp_kses_post(wc_price($next)),
                            esc_html(number_format_i18n($vkl_due['number'])),
                            esc_html(number_format_i18n($vkl_due['of'])),
                            esc_html($vkl_due['label'])
                        );
                        ?>
                    </p>
                    <?php if ($vkl_penalty > 0) : ?>
                        <p style="margin:0 0 8px;padding:7px 11px;background:#fff8e6;border:1px solid #f0dca8;border-radius:8px;color:#7a5b12;font-size:13px">
                            ⚠️
                            <?php
                            printf(
                                /* translators: %s: accumulated late fee. */
                                esc_html__('Late fee of %s has been added to this plan. It is collected with your next payment.', 'vereskala-login'),
                                wp_kses_post(wc_price($vkl_penalty))
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php // Payments are initiated from the panels above (due now / upcoming). ?>
                <?php else : ?>
                    <p style="color:#157f3a;font-weight:600;margin:8px 0 0"><?php esc_html_e('Fully settled — thank you!', 'vereskala-login'); ?></p>
                <?php endif; ?>
                <?php if ('plan' === (string) ($plan['type'] ?? 'plan')) : // Kreditzeilen (Sqf) haben keinen Ratenplan — kein Knopf ?>
                    <p style="margin:10px 0 0">
                        <a href="<?php echo esc_url(VKL_Wallet_Statement::url((int) $plan['id'])); ?>" target="_blank" rel="noopener"
                           style="font-size:13px;color:#157f3a;text-decoration:underline">
                            🧾 <?php esc_html_e('Loan statement', 'vereskala-login'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php
    }

    /**
     * Start an installment payment: put the carrier product in the cart
     * and send the customer to checkout.
     */
    public function handle_pay(): void
    {
        if (!is_user_logged_in()
            || !isset($_POST['vkl_installment_pay'], $_POST['_vkl_inst_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_inst_nonce'])), self::NONCE)
        ) {
            return;
        }

        $plan = $this->find(absint($_POST['vkl_installment_pay']));

        if (null === $plan
            || (int) $plan['user_id'] !== get_current_user_id()
            || 'active' !== (string) $plan['status']
            || $this->remaining($plan) <= 0
        ) {
            return;
        }

        $topup      = vkl('wallet.topup');
        $product_id = $topup instanceof VKL_Wallet_Topup ? $topup->ensure_product() : 0;

        if ($product_id <= 0 || null === WC()->cart) {
            wc_add_notice(__('Installment payment is temporarily unavailable.', 'vereskala-login'), 'error');

            return;
        }

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($product_id, 1);
        WC()->session->set(self::SESSION_KEY, [
            'plan'   => (int) $plan['id'],
            'amount' => $this->next_amount($plan),
        ]);

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    public function handle_pay_multi(): void
    {
        if (!is_user_logged_in()
            || !isset($_POST['vkl_multi_pay'], $_POST['_vkl_multi_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_multi_nonce'])), 'vkl_multi_pay')
        ) {
            return;
        }

        $raw_ids = isset($_POST['vkl_plan_ids']) && is_array($_POST['vkl_plan_ids'])
            ? array_map('absint', (array) $_POST['vkl_plan_ids'])
            : [];

        // Optional: pay through a further instalment than just the next
        // one — the wallet tab's "Upcoming instalments" panel submits this
        // when the customer selects a later month, which implies every
        // unpaid month up to it. Absent (or 0), falls back to the single
        // next payment, same as before this option existed.
        $raw_through = isset($_POST['vkl_through']) && is_array($_POST['vkl_through'])
            ? array_map('absint', (array) $_POST['vkl_through'])
            : [];

        if ([] === $raw_ids) {
            wc_add_notice(__('Please select at least one instalment.', 'vereskala-login'), 'error');
            wp_safe_redirect(wc_get_account_url('wallet'));
            exit;
        }

        $topup      = vkl('wallet.topup');
        $product_id = $topup instanceof VKL_Wallet_Topup ? $topup->ensure_product() : 0;

        if ($product_id <= 0 || null === WC()->cart) {
            wc_add_notice(__('Instalment payment is temporarily unavailable.', 'vereskala-login'), 'error');
            wp_safe_redirect(wc_get_account_url('wallet'));
            exit;
        }

        WC()->cart->empty_cart();

        $multi = [];

        foreach ($raw_ids as $plan_id) {
            $plan = $this->find($plan_id);

            if (null === $plan
                || (int) $plan['user_id'] !== get_current_user_id()
                || 'active' !== (string) $plan['status']
                || $this->remaining($plan) <= 0
            ) {
                continue;
            }

            $through = $raw_through[$plan_id] ?? 0;
            $amount  = $through > 0 ? $this->amount_through($plan, $through) : $this->next_amount($plan);

            if ($amount <= 0) {
                continue;
            }

            WC()->cart->add_to_cart($product_id, 1, 0, [], ['_vkl_plan' => $plan_id]);
            $multi[] = ['plan' => $plan_id, 'amount' => $amount];
        }

        if ([] === $multi) {
            wc_add_notice(__('None of the selected instalments could be processed.', 'vereskala-login'), 'error');
            wp_safe_redirect(wc_get_account_url('wallet'));
            exit;
        }

        WC()->session->set(self::SESSION_KEY, null);
        WC()->session->set(self::SESSION_MULTI, $multi);

        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    public function flag_order_multi(WC_Order $order): void
    {
        $multi = WC()->session ? WC()->session->get(self::SESSION_MULTI) : null;

        if (!is_array($multi) || [] === $multi) {
            return;
        }

        // Only stamp multi-plan meta when the cart is actually carrying the
        // repayment carrier product right now. The session alone survives a
        // previous multi-payment and would otherwise flag a later, unrelated
        // order as a repayment of stale plan-ids. cart_is_installment() now
        // recognises the multi-plan session too (it used to only look at
        // SESSION_KEY, the single-plan one), so it is the single source of
        // truth for both flows.
        if (!self::cart_is_installment()) {
            if (WC()->session) {
                WC()->session->set(self::SESSION_MULTI, null);
            }

            return;
        }

        $ids = array_column($multi, 'plan');
        $order->update_meta_data('_vkl_installment_plans', wp_json_encode($ids));
    }

    public function settle_multi(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order || !$order->is_paid()) {
            return;
        }

        $raw = (string) $order->get_meta('_vkl_installment_plans');

        if ('' === $raw || '1' === (string) $order->get_meta('_vkl_multi_settled')) {
            return;
        }

        $ids = json_decode($raw, true);

        if (!is_array($ids) || [] === $ids) {
            return;
        }

        if (WC()->session) {
            WC()->session->set(self::SESSION_MULTI, null);
        }

        $order->update_meta_data('_vkl_multi_settled', '1');
        $order->save();

        $totals = [];
        $sum    = 0.0;

        foreach ($ids as $pid) {
            $p = $this->find((int) $pid);

            if (null === $p) {
                continue;
            }

            $totals[(int) $pid] = $this->next_amount($p);
            $sum               += $totals[(int) $pid];
        }

        if ($sum <= 0) {
            return;
        }

        $order_total = (float) $order->get_total();
        $ledger      = [];
        $paid_total  = 0.0;
        $all_settled = true;
        $first_plan  = null;
        $paid_user   = 0;

        foreach ($totals as $pid => $share) {
            $plan = $this->find($pid);

            if (null === $plan) {
                continue;
            }

            $amount      = round($order_total * ($share / $sum), 4);
            $fee_covered = $this->apply_to_penalty($plan, $amount);
            $paid        = (float) $plan['paid'] + $amount;
            $status      = $paid >= (float) $plan['total'] ? 'settled' : 'active';

            $this->db->update('installments', [
                'paid'         => $paid,
                'penalty_paid' => (float) ($plan['penalty_paid'] ?? 0) + $fee_covered,
                'status'       => $status,
            ], ['id' => $pid]);

            $credit_row_id = $this->restore_credit_after_payment($plan, $amount - $fee_covered);

            // roll_back_multi() needs these to undo exactly this split if
            // the order is later cancelled/refunded — same reasoning as
            // the single-plan meta in settle().
            $ledger[$pid] = ['amount' => $amount, 'fee_covered' => $fee_covered, 'credit_row_id' => $credit_row_id];

            $paid_total += $amount;
            $all_settled = $all_settled && 'settled' === $status;
            $first_plan  = $first_plan ?? $pid;
            $paid_user   = (int) $plan['user_id'];
        }

        // One announcement for the whole payment: paying three instalments
        // in a single checkout used to text the customer and the shop three
        // times. The listeners get the combined amount instead.
        if ($paid_user > 0) {
            vkl_dispatch('installment.paid', [
                'plan_id'  => (int) $first_plan,
                'plan_ids' => array_keys($ledger),
                'user_id'  => $paid_user,
                'amount'   => $paid_total,
                'order_id' => (int) $order_id,
                'settled'  => $all_settled,
                'multi'    => true,
            ]);
        }

        $order->update_meta_data('_vkl_multi_ledger', wp_json_encode($ledger));
        $order->save();
    }

    /**
     * Is the cart currently an instalment payment — either a single plan
     * (SESSION_KEY, from the Wallet tab's "Pay" button) or several at
     * once (SESSION_MULTI, from "Pay selected/Pay all")?
     *
     * Paying a repayment must be settled with real money: covering it with
     * wallet credit, festival credit or a fresh instalment purchase would
     * just move the debt around instead of clearing it. Missing the
     * multi-plan case here used to leave exactly that door open — the
     * wallet checkbox, festival credit, and the instalment-credit gateway
     * all relied on this one method, so a multi-plan repayment could be
     * "paid" with more credit while looking, on the surface, like a real
     * repayment (settle_multi() marked the old plans paid off the same
     * order that consume_credit() had just turned into a brand new debt).
     */
    public static function cart_is_installment(?WC_Cart $cart = null): bool
    {
        if (!function_exists('WC') || null === WC()->session) {
            return false;
        }

        $single = WC()->session->get(self::SESSION_KEY);
        $multi  = WC()->session->get(self::SESSION_MULTI);

        $has_single = is_array($single) && (int) ($single['plan'] ?? 0) > 0;
        $has_multi  = is_array($multi) && [] !== $multi;

        if (!$has_single && !$has_multi) {
            return false;
        }

        // The session alone is not proof — it survives a previous payment
        // and would suppress the wallet and credit panel on every ordinary
        // cart the customer opens next. The carrier product (topup/topup
        // acting as instalment carrier) must actually be in the cart right
        // now, otherwise this is just a normal purchase.
        $carrier_id = (int) get_option(VKL_Wallet_Topup::PRODUCT_OPTION, 0);
        $cart       = $cart instanceof WC_Cart ? $cart : WC()->cart;

        if (!$cart instanceof WC_Cart || $cart->is_empty() || $carrier_id <= 0) {
            return false;
        }

        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] === $carrier_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Price the carrier product with the installment amount.
     */
    public function apply_price(WC_Cart $cart): void
    {
        if (!WC()->session) {
            return;
        }

        $product_id = (int) get_option(VKL_Wallet_Topup::PRODUCT_OPTION, 0);

        if ($product_id <= 0) {
            return;
        }

        $payment = WC()->session->get(self::SESSION_KEY);

        if (is_array($payment) && (float) ($payment['amount'] ?? 0) > 0) {
            foreach ($cart->get_cart() as $item) {
                if ((int) $item['product_id'] === $product_id && isset($item['data']) && $item['data'] instanceof WC_Product) {
                    $item['data']->set_price((float) $payment['amount']);
                    $item['data']->set_name(__('Installment payment', 'vereskala-login'));
                }
            }

            return;
        }

        // Multi-pay: handle_pay_multi() put one cart line PER selected
        // plan, each tagged with its own '_vkl_plan' cart-item data, and
        // recorded each plan's amount in SESSION_MULTI at the moment the
        // customer chose it. Without pricing each line individually here,
        // every line silently kept the carrier product's default price —
        // the checkout total never matched what "Pay all / Pay selected"
        // promised, and settle_multi() then divided the wrong total across
        // the plans.
        $multi = WC()->session->get(self::SESSION_MULTI);

        if (!is_array($multi) || [] === $multi) {
            return;
        }

        $amounts = [];

        foreach ($multi as $row) {
            if (is_array($row) && (int) ($row['plan'] ?? 0) > 0) {
                $amounts[(int) $row['plan']] = (float) ($row['amount'] ?? 0);
            }
        }

        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] !== $product_id || !isset($item['data']) || !($item['data'] instanceof WC_Product)) {
                continue;
            }

            $plan_id = (int) ($item['_vkl_plan'] ?? 0);

            if ($plan_id <= 0 || !isset($amounts[$plan_id]) || $amounts[$plan_id] <= 0) {
                continue;
            }

            $item['data']->set_price($amounts[$plan_id]);
            $item['data']->set_name(__('Installment payment', 'vereskala-login'));
        }
    }

    /**
     * Record the plan id on the order.
     */
    public function flag_order(WC_Order $order): void
    {
        $payment = WC()->session ? WC()->session->get(self::SESSION_KEY) : null;

        if (!is_array($payment) || (int) ($payment['plan'] ?? 0) <= 0) {
            return;
        }

        // The session is not proof by itself — it can survive a previous
        // payment (or, before this fix, a credit purchase). Only the
        // carrier product actually sitting in THIS cart makes it a
        // repayment; anything else — including an ordinary purchase paid
        // for with instalment credit — must not get the plan meta, or
        // carrier-order.php::move() books a normal sale as a refund.
        if (!self::cart_is_installment()) {
            if (WC()->session) {
                WC()->session->set(self::SESSION_KEY, null);
            }

            return;
        }

        $plan_id = (int) $payment['plan'];
        $plan    = $this->find($plan_id);

        // Guard: the session may still point at a plan from a previous
        // purchase that has since been settled and removed. In that case
        // do nothing — this is an ordinary order, not a repayment. The
        // session will be cleared by settle() on the first successful
        // payment, or it will expire on its own.
        if (null === $plan
            || 'plan' !== (string) ($plan['type'] ?? 'plan')
            || 'settled' === (string) ($plan['status'] ?? '')
        ) {
            // Session points at a closed or missing plan — this is an
            // ordinary purchase, not a repayment. Clear the stale
            // session so the next checkout is not affected.
            if (WC()->session) {
                WC()->session->set(self::SESSION_KEY, null);
            }

            return;
        }

        $order->update_meta_data(self::ORDER_META, $plan_id);

        // Clear any leftover SESSION_MULTI so flag_order_multi() cannot
        // stamp this ordinary repayment order with old plan-ids.
        if (WC()->session) {
            WC()->session->set(self::SESSION_MULTI, null);
        }

        // The session is deliberately NOT cleared here. This runs when
        // the order is created — before the customer reaches the bank.
        // Clearing it now meant that a failed or abandoned payment left
        // them unable to retry: the order carried no plan any more.
        // It is cleared once the payment actually settles instead.
    }

    /**
     * Settle a paid installment (idempotent per order).
     *
     * @param mixed $order_id Order id.
     */
    public function settle(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        // Payment went through — clear the session only when this order
        // was actually a repayment. For a normal purchase the session is
        // either empty or already pointing at a different plan; touching it
        // here would wipe the customer's next repayment intent.
        $order_plan_id = (int) $order->get_meta(self::ORDER_META);

        if ($order_plan_id > 0 && WC()->session) {
            $session = WC()->session->get(self::SESSION_KEY);

            // Only wipe the session when it still points at this exact plan.
            // The customer may have opened a second instalment purchase while
            // this payment was in flight; clearing unconditionally would
            // destroy that intent.
            if (is_array($session) && (int) ($session['plan'] ?? 0) === $order_plan_id) {
                WC()->session->set(self::SESSION_KEY, null);
            }
        }

        // Only count money that actually arrived. Reaching a paid status
        // is not proof on its own: a gateway that fails mid-flow, or an
        // admin moving the status by hand, would otherwise mark a whole
        // instalment as received without a single rial being paid.
        if (!$order->is_paid() && '' === (string) $order->get_date_paid()) {
            $order->add_order_note(
                __('Instalment not credited: the order has no payment date yet.', 'vereskala-login')
            );

            return;
        }

        $plan_id = (int) $order->get_meta(self::ORDER_META);

        if ($plan_id <= 0 || '' !== (string) $order->get_meta(self::META_SETTLED)) {
            return;
        }

        $plan = $this->find($plan_id);

        if (null === $plan) {
            return;
        }

        // This order is the plan's ORIGIN — consume_credit() wrote this
        // very meta on it the moment the credit was converted. No real
        // money was collected here (the "payment" was the credit itself),
        // so the order total is not an instalment payment. Without this
        // check the freshly created plan was marked 'paid' == 'total' and
        // 'settled' the instant the purchase completed, before the
        // customer had repaid a single instalment.
        if ((int) $plan['order_id'] === (int) $order_id) {
            $order->update_meta_data(self::META_SETTLED, gmdate('Y-m-d H:i:s'));
            $order->save();

            return;
        }

        $order->update_meta_data(self::META_SETTLED, gmdate('Y-m-d H:i:s'));
        $order->save();

        $amount      = (float) $order->get_total();
        $fee_covered = $this->apply_to_penalty($plan, $amount);
        $paid        = (float) $plan['paid'] + $amount;
        $status      = $paid >= (float) $plan['total'] ? 'settled' : 'active';

        $this->db->update('installments', [
            'paid'         => $paid,
            'penalty_paid' => (float) ($plan['penalty_paid'] ?? 0) + $fee_covered,
            'status'       => $status,
        ], ['id' => $plan_id]);

        $credit_row_id = $this->restore_credit_after_payment($plan, $amount - $fee_covered);

        // roll_back() needs these to undo EXACTLY this payment if the order
        // is later cancelled/refunded — not a guess off the plan's current
        // totals. See the same reasoning on delete_offline_payment().
        $order->update_meta_data('_vkl_fee_covered', $fee_covered);
        $order->update_meta_data('_vkl_credit_row_id', $credit_row_id);
        $order->save();

        vkl_dispatch('installment.paid', [
            'plan_id'  => $plan_id,
            'user_id'  => (int) $plan['user_id'],
            'amount'   => (float) $order->get_total(),
            'order_id' => (int) $order_id,
            'settled'  => 'settled' === $status,
        ]);
    }

    /**
     * When an instalment-funded purchase is cancelled or refunded, return
     * the credit to the customer so they can spend it again.
     *
     * consume_credit() converted a credit row into a plan. A cancellation
     * means the purchase never happened, so the plan goes back to credit.
     * Idempotent: the meta flag prevents a double reversal.
     *
     * @param mixed $order_id Order id.
     */
    public function restore_credit(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $plan_id = (int) $order->get_meta(self::ORDER_META);

        if ($plan_id <= 0 || '1' === (string) $order->get_meta('_vkl_credit_restored')) {
            return;
        }

        $plan = $this->find($plan_id);

        // Only restore if it was an installment-funded plan with no payments
        // yet. A partially-paid plan needs manual handling.
        if (null === $plan
            || 'plan' !== (string) ($plan['type'] ?? 'plan')
            || (float) $plan['paid'] > 0
        ) {
            return;
        }

        // Restore as credit — using the original credit ceiling which is
        // the plan's total (nothing was paid, so total = original amount).
        // Remember everything needed to put the plan back if the order is
        // completed again later. Without this the row stays half-converted:
        // credit type but with a plan title, counted as available credit
        // while the customer already has the goods.
        $order->update_meta_data('_vkl_credit_restored', '1');
        $order->update_meta_data('_vkl_restored_plan', (int) $plan_id);
        $order->update_meta_data('_vkl_restored_total', (float) $plan['total']);
        $order->update_meta_data('_vkl_restored_title', (string) $plan['title']);
        $order->update_meta_data('_vkl_restored_created', (string) $plan['created_at']);
        $order->save();

        $this->db->update('installments', [
            'type'      => 'credit',
            'order_id'  => 0,
            'status'    => 'active',
            'created_at' => $this->now(),
        ], ['id' => $plan_id]);

        $this->logger->info('Credit restored after order cancellation', [
            'plan'  => $plan_id,
            'order' => (int) $order_id,
        ]);
    }

    /**
     * Restore credit that consume_credit() converted into a plan for an
     * order that then never actually completed — a crash between
     * consume_credit() and the order reaching a paid status (see
     * VKL_Installment_Gateway::process_payment()), left with no
     * woocommerce_order_status_cancelled/refunded transition to trigger
     * the normal restore_credit() path, because the order is stuck in
     * whatever pre-payment status it was created in (pending, failed, a
     * Store API "checkout-draft") rather than actually being cancelled.
     *
     * Deliberately conservative: only orders older than the grace window
     * are touched, so an order genuinely mid-payment (bank redirect,
     * awaiting confirmation) is never disturbed.
     *
     * @return int Number of plans restored.
     */
    public function recover_stuck_plans(int $older_than_minutes = 60): int
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $orders = wc_get_orders([
            'limit'        => 200,
            'meta_key'     => self::ORDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_compare' => 'EXISTS',
            'return'       => 'objects',
            'date_created' => '<' . (time() - max(1, $older_than_minutes) * 60),
        ]);

        $recovered = 0;

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order || $order->is_paid()) {
                continue;
            }

            // Already booked as a repayment/top-up, or already restored —
            // both mean this order does not need (or does not qualify
            // for) recovery.
            if (class_exists('VKL_Wallet_Carrier_Order') && VKL_Wallet_Carrier_Order::STATUS === $order->get_status()) {
                continue;
            }

            if ('1' === (string) $order->get_meta('_vkl_credit_restored')) {
                continue;
            }

            $before = (int) $order->get_meta(self::ORDER_META);

            $this->restore_credit($order->get_id());

            // restore_credit() itself checks paid == 0 and stamps
            // _vkl_credit_restored — re-read it to know whether this
            // particular order actually qualified (it silently no-ops on
            // a partially-paid plan, which needs a human).
            if ($before > 0 && '1' === (string) $order->get_meta('_vkl_credit_restored')) {
                $order->add_order_note(__('Vereskonto: instalment credit automatically restored — this order never completed payment within the recovery window.', 'vereskala-login'));
                ++$recovered;
            }
        }

        return $recovered;
    }

    /**
     * An order that was cancelled and is now paid again must get its plan
     * back. restore_credit() turned the plan into credit; this turns it
     * back, otherwise the row keeps a plan title while counting as free
     * credit and the customer can spend the same money twice.
     *
     * @param mixed $order_id Order id.
     */
    public function reapply_credit(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order || '1' !== (string) $order->get_meta('_vkl_credit_restored')) {
            return;
        }

        $plan_id = (int) $order->get_meta('_vkl_restored_plan');
        $total   = (float) $order->get_meta('_vkl_restored_total');
        $plan    = $plan_id > 0 ? $this->find($plan_id) : null;

        if (null === $plan || $total <= 0) {
            return;
        }

        $count = max(1, (int) $plan['installment_count']);

        $this->db->update('installments', [
            'type'               => 'plan',
            'order_id'           => (int) $order_id,
            'total'              => $total,
            'installment_amount' => round($total / $count, 0),
            'title'              => (string) $order->get_meta('_vkl_restored_title'),
            'created_at'         => (string) $order->get_meta('_vkl_restored_created'),
            'status'             => 'active',
        ], ['id' => $plan_id]);

        $order->delete_meta_data('_vkl_credit_restored');
        $order->delete_meta_data('_vkl_restored_plan');
        $order->delete_meta_data('_vkl_restored_total');
        $order->delete_meta_data('_vkl_restored_title');
        $order->delete_meta_data('_vkl_restored_created');
        $order->save();

        $this->logger->info('Credit re-applied after order was paid again', [
            'plan'  => $plan_id,
            'order' => (int) $order_id,
        ]);
    }

    /**
     * Roll a refunded/cancelled installment order back (once).
     *
     * @param mixed $order_id Order id.
     */
    public function roll_back(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $plan_id = (int) $order->get_meta(self::ORDER_META);

        if ($plan_id <= 0 || '' === (string) $order->get_meta(self::META_SETTLED) || '1' === (string) $order->get_meta('_vkl_installment_reversed')) {
            return;
        }

        $plan = $this->find($plan_id);

        if (null === $plan) {
            return;
        }

        $order->update_meta_data('_vkl_installment_reversed', '1');
        $order->save();

        $paid = max(0.0, (float) $plan['paid'] - (float) $order->get_total());

        // Uncover exactly the fee THIS payment covered (stamped by settle()
        // when it was received) — not penalty_paid wholesale and not left
        // untouched. Before this, a refunded/cancelled instalment payment
        // never gave back any fee coverage it had bought, permanently
        // overstating how much fee the customer still owed.
        $fee_back = (float) $order->get_meta('_vkl_fee_covered');

        $this->db->update('installments', [
            'paid'         => $paid,
            'penalty_paid' => max(0.0, (float) ($plan['penalty_paid'] ?? 0) - $fee_back),
            'status'       => 'active',
        ], ['id' => $plan_id]);

        $this->reverse_credit_restore((int) $order->get_meta('_vkl_credit_row_id'));

        $this->logger->info('Installment payment rolled back', ['plan' => $plan_id, 'order' => (int) $order_id]);
    }

    /**
     * Roll a refunded/cancelled MULTI-instalment repayment order back
     * (once) — the counterpart of roll_back() for orders that paid several
     * plans in one go (settle_multi()).
     *
     * @param mixed $order_id Order id.
     */
    public function roll_back_multi(mixed $order_id): void
    {
        $order = wc_get_order((int) $order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $raw = (string) $order->get_meta('_vkl_multi_ledger');

        if ('' === $raw || '1' === (string) $order->get_meta('_vkl_multi_reversed')) {
            return;
        }

        $ledger = json_decode($raw, true);

        if (!is_array($ledger) || [] === $ledger) {
            return;
        }

        $order->update_meta_data('_vkl_multi_reversed', '1');
        $order->save();

        foreach ($ledger as $pid => $entry) {
            $plan = $this->find((int) $pid);

            if (null === $plan || !is_array($entry)) {
                continue;
            }

            $amount   = (float) ($entry['amount'] ?? 0);
            $fee_back = (float) ($entry['fee_covered'] ?? 0);
            $paid     = max(0.0, (float) $plan['paid'] - $amount);

            $this->db->update('installments', [
                'paid'         => $paid,
                'penalty_paid' => max(0.0, (float) ($plan['penalty_paid'] ?? 0) - $fee_back),
                'status'       => 'active',
            ], ['id' => (int) $pid]);

            $this->reverse_credit_restore((int) ($entry['credit_row_id'] ?? 0));
        }

        $this->logger->info('Multi-instalment payment rolled back', ['order' => (int) $order_id, 'plans' => array_keys($ledger)]);
    }

    /**
     * Book a repayment that arrived outside the shop — card-to-card,
     * cash at the counter, a bank transfer.
     *
     * The money moved in the real world, so the plan must move with it.
     * Every such booking is written to its own ledger row with who
     * entered it and which reference it carries, because an amount that
     * changes a customer's debt without a trace is impossible to defend
     * later. Deleting the row reverses the booking exactly.
     *
     * @return string 'ok' | 'noplan' | 'noamount' | 'settled'
     */
    public function record_offline_payment(
        int $plan_id,
        float $amount,
        string $method = 'card',
        string $reference = '',
        string $note = '',
        int $admin_id = 0
    ): string {
        $plan = $this->find($plan_id);

        if (null === $plan) {
            return 'noplan';
        }

        // Purchase credit is a ceiling, not a debt — there is nothing to
        // repay until the customer has actually spent it at checkout.
        if ('credit' === (string) ($plan['type'] ?? 'plan')) {
            return 'credit';
        }

        if ($amount <= 0) {
            return 'noamount';
        }

        $remaining = $this->remaining($plan);

        if ($remaining <= 0) {
            return 'settled';
        }

        // Never book more than is owed: an overpayment would turn into a
        // negative debt that no screen in the plugin can explain.
        $amount = min($amount, $remaining);

        $fee_covered = $this->apply_to_penalty($plan, $amount);
        $paid        = (float) $plan['paid'] + $amount;
        $status      = $paid >= (float) $plan['total'] ? 'settled' : 'active';

        $this->db->update('installments', [
            'paid'         => $paid,
            'penalty_paid' => (float) ($plan['penalty_paid'] ?? 0) + $fee_covered,
            'status'       => $status,
        ], ['id' => $plan_id]);

        $credit_row_id = $this->restore_credit_after_payment($plan, $amount - $fee_covered);

        $this->db->insert('installment_payments', [
            'plan_id'       => $plan_id,
            'user_id'       => (int) $plan['user_id'],
            'amount'        => $amount,
            'fee_covered'   => $fee_covered,
            'credit_row_id' => $credit_row_id,
            'method'        => in_array($method, ['card', 'cash', 'bank', 'other'], true) ? $method : 'other',
            'reference'     => mb_substr($reference, 0, 120),
            'note'          => mb_substr($note, 0, 255),
            'admin_id'      => $admin_id,
            'created_at'    => $this->now(),
        ]);

        $this->logger->info('Offline installment payment recorded', [
            'plan'   => $plan_id,
            'amount' => $amount,
            'method' => $method,
            'by'     => $admin_id,
        ]);

        // Same event as a gateway payment, so the customer and the shop
        // get the very same confirmation SMS — no second code path.
        vkl_dispatch('installment.paid', [
            'plan_id'  => $plan_id,
            'user_id'  => (int) $plan['user_id'],
            'amount'   => $amount,
            'order_id' => 0,
            'settled'  => 'settled' === $status,
            'offline'  => true,
        ]);

        return 'ok';
    }

    /**
     * Offline payments booked against a plan, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function offline_payments(int $plan_id): array
    {
        return $this->db->get_results(
            "SELECT * FROM {installment_payments} WHERE plan_id = %d ORDER BY id DESC",
            $plan_id
        );
    }

    /**
     * Offline payments for several plans at once, keyed by plan id.
     *
     * The loans table renders every plan of every customer; asking per
     * plan would be one query per row on a host that already sits at its
     * IO ceiling.
     *
     * @param array<int, int> $plan_ids Plan ids.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function offline_payments_map(array $plan_ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $plan_ids)));

        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->get_results(
            "SELECT * FROM {installment_payments} WHERE plan_id IN (" . implode(',', $ids) . ") ORDER BY id DESC"
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['plan_id']][] = $row;
        }

        return $map;
    }

    /**
     * Reverse one offline payment: the amount leaves the plan again and
     * the row disappears. Used when an admin mistypes an amount.
     */
    public function delete_offline_payment(int $id): bool
    {
        $row = $this->db->get_row("SELECT * FROM {installment_payments} WHERE id = %d", $id);

        if (null === $row) {
            return false;
        }

        $plan = $this->find((int) $row['plan_id']);

        if (null !== $plan) {
            $amount = (float) $row['amount'];
            $paid   = max(0.0, (float) $plan['paid'] - $amount);

            // Uncover EXACTLY the fee this specific payment covered when it
            // was made (stored on the row itself since 2.9.10) — never a
            // guess off the plan's current penalty_paid. penalty_paid is a
            // running total that later, unrelated payments also add to; a
            // guess based on "up to this amount, off whatever the balance
            // is now" could unwind a fee that a DIFFERENT payment paid off,
            // making a customer who was current look freshly overdue.
            // Rows recorded before 2.9.10 have no stored figure (defaults
            // to 0) — left untouched rather than guessed, since leaving
            // penalty_paid too high is far less harmful than the false
            // arrears a wrong guess produces.
            $fee_back = (float) ($row['fee_covered'] ?? 0);

            $this->db->update('installments', [
                'paid'         => $paid,
                'penalty_paid' => max(0.0, (float) ($plan['penalty_paid'] ?? 0) - $fee_back),
                'status'       => $paid >= (float) $plan['total'] ? 'settled' : 'active',
            ], ['id' => (int) $plan['id']]);

            $this->reverse_credit_restore((int) ($row['credit_row_id'] ?? 0));
        }

        $this->db->delete('installment_payments', ['id' => $id]);

        $this->logger->info('Offline installment payment reversed', [
            'payment' => $id,
            'plan'    => (int) $row['plan_id'],
            'amount'  => (float) $row['amount'],
        ]);

        return true;
    }

    /**
     * Every plan of a customer, settled ones included.
     *
     * open_plans() deliberately hides finished plans from the reminder
     * lists; the admin editor needs the opposite, otherwise a plan
     * created by mistake can never be corrected once it is marked paid.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all_plans(int $user_id): array
    {
        return $this->db->get_results(
            "SELECT * FROM {installments} WHERE user_id = %d ORDER BY (status = 'settled'), id DESC",
            $user_id
        );
    }

    /**
     * Human label for a payment method key.
     */
    public function payment_method_label(string $method): string
    {
        $labels = [
            'card' => __('Card to card', 'vereskala-login'),
            'cash' => __('Cash', 'vereskala-login'),
            'bank' => __('Bank transfer', 'vereskala-login'),
        ];

        return $labels[$method] ?? __('Other', 'vereskala-login');
    }

    /**
     * Update an editable plan (title, total, count, due day, late-fee
     * rate and the accrued fee). The paid amount is never edited here —
     * money movements happen through orders or through the offline
     * ledger above, so the paid column always has a receipt behind it.
     *
     * @param float|null $penalty_percent Per-plan rate, -1 to inherit the shop rate, null to leave as is.
     * @param float|null $penalty         New accrued late fee, null to leave as is.
     *
     * @return bool Whether anything was updated.
     */
    public function update_plan(
        int $id,
        string $title,
        float $total,
        int $count,
        int $due_day,
        ?float $penalty_percent = null,
        ?float $penalty = null
    ): bool {
        $plan = $this->find($id);

        if (null === $plan) {
            return false;
        }

        $count = max(1, $count);

        // Forgiving part of the late fee has to leave the total as well:
        // check_overdue() adds every fee to both columns, so changing one
        // alone would leave the customer owing a fee that is no longer
        // recorded anywhere.
        if (null !== $penalty) {
            $penalty = max(0.0, $penalty);
            $delta   = $penalty - (float) ($plan['penalty'] ?? 0);

            if (abs($delta) > 0.001) {
                $total = max(0.0, $total + $delta);
            }
        }

        $total = max((float) $plan['paid'], $total); // Never below what is already paid.

        $data = [
            'title'              => mb_substr($title, 0, 200),
            'total'              => $total,
            'installment_count'  => $count,
            'installment_amount' => round($total / $count, 0),
            'due_day'            => min(28, max(1, $due_day)),
            // Always recomputed from the numbers being saved right now —
            // never carried over from before. The old "else keep whatever
            // status already was" let a plan get stuck marked 'settled'
            // forever: one edit that happened to bring the total down to
            // (or below, then clamped up to) paid flips it to settled;
            // any LATER edit raising the total back to a real remaining
            // balance recomputed a correct new total but kept the stale
            // 'settled' flag, since paid < total took the else branch and
            // that branch was never touching status at all. The customer
            // and admin screens both trust this column directly.
            'status'             => (float) $plan['paid'] >= $total ? 'settled' : 'active',
        ];

        if (null !== $penalty) {
            $data['penalty'] = $penalty;
        }

        if (null !== $penalty_percent) {
            $data['penalty_percent'] = $penalty_percent < 0 ? -1.0 : min(100.0, $penalty_percent);
        }

        return 0 < $this->db->update('installments', $data, ['id' => $id]);
    }

    /**
     * Delete a plan. Also clears the plan's own origin order's meta if it
     * still points here — left in place, a later repayment attempt on
     * that order would silently find nothing (find() returns null) and
     * do nothing instead of failing loudly, and the order would keep
     * showing a "linked plan" that no longer exists.
     *
     * This only clears the ORIGIN order (the one consume_credit() wrote
     * the meta on). A plan that was also referenced by one or more
     * REPAYMENT orders (single via the same meta key, or several at once
     * via '_vkl_installment_plans') keeps those references — WordPress
     * has no efficient way to search order meta by value, so finding
     * every such order is not something this method can safely do. Those
     * old orders' notes and ledgers stay as history; they do not drive
     * any further behaviour once the plan itself is gone.
     */
    public function delete_plan(int $id): void
    {
        $plan = $this->find($id);

        if (null === $plan) {
            return;
        }

        $order_id = (int) ($plan['order_id'] ?? 0);

        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);

            if ($order instanceof WC_Order && (int) $order->get_meta(self::ORDER_META) === $id) {
                $order->delete_meta_data(self::ORDER_META);
                $order->add_order_note(sprintf('ورسکونتو: ردیف قسط/اعتبار مرتبط #%d توسط ادمین حذف شد.', $id));
                $order->save();
            }
        }

        $this->db->delete('installments', ['id' => $id]);
    }

    /**
     * Send a manual reminder SMS for a plan's next installment.
     *
     * @return bool Whether a message was attempted.
     */
    public function remind(int $id): bool
    {
        $plan = $this->find($id);

        if (null === $plan || $this->remaining($plan) <= 0) {
            return false;
        }

        $pattern = $this->config->get_string('installment_notice_pattern');

        if ('' === $pattern) {
            return false;
        }

        $mobile = vkl('validator')->normalize_mobile((string) get_user_meta((int) $plan['user_id'], 'billing_phone', true));

        if (!vkl('validator')->is_mobile($mobile)) {
            return false;
        }

        // A manual reminder is an explicit admin choice for THIS plan, so
        // it may quote the next instalment even when it is not due yet;
        // when months are due already, quote what is owed instead.
        $through = 0;

        foreach ($this->schedule($plan) as $row) {
            if (in_array($row['state'], ['due', 'overdue'], true)) {
                $through = max($through, (int) $row['number']);
            }
        }

        $amount = $through > 0 ? $this->amount_through($plan, $through) : $this->next_amount($plan);

        vkl('sms.sender')->send($mobile, 'installment.reminder', $pattern, [
            'name'   => vkl('sms.sender')->resolve_name((int) $plan['user_id']),
            'title'  => (string) $plan['title'],
            'amount' => (string) (int) $amount,
            'due'    => $this->due_label((int) $plan['due_day']),
        ]);

        return true;
    }

    /**
     * Schedule the daily overdue check.
     */
    public function maybe_schedule(): void
    {
        if (!wp_next_scheduled('vkl_installment_check')) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'vkl_installment_check');
        }
    }

    /**
     * Unschedule (deactivation).
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook('vkl_installment_check');
    }

    /**
     * Daily overdue pass: for every repayment plan whose expected
     * cumulative payment is behind schedule and whose monthly due day has
     * passed, apply the configured late fee ONCE per calendar month
     * (idempotent via last_penalty = 'Y-m') and text a reminder.
     *
     * Expected-by-now = months elapsed since the plan started ×
     * installment amount, capped at the total. Percent 0 = no fee, but
     * the reminder SMS still goes out when its pattern is set.
     */
    /**
     * Monthly SMS run, driven by the JALALI calendar so it lands on the
     * dates customers actually recognise:
     *   • 1st of the month — every plan with an instalment due this month
     *     gets the notice.
     *   • 4th of the month — whoever still has not paid gets a follow-up.
     * Each pass is stamped per plan (Y-m + stage), so a cron that fires
     * twice in a day, or a missed day caught up later, never doubles a
     * message.
     */
    public function monthly_notices(): void
    {
        $now = current_datetime();

        if (!class_exists('VKL_Wc_Account')) {
            return;
        }

        [$jy, $jm, $jd] = VKL_Wc_Account::g2j((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));

        $notice_day = max(1, min(28, $this->config->get_int('installment_notice_day', 3)));
        $chase_day  = max(1, min(28, $this->config->get_int('installment_chase_day', 5)));

        if (!in_array($jd, [$notice_day, $chase_day], true)) {
            return;
        }

        $stage = $jd === $notice_day ? 'due' : 'chase';
        $stamp = sprintf('%04d-%02d-%s', $jy, $jm, $stage);

        $pattern = $this->config->get_string(
            'chase' === $stage ? 'installment_due_pattern' : 'installment_notice_pattern'
        );

        if ('' === $pattern) {
            return;
        }

        // One message per CUSTOMER, worded from the same schedule the
        // customer sees on the repayment page: only months that are really
        // due/overdue count, summed across every plan they hold. (It used
        // to text once per plan with that plan's next instalment — whether
        // or not it was due, or already paid.)
        foreach ($this->owed_by_user('chase' === $stage) as $user_id => $owed) {
            $fresh = array_filter($owed['plans'], static fn(array $p): bool => $stamp !== (string) ($p['last_notice'] ?? ''));

            if ([] === $fresh) {
                continue;
            }

            // Claim first: a failure below must not re-send next run.
            foreach ($owed['plans'] as $p) {
                $this->db->update('installments', ['last_notice' => $stamp], ['id' => (int) $p['id']]);
            }

            $this->send_owed($user_id, $owed, 'chase' === $stage ? 'installment.chase' : 'installment.due', $pattern);
        }
    }

    /**
     * What each customer owes RIGHT NOW, grouped per user.
     *
     * Built from schedule() and amount_through() — the very functions the
     * repayment page uses — so an SMS can never quote a different figure
     * than the page: a month that is paid or not yet due contributes
     * nothing, and several plans add up to one amount.
     *
     * @param bool $overdue_only Count only overdue months (arrears / chase).
     *
     * @return array<int, array{amount: float, penalty: float, plans: array<int, array<string, mixed>>, due_day: int, titles: string[]}>
     */
    public function owed_by_user(bool $overdue_only = false): array
    {
        $plans = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE status = 'active' AND type = 'plan' AND paid < total
             ORDER BY user_id ASC LIMIT 500"
        );

        $out = [];

        foreach ($plans as $plan) {
            $through = 0;

            foreach ($this->schedule($plan) as $row) {
                if ('overdue' === $row['state'] || (!$overdue_only && 'due' === $row['state'])) {
                    $through = max($through, (int) $row['number']);
                }
            }

            if ($through <= 0) {
                continue;
            }

            $amount = $this->amount_through($plan, $through);

            if ($amount <= 0) {
                continue;
            }

            $uid = (int) $plan['user_id'];

            $out[$uid] ??= ['amount' => 0.0, 'penalty' => 0.0, 'plans' => [], 'due_day' => (int) $plan['due_day'], 'titles' => []];
            $out[$uid]['amount']   += $amount;
            $out[$uid]['penalty']  += $this->outstanding_penalty($plan);
            $out[$uid]['plans'][]   = $plan;
            $out[$uid]['titles'][]  = (string) $plan['title'];
        }

        return $out;
    }

    /**
     * Send one combined reminder to a customer from owed_by_user() data.
     *
     * @param array<string, mixed> $owed One owed_by_user() entry.
     */
    private function send_owed(int $user_id, array $owed, string $type, string $pattern, int $throttle = 0): bool
    {
        $mobile = vkl('validator')->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

        if ('' === $pattern || !vkl('validator')->is_mobile($mobile)) {
            return false;
        }

        return vkl('sms.sender')->send($mobile, $type, $pattern, [
            'name'    => vkl('sms.sender')->resolve_name($user_id),
            'title'   => count($owed['titles']) > 1 ? 'اقساط شما' : (string) ($owed['titles'][0] ?? ''),
            'amount'  => (string) (int) $owed['amount'],
            'due'     => $this->due_label((int) $owed['due_day']),
            'penalty' => (string) (int) $owed['penalty'],
        ], $throttle);
    }

    public function check_overdue(): void
    {
        $percent = (float) $this->config->get_int('installment_penalty_percent', 0);
        // Both must be Jalali: due_day is a Jalali day, so a Gregorian day
        // comparison skipped the fee for most of the month.
        $month   = $this->today_jalali_month();
        $day     = $this->today_jalali_day();

        $plans = $this->db->get_results(
            "SELECT * FROM {installments} WHERE status = 'active' AND type = 'plan' AND paid < total LIMIT 200"
        );

        $late = [];

        foreach ($plans as $plan) {
            if ($day <= (int) $plan['due_day'] || $month === (string) ($plan['last_penalty'] ?? '')) {
                continue;
            }

            // Same Jalali-based count as everywhere else: mixing calendars
            // here charged a late fee an instalment too early.
            // Fee base without the older unpaid fees: a late fee that is
            // itself charged a late fee compounds a debt the customer can
            // never catch up with.
            $overdue = $this->overdue_amount($plan, true, false);

            if ($overdue <= 0) {
                continue;
            }

            // A plan may carry its own rate. -1 (the default) means the
            // shop-wide rate above; 0 genuinely means "no fee on this one".
            $rate = isset($plan['penalty_percent']) && (float) $plan['penalty_percent'] >= 0
                ? (float) $plan['penalty_percent']
                : $percent;

            $fee = $rate > 0 ? round($overdue * $rate / 100, 0) : 0.0;

            // Mark the month first — a failure below must not double-charge.
            $this->db->update('installments', [
                'last_penalty' => $month,
                'penalty'      => (float) ($plan['penalty'] ?? 0) + $fee,
                'total'        => (float) $plan['total'] + $fee,
            ], ['id' => (int) $plan['id']]);

            $this->logger->info('Installment overdue processed', [
                'plan'    => (int) $plan['id'],
                'overdue' => $overdue,
                'fee'     => $fee,
            ]);

            // Collected, then texted once per customer after the loop.
            $uid = (int) $plan['user_id'];

            $late[$uid] ??= ['amount' => 0.0, 'penalty' => 0.0, 'plans' => [], 'due_day' => (int) $plan['due_day'], 'titles' => []];
            $late[$uid]['amount']  += $overdue + $fee;
            $late[$uid]['penalty'] += $fee;
            $late[$uid]['titles'][] = (string) $plan['title'];
        }

        $pattern = $this->config->get_string('installment_due_pattern');

        foreach ($late as $uid => $owed) {
            $this->send_owed((int) $uid, $owed, 'installment.overdue', $pattern, 20);
        }
    }

    /**
     * Credit customers overview: one row per customer who has any plan —
     * available credit, open debt, and how many installments remain.
     *
     * @return array<int, array<string, mixed>>
     */
    public function credit_customers(int $limit = 50, array $only_users = []): array
    {
        // Filtering in SQL rather than after the fact: the query is
        // capped at $limit, so post-filtering would silently drop a
        // searched-for customer who sits outside the first fifty rows.
        $ids   = array_values(array_filter(array_map('intval', $only_users), static fn(int $id): bool => $id > 0));
        $where = [] === $ids ? '' : ' WHERE user_id IN (' . implode(',', $ids) . ')';

        return $this->db->get_results(
            "SELECT user_id,
                    SUM(CASE WHEN type = 'credit' AND status = 'active' THEN total ELSE 0 END) AS credit_available,
                    SUM(CASE WHEN type = 'plan' AND status = 'active' THEN (total - paid) ELSE 0 END) AS debt,
                    SUM(CASE WHEN type = 'plan' AND status = 'active' AND installment_amount > 0
                             THEN CEIL((total - paid) / installment_amount) ELSE 0 END) AS installments_left,
                    SUM(CASE WHEN type = 'plan' AND status = 'active' THEN penalty ELSE 0 END) AS penalty,
                    SUM(CASE WHEN type = 'plan' THEN penalty ELSE 0 END) AS penalty_ever,
                    SUM(CASE WHEN type = 'plan' THEN GREATEST(penalty - penalty_paid, 0) ELSE 0 END) AS penalty_open,
                    SUM(CASE WHEN type = 'plan' AND status = 'settled' THEN 1 ELSE 0 END) AS settled_plans,
                    SUM(CASE WHEN type = 'plan' THEN total ELSE 0 END) AS borrowed_ever,
                    SUM(CASE WHEN type = 'plan' THEN paid ELSE 0 END) AS repaid_ever,
                    MIN(CASE WHEN type = 'plan' THEN created_at END) AS first_plan_at,
                    MAX(CASE WHEN type = 'plan' THEN created_at END) AS last_plan_at,
                    COUNT(*) AS plans
             FROM {installments}" . $where . "
             GROUP BY user_id
             ORDER BY debt DESC, credit_available DESC
             LIMIT %d",
            $limit
        );
    }

    /**
     * Score a credit customer's behaviour out of five.
     *
     * Built from what the ledger already knows: how much of what they
     * borrowed they have repaid, whether they ever collected a late fee,
     * how many plans they finished, and whether they are behind right now.
     *
     * @param array<string, mixed> $row One row from credit_customers().
     *
     * @return array{stars: int, label: string, note: string}
     */
    public function credit_rating(array $row): array
    {
        $borrowed = (float) ($row['borrowed_ever'] ?? 0);
        $repaid   = (float) ($row['repaid_ever'] ?? 0);
        $penalty  = (float) ($row['penalty_ever'] ?? 0);
        $open_fee = (float) ($row['penalty_open'] ?? 0);
        $settled  = (int) ($row['settled_plans'] ?? 0);
        $debt     = (float) ($row['debt'] ?? 0);

        // Nothing borrowed yet: no track record to judge.
        if ($borrowed <= 0) {
            return [
                'stars' => 0,
                'label' => __('No history yet', 'vereskala-login'),
                'note'  => __('This customer has not used credit yet.', 'vereskala-login'),
            ];
        }

        $ratio = min(1.0, $repaid / $borrowed);
        $score = 1 + ($ratio * 3);            // 1..4 from the repayment ratio

        if ($settled > 0) {
            $score += min(1.0, $settled * 0.5); // finishing plans is the strongest signal
        }

        // An unpaid fee is a live problem; one that was settled is only
        // history, and history should not brand a customer forever.
        if ($open_fee > 0) {
            $score -= 1.5;
        } elseif ($penalty > 0) {
            $score -= 0.75;
        }

        $stars = (int) max(1, min(5, round($score)));

        if ($open_fee > 0) {
            $label = __('Needs watching', 'vereskala-login');
            $note  = __('Carries a late fee that has not been paid yet.', 'vereskala-login');
        } elseif ($penalty > 0) {
            $label = __('Was late before', 'vereskala-login');
            $note  = __('Collected a late fee along the way, but has paid it.', 'vereskala-login');
        } elseif ($settled > 0 && $debt <= 0) {
            $label = __('Excellent', 'vereskala-login');
            $note  = __('Finished every plan without a single late fee.', 'vereskala-login');
        } elseif ($ratio >= 0.5) {
            $label = __('On track', 'vereskala-login');
            $note  = __('Repaying steadily and never late.', 'vereskala-login');
        } else {
            $label = __('Early days', 'vereskala-login');
            $note  = __('Recently started repaying; no problems so far.', 'vereskala-login');
        }

        return ['stars' => $stars, 'label' => $label, 'note' => $note];
    }

    /**
     * Active repayment plans of one user (for per-plan reminders).
     *
     * @return array<int, array<string, mixed>>
     */
    public function open_plans(int $user_id): array
    {
        return $this->db->get_results(
            "SELECT * FROM {installments} WHERE user_id = %d AND type = 'plan' AND status = 'active' AND paid < total ORDER BY id DESC",
            $user_id
        );
    }

    /**
     * Jalali month names, one place so they cannot drift apart.
     *
     * @return array<int, string>
     */
    private function jalali_months(): array
    {
        return ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'امرداد', 'شهریور',
                'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    }

    /**
     * "قسط چهارم" needs a word, not a digit — this covers every count the
     * admin form allows (up to 36); beyond that, a numbered fallback.
     */
    private function ordinal_fa(int $n): string
    {
        $words = [
            1 => 'اول', 2 => 'دوم', 3 => 'سوم', 4 => 'چهارم', 5 => 'پنجم',
            6 => 'ششم', 7 => 'هفتم', 8 => 'هشتم', 9 => 'نهم', 10 => 'دهم',
            11 => 'یازدهم', 12 => 'دوازدهم', 13 => 'سیزدهم', 14 => 'چهاردهم', 15 => 'پانزدهم',
            16 => 'شانزدهم', 17 => 'هفدهم', 18 => 'هجدهم', 19 => 'نوزدهم', 20 => 'بیستم',
        ];

        return $words[$n] ?? sprintf('شماره %s', number_format_i18n($n));
    }

    /**
     * When the NEXT payment actually falls due, and which one it is.
     *
     * A bare "day 5 of each month" tells the customer almost nothing: they
     * cannot see which instalment is next, nor whether it is this month or
     * the next. This returns both, on the Jalali calendar.
     *
     * @param array<string, mixed> $plan Plan row.
     *
     * @return array{number: int, of: int, label: string}
     */
    public function next_due(array $plan): array
    {
        $count   = max(1, (int) $plan['installment_count']);
        $each    = (float) $plan['installment_amount'];
        $paid    = (float) $plan['paid'];
        $due_day = max(1, (int) $plan['due_day']);

        // Count against the RAW total paid, not paid-minus-penalty-paid —
        // same fix, and same reason, as schedule() in statement.php. A fee
        // already settled is done; outstanding_penalty() is 0 for it, and
        // next_amount() only ever weighs the fee while it is still
        // outstanding. Subtracting penalty_paid here regardless
        // double-charged the customer for it: once a fee got paid off,
        // this line kept discounting that same money from their
        // instalment progress forever, so a customer who had genuinely
        // paid enough still saw their next instalment named as one they
        // had already settled — the printed statement's own schedule
        // table (using raw paid) disagreed with this "next payment" line
        // on the very same page.
        $done = $each > 0 ? (int) floor(($paid + 0.01) / $each) : 0;
        $number = min($count, $done + 1);

        $months = $this->jalali_months();
        $now    = current_datetime();

        if (!class_exists('VKL_Wc_Account')) {
            return ['number' => $number, 'of' => $count, 'label' => (string) $due_day];
        }

        [$jy, $jm, $jd] = VKL_Wc_Account::g2j(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j')
        );

        // This month's window has closed → the calendar's next one is
        // next month.
        if ((int) $jd > $due_day) {
            ++$jm;

            if ($jm > 12) {
                $jm = 1;
                ++$jy;
            }
        }

        // The schedule, not the calendar, decides which window is next:
        // the plan's first window plus the instalments already paid. A
        // customer who has settled two of four is due in the third
        // window, whatever month it happens to be today — reading the
        // date off the calendar told someone who had just paid ahead
        // that they owed again this very month.
        [$fy, $fm] = $this->first_due($plan);

        if (0 !== $fy) {
            $months_on = $fm - 1 + $done;
            $sy        = $fy + intdiv($months_on, 12);
            $sm        = ($months_on % 12) + 1;

            // Behind schedule: the missed window is in the past, so the
            // payment is owed now rather than then.
            if ($sy > $jy || ($sy === $jy && $sm > $jm)) {
                $jy = $sy;
                $jm = $sm;
            }
        }

        return [
            'number' => $number,
            'of'     => $count,
            'label'  => $this->window_label($plan, $jm),
        ];
    }

    /**
     * Human due label for SMS: the plan's due day in the CURRENT Jalali
     * month, e.g. «۵ امرداد» instead of a bare «5».
     */
    public function due_label(int $due_day): string
    {
        $now = current_datetime();

        if (class_exists('VKL_Wc_Account')) {
            [, $jm] = VKL_Wc_Account::g2j((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
        } else {
            $jm = 0;
        }

        // Through window_label(), so an SMS never names a single day while
        // the customer's own screen shows a range.
        return $this->window_label(['due_day' => max(1, $due_day)], (int) $jm);
    }

    /**
     * Plans whose payment has FALLEN DUE this month: the due day has
     * arrived and the current month's instalment is still outstanding —
     * but the customer is otherwise on schedule. Anyone already behind
     * from earlier months belongs to arrears(), not here, so the two
     * lists never show the same person twice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function due_now(int $limit = 200): array
    {
        $day  = $this->today_jalali_day();
        $rows = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE status = 'active' AND type = 'plan' AND paid < total
             ORDER BY user_id ASC LIMIT %d",
            $limit
        );

        $out = [];

        $now = current_datetime();

        [$jy, $jm] = class_exists('VKL_Wc_Account')
            ? VKL_Wc_Account::g2j((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'))
            : [0, 0];

        foreach ($rows as $plan) {
            // Anywhere inside the payment window — from the day it opens
            // up to and including the due day. From the day AFTER, the
            // payment is late and the customer belongs in arrears, so the
            // two lists never show the same person twice.
            [$window_start, $window_end] = $this->due_window($plan);

            if ($day < $window_start || $day > $window_end) {
                continue;
            }

            // A brand-new plan whose first window has not arrived yet must
            // stay out: otherwise a customer who bought two days ago is
            // asked for money in the very same month.
            [$fy, $fm] = $this->first_due($plan);

            if (0 !== $fy && ((int) $jy < $fy || ((int) $jy === $fy && (int) $jm < $fm))) {
                continue;
            }

            // Behind from EARLIER months only: today's instalment must not
            // count here, or every customer would be filtered out on their
            // own due day — which is exactly what happened, leaving this
            // list permanently empty.
            if ($this->overdue_amount($plan, false) > 0) {
                continue;                       // already in arrears
            }

            // Is this month's instalment itself still unpaid? Count the
            // due dates already passed and look one ahead — on the Jalali
            // calendar, which is the one the due day belongs to.
            $due_so_far = $this->due_installments($plan, false);

            $expected_with_this_month = min(
                (float) $plan['total'],
                ($due_so_far + 1) * (float) $plan['installment_amount']
            );
            $outstanding              = $expected_with_this_month - (float) $plan['paid'];

            if ($outstanding <= 0) {
                continue;                       // already paid for this month
            }

            $plan['due_amount'] = min($outstanding, $this->next_amount($plan));
            $out[]              = $plan;
        }

        return $out;
    }

    /**
     * Group reminder to everyone whose payment has just fallen due.
     */
    public function remind_due_now(string $pattern = ''): int
    {
        $pattern = '' !== $pattern ? $pattern : $this->config->get_string('installment_notice_pattern');

        if ('' === $pattern) {
            return 0;
        }

        $sent    = 0;
        $arrears = $this->owed_by_user(true);

        // Customers who are only in their window (nothing overdue); one
        // combined message each.
        foreach ($this->owed_by_user(false) as $user_id => $owed) {
            if (isset($arrears[$user_id])) {
                continue; // Already late — belongs to the arrears reminder.
            }

            if ($this->send_owed((int) $user_id, $owed, 'installment.due_now', $pattern)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Plans that are behind schedule right now: the expected cumulative
     * payment (months elapsed × instalment) exceeds what has been paid,
     * and this month's due day has passed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function arrears(int $limit = 200): array
    {
        $rows = $this->db->get_results(
            "SELECT * FROM {installments}
             WHERE status = 'active' AND type = 'plan' AND paid < total
             ORDER BY user_id ASC LIMIT %d",
            $limit
        );

        $out = [];

        foreach ($rows as $plan) {
            // Behind from earlier due dates — today's own instalment does
            // not count yet. The old test only looked at the day of the
            // month (day > due_day), so a customer with a due day of 25
            // was invisible here for the first 25 days of every month, no
            // matter how far behind they were.
            $overdue = $this->overdue_amount($plan, false);

            if ($overdue <= 0) {
                continue;
            }

            $plan['overdue'] = $overdue;
            $out[]           = $plan;
        }

        return $out;
    }

    /**
     * Current Jalali year-month, e.g. "1405-05".
     *
     * The penalty must land once per JALALI month because the due day is a
     * Jalali day; stamping it with the Gregorian month let a single Jalali
     * month straddle two stamps.
     */
    private function today_jalali_month(): string
    {
        $now = current_datetime();

        if (!class_exists('VKL_Wc_Account')) {
            return $now->format('Y-m');
        }

        [$jy, $jm] = VKL_Wc_Account::g2j(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j')
        );

        return sprintf('%04d-%02d', (int) $jy, (int) $jm);
    }

    /**
     * Today's day-of-month on the JALALI calendar.
     *
     * due_day is a Jalali day (the customer sees "۵ امرداد"), so every
     * comparison against it must use the Jalali day too. Comparing it with
     * the Gregorian day was wrong for most of the month.
     */
    private function today_jalali_day(): int
    {
        $now = current_datetime();

        if (!class_exists('VKL_Wc_Account')) {
            return (int) $now->format('j');
        }

        [, , $jd] = VKL_Wc_Account::g2j(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j')
        );

        return (int) $jd;
    }

    /**
     * How much of a plan is past due right now (0 when on schedule).
     *
     * @param array<string, mixed> $plan Plan row.
     */
    public function overdue_amount(array $plan, bool $include_due_day = true, bool $include_penalty = true): float
    {
        $expected = $this->due_installments($plan, $include_due_day) * (float) $plan['installment_amount'];

        // An unpaid fee is owed immediately, so it belongs to the arrears
        // — except when this very number is the base for calculating the
        // next fee, which would charge a fee on a fee.
        if ($include_penalty) {
            $expected += $this->outstanding_penalty($plan);
        }

        $expected = min((float) $plan['total'], $expected);

        return max(0.0, $expected - (float) $plan['paid']);
    }

    /**
     * How many due dates have actually gone by since the plan started.
     *
     * Counted on the JALALI calendar, because the due day is a Jalali day.
     * The old version mixed calendars — it took the difference in
     * GREGORIAN months and then added one more when the Jalali due day had
     * passed, which double-counted and put customers into arrears a whole
     * instalment early.
     *
     * @param array<string, mixed> $plan Plan row.
     */
    public function due_window(array $plan): array
    {
        $end    = max(1, (int) $plan['due_day']);
        $length = max(1, min(28, $this->config->get_int('installment_due_window', 5)));
        $start  = max(1, $end - $length + 1);

        return [$start, $end];
    }

    /**
     * The window as the customer reads it: "1 to 5 Mehr".
     */
    public function window_label(array $plan, int $month = 0): string
    {
        [$start, $end] = $this->due_window($plan);

        $months = $this->jalali_months();
        $name   = ($month >= 1 && $month <= 12) ? $months[$month - 1] : '';

        $days = $start === $end
            ? (string) $end
            : sprintf(
                /* translators: 1: first day of the payment window, 2: last day. */
                __('%1$d to %2$d', 'vereskala-login'),
                $start,
                $end
            );

        return '' !== $name ? $days . ' ' . $name : $days;
    }

    /**
     * Every instalment of the plan, one row each, with the month it falls
     * in and where it stands today — paid / due / overdue / upcoming.
     *
     * The single source of truth for "which instalments are done and
     * which are left": the printed loan statement and the customer's own
     * Wallet tab both call this, so they can never show two different
     * answers again (see the 2.9.10 fix, where next_due() quietly
     * disagreed with this very logic for years).
     *
     * @param array<string, mixed> $plan Plan row.
     *
     * @return array<int, array{number: int, year: int, month: int, label: string, amount: float, state: string}>
     */
    public function schedule(array $plan): array
    {
        $count = max(1, (int) $plan['installment_count']);
        $each  = (float) $plan['installment_amount'];

        // Count against the RAW total paid, not paid-minus-penalty-paid. A
        // fee already settled is done — outstanding_penalty() is 0 for it —
        // and overdue_amount()/next_amount() everywhere else in the plugin
        // only ever weigh the fee while it is still outstanding.
        // Subtracting penalty_paid here regardless double-charged the
        // customer for it: once a fee got paid off, it kept discounting
        // that same money from their instalment progress forever, so a
        // customer who had genuinely paid enough still saw their next
        // instalment marked overdue.
        $done = $each > 0 ? (int) floor(((float) $plan['paid'] + 0.01) / $each) : 0;

        [$fy, $fm] = $this->first_due($plan);
        [$window_start, $window_end] = $this->due_window($plan);

        $now = current_datetime();

        [$jy, $jm, $jd] = class_exists('VKL_Wc_Account')
            ? VKL_Wc_Account::g2j((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'))
            : [0, 0, 0];

        $rows = [];

        for ($i = 1; $i <= $count; ++$i) {
            $months_on = $fm - 1 + ($i - 1);
            $year      = $fy + intdiv($months_on, 12);
            $month     = ($months_on % 12) + 1;

            // The final instalment carries whatever rounding is left.
            $amount = $i === $count ? max(0.0, (float) $plan['total'] - (float) ($plan['penalty'] ?? 0) - ($each * ($count - 1))) : $each;

            if ($i <= $done) {
                $state = 'paid';
            } elseif (0 === $fy || 0 === $jy) {
                $state = 'upcoming';
            } elseif ($year < $jy || ($year === $jy && $month < $jm)) {
                $state = 'overdue';
            } elseif ($year === $jy && $month === $jm && $jd >= $window_start && $jd <= $window_end) {
                $state = 'due';
            } elseif ($year === $jy && $month === $jm && $jd > $window_end) {
                $state = 'overdue';
            } else {
                $state = 'upcoming';
            }

            $rows[] = [
                'number' => $i,
                'year'   => $year,
                'month'  => $month,
                'label'  => $this->window_label($plan, $month) . ' ' . $year,
                'amount' => $amount,
                'state'  => $state,
            ];
        }

        return $rows;
    }

    /**
     * How much is needed to pay everything through a given instalment
     * number — every not-yet-paid row up to it, plus the late fee (which,
     * like next_amount(), always rides on the very first unpaid payment).
     * Lets a customer pay several instalments ahead in one go instead of
     * one submission per month.
     *
     * @param array<string, mixed> $plan            Plan row.
     * @param int                  $through_number  Highest instalment
     *                                               number to include.
     */
    public function amount_through(array $plan, int $through_number): float
    {
        $target = 0.0;

        foreach ($this->schedule($plan) as $row) {
            if ((int) $row['number'] <= $through_number && 'paid' !== $row['state']) {
                $target += (float) $row['amount'];
            }
        }

        if ($target > 0) {
            $target += $this->outstanding_penalty($plan);
        }

        return min($target, $this->remaining($plan));
    }

    /**
     * The Jalali year/month of a plan's FIRST payment window.
     */
    public function first_due(array $plan): array
    {
        // Everything here is measured against the day the window OPENS —
        // that is the day the customer is first asked for money.
        [$window_start] = $this->due_window($plan);

        $start = strtotime((string) $plan['created_at']);

        if (false === $start || !class_exists('VKL_Wc_Account')) {
            return [0, 0];
        }

        [$sy, $sm, $sd] = VKL_Wc_Account::g2j(
            (int) gmdate('Y', $start),
            (int) gmdate('n', $start),
            (int) gmdate('j', $start)
        );

        $y = (int) $sy;
        $m = (int) $sm;

        // This month's window has already opened, so the first payment
        // belongs to the next month at the earliest.
        if ((int) $sd >= $window_start) {
            [$y, $m] = $this->next_month($y, $m);
        }

        // And it has to leave a real gap between the purchase and the day
        // the window opens. Buying on the 29th and being asked to pay on
        // the 1st is three days of credit, not a month; below the
        // configured minimum the first payment moves one month further
        // out. 0 turns the rule off.
        $gap = max(0, $this->config->get_int('installment_first_due_gap', 7));

        if ($gap > 0) {
            [$gy, $gm, $gd] = VKL_Wc_Account::j2g($y, $m, $window_start);

            $due_ts   = gmmktime(0, 0, 0, (int) $gm, (int) $gd, (int) $gy);
            $start_ts = gmmktime(0, 0, 0, (int) gmdate('n', $start), (int) gmdate('j', $start), (int) gmdate('Y', $start));

            if (false !== $due_ts && false !== $start_ts
                && (int) floor(($due_ts - $start_ts) / DAY_IN_SECONDS) < $gap
            ) {
                [$y, $m] = $this->next_month($y, $m);
            }
        }

        return [$y, $m];
    }

    /**
     * The Jalali month after the given one.
     *
     * @return array{0: int, 1: int}
     */
    private function next_month(int $y, int $m): array
    {
        ++$m;

        if ($m > 12) {
            $m = 1;
            ++$y;
        }

        return [$y, $m];
    }

    public function due_installments(array $plan, bool $include_due_day = true): int
    {
        $due_day = max(1, (int) $plan['due_day']);
        $count   = max(1, (int) $plan['installment_count']);
        $start   = strtotime((string) $plan['created_at']);

        if (false === $start || !class_exists('VKL_Wc_Account')) {
            return 0;
        }

        [$sy, $sm, $sd] = VKL_Wc_Account::g2j(
            (int) gmdate('Y', $start),
            (int) gmdate('n', $start),
            (int) gmdate('j', $start)
        );

        $now = current_datetime();

        [$ny, $nm, $nd] = VKL_Wc_Account::g2j(
            (int) $now->format('Y'),
            (int) $now->format('n'),
            (int) $now->format('j')
        );

        // First due date: the shared rule, so the customer's own screen,
        // the arrears list and the late fee cannot disagree about when the
        // first instalment was owed.
        [$first_y, $first_m] = $this->first_due($plan);

        if (0 === $first_y) {
            return 0;
        }

        // Whole Jalali months from that first due date to today.
        $elapsed = (((int) $ny - $first_y) * 12) + ((int) $nm - $first_m);

        if ($elapsed < 0) {
            return 0; // the first due date has not arrived yet
        }

        // On the due day itself the instalment IS due — but it is not yet
        // late. Callers asking "who is behind?" pass false, otherwise the
        // same customer is counted as in arrears on the very day they were
        // asked to pay, and then shows up in neither list.
        $today_counts = $include_due_day ? (int) $nd >= $due_day : (int) $nd > $due_day;

        $passed = $elapsed + ($today_counts ? 1 : 0);

        return max(0, min($count, $passed));
    }

    /**
     * Text every customer currently in arrears. Returns how many messages
     * were queued.
     */
    public function remind_arrears(string $pattern = ''): int
    {
        $pattern = '' !== $pattern ? $pattern : $this->config->get_string('installment_due_pattern');

        if ('' === $pattern) {
            return 0;
        }

        $sent = 0;

        foreach ($this->owed_by_user(true) as $user_id => $owed) {
            if ($this->send_owed((int) $user_id, $owed, 'installment.arrears', $pattern)) {
                $sent++;
            }
        }

        return $sent;
    }
}
