<?php
/**
 * Loan statement (صورت‌حساب وام) — one printable page that answers the
 * only three questions anybody ever asks about an instalment plan: what
 * was owed, what has been paid, and what is left.
 *
 * The same document serves the customer and the shop. A separate admin
 * version would drift from the customer's within two releases, and then
 * the two of them would be arguing over different numbers.
 *
 * @package Vereskonto\Modules\Wallet
 * @since   2.6.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wallet_Statement
 */
final class VKL_Wallet_Statement
{
    private VKL_Wallet_Installments $installments;

    public function __construct(VKL_Wallet_Installments $installments)
    {
        $this->installments = $installments;
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_render']);
    }

    /**
     * Address of a plan's statement.
     */
    public static function url(int $plan_id): string
    {
        return add_query_arg(['vkl_loan_statement' => $plan_id], home_url('/'));
    }

    /**
     * Render when asked for, and only for people entitled to see it.
     */
    public function maybe_render(): void
    {
        if (!isset($_GET['vkl_loan_statement'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $plan = $this->installments->find(absint($_GET['vkl_loan_statement'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (null === $plan) {
            return;
        }

        $is_owner = is_user_logged_in() && (int) $plan['user_id'] === get_current_user_id();

        if (!$is_owner && !current_user_can(VKL_Security::ADMIN_CAP)) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        if ('credit' === (string) ($plan['type'] ?? 'plan')) {
            // A credit row is a ceiling the customer may spend, not a
            // debt with a schedule. Rendered as a loan it invented four
            // instalments out of the ceiling and declared the first one
            // overdue — a bill for money nobody ever borrowed.
            $this->render_credit($plan, !$is_owner);
            exit;
        }

        $this->render($plan, !$is_owner);
        exit;
    }

    /**
     * Jalali plus Gregorian, the way the invoice shows dates.
     */
    private function dual_date(string $mysql_date): string
    {
        $time = strtotime($mysql_date);

        if (false === $time || !class_exists('VKL_Wc_Account')) {
            return '—';
        }

        [$jy, $jm, $jd] = VKL_Wc_Account::g2j(
            (int) gmdate('Y', $time),
            (int) gmdate('n', $time),
            (int) gmdate('j', $time)
        );

        return sprintf('%04d/%02d/%02d — %s', $jy, $jm, $jd, gmdate('Y-m-d', $time));
    }

    /**
     * Everything that was actually received against this plan: gateway
     * payments (each one a carrier order) and payments the shop booked
     * by hand.
     *
     * @param array<string, mixed> $plan Plan row.
     *
     * @return array<int, array<string, string|float>>
     */
    private function payments(array $plan): array
    {
        $rows = [];

        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'limit'      => 100,
                'meta_key'   => VKL_Wallet_Installments::ORDER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => (string) (int) $plan['id'],          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'return'     => 'objects',
            ]);

            foreach ($orders as $order) {
                if (!$order instanceof WC_Order || !$order->is_paid()) {
                    continue;
                }

                $date = $order->get_date_paid() ?? $order->get_date_created();

                $rows[] = [
                    'sort'   => null === $date ? '' : $date->date('Y-m-d H:i:s'),
                    'date'   => null === $date ? '—' : $this->dual_date($date->date('Y-m-d H:i:s')),
                    'amount' => (float) $order->get_total(),
                    'source' => sprintf(
                        /* translators: %s: order number. */
                        __('Online payment — order %s', 'vereskala-login'),
                        $order->get_order_number()
                    ),
                    'note'   => '',
                ];
            }
        }

        foreach ($this->installments->offline_payments((int) $plan['id']) as $offline) {
            $rows[] = [
                'sort'   => (string) $offline['created_at'],
                'date'   => $this->dual_date((string) $offline['created_at']),
                'amount' => (float) $offline['amount'],
                'source' => $this->installments->payment_method_label((string) $offline['method'])
                    . ('' !== (string) $offline['reference'] ? ' — ' . (string) $offline['reference'] : ''),
                'note'   => (string) $offline['note'],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['sort'], (string) $b['sort']));

        return $rows;
    }

    /**
     * Wording and colour for an instalment's state.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function state_style(string $state): array
    {
        switch ($state) {
            case 'paid':
                return [__('Paid', 'vereskala-login'), '#157f3a', '#e9f6ee'];
            case 'due':
                return [__('Due now', 'vereskala-login'), '#8a6d1f', '#fff8e6'];
            case 'overdue':
                return [__('Overdue', 'vereskala-login'), '#b32d2e', '#fdeceb'];
            default:
                return [__('Upcoming', 'vereskala-login'), '#5a6b61', '#f4f6f5'];
        }
    }

    /**
     * The document for a purchase-credit row: a ceiling, not a debt.
     *
     * @param array<string, mixed> $plan     Credit row.
     * @param bool                 $for_shop Whether the shop is reading it.
     */
    private function render_credit(array $plan, bool $for_shop): void
    {
        $user      = get_userdata((int) $plan['user_id']);
        $available = $this->installments->available_credit((int) $plan['user_id']);
        ?>
<!DOCTYPE html>
<html dir="rtl" lang="fa-IR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(/* translators: %s: credit line title. */ __('Purchase credit — %s', 'vereskala-login'), (string) $plan['title'])); ?></title>
    <style>
        body{font-family:Tahoma,'Segoe UI',sans-serif;color:#222;margin:0;background:#f2f2f2;line-height:1.9}
        .sheet{max-width:680px;margin:20px auto;background:#fff;border-radius:12px;padding:28px 32px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
        h1{font-size:20px;color:#157f3a;margin:0 0 4px}
        table{width:100%;border-collapse:collapse;font-size:13.5px;margin:14px 0}
        td{border-bottom:1px solid #eee;padding:9px 10px}
        td:last-child{text-align:left}
        .note{background:#f4fbf6;border:1px solid #dceee2;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:14px}
        .print{position:fixed;top:14px;inset-inline-start:14px}
        .print button{background:#157f3a;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:14px;cursor:pointer}
        @media print{.print{display:none}body{background:#fff}.sheet{box-shadow:none;margin:0;border-radius:0}}
    </style>
</head>
<body>
<div class="print"><button onclick="window.print()"><?php esc_html_e('Print', 'vereskala-login'); ?></button></div>
<div class="sheet">
    <h1><?php esc_html_e('Purchase credit', 'vereskala-login'); ?></h1>
    <div style="font-size:12.5px;color:#666"><?php echo esc_html(get_bloginfo('name')); ?> — <?php echo esc_html($this->dual_date((string) $plan['created_at'])); ?></div>

    <table>
        <tr>
            <td><?php esc_html_e('Customer', 'vereskala-login'); ?></td>
            <td><?php echo esc_html($user instanceof WP_User ? $user->display_name : '—'); ?></td>
        </tr>
        <?php if ($for_shop) : ?>
            <tr>
                <td><?php esc_html_e('National ID', 'vereskala-login'); ?></td>
                <td dir="ltr"><?php echo esc_html((string) get_user_meta((int) $plan['user_id'], 'vkl_national_id', true)); ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <td><?php esc_html_e('Granted on this line', 'vereskala-login'); ?></td>
            <td><?php echo wp_kses_post(wc_price((float) $plan['total'])); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e('Available to spend right now', 'vereskala-login'); ?></td>
            <td><strong><?php echo wp_kses_post(wc_price($available)); ?></strong></td>
        </tr>
        <tr>
            <td><?php esc_html_e('Repayable in', 'vereskala-login'); ?></td>
            <td><?php
                printf(
                    /* translators: 1: number of instalments, 2: payment window. */
                    esc_html__('%1$s instalments, window %2$s of each month', 'vereskala-login'),
                    esc_html(number_format_i18n((int) $plan['installment_count'])),
                    esc_html($this->installments->window_label($plan))
                );
            ?></td>
        </tr>
    </table>

    <p class="note">
        <?php esc_html_e('This is a spending limit, not a debt: nothing is owed and no instalment falls due until the credit is used at checkout. A separate loan statement is issued for each purchase made with it.', 'vereskala-login'); ?>
    </p>
</div>
</body>
</html>
        <?php
    }

    /**
     * The document itself.
     *
     * @param array<string, mixed> $plan     Plan row.
     * @param bool                 $for_shop Whether the shop is reading it.
     */
    private function render(array $plan, bool $for_shop): void
    {
        $user      = get_userdata((int) $plan['user_id']);
        $logo      = vkl_image_url((int) get_theme_mod('custom_logo'), 'medium');
        $fee       = (float) ($plan['penalty'] ?? 0);
        $fee_open  = $this->installments->outstanding_penalty($plan);
        $principal = max(0.0, (float) $plan['total'] - $fee);
        $paid      = (float) $plan['paid'];
        $remaining = $this->installments->remaining($plan);
        $schedule  = $this->installments->schedule($plan);
        $payments  = $this->payments($plan);
        $due       = $this->installments->next_due($plan);
        $progress  = (float) $plan['total'] > 0 ? min(100, (int) round($paid / (float) $plan['total'] * 100)) : 0;
        ?>
<!DOCTYPE html>
<html dir="rtl" lang="fa-IR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(/* translators: %s: plan title. */ __('Loan statement — %s', 'vereskala-login'), (string) $plan['title'])); ?></title>
    <style>
        body{font-family:Tahoma,'Segoe UI',sans-serif;color:#222;margin:0;background:#f2f2f2;line-height:1.9}
        .sheet{max-width:840px;margin:20px auto;background:#fff;border-radius:12px;padding:28px 32px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
        .head{display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:3px solid #157f3a;padding-bottom:14px;margin-bottom:18px;flex-wrap:wrap}
        .head img{max-height:56px}
        h1{font-size:20px;color:#157f3a;margin:0}
        h2{font-size:15px;color:#157f3a;margin:22px 0 8px}
        .boxes{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:6px;font-size:13px}
        .boxes div{background:#f4fbf6;border:1px solid #dceee2;border-radius:8px;padding:10px 14px;flex:1;min-width:220px}
        .bar{background:#eee;border-radius:5px;height:10px;overflow:hidden;margin:6px 0 2px}
        .bar span{display:block;height:100%;background:#157f3a}
        table{width:100%;border-collapse:collapse;font-size:13px;margin:6px 0}
        th{background:#157f3a;color:#fff;padding:9px 10px;text-align:right;font-weight:600}
        td{border-bottom:1px solid #eee;padding:8px 10px}
        tbody tr:nth-child(even){background:#fafbfb}
        .tag{display:inline-block;border-radius:20px;padding:2px 12px;font-size:12px;font-weight:600}
        .totals{max-width:380px;margin-inline-start:auto}
        .totals td{border:0;padding:5px 10px}
        .totals tr:last-child td{border-top:2px solid #157f3a;font-weight:700;font-size:14px}
        .warn{background:#fff8e6;border:1px solid #f0dca8;border-radius:8px;padding:9px 13px;color:#7a5b12;font-size:13px;margin:10px 0}
        .foot{margin-top:20px;font-size:12px;color:#666;border-top:1px dashed #ddd;padding-top:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px}
        .print{position:fixed;top:14px;inset-inline-start:14px}
        .print button{background:#157f3a;color:#fff;border:0;border-radius:8px;padding:10px 22px;font-size:14px;cursor:pointer}
        @media print{.print{display:none}body{background:#fff}.sheet{box-shadow:none;margin:0;border-radius:0;max-width:none}}
    </style>
</head>
<body>
<div class="print"><button onclick="window.print()"><?php esc_html_e('Print', 'vereskala-login'); ?></button></div>
<div class="sheet">
    <div class="head">
        <div>
            <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
            <div style="font-size:12px;color:#666"><?php echo esc_html((string) home_url()); ?></div>
        </div>
        <?php if ('' !== $logo) : ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php endif; ?>
        <div style="text-align:left;font-size:13px">
            <strong><?php esc_html_e('Loan statement', 'vereskala-login'); ?> #<?php echo esc_html(number_format_i18n((int) $plan['id'])); ?></strong><br>
            <?php echo esc_html($this->dual_date((string) $plan['created_at'])); ?>
        </div>
    </div>

    <div class="boxes">
        <div>
            <strong><?php esc_html_e('Customer', 'vereskala-login'); ?></strong><br>
            <?php echo esc_html($user instanceof WP_User ? $user->display_name : '—'); ?><br>
            <span dir="ltr"><?php echo esc_html((string) get_user_meta((int) $plan['user_id'], 'billing_phone', true)); ?></span>
            <?php if ($for_shop) : ?>
                <br><?php esc_html_e('National ID', 'vereskala-login'); ?>:
                <span dir="ltr"><?php echo esc_html((string) get_user_meta((int) $plan['user_id'], 'vkl_national_id', true)); ?></span>
            <?php endif; ?>
        </div>
        <div>
            <strong><?php echo esc_html((string) $plan['title']); ?></strong><br>
            <?php esc_html_e('Instalments', 'vereskala-login'); ?>:
            <?php echo esc_html(number_format_i18n((int) $plan['installment_count'])); ?>
            × <?php echo wp_kses_post(wc_price((float) $plan['installment_amount'])); ?><br>
            <?php esc_html_e('Payment window', 'vereskala-login'); ?>:
            <?php echo esc_html($this->installments->window_label($plan)); ?>
            <?php esc_html_e('of each month', 'vereskala-login'); ?>
        </div>
    </div>

    <div class="bar"><span style="width:<?php echo esc_attr((string) $progress); ?>%"></span></div>
    <div style="font-size:12.5px;color:#4a5a50">
        <?php
        printf(
            /* translators: 1: amount paid, 2: total, 3: percentage. */
            esc_html__('Paid %1$s of %2$s (%3$s%%)', 'vereskala-login'),
            wp_kses_post(wc_price($paid)),
            wp_kses_post(wc_price((float) $plan['total'])),
            esc_html(number_format_i18n($progress))
        );
        ?>
    </div>

    <?php if ($fee_open > 0) : ?>
        <p class="warn">⚠️
            <?php
            printf(
                /* translators: %s: unpaid late fee. */
                esc_html__('An unpaid late fee of %s is included in the next instalment.', 'vereskala-login'),
                wp_kses_post(wc_price($fee_open))
            );
            ?>
        </p>
    <?php endif; ?>

    <h2><?php esc_html_e('Repayment schedule', 'vereskala-login'); ?></h2>
    <table>
        <thead>
        <tr>
            <th style="width:60px">#</th>
            <th><?php esc_html_e('Due window', 'vereskala-login'); ?></th>
            <th><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
            <th style="width:120px"><?php esc_html_e('State', 'vereskala-login'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($schedule as $row) : ?>
            <?php [$label, $colour, $background] = $this->state_style((string) $row['state']); ?>
            <tr>
                <td><?php echo esc_html(number_format_i18n((int) $row['number'])); ?></td>
                <td><?php echo esc_html((string) $row['label']); ?></td>
                <td><?php echo wp_kses_post(wc_price((float) $row['amount'])); ?></td>
                <td><span class="tag" style="color:<?php echo esc_attr($colour); ?>;background:<?php echo esc_attr($background); ?>"><?php echo esc_html($label); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2><?php esc_html_e('Payments received', 'vereskala-login'); ?></h2>
    <?php if ([] === $payments) : ?>
        <p style="font-size:13px;color:#666"><?php esc_html_e('Nothing has been received on this plan yet.', 'vereskala-login'); ?></p>
    <?php else : ?>
        <table>
            <thead>
            <tr>
                <th style="width:190px"><?php esc_html_e('Date', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('How it was paid', 'vereskala-login'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $row) : ?>
                <tr>
                    <td dir="ltr" style="text-align:right"><?php echo esc_html((string) $row['date']); ?></td>
                    <td><?php echo wp_kses_post(wc_price((float) $row['amount'])); ?></td>
                    <td>
                        <?php echo esc_html((string) $row['source']); ?>
                        <?php if ('' !== (string) $row['note']) : ?>
                            <div style="font-size:11.5px;color:#7a8a80"><?php echo esc_html((string) $row['note']); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php esc_html_e('Account summary', 'vereskala-login'); ?></h2>
    <table class="totals">
        <tr>
            <td><?php esc_html_e('Loan principal', 'vereskala-login'); ?></td>
            <td style="text-align:left"><?php echo wp_kses_post(wc_price($principal)); ?></td>
        </tr>
        <?php if ($fee > 0) : ?>
            <tr>
                <td><?php esc_html_e('Late fees charged', 'vereskala-login'); ?></td>
                <td style="text-align:left"><?php echo wp_kses_post(wc_price($fee)); ?></td>
            </tr>
        <?php endif; ?>
        <tr>
            <td><?php esc_html_e('Paid so far', 'vereskala-login'); ?></td>
            <td style="text-align:left"><?php echo wp_kses_post(wc_price($paid)); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e('Still owed', 'vereskala-login'); ?></td>
            <td style="text-align:left"><?php echo wp_kses_post(wc_price($remaining)); ?></td>
        </tr>
    </table>

    <?php if ($remaining > 0) : ?>
        <p style="font-size:13px;margin-top:14px">
            <?php
            printf(
                /* translators: 1: amount, 2: instalment number, 3: total instalments, 4: due window. */
                esc_html__('Next payment: %1$s — instalment %2$s of %3$s, due %4$s.', 'vereskala-login'),
                wp_kses_post(wc_price($this->installments->next_amount($plan))),
                esc_html(number_format_i18n((int) $due['number'])),
                esc_html(number_format_i18n((int) $due['of'])),
                esc_html((string) $due['label'])
            );
            ?>
        </p>
    <?php else : ?>
        <p style="color:#157f3a;font-weight:700;margin-top:14px"><?php esc_html_e('This loan is fully settled. Thank you!', 'vereskala-login'); ?></p>
    <?php endif; ?>

    <div class="foot">
        <span><?php esc_html_e('This statement reflects the shop ledger at the moment it was opened.', 'vereskala-login'); ?></span>
        <span><?php echo esc_html($this->dual_date(gmdate('Y-m-d H:i:s'))); ?></span>
    </div>
</div>
</body>
</html>
        <?php
    }
}
