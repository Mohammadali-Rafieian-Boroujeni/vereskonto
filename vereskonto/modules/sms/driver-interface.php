<?php
/**
 * Contract every SMS gateway driver implements.
 *
 * Consumers must type-hint THIS, never a concrete driver — a consumer
 * tied to one concrete gateway throws a TypeError on every page load the
 * moment the gateway is swapped, which once took the whole site down.
 *
 * @package Vereskonto\Modules\Sms
 * @since   1.67.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface VKL_Sms_Driver
 */
interface VKL_Sms_Driver
{
    /**
     * Are the gateway credentials present?
     */
    public function is_configured(): bool;

    /**
     * Send a pattern message to one or more mobiles.
     *
     * @param string[]             $mobiles Recipients.
     * @param string               $pattern Pattern / template id.
     * @param array<string, mixed> $params  Pattern parameter values.
     *
     * @return array{ok: bool, response: string}
     */
    public function send_pattern(array $mobiles, string $pattern, array $params): array;

    /**
     * Whether this gateway offers a phonebook API at all. Callers check
     * this first so an unsupported gateway does not fill the SMS report
     * with failures on every registration.
     */
    public function supports_phonebook(): bool;

    /**
     * Add one number to the provider's phonebook.
     *
     * @return array{ok: bool, response: string}
     */
    public function add_contact(string $phonebook_id, string $mobile, string $name): array;
}
