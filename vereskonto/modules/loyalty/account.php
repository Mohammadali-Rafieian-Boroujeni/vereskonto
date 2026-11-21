<?php
/**
 * Loyalty My Account tab — points balance, history, convert to wallet.
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Loyalty_Account
 */
final class VKL_Loyalty_Account
{
    public const ENDPOINT = 'club';

    /**
     * Slug used before 1.6.0; kept registered so old links redirect
     * instead of 404ing.
     */
    public const LEGACY_ENDPOINT = 'vkl-club';

    private const NONCE = 'vkl_loyalty_convert';

    private VKL_Loyalty_Repository $repository;

    private VKL_Wallet_Service $wallet;

    private VKL_Config $config;

    public function __construct(VKL_Loyalty_Repository $repository, VKL_Wallet_Service $wallet, VKL_Config $config)
    {
        $this->repository = $repository;
        $this->wallet     = $wallet;
        $this->config     = $config;
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
            add_rewrite_endpoint(self::LEGACY_ENDPOINT, EP_ROOT | EP_PAGES);
        });
        add_filter('woocommerce_get_query_vars', [$this, 'query_var']);
        add_filter('woocommerce_account_menu_items', [$this, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render']);
        add_action('template_redirect', [$this, 'redirect_legacy'], 5);
        add_action('template_redirect', [$this, 'handle_convert']);
    }

    /** Permanent redirect from the pre-1.6.0 slug to the clean one. */
    public function redirect_legacy(): void
    {
        global $wp_query;

        if (isset($wp_query->query_vars[self::LEGACY_ENDPOINT])) {
            wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT), 301);
            exit;
        }
    }

    /** @param array<string, string> $vars */
    public function query_var(array $vars): array
    {
        $vars[self::ENDPOINT]        = self::ENDPOINT;
        $vars[self::LEGACY_ENDPOINT] = self::LEGACY_ENDPOINT;

        return $vars;
    }

    /** @param array<string, string> $items */
    public function menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT] = __('Customer club', 'vereskala-login');

        if (null !== $logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /** Convert the full points balance to wallet credit. */
    public function handle_convert(): void
    {
        if (!is_user_logged_in() || empty($_POST['vkl_loyalty_convert'])) {
            return;
        }

        $nonce = isset($_POST['_vkl_loyalty_nonce']) ? sanitize_text_field(wp_unslash($_POST['_vkl_loyalty_nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }

        $user_id = get_current_user_id();
        $points  = $this->repository->balance($user_id);
        $value   = max(0, $this->config->get_int('loyalty_point_value', 0));

        if ($points > 0 && $value > 0) {

            // Deduct first (append is atomic), then credit; if credit ever
            // failed the negative row documents intent and support can fix.
            $this->repository->append($user_id, -$points, 'converted_to_wallet');

            $this->wallet->credit($user_id, (float) ($points * $value), 'loyalty_conversion', 'points:' . $points);

            wc_add_notice(
                sprintf(
                    /* translators: %d: converted points. */
                    __('%d points converted to wallet credit.', 'vereskala-login'),
                    $points
                )
            );
        }

        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    public function render(): void
    {
        $user_id = get_current_user_id();
        $points  = $this->repository->balance($user_id);
        $value   = max(0, $this->config->get_int('loyalty_point_value', 0));
        ?>
        <h3><?php esc_html_e('Customer club', 'vereskala-login'); ?></h3>

        <p>
            <?php esc_html_e('Your points:', 'vereskala-login'); ?>
            <strong><?php echo esc_html(number_format_i18n($points)); ?></strong>
        </p>

        <?php
        // ---- Membership tier: same maths as the admin "Top members". ----
        $year_points = $this->repository->year_points($user_id);
        $silver      = max(1, $this->config->get_int('loyalty_tier_silver', 50));
        $gold        = max($silver + 1, $this->config->get_int('loyalty_tier_gold', 200));
        $diamond     = max($gold + 1, $this->config->get_int('loyalty_tier_diamond', 500));

        $tiers = [
            'bronze'  => [__('Bronze', 'vereskala-login'), 0, '🥉', '#b07a4a', $this->config->get_string('loyalty_perks_bronze') ?: __("Earn points on every completed order\nConvert points to wallet credit any time", 'vereskala-login')],
            'silver'  => [__('Silver', 'vereskala-login'), $silver, '🥈', '#8a8f98', $this->config->get_string('loyalty_perks_silver') ?: __("Everything in Bronze\nOccasional personal discount coupons\nEarly access to sales", 'vereskala-login')],
            'gold'    => [__('Gold', 'vereskala-login'), $gold, '🥇', '#d4a017', $this->config->get_string('loyalty_perks_gold') ?: __("Everything in Silver\nFREE dispatch on all orders\nPriority support", 'vereskala-login')],
            'diamond' => [__('Diamond', 'vereskala-login'), $diamond, '💎', '#3b82c4', $this->config->get_string('loyalty_perks_diamond') ?: __("Everything in Gold\nDedicated shopping advisor\nSpecial birthday gift", 'vereskala-login')],
        ];

        $current = 'bronze';

        foreach ($tiers as $key => $tier) {
            if ($year_points >= $tier[1]) {
                $current = $key;
            }
        }

        $keys      = array_keys($tiers);
        $pos       = (int) array_search($current, $keys, true);
        $next_key  = $keys[$pos + 1] ?? '';
        ?>
        <div style="border:1px solid #e2e2e2;border-radius:12px;padding:14px 18px;margin:12px 0;background:linear-gradient(135deg,#fdfdfd,#f1faf4)">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:30px"><?php echo esc_html($tiers[$current][2]); ?></span>
                <div>
                    <strong style="color:<?php echo esc_attr($tiers[$current][3]); ?>;font-size:1.15em">
                        <?php
                        printf(
                            /* translators: %s: tier name. */
                            esc_html__('Your membership tier: %s', 'vereskala-login'),
                            esc_html($tiers[$current][0])
                        );
                        ?>
                    </strong>
                    <div class="description">
                        <?php
                        printf(
                            /* translators: %s: points this year. */
                            esc_html__('Points earned this year: %s', 'vereskala-login'),
                            esc_html(number_format_i18n($year_points))
                        );
                        ?>
                    </div>
                </div>
            </div>
            <?php if ('' !== $next_key) : ?>
                <?php $needed = $tiers[$next_key][1] - $year_points; ?>
                <div style="margin-top:10px">
                    <div style="background:#e8e8e8;border-radius:5px;height:9px;overflow:hidden">
                        <span style="display:block;height:100%;background:#157f3a;width:<?php echo esc_attr((string) min(100, (int) round($year_points * 100 / max(1, $tiers[$next_key][1])))); ?>%"></span>
                    </div>
                    <p class="description" style="margin:5px 0 0">
                        <?php
                        printf(
                            /* translators: 1: points needed, 2: next tier name. */
                            esc_html__('%1$s more points this year to reach %2$s.', 'vereskala-login'),
                            esc_html(number_format_i18n(max(0, $needed))),
                            esc_html($tiers[$next_key][0])
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <p class="description" style="margin:8px 0 0"><?php esc_html_e('You are at the highest tier — thank you for being with us!', 'vereskala-login'); ?></p>
            <?php endif; ?>
        </div>

        <h3><?php esc_html_e('Tier benefits', 'vereskala-login'); ?></h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:14px">
            <?php foreach ($tiers as $key => $tier) : ?>
                <div style="border:2px solid <?php echo esc_attr($key === $current ? $tier[3] : '#e8e8e8'); ?>;border-radius:12px;padding:12px 14px;<?php echo $key === $current ? 'box-shadow:0 3px 10px rgba(0,0,0,.08);' : ''; ?>">
                    <div style="font-weight:700;color:<?php echo esc_attr($tier[3]); ?>">
                        <?php echo esc_html($tier[2] . ' ' . $tier[0]); ?>
                        <?php if ($key === $current) : ?>
                            <span style="background:#eaf9ef;color:#157f3a;border-radius:10px;padding:1px 8px;font-size:11px;margin-inline-start:4px"><?php esc_html_e('You are here', 'vereskala-login'); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="description" style="margin-top:4px;font-size:.86em">
                        <?php
                        printf(
                            /* translators: %s: points threshold. */
                            esc_html__('From %s yearly points', 'vereskala-login'),
                            esc_html(number_format_i18n($tier[1]))
                        );
                        ?>
                    </div>
                    <ul style="margin:8px 0 0;padding-inline-start:16px;font-size:.92em;line-height:1.9">
                        <?php foreach (array_filter(array_map('trim', explode("\n", (string) $tier[4]))) as $perk) : ?>
                            <li><?php echo esc_html($perk); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($points > 0 && $value > 0) : ?>
            <form method="post">
                <input type="hidden" name="vkl_loyalty_convert" value="1">
                <?php wp_nonce_field(self::NONCE, '_vkl_loyalty_nonce'); ?>
                <button type="submit" class="button">
                    <?php
                    printf(
                        /* translators: %s: formatted wallet amount. */
                        esc_html__('Convert all points to wallet credit (%s)', 'vereskala-login'),
                        wp_kses_post(wc_price($points * $value))
                    );
                    ?>
                </button>
            </form>
        <?php endif; ?>

        <h4><?php esc_html_e('History', 'vereskala-login'); ?></h4>

        <table class="woocommerce-table shop_table">
            <thead><tr>
                <th><?php esc_html_e('Date', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Points', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php $vkl_history = $this->repository->latest_for_user($user_id); ?>
            <?php if ([] === $vkl_history) : ?>
                <tr><td colspan="2" style="text-align:center;color:#666;padding:18px">
                    <?php esc_html_e('No points yet — every completed order earns you points automatically.', 'vereskala-login'); ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($vkl_history as $row) : ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $row['created_at']))); ?></td>
                    <td><?php echo esc_html(number_format_i18n((int) $row['points'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
