<?php
/**
 * Views (بازدیدها) dashboard.
 *
 * A Google-Analytics-style read of the tracker data: range chips,
 * headline cards (views / sessions / visitors / time on site), a daily
 * trend bar chart drawn with pure CSS (no external libraries), most
 * viewed products, on-site search terms, countries & cities, referrer
 * sources and the device split — every number scoped to the chosen
 * range.
 *
 * @package Vereskonto\Modules\Analytics
 * @since   1.33.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Analytics_Admin
 */
final class VKL_Analytics_Admin
{
    private const SETTINGS_GROUP = 'vkl_analytics_settings';

    private VKL_Config $config;

    private VKL_Security $security;

    private VKL_Analytics_Repository $repository;

    /**
     * Constructor.
     */
    public function __construct(VKL_Config $config, VKL_Security $security, VKL_Analytics_Repository $repository)
    {
        $this->config     = $config;
        $this->security   = $security;
        $this->repository = $repository;
    }

    /**
     * Hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 65);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Submenu.
     */
    public function menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Views', 'vereskala-login'),
            __('Views', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-analytics',
            [$this, 'render']
        );
    }

    /**
     * Settings.
     */
    public function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('analytics_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('analytics_dwell'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('analytics_retention_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);
    }

    /**
     * mm:ss for durations.
     */
    /**
     * Localised device-type label.
     */
    /**
     * Recommendations derived from the visitor-type mix. Each note fires
     * only when its threshold is met, so advice matches the real traffic.
     *
     * @param array<string, array{views:int,sessions:int}> $v     Breakdown.
     * @param int                                           $total Total views.
     *
     * @return array<int, array{icon:string,title:string,body:string,action:string}>
     */
    /**
     * Turn the whole picture — who visited, what they searched for, where
     * they came from, what they used — into concrete recommendations.
     *
     * @param array<string, array{views:int,sessions:int}> $v         Visitor split.
     * @param int                                           $total     Total views.
     * @param array<int, array<string, mixed>>              $searches  Top search terms.
     * @param array<int, array<string, mixed>>              $locations Top locations.
     * @param array<int, array<string, mixed>>              $referrers Top referrers.
     * @param array<int, array<string, mixed>>              $devices   Device split.
     * @param array<string, mixed>                          $summary   Headline figures.
     *
     * @return array<int, array{icon:string,title:string,body:string,action:string}>
     */
    private function visitor_insights(
        array $v,
        int $total,
        array $searches = [],
        array $locations = [],
        array $referrers = [],
        array $devices = [],
        array $summary = []
    ): array {
        $out = [];

        if ($total < 1) {
            return $out;
        }

        $human   = (int) ($v['human']['views'] ?? 0);
        $search  = (int) ($v['search_engine']['views'] ?? 0);
        $ai      = (int) ($v['ai_bot']['views'] ?? 0);
        $scraper = (int) ($v['scraper']['views'] ?? 0);

        $pct = static fn(int $n): int => $total > 0 ? (int) round($n / $total * 100) : 0;

        $human_pct   = $pct($human);
        $bot_pct     = $pct($search + $ai + $scraper);
        $ai_pct      = $pct($ai);
        $scraper_pct = $pct($scraper);

        // Say so plainly when there is not much to go on, rather than
        // showing nothing at all.
        if ($total < 20) {
            $out[] = [
                'icon'   => 'ℹ️',
                'title'  => __('Not much traffic in this range yet', 'vereskala-login'),
                'body'   => sprintf(
                    /* translators: %s: number of views. */
                    __('Only %s views were recorded, so treat the shares below as a rough hint rather than a trend.', 'vereskala-login'),
                    number_format_i18n($total)
                ),
                'action' => __('Pick a wider date range, or come back once more visits have accumulated.', 'vereskala-login'),
            ];
        }

        // --- who is visiting -------------------------------------------
        if ($human_pct >= 70) {
            $out[] = [
                'icon'   => '💚',
                'title'  => __('Mostly real people', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: percent. */ __('About %s%% of visits are humans — your traffic quality is good.', 'vereskala-login'), number_format_i18n($human_pct)),
                'action' => __('Focus on conversion: clear prices, fast checkout, and the smart-bar offer.', 'vereskala-login'),
            ];
        } elseif ($bot_pct >= 50) {
            $out[] = [
                'icon'   => '⚠️',
                'title'  => __('Bots outnumber people', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: percent. */ __('Around %s%% of visits are automated. Real demand may be smaller than the raw totals suggest.', 'vereskala-login'), number_format_i18n($bot_pct)),
                'action' => __('Judge sales by the human share, and consider a caching/CDN rule or rate-limit for aggressive bots.', 'vereskala-login'),
            ];
        }

        if ($search > 0) {
            $out[] = [
                'icon'   => '🔎',
                'title'  => __('Search engines are indexing you', 'vereskala-login'),
                'body'   => __('Google/Bing-type crawlers are visiting — that is how you get found in search.', 'vereskala-login'),
                'action' => __('Keep product titles and descriptions unique and complete so those crawls turn into search traffic.', 'vereskala-login'),
            ];
        } else {
            $out[] = [
                'icon'   => '🔻',
                'title'  => __('No search-engine crawls seen', 'vereskala-login'),
                'body'   => __('No Google/Bing-type indexer appeared in this range. New or low-visibility sites often see few.', 'vereskala-login'),
                'action' => __('Submit a sitemap in Google Search Console and check the site is not set to discourage indexing.', 'vereskala-login'),
            ];
        }

        if ($ai_pct >= 3) {
            $out[] = [
                'icon'   => '🤖',
                'title'  => __('AI assistants are reading your catalog', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: percent. */ __('About %s%% of visits are AI crawlers (ChatGPT, Claude, Perplexity…). They can surface your products in AI answers.', 'vereskala-login'), number_format_i18n($ai_pct)),
                'action' => __('Make sure product pages have clear text (name, price, key specs) so AI tools describe them accurately. Block them in robots.txt only if you do not want that exposure.', 'vereskala-login'),
            ];
        }

        // --- scrapers, in more depth -----------------------------------
        if ($scraper > 0) {
            if ($scraper_pct >= 20) {
                $out[] = [
                    'icon'   => '🕷',
                    'title'  => __('Heavy scraper activity', 'vereskala-login'),
                    'body'   => sprintf(
                        /* translators: 1: percent, 2: view count. */
                        __('Around %1$s%% of visits (%2$s views) look like generic scrapers — usually competitors watching prices or copying descriptions.', 'vereskala-login'),
                        number_format_i18n($scraper_pct),
                        number_format_i18n($scraper)
                    ),
                    'action' => __('Add a firewall or rate-limit rule, or turn on bot protection at your CDN. It lowers server load, and on a busy shop that also means fewer 503 errors.', 'vereskala-login'),
                ];
            } else {
                $out[] = [
                    'icon'   => '🕸',
                    'title'  => __('Some scraper traffic', 'vereskala-login'),
                    'body'   => sprintf(
                        /* translators: %s: view count. */
                        __('%s views came from generic automation. At this level it is normal background noise and costs you little.', 'vereskala-login'),
                        number_format_i18n($scraper)
                    ),
                    'action' => __('Nothing to do now — worth revisiting if the share climbs above a fifth of your traffic.', 'vereskala-login'),
                ];
            }
        }

        // --- what people are looking for -------------------------------
        if ([] !== $searches) {
            $top = $searches[0];
            $term = (string) ($top['search_term'] ?? '');
            $hits = (int) ($top['searches'] ?? 0);

            if ('' !== $term) {
                $out[] = [
                    'icon'   => '🔤',
                    'title'  => __('What people search for on your site', 'vereskala-login'),
                    'body'   => sprintf(
                        /* translators: 1: search term, 2: number of searches, 3: number of distinct terms. */
                        __('The most-used search was “%1$s” (%2$s times), across %3$s different terms in this range.', 'vereskala-login'),
                        $term,
                        number_format_i18n($hits),
                        number_format_i18n(count($searches))
                    ),
                    'action' => __('Check that searching those words actually finds the right products. A popular search that returns nothing is a sale walking away — either stock it, or add the word to an existing product.', 'vereskala-login'),
                ];
            }
        } elseif ($human >= 20) {
            $out[] = [
                'icon'   => '🔤',
                'title'  => __('Nobody is using the site search', 'vereskala-login'),
                'body'   => __('Real visitors came but none searched, which usually means the search box is hard to find.', 'vereskala-login'),
                'action' => __('Put the search box somewhere obvious in the header — search users buy far more often than browsers.', 'vereskala-login'),
            ];
        }

        // --- where they come from --------------------------------------
        if ([] !== $referrers) {
            $rtop = (string) ($referrers[0]['referrer'] ?? '');

            if ('' !== $rtop) {
                $out[] = [
                    'icon'   => '↗️',
                    'title'  => __('Where visitors arrive from', 'vereskala-login'),
                    'body'   => sprintf(/* translators: %s: hostname. */ __('The biggest single source of visits is %s.', 'vereskala-login'), $rtop),
                    'action' => __('Whatever is working there deserves more of your effort — and it is worth checking the link still points at the right page.', 'vereskala-login'),
                ];
            }
        }

        // --- devices ---------------------------------------------------
        if ([] !== $devices) {
            $mobile = 0;
            $desk   = 0;

            foreach ($devices as $row) {
                $name = (string) ($row['device'] ?? '');
                $cnt  = (int) ($row['sessions'] ?? 0);

                if ('mobile' === $name) {
                    $mobile += $cnt;
                } else {
                    $desk += $cnt;
                }
            }

            $both = $mobile + $desk;

            if ($both > 0 && $mobile / $both >= 0.6) {
                $out[] = [
                    'icon'   => '📱',
                    'title'  => __('Your shop is mostly used on phones', 'vereskala-login'),
                    'body'   => sprintf(
                        /* translators: %s: percent. */
                        __('About %s%% of visits come from a phone.', 'vereskala-login'),
                        number_format_i18n((int) round($mobile / $both * 100))
                    ),
                    'action' => __('Test the checkout on a real phone now and then: tap targets, the address form, and how long the page takes on mobile data.', 'vereskala-login'),
                ];
            }
        }

        // --- where in the country --------------------------------------
        if (count($locations) > 1) {
            $ltop = (string) ($locations[0]['country'] ?? '');

            if ('' !== $ltop) {
                $out[] = [
                    'icon'   => '📍',
                    'title'  => __('Where your visitors are', 'vereskala-login'),
                    'body'   => sprintf(/* translators: 1: place, 2: count of places. */ __('Most visits come from %1$s, with %2$s places seen in total.', 'vereskala-login'), $ltop, number_format_i18n(count($locations))),
                    'action' => __('Match shipping options and delivery estimates to where your customers actually are.', 'vereskala-login'),
                ];
            }
        }

        // --- engagement -------------------------------------------------
        $avg = (int) ($summary['avg_duration'] ?? 0);

        if ($avg > 0 && $human >= 20) {
            if ($avg < 30) {
                $out[] = [
                    'icon'   => '⏱',
                    'title'  => __('Visits are very short', 'vereskala-login'),
                    'body'   => sprintf(/* translators: %s: seconds. */ __('An average visit lasts about %s seconds, so most people leave before reading anything.', 'vereskala-login'), number_format_i18n($avg)),
                    'action' => __('Look at page speed first, then at whether the landing page answers what the visitor came for.', 'vereskala-login'),
                ];
            } else {
                $out[] = [
                    'icon'   => '⏱',
                    'title'  => __('Visitors are staying to read', 'vereskala-login'),
                    'body'   => sprintf(/* translators: %s: seconds. */ __('An average visit lasts about %s seconds.', 'vereskala-login'), number_format_i18n($avg)),
                    'action' => __('Give those readers an obvious next step — a clear add-to-cart, or the wallet and instalment options on the product page.', 'vereskala-login'),
                ];
            }
        }

        return $out;
    }

    private function device_type_label(string $type): string
    {
        switch ($type) {
            case 'phone':
                return __('Phone', 'vereskala-login');
            case 'tablet':
                return __('Tablet', 'vereskala-login');
            case 'desktop':
                return __('Desktop', 'vereskala-login');
            default:
                return $type;
        }
    }

    private function fmt_duration(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Turn the raw metrics into plain-language expert notes with concrete
     * next steps. Pure rules, no external service — each note only fires
     * when its own data threshold is met, so nothing is invented.
     *
     * @param array<string, int>               $summary   Headline totals.
     * @param array<int, array<string, mixed>> $daily     Daily series.
     * @param array<int, array<string, mixed>> $products  Top products.
     * @param array<int, array<string, mixed>> $searches  Search terms.
     * @param array<string, int>               $devices   Device split.
     * @param array<int, array<string, mixed>> $locations Locations.
     * @param array<int, array<string, mixed>> $referrers Referrers.
     *
     * @return array<int, array{icon: string, title: string, body: string, action: string}>
     */
    private function build_insights(array $summary, array $daily, array $products, array $searches, array $devices, array $locations, array $referrers): array
    {
        $out = [];

        if ((int) $summary['views'] < 20) {
            return $out;
        }

        // 1) Trend: compare the first and second half of the range.
        if (count($daily) >= 4) {
            $half  = (int) floor(count($daily) / 2);
            $first = array_sum(array_map(static fn($d): int => (int) $d['views'], array_slice($daily, 0, $half)));
            $last  = array_sum(array_map(static fn($d): int => (int) $d['views'], array_slice($daily, -$half)));

            if ($first > 0) {
                $change = (int) round(($last - $first) / $first * 100);

                if ($change >= 15) {
                    $out[] = [
                        'icon'   => '📈',
                        'title'  => __('Traffic is trending up', 'vereskala-login'),
                        'body'   => sprintf(/* translators: %s: percent. */ __('Visits in the second half of this range are about %s%% higher than the first half.', 'vereskala-login'), number_format_i18n($change)),
                        'action' => __('Whatever you changed recently is working — keep that channel warm and consider increasing its budget.', 'vereskala-login'),
                    ];
                } elseif ($change <= -15) {
                    $out[] = [
                        'icon'   => '📉',
                        'title'  => __('Traffic is slowing down', 'vereskala-login'),
                        'body'   => sprintf(/* translators: %s: percent. */ __('Visits dropped about %s%% between the first and second half of this range.', 'vereskala-login'), number_format_i18n(abs($change))),
                        'action' => __('Refresh the homepage offer or send a campaign — a smart-bar promotion or an SMS to past buyers usually lifts it quickly.', 'vereskala-login'),
                    ];
                }
            }
        }

        // 2) Engagement: average time on site.
        $avg = (int) $summary['avg_duration'];

        if ($avg > 0 && $avg < 40) {
            $out[] = [
                'icon'   => '⏱',
                'title'  => __('Visitors leave quickly', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: mm:ss. */ __('Average time on site is only %s. Short visits often mean slow pages or a weak first impression.', 'vereskala-login'), $this->fmt_duration($avg)),
                'action' => __('Check homepage load speed and make the hero banner and best offers visible without scrolling.', 'vereskala-login'),
            ];
        } elseif ($avg >= 120) {
            $out[] = [
                'icon'   => '💚',
                'title'  => __('Strong engagement', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: mm:ss. */ __('Visitors spend %s on average — they are genuinely browsing.', 'vereskala-login'), $this->fmt_duration($avg)),
                'action' => __('This is a good moment to surface upsells and bundles; the audience is receptive.', 'vereskala-login'),
            ];
        }

        // 3) Search terms with no matching product = demand gaps.
        $gaps = [];

        foreach (array_slice($searches, 0, 8) as $search) {
            $term = (string) $search['search_term'];

            if (function_exists('wc_get_products')) {
                $hits = wc_get_products(['status' => 'publish', 'limit' => 1, 's' => $term, 'return' => 'ids']);

                if ([] === $hits) {
                    $gaps[] = $term;
                }
            }
        }

        if ([] !== $gaps) {
            $out[] = [
                'icon'   => '🔎',
                'title'  => __('Customers search for things you may not sell', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: terms. */ __('These searched terms returned no product: %s', 'vereskala-login'), implode('، ', array_slice($gaps, 0, 6))),
                'action' => __('Each is a ready-made demand signal — stock the closest item or publish a matching product/landing page.', 'vereskala-login'),
            ];
        }

        // 4) Mobile-heavy audience.
        $device_total = max(1, ($devices['mobile'] ?? 0) + ($devices['desktop'] ?? 0));
        $mobile_pct   = (int) round(($devices['mobile'] ?? 0) / $device_total * 100);

        if ($mobile_pct >= 70) {
            $out[] = [
                'icon'   => '📱',
                'title'  => __('Your audience is mostly on mobile', 'vereskala-login'),
                'body'   => sprintf(/* translators: %s: percent. */ __('About %s%% of sessions are on phones.', 'vereskala-login'), number_format_i18n($mobile_pct)),
                'action' => __('Prioritise mobile: big tap targets, a short checkout, and images that load fast on mobile data.', 'vereskala-login'),
            ];
        }

        // 5) Hero product — always explain the top item when there is data.
        if ([] !== $products) {
            $top         = $products[0];
            $top_views   = (int) $top['views'];
            $top_sess    = (int) ($top['sessions'] ?? 0);
            $total_views = array_sum(array_map(static fn($p): int => (int) $p['views'], $products));
            $title       = get_the_title((int) $top['product_id']);
            $title       = '' !== $title ? $title : ('#' . (int) $top['product_id']);
            $share       = $total_views > 0 ? (int) round($top_views / $total_views * 100) : 0;

            // Return-visit signal: many views per session = strong interest.
            $per_session = $top_sess > 0 ? round($top_views / $top_sess, 1) : 0;

            $body = sprintf(
                /* translators: 1: product, 2: views, 3: percent of all product views. */
                __('“%1$s” is your most-viewed product: %2$s views, about %3$s%% of all product views in this range.', 'vereskala-login'),
                $title,
                number_format_i18n($top_views),
                number_format_i18n($share)
            );

            if ($per_session >= 1.5) {
                $body .= ' ' . sprintf(
                    /* translators: %s: number. */
                    __('Visitors look at it %s times per visit on average — a strong sign of buying interest.', 'vereskala-login'),
                    number_format_i18n($per_session)
                );
            }

            // Action depends on how dominant it is.
            if ($share >= 40) {
                $action = __('It carries much of your demand, so keep it always in stock and cross-sell related items from its page so one stock-out cannot hurt overall sales.', 'vereskala-login');
            } else {
                $action = __('Feature it on the homepage and in a campaign, and place related items on its page to turn that attention into larger baskets.', 'vereskala-login');
            }

            $out[] = [
                'icon'   => '⭐',
                'title'  => __('Your top product right now', 'vereskala-login'),
                'body'   => $body,
                'action' => $action,
            ];
        }

        // 6) Traffic dependence on a single referrer.
        if (count($referrers) >= 1) {
            $ref_total = array_sum(array_map(static fn($r): int => (int) $r['sessions'], $referrers));
            $ref_top   = (int) $referrers[0]['sessions'];

            if ($ref_total > 0 && $ref_top / $ref_total >= 0.6) {
                $out[] = [
                    'icon'   => '🔗',
                    'title'  => __('Traffic leans on one source', 'vereskala-login'),
                    'body'   => sprintf(/* translators: %s: host. */ __('Most external visits come from %s. That is great while it lasts, but it is a single point of failure.', 'vereskala-login'), (string) $referrers[0]['referrer']),
                    'action' => __('Build a second channel (SMS to past buyers, or another social profile) so a change on one platform cannot halve your traffic.', 'vereskala-login'),
                ];
            }
        }

        return $out;
    }

    /**
     * Render the dashboard.
     */
    public function render(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $range  = isset($_GET['vkl_range']) ? sanitize_key($_GET['vkl_range']) : 'week'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $ranges = [
            'day'   => [__('Today', 'vereskala-login'), '-1 day'],
            'week'  => [__('Last 7 days', 'vereskala-login'), '-7 days'],
            'month' => [__('Last 30 days', 'vereskala-login'), '-30 days'],
            'quarter' => [__('Last 90 days', 'vereskala-login'), '-90 days'],
        ];

        if (!isset($ranges[$range])) {
            $range = 'week';
        }

        // Query window in GMT (created_at is stored in GMT).
        $to   = gmdate('Y-m-d H:i:s');
        $from = gmdate('Y-m-d H:i:s', (int) strtotime($ranges[$range][1]));

        // Headline figures used to include crawler traffic, which made the
        // numbers a shop owner decides on look better than reality. The
        // switch is on by default: people first, bots on request.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $only_humans = !isset($_GET['vkl_who_all']) || '1' !== $_GET['vkl_who_all'];
        $this->repository->set_humans_only($only_humans);

        $summary   = $this->repository->summary($from, $to);
        $daily     = $this->repository->daily($from, $to);
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
        $product_q = isset($_GET['vkl_product_q']) ? sanitize_text_field(wp_unslash($_GET['vkl_product_q'])) : '';
        $p_page    = isset($_GET['vkl_ppage']) ? max(1, absint($_GET['vkl_ppage'])) : 1;
        // phpcs:enable
        $p_per     = 10;
        $p_total   = $this->repository->top_products_count($from, $to, $product_q);
        $p_pages   = max(1, (int) ceil($p_total / $p_per));
        $p_page    = min($p_page, $p_pages);
        $p_sort    = isset($_GET['vkl_psort']) ? sanitize_key($_GET['vkl_psort']) : 'views'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!in_array($p_sort, ['views', 'sessions', 'known_users', 'duration'], true)) {
            $p_sort = 'views';
        }
        $products  = $this->repository->top_products($from, $to, $p_per, ($p_page - 1) * $p_per, $product_q, $p_sort);
        $visitors  = $this->repository->visitor_breakdown($from, $to);
        $user_vis  = $this->repository->user_visits($from, $to);
        $searches  = $this->repository->top_searches($from, $to);
        $locations = $this->repository->top_locations($from, $to);
        $referrers = $this->repository->top_referrers($from, $to);
        $devices   = $this->repository->devices($from, $to);
        $types     = $this->repository->page_types($from, $to);

        $enabled = $this->config->get_bool('analytics_enabled', true);
        ?>
        <div class="wrap">
            <?php
            $vkl_base_args = ['page' => 'vkl-analytics', 'vkl_range' => $range];
            ?>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #dceee2;border-radius:12px;padding:9px 14px;margin:12px 0;max-width:1140px">
                <strong><?php esc_html_e('Counting:', 'vereskala-login'); ?></strong>
                <a class="button <?php echo $only_humans ? 'button-primary' : ''; ?>"
                   href="<?php echo esc_url(add_query_arg($vkl_base_args, admin_url('admin.php'))); ?>">
                    👤 <?php esc_html_e('People only', 'vereskala-login'); ?>
                </a>
                <a class="button <?php echo $only_humans ? '' : 'button-primary'; ?>"
                   href="<?php echo esc_url(add_query_arg($vkl_base_args + ['vkl_who_all' => '1'], admin_url('admin.php'))); ?>">
                    🌐 <?php esc_html_e('Everything, bots included', 'vereskala-login'); ?>
                </a>
                <span class="description">
                    <?php echo $only_humans
                        ? esc_html__('Every figure on this page counts real visitors only — bots and crawlers are left out.', 'vereskala-login')
                        : esc_html__('Figures include crawler and scraper traffic, so they run higher than real demand.', 'vereskala-login'); ?>
                </span>
            </div>
            <h1>📊 <?php esc_html_e('Views', 'vereskala-login'); ?></h1>

            <?php if (!$enabled) : ?>
                <div class="notice notice-warning"><p><?php esc_html_e('Tracking is currently OFF — the dashboard shows past data only. Enable it below to collect new visits.', 'vereskala-login'); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:6px;margin:6px 0 16px;flex-wrap:wrap">
                <?php foreach ($ranges as $key => $def) : ?>
                    <a class="button <?php echo $key === $range ? 'button-primary' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg(['page' => 'vkl-analytics', 'vkl_range' => $key], admin_url('admin.php'))); ?>"><?php echo esc_html($def[0]); ?></a>
                <?php endforeach; ?>
            </div>

            <?php
            $cards = [
                ['👁', __('Pageviews', 'vereskala-login'), number_format_i18n($summary['views'])],
                ['🧭', __('Sessions', 'vereskala-login'), number_format_i18n($summary['sessions'])],
                ['👥', __('Visitors', 'vereskala-login'), number_format_i18n($summary['visitors'])],
                ['⏱', __('Avg. time on site', 'vereskala-login'), $this->fmt_duration($summary['avg_duration'])],
                ['🛍', __('Product views', 'vereskala-login'), number_format_i18n($summary['product_views'])],
                ['🔎', __('Searches', 'vereskala-login'), number_format_i18n($summary['searches'])],
            ];
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:1140px;margin-bottom:18px">
                <?php foreach ($cards as $card) : ?>
                    <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px">
                        <div style="font-size:12px;color:#666"><?php echo esc_html($card[0] . ' ' . $card[1]); ?></div>
                        <div style="font-size:24px;font-weight:800;color:#157f3a;margin-top:2px" dir="ltr"><?php echo esc_html((string) $card[2]); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2 class="title">📈 <?php esc_html_e('Daily trend', 'vereskala-login'); ?></h2>
            <?php if ([] === $daily) : ?>
                <p class="description"><?php esc_html_e('No visits recorded in this range yet. Data appears as soon as real visitors browse the site.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <?php $peak = max(1, max(array_map(static fn(array $d): int => (int) $d['views'], $daily))); ?>
                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:18px 16px 8px;max-width:1140px;margin-bottom:18px">
                    <div style="display:flex;align-items:flex-end;gap:4px;height:160px">
                        <?php foreach ($daily as $day) : ?>
                            <?php
                            $views    = (int) $day['views'];
                            $sessions = (int) $day['sessions'];
                            $height   = max(3, (int) round($views / $peak * 150));
                            ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0"
                                 title="<?php echo esc_attr(sprintf(/* translators: 1: date, 2: views, 3: sessions. */ __('%1$s — %2$s views, %3$s sessions', 'vereskala-login'), (string) $day['day'], number_format_i18n($views), number_format_i18n($sessions))); ?>">
                                <span style="font-size:10px;color:#157f3a;font-weight:700"><?php echo esc_html(number_format_i18n($views)); ?></span>
                                <div style="width:100%;max-width:34px;height:<?php echo (int) $height; ?>px;background:linear-gradient(180deg,#22a84d,#157f3a);border-radius:6px 6px 2px 2px"></div>
                                <span style="font-size:9.5px;color:#888;white-space:nowrap" dir="ltr"><?php echo esc_html(gmdate('m/d', (int) strtotime((string) $day['day']))); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

                        <?php
                        $vkl_vt_meta = [
                            'human'         => ['👤', __('People', 'vereskala-login'), '#157f3a'],
                            'search_engine' => ['🔎', __('Search engines', 'vereskala-login'), '#2b6cb0'],
                            'ai_bot'        => ['🤖', __('AI bots', 'vereskala-login'), '#8a4ab0'],
                            'scraper'       => ['🕷', __('Scrapers', 'vereskala-login'), '#b0562b'],
                        ];
                        $vkl_vt_total = 0;
                        foreach ($visitors as $vkl_vt_row) { $vkl_vt_total += (int) $vkl_vt_row['views']; }
                        ?>
                        <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;max-width:1140px;margin-bottom:16px">
                            <h2 class="title" style="margin-top:0">🧭 <?php esc_html_e('Who is visiting', 'vereskala-login'); ?></h2>
                            <?php if (0 === $vkl_vt_total) : ?>
                                <p class="description"><?php esc_html_e('No visits recorded in this range yet. Classification appears once traffic is tracked.', 'vereskala-login'); ?></p>
                            <?php else : ?>
                                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                                    <?php foreach ($vkl_vt_meta as $vkl_vt_key => $vkl_vt_m) : ?>
                                        <?php
                                        $vkl_vt_v   = (int) ($visitors[$vkl_vt_key]['views'] ?? 0);
                                        $vkl_vt_pct = $vkl_vt_total > 0 ? round($vkl_vt_v / $vkl_vt_total * 100) : 0;
                                        ?>
                                        <div style="flex:1;min-width:150px;border:1px solid #eef2f0;border-radius:12px;padding:10px 12px;border-top:3px solid <?php echo esc_attr($vkl_vt_m[2]); ?>">
                                            <div style="font-size:20px;line-height:1"><?php echo esc_html($vkl_vt_m[0]); ?></div>
                                            <div style="font-weight:700;margin-top:4px"><?php echo esc_html($vkl_vt_m[1]); ?></div>
                                            <div style="color:#556"><?php echo esc_html(number_format_i18n($vkl_vt_v)); ?> · <?php echo esc_html(number_format_i18n($vkl_vt_pct)); ?>٪</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php foreach ($this->visitor_insights($visitors, $vkl_vt_total, $searches, $locations, $referrers, $devices, $summary) as $vkl_vt_ins) : ?>
                                    <div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-top:1px solid #f3f7f5">
                                        <span style="font-size:17px"><?php echo esc_html($vkl_vt_ins['icon']); ?></span>
                                        <div>
                                            <strong><?php echo esc_html($vkl_vt_ins['title']); ?></strong>
                                            <div style="color:#455"><?php echo esc_html($vkl_vt_ins['body']); ?></div>
                                            <?php if ('' !== $vkl_vt_ins['action']) : ?>
                                                <div style="color:#157f3a;font-size:13px;margin-top:2px">✅ <?php echo esc_html($vkl_vt_ins['action']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;max-width:1140px;margin-bottom:16px">
                    <h2 class="title" style="margin-top:0">🛍 <?php esc_html_e('Most viewed products', 'vereskala-login'); ?></h2>

                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:6px;margin:0 0 10px">
                        <input type="hidden" name="page" value="vkl-analytics">
                        <input type="hidden" name="vkl_range" value="<?php echo esc_attr($range); ?>">
                        <input type="search" name="vkl_product_q" value="<?php echo esc_attr($product_q); ?>"
                               placeholder="<?php esc_attr_e('Search a product…', 'vereskala-login'); ?>" style="flex:1">
                        <button class="button"><?php esc_html_e('Filter', 'vereskala-login'); ?></button>
                        <?php if ('' !== $product_q) : ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'vkl-analytics', 'vkl_range' => $range], admin_url('admin.php'))); ?>">✕</a>
                        <?php endif; ?>
                    </form>

                    <?php if ([] === $products) : ?>
                        <p class="description"><?php echo '' !== $product_q ? esc_html__('No viewed product matches this search in the selected range.', 'vereskala-login') : esc_html__('Nothing here yet.', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <?php
                        $vkl_sort_link = function (string $key) use ($range, $product_q, $p_sort): string {
                            $args = array_filter([
                                'page'          => 'vkl-analytics',
                                'vkl_range'     => $range,
                                'vkl_product_q' => '' !== $product_q ? $product_q : null,
                                'vkl_psort'     => $key,
                            ]);

                            return esc_url(add_query_arg($args, admin_url('admin.php')));
                        };
                        $vkl_arrow = static fn(string $key): string => $key === $p_sort ? ' ↓' : '';
                        ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Product', 'vereskala-login'); ?></th>
                                <th><a href="<?php echo $vkl_sort_link('views'); // phpcs:ignore ?>"><?php esc_html_e('Views', 'vereskala-login'); ?><?php echo esc_html($vkl_arrow('views')); ?></a></th>
                                <th><a href="<?php echo $vkl_sort_link('sessions'); // phpcs:ignore ?>"><?php esc_html_e('Sessions', 'vereskala-login'); ?><?php echo esc_html($vkl_arrow('sessions')); ?></a></th>
                                <th><a href="<?php echo $vkl_sort_link('known_users'); // phpcs:ignore ?>" title="<?php esc_attr_e('Registered users among the viewers', 'vereskala-login'); ?>">👤<?php echo esc_html($vkl_arrow('known_users')); ?></a></th>
                                <th><a href="<?php echo $vkl_sort_link('duration'); // phpcs:ignore ?>">⏱<?php echo esc_html($vkl_arrow('duration')); ?></a></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($products as $product) : ?>
                                    <?php $title = get_the_title((int) $product['product_id']); ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url((string) get_permalink((int) $product['product_id'])); ?>" target="_blank" rel="noopener">
                                                <?php echo esc_html('' !== $title ? $title : ('#' . (int) $product['product_id'])); ?>
                                            </a>
                                            <?php $vtypes = $this->repository->product_visitor_types((int) $product['product_id'], $from, $to); ?>
                                            <?php if ([] !== $vtypes) : ?>
                                                <?php
                                                $vt_icon = ['human' => '👤', 'search_engine' => '🔎', 'ai_bot' => '🤖', 'scraper' => '🕷'];
                                                ?>
                                                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">
                                                    <?php foreach ($vtypes as $vt) : ?>
                                                        <?php $vk = (string) $vt['visitor_type']; ?>
                                                        <span style="background:#eef4ff;color:#334;border:1px solid #dbe6f7;border-radius:999px;padding:1px 9px;font-size:11px">
                                                            <?php echo esc_html(($vt_icon[$vk] ?? '•') . ' ' . number_format_i18n((int) $vt['views'])); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php $platforms = $this->repository->product_platforms((int) $product['product_id'], $from, $to); ?>
                                            <?php if ([] !== $platforms) : ?>
                                                <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">
                                                    <?php foreach (array_slice($platforms, 0, 5) as $pf) : ?>
                                                        <?php
                                                        $pf_type  = (string) $pf['device_type'];
                                                        $pf_icon  = 'phone' === $pf_type ? '📱' : ('tablet' === $pf_type ? '📟' : '🖥');
                                                        $pf_label = trim(((string) $pf['os']) . ('' !== (string) $pf['device_type'] ? ' · ' . $this->device_type_label($pf_type) : ''));
                                                        $pf_label = '' !== $pf_label ? $pf_label : __('Unknown', 'vereskala-login');
                                                        ?>
                                                        <span style="background:#f0f6f3;color:#334;border:1px solid #e2ece7;border-radius:999px;padding:1px 9px;font-size:11px">
                                                            <?php echo esc_html($pf_icon . ' ' . $pf_label); ?>
                                                            <strong style="color:#157f3a">×<?php echo esc_html(number_format_i18n((int) $pf['views'])); ?></strong>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ('' !== $product_q) : ?>
                                                <?php $viewers = $this->repository->product_viewers((int) $product['product_id'], $from, $to); ?>
                                                <?php if ([] !== $viewers) : ?>
                                                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px">
                                                        <?php foreach ($viewers as $viewer) : ?>
                                                            <?php $v_user = get_userdata((int) $viewer['user_id']); ?>
                                                            <span style="background:#eaf9ef;color:#157f3a;border-radius:999px;padding:1px 9px;font-size:11px">
                                                                👤 <?php echo esc_html($v_user ? $v_user->display_name : ('#' . (int) $viewer['user_id'])); ?>
                                                                ×<?php echo esc_html(number_format_i18n((int) $viewer['views'])); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(number_format_i18n((int) $product['views'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $product['sessions'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) ($product['known_users'] ?? 0))); ?></td>
                                        <td dir="ltr"><?php echo esc_html($this->fmt_duration((int) ($product['total_duration'] ?? 0))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($p_pages > 1) : ?>
                            <div style="display:flex;gap:5px;align-items:center;margin-top:10px;flex-wrap:wrap">
                                <?php
                                $p_base = array_filter([
                                    'page'          => 'vkl-analytics',
                                    'vkl_range'     => $range,
                                    'vkl_product_q' => '' !== $product_q ? $product_q : null,
                                    'vkl_psort'     => 'views' !== $p_sort ? $p_sort : null,
                                ]);

                                for ($pp = 1; $pp <= min($p_pages, 30); $pp++) :
                                    ?>
                                    <a class="button button-small <?php echo $pp === $p_page ? 'button-primary' : ''; ?>"
                                       href="<?php echo esc_url(add_query_arg($p_base + ['vkl_ppage' => $pp], admin_url('admin.php'))); ?>"><?php echo esc_html(number_format_i18n($pp)); ?></a>
                                <?php endfor; ?>
                                <span class="description"><?php printf(/* translators: %s: products count. */ esc_html__('%s products', 'vereskala-login'), esc_html(number_format_i18n($p_total))); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

            <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;max-width:1140px;margin-bottom:16px">
                    <h2 class="title" style="margin-top:0">👤 <?php esc_html_e('Known visitors (registered users)', 'vereskala-login'); ?></h2>
                    <?php if ([] === $user_vis) : ?>
                        <p class="description"><?php esc_html_e('No logged-in customers visited in this range.', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <?php foreach ($user_vis as $visitor) : ?>
                            <?php
                            $v_id     = (int) $visitor['user_id'];
                            $v_user   = get_userdata($v_id);
                            $v_mobile = (string) get_user_meta($v_id, 'billing_phone', true);
                            ?>
                            <details style="border:1px solid #dceee2;border-radius:10px;margin:0 0 8px;background:#f7fdf9">
                                <summary style="cursor:pointer;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:9px 12px;list-style:none">
                                    <strong style="color:#157f3a">👤 <?php echo esc_html($v_user ? $v_user->display_name : ('#' . $v_id)); ?></strong>
                                    <?php if ('' !== $v_mobile) : ?>
                                        <span class="description" dir="ltr" style="font-size:11px"><?php echo esc_html($v_mobile); ?></span>
                                    <?php endif; ?>
                                    <span style="margin-inline-start:auto;display:flex;gap:12px;font-size:12px;color:#555">
                                        <span>👁 <?php echo esc_html(number_format_i18n((int) $visitor['views'])); ?></span>
                                        <span>🛍 <?php echo esc_html(number_format_i18n((int) $visitor['product_views'])); ?></span>
                                        <span dir="ltr">⏱ <?php echo esc_html($this->fmt_duration((int) $visitor['total_duration'])); ?></span>
                                        <span dir="ltr" style="color:#999"><?php echo esc_html((string) $visitor['last_at']); ?></span>
                                    </span>
                                </summary>
                                <div style="padding:4px 12px 10px;border-top:1px solid #e6f4ea">
                                    <?php foreach ($this->repository->user_hits($v_id, $from, $to) as $hit) : ?>
                                        <div style="display:flex;align-items:center;gap:10px;padding:4px 0;font-size:12.5px;border-bottom:1px dashed #f0f0f0">
                                            <span dir="ltr" style="color:#999;min-width:118px;font-size:11px"><?php echo esc_html((string) $hit['created_at']); ?></span>
                                            <span style="flex:1;min-width:0">
                                                <?php
                                                $h_type = (string) $hit['page_type'];

                                                if ('product' === $h_type && (int) $hit['product_id'] > 0) {
                                                    $h_title = get_the_title((int) $hit['product_id']);
                                                    printf(
                                                        '🛍 <a href="%s" target="_blank" rel="noopener">%s</a>',
                                                        esc_url((string) get_permalink((int) $hit['product_id'])),
                                                        esc_html('' !== $h_title ? $h_title : ('#' . (int) $hit['product_id']))
                                                    );
                                                } elseif ('search' === $h_type) {
                                                    printf('🔎 %s', esc_html((string) $hit['search_term']));
                                                } else {
                                                    $h_labels = [
                                                        'home'     => __('Home', 'vereskala-login'),
                                                        'category' => __('Category pages', 'vereskala-login'),
                                                        'cart'     => __('Cart', 'vereskala-login'),
                                                        'checkout' => __('Checkout', 'vereskala-login'),
                                                        'other'    => __('Other pages', 'vereskala-login'),
                                                    ];
                                                    echo esc_html($h_labels[$h_type] ?? $h_type);
                                                }
                                                ?>
                                            </span>
                                            <?php if ((int) $hit['duration'] > 0) : ?>
                                                <span dir="ltr" style="color:#157f3a;font-size:11px">⏱ <?php echo esc_html($this->fmt_duration((int) $hit['duration'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        <p class="description" style="margin-top:8px"><?php esc_html_e('Click a customer to see exactly which pages and products they visited. Guests appear in the totals but stay anonymous.', 'vereskala-login'); ?></p>
                    <?php endif; ?>
                </div>

            <div style="columns:2 340px;column-gap:16px;max-width:1140px">
                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;break-inside:avoid;margin:0 0 16px">
                    <h2 class="title" style="margin-top:0">🔎 <?php esc_html_e('On-site search terms', 'vereskala-login'); ?></h2>
                    <?php if ([] === $searches) : ?>
                        <p class="description"><?php esc_html_e('Nothing here yet.', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ($searches as $search) : ?>
                                <span style="background:#eaf9ef;color:#157f3a;border-radius:999px;padding:3px 12px;font-size:12.5px">
                                    <?php echo esc_html((string) $search['search_term']); ?>
                                    <strong style="opacity:.7">×<?php echo esc_html(number_format_i18n((int) $search['searches'])); ?></strong>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <p class="description" style="margin-top:10px"><?php esc_html_e('What customers type into your own search box — pure demand signal: frequent terms with no matching product are your next best sellers.', 'vereskala-login'); ?></p>
                    <?php endif; ?>
                </div>

                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;break-inside:avoid;margin:0 0 16px">
                    <h2 class="title" style="margin-top:0">🌍 <?php esc_html_e('Countries & cities', 'vereskala-login'); ?></h2>
                    <?php if ([] === $locations) : ?>
                        <p class="description"><?php esc_html_e('No geo data yet. Country/city comes from CDN headers (e.g. Cloudflare) — without a CDN in front of the site this stays empty; no raw IP address is ever stored.', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Location', 'vereskala-login'); ?></th>
                                <th><?php esc_html_e('Sessions', 'vereskala-login'); ?></th>
                                <th><?php esc_html_e('Views', 'vereskala-login'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($locations as $location) : ?>
                                    <tr>
                                        <td dir="ltr">
                                            <?php
                                            $label = (string) $location['country'];

                                            if ('' !== (string) $location['city']) {
                                                $label .= ' · ' . (string) $location['city'];
                                            }

                                            echo esc_html($label);
                                            ?>
                                        </td>
                                        <td><?php echo esc_html(number_format_i18n((int) $location['sessions'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $location['views'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;break-inside:avoid;margin:0 0 16px">
                    <h2 class="title" style="margin-top:0">🔗 <?php esc_html_e('Traffic sources', 'vereskala-login'); ?></h2>
                    <?php if ([] === $referrers) : ?>
                        <p class="description"><?php esc_html_e('Only direct visits so far (no external referrers).', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <table class="widefat striped">
                            <thead><tr>
                                <th><?php esc_html_e('Referrer', 'vereskala-login'); ?></th>
                                <th><?php esc_html_e('Sessions', 'vereskala-login'); ?></th>
                                <th><?php esc_html_e('Views', 'vereskala-login'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($referrers as $referrer) : ?>
                                    <tr>
                                        <td dir="ltr"><?php echo esc_html((string) $referrer['referrer']); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $referrer['sessions'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $referrer['views'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;break-inside:avoid;margin:0 0 16px">
                    <h2 class="title" style="margin-top:0">📱 <?php esc_html_e('Devices', 'vereskala-login'); ?></h2>
                    <?php
                    $device_total = max(1, $devices['mobile'] + $devices['desktop']);
                    $mobile_pct   = (int) round($devices['mobile'] / $device_total * 100);
                    ?>
                    <div style="display:flex;height:26px;border-radius:999px;overflow:hidden;border:1px solid #dceee2;margin-bottom:8px">
                        <div style="width:<?php echo (int) $mobile_pct; ?>%;background:#157f3a"></div>
                        <div style="flex:1;background:#c9edd5"></div>
                    </div>
                    <p style="display:flex;gap:18px;margin:0">
                        <span>📱 <?php printf(/* translators: 1: percent, 2: sessions. */ esc_html__('Mobile %1$s%% (%2$s sessions)', 'vereskala-login'), esc_html(number_format_i18n($mobile_pct)), esc_html(number_format_i18n($devices['mobile']))); ?></span>
                        <span>🖥 <?php printf(/* translators: 1: percent, 2: sessions. */ esc_html__('Desktop %1$s%% (%2$s sessions)', 'vereskala-login'), esc_html(number_format_i18n(100 - $mobile_pct)), esc_html(number_format_i18n($devices['desktop']))); ?></span>
                    </p>
                </div>

                <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:14px 16px;break-inside:avoid;margin:0 0 16px">
                    <h2 class="title" style="margin-top:0">🗂 <?php esc_html_e('Page types', 'vereskala-login'); ?></h2>
                    <?php if ([] === $types) : ?>
                        <p class="description"><?php esc_html_e('Nothing here yet.', 'vereskala-login'); ?></p>
                    <?php else : ?>
                        <?php
                        $type_labels = [
                            'home'     => __('Home', 'vereskala-login'),
                            'product'  => __('Product pages', 'vereskala-login'),
                            'category' => __('Category pages', 'vereskala-login'),
                            'search'   => __('Search results', 'vereskala-login'),
                            'cart'     => __('Cart', 'vereskala-login'),
                            'checkout' => __('Checkout', 'vereskala-login'),
                            'other'    => __('Other pages', 'vereskala-login'),
                        ];
                        $type_peak = max(1, max(array_map(static fn(array $t): int => (int) $t['views'], $types)));
                        ?>
                        <?php foreach ($types as $type) : ?>
                            <div style="display:flex;align-items:center;gap:10px;margin:6px 0">
                                <span style="width:120px;font-size:12.5px"><?php echo esc_html($type_labels[(string) $type['page_type']] ?? (string) $type['page_type']); ?></span>
                                <div style="flex:1;background:#eaf6ee;border-radius:999px;height:14px;overflow:hidden">
                                    <div style="width:<?php echo (int) round((int) $type['views'] / $type_peak * 100); ?>%;height:100%;background:#22a84d"></div>
                                </div>
                                <strong style="min-width:52px;text-align:end;color:#157f3a"><?php echo esc_html(number_format_i18n((int) $type['views'])); ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <h2 class="title" style="margin-top:22px">🧠 <?php esc_html_e('Expert analysis', 'vereskala-login'); ?></h2>
            <?php $vkl_insights = $this->build_insights($summary, $daily, $products, $searches, $devices, $locations, $referrers); ?>
            <div style="background:#fff;border:1px solid #dceee2;border-radius:14px;padding:16px 20px;max-width:1140px;margin-bottom:18px;line-height:2">
                <?php if ([] === $vkl_insights) : ?>
                    <p class="description"><?php esc_html_e('Not enough data in this range yet for a meaningful analysis. Come back once more visits are recorded.', 'vereskala-login'); ?></p>
                <?php else : ?>
                    <?php foreach ($vkl_insights as $vkl_ins) : ?>
                        <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid #f3f7f5">
                            <span style="font-size:19px;line-height:1.4"><?php echo esc_html($vkl_ins['icon']); ?></span>
                            <div>
                                <strong><?php echo esc_html($vkl_ins['title']); ?></strong>
                                <div style="color:#455"><?php echo esc_html($vkl_ins['body']); ?></div>
                                <?php if ('' !== $vkl_ins['action']) : ?>
                                    <div style="color:#157f3a;font-size:13px;margin-top:2px">✅ <?php echo esc_html($vkl_ins['action']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <p class="description" style="margin-top:10px"><?php esc_html_e('These notes are generated automatically from the numbers above — treat them as a starting point, not financial advice.', 'vereskala-login'); ?></p>
                <?php endif; ?>
            </div>

            <h2 class="title" style="margin-top:22px">⚙️ <?php esc_html_e('Tracking settings', 'vereskala-login'); ?></h2>
            <form method="post" action="options.php" style="max-width:640px">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable tracking', 'vereskala-login'); ?></th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('analytics_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('analytics_enabled')); ?>" value="1" <?php checked($enabled); ?>>
                                <?php esc_html_e('Record real-visitor pageviews (admins and known crawlers are always skipped; no raw IP is stored).', 'vereskala-login'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ثبت مدت ماندن در صفحه</th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('analytics_dwell')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('analytics_dwell')); ?>" value="1" <?php checked($this->config->get_bool('analytics_dwell', false)); ?>>
                                ثبت ثانیه‌های ماندن هر بازدیدکننده
                            </label>
                            <p class="description">با روشن بودن، هر بازدید هنگام ترک صفحه یک درخواست اضافه (admin-ajax) به سرور می‌فرستد؛ روی هاست‌های محدود، حجم درخواست‌ها را تقریباً دو برابر می‌کند. پیش‌فرض: خاموش.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Keep data for (days)', 'vereskala-login'); ?></th>
                        <td>
                            <input type="number" min="7" max="365" style="width:90px"
                                   name="<?php echo esc_attr($this->config->option_name('analytics_retention_days')); ?>"
                                   value="<?php echo (int) $this->config->get_int('analytics_retention_days', 60); ?>">
                            <p class="description"><?php esc_html_e('Older rows are removed by the daily housekeeping — keeps the table light on shared hosting.', 'vereskala-login'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
