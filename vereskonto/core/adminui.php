<?php
/**
 * Shared admin UI for all Vereskonto screens.
 *
 * Injects (only on this plugin's pages): a header band with the plugin
 * name, version and a tab bar linking every Vereskonto screen, plus a
 * stylesheet that makes the existing settings markup (form-table,
 * widefat) consistent and pleasant — cards, spacing, RTL-safe.
 *
 * No page markup was changed; everything is additive via hooks, so all
 * existing screens automatically pick up the new look.
 *
 * @package Vereskonto\Core
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Admin_Ui
 */
final class VKL_Admin_Ui
{
    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('admin_head', [$this, 'styles']);
        add_action('in_admin_header', [$this, 'header']);
        add_action('admin_footer', [$this, 'accordions']);
    }

    /**
     * Section accordions:
     *  - Finance page (vkl-wallet): every section is grouped into TWO
     *    parent panels — Wallet vs Credit — using the vkl-sec-* classes
     *    stamped on the headings, which also untangles their DOM order.
     *  - SMS Notifications (vkl-notifications): each titled section
     *    collapses on its own so the page stays tidy.
     */
    public function accordions(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return;
        }

        $id = (string) $screen->id;

        // Styling applies to every screen of this plugin; only the
        // accordion grouping below is limited to the two pages that were
        // built around it. Before, the other screens inherited nothing and
        // looked like a different product.
        $is_ours = false !== strpos($id, 'vkl-') || false !== strpos($id, 'vereskala-login');

        if (!$is_ours) {
            return;
        }

        // The wallet screen is split into view tabs since 2.11.0 (VKL_Admin_Tabs);
        // its old two-panel accordion would fold the tabbed content again.
        $accordion = false !== strpos($id, 'vkl-notifications');
        ?>
        <style>
            /* ---- Shared admin styling -------------------------------
             * One place for type scale, spacing, controls and contrast,
             * so every screen looks like the same product instead of a
             * pile of one-off inline styles.
             * Scoped to .wrap on our own pages only.
             */
            :root{
                --vkl-green:#157f3a; --vkl-green-soft:#f1faf4; --vkl-line:#dceee2;
                --vkl-ink:#1f2933; --vkl-muted:#5b6b7a; --vkl-danger:#b32d2e;
                --vkl-radius:12px;
            }
            .wrap{font-size:14px;line-height:1.75;color:var(--vkl-ink)}
            .wrap h1{font-size:23px;font-weight:800;margin:12px 0 18px;letter-spacing:-.2px}
            .wrap h2.title{font-size:16px;font-weight:800;color:var(--vkl-green);margin:26px 0 10px}
            .wrap h3{font-size:15px;font-weight:700;margin:18px 0 8px}
            .wrap p.description{font-size:13px;color:var(--vkl-muted);line-height:1.85}

            /* Tables: quieter grid, roomier rows, readable headers. */
            .wrap table.widefat{border:1px solid var(--vkl-line);border-radius:var(--vkl-radius);overflow:hidden;box-shadow:none}
            .wrap table.widefat thead th{background:var(--vkl-green-soft);color:var(--vkl-green);font-weight:800;font-size:13px;padding:11px 12px}
            .wrap table.widefat td,.wrap table.widefat th{padding:11px 12px;vertical-align:middle}
            .wrap table.widefat tbody tr:hover{background:#fafdfb}

            /* Form rows: labels that do not crowd their fields. */
            .wrap .form-table th{font-weight:700;font-size:13.5px;padding:14px 10px 14px 0;width:230px}
            .wrap .form-table td{padding:12px 10px}
            .wrap .form-table input[type=text],
            .wrap .form-table input[type=number],
            .wrap .form-table input[type=password],
            .wrap .form-table input[type=email],
            .wrap .form-table select,
            .wrap .form-table textarea{
                border-radius:8px;border:1px solid #c9d6cf;padding:7px 10px;font-size:14px;
                background:#fff;color:var(--vkl-ink);
            }
            .wrap .form-table input:focus,
            .wrap .form-table select:focus,
            .wrap .form-table textarea:focus{
                border-color:var(--vkl-green);outline:2px solid rgba(21,127,58,.18);outline-offset:1px;box-shadow:none
            }

            /* Buttons: one consistent shape, with a visible focus ring so
             * the page stays usable from the keyboard. */
            .wrap .button{border-radius:8px;font-weight:600;padding:4px 14px;height:auto;line-height:2.1}
            .wrap .button-primary{background:var(--vkl-green);border-color:var(--vkl-green);text-shadow:none;box-shadow:none}
            .wrap .button-primary:hover{background:#0f6a30;border-color:#0f6a30}
            .wrap .button:focus-visible,
            .wrap a:focus-visible,
            .wrap summary:focus-visible{outline:2px solid var(--vkl-green);outline-offset:2px}

            /* Numbers read better aligned and non-italic in both scripts. */
            .wrap td[dir=ltr],.wrap code{font-variant-numeric:tabular-nums}
            .wrap code{background:#f4f6f5;border-radius:6px;padding:2px 6px;font-size:12.5px}

            /* Keep long screens readable on wide monitors. */
            .wrap > .notice,.wrap table.widefat,.wrap .form-table{max-width:1140px}

            @media (max-width:782px){
                .wrap .form-table th{width:auto;padding-bottom:4px}
                .wrap table.widefat thead th{font-size:12.5px}
            }

            details.vkl-acc{border:1px solid #dceee2;border-radius:14px;margin:0 0 14px;background:#fff;max-width:1140px;overflow:hidden}
            details.vkl-acc > summary{cursor:pointer;list-style:none;padding:13px 18px;font-weight:800;font-size:15px;color:#157f3a;background:#f1faf4;user-select:none;display:flex;align-items:center;gap:8px}
            details.vkl-acc > summary::-webkit-details-marker{display:none}
            details.vkl-acc > summary::after{content:'▾';margin-inline-start:auto;transition:transform .2s}
            details.vkl-acc[open] > summary::after{transform:rotate(180deg)}
            details.vkl-acc > .vkl-acc-body{padding:6px 18px 16px}
            details.vkl-acc h2.title{margin-top:14px}
        </style>
        <script>
        (function () {
            var wrap = document.querySelector('.wrap');
            if (!wrap) { return; }

            function sectionOf(h2) {
                // The heading plus everything until the next section heading.
                // The form's save row (p.submit) is NEVER swept into a
                // collapsible — it must stay visible below all sections.
                var nodes = [h2];
                var n = h2.nextSibling;
                while (n && !(n.nodeType === 1 && n.matches && n.matches('h2.title, p.submit, .submit'))) {
                    var next = n.nextSibling;
                    nodes.push(n);
                    n = next;
                }
                return nodes;
            }

            function makePanel(label, open) {
                var d = document.createElement('details');
                d.className = 'vkl-acc';
                if (open) { d.open = true; }
                var sm = document.createElement('summary');
                sm.textContent = label;
                var body = document.createElement('div');
                body.className = 'vkl-acc-body';
                d.appendChild(sm);
                d.appendChild(body);
                return d;
            }

            if (!<?php echo $accordion ? 'true' : 'false'; ?>) { return; }

            var isWallet = <?php echo false !== strpos($id, 'vkl-wallet') ? 'true' : 'false'; ?>;
            var heads = Array.prototype.slice.call(wrap.querySelectorAll('h2.title'));
            if (!heads.length) { return; }

            if (isWallet) {
                // The credit sections moved to the Loans screen, so that
                // panel is usually empty now. Build it only when something
                // is actually going into it — an empty accordion that
                // opens onto nothing is worse than no accordion.
                var hasCredit = heads.some(function (h2) {
                    return h2.classList.contains('vkl-sec-credit');
                });

                var pw = makePanel('💳 <?php echo esc_js(__('Wallet', 'vereskala-login')); ?>', true);
                heads[0].parentNode.insertBefore(pw, heads[0]);

                var pc = null;

                if (hasCredit) {
                    pc = makePanel('🛒 <?php echo esc_js(__('Purchase credit & installments', 'vereskala-login')); ?>', false);
                    pw.parentNode.insertBefore(pc, pw.nextSibling);
                }

                heads.forEach(function (h2) {
                    var target = (pc && h2.classList.contains('vkl-sec-credit')) ? pc : pw;
                    sectionOf(h2).forEach(function (node) {
                        target.lastElementChild.appendChild(node);
                    });
                });
            } else {
                heads.forEach(function (h2) {
                    var panel = makePanel(h2.textContent.trim(), false);
                    h2.parentNode.insertBefore(panel, h2);
                    var nodes = sectionOf(h2);
                    h2.style.display = 'none';
                    nodes.forEach(function (node) { panel.lastElementChild.appendChild(node); });
                });
                var first = document.querySelector('details.vkl-acc');
                if (first) { first.open = true; }

                // Guaranteed save button: if the page's form lost its
                // visible submit for any reason, add one at the end.
                document.querySelectorAll('.wrap form[action="options.php"]').forEach(function (form) {
                    var btn = form.querySelector('p.submit, .submit, input[type=submit], button[type=submit]');
                    if (btn) {
                        // Ensure it sits AFTER the accordions, not inside one.
                        var holder = btn.closest('p.submit') || btn;
                        form.appendChild(holder);
                        return;
                    }
                    var p = document.createElement('p');
                    p.className = 'submit';
                    var s2 = document.createElement('input');
                    s2.type = 'submit';
                    s2.className = 'button button-primary';
                    s2.value = '<?php echo esc_js(__('Save', 'vereskala-login')); ?>';
                    p.appendChild(s2);
                    form.appendChild(p);
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Whether the current screen belongs to Vereskonto.
     */
    private function is_vkl_screen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return false;
        }

        $id = (string) $screen->id;

        return false !== strpos($id, 'vereskala-login') || false !== strpos($id, 'vkl-');
    }

    /**
     * Navigation structure for the top tab bar, built from the shared
     * VKL_Admin_Menu groups so the tab bar and the WordPress sidebar can
     * never drift apart. A group with one screen becomes a direct link,
     * a larger group a dropdown.
     *
     * Each entry is either:
     *   [label => string, slug => string]                 — a direct link
     *   [label => string, children => array<int, entry>]  — a dropdown
     *
     * @return array<int, array<string, mixed>>
     */
    private function nav(): array
    {
        $nav = [];

        foreach (VKL_Admin_Menu::groups() as $group) {
            $children = [];

            foreach ($group['items'] as $slug => $label) {
                if (VKL_Admin_Menu::can_open((string) $slug)) {
                    $children[] = ['label' => $label, 'slug' => $slug];
                }
            }

            if (1 === count($children)) {
                $nav[] = $children[0];
            } elseif ($children) {
                $nav[] = ['label' => $group['label'], 'children' => $children];
            }
        }

        return $nav;
    }

    /**
     * Whether an entry (direct link or group) contains the current page.
     *
     * @param array<string, mixed> $entry
     */
    private function nav_is_active(array $entry, string $current): bool
    {
        if (isset($entry['slug'])) {
            return $entry['slug'] === $current;
        }

        foreach ($entry['children'] as $child) {
            if ($child['slug'] === $current) {
                return true;
            }
        }

        return false;
    }

    /**
     * Header band with the grouped tab navigation.
     */
    public function header(): void
    {
        if (!$this->is_vkl_screen()) {
            return;
        }

        $current = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="vkl-header">
            <div class="vkl-header-brand">
                <span class="vkl-header-dot"></span>
                <strong>Vereskonto</strong>
                <span class="vkl-header-version">v<?php echo esc_html(VKL_VERSION); ?></span>
            </div>
            <nav class="vkl-tabs">
                <?php foreach ($this->nav() as $entry) : ?>
                    <?php $active = $this->nav_is_active($entry, $current); ?>
                    <?php if (isset($entry['slug'])) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $entry['slug'])); ?>"
                           class="<?php echo $active ? 'vkl-tab-active' : ''; ?>"><?php echo esc_html($entry['label']); ?></a>
                    <?php else : ?>
                        <div class="vkl-tab-group">
                            <button type="button" class="vkl-tab-parent <?php echo $active ? 'vkl-tab-active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                                <?php echo esc_html($entry['label']); ?> <span class="vkl-tab-caret">▾</span>
                            </button>
                            <div class="vkl-tab-dropdown">
                                <?php foreach ($entry['children'] as $child) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $child['slug'])); ?>"
                                       class="<?php echo $child['slug'] === $current ? 'vkl-tab-active' : ''; ?>"><?php echo esc_html($child['label']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>
        <script>
        (function () {
            var groups = document.querySelectorAll('.vkl-tab-group');

            function closeAll(except) {
                groups.forEach(function (g) {
                    if (g === except) { return; }
                    g.classList.remove('vkl-open');
                    g.querySelector('.vkl-tab-parent').setAttribute('aria-expanded', 'false');
                });
            }

            groups.forEach(function (group) {
                var btn = group.querySelector('.vkl-tab-parent');

                // Click toggles (works everywhere, including touch —
                // hover alone would leave touch users unable to open a
                // group at all).
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var willOpen = !group.classList.contains('vkl-open');
                    closeAll(willOpen ? group : null);
                    group.classList.toggle('vkl-open', willOpen);
                    btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                });
            });

            document.addEventListener('click', function () { closeAll(); });
            document.addEventListener('keydown', function (e) {
                if ('Escape' === e.key) { closeAll(); }
            });
        })();
        </script>
        <?php
    }

    /**
     * Stylesheet, printed only on Vereskonto screens.
     */
    public function styles(): void
    {
        if (!$this->is_vkl_screen()) {
            return;
        }
        ?>
        <style>
            .vkl-header{background:linear-gradient(135deg,#22a84d,#0e5f29);color:#fff;margin-inline-start:-20px;padding:16px 24px 0;border-bottom:3px solid #e5262c}
            .vkl-header-brand{display:flex;align-items:center;gap:8px;font-size:16px;padding-bottom:12px}
            .vkl-header-dot{width:10px;height:10px;border-radius:50%;background:#e5262c;box-shadow:0 0 8px rgba(229,38,44,.8)}
            .vkl-header-version{opacity:.75;font-size:12px;background:rgba(255,255,255,.15);border-radius:10px;padding:2px 8px}
            /* Sticky brand/tab bar: stays visible while long pages scroll. */
            .vkl-header{position:sticky;top:32px;z-index:99;box-shadow:0 4px 14px rgba(16,42,34,.18)}
            @media screen and (max-width:782px){.vkl-header{top:46px}}
            @media screen and (max-width:600px){.vkl-header{top:0}}
            /* ---- 1.21.0 design pass: one calm, card-based language on every tab. ---- */
            .wrap h1{font-size:22px;font-weight:800;color:#124e27}
            .wrap h2.title{font-size:16px;color:#157f3a;border-inline-start:4px solid #157f3a;padding-inline-start:10px;margin-top:26px}
            .wrap .widefat{border:1px solid #dceee2;border-radius:12px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.05);border-spacing:0}
            .wrap .widefat thead th{background:#f1faf4;color:#124e27;font-weight:700;border-bottom:1px solid #dceee2}
            .wrap .widefat tbody tr:hover{background:#f4fbf6}
            .wrap .widefat td,.wrap .widefat th{padding:10px 14px;vertical-align:middle}
            .wrap .button-primary{background:#157f3a;border-color:#0e5f29;box-shadow:0 3px 10px rgba(30,111,92,.25);border-radius:8px}
            .wrap .button-primary:hover{background:#157f3a;border-color:#0e5f29}
            .wrap .button{border-radius:8px}
            .wrap input[type=text],.wrap input[type=number],.wrap input[type=date],.wrap input[type=time],.wrap select,.wrap textarea{border-radius:8px;border-color:#cfe6d6;box-shadow:none}
            .wrap input[type=text]:focus,.wrap input[type=number]:focus,.wrap select:focus,.wrap textarea:focus{border-color:#157f3a;box-shadow:0 0 0 2px rgba(30,111,92,.15)}
            .wrap details > summary{outline:none;user-select:none}
            .wrap details[open]{background:#f7fdf9;border-radius:8px;padding:4px 8px;margin:-4px -8px}
            .wrap .notice{border-radius:10px;border-inline-start-width:4px}
            .wrap .form-table th{color:#124e27}
            .wrap .description{color:#6d7773}
            /* ---- 1.30.0 polish: calmer rhythm, softer depth, clearer hierarchy. ---- */
            #wpbody-content .wrap{max-width:1180px}
            .wrap h2.title{display:flex;align-items:center;gap:8px;font-size:15.5px;letter-spacing:.2px}
            .wrap .widefat{box-shadow:0 1px 3px rgba(16,42,34,.06),0 6px 18px rgba(16,42,34,.05)}
            .wrap .widefat thead th{font-size:12.5px;text-transform:none;letter-spacing:.3px}
            .wrap .widefat tbody td{font-size:13px}
            .wrap .button{transition:transform .08s ease, box-shadow .15s ease}
            .wrap .button:active{transform:scale(.97)}
            .wrap .button.button-small{border-radius:999px;background:#eaf9ef;border-color:#c9edd5;color:#157f3a}
            .wrap .button.button-small:hover{background:#d8f3e1}
            .wrap form[style*="border:1px solid #e2e2e2"]{border-color:#dceee2 !important;box-shadow:0 1px 3px rgba(16,42,34,.05)}
            .wrap details.vkl-acc > summary:hover{background:#eaf9ef}
            .wrap .form-table th{padding-top:14px;font-size:13px}
            .wrap .form-table td{padding-top:10px}
            .wrap input[type=number]{text-align:center}
            .wrap .notice{box-shadow:0 1px 4px rgba(16,42,34,.06)}
            .wrap code{background:#eaf6ee;border-radius:6px;padding:1px 6px;color:#0e5f29}
            .wrap details > summary:focus-visible{outline:2px solid #157f3a;outline-offset:2px;border-radius:6px}
            .vkl-tabs{display:flex;flex-wrap:wrap;gap:2px;position:relative}
            .vkl-tabs a{color:#fff;text-decoration:none;padding:9px 14px;border-radius:8px 8px 0 0;font-size:13px;opacity:.85}
            .vkl-tabs a:hover{background:rgba(255,255,255,.12);opacity:1}
            .vkl-tabs a.vkl-tab-active{background:#f0f0f1;color:#157f3a;font-weight:600;opacity:1}

            /* Grouped (dropdown) tabs: a parent button + an absolutely
             * positioned panel of children, opened by click (touch-safe)
             * and by hover/focus for mouse and keyboard users alike. */
            .vkl-tab-group{position:relative}
            .vkl-tab-parent{display:flex;align-items:center;gap:4px;color:#fff;background:transparent;border:0;cursor:pointer;
                padding:9px 14px;border-radius:8px 8px 0 0;font-size:13px;opacity:.85;font-family:inherit}
            .vkl-tab-parent:hover{background:rgba(255,255,255,.12);opacity:1}
            .vkl-tab-parent.vkl-tab-active{background:#f0f0f1;color:#157f3a;font-weight:600;opacity:1}
            .vkl-tab-caret{font-size:10px;transition:transform .15s ease}
            .vkl-tab-group.vkl-open .vkl-tab-caret{transform:rotate(180deg)}
            .vkl-tab-dropdown{display:none;position:absolute;inset-inline-start:0;top:100%;min-width:190px;
                background:#fff;border-radius:0 0 10px 10px;box-shadow:0 10px 24px rgba(16,42,34,.18);
                padding:6px;z-index:100;flex-direction:column;gap:1px}
            .vkl-tab-group:hover .vkl-tab-dropdown,
            .vkl-tab-group:focus-within .vkl-tab-dropdown,
            .vkl-tab-group.vkl-open .vkl-tab-dropdown{display:flex}
            .vkl-tab-dropdown a{color:#33403a;opacity:1;border-radius:6px;padding:8px 12px;font-size:13px}
            .vkl-tab-dropdown a:hover{background:#f1faf4;color:#157f3a}
            .vkl-tab-dropdown a.vkl-tab-active{background:#eaf9ef;color:#157f3a;font-weight:600}
            @media (max-width:782px){
                .vkl-tab-dropdown{position:static;box-shadow:none;background:rgba(0,0,0,.12);border-radius:8px;margin:2px 0 6px}
                .vkl-tab-group:hover .vkl-tab-dropdown,
                .vkl-tab-group:focus-within .vkl-tab-dropdown{display:none}
                .vkl-tab-group.vkl-open .vkl-tab-dropdown{display:flex}
                .vkl-tab-dropdown a{color:#fff}
                .vkl-tab-dropdown a:hover{background:rgba(255,255,255,.15);color:#fff}
            }
            .vkl-header + #wpbody-content .wrap > h1:first-of-type,
            #wpbody-content .wrap > h1:first-of-type{margin-top:18px}
            body[class*="vkl-"] .wrap form > .form-table,
            body[class*="vereskala-login"] .wrap form > .form-table,
            body[class*="vkl-"] .wrap > form,
            body[class*="vkl-"] .wrap h2.title{max-width:960px}
            body[class*="vkl-"] h2.title,
            body[class*="vereskala-login"] h2.title{border-inline-start:4px solid #157f3a;padding-inline-start:10px;margin-top:28px}
            body[class*="vkl-"] .form-table,
            body[class*="vereskala-login"] .form-table{background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:6px 16px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
            body[class*="vkl-"] .form-table th{width:240px}
            body[class*="vkl-"] table.widefat,
            body[class*="vereskala-login"] table.widefat{border-radius:10px;overflow:hidden;border:1px solid #e2e2e2;box-shadow:0 1px 3px rgba(0,0,0,.04)}
            body[class*="vkl-"] table.widefat thead th{background:#f1faf4;font-weight:600}
            body[class*="vkl-"] .form-table .description{color:#667}
            body[class*="vkl-"] details > summary{cursor:pointer;font-weight:600;color:#157f3a}
            @media (max-width:782px){.vkl-header{margin-inline-start:-10px;padding-inline:12px}.vkl-tabs a{padding:8px 10px}}
        </style>
        <?php
    }
}
