<?php
/**
 * Shared presentation helpers for the CRM screens: labels, dates, URLs,
 * notices and small widgets. One copy for every CRM page.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Ui
 */
final class VKL_Crm_Ui
{
    public const CONTACTS_PAGE = 'vkl-crm-contacts';
    public const SETTINGS_PAGE = 'vkl-crm-settings';

    /** @return array<string, string> */
    public static function lifecycles(): array
    {
        return [
            'lead'     => __('Lead', 'vereskala-login'),
            'prospect' => __('Prospect', 'vereskala-login'),
            'customer' => __('Customer', 'vereskala-login'),
            'inactive' => __('Inactive', 'vereskala-login'),
            'lost'     => __('Lost', 'vereskala-login'),
        ];
    }

    /**
     * Known lead sources. Any other stored key is shown as-is.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return (array) apply_filters('vkl_crm_sources', [
            'manual'      => __('Entered by staff', 'vereskala-login'),
            'signup'      => __('Website sign-up', 'vereskala-login'),
            'guest_order' => __('Guest checkout', 'vereskala-login'),
            'phone'       => __('Phone call', 'vereskala-login'),
            'instagram'   => __('Instagram', 'vereskala-login'),
            'whatsapp'    => __('WhatsApp', 'vereskala-login'),
            'referral'    => __('Referral', 'vereskala-login'),
            'chatbot'     => __('Chatbot', 'vereskala-login'),
            'cart'        => __('Abandoned cart', 'vereskala-login'),
            'wheel'       => __('Prize wheel', 'vereskala-login'),
            'credit'      => __('Credit request', 'vereskala-login'),
            'exhibition'  => __('Exhibition / in store', 'vereskala-login'),
        ]);
    }

    /** @return array<string, string> */
    public static function activity_types(): array
    {
        return [
            'note'        => __('Note', 'vereskala-login'),
            'call'        => __('Call', 'vereskala-login'),
            'meeting'     => __('Meeting', 'vereskala-login'),
            'email'       => __('Email', 'vereskala-login'),
            'sms'         => __('SMS', 'vereskala-login'),
            'order'       => __('Order', 'vereskala-login'),
            'ticket'      => __('Ticket', 'vereskala-login'),
            'return'      => __('Return', 'vereskala-login'),
            'wallet'      => __('Wallet', 'vereskala-login'),
            'installment' => __('Installment', 'vereskala-login'),
            'loyalty'     => __('Club', 'vereskala-login'),
            'stage'       => __('Sales stage', 'vereskala-login'),
            'task'        => __('Task', 'vereskala-login'),
            'system'      => __('Change', 'vereskala-login'),
        ];
    }

    /** @return array<string, string> */
    public static function call_outcomes(): array
    {
        return [
            'answered'  => __('Answered', 'vereskala-login'),
            'no_answer' => __('No answer', 'vereskala-login'),
            'busy'      => __('Busy', 'vereskala-login'),
            'off'       => __('Phone off / unreachable', 'vereskala-login'),
            'callback'  => __('Asked to call back', 'vereskala-login'),
        ];
    }

    /** Dashicon per activity type. */
    public static function activity_icon(string $type): string
    {
        $icons = [
            'note' => 'edit', 'call' => 'phone', 'meeting' => 'groups', 'email' => 'email', 'sms' => 'smartphone',
            'order' => 'cart', 'ticket' => 'sos', 'return' => 'undo', 'wallet' => 'money-alt', 'installment' => 'calendar-alt',
            'loyalty' => 'star-filled', 'stage' => 'chart-line', 'task' => 'yes-alt', 'system' => 'admin-generic',
        ];

        return 'dashicons-' . ($icons[$type] ?? 'marker');
    }

    public static function label(array $map, string $key): string
    {
        return $map[$key] ?? ('' === $key ? '—' : $key);
    }

