<?php
/**
 * Shop chatbot — answers customers from live shop data.
 *
 * Rule-based intent engine (no external AI service, no data leaves the
 * site): product search and prices, best-seller suggestions,
 * complementary (cross-sell/related) items, order status for the
 * logged-in customer, wallet balance, and hand-off to the ticket system.
 *
 * The reply pipeline is filterable ('vkl_chatbot_reply'), so an external
 * LLM can be plugged in later without touching this file.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Chatbot
 */
final class VKL_Smart_Chatbot
{
    private const AJAX_ACTION          = 'vkl_chatbot';
    private const AJAX_FEEDBACK_ACTION = 'vkl_chatbot_feedback';
    private const AJAX_POLL_ACTION     = 'vkl_chatbot_poll';
    private const NONCE                = 'vkl_chatbot_front';

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Wallet (optional — null when the wallet service is unavailable).
     */
    private ?VKL_Wallet_Service $wallet;

    /**
     * Conversation history (optional).
     */
    private ?VKL_Smart_Chatlog $chatlog;

    /**
     * SMS sender (optional — null when the SMS module is unavailable).
     */
    private ?VKL_Sms_Sender $sms;

    /**
     * Shipping facts (lazy).
     */
    private ?VKL_Smart_Shipping $shipping = null;

