<?php
/**
 * Customer surveys with loyalty rewards.
 *
 * The shop creates single-question surveys with a point reward. Customers
 * answer once per survey in a My Account tab; the reward is written into
 * the loyalty ledger (idempotent) and can be converted to wallet credit
 * through the existing club conversion — surveys therefore literally pay
 * into the wallet.
 *
 * Tables: wp_vkl_surveys, wp_vkl_survey_answers (UNIQUE (survey, user)).
 *
 * @package Vereskonto\Modules\Loyalty
 * @since   1.11.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Survey_Repository
 */
final class VKL_Survey_Repository extends VKL_Repository
{
    /**
     * Table base name.
     */
    protected function table_name(): string
    {
        return 'surveys';
    }

    /**
     * Register schema.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.11.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_surveys (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    question VARCHAR(500) NOT NULL,
                    options TEXT NOT NULL,
                    points INT UNSIGNED NOT NULL DEFAULT 0,
                    status VARCHAR(12) NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    KEY status (status)
                ) {$charset};"
            );

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_survey_answers (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    survey_id BIGINT UNSIGNED NOT NULL,
                    user_id BIGINT UNSIGNED NOT NULL,
                    answer VARCHAR(300) NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY survey_user (survey_id, user_id),
                    KEY survey_id (survey_id)
                ) {$charset};"
            );
        });
    }

    /**
     * Create a survey.
     *
     * @param string   $question Question.
     * @param string[] $options  Answer options.
     * @param int      $points   Reward points.
     */
    public function create(string $question, array $options, int $points): int
    {
        return $this->insert_row([
            'question'   => mb_substr($question, 0, 500),
            'options'    => (string) wp_json_encode(array_values($options)),
            'points'     => max(0, $points),
            'status'     => 'active',
            'created_at' => $this->now(),
        ]);
    }

    /**
     * One survey row.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->get_row('SELECT * FROM {surveys} WHERE id = %d', $id);
    }

    /**
     * Surveys, newest first (active ones first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(int $limit = 30): array
    {
        return $this->db->get_results(
            "SELECT * FROM {surveys} ORDER BY FIELD(status, 'active', 'closed'), id DESC LIMIT %d",
            $limit
        );
    }

    /**
     * Active surveys the user has not answered yet, plus their answers.
     *
     * @return array{open: array<int, array<string, mixed>>, answered: array<int, string>}
     */
    public function for_user(int $user_id): array
    {
        $open = $this->db->get_results(
            "SELECT s.* FROM {surveys} s
             LEFT JOIN {survey_answers} a ON a.survey_id = s.id AND a.user_id = %d
             WHERE s.status = 'active' AND a.id IS NULL
             ORDER BY s.id DESC LIMIT 10",
            $user_id
        );

        $rows = $this->db->get_results(
            'SELECT survey_id, answer FROM {survey_answers} WHERE user_id = %d',
            $user_id
        );

        $answered = [];

        foreach ($rows as $row) {
            $answered[(int) $row['survey_id']] = (string) $row['answer'];
        }

        return ['open' => $open, 'answered' => $answered];
    }

    /**
     * Store an answer. Returns false when already answered.
     */
    public function answer(int $survey_id, int $user_id, string $answer): bool
    {
        $exists = $this->db->get_var(
            'SELECT id FROM {survey_answers} WHERE survey_id = %d AND user_id = %d',
            $survey_id,
            $user_id
        );

        if (null !== $exists) {
            return false;
        }

        return 0 < $this->db->insert('survey_answers', [
            'survey_id'  => $survey_id,
            'user_id'    => $user_id,
            'answer'     => mb_substr($answer, 0, 300),
            'created_at' => $this->now(),
        ]);
    }

