<?php
/**
 * Shared building blocks that keep Vereskonto admin screens short:
 *
 *  - View tabs (?view=…): a screen shows one job at a time instead of
 *    every form stacked on one long page. render_views() prints the tab
 *    bar; current_view() reads the active one.
 *  - Modals: an action form (credit a wallet, create a plan) sits behind
 *    a button instead of permanently filling the page.
 *  - Section tabs (client side): for a screen whose settings are ONE
 *    options.php form, sections are shown one at a time in the browser.
 *    Every field stays inside the form, so saving never drops the
 *    settings of a hidden section (options.php writes null for any
 *    registered option missing from the POST).
 *  - Group strip: sibling screens of one menu group (the Inbox) are
 *    linked as tabs with their waiting-item counts.
 *  - After an admin-post action, the redirect returns to the view the
 *    form was submitted from, so no handler needs to know about views.
 *
 * @package Vereskonto\Core
 * @since   2.11.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Admin_Tabs
 */
final class VKL_Admin_Tabs
{
    /**
     * Screens whose h2.title sections become client-side tabs.
     */
    private const SECTION_TAB_PAGES = ['vkl-smart'];

    /**
     * Menu groups that get a tab strip linking their screens.
     */
    private const STRIP_GROUPS = ['inbox'];

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_filter('wp_redirect', [$this, 'keep_view_on_redirect'], 10, 1);
        add_action('in_admin_header', [$this, 'group_strip'], 11);
        add_action('admin_footer', [$this, 'footer_assets']);
    }

    /**
     * The active view: ?view= when it is one of $views, else the first.
     *
     * @param array<string, mixed> $views Keys are view slugs.
     */
    public static function current_view(array $views): string
    {
        $view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return isset($views[$view]) ? $view : (string) array_key_first($views);
    }

    /**
     * Tab bar for a screen's views.
     *
     * @param string                                      $page    Admin page slug.
     * @param array<string, string|array{0:string,1:int}> $views   slug => label, or slug => [label, count].
     * @param string                                      $current Active view slug.
     * @param array<string, scalar>                       $extra   Query args every tab keeps (e.g. customer id).
     */
    public static function render_views(string $page, array $views, string $current, array $extra = []): void
    {
        echo '<nav class="nav-tab-wrapper vkl-views" aria-label="' . esc_attr__('Sections', 'vereskala-login') . '">';

        foreach ($views as $slug => $view) {
            [$label, $count] = is_array($view) ? [$view[0], (int) $view[1]] : [$view, 0];
            $active          = $slug === $current;

            printf(
                '<a class="nav-tab%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
                $active ? ' nav-tab-active' : '',
                esc_url(add_query_arg(array_merge(['page' => $page], $extra, ['view' => $slug]), admin_url('admin.php'))),
                $active ? ' aria-current="page"' : '',
                esc_html($label),
                $count > 0 ? ' <span class="vkl-count">' . esc_html(number_format_i18n($count)) . '</span>' : ''
            );
        }

        echo '</nav>';
    }

    /**
     * Button that opens a modal.
     */
    public static function modal_button(string $id, string $label, string $class = 'button button-primary'): void
    {
        printf(
            '<button type="button" class="%1$s" data-vkl-modal="%2$s" aria-haspopup="dialog">%3$s</button>',
            esc_attr($class),
            esc_attr($id),
            esc_html($label)
        );
    }

    /**
     * Open a modal; everything printed until modal_end() is its body.
     * Not a native <dialog>: the WooCommerce customer search (select2)
     * opens its list on <body>, which a top-layer dialog would cover.
     */
    public static function modal_start(string $id, string $title): void
    {
        printf(
            '<div class="vkl-modal" id="%1$s" hidden role="dialog" aria-modal="true" aria-labelledby="%1$s-title">'
            . '<div class="vkl-modal-box"><div class="vkl-modal-head"><h2 id="%1$s-title">%2$s</h2>'
            . '<button type="button" class="vkl-modal-close" data-vkl-modal-close aria-label="%3$s">&times;</button></div>'
            . '<div class="vkl-modal-body">',
            esc_attr($id),
            esc_html($title),
            esc_attr__('Close', 'vereskala-login')
        );
    }

    public static function modal_end(): void
    {
        echo '</div></div></div>';
    }

    /**
     * After an admin-post action, keep the user on the view they were on:
     * if the redirect goes back to the same Vereskonto page without a
     * ?view=, carry the referring page's view over.
     */
    public function keep_view_on_redirect($location)
    {
        if (!is_string($location) || 'admin-post.php' !== ($GLOBALS['pagenow'] ?? '')) {
            return $location;
        }

        $referer = wp_get_referer();

        if (!is_string($referer) || '' === $referer) {
            return $location;
        }

        $to   = self::query_of($location);
        $from = self::query_of($referer);

        if (empty($to['page']) || isset($to['view']) || empty($from['view'])) {
            return $location;
        }

        if ((string) $to['page'] !== (string) ($from['page'] ?? '') || !self::is_ours((string) $to['page'])) {
            return $location;
        }

        return add_query_arg('view', sanitize_key((string) $from['view']), $location);
    }

    /**
     * @return array<string, mixed>
     */
    private static function query_of(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $args  = [];
        parse_str($query, $args);

        return $args;
    }

    private static function is_ours(string $page): bool
    {
        return VKL_Admin_Menu::PARENT === $page || 0 === strpos($page, 'vkl-');
    }

    private static function current_page(): string
    {
        return isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Tab strip across the screens of a menu group (e.g. Inbox: tickets,
     * chatbot conversations, return requests), with waiting counts.
     */
    public function group_strip(): void
    {
        $page = self::current_page();

        if ('' === $page || !self::is_ours($page)) {
            return;
        }

        foreach (VKL_Admin_Menu::groups() as $group) {
            if (!in_array($group['key'], self::STRIP_GROUPS, true) || !isset($group['items'][$page])) {
                continue;
            }

            $counts = VKL_Admin_Menu::counts();

            echo '<nav class="vkl-group-strip" aria-label="' . esc_attr($group['label']) . '">';

            foreach ($group['items'] as $slug => $label) {
                $count = (int) ($counts[$slug] ?? 0);
                printf(
                    '<a href="%1$s" class="%2$s"%3$s>%4$s%5$s</a>',
                    esc_url(admin_url('admin.php?page=' . $slug)),
                    $slug === $page ? 'is-active' : '',
                    $slug === $page ? ' aria-current="page"' : '',
                    esc_html($label),
                    $count > 0 ? ' <span class="vkl-count">' . esc_html(number_format_i18n($count)) . '</span>' : ''
                );
            }

            echo '</nav>';

            return;
        }
    }

    /**
     * Styles and behaviour for views, modals, section tabs and the strip.
     */
    public function footer_assets(): void
    {
        if (!self::is_ours(self::current_page())) {
            return;
        }

        $section_tabs = in_array(self::current_page(), self::SECTION_TAB_PAGES, true);
        ?>
        <style id="vkl-admin-tabs">
            .vkl-views{margin:14px 0 18px}
            .vkl-views .nav-tab{border-radius:8px 8px 0 0}
            .vkl-count{display:inline-block;min-width:18px;padding:0 6px;margin-inline-start:4px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:18px;text-align:center;font-weight:600}
            .vkl-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:4px 0 16px}
            .vkl-modal{position:fixed;inset:0;z-index:100001;background:rgba(16,24,32,.45);display:flex;align-items:flex-start;justify-content:center;padding:8vh 16px 16px;overflow:auto}
            .vkl-modal[hidden]{display:none}
            .vkl-modal-box{background:#fff;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.25);width:100%;max-width:640px}
            .vkl-modal-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #e2e2e2}
            .vkl-modal-head h2{margin:0;font-size:16px}
            .vkl-modal-close{background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:#50575e;padding:2px 6px;border-radius:6px}
            .vkl-modal-close:hover,.vkl-modal-close:focus-visible{background:#f0f0f1;outline:none}
            .vkl-modal-body{padding:6px 18px 16px}
            .vkl-modal-body .form-table{border:0 !important;box-shadow:none !important;padding:0 !important}
            .vkl-modal-body form{border:0 !important;padding:0 !important;max-width:none !important;box-shadow:none !important}
            body.vkl-modal-open{overflow:hidden}
            .select2-container--open{z-index:100002 !important}
            .vkl-group-strip{display:flex;flex-wrap:wrap;gap:6px;margin:14px 20px 0 0;padding:0}
            .vkl-group-strip a{padding:7px 14px;border-radius:999px;background:#fff;border:1px solid #dcdcde;text-decoration:none;color:#1d2327;font-size:13px}
            .vkl-group-strip a:hover{border-color:#157f3a;color:#157f3a}
            .vkl-group-strip a.is-active{background:#157f3a;border-color:#157f3a;color:#fff;font-weight:600}
            .vkl-group-strip a.is-active .vkl-count{background:#fff;color:#157f3a}
            .vkl-sec-tabs{margin:12px 0 0}
            .vkl-sec-panel[hidden]{display:none}
        </style>
        <script>
        (function () {
            var lastFocus = null;

            function openModal(m) {
                lastFocus = document.activeElement;
                m.hidden = false;
                document.body.classList.add('vkl-modal-open');
                var f = m.querySelector('input:not([type=hidden]),select,textarea,button:not(.vkl-modal-close)');
                if (f) { f.focus(); }
            }

            function closeModal(m) {
                m.hidden = true;
                document.body.classList.remove('vkl-modal-open');
                if (lastFocus) { lastFocus.focus(); }
            }

            document.addEventListener('click', function (e) {
                var opener = e.target.closest('[data-vkl-modal]');
                if (opener) {
                    var m = document.getElementById(opener.getAttribute('data-vkl-modal'));
                    if (m) { e.preventDefault(); openModal(m); }
                    return;
                }
                var modal = e.target.closest('.vkl-modal');
                if (modal && (e.target === modal || e.target.closest('[data-vkl-modal-close]'))) {
                    closeModal(modal);
                }
            });

            document.addEventListener('keydown', function (e) {
                if ('Escape' !== e.key) { return; }
                // Let an open select2 list close first.
                if (document.querySelector('.select2-container--open')) { return; }
                document.querySelectorAll('.vkl-modal:not([hidden])').forEach(closeModal);
            });

            <?php if ($section_tabs) : ?>
            // Section tabs: each h2.title inside the settings form starts
            // a panel; the submit row stays visible under every panel.
            var form = document.querySelector('.wrap form[action="options.php"]');
            if (!form) { return; }

            var heads = Array.prototype.filter.call(form.children, function (el) {
                return el.matches('h2.title');
            });
            if (heads.length < 2) { return; }

            var key = 'vkl-sec-tab:' + location.search.replace(/[?&](settings-updated|_wpnonce)=[^&]*/g, '');
            var bar = document.createElement('nav');
            bar.className = 'nav-tab-wrapper vkl-sec-tabs';
            bar.setAttribute('role', 'tablist');
            form.insertBefore(bar, heads[0]);

            var panels = heads.map(function (h2, i) {
                var panel = document.createElement('div');
                panel.className = 'vkl-sec-panel';
                panel.id = 'vkl-sec-panel-' + i;
                panel.setAttribute('role', 'tabpanel');
                form.insertBefore(panel, h2);

                var node = h2;
                while (node && !(node !== h2 && node.matches && node.matches('h2.title')) && !(node.matches && node.matches('p.submit'))) {
                    var next = node.nextElementSibling;
                    panel.appendChild(node);
                    node = next;
                }

                var tab = document.createElement('a');
                tab.href = '#' + panel.id;
                tab.className = 'nav-tab';
                tab.setAttribute('role', 'tab');
                tab.textContent = h2.textContent.trim();
                tab.addEventListener('click', function (e) { e.preventDefault(); show(i); });
                bar.appendChild(tab);

                return panel;
            });

            // Content after the form (e.g. the AI connection test) belongs
            // to the section whose heading has data-vkl-trailing (else the first).
            var after = form.nextElementSibling;
            var trailing = [];
            while (after) { trailing.push(after); after = after.nextElementSibling; }
            var owner = Math.max(0, heads.findIndex(function (h2) { return h2.hasAttribute('data-vkl-trailing'); }));

            function show(i) {
                panels.forEach(function (p, j) {
                    p.hidden = j !== i;
                    bar.children[j].classList.toggle('nav-tab-active', j === i);
                    bar.children[j].setAttribute('aria-selected', j === i ? 'true' : 'false');
                });
                trailing.forEach(function (el) { el.hidden = owner !== i; });
                try { sessionStorage.setItem(key, String(i)); } catch (err) {}
            }

            var start = 0;
            try { start = parseInt(sessionStorage.getItem(key) || '0', 10) || 0; } catch (err) {}

            // A field the browser rejects on submit must be visible.
            form.addEventListener('invalid', function (e) {
                var p = e.target.closest('.vkl-sec-panel');
                if (p && p.hidden) { show(panels.indexOf(p)); }
            }, true);

            show(start < panels.length ? start : 0);
            <?php endif; ?>
        })();
        </script>
        <?php
    }
}
