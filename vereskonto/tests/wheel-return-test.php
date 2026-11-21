<?php
/**
 * Structural tests for the wheel return address (VKL_Wheel_Return).
 *
 * No WordPress needed:  php tests/wheel-return-test.php
 * Exit code 0 = all passed. Also run by smoke.py.
 *
 * Covers the crawler trap measured on vereskala.com (Nov 2026): wheel links
 * built from the current address wrapped the previous one in
 * vkl_wheel_return= over and over, 1 to 13 layers deep, in three encodings.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');

$root = dirname(__DIR__);

require $root . '/modules/smart/wheel-return.php';

const HOME    = 'https://vereskala.com';
const ACCOUNT = 'https://vereskala.com/konto/';
const PARAM   = 'vkl_wheel_return';

$failures = 0;
$checks   = 0;

function check(bool $ok, string $what): void
{
    global $failures, $checks;

    $checks++;

    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: $what\n");
    }
}

/** What the site would receive as $_GET[param] for ?param=<wrapped>. */
function as_get_value(string $url_value): string
{
    return rawurldecode($url_value); // PHP decodes the query string once
}

/** Wrap $inner in $layers wheel-return layers over $page, in encoding $mode. */
function wrap(string $inner, string $page, int $layers, string $mode): string
{
    $value = $inner;

    for ($i = 0; $i < $layers; $i++) {
        $encoded = match ($mode) {
            'plain'  => $value,
            'once'   => rawurlencode($value),
            default  => rawurlencode(rawurlencode($value)), // 'twice'
        };
        $value = $page . (str_contains($page, '?') ? '&' : '?') . PARAM . '=' . $encoded;
    }

    return $value;
}

/** Decode as far as it goes — what a crawler or a log reader would see. */
function fully_decoded(string $s): string
{
    for ($i = 0; $i < 30; $i++) {
        $d = rawurldecode($s);

        if ($d === $s) {
            break;
        }

        $s = $d;
    }

    return $s;
}

// ---------------------------------------------------------------- fixtures

// [the page that was being shown when the wheel link was built, inner target]
$pages = [
    'konto'               => ['https://vereskala.com/konto', ''],
    'konto/'              => ['https://vereskala.com/konto/', ''],
    'konto + add-to-cart' => ['https://vereskala.com/konto/?add-to-cart=4711', ''],
    'compare'             => ['https://vereskala.com/compare/?product_id=77', 'https://vereskala.com/compare/?product_id=77'],
    'product'             => ['https://vereskala.com/product/some-perfume/', 'https://vereskala.com/product/some-perfume/'],
    'product add-to-cart' => ['https://vereskala.com/product/some-perfume/?add-to-cart=9', 'https://vereskala.com/product/some-perfume/'],
    'category'            => ['https://vereskala.com/product-category/perfume/', 'https://vereskala.com/product-category/perfume/'],
    'inhalt'              => ['https://vereskala.com/inhalt/some-guide/', 'https://vereskala.com/inhalt/some-guide/'],
];

// 1) every variant, 1..13 layers, 3 encodings -> clean result, no param left
foreach ($pages as $name => [$page, $expected]) {
    foreach (['plain', 'once', 'twice'] as $mode) {
        for ($layers = 1; $layers <= 13; $layers++) {
            // The outermost request is /konto?param=<chain>; the value the
            // site reads is the chain's first value, decoded once by PHP.
            $chain = wrap($page, 'https://vereskala.com/konto/', $layers, $mode);
            $value = as_get_value((string) substr($chain, strlen('https://vereskala.com/konto/?' . PARAM . '=')));

            $got = VKL_Wheel_Return::clean($value, HOME . '/', ACCOUNT);
            $tag = "$name / $mode / $layers layers";

            check($got === $expected, "$tag -> expected '" . $expected . "' got '" . $got . "'");
            check(false === stripos(fully_decoded($got), PARAM), "$tag: result still contains the parameter");
            check(false === stripos($got, 'add-to-cart'), "$tag: result still contains add-to-cart");
            check(strlen($got) <= VKL_Wheel_Return::MAX_LEN, "$tag: result too long");
            check(VKL_Wheel_Return::clean($got, HOME . '/', ACCOUNT) === $got, "$tag: cleaning is not idempotent");
            check(!VKL_Wheel_Return::is_nested($got), "$tag: result reads as nested");
        }
    }
}

