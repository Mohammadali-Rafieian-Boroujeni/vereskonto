<?php
/**
 * Per-customer channel preferences: which channels a user allows and in
 * what priority. Stored in user meta as an ordered list of channel ids.
 * An empty/absent preference means "use the site default order".
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Preferences
 */
final class VKL_Notify_Preferences
{
    private const META_ORDER   = '_vkl_notify_order';
    private const META_DISABLED = '_vkl_notify_disabled';

    /**
     * Site-wide default priority (richest & cheapest first).
     *
     * @var string[]
     */
    private array $default_order;

    /**
     * @param string[] $default_order Channel ids in priority order.
     */
    public function __construct(array $default_order)
    {
        $this->default_order = $default_order;
    }

    /**
     * The channels a user permits, in priority order — the site default
     * minus anything the user switched off.
     *
     * @return string[]
     */
    public function order_for(int $user_id): array
    {
        $disabled = $this->disabled_for($user_id);

        $order = array_values(array_filter(
            $this->default_order,
            static fn(string $id): bool => !in_array($id, $disabled, true)
        ));

        return $order;
    }

    /**
     * Channel ids the user has switched off.
     *
     * @return string[]
     */
    public function disabled_for(int $user_id): array
    {
        $raw = get_user_meta($user_id, self::META_DISABLED, true);

        return is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    /**
     * Persist the user's disabled channels.
     *
     * @param string[] $disabled Channel ids to switch off.
     */
    public function set_disabled(int $user_id, array $disabled): void
    {
        update_user_meta($user_id, self::META_DISABLED, array_values(array_map('sanitize_key', $disabled)));
    }
}
