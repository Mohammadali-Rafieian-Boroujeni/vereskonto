<?php
/**
 * Plain-PHP test (no WordPress): every view of the tabbed admin screens
 * actually renders — no PHP error, warning or notice, and balanced
 * <div>/<form>/<table>/<details> tags — plus the shared tab helpers.
 *
 * Collaborators are stand-ins that return empty data, so this checks the
 * screen structure, not real figures. Run: php tests/admin-views-test.php
 */

declare(strict_types=1);

require __DIR__ . '/lib/admin-bootstrap.php';
require VKL_PATH . 'modules/wallet/admin.php';
require VKL_PATH . 'modules/loyalty/admin.php';
require VKL_PATH . 'modules/smart/ai.php';
require VKL_PATH . 'modules/smart/wheel.php';
require VKL_PATH . 'modules/smart/admin.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
};

/** Render a callback; return [html, error message or '']. */
$render = static function (callable $fn): array {
    ob_start();
    try {
        $fn();
        return [(string) ob_get_clean(), ''];
    } catch (Throwable $e) {
        ob_end_clean();
        return ['', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
    }
};

$balanced = static function (string $html): string {
    $html = preg_replace('~<script\b.*?</script>~s', '', $html);
    foreach (['div', 'form', 'table', 'details', 'nav'] as $tag) {
        $open  = preg_match_all('~<' . $tag . '\b~i', $html);
        $close = preg_match_all('~</' . $tag . '>~i', $html);
        if ($open !== $close) { return "$tag $open/$close"; }
    }
    return '';
};

$stub   = new VKL_Test_Stub();
$wallet = new VKL_Wallet_Admin(new VKL_Wallet_Service(), new VKL_Wallet_Repository(), new VKL_Security(), new VKL_Config(), new VKL_Wallet_Installments());
$club   = new VKL_Loyalty_Admin(new VKL_Config(), new VKL_Security(), new VKL_Loyalty_Repository(), new VKL_Survey_Repository());
$smart  = new VKL_Smart_Admin(new VKL_Config(), new VKL_Smart_Chatlog());

$screens = [
    ['vkl-wallet', [$wallet, 'render'], ['transactions', 'festival', 'settings'], 'vkl_wallet_adjust', 'transactions'],
    ['vkl-credit-customers', [$wallet, 'render_credit_customers'], ['due', 'customers', 'requests', 'accounting', 'settings'], 'vkl_installment_create', null],
    ['vkl-loyalty', [$club, 'render'], ['members', 'surveys', 'settings'], null, null],
];

foreach ($screens as [$page, $fn, $views, $modal_action, $modal_view]) {
    foreach ($views as $view) {
        $_GET = ['page' => $page, 'view' => $view];
        [$html, $err] = $render($fn);
        $check('' === $err, "$page › $view renders" . ('' !== $err ? " — $err" : ''));
        if ('' !== $err) { continue; }
        $check('' === $balanced($html), "$page › $view tags balanced " . $balanced($html));
        $check(1 === preg_match('~nav-tab-active"[^>]*href="[^"]*view=' . $view . '"|href="[^"]*view=' . $view . '"[^>]*aria-current~', $html), "$page › $view tab marked active");
        if (null !== $modal_action && (null === $modal_view || $modal_view === $view)) {
            $check(str_contains($html, 'data-vkl-modal=') && str_contains($html, 'value="' . $modal_action . '"'), "$page › $view action form sits in a modal");
        }
    }
}

// Each view shows only its own sections.
$_GET = ['page' => 'vkl-wallet', 'view' => 'transactions'];
[$html] = $render([$wallet, 'render']);
$check(!str_contains($html, 'value="vkl_wallet_settings"'), 'wallet › transactions has no settings form');
$_GET = ['page' => 'vkl-wallet', 'view' => 'settings'];
[$html] = $render([$wallet, 'render']);
$check(str_contains($html, 'value="vkl_wallet_settings"') && !str_contains($html, 'vkl_wallet_adjust'), 'wallet › settings shows only the settings form');
$_GET = ['page' => 'vkl-wallet', 'view' => 'nonsense'];
[$html] = $render([$wallet, 'render']);
$check(str_contains($html, 'vkl_wallet_adjust'), 'unknown view falls back to the first tab');

// Smart shop: still ONE settings form (section tabs are client side).
$_GET = ['page' => 'vkl-smart'];
[$html, $err] = $render([$smart, 'render']);
$check('' === $err, 'vkl-smart renders' . ('' !== $err ? " — $err" : ''));
$check('' === $err && 1 === substr_count($html, 'action="options.php"'), 'vkl-smart keeps a single options.php form');
$check('' === $err && substr_count($html, '<h2 class="title"') >= 5 && str_contains($html, 'data-vkl-trailing'), 'vkl-smart has the section headings the tabs are built from');

// ---- Helpers ---------------------------------------------------------------
$GLOBALS['pagenow'] = 'admin-post.php';
$tabs = new VKL_Admin_Tabs();
$GLOBALS['test_referer'] = admin_url('admin.php?page=vkl-wallet&view=festival');
$check(str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=vkl-wallet&vkl_promo=3')), 'view=festival'), 'redirect returns to the submitting view');
$check(!str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=vkl-credit-customers')), 'view='), 'redirect to another page is left alone');
$check(str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=vkl-wallet&view=settings')), 'view=settings') && !str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=vkl-wallet&view=settings')), 'festival'), 'explicit view in a redirect wins');
$GLOBALS['test_referer'] = 'https://shop.test/wp-admin/edit.php?page=other&view=x';
$check(!str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=other')), 'view='), 'non-Vereskonto pages are left alone');
$GLOBALS['pagenow'] = 'admin.php';
$GLOBALS['test_referer'] = admin_url('admin.php?page=vkl-wallet&view=festival');
$check(!str_contains($tabs->keep_view_on_redirect(admin_url('admin.php?page=vkl-wallet')), 'view='), 'only admin-post redirects are touched');

$_GET = ['page' => 'vkl-tickets'];
[$html, $err] = $render([$tabs, 'group_strip']);
$check('' === $err && 3 === substr_count($html, '<a ') && str_contains($html, 'class="is-active"'), 'inbox strip links its three screens' . ('' !== $err ? " — $err" : ''));
$_GET = ['page' => 'vkl-wallet'];
[$html] = $render([$tabs, 'group_strip']);
$check('' === $html, 'no inbox strip outside the inbox');

foreach (['vkl-smart', 'vkl-wallet'] as $vkl_page) {
    $_GET = ['page' => $vkl_page];
    [$html, $err] = $render([$tabs, 'footer_assets']);
    $check('' === $err && str_contains($html, 'vkl-modal'), "footer assets print on $vkl_page" . ('' !== $err ? " — $err" : ''));
}
$check(str_contains($render(static function () use ($tabs) { $_GET = ['page' => 'vkl-smart']; $tabs->footer_assets(); })[0], 'vkl-sec-panel-'), 'section-tab script only on Smart shop (present)');
$check(!str_contains($render(static function () use ($tabs) { $_GET = ['page' => 'vkl-wallet']; $tabs->footer_assets(); })[0], 'vkl-sec-panel-'), 'section-tab script only on Smart shop (absent elsewhere)');

exit($failures > 0 ? 1 : 0);
