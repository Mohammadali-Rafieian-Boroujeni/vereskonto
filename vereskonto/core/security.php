<?php
/**
 * Security helpers.
 *
 * Capability checks and nonce verification for platform admin surfaces.
 * The stable 1.0 login nonces ('vkl_nonce') are untouched.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Security
 */
final class VKL_Security
{
    /**
     * Capability required for platform administration.
     */
    public const ADMIN_CAP = 'manage_options';

    /**
     * Nonce action for platform admin forms.
     */
    public const ADMIN_NONCE = 'vkl_platform_admin';

    /**
     * Whether the current user may administer the platform.
     */
    public function current_user_can_admin(): bool
    {
        return current_user_can(self::ADMIN_CAP);
    }

    /**
     * Render a nonce field for platform admin forms.
     */
    public function admin_nonce_field(): void
    {
        wp_nonce_field(self::ADMIN_NONCE, '_vkl_nonce');
    }

    /**
     * Verify an admin form submission (capability + nonce). Dies on failure.
     */
    public function verify_admin_request(): void
    {
        if (!$this->current_user_can_admin()) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $nonce = isset($_POST['_vkl_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['_vkl_nonce']))
            : '';

        if (!wp_verify_nonce($nonce, self::ADMIN_NONCE)) {
            wp_die(esc_html__('Security check failed.', 'vereskala-login'));
        }
    }

    /**
     * Mask a mobile number for display/logging (0912***4567).
     *
     * @param string $mobile Normalised mobile.
     */
    public function mask_mobile(string $mobile): string
    {
        if (strlen($mobile) < 8) {
            return $mobile;
        }

        return substr($mobile, 0, 4) . '***' . substr($mobile, -4);
    }
}
