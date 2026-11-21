<?php
/**
 * Refer-a-friend (دعوت دوستان).
 *
 * Every customer gets a permanent unique code and share link. When an
 * invited person registers through that link and later completes their
 * FIRST order at or above the admin-set minimum, the referrer's wallet
 * is credited automatically with the admin-set reward — once per
 * referred person — and an SMS announces the deposit. The customer's
 * club page lists their invitees, each turning green the moment the
 * reward lands.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.34.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Loyalty_Referral
 */
final class VKL_Loyalty_Referral extends VKL_Repository
{
    private const COOKIE = 'vkl_ref';

    private const META_CODE = 'vkl_ref_code';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REWARDED = 'rewarded';

    private VKL_Config $config;

    /**
     * Constructor.
     */
    public function __construct(VKL_Database $db, VKL_Config $config)
    {
        parent::__construct($db);

        $this->config = $config;
    }

    /**
     * Table key.
     */
    protected function table_name(): string
    {
        return 'referrals';
    }

    /**
     * Migrations.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.34.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_referrals (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    referrer_id BIGINT UNSIGNED NOT NULL,
                    referred_id BIGINT UNSIGNED NOT NULL,
                    code VARCHAR(20) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    reward_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    rewarded_at DATETIME NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY referred_id (referred_id),
                    KEY referrer_id (referrer_id),
                    KEY status (status)
                ) {$wpdb->get_charset_collate()};"
            );
        });
    }

    /**
     * Hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'capture_link']);
        add_action('user_register', [$this, 'link_new_user'], 30);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_reward'], 20);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_reward'], 20);

        // Renders after the club balance block on the same endpoint.
        add_action('woocommerce_account_' . VKL_Loyalty_Account::ENDPOINT . '_endpoint', [$this, 'render_customer'], 20);
    }

    /**
     * Whether the whole feature is on (reward must be configured).
     */
    public function enabled(): bool
    {
        return $this->config->get_bool('loyalty_ref_enabled', false)
            && $this->config->get_int('loyalty_ref_reward', 0) > 0;
    }

    /* -------------------------------------------------------------- *
     *  Codes & capture
     * -------------------------------------------------------------- */

