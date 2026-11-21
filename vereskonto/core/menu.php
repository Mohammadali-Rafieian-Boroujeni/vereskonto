<?php
/**
 * One source of truth for the Vereskonto admin navigation.
 *
 * Each module still registers its own screen with add_submenu_page()
 * (slug, capability and callback stay where they are). This class only
 * decides ORDER, GROUPING and LABELS, and applies them to both places
 * the shop owner navigates from:
 *   - the WordPress sidebar submenu (rebuilt late on admin_menu), with a
 *     small group heading above the first item of each group;
 *   - the green tab bar at the top of every Vereskonto screen
 *     (VKL_Admin_Ui reads groups() instead of keeping its own list).
 *
 * Groups follow the shop's daily jobs, most frequent first: handle what
 * customers are waiting on, money, marketing, reports, then settings.
 * A screen a module registers that is not listed here is kept and shown
 * at the end, so a new module never disappears from the menu.
 *
 * @package Vereskonto\Core
 * @since   2.10.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Admin_Menu
 */
final class VKL_Admin_Menu
{
    public const PARENT = 'vereskala-login';

    /**
     * Ordered groups of screens.
     *
     * @return array<int, array{key: string, label: string, items: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            [
                'key'   => 'today',
                'label' => __('Dashboard', 'vereskala-login'),
                'items' => [
                    'vkl-today' => __('Today', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'inbox',
                'label' => __('Inbox', 'vereskala-login'),
                'items' => [
                    'vkl-tickets'       => __('Tickets', 'vereskala-login'),
                    'vkl-chatbot-inbox' => __('Chatbot conversations', 'vereskala-login'),
                    'vkl-returns'       => __('Return requests', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'crm',
                'label' => __('CRM', 'vereskala-login'),
                'items' => [
                    'vkl-crm-contacts' => __('Contacts', 'vereskala-login'),
                    'vkl-crm-deals'    => __('Deals', 'vereskala-login'),
                    'vkl-crm-tasks'    => __('Tasks', 'vereskala-login'),
                    'vkl-crm-orders'   => __('Customer orders', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'customers',
                'label' => __('Customers', 'vereskala-login'),
                'items' => [
                    'vkl-customers' => __('Customers', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'finance',
                'label' => __('Wallet & credit', 'vereskala-login'),
                'items' => [
                    'vkl-wallet'           => __('Wallet', 'vereskala-login'),
                    'vkl-credit-customers' => __('Installments & credit', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'marketing',
                'label' => __('Marketing', 'vereskala-login'),
                'items' => [
                    'vkl-loyalty'   => __('Customer club', 'vereskala-login'),
                    'vkl-campaigns' => __('SMS Campaigns', 'vereskala-login'),
                    'vkl-cart'      => __('Cart Tracking', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'reports',
                'label' => __('Reports', 'vereskala-login'),
                'items' => [
                    'vkl-history'   => __('Sales report', 'vereskala-login'),
                    'vkl-cancelled' => __('Cancelled orders', 'vereskala-login'),
                    'vkl-analytics' => __('Site visits', 'vereskala-login'),
                    'vkl-sms-logs'  => __('SMS log', 'vereskala-login'),
                ],
            ],
            [
                'key'   => 'settings',
                'label' => __('Settings', 'vereskala-login'),
                'items' => [
                    self::PARENT        => __('General settings', 'vereskala-login'),
                    'vkl-messages'      => __('All messages', 'vereskala-login'),
                    'vkl-notifications' => __('SMS Notifications', 'vereskala-login'),
                    'vkl-smart'         => __('Smart shop', 'vereskala-login'),
                    'vkl-crm-settings'  => __('CRM settings', 'vereskala-login'),
                    'vkl-status'        => __('System status', 'vereskala-login'),
                ],
            ],
        ];
    }

    /**
     * Whether the current user may open a screen of the plugin menu, by
     * the capability that screen was registered with. CRM staff, for
     * example, only reach the CRM screens, so the tab bar must not offer
     * them links that end in "not allowed".
     */
    public static function can_open(string $slug): bool
    {
        global $submenu;

        foreach ((array) ($submenu[self::PARENT] ?? []) as $item) {
            if (isset($item[1], $item[2]) && $slug === (string) $item[2]) {
                return current_user_can((string) $item[1]);
            }
        }

        // Unknown screen (module off): only administrators see it.
        return current_user_can('manage_options');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        // After every module (status registers at 99) has added its page.
        add_action('admin_menu', [$this, 'reorder'], 999);
        add_action('admin_head', [$this, 'sidebar_styles']);
    }

