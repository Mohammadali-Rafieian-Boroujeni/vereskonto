<?php
/**
 * Customer-facing connect UI: a "Connect Bale" button and per-channel
 * on/off preferences inside My Account → account details.
 *
 * @package Vereskonto\Modules\Notify
 * @since   1.48.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Notify_Account
 */
final class VKL_Notify_Account
{
    private VKL_Notify_Bale $bale;

    private VKL_Notify_Dispatcher $dispatcher;

    private VKL_Notify_Preferences $prefs;

    public function __construct(VKL_Notify_Bale $bale, VKL_Notify_Dispatcher $dispatcher, VKL_Notify_Preferences $prefs)
    {
        $this->bale       = $bale;
        $this->dispatcher = $dispatcher;
        $this->prefs      = $prefs;
    }

    public function register(): void
    {
        add_action('woocommerce_edit_account_form', [$this, 'render']);
        add_action('template_redirect', [$this, 'maybe_save']);
    }

    public function render(): void
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return;
        }

        echo '<fieldset style="margin-top:18px;border:1px solid #e2ece7;border-radius:12px;padding:14px 16px"><legend style="font-weight:700">' . esc_html__('Notifications', 'vereskala-login') . '</legend>';

        // Bale connection.
        if ($this->bale->is_enabled()) {
            if ($this->bale->is_connected($user_id)) {
                echo '<p style="color:#157f3a">✅ ' . esc_html__('Bale is connected — you receive invoices and updates there.', 'vereskala-login') . '</p>';
            } else {
                $link = $this->bale->connect_link($user_id);

                if ('' !== $link) {
                    echo '<p><a class="button" href="' . esc_url($link) . '" target="_blank" rel="noopener">💬 ' . esc_html__('Connect Bale (free notifications)', 'vereskala-login') . '</a></p>';
                    echo '<p class="description">' . esc_html__('Opens Bale and connects this account after you tap Start.', 'vereskala-login') . '</p>';
                }
            }
        }

        // Per-channel on/off.
        $disabled = $this->prefs->disabled_for($user_id);

        echo '<p style="margin-top:10px;font-weight:600">' . esc_html__('Where should we message you?', 'vereskala-login') . '</p>';

        foreach ($this->dispatcher->channels() as $channel) {
            $id  = $channel->id();
            $on  = !in_array($id, $disabled, true);
            echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="vkl_notify_on[]" value="' . esc_attr($id) . '" ' . checked($on, true, false) . '> ' . esc_html($channel->label()) . '</label>';
        }

        wp_nonce_field('vkl_notify_prefs', 'vkl_notify_nonce');

        echo '</fieldset>';
    }

    public function maybe_save(): void
    {
        if (!isset($_POST['vkl_notify_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vkl_notify_nonce'])), 'vkl_notify_prefs')) {
            return;
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return;
        }

        $on  = isset($_POST['vkl_notify_on']) ? array_map('sanitize_key', (array) wp_unslash($_POST['vkl_notify_on'])) : [];
        $all = array_keys($this->dispatcher->channels());

        // Disabled = every channel not ticked.
        $disabled = array_values(array_diff($all, $on));

        $this->prefs->set_disabled($user_id, $disabled);
    }
}