    /**
     * Permanent unique code for a user (created on first request).
     */
    public function code_for(int $user_id): string
    {
        $code = (string) get_user_meta($user_id, self::META_CODE, true);

        if ('' !== $code) {
            return $code;
        }

        global $wpdb;

        do {
            $code   = 'VK' . strtoupper(substr(str_shuffle('23456789ABCDEFGHJKMNPQRSTUVWXYZ'), 0, 6));
            $exists = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
                self::META_CODE,
                $code
            ));
        } while ($exists > 0);

        update_user_meta($user_id, self::META_CODE, $code);

        return $code;
    }

    /**
     * User id owning a code (0 = unknown).
     */
    public function user_by_code(string $code): int
    {
        $code = strtoupper(trim($code));

        if (1 !== preg_match('/^VK[A-Z0-9]{6}$/', $code)) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            self::META_CODE,
            $code
        ));
    }

    /**
     * ?ref=CODE on any front-end URL → 30-day cookie.
     */
    public function capture_link(): void
    {
        if (is_admin() || !$this->enabled()) {
            return;
        }

        $code = isset($_GET['ref']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['ref']))) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (1 !== preg_match('/^VK[A-Z0-9]{6}$/', $code) || headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, $code, [
            'expires'  => time() + 30 * DAY_IN_SECONDS,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * A new account registered while the cookie is set → link it.
     */
    public function link_new_user(int $user_id): void
    {
        if (!$this->enabled()) {
            return;
        }

        $code = isset($_COOKIE[self::COOKIE]) ? strtoupper(sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]))) : '';

        if ('' === $code) {
            return;
        }

        $referrer = $this->user_by_code($code);

        // Real people only: no self-referral, both sides must exist.
        if ($referrer <= 0 || $referrer === $user_id) {
            return;
        }

        // UNIQUE KEY on referred_id makes double inserts impossible, but
        // check anyway to keep the log clean.
        $existing = $this->db->get_var('SELECT id FROM {referrals} WHERE referred_id = %d', $user_id);

        if (null !== $existing) {
            return;
        }

        $this->insert_row([
            'referrer_id' => $referrer,
            'referred_id' => $user_id,
            'code'        => $code,
            'status'      => self::STATUS_PENDING,
            'created_at'  => $this->now(),
        ]);
    }

    /* -------------------------------------------------------------- *
     *  Reward
     * -------------------------------------------------------------- */

    /**
     * Order completed/processing: reward the referrer when this is the
     * referred customer's FIRST qualifying order.
     */
    public function maybe_reward(int $order_id): void
    {
        if (!$this->enabled() || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            return;
        }

        $customer = (int) $order->get_customer_id();

        if ($customer <= 0) {
            return;
        }

        $row = $this->db->get_row(
            'SELECT * FROM {referrals} WHERE referred_id = %d AND status = %s',
            $customer,
            self::STATUS_PENDING
        );

        if (null === $row) {
            return;
        }

        // The invited friend must spend at least the admin-set minimum.
        $min = max(0, $this->config->get_int('loyalty_ref_min_order', 0));

        if ((float) $order->get_total() < (float) $min) {
            return;
        }

        $reward = $this->config->get_int('loyalty_ref_reward', 0);

        // Atomic claim: only one process flips pending → rewarded.
        $claimed = $this->db->query(
            'UPDATE {referrals}
             SET status = %s, reward_amount = %d, order_id = %d, rewarded_at = %s
             WHERE id = %d AND status = %s',
            self::STATUS_REWARDED,
            $reward,
            $order_id,
            $this->now(),
            (int) $row['id'],
            self::STATUS_PENDING
        );

        if (1 !== $claimed) {
            return;
        }

        $referrer = (int) $row['referrer_id'];

        vkl('wallet')->credit(
            $referrer,
            (float) $reward,
            'referral_reward',
            'referral:' . (int) $row['id'],
            sprintf(
                /* translators: %s: friend's display name. */
                __('Referral reward — your invited friend made their first purchase (%s)', 'vereskala-login'),
                $this->masked_name($customer)
            )
        );

        $this->send_reward_sms($referrer, $reward);
    }

    /**
     * Deposit SMS to the referrer (pattern-based, logged like the rest).
     */
    private function send_reward_sms(int $referrer, int $reward): void
    {
        $pattern = $this->config->get_string('loyalty_ref_pattern');

        if ('' === $pattern || !vkl()->has('sms.sender')) {
            return;
        }

        $mobile = (string) get_user_meta($referrer, 'billing_phone', true);

        if ('' === $mobile) {
            return;
        }

        vkl('sms.sender')->send_bulk(
            [$mobile],
            'referral.reward',
            $pattern,
            [
                'name'   => 'auto',
                'amount' => (string) $reward,
            ]
        );
    }

    /* -------------------------------------------------------------- *
     *  Data for UIs
     * -------------------------------------------------------------- */

    /**
     * A user's invitees, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invitees(int $referrer_id): array
    {
        return $this->db->get_results(
            'SELECT * FROM {referrals} WHERE referrer_id = %d ORDER BY id DESC LIMIT 100',
            $referrer_id
        );
    }

    /**
     * Program stats for the admin page.
     *
     * @return array{total: int, rewarded: int, paid: int}
     */
    public function stats(): array
    {
        $row = $this->db->get_row(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'rewarded' THEN 1 ELSE 0 END) AS rewarded,
                    COALESCE(SUM(CASE WHEN status = 'rewarded' THEN reward_amount ELSE 0 END), 0) AS paid
             FROM {referrals} WHERE 1 = %d",
            1
        );

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'rewarded' => (int) ($row['rewarded'] ?? 0),
            'paid'     => (int) ($row['paid'] ?? 0),
        ];
    }

    /**
     * Privacy-friendly display of the invited person's name.
     */
    private function masked_name(int $user_id): string
    {
        $user = get_userdata($user_id);

        if (!$user) {
            return '#' . $user_id;
        }

        $name = trim($user->display_name);

        // A mobile-derived login like vk_0912… shows as a masked number.
        if ('' === $name || str_starts_with($name, 'vk_')) {
            $mobile = (string) get_user_meta($user_id, 'billing_phone', true);

            return '' !== $mobile ? substr($mobile, 0, 4) . '***' . substr($mobile, -3) : ('#' . $user_id);
        }

        return $name;
    }

    /* -------------------------------------------------------------- *
     *  Customer block (club endpoint)
     * -------------------------------------------------------------- */

    /**
     * The "invite friends" block on the My Account club page.
     */
    public function render_customer(): void
    {
        if (!$this->enabled()) {
            return;
        }

        $user_id  = get_current_user_id();
        $code     = $this->code_for($user_id);
        $link     = add_query_arg('ref', $code, home_url('/'));
        $reward   = $this->config->get_int('loyalty_ref_reward', 0);
        $min      = $this->config->get_int('loyalty_ref_min_order', 0);
        $invitees = $this->invitees($user_id);
        ?>
        <div class="vkl-ref" style="background:#fff;border:1px solid #e6e6e6;border-radius:14px;padding:16px 18px;margin-top:18px">
            <h3 style="margin-top:0">🤝 <?php esc_html_e('Invite friends', 'vereskala-login'); ?></h3>

            <p>
                <?php
                printf(
                    /* translators: 1: reward amount, 2: minimum order. */
                    esc_html__('Share your link — when an invited friend makes their first purchase of at least %2$s, %1$s is deposited to your wallet automatically.', 'vereskala-login'),
                    '<strong>' . (function_exists('wc_price') ? wp_kses_post(wc_price($reward)) : esc_html(number_format_i18n($reward))) . '</strong>',
                    '<strong>' . (function_exists('wc_price') ? wp_kses_post(wc_price($min)) : esc_html(number_format_i18n($min))) . '</strong>'
                );
                ?>
            </p>

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0">
                <span style="background:#eaf9ef;color:#157f3a;border-radius:10px;padding:6px 14px;font-weight:800;letter-spacing:1px" dir="ltr"><?php echo esc_html($code); ?></span>
                <input type="text" readonly value="<?php echo esc_attr($link); ?>" id="vkl-ref-link"
                       style="flex:1;min-width:220px;direction:ltr" onclick="this.select()">
                <button type="button" class="button" id="vkl-ref-copy">📋 <?php esc_html_e('Copy link', 'vereskala-login'); ?></button>
            </div>

            <script>
            (function () {
                var btn = document.getElementById('vkl-ref-copy');
                var input = document.getElementById('vkl-ref-link');
                if (!btn || !input) { return; }
                btn.addEventListener('click', function () {
                    input.select();
                    try {
                        if (navigator.clipboard) { navigator.clipboard.writeText(input.value); }
                        else { document.execCommand('copy'); }
                        btn.textContent = '✓ <?php echo esc_js(__('Copied', 'vereskala-login')); ?>';
                        setTimeout(function () { btn.textContent = '📋 <?php echo esc_js(__('Copy link', 'vereskala-login')); ?>'; }, 1800);
                    } catch (e) {}
                });
            })();
            </script>

            <?php if ([] !== $invitees) : ?>
                <h4 style="margin:14px 0 8px"><?php esc_html_e('Your invitees', 'vereskala-login'); ?></h4>
                <div style="display:flex;flex-direction:column;gap:6px">
                    <?php foreach ($invitees as $invitee) : ?>
                        <?php $rewarded = self::STATUS_REWARDED === (string) $invitee['status']; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid <?php echo $rewarded ? '#bfe9cb' : '#eee'; ?>;background:<?php echo $rewarded ? '#f0fcf4' : '#fafafa'; ?>;border-radius:10px;padding:8px 12px">
                            <span>
                                👤 <?php echo esc_html($this->masked_name((int) $invitee['referred_id'])); ?>
                                <span style="font-size:11px;color:#888">· <?php echo esc_html(mysql2date(get_option('date_format'), (string) $invitee['created_at'])); ?></span>
                            </span>
                            <?php if ($rewarded) : ?>
                                <span style="color:#157f3a;font-weight:700;display:flex;align-items:center;gap:5px">
                                    ✅ <?php esc_html_e('Purchased — reward deposited to your wallet', 'vereskala-login'); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#9a7b00;font-weight:600">⏳ <?php esc_html_e('Waiting for their first purchase', 'vereskala-login'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="description" style="color:#777"><?php esc_html_e('No invitees yet — share your link and the list appears here.', 'vereskala-login'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
