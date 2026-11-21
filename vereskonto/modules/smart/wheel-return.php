<?php
/**
 * Return-address hygiene for the spin-the-wheel sign-in link.
 *
 * The wheel sends a visitor who is not signed in to the account page with
 * the page they came from in `vkl_wheel_return`. Built from the CURRENT
 * address, that parameter used to wrap itself: on /konto/?vkl_wheel_return=…
 * the next link carried the whole previous address inside a new one, and so
 * on without end — an unbounded set of unique, uncacheable URLs that
 * crawlers followed by the hundred thousand.
 *
 * Everything here is a pure string function (no WordPress calls), so it is
 * unit-testable on the command line: see tests/wheel-return-test.php.
 *
 * @package Vereskonto\Modules\Smart
 * @since   2.9.33
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Wheel_Return
 */
final class VKL_Wheel_Return
{
    public const PARAM = 'vkl_wheel_return';

    /** Parameters that must never travel inside a return address. */
    private const STRIP = ['vkl_wheel_return', 'add-to-cart', 'vkl_wheel'];

    /** Deepest nesting honoured; anything deeper is not a real page. */
    public const MAX_LAYERS = 15;

    /** Longest return address kept (characters). */
    public const MAX_LEN = 500;

    /**
     * Percent-decode until the text is an address (starts with http:// or
     * https://) or stops changing — handles plain, once- and twice-encoded
     * values alike.
     */
    public static function decode_until_url(string $value): string
    {
        for ($i = 0; $i < self::MAX_LAYERS; $i++) {
            if (1 === preg_match('~^https?://~i', $value)) {
                return $value;
            }

            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                return $value;
            }

            $value = $decoded;
        }

        return $value;
    }

    /**
     * Split a query string into [key, value] pairs, decoded once, order
     * and duplicates preserved.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function pairs(string $query): array
    {
        $out = [];

        foreach (explode('&', $query) as $piece) {
            if ('' === $piece) {
                continue;
            }

            $eq    = strpos($piece, '=');
            $key   = urldecode(false === $eq ? $piece : substr($piece, 0, $eq));
            $value = false === $eq ? '' : urldecode(substr($piece, $eq + 1));

            if ('' !== $key) {
                $out[] = [$key, $value];
            }
        }

        return $out;
    }

    /**
     * Value of the return parameter inside an address, or null.
     */
    private static function param_of(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        foreach (self::pairs($query) as [$key, $value]) {
            if (self::PARAM === $key) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Does this value contain another return parameter inside it?
     */
    public static function is_nested(string $raw): bool
    {
        return null !== self::param_of(self::decode_until_url($raw));
    }

    /**
     * Peel the wrapping off: the innermost address that carries no return
     * parameter of its own. '' when the nesting is deeper than MAX_LAYERS
     * (not a real page) or the value is empty.
     */
    public static function innermost(string $raw): string
    {
        $current = $raw;

        for ($i = 0; $i < self::MAX_LAYERS; $i++) {
            $current = self::decode_until_url($current);
            $inner   = self::param_of($current);

            if (null === $inner) {
                return $current;
            }

            $current = $inner;
        }

        return '';
    }

    /**
     * Same address without the parameters that must not be carried.
     * Rebuilt from decoded parts, so the result is stable under a second pass.
     *
     * @param array<string, mixed> $parts parse_url() parts (scheme and host present).
     */
    private static function rebuild(array $parts): string
    {
        $path = (string) ($parts['path'] ?? '');
        $path = '' === $path ? '/' : implode('/', array_map('rawurlencode', explode('/', rawurldecode($path))));

        $query = [];

        if (isset($parts['query']) && is_string($parts['query'])) {
            foreach (self::pairs($parts['query']) as [$key, $value]) {
                if (!in_array($key, self::STRIP, true)) {
                    $query[] = rawurlencode($key) . '=' . rawurlencode($value);
                }
            }
        }

        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host'])
            . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
            . $path
            . ([] !== $query ? '?' . implode('&', $query) : '');
    }

    /**
     * Is $url the account (login) page itself — same host, same path (a
     * trailing slash does not matter) and the same query? The query counts
     * because on a plain-permalink site the page IS its query (?page_id=8).
     */
    private static function is_account_page(string $url, string $account_url): bool
    {
        $a = parse_url($url);
        $b = parse_url($account_url);

        if (!is_array($a) || !is_array($b)) {
            return false;
        }

        $query = static function (array $parts): array {
            $pairs = [];

            foreach (self::pairs((string) ($parts['query'] ?? '')) as [$key, $value]) {
                if (!in_array($key, self::STRIP, true)) {
                    $pairs[] = $key . '=' . $value;
                }
            }

            sort($pairs);

            return $pairs;
        };

        return strtolower((string) ($a['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''))
            && rtrim(rawurldecode((string) ($a['path'] ?? '/')), '/') === rtrim(rawurldecode((string) ($b['path'] ?? '/')), '/')
            && $query($a) === $query($b);
    }

    /**
     * Turn any raw return value into a safe, minimal return address.
     *
     * Guarantees about the result (checked by the tests):
     *  - never contains `vkl_wheel_return` (even after any amount of decoding);
     *  - is on this site's own host, http or https, without credentials;
     *  - carries no `add-to-cart` (a return must not add to the cart again);
     *  - is no longer than MAX_LEN;
     *  - is not the account page itself (returning to the login page is pointless);
     *  - cleaning it again gives the same result.
     * Anything else yields $fallback.
     *
     * @param string $raw         Raw value (any encoding, any nesting).
     * @param string $home_url    This site's home URL.
     * @param string $account_url The account (login) page URL, or ''.
     * @param string $fallback    Returned when no valid address remains.
     */
    public static function clean(string $raw, string $home_url, string $account_url = '', string $fallback = ''): string
    {
        $url = self::innermost($raw);

        if ('' === $url) {
            return $fallback;
        }

        $parts = parse_url($url);
        $home  = parse_url($home_url);

        if (!is_array($parts) || !is_array($home) || !isset($parts['scheme'], $parts['host'], $home['host'])) {
            return $fallback;
        }

        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || strtolower((string) $parts['host']) !== strtolower((string) $home['host'])
            || ($parts['port'] ?? null) !== ($home['port'] ?? null)
        ) {
            return $fallback;
        }

        $clean = self::rebuild($parts);

        if (strlen($clean) > self::MAX_LEN) {
            return $fallback;
        }

        if ('' !== $account_url && self::is_account_page($clean, $account_url)) {
            return $fallback;
        }

        return $clean;
    }
}
