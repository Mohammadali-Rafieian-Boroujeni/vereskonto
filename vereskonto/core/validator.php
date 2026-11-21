<?php
/**
 * Validator.
 *
 * Central input validation. Mobile normalisation/validation delegates to
 * the stable 1.0 functions in includes/functions.php — one source of
 * truth, zero duplicated logic, zero risk to SMS login.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Validator
 */
final class VKL_Validator
{
    /**
     * Normalise a mobile number to local 09xxxxxxxxx form.
     *
     * @param string $mobile Raw input.
     */
    public function normalize_mobile(string $mobile): string
    {
        return (string) vkl_normalize_mobile($mobile);
    }

    /**
     * Whether a value is a valid Iranian mobile number.
     *
     * @param string $mobile Normalised mobile.
     */
    public function is_mobile(string $mobile): bool
    {
        return (bool) vkl_is_mobile($mobile);
    }

    /**
     * Clamp an integer into a range.
     *
     * @param mixed $value Raw value.
     * @param int   $min   Minimum.
     * @param int   $max   Maximum.
     */
    public function int_in_range(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /**
     * Whether a value is one of an allowed set.
     *
     * @param mixed    $value   Raw value.
     * @param string[] $allowed Allowed values.
     * @param string   $default Fallback.
     */
    public function one_of(mixed $value, array $allowed, string $default): string
    {
        $value = (string) $value;

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
