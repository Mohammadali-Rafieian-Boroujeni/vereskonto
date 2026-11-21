<?php
/**
 * Configuration layer.
 *
 * Single access point for plugin options. Prevents magic option strings
 * from spreading across modules and controls autoload behaviour.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Config
 */
final class VKL_Config
{
    /**
     * Option name prefix.
     */
    private const PREFIX = 'vkl_';

    /**
     * Default values, keyed by unprefixed name.
     *
     * @var array<string, mixed>
     */
    private array $defaults;

    /**
     * Per-request cache.
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * @param array<string, mixed> $defaults Default values.
     */
    public function __construct(array $defaults = [])
    {
        $this->defaults = $defaults;
    }

    /**
     * Merge additional defaults (used by modules on registration).
     *
     * @param array<string, mixed> $defaults Defaults to merge.
     */
    public function add_defaults(array $defaults): void
    {
        $this->defaults = array_merge($this->defaults, $defaults);
    }

    /**
     * Get an option value.
     *
     * @param string $key     Unprefixed key, e.g. 'api_key'.
     * @param mixed  $default Fallback when neither option nor default exists.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $fallback = $this->defaults[$key] ?? $default;
        $value    = get_option(self::PREFIX . $key, $fallback);

        $this->cache[$key] = $value;

        return $value;
    }

    /**
     * Get an option as trimmed string.
     *
     * @param string $key     Unprefixed key.
     * @param string $default Fallback.
     */
    public function get_string(string $key, string $default = ''): string
    {
        return trim((string) $this->get($key, $default));
    }

    /**
     * Get an option as integer.
     *
     * @param string $key     Unprefixed key.
     * @param int    $default Fallback.
     */
    public function get_int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    /**
     * Get an option as boolean ('1', 1, true => true).
     *
     * @param string $key     Unprefixed key.
     * @param bool   $default Fallback.
     */
    public function get_bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return in_array($value, [true, 1, '1', 'yes', 'on'], true);
    }

    /**
     * Persist an option value. Non-critical platform options are stored
     * with autoload disabled to keep the alloptions cache small.
     *
     * @param string $key      Unprefixed key.
     * @param mixed  $value    Value to store.
     * @param bool   $autoload Whether WordPress should autoload the option.
     */
    public function set(string $key, mixed $value, bool $autoload = false): void
    {
        update_option(self::PREFIX . $key, $value, $autoload);

        $this->cache[$key] = $value;
    }

    /**
     * Full option name for a key (for register_setting etc.).
     *
     * @param string $key Unprefixed key.
     */
    public function option_name(string $key): string
    {
        return self::PREFIX . $key;
    }
}