    /**
     * Result counts per option.
     *
     * @return array<string, int>
     */
    public function results(int $survey_id): array
    {
        $rows = $this->db->get_results(
            'SELECT answer, COUNT(*) AS total FROM {survey_answers} WHERE survey_id = %d GROUP BY answer ORDER BY total DESC',
            $survey_id
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['answer']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Close a survey.
     */
    public function close(int $survey_id): void
    {
        $this->db->update('surveys', ['status' => 'closed'], ['id' => $survey_id]);
    }
}

/**
 * My Account tab: answer surveys, earn points.
 */
final class VKL_Survey_Account
{
    public const ENDPOINT = 'surveys';

    private const NONCE = 'vkl_survey_front';

    private VKL_Survey_Repository $surveys;

    private VKL_Loyalty_Repository $loyalty;

    public function __construct(VKL_Survey_Repository $surveys, VKL_Loyalty_Repository $loyalty)
    {
        $this->surveys = $surveys;
        $this->loyalty = $loyalty;
    }

    public function register(): void
    {
        add_action('init', static fn() => add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES));
        add_filter('woocommerce_get_query_vars', [$this, 'query_var']);
        add_filter('woocommerce_account_menu_items', [$this, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render']);
        add_action('template_redirect', [$this, 'handle_post']);
        add_action('template_redirect', [$this, 'handle_review']);
    }

    /** @param array<string, string> $vars */
    public function query_var(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /** @param array<string, string> $items */
    public function menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT] = __('Surveys', 'vereskala-login');

        if (null !== $logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /**
     * Recent purchased products the user has not reviewed yet.
     *
     * @return WC_Product[]
     */
    private function reviewable_products(int $user_id, int $limit = 6): array
    {
        $orders = wc_get_orders(['customer_id' => $user_id, 'status' => ['completed'], 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC']);

        $products = [];

        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $product = $item->get_product();

                if (!$product instanceof WC_Product || isset($products[$product->get_id()]) || $product->is_virtual()) {
                    continue;
                }

                // Already reviewed by this user?
                $existing = get_comments([
                    'post_id' => $product->get_id(),
                    'user_id' => $user_id,
                    'type'    => 'review',
                    'count'   => true,
                ]);

                if (0 === (int) $existing) {
                    $products[$product->get_id()] = $product;
                }

                if (count($products) >= $limit) {
                    return array_values($products);
                }
            }
        }

        return array_values($products);
    }

    /**
     * Handle a paid product review: three 1–5 scores + optional text →
     * a real WooCommerce review (SEO content) + club points.
     */
    public function handle_review(): void
    {
        if (!is_user_logged_in()
            || !isset($_POST['vkl_review_product'], $_POST['_vkl_survey_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_survey_nonce'])), self::NONCE)
        ) {
            return;
        }

        $user_id    = get_current_user_id();
        $product_id = absint($_POST['vkl_review_product']);
        $product    = wc_get_product($product_id);

        $scores = [];

        foreach (['quality', 'value', 'satisfaction'] as $key) {
            $score = isset($_POST['vkl_score_' . $key]) ? absint($_POST['vkl_score_' . $key]) : 0;

            if ($score < 1 || $score > 5) {
                return;
            }

            $scores[$key] = $score;
        }

        if (!$product instanceof WC_Product) {
            return;
        }

        // The product must actually be reviewable by this user (purchased,
        // not yet reviewed) — same list the form was rendered from.
        $allowed = array_map(static fn(WC_Product $p): int => $p->get_id(), $this->reviewable_products($user_id, 20));

        if (!in_array($product_id, $allowed, true)) {
            return;
        }

        $text = isset($_POST['vkl_review_text']) ? sanitize_textarea_field(wp_unslash((string) $_POST['vkl_review_text'])) : '';
        $user = wp_get_current_user();

        $overall = (int) round(array_sum($scores) / count($scores));

        $comment_id = wp_insert_comment([
            'comment_post_ID'      => $product_id,
            'comment_author'       => $user->display_name,
            'comment_author_email' => (string) $user->user_email,
            'comment_content'      => '' !== $text ? $text : sprintf(
                /* translators: %d: overall rating. */
                __('Overall rating: %d out of 5.', 'vereskala-login'),
                $overall
            ),
            'comment_type'         => 'review',
            'comment_approved'     => 0, // Moderated like any review.
            'user_id'              => $user_id,
        ]);

        if (!is_int($comment_id) || $comment_id <= 0) {
            return;
        }

        add_comment_meta($comment_id, 'rating', $overall, true);
        add_comment_meta($comment_id, 'vkl_score_quality', $scores['quality'], true);
        add_comment_meta($comment_id, 'vkl_score_value', $scores['value'], true);
        add_comment_meta($comment_id, 'vkl_score_satisfaction', $scores['satisfaction'], true);

        // Pay for the review — idempotent per product+user.
        $points    = max(0, vkl('config')->get_int('survey_review_points', 5));
        $reference = 'review:' . $product_id . ':user:' . $user_id;

        if ($points > 0 && !$this->loyalty->exists('review', $reference)) {
            $this->loyalty->append($user_id, $points, 'review', $reference);
            vkl_dispatch('loyalty.points_earned', ['user_id' => $user_id, 'points' => $points, 'reason' => 'review']);
        }

        wc_add_notice(
            $points > 0
                ? sprintf(
                    /* translators: %d: points earned. */
                    __('Your review was submitted for moderation and %d points landed in your club balance. Thank you!', 'vereskala-login'),
                    $points
                )
                : __('Your review was submitted for moderation. Thank you!', 'vereskala-login'),
            'success'
        );

        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    /**
     * Handle an answer submission.
     */
    public function handle_post(): void
    {
        if (!is_user_logged_in()
            || !isset($_POST['vkl_survey_id'], $_POST['_vkl_survey_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['_vkl_survey_nonce'])), self::NONCE)
        ) {
            return;
        }

        $survey_id = absint($_POST['vkl_survey_id']);
        $answer    = isset($_POST['vkl_survey_answer']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_survey_answer'])) : '';
        $survey    = $this->surveys->find($survey_id);
        $user_id   = get_current_user_id();

        if (null === $survey || 'active' !== (string) $survey['status'] || '' === $answer) {
            return;
        }

        // Only the survey's own options are accepted.
        $options = json_decode((string) $survey['options'], true);

        if (!is_array($options) || !in_array($answer, array_map('strval', $options), true)) {
            return;
        }

        if (!$this->surveys->answer($survey_id, $user_id, $answer)) {
            return;
        }

        // Reward — idempotent through the ledger reference.
        $points    = (int) $survey['points'];
        $reference = 'survey:' . $survey_id . ':user:' . $user_id;

        if ($points > 0 && !$this->loyalty->exists('survey', $reference)) {
            $this->loyalty->append($user_id, $points, 'survey', $reference);

            vkl_dispatch('loyalty.points_earned', [
                'user_id' => $user_id,
                'points'  => $points,
                'reason'  => 'survey',
            ]);
        }

        wc_add_notice(
            $points > 0
                ? sprintf(
                    /* translators: %d: points earned. */
                    __('Thanks! %d points were added to your club balance — convert them to wallet credit any time.', 'vereskala-login'),
                    $points
                )
                : __('Thanks for your feedback!', 'vereskala-login'),
            'success'
        );

        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    /**
     * Render the tab.
     */
    public function render(): void
    {
        $user_id = get_current_user_id();
        $data    = $this->surveys->for_user($user_id);

        // ---- Paid reviews of purchased products (SEO-friendly UGC) ----
        $reviewable = $this->reviewable_products($user_id);
        $points     = max(0, vkl('config')->get_int('survey_review_points', 5));

        if ([] !== $reviewable) {
            echo '<h3 style="margin-top:0">💰 ' . esc_html__('Turn your opinion into money!', 'vereskala-login') . '</h3>';
            echo '<p>' . esc_html(sprintf(
                /* translators: %d: points per review. */
                __('Review the products you bought — each review earns %d club points, convertible to wallet credit, and helps other shoppers choose.', 'vereskala-login'),
                $points
            )) . '</p>';

            $criteria = [
                'quality'      => __('Product quality', 'vereskala-login'),
                'value'        => __('Value for money', 'vereskala-login'),
                'satisfaction' => __('Overall satisfaction', 'vereskala-login'),
            ];

            foreach ($reviewable as $product) {
                ?>
                <form method="post" class="vkl-card" style="border:1px solid #e2e2e2;border-radius:12px;padding:14px 16px;margin-bottom:14px;background:#fff">
                    <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
                        <?php echo wp_kses_post($product->get_image('woocommerce_gallery_thumbnail', ['style' => 'width:52px;height:52px;object-fit:cover;border-radius:8px'])); ?>
                        <strong><?php echo esc_html($product->get_name()); ?></strong>
                    </div>
                    <?php foreach ($criteria as $key => $label) : ?>
                        <p style="margin:6px 0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <span style="min-width:130px"><?php echo esc_html($label); ?></span>
                            <span class="vkl-stars" style="display:inline-flex;flex-direction:row-reverse;gap:2px">
                                <?php for ($star = 5; $star >= 1; $star--) : ?>
                                    <label style="cursor:pointer;font-size:20px;color:#c8c8c8">
                                        <input type="radio" name="vkl_score_<?php echo esc_attr($key); ?>" value="<?php echo (int) $star; ?>" required style="display:none">★
                                    </label>
                                <?php endfor; ?>
                            </span>
                        </p>
                    <?php endforeach; ?>
                    <p style="margin:8px 0">
                        <textarea name="vkl_review_text" rows="2" style="width:100%" placeholder="<?php esc_attr_e('A line or two about your experience (optional — but it helps a lot).', 'vereskala-login'); ?>"></textarea>
                    </p>
                    <input type="hidden" name="vkl_review_product" value="<?php echo (int) $product->get_id(); ?>">
                    <?php wp_nonce_field(self::NONCE, '_vkl_survey_nonce'); ?>
                    <button type="submit" class="button">
                        <?php
                        printf(
                            /* translators: %d: points per review. */
                            esc_html__('Submit review & earn %d points', 'vereskala-login'),
                            $points
                        );
                        ?>
                    </button>
                </form>
                <?php
            }

            // Star highlight (pure CSS-in-JS-free trick via :checked is not
            // possible with hidden inputs, so a tiny script does it).
            ?>
            <script>
            document.querySelectorAll('.vkl-stars').forEach(function (group) {
                var labels = Array.prototype.slice.call(group.querySelectorAll('label'));
                group.addEventListener('change', function () {
                    var checked = group.querySelector('input:checked');
                    var val = checked ? parseInt(checked.value, 10) : 0;
                    labels.forEach(function (l) {
                        var v = parseInt(l.querySelector('input').value, 10);
                        l.style.color = v <= val ? '#f5a623' : '#c8c8c8';
                    });
                });
            });
            </script>
            <?php
        }

        if ([] === $data['open'] && [] === $data['answered'] && [] === $reviewable) {
            echo '<p>' . esc_html__('No surveys right now — check back soon!', 'vereskala-login') . '</p>';

            return;
        }

        if ([] !== $data['open']) {
            echo '<h3>' . esc_html__('Surveys', 'vereskala-login') . '</h3>';
        }

        foreach ($data['open'] as $survey) {
            $options = json_decode((string) $survey['options'], true);

            if (!is_array($options)) {
                continue;
            }
            ?>
            <form method="post" style="border:1px solid #e2e2e2;border-radius:10px;padding:14px 16px;margin-bottom:14px">
                <p style="font-weight:700;margin-top:0"><?php echo esc_html((string) $survey['question']); ?></p>
                <?php foreach ($options as $option) : ?>
                    <label style="display:block;margin:6px 0">
                        <input type="radio" name="vkl_survey_answer" value="<?php echo esc_attr((string) $option); ?>" required>
                        <?php echo esc_html((string) $option); ?>
                    </label>
                <?php endforeach; ?>
                <?php if ((int) $survey['points'] > 0) : ?>
                    <p style="color:#157f3a">
                        <?php
                        printf(
                            /* translators: %d: points. */
                            esc_html__('Reward: %d club points (convertible to wallet credit).', 'vereskala-login'),
                            (int) $survey['points']
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <input type="hidden" name="vkl_survey_id" value="<?php echo (int) $survey['id']; ?>">
                <?php wp_nonce_field(self::NONCE, '_vkl_survey_nonce'); ?>
                <button type="submit" class="button"><?php esc_html_e('Submit answer', 'vereskala-login'); ?></button>
            </form>
            <?php
        }

        if ([] !== $data['answered']) {
            echo '<p class="description">'
                . esc_html(
                    sprintf(
                        /* translators: %d: number of answered surveys. */
                        _n('You have answered %d survey.', 'You have answered %d surveys.', count($data['answered']), 'vereskala-login'),
                        count($data['answered'])
                    )
                )
                . '</p>';
        }
    }
}