    /**
     * Rebuild the plugin's sidebar submenu in group order with the
     * shared labels. Badges a module appended to its own title (e.g. the
     * pending-returns count) are carried over.
     */
    public function reorder(): void
    {
        global $submenu;

        if (empty($submenu[self::PARENT]) || !is_array($submenu[self::PARENT])) {
            return;
        }

        $by_slug = [];

        foreach ($submenu[self::PARENT] as $item) {
            if (isset($item[2])) {
                $by_slug[(string) $item[2]] = $item;
            }
        }

        $ordered = [];
        $counts  = self::counts();

        foreach (self::groups() as $group) {
            foreach ($group['items'] as $slug => $label) {
                if (!isset($by_slug[$slug])) {
                    continue; // Module disabled or not loaded.
                }

                $item  = $by_slug[$slug];
                $badge = self::badge((string) $item[0]);

                if ('' === $badge && !empty($counts[$slug])) {
                    $badge = ' <span class="awaiting-mod">' . (int) $counts[$slug] . '</span>';
                }

                $item[0] = esc_html($label) . $badge;
                $ordered[] = $item;
                unset($by_slug[$slug]);
            }
        }

        // Anything not listed above keeps its own label, at the end.
        foreach ($by_slug as $item) {
            $ordered[] = $item;
        }

        $submenu[self::PARENT] = $ordered; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    /**
     * Items waiting on the shop, per screen: open tickets (last word from
     * the customer), chatbot conversations asking for a human, pending
     * return requests. Computed once per request; a module that is off
     * simply contributes nothing.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        static $counts = null;

        if (null !== $counts) {
            return $counts;
        }

        $counts  = [];
        $sources = [
            'vkl-tickets'       => ['ticket.repository', 'open_count'],
            'vkl-chatbot-inbox' => ['smart.chatlog', 'needing_human_count'],
            'vkl-returns'       => ['returns.repository', 'pending_count'],
            'vkl-crm-tasks'     => ['crm.task_service', 'badge_count'],
        ];

        if (!function_exists('vkl')) {
            return $counts;
        }

        foreach ($sources as $slug => [$service, $method]) {
            try {
                if (vkl()->has($service)) {
                    $object = vkl($service);

                    if (is_object($object) && is_callable([$object, $method])) {
                        $counts[$slug] = (int) $object->{$method}();
                    }
                }
            } catch (Throwable $e) {
                // A badge is never worth breaking the admin menu.
                unset($counts[$slug]);
            }
        }

        return $counts;
    }

    /**
     * The badge markup (<span class="awaiting-mod">3</span>) inside a
     * menu title, or ''.
     */
    private static function badge(string $title): string
    {
        if (preg_match('~\s*<span class="(?:awaiting-mod|update-plugins)[^"]*">.*?</span>(?:\s*</span>)?~s', $title, $m)) {
            return ' ' . trim($m[0]);
        }

        return '';
    }

    /**
     * Group headings in the sidebar: a small caption above the first
     * screen of each group. Pure CSS on the existing links, so WordPress
     * keeps rendering (and highlighting) the menu as usual.
     */
    public function sidebar_styles(): void
    {
        $sel = '#toplevel_page_' . self::PARENT . ' .wp-submenu';
        $css = '';

        foreach (self::groups() as $i => $group) {
            if (count($group['items']) < 2) {
                continue; // A heading over a single screen only repeats its name.
            }

            $first = (string) array_key_first($group['items']);
            $label = str_replace(['\\', '"', '<', '>'], ['\\\\', '\\"', '', ''], $group['label']);
            $css  .= sprintf(
                '%1$s li:has(> a[href$="page=%2$s"])::before{content:"%3$s"}',
                $sel,
                esc_attr($first),
                $label
            );
        }
        ?>
        <style id="vkl-menu-groups">
            <?php echo $sel; // phpcs:ignore ?> li::before{display:block;padding:10px 12px 2px;font-size:11px;font-weight:600;letter-spacing:.02em;color:#8c9399;pointer-events:none}
            <?php echo $sel; // phpcs:ignore ?> li.wp-first-item::before{padding-top:4px}
            <?php echo $css; // phpcs:ignore -- labels escaped above. ?>
        </style>
        <?php
    }
}
