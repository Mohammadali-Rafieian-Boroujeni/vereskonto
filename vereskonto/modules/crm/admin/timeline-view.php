<?php
/**
 * The activity composer (note / call / meeting) and the timeline list,
 * plus their form handlers — shared by the contact profile and the deal
 * page so both look and behave the same.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.17.0 (moved out of the contacts page)
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Timeline_View
 */
final class VKL_Crm_Timeline_View
{
    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Access $access;
    private VKL_Crm_Timeline $timeline;

    public function __construct(VKL_Crm_Contacts $contacts, VKL_Crm_Activities $activities, VKL_Crm_Deals $deals, VKL_Crm_Access $access, VKL_Crm_Timeline $timeline)
    {
        $this->timeline   = $timeline;
        $this->contacts   = $contacts;
        $this->activities = $activities;
        $this->deals      = $deals;
        $this->access     = $access;
    }

    public function register(): void
    {
        add_action('admin_post_vkl_crm_contact_activity', [$this, 'handle_activity']);
        add_action('admin_post_vkl_crm_contact_activity_delete', [$this, 'handle_activity_delete']);
    }

    /**
     * Note / call / meeting composer.
     */
    public function composer(int $id, string $back, int $deal_id = 0): void
    {
        $tabs = [
            'note'    => __('Note', 'vereskala-login'),
            'call'    => __('Call', 'vereskala-login'),
            'meeting' => __('Meeting', 'vereskala-login'),
        ];
        ?>
        <section class="vkl-crm-card vkl-crm-composer" data-vkl-composer>
            <div class="vkl-crm-composer-tabs" role="tablist">
                <?php foreach ($tabs as $type => $label) : ?>
                    <button type="button" role="tab" data-type="<?php echo esc_attr($type); ?>" aria-selected="<?php echo 'note' === $type ? 'true' : 'false'; ?>">
                        <span class="dashicons <?php echo esc_attr(VKL_Crm_Ui::activity_icon($type)); ?>" aria-hidden="true"></span><?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>">
                <input type="hidden" name="action" value="vkl_crm_contact_activity">
                <input type="hidden" name="contact" value="<?php echo esc_attr((string) $id); ?>">
                <input type="hidden" name="type" value="note">
                <input type="hidden" name="deal_id" value="<?php echo esc_attr((string) $deal_id); ?>">
                <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
                <?php wp_nonce_field('vkl_crm_activity_' . $id); ?>

                <div class="vkl-crm-fields" data-show="call meeting">
                    <label><span><?php esc_html_e('Subject', 'vereskala-login'); ?></span><input type="text" name="subject" maxlength="190"></label>
                </div>
                <div class="vkl-crm-fields" data-show="call">
                    <label><span><?php esc_html_e('Direction', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('direction', ['out' => __('We called', 'vereskala-login'), 'in' => __('They called', 'vereskala-login')], 'out'); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Result', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('outcome', VKL_Crm_Ui::call_outcomes(), 'answered'); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Minutes', 'vereskala-login'); ?></span><input type="number" name="minutes" min="0" max="600" step="1" inputmode="numeric"></label>
                </div>
                <div class="vkl-crm-fields" data-show="meeting">
                    <label><span><?php esc_html_e('Date', 'vereskala-login'); ?></span><input type="text" name="meeting_date" dir="ltr" inputmode="numeric" placeholder="<?php echo esc_attr(VKL_Jalali::format(gmdate('Y-m-d H:i:s'), 'short')); ?>"></label>
                    <label><span><?php esc_html_e('Time', 'vereskala-login'); ?></span><input type="time" name="meeting_time" dir="ltr"></label>
                    <label><span><?php esc_html_e('Status', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('meeting_status', ['done' => __('Held', 'vereskala-login'), 'scheduled' => __('Scheduled', 'vereskala-login'), 'cancelled' => __('Cancelled', 'vereskala-login')], 'done'); // phpcs:ignore ?></label>
                </div>
                <label class="vkl-crm-wide"><span class="screen-reader-text"><?php esc_html_e('Details', 'vereskala-login'); ?></span>
                    <textarea name="body" rows="3" placeholder="<?php esc_attr_e('Write a note…', 'vereskala-login'); ?>" data-ph-note="<?php esc_attr_e('Write a note…', 'vereskala-login'); ?>" data-ph-call="<?php esc_attr_e('What was discussed?', 'vereskala-login'); ?>" data-ph-meeting="<?php esc_attr_e('Agenda or minutes…', 'vereskala-login'); ?>"></textarea>
                </label>
                <p><button class="button button-primary"><?php esc_html_e('Add to timeline', 'vereskala-login'); ?></button></p>
            </form>
        </section>
        <?php
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>             $c
     */
    public function timeline_list(array $items, array $c, string $back): void
    {
        if ([] === $items) {
            echo '<p class="vkl-crm-empty">' . esc_html__('Nothing here yet.', 'vereskala-login') . '</p>';

            return;
        }

        $types    = VKL_Crm_Ui::activity_types();
        $outcomes = VKL_Crm_Ui::call_outcomes();
        $day      = '';

        echo '<ol class="vkl-crm-timeline">';

        foreach ($items as $a) {
            $this_day = VKL_Jalali::format((string) $a['created_at']);

            if ($this_day !== $day) {
                $day = $this_day;
                echo '<li class="vkl-crm-day">' . esc_html($day) . '</li>';
            }

            $type   = (string) $a['type'];
            $manual = in_array($type, VKL_Crm_Activities::MANUAL_TYPES, true);
            $meta   = [];

            if ('call' === $type) {
                $meta[] = 'in' === $a['direction'] ? __('They called', 'vereskala-login') : __('We called', 'vereskala-login');
                $meta[] = VKL_Crm_Ui::label($outcomes, (string) $a['outcome']);

                if ((int) $a['duration'] > 0) {
                    $meta[] = sprintf(/* translators: %s: minutes */ __('%s min', 'vereskala-login'), number_format_i18n((int) round((int) $a['duration'] / 60)));
                }
            }

            if ('meeting' === $type) {
                if (!empty($a['scheduled_at'])) {
                    $meta[] = VKL_Jalali::format((string) $a['scheduled_at'], 'long', true);
                }

                $meta[] = ['done' => __('Held', 'vereskala-login'), 'scheduled' => __('Scheduled', 'vereskala-login'), 'cancelled' => __('Cancelled', 'vereskala-login')][$a['outcome']] ?? '';
            }

            $link = '';

            if ('order' === $type && (int) $a['ref_id'] > 0 && function_exists('wc_get_order') && current_user_can('edit_shop_orders')) {
                $order = wc_get_order((int) $a['ref_id']);
                $link  = $order instanceof WC_Order ? (string) $order->get_edit_order_url() : '';
            } elseif ('ticket' === $type && (int) $a['ref_id'] > 0 && current_user_can('manage_options')) {
                $link = admin_url('admin.php?page=vkl-tickets&ticket=' . (int) $a['ref_id']);
            }
            ?>
            <li class="vkl-crm-entry is-<?php echo esc_attr($type); ?>">
                <span class="vkl-crm-entry-icon dashicons <?php echo esc_attr(VKL_Crm_Ui::activity_icon($type)); ?>" aria-hidden="true"></span>
                <div class="vkl-crm-entry-body">
                    <div class="vkl-crm-entry-head">
                        <strong><?php echo esc_html('' !== (string) $a['subject'] ? (string) $a['subject'] : VKL_Crm_Ui::label($types, $type)); ?></strong>
                        <?php if ('' !== $link) : ?><a href="<?php echo esc_url($link); ?>" class="vkl-crm-entry-link"><?php esc_html_e('Open', 'vereskala-login'); ?></a><?php endif; ?>
                        <span class="vkl-crm-entry-time" title="<?php echo esc_attr(VKL_Jalali::format((string) $a['created_at'], 'long', true)); ?>"><?php echo esc_html((string) wp_date('H:i', (int) strtotime($a['created_at'] . ' UTC'))); ?></span>
                    </div>
                    <?php if ([] !== array_filter($meta)) : ?>
                        <div class="vkl-crm-entry-meta"><?php echo esc_html(implode(' · ', array_filter($meta))); ?></div>
                    <?php endif; ?>
                    <?php if ('' !== trim((string) $a['body'])) : ?>
                        <div class="vkl-crm-entry-text"><?php echo wp_kses(wpautop(esc_html((string) $a['body'])), ['p' => [], 'br' => []]); ?></div>
                    <?php endif; ?>
                    <div class="vkl-crm-entry-foot">
                        <?php if ((int) $a['author_id'] > 0) : ?>
                            <span><?php echo esc_html(VKL_Crm_Ui::user_name((int) $a['author_id'])); ?></span>
                        <?php else : ?>
                            <span><?php esc_html_e('Automatic', 'vereskala-login'); ?></span>
                        <?php endif; ?>
                        <?php if ($manual && $this->can_delete_activity($a, $c)) : ?>
                            <a href="<?php echo esc_url(add_query_arg('_vkl_back', rawurlencode($back), VKL_Crm_Ui::action_url('activity_delete', (int) $a['id']))); ?>" data-confirm="activity" class="vkl-crm-danger-link"><?php esc_html_e('Remove', 'vereskala-login'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
            <?php
        }

        echo '</ol>';
    }

    public function handle_activity(): void
    {
        $id = isset($_POST['contact']) ? absint($_POST['contact']) : 0;
        check_admin_referer('vkl_crm_activity_' . $id);

        $c    = $this->contacts->find($id);
        $back = VKL_Crm_Ui::back_url();

        if (null === $c || !$this->access->can_edit($c)) {
            VKL_Crm_Ui::redirect($back, null === $c ? 'missing' : 'denied');
        }

        // A note can also belong to one of the contact's deals.
        $deal_id = isset($_POST['deal_id']) ? absint($_POST['deal_id']) : 0;

        if ($deal_id > 0) {
            $deal = $this->deals->find($deal_id);

            if (null === $deal || (int) $deal['contact_id'] !== $id) {
                VKL_Crm_Ui::redirect($back, 'invalid');
            }
        }

        $input = [];

        foreach (['type', 'subject', 'body', 'direction', 'outcome', 'minutes', 'meeting_status', 'meeting_date', 'meeting_time'] as $field) {
            if (isset($_POST[$field])) {
                $input[$field] = wp_unslash((string) $_POST[$field]);
            }
        }

        $result = $this->timeline->manual($input, $id, $deal_id, get_current_user_id());
        VKL_Crm_Ui::redirect($back, is_wp_error($result) ? 'invalid' : 'logged');
    }

    public function handle_activity_delete(): void
    {
        $aid = isset($_GET['contact']) ? absint($_GET['contact']) : 0; // the row-action helper names the id "contact"
        check_admin_referer('vkl_crm_activity_delete_' . $aid);

        $back = isset($_GET['_vkl_back']) ? wp_validate_redirect(esc_url_raw(rawurldecode(wp_unslash((string) $_GET['_vkl_back']))), VKL_Crm_Ui::contacts_url()) : VKL_Crm_Ui::contacts_url();
        $a    = $this->activities->find($aid);
        $c    = null !== $a ? $this->contacts->find((int) $a['contact_id']) : null;

        if (null === $a || null === $c || !in_array($a['type'], VKL_Crm_Activities::MANUAL_TYPES, true) || !$this->can_delete_activity($a, $c)) {
            VKL_Crm_Ui::redirect($back, 'denied');
        }

        $this->activities->soft_delete($aid);
        VKL_Crm_Ui::redirect($back, 'act_deleted');
    }

    /**
     * Authors remove their own manual entries; managers remove any.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $c
     */
    public function can_delete_activity(array $a, array $c): bool
    {
        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
            return true;
        }

        return (int) $a['author_id'] === get_current_user_id() && $this->access->can_edit($c);
    }

}