    /**
     * @param VKL_Config              $config    Config.
     * @param VKL_Wallet_Service|null $wallet    Wallet service.
     * @param VKL_Smart_Chatlog|null  $chatlog   History repository.
     * @param VKL_Sms_Sender|null     $sms       SMS sender, for staff notify/escalation.
     */
    public function __construct(VKL_Config $config, ?VKL_Wallet_Service $wallet, ?VKL_Smart_Chatlog $chatlog = null, ?VKL_Sms_Sender $sms = null)
    {
        $this->config  = $config;
        $this->wallet  = $wallet;
        $this->chatlog = $chatlog;
        $this->sms     = $sms;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'ajax']);
        add_action('wp_ajax_' . self::AJAX_FEEDBACK_ACTION, [$this, 'ajax_feedback']);
        add_action('wp_ajax_nopriv_' . self::AJAX_FEEDBACK_ACTION, [$this, 'ajax_feedback']);
        add_action('wp_ajax_' . self::AJAX_POLL_ACTION, [$this, 'ajax_poll']);
        add_action('wp_ajax_nopriv_' . self::AJAX_POLL_ACTION, [$this, 'ajax_poll']);
        add_action('wp_footer', [$this, 'widget']);
    }

    /**
     * Designated staff mobiles from settings — trimmed, deduplicated,
     * blanks dropped. One malformed entry ("09121234567," with a trailing
     * comma, or a stray space) used to become an empty-string recipient
     * that the SMS driver quietly rejected the whole batch over.
     *
     * @return string[]
     */
    private function staff_list(): array
    {
        $raw = $this->config->get_string('chatbot_staff');

        if ('' === $raw) {
            return [];
        }

        $rows = json_decode($raw, true);

        if (!is_array($rows)) {
            return [];
        }

        $list = [];

        foreach ($rows as $row) {
            $mobile = isset($row['mobile']) ? (string) (preg_replace('/[^0-9]/', '', (string) $row['mobile']) ?? '') : '';

            if ('' === $mobile) {
                continue;
            }

            $name   = isset($row['name']) ? trim(sanitize_text_field((string) $row['name'])) : '';
            $list[] = ['name' => '' !== $name ? $name : __('Colleague', 'vereskala-login'), 'mobile' => $mobile];
        }

        return $list;
    }

    /**
     * Notify designated staff that a customer just wrote something — the
     * routine "someone is in the chat" ping, sent at most once per
     * session per throttle window (per recipient) so a fast back-and-forth
     * does not turn into one SMS per message. Sent individually, not as
     * one batch call, because the pattern needs EACH recipient's own
     * name, not the customer's — a batch send has no way to vary that per
     * mobile.
     */
    private function notify_staff(string $session, int $conversation_number, string $message, int $user_id): void
    {
        if (null === $this->sms || !$this->config->get_bool('chatbot_notify_staff', false)) {
            return;
        }

        $staff   = $this->staff_list();
        $pattern = $this->config->get_string('chatbot_pattern_notify');

        if ([] === $staff || '' === $pattern) {
            return;
        }

        $customer_name = $user_id > 0 ? $this->resolve_customer_name($user_id) : __('Guest', 'vereskala-login');

        foreach ($staff as $person) {
            $this->sms->send(
                $person['mobile'],
                'chatbot.notify.' . $session,
                $pattern,
                [
                    'staff_name' => $person['name'],
                    'name'       => $customer_name,
                    'session'    => (string) $conversation_number,
                    'message'    => mb_substr($message, 0, 120),
                ],
                1 // At most one of this exact session's notify SMS per hour, per recipient.
            );
        }
    }

    /**
     * Escalation SMS: a customer marked a bot reply unhelpful. Sent
     * without the routine throttle — this one is meant to reach staff
     * every time, not be swallowed by the same-session cooldown above.
     */
    private function notify_escalation(string $session, int $conversation_number, string $message, int $user_id): void
    {
        if (null === $this->sms) {
            return;
        }

        $staff   = $this->staff_list();
        $pattern = $this->config->get_string('chatbot_pattern_escalate');

        if ([] === $staff || '' === $pattern) {
            return;
        }

        $customer_name = $user_id > 0 ? $this->resolve_customer_name($user_id) : __('Guest', 'vereskala-login');

        foreach ($staff as $person) {
            $this->sms->send(
                $person['mobile'],
                'chatbot.escalate.' . $session,
                $pattern,
                [
                    'staff_name' => $person['name'],
                    'name'       => $customer_name,
                    'session'    => (string) $conversation_number,
                    'message'    => mb_substr($message, 0, 120),
                ],
                // Throttled per session, not skipped — a genuine escalation
                // always gets through once. Without this, the feedback AJAX
                // endpoint (reachable directly, not only through the widget's
                // own gating) could be hit repeatedly to fire unlimited SMS
                // at the shop's own expense.
                1
            );
        }
    }

    /**
     * First name for SMS params — falls back gracefully rather than
     * requiring the sms module's own resolver (kept decoupled: the
     * chatbot must still work if that service ever changes shape).
     */
    private function resolve_customer_name(int $user_id): string
    {
        foreach (['first_name', 'billing_first_name'] as $key) {
            $name = trim((string) get_user_meta($user_id, $key, true));

            if ('' !== $name) {
                return $name;
            }
        }

        return __('Dear customer', 'vereskala-login');
    }

    /**
     * Normalise Persian/Arabic text for matching.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\xD9\x8A", "\xD9\x83"], ["\xDB\x8C", "\xDA\xA9"], $text); // ي→ی ك→ک
        $text = str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $text
        );
        // Half-space (ZWNJ) removed here, and stripped from every keyword
        // at comparison time in has()/score() — "می‌خوام" and "میخوام" are
        // the same word to a customer typing on a phone that almost never
        // bothers with the half-space, but two different byte strings to
        // a plain substring search. Enumerating every half-spaced variant
        // by hand in every keyword list below is a losing game; several
        // already do it inconsistently. One normalisation covers all of
        // them, present and future.
        $text = str_replace("\xE2\x80\x8C", '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim(function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text));
    }

    /**
     * Half-space stripped, matching normalize()'s treatment of the
     * customer's own message — without this, a keyword literal that
     * happens to be written WITH a half-space ("می‌خوام") could never
     * match a message that normalize() already stripped it from.
     */
    private function strip_zwnj(string $text): string
    {
        return str_replace("\xE2\x80\x8C", '', $text);
    }

    /**
     * Structure-aware intent score: strong phrases count 2, weak single
     * words 1 — an intent fires only when the combined signal is clear,
     * so one stray word no longer hijacks the whole question.
     *
     * @param string   $message Normalised message.
     * @param string[] $strong  Strong phrases (2 points each).
     * @param string[] $weak    Weak words (1 point each).
     */
    private function score(string $message, array $strong, array $weak = []): int
    {
        $points = 0;

        foreach ($strong as $phrase) {
            if (false !== mb_strpos($message, $this->strip_zwnj($phrase))) {
                $points += 2;
            }
        }

        foreach ($weak as $word) {
            if (false !== mb_strpos($message, $this->strip_zwnj($word))) {
                ++$points;
            }
        }

        return $points;
    }

    /**
     * Whether any keyword occurs in the message.
     *
     * @param string   $message  Normalised message.
     * @param string[] $keywords Keywords.
     */
    private function has(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = $this->strip_zwnj($keyword);

            if (false !== (function_exists('mb_strpos') ? mb_strpos($message, $keyword) : strpos($message, $keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip intent keywords/stop words to isolate the product phrase.
     */
    private function extract_query(string $message): string
    {
        $stop = [
            'قیمت', 'چنده', 'چند است', 'چند', 'هزینه', 'تومان', 'تومن',
            'داری', 'دارید', 'موجوده', 'موجود', 'هست', 'میخوام', 'می‌خوام', 'میخواهم', 'می‌خواهم',
            'دنبال', 'پیدا', 'کن', 'لطفا', 'لطفاً', 'یه', 'یک', 'برام', 'برای من', 'سلام', '؟', '?',
            'پیشنهادت', 'پیشنهاد', 'چیست', 'چیه', 'کدوم', 'کدام', 'معرفی', 'بده', 'بدهید', 'چی', 'خوبه', 'بهترین',
        ];

        $query = str_replace($stop, ' ', $message);

        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }

    /**
     * Split a normalised message into meaningful search tokens.
     *
     * @return string[]
     */
    private function tokenize(string $message): array
    {
        $stop = [
            'از', 'به', 'با', 'در', 'که', 'را', 'و', 'یا', 'تا', 'هم', 'این', 'آن', 'the', 'a', 'an', 'of', 'for',
            // Common grammatical filler that survives extract_query()
            // (which strips intent/question phrasing, not plain verbs and
            // connectives) and otherwise dilutes the per-token search in
            // search()'s second pass — a token search on "است" or "می"
            // matches almost every product, drowning out the real ones.
            'می', 'است', 'بود', 'شود', 'شده', 'هست', 'نیست', 'خیلی', 'چون', 'اگر', 'ولی', 'اما', 'های', 'کدام',
            // Question words and verbs: «برای بندرعباس چقدر زمان می‌برد؟»
            // used to match toners because «برای» sits in every description.
            'برای', 'واسه', 'چقدر', 'چقد', 'چطور', 'چطوری', 'چگونه', 'چرا', 'کجا', 'کی', 'چه', 'چی', 'آیا',
            'زمان', 'طول', 'برد', 'میبره', 'میبرد', 'کشد', 'میکشه', 'روز', 'دارم', 'داره', 'دارد', 'من', 'شما',
            'میشه', 'بشه', 'باید', 'خوب', 'لطفا', 'ممنون', 'مرسی', 'سوال', 'بپرسم',
        ];

        $tokens = preg_split('/[\s,،؛;!?؟.]+/u', $message) ?: [];
        $tokens = array_filter($tokens, static function (string $token) use ($stop): bool {
            $len = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);

            return $len >= 2 && !in_array($token, $stop, true);
        });

        return array_values(array_unique($tokens));
    }

    /**
     * Category slug of a product's primary category ('' when none).
     */
    private function product_category(int $product_id): string
    {
        $terms = get_the_terms($product_id, 'product_cat');

        return (is_array($terms) && [] !== $terms) ? (string) $terms[0]->slug : '';
    }

    /**
     * Match question tokens against shop category names — so «عطر گرم
     * زنانه» narrows to the perfume category instead of, say, body
     * sprays. Longest matching category name wins.
     */
    private function match_category(array $tokens): string
    {
        if ([] === $tokens) {
            return '';
        }

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 200]);

        if (!is_array($terms)) {
            return '';
        }

        $best      = '';
        $best_len  = 0;

        foreach ($terms as $term) {
            $name = $this->normalize((string) $term->name);
            $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

            foreach ($tokens as $token) {
                if (($token === $name || false !== (function_exists('mb_strpos') ? mb_strpos($name, $token) : strpos($name, $token))) && $len > $best_len) {
                    $best     = (string) $term->slug;
                    $best_len = $len;
                }
            }
        }

        return $best;
    }

    /**
     * Search purchasable products — three passes:
     * 1) full phrase, 2) per-token frequency scoring, 3) exact SKU.
     *
     * @return WC_Product[]
     */
    private function search(string $query, int $limit = 3, string $category = ''): array
    {
        if ('' === $query) {
            return [];
        }

        $base = ['status' => 'publish', 'orderby' => 'relevance'];

        if ('' !== $category) {
            $base['category'] = [$category];
        }

        // Pass 1: full phrase.
        $products = wc_get_products($base + ['limit' => $limit, 's' => $query]);

        // Pass 2: dig through the words of the question — search every
        // token separately and rank products by how many tokens hit them.
        if ([] === $products) {
            $tokens = $this->tokenize($query);

            if (count($tokens) >= 1) {
                $scores = [];

                foreach (array_slice($tokens, 0, 5) as $token) {
                    foreach (wc_get_products($base + ['limit' => 8, 's' => $token]) as $hit) {
                        if ($hit instanceof WC_Product) {
                            // Name hit outweighs description; a TAG hit sits
                            // in between — tags are curated keywords, so a
                            // match there is a strong relevance signal.
                            $weight = false !== mb_stripos($hit->get_name(), $token) ? 3 : 1;

                            foreach ($hit->get_tag_ids() as $tag_id) {
                                $tag = get_term($tag_id, 'product_tag');

                                if ($tag instanceof WP_Term && false !== mb_stripos($tag->name, $token)) {
                                    $weight += 2;

                                    break;
                                }
                            }

                            $scores[$hit->get_id()] = ($scores[$hit->get_id()] ?? 0) + $weight;
                        }
                    }

                    // Products labelled with this token as a TAG, even when
                    // the word appears nowhere in title or description.
                    $tag_term = get_term_by('name', $token, 'product_tag');

                    if ($tag_term instanceof WP_Term) {
                        foreach (wc_get_products(['status' => 'publish', 'limit' => 8, 'tag' => [$tag_term->slug]]) as $tag_hit) {
                            if ($tag_hit instanceof WC_Product) {
                                $scores[$tag_hit->get_id()] = ($scores[$tag_hit->get_id()] ?? 0) + 3;
                            }
                        }
                    }
                }

                // One stray word in a description (score 1) is noise, not a
                // match: keep products hit in the name/tag or by 2+ words.
                $scores = array_filter($scores, static fn(int $s): bool => $s >= 2);

                arsort($scores);
                $products = array_filter(array_map('wc_get_product', array_slice(array_keys($scores), 0, $limit)));
            }
        }

        // Pass 3: SKU exact match.
        if ([] === $products) {
            $id = wc_get_product_id_by_sku($query);

            if ($id > 0) {
                $product = wc_get_product($id);

                if ($product instanceof WC_Product) {
                    $products = [$product];
                }
            }
        }

        return array_values(array_filter((array) $products, static fn($p) => $p instanceof WC_Product));
    }

    /**
     * Answer general questions from site content (pages/posts):
     * shipping terms, guides, FAQ pages, and so on.
     *
     * @return array<int, array{title: string, url: string, excerpt: string}>
     */
    private function search_content(string $query, int $limit = 2): array
    {
        $found = [];

        foreach ([$query, implode(' ', array_slice($this->tokenize($query), 0, 3))] as $needle) {
            if ('' === trim($needle)) {
                continue;
            }

            $posts = get_posts([
                'post_type'   => ['page', 'post'],
                'post_status' => 'publish',
                's'           => $needle,
                'numberposts' => $limit,
            ]);

            foreach ($posts as $post) {
                $found[$post->ID] = [
                    'title'   => get_the_title($post),
                    'url'     => (string) get_permalink($post),
                    'excerpt' => wp_trim_words(wp_strip_all_tags((string) $post->post_content), 28),
                ];
            }

            if ([] !== $found) {
                break;
            }
        }

        return array_slice(array_values($found), 0, $limit);
    }

    /**
     * Plain-text price (wc_price emits HTML and &nbsp; entities).
     */
    private function price_text(float $amount): string
    {
        return trim(html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Parse a budget cap from the message: «زیر ۵۰۰ هزار», «تا ۲ میلیون»,
     * «حداکثر 800000 تومان». Returns 0 when none.
     */
    private function budget_cap(string $m): float
    {
        if (!preg_match('/(?:زیر|تا|حداکثر|کمتر از)\s*([0-9.,]+)\s*(میلیون|هزار|تومان|تومن)?/u', $m, $match)) {
            return 0.0;
        }

        $number = (float) str_replace([',', '،'], '', $match[1]);
        $unit   = $match[2] ?? '';

        if ('میلیون' === $unit) {
            $number *= 1000000;
        } elseif ('هزار' === $unit) {
            $number *= 1000;
        }

        return $number;
    }

    /**
     * Cheaper/pricier alternatives within the context product's category.
     *
     * @return WC_Product[]
     */
    private function neighbors(int $context, bool $cheaper): array
    {
        $product = wc_get_product($context);

        if (!$product instanceof WC_Product) {
            return [];
        }

        $reference = (float) $product->get_price();
        $category  = $this->product_category($context);
        $args      = ['status' => 'publish', 'limit' => 20, 'orderby' => 'popularity'];

        if ('' !== $category) {
            $args['category'] = [$category];
        }

        $candidates = [];

        foreach (wc_get_products($args) as $candidate) {
            if (!$candidate instanceof WC_Product || $candidate->get_id() === $context) {
                continue;
            }

            $price = (float) $candidate->get_price();

            if ($price <= 0) {
                continue;
            }

            if (($cheaper && $price < $reference) || (!$cheaper && $price > $reference)) {
                $candidates[] = $candidate;
            }
        }

        // Nearest prices first — a "cheaper" ask wants a close alternative,
        // not the bargain bin.
        usort($candidates, static fn(WC_Product $a, WC_Product $b): int => abs((float) $a->get_price() - $reference) <=> abs((float) $b->get_price() - $reference));

        return array_slice($candidates, 0, 3);
    }

    /**
     * Filter products by a price cap, refilling from category favourites
     * when everything got filtered out.
     *
     * @param WC_Product[] $products Products.
     *
     * @return WC_Product[]
     */
    private function apply_budget(array $products, float $cap, string $category): array
    {
        if ($cap <= 0) {
            return $products;
        }

        $within = array_values(array_filter($products, static fn(WC_Product $p): bool => (float) $p->get_price() > 0 && (float) $p->get_price() <= $cap));

        if ([] !== $within) {
            return $within;
        }

        $args = ['status' => 'publish', 'limit' => 20, 'orderby' => 'popularity'];

        if ('' !== $category) {
            $args['category'] = [$category];
        }

        $pool = array_filter(wc_get_products($args), static fn($p) => $p instanceof WC_Product && (float) $p->get_price() > 0 && (float) $p->get_price() <= $cap);

        return array_slice(array_values($pool), 0, 3);
    }

    /**
     * Product cards payload for the widget.
     *
     * @param WC_Product[] $products Products.
     *
     * @return array<int, array<string, int|string>>
     */
    private function cards(array $products): array
    {
        $cards = [];

        foreach ($products as $product) {
            $cards[] = [
                'id'    => (int) $product->get_id(), // lets the AI read full product facts
                'name'  => $product->get_name(),
                'price' => $this->price_text((float) $product->get_price()),
                'url'   => (string) get_permalink($product->get_id()),
                'image' => vkl_image_url((int) $product->get_image_id(), 'woocommerce_gallery_thumbnail'),
            ];
        }

        return $cards;
    }

    /**
     * Shipping facts, read live from WooCommerce and the checkout logic.
     */
    private function shipping(): VKL_Smart_Shipping
    {
        return $this->shipping ??= new VKL_Smart_Shipping($this->config);
    }

    /**
     * The shipping policy as one sentence (see VKL_Smart_Shipping).
     */
    public function ship_text(): string
    {
        return $this->shipping()->short();
    }

    /**
     * Build a reply.
     *
     * @param string $message Raw customer message.
     * @param int    $context Last discussed product id (0 = none).
     * @param int    $user_id Current user id (0 = guest).
     *
     * @return array{text: string, products: array, context: int}
     */
    public function reply(string $message, int $context, int $user_id, string $session = ''): array
    {
        $m      = $this->normalize($message);
        $result = ['text' => '', 'products' => [], 'context' => $context, 'more' => '', 'topic' => ''];

        // 1) Greeting.
        if ($this->has($m, ['سلام', 'درود', 'وقت بخیر', 'خسته نباشید']) && mb_strlen($m) < 25) {
            $result['text']  = __('Hello! I can look up prices, find products, suggest best sellers and matching accessories, and check your order status. What are you looking for?', 'vereskala-login')
                . ('' !== $this->ship_text() ? "\n" . $this->ship_text() . ' 🚚' : '');
            $result['chips'] = [
                __('Best sellers', 'vereskala-login'),
                __('Help me choose', 'vereskala-login'),
                __('Order tracking', 'vereskala-login'),
                __('Support', 'vereskala-login'),
            ];

            return $this->filtered($result, $message, $user_id);
        }

        // Installment credit / loan guidance — structure-aware (needs a
        // clear combined signal, not just one word like «قسط» in passing).
        $loan_score = $this->score(
            $m,
            ['خرید اقساطی', 'اعتبار خرید', 'درخواست وام', 'وام میخوام', 'وام می‌خوام', 'شرایط اقساط', 'قسطی بخرم'],
            ['وام', 'اقساط', 'قسطی', 'اعتبار', 'شرایط', 'مدارک', 'ضمانت', 'سفته', 'بدهی', 'بدهکار', 'سررسید']
        );

        if ($loan_score >= 2) {
            $lines = [
                __('Installment purchase in three steps:', 'vereskala-login'),
                '۱) از «حساب من ← وام و اعتبار» درخواست اعتبار را ثبت کنید: مبلغ، پذیرش شرایط، کد ملی و شمارهٔ شبا و تصویر روی و پشت کارت ملی (مدرک دیگری لازم نیست).',
                __('2) After approval you will see "Installment purchase" as a payment method at checkout — while your remaining credit covers the order.', 'vereskala-login'),
                '۳) بازپرداخت فقط پس از مصرف اعتبار شروع می‌شود، ماهانه در روز سررسید و از همان برگه قابل پرداخت است.',
            ];

            if ($user_id > 0 && null !== $this->wallet && class_exists('VKL_Wallet_Installments')) {
                $result['keep'] = true; // carries this customer's credit — never sent to the AI.

                try {
                    $credit = vkl('wallet.installments')->available_credit($user_id);

                    $lines[] = $credit > 0
                        ? sprintf(
                            /* translators: %s: available credit. */
                            __('Your current available credit: %s.', 'vereskala-login'),
                            $this->price_text($credit)
                        )
                        : __('You have no active credit yet — submit a request from Financial services.', 'vereskala-login');
                } catch (Throwable $e) { // phpcs:ignore
                    // Diagnostics only; the guidance above stands alone.
                }
            }

            $lines[]        = wc_get_account_endpoint_url(VKL_Wallet_Account::ENDPOINT);
            $result['text'] = implode("\n", $lines);

            return $this->filtered($result, $message, $user_id);
        }

        // Shipping cost / shop benefits.
        // Delivery time («برای بندرعباس چقدر زمان می‌برد؟», «کی میرسه؟»).
        $asks_time = $this->has($m, [
            'چقدر زمان', 'چقد زمان', 'چقدر طول', 'چقد طول', 'چند روز', 'چند روزه', 'کی میرسه', 'کی میرسد', 'کی به دستم',
            'زمان ارسال', 'زمان تحویل', 'مدت ارسال', 'مدت تحویل', 'زمان رسیدن', 'میرسه', 'میرسد', 'به دستم میرسه',
        ]);

        // «سفارشم کی میرسه؟» is about THEIR order — the order branch below.
        $about_own_order = $this->has($m, ['سفارش', 'سپارش', 'مرسوله', 'رهگیری']);

        if (!$about_own_order && ($asks_time || $this->has($m, ['ارسال', 'گسیل', 'حمل', 'تحویل', 'پست', 'پیک', 'مزایا', 'چرا از شما']))) {
            $delivery = trim($this->config->get_string('shop_delivery_time'));

            // Live numbers: WooCommerce zones for the free-shipping threshold, the
            // checkout logic for the price table, and — when the customer has a
            // cart — what THAT cart would pay.
            $lines = array_filter([
                $asks_time
                    ? ('' !== $delivery
                        ? '⏱️ زمان تحویل: ' . $delivery
                        : $this->support_handoff('⏱️ زمان دقیق رسیدن به شهر شما را همکاران پشتیبانی می‌گویند:'))
                    : ('' !== $delivery ? '⏱️ زمان تحویل: ' . $delivery : ''),
                $this->shipping()->summary(),
                $this->shipping()->cart_sentence(),
                $asks_time ? '' : 'همهٔ کالاها اصل‌اند و برگشت از خرید هم از «حساب من ← سفارش‌ها» برای هر کالا ممکن است. 🚚✨',
            ], static fn(string $line): bool => '' !== $line);

            $result['chips'] = [__('Best sellers', 'vereskala-login'), __('Order tracking', 'vereskala-login')];

            $result['text'] = implode("\n\n", $lines);

            return $this->filtered($result, $message, $user_id);
        }

        // Guided consultation: ask the two questions that actually narrow
        // a purchase — what for, and what budget.
        if ($this->has($m, ['مشاوره', 'کمکم کن', 'کمک کن انتخاب', 'راهنماییم کن', 'نمیدونم چی بخرم', 'نمی‌دونم چی بخرم'])) {
            $result['text'] = __('Happy to help you choose! Tell me in one line: what is it for (e.g. gift, daily use, summer), and roughly what budget — like "a women\'s perfume for a gift, under 2 million".', 'vereskala-login');

            return $this->filtered($result, $message, $user_id);
        }

        // 2) Human hand-off — asked for directly, or a question only a
        //    person should answer (authenticity, warranty, complaints).
        // Loyalty club: asked often enough to deserve its own answer, and
        // it is a question the bot can answer well from the customer's own
        // record rather than handing off.
        if ($this->has($m, ['باشگاه', 'امتیاز', 'امتیازها', 'سطح', 'پله', 'وفاداری'])) {
            $lines = [__('باشگاه مشتریان ورسکالا چهار پله دارد و هر خرید امتیاز می‌آورد؛ امتیازها را می‌توانید به اعتبار کیف پول تبدیل کنید. 🌿', 'vereskala-login')];

            if ($user_id > 0) {
                $result['keep'] = true;

                try {
                    $points = (int) vkl('loyalty.repository')->balance($user_id);

                    $lines[] = sprintf(
                        /* translators: %s: points balance. */
                        __('امتیاز کنونی شما: %s', 'vereskala-login'),
                        number_format_i18n($points)
                    );
                } catch (Throwable $e) { // phpcs:ignore
                    // Diagnostics only; the general answer stands alone.
                }
            } else {
                $lines[] = __('برای دیدن امتیاز خودتان، نخست وارد شوید.', 'vereskala-login');
            }

            $lines[] = wc_get_account_endpoint_url(VKL_Loyalty_Account::ENDPOINT);

            $result['text'] = implode("\n", $lines);

            return $this->filtered($result, $message, $user_id);
        }

        $wants_human = $this->has($m, [
            'اپراتور', 'پشتیبان', 'انسان', 'مسئول', 'شکایت', 'شکایات',
            'تماس', 'مشاوره', 'کارشناس', 'صحبت', 'تیکت', 'پیگیری شکایت',
        ]);

        $needs_human = $this->has($m, [
            'اصل', 'اورجینال', 'اورجینال', 'تقلبی', 'قلابی', 'جعلی',
            'گارانتی', 'ضمانت', 'مرجوع', 'پس بدم', 'پس دادن', 'خراب',
            'شکسته', 'معیوب', 'ایراد', 'مشکل دارد', 'تاریخ انقضا',
            'اصالت', 'مجوز', 'شکایت',
        ]);

        if ($wants_human || $needs_human) {
            $result['keep'] = true; // contact links must reach the customer exactly.
            $result['text'] = $this->support_handoff(
                $needs_human && !$wants_human
                    ? __('That one deserves a proper answer from our team rather than a guess from me.', 'vereskala-login')
                    : __('Of course — here is how to reach our team directly.', 'vereskala-login')
            );

            return $this->filtered($result, $message, $user_id);
        }

        // 3) Wallet balance — needs a clear combined signal («موجودی» alone
        // could be about product stock).
        if ($this->score($m, ['کیف پول', 'موجودی کیف', 'اعتبار کیف'], ['موجودی', 'حساب من']) >= 2) {
            $result['keep'] = true;

            if ($user_id > 0 && null !== $this->wallet) {
                $result['text'] = sprintf(
                    /* translators: %s: formatted wallet balance. */
                    __('Your wallet balance is %s.', 'vereskala-login'),
                    $this->price_text($this->wallet->balance($user_id))
                );
            } else {
                $result['text'] = __('Please log in first, then I can show your wallet balance.', 'vereskala-login');
            }

            return $this->filtered($result, $message, $user_id);
        }

        // 4) Order status ("وضعیت سفارش 123").
        if ($this->has($m, ['سفارش', 'سپارش', 'مرسوله', 'رهگیری', 'پیگیری']) && !preg_match('/(\d{2,})/', $m)) {
            $result['keep'] = true;

            // Be genuinely helpful: a logged-in customer asking "track my
            // order" without a number almost always means their latest one.
            if ($user_id > 0) {
                $orders = wc_get_orders(['customer_id' => $user_id, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC']);

                if (isset($orders[0]) && $orders[0] instanceof WC_Order) {
                    $order          = $orders[0];
                    $tracking       = (string) $order->get_meta('_vkl_tracking_code');
                    $method         = (string) $order->get_meta('_vkl_dispatch_method');
                    $result['text'] = sprintf(
                        /* translators: 1: order number, 2: order status. */
                        __('Your latest order %1$s is currently: %2$s.', 'vereskala-login'),
                        $order->get_order_number(),
                        wc_get_order_status_name($order->get_status())
                    );

                    if ('' !== $tracking) {
                        $result['text'] .= ' ' . sprintf(
                            /* translators: 1: dispatch method, 2: tracking code. */
                            __('Dispatched via %1$s, tracking code: %2$s.', 'vereskala-login'),
                            '' !== $method ? $method : __('post', 'vereskala-login'),
                            $tracking
                        );
                    }

                    $result['text'] .= ' ' . __('For another order, send its number.', 'vereskala-login');

                    return $this->filtered($result, $message, $user_id);
                }

                $result['text'] = __('I found no orders on your account yet. Once you order, I can track it here for you.', 'vereskala-login');

                return $this->filtered($result, $message, $user_id);
            }

            $result['text'] = __('Please log in first, then I can look up your orders — or send me the order number.', 'vereskala-login');

            return $this->filtered($result, $message, $user_id);
        }

        // A bare number (no order-ish keyword at all) counts too — but
        // only for a signed-in customer. The branch right above this one
        // ends every "which order?" reply with "For another order, send
        // its number", and a customer who does exactly that types NOTHING
        // but digits: "41131". Requiring a keyword here meant that literal
        // follow-up always missed both order branches and fell through to
        // the product search, which of course found no product named
        // "41131" and handed the customer to support — right after the
        // bot itself had asked for exactly this input.
        $bare_order_number = $user_id > 0 && 1 === preg_match('/^\d{2,8}$/', trim($m));

        if (($this->has($m, ['سفارش', 'سپارش', 'مرسوله', 'رهگیری']) || $bare_order_number) && preg_match('/(\d{2,})/', $m, $match)) {
            $result['keep'] = true;
            $order          = $user_id > 0 ? wc_get_order((int) $match[1]) : false;

            if ($order instanceof WC_Order && (int) $order->get_customer_id() === $user_id) {
                $status   = wc_get_order_status_name($order->get_status());
                $tracking = (string) $order->get_meta('_vkl_tracking_code');
                $method   = (string) $order->get_meta('_vkl_dispatch_method');

                $result['text'] = sprintf(
                    /* translators: 1: order number, 2: order status. */
                    __('Order %1$s is currently: %2$s.', 'vereskala-login'),
                    $order->get_order_number(),
                    $status
                );

                if ('' !== $tracking) {
                    $result['text'] .= ' ' . sprintf(
                        /* translators: 1: dispatch method, 2: tracking code. */
                        __('Dispatched via %1$s, tracking code: %2$s.', 'vereskala-login'),
                        '' !== $method ? $method : __('post', 'vereskala-login'),
                        $tracking
                    );
                }
            } else {
                $result['text'] = __('I could not find that order on your account. Please log in and check the order number.', 'vereskala-login');
            }

            return $this->filtered($result, $message, $user_id);
        }

        // 5a) Cheaper / higher-end alternatives to the product on the table.
        $wants_cheaper = $this->has($m, ['ارزان', 'ارزون', 'اقتصادی']);
        $wants_premium = $this->has($m, ['گرانتر', 'گران‌تر', 'گرون', 'بهترش', 'باکیفیت', 'لوکس', 'حرفه‌ای‌تر', 'رده بالاتر']);

        if (($wants_cheaper || $wants_premium) && $context > 0) {
            $products = $this->neighbors($context, $wants_cheaper);

            if ([] !== $products) {
                $result['text'] = $wants_cheaper
                    ? __('More budget-friendly options close to it:', 'vereskala-login')
                    : __('Higher-end options worth a look:', 'vereskala-login');
                $result['products'] = $this->cards($products);
                $result['context']  = (int) $products[0]->get_id();
                $result['chips']    = [__('Goes well with it', 'vereskala-login'), __('Best sellers', 'vereskala-login')];
            } else {
                $result['text'] = $wants_cheaper
                    ? __('This is already the most affordable option in its category right now.', 'vereskala-login')
                    : __('This is already the top option in its category right now.', 'vereskala-login');
            }

            return $this->filtered($result, $message, $user_id);
        }

        // 4b) Product-attribute questions — «رایحه عطر X چیست؟», «حجمش
        // چقدره؟», «مناسب چه پوستیه؟». Answered from the product's own
        // attributes and description, never from unrelated suggestions.
        $attr_words = ['رایحه', 'بو', 'نت', 'ساختار بویایی', 'ماندگاری', 'جنس', 'حجم', 'وزن', 'ابعاد', 'رنگ', 'ترکیبات', 'کاربرد', 'مناسب', 'انقضا', 'ژادینگ'];
        $attr_hit   = '';

        foreach ($attr_words as $attr_word) {
            if (false !== mb_strpos($m, $attr_word)) {
                $attr_hit = $attr_word;

                break;
            }
        }

        if ('' !== $attr_hit) {
            // The product named in the MESSAGE wins; the page context is
            // the fallback for «حجمش چقدره؟»-style follow-ups.
            $attr_products = $this->search($this->extract_query($m), 1);
            $attr_product  = [] !== $attr_products ? $attr_products[0] : ($context > 0 ? wc_get_product($context) : null);

            if ($attr_product instanceof WC_Product) {
                $lines = [];

                // 1st source: structured attributes whose label matches.
                foreach ($attr_product->get_attributes() as $attribute) {
                    if (!$attribute instanceof WC_Product_Attribute) {
                        continue;
                    }

                    $label = wc_attribute_label($attribute->get_name(), $attr_product);

                    $values = $attribute->is_taxonomy()
                        ? implode('، ', array_map(static fn($t) => $t->name, (array) $attribute->get_terms()))
                        : implode('، ', $attribute->get_options());

                    if ('' !== $values && (false !== mb_stripos($label, $attr_hit) || false !== mb_stripos($values, $attr_hit))) {
                        $lines[] = $label . ': ' . $values;
                    }
                }

                // 2nd source: description sentences mentioning the word.
                if ([] === $lines) {
                    $text_pool = wp_strip_all_tags($attr_product->get_short_description() . ' ' . $attr_product->get_description());

                    foreach (preg_split('/(?<=[.؟!؛\n])/u', $text_pool) ?: [] as $sentence) {
                        if (false !== mb_strpos($sentence, $attr_hit) && count($lines) < 2) {
                            $lines[] = trim($sentence);
                        }
                    }
                }

                // 3rd source: full spec sheet, so the reply is still useful.
                if ([] === $lines) {
                    foreach (array_slice($attr_product->get_attributes(), 0, 4) as $attribute) {
                        if ($attribute instanceof WC_Product_Attribute) {
                            $spec_values = $attribute->is_taxonomy()
                                ? implode('، ', array_map(static fn($t) => $t->name, (array) $attribute->get_terms()))
                                : implode('، ', $attribute->get_options());

                            if ('' !== $spec_values) {
                                $lines[] = wc_attribute_label($attribute->get_name(), $attr_product) . ': ' . $spec_values;
                            }
                        }
                    }

                    array_unshift($lines, __('That exact detail is not stated for this product; its specs are:', 'vereskala-login'));
                }

                $result['text'] = $attr_product->get_name() . "\n" . implode("\n", $lines)
                    . "\n" . (string) get_permalink($attr_product->get_id());
                $result['context'] = $attr_product->get_id();
                $result['topic']   = $this->product_category($attr_product->get_id());

                return $this->filtered($result, $message, $user_id);
            }
        }

        // 5) Complementary items for the product being discussed.
        // «ست» only as its own word — it used to fire inside «چیست»!
        if ($this->has($m, ['مکمل', 'همراهش', 'باهاش', 'اکسسوری', 'جور درمی', 'ست کردن'])
            || 1 === preg_match('/(^|[\s،؟!])ست([\s،؟!]|$)/u', $m)
        ) {
            $ids = [];

            if ($context > 0) {
                $product = wc_get_product($context);

                if ($product instanceof WC_Product) {
                    $ids = $product->get_cross_sell_ids();

                    if ([] === $ids) {
                        $ids = wc_get_related_products($product->get_id(), 3);
                    }
                }
            }

            if ([] !== $ids) {
                $products           = array_filter(array_map('wc_get_product', array_slice($ids, 0, 3)));
                $result['text']     = __('These go well with it:', 'vereskala-login');
                $result['products'] = $this->cards($products);

                $terms = $context > 0 ? get_the_terms($context, 'product_cat') : false;

                if (is_array($terms) && isset($terms[0])) {
                    $link = get_term_link($terms[0]);

                    if (is_string($link)) {
                        $result['more'] = $link;
                    }
                }
            } else {
                $result['text'] = __('Tell me which product you mean (or ask about one first), and I will suggest matching items.', 'vereskala-login');
            }

            return $this->filtered($result, $message, $user_id);
        }

        // 6) Recommendations — «عطر مناسب تابستان پیشنهادت چیست؟»,
        // «کرم آبرسان قوی معرفی کن», «چی بخرم؟». The descriptive words are
        // searched first; when nothing matches them exactly, the answer
        // falls back to the best sellers of the matched category instead
        // of giving up.
        if ($this->has($m, ['پیشنهاد', 'پرفروش', 'چی بخرم', 'بهترین', 'محبوب', 'معرفی', 'مناسب', 'خوبه'])) {
            $needle   = $this->extract_query($m);
            // The MESSAGE decides the category first — the running
            // context/topic only fill in when the new question names
            // none itself. This keeps answer #2, #3, ... on the new
            // subject instead of repeating the first one forever.
            $category = $this->match_category($this->tokenize($m));

            if ('' === $category && $context > 0) {
                $category = $this->product_category($context);
            }

            if ('' === $category && '' !== $session && null !== $this->chatlog) {
                $category = $this->chatlog->last_topic($session);
            }

            $products = '' !== $needle ? $this->search($needle, 3, $category) : [];
            $exact    = [] !== $products;

            if (!$exact) {
                $args = ['status' => 'publish', 'limit' => 3, 'orderby' => 'popularity'];

                if ('' !== $category) {
                    $args['category'] = [$category];
                }

                $products = array_values(array_filter(
                    wc_get_products($args),
                    static fn($p) => $p instanceof WC_Product
                ));
            }

            $term = '' !== $category ? get_term_by('slug', $category, 'product_cat') : false;

            if ($exact) {
                $result['text'] = __('My suggestion for you:', 'vereskala-login');
            } elseif ($term instanceof WP_Term) {
                $result['text'] = sprintf(
                    /* translators: %s: category name. */
                    __('I did not find that exact match — these are the favourites in %s:', 'vereskala-login'),
                    $term->name
                );
            } else {
                $result['text'] = __('Our current best sellers:', 'vereskala-login');
            }

            $result['products'] = $this->cards($products);
            $result['topic']    = $category;
            $result['more']     = (string) wc_get_page_permalink('shop');

            if ($term instanceof WP_Term) {
                $link = get_term_link($term);

                if (is_string($link)) {
                    $result['more'] = $link;
                }
            }

            if ([] !== $products) {
                $result['context'] = (int) $products[0]->get_id();
            }

            return $this->filtered($result, $message, $user_id);
        }

        // 7) Price / availability / free search (default). On a product
        // page the answer stays inside that product's own category;
        // otherwise a category named in the question narrows the scope
        // («عطر گرم زنانه» → the perfume category, not body sprays).
        $query  = $this->extract_query($m);
        $needle = '' !== $query ? $query : $m;

        // 6.4) A staff member has answered a close enough question before
        // — reuse that support-verified wording ahead of a generic guess.
        // Checked here, not earlier: this is a fallback for gaps in the
        // rules above, not a way to override an intent that already
        // handles this well (a learned reply about, say, checkout that
        // happened to share words with "wallet" must not pre-empt the
        // wallet-balance branch a few lines up).
        $learned = $this->learned_answer($m);

        if (null !== $learned) {
            $result['text'] = $learned['text'];

            return $this->filtered($result, $message, $user_id);
        }

        // 6.5) Advice first: «چطور»، «مشاوره»، «کدام بهتر است» are answered
        // from the site's own articles (the knowledge base), because a
        // guide answers those far better than a product card does.
        $advice = $this->advice_answer($m, $needle);

        if (null !== $advice) {
            $result['text'] = $advice;

            return $this->filtered($result, $message, $user_id);
        }

        // Message-named category wins; the running context is only a
        // fallback for follow-ups that name nothing themselves.
        $category = $this->match_category($this->tokenize($needle));

        if ('' === $category && $context > 0) {
            $category = $this->product_category($context);
        }

        // Conversation memory: «ارزون‌ترش چی داری؟» after a perfume question
        // stays inside perfumes.
        if ('' === $category && '' !== $session && null !== $this->chatlog) {
            $category = $this->chatlog->last_topic($session);
        }

        // The question moved to a different category → the old product
        // context is stale; drop it so follow-up intents (price, cheaper,
        // complementary) answer about the NEW subject.
        if ($context > 0 && '' !== $category && $this->product_category($context) !== $category) {
            $context           = 0;
            $result['context'] = 0;
        }

        $result['topic'] = $category;

        // Nothing searchable left after stripping the question words →
        // ask what they need instead of parroting the same favourites.
        if ('' === trim($needle) || mb_strlen(trim($needle)) < 2) {
            $result['weak']  = true;
            $result['text']  = __('What exactly are you looking for? Name a product or category — or tap one of these:', 'vereskala-login');
            $result['chips'] = [
                __('Best sellers', 'vereskala-login'),
                __('Help me choose', 'vereskala-login'),
                __('Order tracking', 'vereskala-login'),
            ];

            return $this->filtered($result, $message, $user_id);
        }

        $products = $this->search($needle, 3, $category);

        // Respect a stated budget («زیر ۲ میلیون»).
        $cap = $this->budget_cap($m);

        if ($cap > 0) {
            $products = $this->apply_budget($products, $cap, $category);
        }

        // Never answer a shopping question empty-handed: when the exact
        // phrase misses but a category is known, offer its favourites.
        $fell_back = false;

        // Site-content mastery: questions that are not about a product
        // (guides, policies, services) get answered from pages and posts
        // before we fall back to shop favourites.
        if ([] === $products) {
            $content = get_posts([
                'post_type'      => ['page', 'post'],
                'post_status'    => 'publish',
                'posts_per_page' => 2,
                's'              => $needle,
            ]);

            if ([] !== $content) {
                $lines = [__('This is what I found on the site about that:', 'vereskala-login')];

                foreach ($content as $content_post) {
                    $excerpt = wp_trim_words(wp_strip_all_tags((string) ($content_post->post_excerpt ?: $content_post->post_content)), 32, '…');
                    $lines[] = '📄 ' . $content_post->post_title . ' — ' . $excerpt;
                    $lines[] = (string) get_permalink($content_post);
                }

                $result['text'] = implode("\n", $lines);

                return $this->filtered($result, $message, $user_id);
            }
        }

        if ([] === $products && '' !== $category) {
            $products = array_values(array_filter(
                wc_get_products(['status' => 'publish', 'limit' => 3, 'orderby' => 'popularity', 'category' => [$category]]),
                static fn($p) => $p instanceof WC_Product
            ));

            $fell_back = [] !== $products;
        }

        // Consistent structure: header line + cards + a "see more" link.
        $more = add_query_arg(['s' => rawurlencode($needle), 'post_type' => 'product'], home_url('/'));

        if ('' !== $category) {
            $term = get_term_by('slug', $category, 'product_cat');

            if ($term instanceof WP_Term) {
                $link = get_term_link($term);

                if (is_string($link)) {
                    $more = $link;
                }
            }
        }

        if ([] !== $products) {
            if ($fell_back) {
                $result['weak'] = true;
                $term = get_term_by('slug', $category, 'product_cat');

                $result['text'] = $term instanceof WP_Term
                    ? sprintf(
                        /* translators: %s: category name. */
                        __('I did not find that exact match — these are the favourites in %s:', 'vereskala-login'),
                        $term->name
                    )
                    : __('Here is what I found:', 'vereskala-login');
            } else {
                $result['text'] = $this->has($m, ['قیمت', 'چند'])
                    ? __('Here is what I found, with prices:', 'vereskala-login')
                    : __('Here is what I found:', 'vereskala-login');
            }

            $result['products'] = $this->cards($products);
            $result['context']  = (int) $products[0]->get_id();
            $result['more']     = $more;
            $result['chips']    = [
                __('Cheaper', 'vereskala-login'),
                __('Higher-end', 'vereskala-login'),
                __('Goes well with it', 'vereskala-login'),
            ];
        } else {
            // No product hit — dig through site pages/posts for an answer
            // (shipping terms, guides, FAQ, ...).
            $articles = $this->search_content('' !== $query ? $query : $m);

            if ([] !== $articles) {
                $lines = [__('I found this on our site:', 'vereskala-login')];

                foreach ($articles as $article) {
                    $lines[] = $article['title'] . "\n" . $article['excerpt'] . "\n" . $article['url'];
                }

                $result['text'] = implode("\n\n", $lines);
            } else {
                $result['weak'] = true;
                $result['text'] = sprintf(
                    /* translators: %s: support contact block. */
                    __("I could not find a matching product. Try different wording, or ask our team:\n%s", 'vereskala-login'),
                    $this->support_handoff('')
                );
            }
        }

        return $this->filtered($result, $message, $user_id);
    }

    /**
     * Every way a customer can reach a human, as one message.
     *
     * A chatbot that cannot answer must not be a dead end: the customer
     * came with a real question, so the reply hands them straight to
     * someone who can answer it.
     *
     * @param string $lead Sentence to open with.
     */
    private function support_handoff(string $lead): string
    {
        $lines = [$lead];

        $ticket = wc_get_account_endpoint_url(VKL_Ticket_Account::ENDPOINT);

        if ('' !== $ticket) {
            $lines[] = '🎫 ' . sprintf(
                /* translators: %s: support tickets URL. */
                __('Open a ticket: %s', 'vereskala-login'),
                esc_url($ticket)
            );
        }

        $wa = trim($this->config->get_string('support_whatsapp'));

        if ('' !== $wa) {
            // Accept whatever the shop typed and normalise to wa.me form.
            $digits = preg_replace('/\D+/', '', $wa) ?? '';

            if (0 === strpos($digits, '0')) {
                $digits = '98' . ltrim($digits, '0');
            }

            if ('' !== $digits) {
                $lines[] = '💬 ' . sprintf(
                    /* translators: %s: WhatsApp URL. */
                    __('WhatsApp: %s', 'vereskala-login'),
                    'https://wa.me/' . $digits
                );
            }
        }

        $phone = trim($this->config->get_string('support_phone'));

        if ('' !== $phone) {
            $lines[] = '📞 ' . sprintf(
                /* translators: %s: phone number. */
                __('Phone: %s', 'vereskala-login'),
                $phone
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Apply the extension filter.
     *
     * @param array  $result  Reply payload.
     * @param string $message Original message.
     * @param int    $user_id User id.
     */
    private function filtered(array $result, string $message, int $user_id): array
    {
        /**
         * Filters the chatbot reply — hook an external AI here if desired.
         *
         * @param array  $result  {text, products, context}.
         * @param string $message Original customer message.
         * @param int    $user_id Current user id (0 = guest).
         */
        return (array) apply_filters('vkl_chatbot_reply', $result, $message, $user_id);
    }

    /**
     * AJAX endpoint.
     */
    public function ajax(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $user_id = get_current_user_id();

        // Computed once, up front, so BOTH the phone step and the message
        // step ever after write to the very same conversation — whichever
        // of the two happens to run first. Previously only the message
        // path created this cookie, so a visitor who gave their number and
        // then closed the tab left no session for it to attach to, and
        // the shop never saw the number at all.
        $session = isset($_COOKIE['vkl_chat_session'])
            ? (string) preg_replace('/[^a-fA-F0-9]/', '', (string) $_COOKIE['vkl_chat_session'])
            : '';

        if ('' === $session || strlen($session) < 16) {
            $session = strtolower(wp_generate_password(32, false, false));
            setcookie('vkl_chat_session', $session, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true);
        }

        // Step 1: a GUEST confirms a mobile number before asking anything.
        // A logged-in customer is already identified by their account —
        // the widget never shows them this step — so this branch only
        // ever runs for guests in practice. The number is kept in a
        // cookie (returning visitors skip the gate) and written into the
        // conversation log itself, so the shop can call the visitor back
        // even if the chat never answers their question.
        $phone_in = isset($_POST['phone']) ? sanitize_text_field(wp_unslash((string) $_POST['phone'])) : '';

        if ('' !== $phone_in) {
            $session_phone = preg_replace('/[^0-9]/', '', $phone_in) ?? '';

            // Accept the number however it was typed — with a leading 0
            // (09123456789), with the country code (+98.../0098...), or
            // bare — and normalise all of them to the same 10 digits
            // starting with 9. Without this, "+989123456789" slipped past
            // the old check (it starts with "9" too, after stripping
            // nothing) and got stored as 12 raw digits: a corrupted
            // billing_phone and a broken tel: link in the admin screen.
            if (str_starts_with($session_phone, '0098')) {
                $session_phone = substr($session_phone, 4);
            } elseif (str_starts_with($session_phone, '98') && 12 === strlen($session_phone)) {
                $session_phone = substr($session_phone, 2);
            } else {
                $session_phone = ltrim($session_phone, '0');
            }

            if (10 !== strlen($session_phone) || !str_starts_with($session_phone, '9')) {
                wp_send_json_error(['text' => __('Please enter a valid mobile number.', 'vereskala-login')]);
                return;
            }

            setcookie('vkl_chat_phone', $session_phone, time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true);

            if ($user_id > 0) {
                update_user_meta($user_id, 'billing_phone', '0' . $session_phone);
            } elseif (null !== $this->chatlog && $this->config->get_bool('chatbot_history', true)) {
                $this->chatlog->add(
                    $session,
                    0,
                    'user',
                    'شماره تماس ثبت شد: 0' . $session_phone,
                    '',
                    $session_phone
                );
            }

            wp_send_json_success([
                'text'         => __('Thank you! How can I help you?', 'vereskala-login'),
                'phone_stored' => true,
            ]);

            return;
        }

        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash((string) $_POST['message'])) : '';
        $context = isset($_POST['context']) ? absint($_POST['context']) : 0;

        if ('' === $message) {
            wp_send_json_error(['text' => __('Please type your question first.', 'vereskala-login')]);

            return;
        }

        if (mb_strlen($message) > 300) {
            wp_send_json_error(['text' => __('That message is a bit long — please shorten it and send again.', 'vereskala-login')]);

            return;
        }

        $history_on = null !== $this->chatlog && $this->config->get_bool('chatbot_history', true);
        $phone      = $user_id > 0
            ? (string) get_user_meta($user_id, 'billing_phone', true)
            : (string) ($_COOKIE['vkl_chat_phone'] ?? '');

        // A human already took this conversation over (staff has replied
        // in it at least once) — keep routing to them instead of letting
        // the bot barge back in with an automated answer once a person is
        // already handling the customer.
        if ($history_on && $this->chatlog->has_staff_reply($session)) {
            $this->chatlog->add($session, $user_id, 'user', $message, '', $phone);
            $this->chatlog->mark_needs_human($session);
            $this->notify_staff($session, $this->chatlog->touch_session($session), $message, $user_id);

            wp_send_json_success([
                'text'             => 'پیام شما برای پشتیبان ارسال شد؛ به‌زودی پاسخ می‌دهد.',
                'handled_by_human' => true,
                'context'          => $context,
            ]);

            return;
        }

        // A single failing lookup used to reach the customer as a bare "!"
        // with no way to tell what broke. Log the detail for the shop owner
        // and answer with something usable.
        try {
            $reply = $this->reply($message, $context, $user_id, $session);
        } catch (Throwable $e) {
            error_log('VKL chatbot failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

            wp_send_json_error([
                'text' => __('Sorry — something went wrong on our side. Please try again, or ask us directly.', 'vereskala-login'),
            ]);

            return;
        }

        // Structured conversation history: the message plus the detected
        // topic (category) and the visitor's known number, so both the
        // shop owner and the bot's own memory can use it. One indexed
        // INSERT per message — negligible. The bot reply's own row id
        // travels back to the widget so the feedback buttons attached to
        // that specific bubble know which message they are rating.
        $bot_message_id = 0;

        if ($history_on) {
            $topic = (string) ($reply['topic'] ?? '');

            $this->chatlog->add($session, $user_id, 'user', $message, $topic, $phone);
            $bot_message_id = $this->chatlog->add($session, $user_id, 'bot', (string) ($reply['text'] ?? ''), $topic, $phone);

            $this->notify_staff($session, $this->chatlog->touch_session($session), $message, $user_id);
        }

        unset($reply['topic'], $reply['weak'], $reply['keep']);

        $reply['message_id'] = $bot_message_id;

        // Remember what the customer looked for — feeds the smart popup link.
        $query = $this->extract_query($this->normalize($message));

        if ('' !== $query) {
            VKL_Smart_Popup::remember_search($query);
        }

        wp_send_json_success($reply);
    }

    /**
     * A customer rates one bot reply 👍/👎. A "no" hands the conversation
     * to a human: the bot tells the customer to expect a person, the
     * session is flagged for the admin's live inbox, and staff get an
     * escalation SMS. A "yes" is just acknowledged — nothing to escalate.
     */
    public function ajax_feedback(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (null === $this->chatlog || !$this->config->get_bool('chatbot_history', true)) {
            wp_send_json_success(['text' => '']);

            return;
        }

        $session = isset($_COOKIE['vkl_chat_session'])
            ? (string) preg_replace('/[^a-fA-F0-9]/', '', (string) $_COOKIE['vkl_chat_session'])
            : '';
        $helpful = isset($_POST['helpful']) && '1' === (string) $_POST['helpful'];
        $reply_text = isset($_POST['reply']) ? sanitize_text_field(wp_unslash((string) $_POST['reply'])) : '';

        if ('' === $session) {
            wp_send_json_success(['text' => '']);

            return;
        }

        if ($helpful) {
            wp_send_json_success(['text' => '', 'escalated' => false]);

            return;
        }

        $user_id      = get_current_user_id();
        $handoff_text = $this->handoff_message();

        $this->chatlog->add($session, 0, 'bot', $handoff_text);
        $this->chatlog->mark_needs_human($session);
        $this->notify_escalation($session, $this->chatlog->touch_session($session), $reply_text, $user_id);

        wp_send_json_success(['text' => $handoff_text, 'escalated' => true]);
    }

    /**
     * What the customer sees right after marking a bot reply unhelpful —
     * names the support hours either way, but says something different
     * depending on whether anyone is actually on shift right now: inside
     * hours, a person is coming; outside them, the message is queued and
     * whoever opens it first thing tomorrow (or whenever staff are next
     * on) will see it. Saying "you will be connected shortly" at 2 a.m.
     * would just be a promise nobody is there to keep.
     */
    private function handoff_message(): string
    {
        $start  = max(0, min(23, $this->config->get_int('chatbot_hours_start', 8)));
        $end    = max(0, min(23, $this->config->get_int('chatbot_hours_end', 22)));
        $hour   = (int) current_time('H');
        $window = sprintf(
            'هر روز ساعت %s تا %s',
            number_format_i18n($start),
            number_format_i18n($end)
        );

        if ($hour >= $start && $hour < $end) {
            return sprintf('ممنون که گفتید! به‌زودی به یک پشتیبان وصل خواهید شد. ساعات پاسخگویی پشتیبانی: %s.', $window);
        }

        return sprintf('ممنون که گفتید! الان خارج از ساعات پاسخگویی پشتیبانی هستیم (%s). پیام شما ثبت شد و در اولین فرصت پاسخ داده می‌شود.', $window);
    }

    /**
     * The widget's poll loop: any staff replies since the last message id
     * it already rendered, plus whether the session is still waiting on a
     * human (so the widget knows when to keep polling).
     */
    public function ajax_poll(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (null === $this->chatlog) {
            wp_send_json_success(['messages' => [], 'needs_human' => false]);

            return;
        }

        $session = isset($_COOKIE['vkl_chat_session'])
            ? (string) preg_replace('/[^a-fA-F0-9]/', '', (string) $_COOKIE['vkl_chat_session'])
            : '';
        $after = isset($_POST['after']) ? absint($_POST['after']) : 0;

        if ('' === $session) {
            wp_send_json_success(['messages' => [], 'needs_human' => false]);

            return;
        }

        $messages = [];

        foreach ($this->chatlog->messages_since($session, $after) as $row) {
            if ('staff' !== (string) ($row['role'] ?? '')) {
                continue; // Only staff replies are new to the customer's own view.
            }

            $messages[] = ['id' => (int) ($row['id'] ?? 0), 'text' => (string) ($row['message'] ?? '')];
        }

        wp_send_json_success([
            'messages'    => $messages,
            'needs_human' => $this->chatlog->needs_human($session),
        ]);
    }

    /**
     * Floating widget markup + inline JS.
     */
    public function widget(): void
    {
        if (!$this->config->get_bool('chatbot_enabled', false) || is_admin()) {
            return;
        }

        if (!is_user_logged_in() && !$this->config->get_bool('chatbot_guests', true)) {
            return;
        }

        $title    = $this->config->get_string('chatbot_title');
        $title    = '' !== $title ? $title : __('Shop assistant', 'vereskala-login');
        $greeting = $this->config->get_string('chatbot_greeting');
        $greeting = '' !== $greeting ? $greeting : __('Hi! Ask me about products, prices, or your order.', 'vereskala-login');

        $product_context = (function_exists('is_product') && is_product()) ? (int) get_queried_object_id() : 0;
        ?>
        <div id="vkl-chat" dir="rtl" style="position:fixed;bottom:20px;inset-inline-start:20px;z-index:99998;font-size:14px">
            <style>@media (max-width:782px){#vkl-chat{bottom:96px !important}}</style>
            <button type="button" id="vkl-chat-toggle" aria-label="<?php echo esc_attr($title); ?>"
                    style="width:56px;height:56px;border-radius:50%;border:0;background:#157f3a;color:#fff;font-size:24px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25)">&#128172;</button>
            <div id="vkl-chat-panel" style="display:none;position:absolute;bottom:70px;inset-inline-start:0;width:320px;max-width:calc(100vw - 40px);background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.25);overflow:hidden">
                <div style="background:#157f3a;color:#fff;padding:10px 14px;font-weight:700"><?php echo esc_html($title); ?></div>
                <div id="vkl-chat-log" style="height:300px;overflow-y:auto;padding:10px;background:#f7f7f7"></div>

                <?php
                // Ready-made questions. Most people do not know what a
                // chatbot understands, so they type something it cannot
                // match and conclude it is broken. One tap removes the
                // guesswork — and every tap is a phrasing we handle.
                // Each label is worded with vocabulary the matcher already
                // recognises — a chip that leads to "I did not understand"
                // would be worse than no chip at all.
                // Grouped roughly by how often they get asked. Signed-in
                // customers get the account questions first; a guest has no
                // wallet or orders to look up, so those would only lead to
                // "please sign in".
                $vkl_chips = [];

                if (is_user_logged_in()) {
                    $vkl_chips[] = __('پیگیری سپارش من', 'vereskala-login');
                    $vkl_chips[] = __('موجودی کیف پول من', 'vereskala-login');
                    $vkl_chips[] = __('اقساط و بدهی من', 'vereskala-login');
                }

                $vkl_chips = array_merge($vkl_chips, [
                    __('شرایط دریافت وام و خرید اقساطی', 'vereskala-login'),
                    __('هزینه ارسال چند است؟', 'vereskala-login'),
                    __('شرایط مرجوعی و گارانتی', 'vereskala-login'),
                    __('پیشنهاد بده چی بخرم', 'vereskala-login'),
                    __('پرفروش‌ترین‌ها', 'vereskala-login'),
                    __('ارزان‌تر از این داری؟', 'vereskala-login'),
                    __('باشگاه مشتریان و امتیازها', 'vereskala-login'),
                    __('پشتیبانی و ثبت تیکت', 'vereskala-login'),
                ]);
                ?>
                        <?php
                // Sort chips into two display groups so they take less space.
                $vkl_account_chips = array_slice($vkl_chips, 0, is_user_logged_in() ? 3 : 0);
                $vkl_store_chips   = array_slice($vkl_chips, is_user_logged_in() ? 3 : 0);
                ?>
                <div id="vkl-chat-chips" style="padding:8px 10px;background:#fff;border-top:1px solid #eee">
                    <?php if ([] !== $vkl_account_chips) : ?>
                        <details open style="margin-bottom:4px">
                            <summary style="cursor:pointer;font-size:12px;color:#7a8a80;user-select:none;margin-bottom:4px"><?php esc_html_e('My account', 'vereskala-login'); ?></summary>
                            <div style="display:flex;flex-wrap:wrap;gap:5px">
                                <?php foreach ($vkl_account_chips as $vkl_chip) : ?>
                                    <button type="button" class="vkl-chat-chip"
                                            style="border:1px solid #cfe6d8;background:#f4fbf6;color:#157f3a;border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit"><?php echo esc_html($vkl_chip); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <details style="margin-top:2px">
                        <summary style="cursor:pointer;font-size:12px;color:#7a8a80;user-select:none;margin-bottom:4px"><?php esc_html_e('Shop questions', 'vereskala-login'); ?></summary>
                        <div style="display:flex;flex-wrap:wrap;gap:5px">
                            <?php foreach ($vkl_store_chips as $vkl_chip) : ?>
                                <button type="button" class="vkl-chat-chip"
                                        style="border:1px solid #cfe6d8;background:#f4fbf6;color:#157f3a;border-radius:999px;padding:4px 10px;font-size:12px;cursor:pointer;font-family:inherit"><?php echo esc_html($vkl_chip); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
                <?php if (!is_user_logged_in()) : // A logged-in customer is already identified by their account — never gated behind a phone number. ?>
                    <div id="vkl-chat-phone-gate" style="display:none;border-top:1px solid #e5e5e5">
                        <form id="vkl-chat-phone-form" style="display:flex">
                            <input id="vkl-chat-phone-input" type="tel" dir="ltr" maxlength="11" autocomplete="tel"
                                   placeholder="<?php esc_attr_e('Your mobile number', 'vereskala-login'); ?>"
                                   style="flex:1;border:0;padding:10px 12px;outline:none">
                            <button type="submit" style="border:0;background:#157f3a;color:#fff;padding:0 16px;cursor:pointer;white-space:nowrap"><?php esc_html_e('Confirm', 'vereskala-login'); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
                <form id="vkl-chat-form" style="display:none;border-top:1px solid #e5e5e5">
                    <input id="vkl-chat-input" type="text" maxlength="300" placeholder="<?php esc_attr_e('Type your question…', 'vereskala-login'); ?>"
                           style="flex:1;border:0;padding:10px 12px;outline:none" autocomplete="off">
                    <button type="submit" style="border:0;background:#157f3a;color:#fff;padding:0 16px;cursor:pointer"><?php esc_html_e('Send', 'vereskala-login'); ?></button>
                </form>
            </div>
        </div>
        <script>
            var vklChatOops = <?php echo wp_json_encode(__('Sorry — I could not answer that just now. Please try again.', 'vereskala-login')); ?>;
        (function () {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce(self::NONCE)); ?>;
            var context = <?php echo (int) $product_context; ?>;
            var greeting = <?php echo wp_json_encode($greeting); ?>;
            var toggle = document.getElementById('vkl-chat-toggle');
            var panel = document.getElementById('vkl-chat-panel');
            var log = document.getElementById('vkl-chat-log');
            var form = document.getElementById('vkl-chat-form');
            var input = document.getElementById('vkl-chat-input');
            var greeted = false;

            function bubble(text, mine) {
                var div = document.createElement('div');
                div.style.cssText = 'margin:6px 0;padding:8px 12px;border-radius:10px;max-width:85%;line-height:1.8;white-space:pre-wrap;word-break:break-word;' +
                    (mine ? 'background:#157f3a;color:#fff;margin-inline-start:auto' : 'background:#fff;border:1px solid #e2e2e2');
                // Linkify safely: text nodes + real anchors, no HTML injection.
                String(text).split(/(https?:\/\/[^\s]+)/g).forEach(function (part) {
                    if (/^https?:\/\//.test(part)) {
                        var a = document.createElement('a');
                        a.href = part;
                        a.textContent = part.length > 42 ? part.slice(0, 42) + '…' : part;
                        a.rel = 'noopener';
                        a.style.cssText = 'color:#157f3a;text-decoration:underline;word-break:break-all';
                        div.appendChild(a);
                    } else if (part) {
                        div.appendChild(document.createTextNode(part));
                    }
                });
                log.appendChild(div);
                log.scrollTop = log.scrollHeight;
                return div;
            }

            function chips(list) {
                var wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 8px';
                list.forEach(function (label) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = label;
                    b.style.cssText = 'border:1px solid #157f3a;color:#157f3a;background:#fff;border-radius:14px;padding:4px 12px;cursor:pointer;font-size:12px';
                    b.addEventListener('click', function () {
                        input.value = label;
                        form.dispatchEvent(new Event('submit', { cancelable: true }));
                    });
                    wrap.appendChild(b);
                });
                log.appendChild(wrap);
                log.scrollTop = log.scrollHeight;
            }

            function moreLink(url) {
                var a = document.createElement('a');
                a.href = url;
                a.textContent = <?php echo wp_json_encode(__('See more results', 'vereskala-login')); ?> + ' ←';
                a.style.cssText = 'display:block;margin:4px 0 8px;text-align:center;padding:8px;border-radius:10px;background:#eaf9ef;color:#157f3a;font-weight:600;text-decoration:none';
                log.appendChild(a);
                log.scrollTop = log.scrollHeight;
            }

            function productCards(items) {
                items.forEach(function (p) {
                    var a = document.createElement('a');
                    a.href = p.url;
                    a.style.cssText = 'display:flex;gap:8px;align-items:center;margin:6px 0;padding:8px;border:1px solid #e2e2e2;border-radius:10px;background:#fff;text-decoration:none;color:inherit';
                    if (p.image) {
                        var img = document.createElement('img');
                        img.src = p.image;
                        img.alt = '';
                        img.style.cssText = 'width:44px;height:44px;object-fit:cover;border-radius:6px';
                        a.appendChild(img);
                    }
                    var box = document.createElement('span');
                    var name = document.createElement('strong');
                    name.textContent = p.name;
                    var price = document.createElement('span');
                    price.textContent = p.price;
                    price.style.cssText = 'display:block;color:#157f3a';
                    box.appendChild(name);
                    box.appendChild(price);
                    a.appendChild(box);
                    log.appendChild(a);
                });
                log.scrollTop = log.scrollHeight;
            }

            // "Was this helpful?" under a bot reply. A 👎 hands the
            // conversation to a human — the session cookie identifies it
            // server-side, so nothing about it needs to travel from here.
            function feedbackRow(replyText) {
                var wrap = document.createElement('div');
                wrap.style.cssText = 'display:flex;gap:6px;margin:-2px 0 10px;justify-content:flex-end';
                var up = document.createElement('button');
                var down = document.createElement('button');
                var note = document.createElement('span');
                note.style.cssText = 'font-size:11px;color:#8a8a8a;align-self:center;margin-inline-end:4px';
                note.textContent = 'پاسخ مفید بود؟';
                [up, down].forEach(function (b) {
                    b.type = 'button';
                    b.style.cssText = 'border:1px solid #e2e2e2;background:#fff;border-radius:8px;padding:3px 10px;cursor:pointer;font-size:12.5px;white-space:nowrap';
                });
                // A bare thumbs-up/down glyph reads as decoration on a lot of
                // devices, not a clickable choice — spelling out "yes/no" next
                // to it makes the two buttons unambiguous either way.
                up.textContent = '👍 بله، مفید بود';
                down.textContent = '👎 نه، کمکی نکرد';

                function choose(helpful) {
                    up.disabled = true;
                    down.disabled = true;
                    up.style.opacity = helpful ? '1' : '.35';
                    down.style.opacity = helpful ? '.35' : '1';

                    var body = new URLSearchParams();
                    body.append('action', 'vkl_chatbot_feedback');
                    body.append('nonce', nonce);
                    body.append('helpful', helpful ? '1' : '0');
                    body.append('reply', replyText);

                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.success && res.data) {
                                if (res.data.text) { bubble(res.data.text, false); }
                                if (res.data.escalated) { startPolling(); }
                            }
                        })
                        .catch(function () {});
                }

                up.addEventListener('click', function () { choose(true); });
                down.addEventListener('click', function () { choose(false); });
                wrap.appendChild(note);
                wrap.appendChild(up);
                wrap.appendChild(down);
                log.appendChild(wrap);
                log.scrollTop = log.scrollHeight;
            }

            // Poll loop for staff replies once a conversation has been
            // handed to a human. Cheap enough to also fire once whenever
            // the panel opens (a returning visitor whose support agent
            // answered while they were away should see it without
            // needing to type anything) — it self-stops the moment the
            // server says nobody is waiting on a reply any more.
            var pollTimer = null;
            var lastStaffId = 0;

            function startPolling() {
                if (pollTimer) { return; }

                function tick() {
                    var body = new URLSearchParams();
                    body.append('action', 'vkl_chatbot_poll');
                    body.append('nonce', nonce);
                    body.append('after', String(lastStaffId));

                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res || !res.success || !res.data) { return; }

                            (res.data.messages || []).forEach(function (m) {
                                if (m.id > lastStaffId) { lastStaffId = m.id; }

                                var label = document.createElement('div');
                                label.textContent = '🧑‍💼 پشتیبان';
                                label.style.cssText = 'font-size:10.5px;color:#157f3a;margin:6px 0 -3px';
                                log.appendChild(label);
                                bubble(m.text, false);
                            });

                            if (!res.data.needs_human) { stopPolling(); }
                        })
                        .catch(function () {});
                }

                pollTimer = setInterval(tick, 5000);
                tick();
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            // Restore a user-chosen vertical position.
            var root = document.getElementById('vkl-chat');
            try {
                var savedBottom = parseInt(localStorage.getItem('vklChatBottom') || '', 10);
                if (!isNaN(savedBottom) && savedBottom >= 10 && savedBottom <= window.innerHeight - 80) {
                    root.style.setProperty('bottom', savedBottom + 'px', 'important');
                }
            } catch (e) {}

            // Drag the button vertically to reposition it; a small
            // movement still counts as a click.
            var dragStartY = 0, dragStartBottom = 0, dragging = false, moved = false;

            toggle.style.touchAction = 'none';
            toggle.addEventListener('pointerdown', function (e) {
                dragging = true;
                moved = false;
                dragStartY = e.clientY;
                dragStartBottom = parseInt(getComputedStyle(root).bottom, 10) || 20;
                toggle.setPointerCapture(e.pointerId);
            });
            toggle.addEventListener('pointermove', function (e) {
                if (!dragging) { return; }
                var delta = dragStartY - e.clientY;
                if (Math.abs(delta) > 8) { moved = true; }
                if (moved) {
                    var next = Math.min(Math.max(dragStartBottom + delta, 10), window.innerHeight - 80);
                    root.style.setProperty('bottom', next + 'px', 'important');
                }
            });
            toggle.addEventListener('pointerup', function () {
                if (dragging && moved) {
                    try { localStorage.setItem('vklChatBottom', String(parseInt(getComputedStyle(root).bottom, 10))); } catch (e) {}
                }
                dragging = false;
            });

            // A logged-in customer is already identified by their account —
            // the gate markup was not even rendered for them (see PHP
            // above) — so only a guest ever needs the phone cookie check.
            var vklChatLoggedIn = <?php echo wp_json_encode(is_user_logged_in()); ?>;
            var phoneOk = vklChatLoggedIn || (function() {
                try { return /^9\d{9}$/.test(document.cookie.match(/vkl_chat_phone=([^;]+)/)?.[1] || ''); } catch(e){ return false; }
            })();

            var phoneGate = document.getElementById('vkl-chat-phone-gate');
            var phoneForm = document.getElementById('vkl-chat-phone-form');
            var phoneInput = document.getElementById('vkl-chat-phone-input');

            function showChatInput() {
                if (phoneGate) { phoneGate.style.display = 'none'; }
                document.getElementById('vkl-chat-form').style.display = 'flex';
                input.focus();
            }
            function showPhoneGate() {
                if (!phoneGate) { showChatInput(); return; }
                document.getElementById('vkl-chat-form').style.display = 'none';
                phoneGate.style.display = 'block';
                phoneInput.focus();
            }

            if (phoneForm) {
                phoneForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var num = phoneInput.value.trim().replace(/^0/, '');
                if (!/^9\d{9}$/.test(num)) {
                    bubble(<?php echo wp_json_encode(__('Please enter a valid Iranian mobile number (e.g. 09131234567).', 'vereskala-login')); ?>, false);
                    return;
                }
                var body2 = new URLSearchParams();
                body2.append('action', 'vkl_chatbot');
                body2.append('nonce', nonce);
                body2.append('phone', phoneInput.value.trim());
                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body2 })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        phoneOk = true;
                        showChatInput();
                        if (res && res.data && res.data.text) { bubble(res.data.text, false); }
                    })
                    .catch(function(){ bubble(vklChatOops, false); });
                });
            }

            toggle.addEventListener('click', function () {
                if (moved) { moved = false; return; }
                var open = panel.style.display !== 'none';
                panel.style.display = open ? 'none' : 'block';
                if (!open && !greeted) {
                    greeted = true;
                    bubble(greeting, false);
                    if (phoneOk) { showChatInput(); } else { showPhoneGate(); }
                    // Catches a support reply that arrived while the visitor
                    // was away; self-stops right away when nothing is
                    // actually pending, so this costs nothing for the vast
                    // majority of chats that never reach a human.
                    startPolling();
                }
            });

            // A chip is just a pre-written question: fill the box and
            // submit through the same path, so there is one code path to
            // reason about rather than two.
            document.querySelectorAll('.vkl-chat-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    input.value = chip.textContent.trim();
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                });
            });

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var text = input.value.trim();
                if (!text) { return; }
                input.value = '';
                bubble(text, true);
                var waiting = bubble('…', false);

                var body = new URLSearchParams();
                body.append('action', 'vkl_chatbot');
                body.append('nonce', nonce);
                body.append('message', text);
                body.append('context', String(context));

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        waiting.remove();
                        if (!res || !res.success) {
                            bubble((res && res.data && res.data.text) ? res.data.text : vklChatOops, false);

                            return;
                        }
                        bubble(res.data.text, false);
                        if (res.data.products && res.data.products.length) { productCards(res.data.products); }
                        if (res.data.products && res.data.products.length && res.data.more) { moreLink(res.data.more); }
                        if (res.data.chips && res.data.chips.length) { chips(res.data.chips); }
                        if (res.data.context) { context = res.data.context; }
                        if (res.data.handled_by_human) {
                            // A human already owns this conversation — resume
                            // polling instead of asking whether an automated
                            // reply that never happened was helpful.
                            startPolling();
                        } else if (res.data.message_id) {
                            feedbackRow(res.data.text);
                        }
                    })
                    .catch(function () { waiting.remove(); bubble(vklChatOops, false); });
            });
        })();
        </script>
        <?php
    }

    /**
     * The best-matching learned answer for a customer's message, scored
     * by token overlap against every stored (question, answer) pair — the
     * same normalize()/tokenize() pipeline every other intent in this
     * class already uses, so a learned answer is held to the same bar as
     * a hand-written one. Returns null when nothing clears a sensible
     * confidence floor: a weak match is worse than no match at all, since
     * it would look authoritative (support-verified wording) while
     * actually answering the wrong question.
     *
     * @return array{text: string}|null
     */
    private function learned_answer(string $normalized_message): ?array
    {
        if (null === $this->chatlog) {
            return null;
        }

        $message_tokens = $this->tokenize($normalized_message);

        if (count($message_tokens) < 2) {
            return null;
        }

        $best_score = 0.0;
        $best_row   = null;

        foreach ($this->chatlog->learned_questions() as $row) {
            $question_tokens = $this->tokenize($this->normalize((string) ($row['question'] ?? '')));

            if ([] === $question_tokens) {
                continue;
            }

            $shared = count(array_intersect($message_tokens, $question_tokens));

            if (0 === $shared) {
                continue;
            }

            // Jaccard-style overlap: both sides have to mostly agree, not
            // just share one common word buried in a much longer question.
            $union = count(array_unique(array_merge($message_tokens, $question_tokens)));
            $score = $union > 0 ? $shared / $union : 0.0;

            if ($score > $best_score) {
                $best_score = $score;
                $best_row   = $row;
            }
        }

        // Roughly "at least half the meaningful words overlap" — high
        // enough that unrelated questions do not accidentally borrow each
        // other's answers, low enough that ordinary rewording still
        // matches.
        if (null === $best_row || $best_score < 0.45) {
            return null;
        }

        $this->chatlog->bump_learned_hit((int) $best_row['id']);

        return ['text' => (string) $best_row['answer']];
    }

    /**
     * Consulting answers drawn from the site's articles.
     *
     * Fires when the wording asks for guidance rather than a price, and
     * quotes the sentence of the article that actually mentions the
     * subject — a generic excerpt reads like a brochure and helps nobody.
     * Two matching products are appended so the advice is actionable.
     */
    private function advice_answer(string $message, string $needle): ?string
    {
        $asking = ['مشاوره', 'راهنمایی', 'پیشنهاد', 'چطور', 'چگونه', 'کدام', 'بهتره', 'بهتر است',
                   'مناسب', 'فرق', 'تفاوت', 'مقایسه', 'باید', 'توصیه', 'روش', 'آموزش', 'نکته', 'عوارض', 'مضر'];

        $wants_advice = false;

        foreach ($asking as $word) {
            if (false !== mb_strpos($message, $word)) {
                $wants_advice = true;

                break;
            }
        }

        if (!$wants_advice || '' === trim($needle)) {
            return null;
        }

        $articles = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 3,
            's'              => $needle,
        ]);

        if ([] === $articles) {
            return null;
        }

        $lines = [__('Here is what our articles say about that:', 'vereskala-login'), ''];

        foreach ($articles as $article) {
            $lines[] = '📚 ' . $article->post_title;
            $lines[] = $this->article_snippet($article, $needle);
            $lines[] = (string) get_permalink($article);
            $lines[] = '';
        }

        // Advice that cannot be acted on is only half an answer.
        if (function_exists('wc_get_products')) {
            $products = array_values(array_filter(
                wc_get_products(['status' => 'publish', 'limit' => 2, 's' => $needle]),
                static fn($p): bool => $p instanceof WC_Product
            ));

            if ([] !== $products) {
                $lines[] = __('Related products:', 'vereskala-login');

                foreach ($products as $product) {
                    $lines[] = '🛍 ' . $product->get_name() . ' — ' . wp_strip_all_tags((string) $product->get_price_html());
                    $lines[] = (string) $product->get_permalink();
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * The sentence of an article that mentions the subject (falls back to
     * the opening lines when nothing matches).
     */
    private function article_snippet(WP_Post $article, string $needle): string
    {
        $text = trim(wp_strip_all_tags(strip_shortcodes((string) $article->post_content)));

        if ('' === $text) {
            return wp_trim_words((string) $article->post_excerpt, 30, '…');
        }

        foreach (preg_split('/(?<=[.!؟?۔])\s+|\n+/u', $text) ?: [] as $sentence) {
            $sentence = trim($sentence);

            if (mb_strlen($sentence) > 25 && false !== mb_stripos($sentence, $needle)) {
                return wp_trim_words($sentence, 45, '…');
            }
        }

        return wp_trim_words($text, 34, '…');
    }
}