// 2) nesting beyond the safety limit is refused, not followed
$deep = as_get_value((string) substr(wrap('https://vereskala.com/compare/?product_id=1', 'https://vereskala.com/konto/', 20, 'plain'), strlen('https://vereskala.com/konto/?' . PARAM . '=')));
check('' === VKL_Wheel_Return::clean($deep, HOME . '/', ACCOUNT), '20 layers must be refused');
check(VKL_Wheel_Return::is_nested($deep), '20 layers must read as nested');

// 3) outside addresses never survive
$evil = [
    'https://evil.example/',
    'https://evil.example/https://vereskala.com/',
    'https://vereskala.com.evil.example/x',
    'https://vereskala.com@evil.example/x',
    'https://user:pass@vereskala.com/x',
    'http://127.0.0.1/admin',
    'javascript:alert(1)',
    '//evil.example/path',
    rawurlencode('https://evil.example/'),
    rawurlencode(rawurlencode('https://evil.example/')),
    'https://vereskala.com:8443/x',
];

foreach ($evil as $bad) {
    check('' === VKL_Wheel_Return::clean($bad, HOME . '/', ACCOUNT), "outside address accepted: $bad");
    check(HOME . '/' === VKL_Wheel_Return::clean($bad, HOME . '/', ACCOUNT, HOME . '/'), "outside address must fall back to home: $bad");
}

// 4) oversized values never survive
check('' === VKL_Wheel_Return::clean('https://vereskala.com/' . str_repeat('a', 600), HOME . '/', ACCOUNT), 'oversized address accepted');

// 5) ordinary one-level returns keep their meaning
check(
    'https://vereskala.com/product/x/?color=red' === VKL_Wheel_Return::clean('https://vereskala.com/product/x/?color=red', HOME . '/', ACCOUNT),
    'plain query parameters must survive'
);
check(
    'https://vereskala.com/product/x/?color=red' === VKL_Wheel_Return::clean('https://vereskala.com/product/x/?add-to-cart=3&color=red&vkl_wheel=1', HOME . '/', ACCOUNT),
    'add-to-cart and vkl_wheel must be dropped, the rest kept'
);
check(false === VKL_Wheel_Return::is_nested('https://vereskala.com/product/x/'), 'plain address must not read as nested');

// 5b) plain-permalink sites: the account page IS its query (?page_id=8)
check('' === VKL_Wheel_Return::clean('https://vereskala.com/?page_id=8&add-to-cart=1', HOME . '/', 'https://vereskala.com/?page_id=8'), 'plain-permalink account page must yield nothing');
check(
    'https://vereskala.com/?page_id=9' === VKL_Wheel_Return::clean('https://vereskala.com/?page_id=9', HOME . '/', 'https://vereskala.com/?page_id=8'),
    'another plain-permalink page must be kept'
);

// 6) static checks on the code that builds and reads the link
$wheel = (string) file_get_contents($root . '/modules/smart/wheel.php');

check(false === strpos($wheel, 'rawurlencode($return_to)'), 'wheel.php encodes the return value itself again (double encoding)');
check(1 === preg_match('/function current_return_url\(\)[^{]*\{[^}]*VKL_Wheel_Return::clean\(/s', $wheel), 'current_return_url() must go through VKL_Wheel_Return::clean()');

foreach (explode("
", $wheel) as $line) {
    if (!str_contains($line, 'customer_login_url(') || str_contains($line, 'function customer_login_url')) {
        continue;
    }

    check(
        str_contains($line, 'customer_login_url()') || str_contains($line, 'customer_login_url($this->current_return_url())'),
        'customer_login_url() called with an argument that is not the cleaned current address: ' . trim($line)
    );
}

check(str_contains($wheel, '<a id="vkl-wheel-login" href="<?php echo esc_url($login_url); ?>" rel="nofollow">'), 'wheel sign-in link needs rel="nofollow"');
check(str_contains($wheel, "'wp_robots'") && str_contains($wheel, 'X-Robots-Tag: noindex, nofollow'), 'noindex (wp_robots + X-Robots-Tag) missing');
check(str_contains($wheel, 'wp_safe_redirect($target, 301)'), 'guard must answer with a 301');

// The parameter name must only be handled inside the wheel files.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ('php' !== $file->getExtension()) {
        continue;
    }

    $name = $file->getFilename();

    if (in_array($name, ['wheel.php', 'wheel-return.php'], true)) {
        continue;
    }

    check(false === strpos((string) file_get_contents($file->getPathname()), PARAM), "$name handles the wheel return parameter outside the wheel files");
}

// ------------------------------------------------------------------ result

echo "$checks checks, $failures failure(s)\n";
exit($failures > 0 ? 1 : 0);