    public static function contact_name(array $c): string
    {
        $name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));

        if ('' !== $name) {
            return $name;
        }

        foreach (['company', 'mobile', 'email'] as $key) {
            if (!empty($c[$key])) {
                return (string) $c[$key];
            }
        }

        return sprintf(/* translators: %d: contact id */ __('Contact #%d', 'vereskala-login'), (int) ($c['id'] ?? 0));
    }

    /** First letter for the avatar circle. */
    public static function initial(array $c): string
    {
        $name = self::contact_name($c);

        return function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
    }

    public static function user_name(int $user_id): string
    {
        static $cache = [];

        if ($user_id <= 0) {
            return __('Unassigned', 'vereskala-login');
        }

        if (!isset($cache[$user_id])) {
            $u                 = get_userdata($user_id);
            $cache[$user_id] = $u instanceof WP_User ? (string) $u->display_name : sprintf('#%d', $user_id);
        }

        return $cache[$user_id];
    }

    /** UTC MySQL datetime → shop-time Jalali (escaped). */
    public static function date(?string $utc, bool $with_time = false): string
    {
        return esc_html(VKL_Jalali::format((string) $utc, 'long', $with_time));
    }

    /** Compact relative time for the timeline ("۳ ساعت پیش"). */
    public static function ago(?string $utc): string
    {
        $ts = $utc ? strtotime($utc . ' UTC') : false;

        if (!$ts) {
            return '';
        }

        return sprintf(/* translators: %s: human time difference */ __('%s ago', 'vereskala-login'), human_time_diff($ts, time()));
    }

    public static function money(float $amount): string
    {
        return function_exists('wc_price') ? wp_kses_post(wc_price($amount)) : esc_html(number_format_i18n($amount));
    }

    public static function contacts_url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::CONTACTS_PAGE], $args), admin_url('admin.php'));
    }

    public static function contact_url(int $id, array $args = []): string
    {
        return self::contacts_url(array_merge(['contact' => $id], $args));
    }

    public static function post_url(): string
    {
        return admin_url('admin-post.php');
    }

    /**
     * Lifecycle pill.
     */
    public static function lifecycle_pill(string $lifecycle): string
    {
        return sprintf(
            '<span class="vkl-crm-pill is-%1$s">%2$s</span>',
            esc_attr($lifecycle),
            esc_html(self::label(self::lifecycles(), $lifecycle))
        );
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     */
    public static function tag_chips(array $tags): string
    {
        $out = '';

        foreach ($tags as $t) {
            $color = preg_match('/^#[0-9a-f]{6}$/i', (string) $t['color']) ? (string) $t['color'] : '';
            $out  .= sprintf(
                '<span class="vkl-crm-tag%1$s"%2$s>%3$s</span>',
                'category' === $t['type'] ? ' is-category' : '',
                '' !== $color ? ' style="--tag:' . esc_attr($color) . '"' : '',
                esc_html((string) $t['name'])
            );
        }

        return $out;
    }

    /**
     * Notices passed back through ?vkl_msg= after a redirect. Only known
     * codes are shown, so nothing user-controlled is ever printed.
     */
    public static function notices(): void
    {
        $code = isset($_GET['vkl_msg']) ? sanitize_key((string) $_GET['vkl_msg']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $messages = [
            'saved'       => ['success', __('Contact saved.', 'vereskala-login')],
            'created'     => ['success', __('Contact created.', 'vereskala-login')],
            'deleted'     => ['success', __('Contact moved to the deleted list.', 'vereskala-login')],
            'restored'    => ['success', __('Contact restored.', 'vereskala-login')],
            'claimed'     => ['success', __('The contact is now yours.', 'vereskala-login')],
            'logged'      => ['success', __('Added to the timeline.', 'vereskala-login')],
            'act_deleted' => ['success', __('Timeline entry removed.', 'vereskala-login')],
            'bulk'        => ['success', __('Bulk action applied.', 'vereskala-login')],
            'tag_saved'   => ['success', __('Tag saved.', 'vereskala-login')],
            'tag_deleted' => ['success', __('Tag deleted.', 'vereskala-login')],
            'settings'    => ['success', __('Settings saved.', 'vereskala-login')],
            'denied'      => ['error', __('You are not allowed to do that.', 'vereskala-login')],
            'missing'     => ['error', __('That contact no longer exists.', 'vereskala-login')],
            'invalid'     => ['error', __('Please check the form and try again.', 'vereskala-login')],
        ];

        if (isset($messages[$code])) {
            [$type, $text] = $messages[$code];
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($text));
        }

        if (isset($_GET['vkl_count'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $n = absint($_GET['vkl_count']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            printf('<div class="notice notice-info is-dismissible"><p>%s</p></div>', esc_html(sprintf(
                /* translators: %s: number of contacts */
                _n('%s contact affected.', '%s contacts affected.', $n, 'vereskala-login'),
                number_format_i18n($n)
            )));
        }
    }

    /**
     * Redirect back with a notice code and stop.
     *
     * @param array<string, mixed> $extra
     */
    public static function redirect(string $url, string $code, array $extra = []): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['vkl_msg' => $code], $extra), $url));
        exit;
    }

    /**
     * Where to send the user back to: the posted, same-site return URL,
     * else the contacts list.
     */
    public static function back_url(): string
    {
        $back = isset($_POST['_vkl_back']) ? esc_url_raw(wp_unslash((string) $_POST['_vkl_back'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $back = remove_query_arg(['vkl_msg', 'vkl_count', 'vkl_err'], $back);

        return '' !== $back ? wp_validate_redirect($back, self::contacts_url()) : self::contacts_url();
    }

    /**
     * <select> of users records can be assigned to.
     */
    public static function owner_select(string $name, int $selected, bool $with_unassigned = true, string $id = '', array $only = []): string
    {
        $users = vkl('crm.access')->assignable_users();

        if ([] !== $only) {
            $users = array_intersect_key($users, array_flip($only));
        }

        $html = '<select name="' . esc_attr($name) . '"' . ('' !== $id ? ' id="' . esc_attr($id) . '"' : '') . '>';

        if ($with_unassigned) {
            $html .= '<option value="0">' . esc_html__('Unassigned', 'vereskala-login') . '</option>';
        }

        foreach ($users as $uid => $name_) {
            $html .= sprintf('<option value="%1$d"%2$s>%3$s</option>', $uid, selected($selected, $uid, false), esc_html($name_));
        }

        return $html . '</select>';
    }

    /**
     * <select> from a value => label map.
     *
     * @param array<string, string> $options
     */
    public static function select(string $name, array $options, string $selected, string $placeholder = '', string $id = ''): string
    {
        $html = '<select name="' . esc_attr($name) . '"' . ('' !== $id ? ' id="' . esc_attr($id) . '"' : '') . '>';

        if ('' !== $placeholder) {
            $html .= '<option value="">' . esc_html($placeholder) . '</option>';
        }

        foreach ($options as $value => $label) {
            $html .= sprintf('<option value="%1$s"%2$s>%3$s</option>', esc_attr((string) $value), selected($selected, (string) $value, false), esc_html($label));
        }

        return $html . '</select>';
    }

    /**
     * Enqueue the CRM stylesheet and script (CRM screens only).
     */
    public static function enqueue(): void
    {
        wp_enqueue_style('dashicons');
        wp_enqueue_style('vkl-crm', VKL_URL . 'assets/crm/admin.css', [], VKL_VERSION);
        wp_enqueue_script('vkl-crm', VKL_URL . 'assets/crm/admin.js', [], VKL_VERSION, true);
        wp_localize_script('vkl-crm', 'vklCrm', [
            'confirmDelete' => __('Move this contact to the deleted list?', 'vereskala-login'),
            'confirmBulk'   => __('Apply this action to the selected contacts?', 'vereskala-login'),
            'confirmAct'    => __('Remove this timeline entry?', 'vereskala-login'),
            'confirmTag'    => __('Delete this tag? It is removed from every contact.', 'vereskala-login'),
        ]);
    }
}
