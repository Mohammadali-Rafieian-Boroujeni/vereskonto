<?php
/**
 * Product insights — social proof on the single product page.
 *
 * Tracks product page views per day in a dedicated table and renders a
 * compact box under the product summary: today's/this week's views, a
 * summary of local customer reviews (average, count, star distribution),
 * and a one-line guidance sentence derived from both.
 *
 * External review aggregation (other marketplaces) is intentionally NOT
 * scraped here; if a data feed/API becomes available it can be added as
 * a source via the 'vkl_insights_extra_lines' filter.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Insights
 */
final class VKL_Smart_Insights extends VKL_Repository
{
    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * @param VKL_Database $db     Database.
     * @param VKL_Config   $config Config.
     */
    public function __construct(VKL_Database $db, VKL_Config $config)
    {
        parent::__construct($db);
        $this->config = $config;
    }

    /**
     * Table base name.
     */
    protected function table_name(): string
    {
        return 'product_views';
    }

    /**
     * Register schema.
     *
     * @param VKL_Migrator $migrator Migrator.
     */
    public static function register_migrations(VKL_Migrator $migrator): void
    {
        $migrator->register('1.9.0', static function (): void {
            global $wpdb;

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset = $wpdb->get_charset_collate();

            dbDelta(
                "CREATE TABLE {$wpdb->prefix}vkl_product_views (
                    product_id BIGINT UNSIGNED NOT NULL,
                    day DATE NOT NULL,
                    views INT UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY  (product_id, day),
                    KEY day (day)
                ) {$charset};"
            );
        });
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('template_redirect', [$this, 'count_view']);
        add_action('woocommerce_single_product_summary', [$this, 'render'], 39);
    }

    /**
     * Count one view per product page load (bots with common UA markers skipped).
     */
    public function count_view(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower((string) $_SERVER['HTTP_USER_AGENT']) : ''; // phpcs:ignore

        foreach (['bot', 'crawl', 'spider', 'slurp', 'lighthouse'] as $marker) {
            if (false !== strpos($ua, $marker)) {
                return;
            }
        }

        $product_id = (int) get_queried_object_id();

        if ($product_id <= 0) {
            return;
        }

        $this->db->query(
            'INSERT INTO {product_views} (product_id, day, views) VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE views = views + 1',
            [$product_id, gmdate('Y-m-d')]
        );
    }

    /**
     * Views for a product: [today, last 7 days].
     *
     * @param int $product_id Product id.
     *
     * @return array{0: int, 1: int}
     */
    public function views(int $product_id): array
    {
        $today = (int) $this->db->get_var(
            'SELECT views FROM {product_views} WHERE product_id = %d AND day = %s',
            [$product_id, gmdate('Y-m-d')]
        );

        $week = (int) $this->db->get_var(
            'SELECT COALESCE(SUM(views), 0) FROM {product_views} WHERE product_id = %d AND day >= %s',
            [$product_id, gmdate('Y-m-d', time() - 6 * DAY_IN_SECONDS)]
        );

        return [$today, $week];
    }

    /**
     * Render the insights box.
     */
    public function render(): void
    {
        if (!$this->config->get_bool('insights_enabled', false)) {
            return;
        }

        $product = wc_get_product(get_the_ID());

        if (!$product instanceof WC_Product) {
            return;
        }

        [$today, $week] = $this->views($product->get_id());

        $count   = (int) $product->get_review_count();
        $average = (float) $product->get_average_rating();

        // Star distribution from local reviews.
        $distribution = [];

        if ($count > 0) {
            foreach ([5, 4, 3, 2, 1] as $stars) {
                $distribution[$stars] = (int) get_comments([
                    'post_id'    => $product->get_id(),
                    'status'     => 'approve',
                    'type'       => 'review',
                    'count'      => true,
                    'meta_key'   => 'rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                    'meta_value' => (string) $stars, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                ]);
            }
        }

        // Brand and key attributes — from the product's own data
        // (brand taxonomy or a "brand" attribute), no external lookups.
        $brand = '';

        foreach (['product_brand', 'pa_brand', 'pa_برند'] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_the_terms($product->get_id(), $taxonomy);

                if (is_array($terms) && [] !== $terms) {
                    $brand = $terms[0]->name;
                    break;
                }
            }
        }

        $attribute_chips = [];

        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_visible()) {
                continue;
            }

            $label  = wc_attribute_label($attribute->get_name());
            $values = $attribute->is_taxonomy()
                ? wp_list_pluck((array) get_the_terms($product->get_id(), $attribute->get_name()), 'name')
                : $attribute->get_options();

            if ([] !== $values) {
                $attribute_chips[] = $label . ': ' . implode('، ', array_slice(array_map('strval', $values), 0, 3));
            }

            if (count($attribute_chips) >= 3) {
                break;
            }
        }

        // Price position inside the product's own category (cached 12h).
        $position  = '';
        $cat_terms = get_the_terms($product->get_id(), 'product_cat');

        if (is_array($cat_terms) && [] !== $cat_terms && (float) $product->get_price() > 0) {
            $term      = $cat_terms[0];
            $cache_key = 'vkl_cat_avg_' . (int) $term->term_id;
            $average_p = get_transient($cache_key);

            if (false === $average_p) {
                $prices = [];

                foreach (wc_get_products(['status' => 'publish', 'limit' => 50, 'category' => [$term->slug]]) as $sibling) {
                    if ($sibling instanceof WC_Product && (float) $sibling->get_price() > 0) {
                        $prices[] = (float) $sibling->get_price();
                    }
                }

                $average_p = [] !== $prices ? array_sum($prices) / count($prices) : 0.0;
                set_transient($cache_key, $average_p, 12 * HOUR_IN_SECONDS);
            }

            $average_p = (float) $average_p;

            if ($average_p > 0) {
                $ratio = (float) $product->get_price() / $average_p;

                if ($ratio <= 0.85) {
                    $position = sprintf(
                        /* translators: %s: category name. */
                        __('Priced below the average of the %s category — good value.', 'vereskala-login'),
                        $term->name
                    );
                } elseif ($ratio >= 1.15) {
                    $position = sprintf(
                        /* translators: %s: category name. */
                        __('A higher-end pick within the %s category.', 'vereskala-login'),
                        $term->name
                    );
                } else {
                    $position = sprintf(
                        /* translators: %s: category name. */
                        __('Priced in line with the %s category average.', 'vereskala-login'),
                        $term->name
                    );
                }
            }
        }

        // Targeted suggestions: what shoppers weighing this product also check.
        $related = array_filter(array_map('wc_get_product', wc_get_related_products($product->get_id(), 3)));

        // Guidance line derived from views + local ratings.
        if ($count >= 5 && $average >= 4.5) {
            $guidance = __('Customers are very satisfied with this product.', 'vereskala-login');
        } elseif ($count >= 5 && $average >= 3.8) {
            $guidance = __('Most customers rate this product positively.', 'vereskala-login');
        } elseif ($today >= 30) {
            $guidance = __('One of the most viewed products today — it may sell out.', 'vereskala-login');
        } elseif ($week >= 100) {
            $guidance = __('A popular pick this week.', 'vereskala-login');
        } else {
            $guidance = '';
        }

        /**
         * Filters extra insight lines (e.g. from an external review feed).
         *
         * @param string[]   $lines   Extra lines, already escaped by the caller.
         * @param WC_Product $product Product.
         */
        $extra = (array) apply_filters('vkl_insights_extra_lines', [], $product);
        ?>
        <div class="vkl-insights" style="margin:14px 0;padding:12px 16px;border:1px solid #e2e2e2;border-radius:8px;font-size:.92em;line-height:1.9">
            <?php if ($today > 0 || $week > 0) : ?>
                <div>
                    <?php
                    printf(
                        /* translators: 1: views today, 2: views in the last 7 days. */
                        esc_html__('%1$s views today · %2$s views in the last 7 days', 'vereskala-login'),
                        esc_html(number_format_i18n($today)),
                        esc_html(number_format_i18n($week))
                    );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($count > 0) : ?>
                <div>
                    <?php
                    printf(
                        /* translators: 1: average rating, 2: number of reviews. */
                        esc_html__('Customer reviews: %1$s out of 5 (%2$s reviews)', 'vereskala-login'),
                        esc_html(number_format_i18n($average, 1)),
                        esc_html(number_format_i18n($count))
                    );
                    ?>
                </div>
                <div style="max-width:260px">
                    <?php foreach ($distribution as $stars => $n) : ?>
                        <?php $pct = $count > 0 ? (int) round($n * 100 / $count) : 0; ?>
                        <div style="display:flex;align-items:center;gap:6px">
                            <span style="width:2.2em"><?php echo esc_html((string) $stars); ?>★</span>
                            <span style="flex:1;background:#eee;border-radius:3px;height:7px;overflow:hidden">
                                <span style="display:block;height:100%;width:<?php echo esc_attr((string) $pct); ?>%;background:#f5a623"></span>
                            </span>
                            <span style="width:2.5em;text-align:left"><?php echo esc_html((string) $n); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ('' !== $brand || [] !== $attribute_chips) : ?>
                <div>
                    <?php
                    $pieces = [];

                    if ('' !== $brand) {
                        $pieces[] = sprintf(
                            /* translators: %s: brand name. */
                            esc_html__('Brand: %s', 'vereskala-login'),
                            esc_html($brand)
                        );
                    }

                    foreach ($attribute_chips as $chip) {
                        $pieces[] = esc_html($chip);
                    }

                    echo implode(' · ', $pieces); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    ?>
                </div>
            <?php endif; ?>

            <?php if ('' !== $position) : ?>
                <div><?php echo esc_html($position); ?></div>
            <?php endif; ?>

            <?php
            // Purchase motivation: live demand (this item sitting in other
            // shoppers' carts right now) or scarcity (low stock) — honest
            // urgency only, never invented numbers.
            global $wpdb;

            $vkl_in_carts = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore
                "SELECT COUNT(*) FROM {$wpdb->prefix}vkl_cart
                 WHERE status IN ('active', 'abandoned')
                   AND (items LIKE %s OR items LIKE %s)",
                '%"product_id":' . $product->get_id() . ',%',
                '%"product_id":' . $product->get_id() . '}%'
            ));

            $vkl_stock   = $product->managing_stock() ? (int) $product->get_stock_quantity() : 0;
            $vkl_urgency = '';

            if ($vkl_in_carts > 0) {
                $vkl_urgency = sprintf(
                    /* translators: %s: number of carts. */
                    __('🛒 Right now this item is in the carts of %s other shoppers — order before it sells out.', 'vereskala-login'),
                    number_format_i18n($vkl_in_carts)
                );
            } elseif ($vkl_stock > 0 && $vkl_stock <= 5) {
                $vkl_urgency = sprintf(
                    /* translators: %s: stock quantity. */
                    __('⏳ Only %s left in stock.', 'vereskala-login'),
                    number_format_i18n($vkl_stock)
                );
            }
            ?>
            <?php if ('' !== $vkl_urgency) : ?>
                <div style="color:#9a6a10;font-weight:600"><?php echo esc_html($vkl_urgency); ?></div>
            <?php endif; ?>

            <?php if ([] !== $related) : ?>
                <div>
                    <?php esc_html_e('Shoppers weighing this item also check:', 'vereskala-login'); ?>
                    <?php
                    $links = [];

                    foreach ($related as $suggestion) {
                        $links[] = '<a href="' . esc_url((string) get_permalink($suggestion->get_id())) . '">' . esc_html($suggestion->get_name()) . '</a>';
                    }

                    echo wp_kses_post(implode('، ', $links));
                    ?>
                </div>
            <?php endif; ?>

            <?php foreach ($extra as $line) : ?>
                <div><?php echo wp_kses_post((string) $line); ?></div>
            <?php endforeach; ?>

            <?php if ('' !== $guidance) : ?>
                <div><strong><?php echo esc_html($guidance); ?></strong></div>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            // "Being written" reveal: when the box scrolls into view, plain
            // text lines are typed out with a caret; lines with charts or
            // links fade in. Without JS/IntersectionObserver everything
            // simply stays visible.
            // Optimizer-proof targeting: script movers/deferrers (cache
            // plugins) break currentScript adjacency, which froze the
            // typing effect — select the box by class instead, once.
            function boot() {
                var box = document.querySelector('.vkl-insights:not([data-vkl-revealed])');
                if (!box) { return; }
                box.setAttribute('data-vkl-revealed', '1');
                reveal(box);
            }
            if ('loading' === document.readyState) {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }

            function reveal(box) {
            if (!('IntersectionObserver' in window)) { return; }

            var lines = Array.prototype.slice.call(box.children);
            lines.forEach(function (line) { line.style.visibility = 'hidden'; });

            var caret = document.createElement('span');
            caret.textContent = '▎';
            caret.style.cssText = 'color:#157f3a;animation:vklblink .8s step-end infinite';

            var style = document.createElement('style');
            style.textContent = '@keyframes vklblink{50%{opacity:0}}';
            document.head.appendChild(style);

            function typeLine(line, done) {
                line.style.visibility = 'visible';
                var hasElements = line.children.length > 0;
                if (hasElements) {
                    line.style.opacity = '0';
                    line.style.transition = 'opacity .5s';
                    requestAnimationFrame(function () { line.style.opacity = '1'; });
                    setTimeout(done, 350);
                    return;
                }
                var full = line.textContent;
                line.textContent = '';
                line.appendChild(caret);
                var i = 0;
                var timer = setInterval(function () {
                    i += 2;
                    line.insertBefore(document.createTextNode(full.slice(i - 2, i)), caret);
                    if (i >= full.length) {
                        clearInterval(timer);
                        caret.remove();
                        done();
                    }
                }, 24);
            }

            var observer = new IntersectionObserver(function (entries) {
                if (!entries[0].isIntersecting) { return; }
                observer.disconnect();
                var idx = 0;
                (function next() {
                    if (idx >= lines.length) { return; }
                    typeLine(lines[idx++], next);
                })();
            }, { threshold: 0.4 });

            observer.observe(box);
            }
        })();
        </script>
        <?php
    }
}
