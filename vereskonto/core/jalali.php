<?php
/**
 * Jalali (Persian) calendar — one shared implementation.
 *
 * Before 2.15.2 the conversion lived inside VKL_Wc_Account and the wallet
 * screens each formatted dates their own way (and in UTC, not shop time).
 * Everything new (the CRM) uses this class; VKL_Wc_Account::g2j()/j2g()
 * now delegate here so there is exactly one copy of the arithmetic.
 *
 * @package Vereskonto\Core
 * @since   2.15.2
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Jalali
 */
final class VKL_Jalali
{
    /**
     * Month names, index 0 = Farvardin.
     *
     * @var string[]
     */
    public const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'امرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /**
     * Gregorian → Jalali. Returns [jy, jm, jd].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function g2j(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
        $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy    = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy   += intdiv($days - 1, 365);
            $days  = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * Jalali → Gregorian. Returns [gy, gm, gd].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function j2g(int $jy, int $jm, int $jd): array
    {
        $jy   += 1595;
        $days  = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy    = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy   += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                ++$days;
            }
        }

        $gy   += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy   += intdiv($days - 1, 365);
            $days  = ($days - 1) % 365;
        }

        $gd   = $days + 1;
        $leap = ((0 === $gy % 4) && (0 !== $gy % 100)) || (0 === $gy % 400);
        $sal  = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm   = 0;

        while ($gm < 13 && $gd > $sal[$gm]) {
            $gd -= $sal[$gm];
            ++$gm;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * Format a stored UTC MySQL datetime in shop time as a Jalali date.
     *
     * Formats: 'long' → «۵ آذر ۱۴۰۵», 'short' → «1405/09/05»;
     * $with_time appends « ساعت 14:30».
     */
    public static function format(string $mysql_utc, string $style = 'long', bool $with_time = false): string
    {
        $mysql_utc = trim($mysql_utc);

        if ('' === $mysql_utc || 0 === strpos($mysql_utc, '0000-00-00')) {
            return '—';
        }

        $ts = strtotime($mysql_utc . ' UTC');

        if (false === $ts) {
            return '—';
        }

        $local = $ts + self::offset_seconds($ts);

        [$jy, $jm, $jd] = self::g2j((int) gmdate('Y', $local), (int) gmdate('n', $local), (int) gmdate('j', $local));

        $out = 'short' === $style
            ? sprintf('%04d/%02d/%02d', $jy, $jm, $jd)
            : sprintf('%d %s %d', $jd, self::MONTHS[$jm - 1] ?? '', $jy);

        if ($with_time) {
            $out .= ' ساعت ' . gmdate('H:i', $local);
        }

        return $out;
    }

    /**
     * Parse a Jalali date typed in a form (1405/09/05, ۱۴۰۵-۰۹-۰۵) into a
     * Gregorian 'Y-m-d', or '' when it is not a valid date.
     */
    public static function parse(string $value): string
    {
        $value = function_exists('vkl_latin_digits') ? vkl_latin_digits($value) : $value;

        if (1 !== preg_match('/^\s*(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\s*$/', $value, $m)) {
            return '';
        }

        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > ($jm <= 6 ? 31 : 30)) {
            return '';
        }

        [$gy, $gm, $gd] = self::j2g($jy, $jm, $jd);

        // Round-trip check catches Esfand 30 in a non-leap year.
        if (self::g2j($gy, $gm, $gd) !== [$jy, $jm, $jd]) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Shop timezone offset in seconds (WordPress setting), 0 outside WP.
     */
    private static function offset_seconds(int $ts): int
    {
        if (function_exists('wp_timezone')) {
            return (int) wp_timezone()->getOffset(new DateTimeImmutable("@" . $ts));
        }

        return 0;
    }
}
