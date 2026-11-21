<?php
/**
 * Ticket admin — list, view, reply, close. Replies dispatch
 * 'ticket.admin_replied' so the customer gets an SMS.
 *
 * @package Vereskonto\Modules\Ticket
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Ticket_Admin
 */
final class VKL_Ticket_Admin
{
    /**
     * Settings group for the departments list.
     */
    private const SETTINGS_GROUP = 'vkl_ticket_settings';

    private VKL_Ticket_Repository $repository;

    private VKL_Security $security;

    private VKL_Validator $validator;

    private VKL_Config $config;

    public function __construct(
        VKL_Ticket_Repository $repository,
        VKL_Security $security,
        VKL_Validator $validator,
        VKL_Config $config
    ) {
        $this->repository = $repository;
        $this->security   = $security;
        $this->validator  = $validator;
        $this->config     = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_vkl_ticket_reply', [$this, 'handle_reply']);
        add_action('admin_post_vkl_ticket_close', [$this, 'handle_close']);
        add_action('admin_post_vkl_ticket_create', [$this, 'handle_create']);
    }

    /**
     * Load WooCommerce's enhanced select (select2 + AJAX customer search)
     * on the tickets screen so the "create for a customer" field is a
     * searchable dropdown instead of a bare user-id box.
     */
    public function enqueue_assets(string $hook): void
    {
        if (false === strpos($hook, 'vkl-tickets') || !class_exists('WooCommerce')) {
            return;
        }

        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
    }

    public function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('ticket_categories'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ]);
    }

    public function add_menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Tickets', 'vereskala-login'),
            __('Tickets', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-tickets',
            [$this, 'render']
        );
    }

    public function handle_reply(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $ticket_id = (int) ($_POST['vkl_ticket_id'] ?? 0);
        $content   = trim(sanitize_textarea_field(wp_unslash($_POST['vkl_content'] ?? '')));
        // phpcs:enable

        $ticket = $this->repository->find($ticket_id);

        if ($ticket && '' !== $content) {
            $this->repository->add_reply($ticket_id, get_current_user_id(), true, $content);

            $user_id = (int) $ticket['user_id'];
            $mobile  = $this->validator->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

            vkl_dispatch('ticket.admin_replied', [
                'ticket_id' => $ticket_id,
                'user_id'   => $user_id,
                'mobile'    => $this->validator->is_mobile($mobile) ? $mobile : '',
                'subject'   => (string) $ticket['subject'],
            ]);
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-tickets&ticket=' . $ticket_id));
        exit;
    }

    public function handle_close(): void
    {
        $this->security->verify_admin_request();

        $ticket_id = (int) ($_POST['vkl_ticket_id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if ($this->repository->find($ticket_id)) {
            $this->repository->close($ticket_id);

            vkl_dispatch('ticket.closed', ['ticket_id' => $ticket_id]);
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-tickets'));
        exit;
    }

    /**
     * Open a ticket on a customer's behalf (e.g. a phone call the shop
     * wants a record of). The opening message is attributed to the admin
     * and the customer is notified by SMS exactly like a normal reply.
     */
    public function handle_create(): void
    {
        $this->security->verify_admin_request();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $user_id  = (int) ($_POST['vkl_user_id'] ?? 0);
        $subject  = trim(sanitize_text_field(wp_unslash($_POST['vkl_subject'] ?? '')));
        $category = trim(sanitize_text_field(wp_unslash($_POST['vkl_category'] ?? '')));
        $priority = sanitize_text_field(wp_unslash($_POST['vkl_priority'] ?? VKL_Ticket_Repository::DEFAULT_PRIORITY));
        $content  = trim(sanitize_textarea_field(wp_unslash($_POST['vkl_content'] ?? '')));
        // phpcs:enable

        $categories = array_filter(array_map('trim', explode("\n", $this->config->get_string('ticket_categories'))));

        if ('' !== $category && !in_array($category, $categories, true)) {
            $category = '';
        }

        $user = $user_id > 0 ? get_userdata($user_id) : false;

        if ($user && '' !== $subject && '' !== $content) {
            $ticket_id = $this->repository->create_by_admin(
                $user_id,
                get_current_user_id(),
                $subject,
                $content,
                $category,
                $priority
            );

            $mobile = $this->validator->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

            // Same event a normal admin reply fires — the customer is
            // waiting on nothing from support here, so this is the
            // correct notification, not "ticket.created" (which alerts
            // the shop admin, not the customer).
            vkl_dispatch('ticket.admin_replied', [
                'ticket_id' => $ticket_id,
                'user_id'   => $user_id,
                'mobile'    => $this->validator->is_mobile($mobile) ? $mobile : '',
                'subject'   => $subject,
            ]);

            wp_safe_redirect(admin_url('admin.php?page=vkl-tickets&ticket=' . $ticket_id));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=vkl-tickets&vkl_ticket_error=1'));
        exit;
    }

    public function render(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        $ticket_id = isset($_GET['ticket']) ? (int) $_GET['ticket'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap"><h1>' . esc_html__('Tickets', 'vereskala-login') . '</h1>';

        if (isset($_GET['vkl_ticket_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-error is-dismissible"><p>یک مشتری انتخاب کنید و موضوع و پیام را پر کنید.</p></div>';
        }

        $ticket_id > 0 ? $this->render_single($ticket_id) : $this->render_list();

        echo '</div>';
    }

    private function render_list(): void
    {
        $priorities = VKL_Ticket_Account::priority_labels();
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th>#</th>
                <th><?php esc_html_e('Subject', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Customer', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Department', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Priority', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                <th>امتیاز</th>
                <th><?php esc_html_e('Last update (UTC)', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php $ticket_search = isset($_GET['vkl_s']) ? sanitize_text_field(wp_unslash((string) $_GET['vkl_s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
        <form method="get" style="margin:8px 0">
            <input type="hidden" name="page" value="vkl-tickets">
            <input type="search" name="vkl_s" value="<?php echo esc_attr($ticket_search); ?>"
                   placeholder="<?php esc_attr_e('Search subject or department…', 'vereskala-login'); ?>">
            <?php // Department sections: one chip per admin-defined department (+ any legacy value still in the data). ?>
            <?php
            $ticket_department = isset($_GET['vkl_department']) ? sanitize_text_field(wp_unslash($_GET['vkl_department'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $vkl_dept_counts   = $this->repository->department_counts();
            ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
                <a href="<?php echo esc_url(admin_url('admin.php?page=vkl-tickets')); ?>"
                   class="button <?php echo '' === $ticket_department ? 'button-primary' : ''; ?>"><?php esc_html_e('All departments', 'vereskala-login'); ?></a>
                <?php foreach ($vkl_dept_counts as $vkl_dept) : ?>
                    <?php $vkl_dept_name = '' !== (string) $vkl_dept['category'] ? (string) $vkl_dept['category'] : __('(no department)', 'vereskala-login'); ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'vkl-tickets', 'vkl_department' => (string) $vkl_dept['category']], admin_url('admin.php'))); ?>"
                       class="button <?php echo $ticket_department === (string) $vkl_dept['category'] && '' !== (string) $vkl_dept['category'] ? 'button-primary' : ''; ?>">
                        <?php echo esc_html($vkl_dept_name); ?>
                        <span style="background:#eaf9ef;color:#157f3a;border-radius:10px;padding:0 7px;margin-inline-start:4px">
                            <?php echo esc_html(number_format_i18n((int) $vkl_dept['total'])); ?><?php echo (int) $vkl_dept['open_count'] > 0 ? ' · ' . esc_html(number_format_i18n((int) $vkl_dept['open_count'])) . ' 🔴' : ''; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            <button class="button"><?php esc_html_e('Apply', 'vereskala-login'); ?></button>
        </form>
        <?php foreach ($this->repository->latest(30, $ticket_search, $ticket_department) as $ticket) : ?>
                <?php $priority = (string) ($ticket['priority'] ?? 'normal'); ?>
                <tr>
                    <td><?php echo esc_html((string) (int) $ticket['id']); ?></td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=vkl-tickets&ticket=' . (int) $ticket['id'])); ?>">
                            <?php echo esc_html((string) $ticket['subject']); ?>
                        </a>
                    </td>
                    <td>
                        <?php
                        $user = get_userdata((int) $ticket['user_id']);
                        echo esc_html($user ? $user->display_name : ('#' . (int) $ticket['user_id']));
                        ?>
                    </td>
                    <td><?php echo esc_html((string) ($ticket['category'] ?? '')); ?></td>
                    <td>
                        <?php if ('urgent' === $priority || 'high' === $priority) : ?>
                            <strong style="color:#d92b31"><?php echo esc_html($priorities[$priority] ?? $priority); ?></strong>
                        <?php else : ?>
                            <?php echo esc_html($priorities[$priority] ?? $priority); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) $ticket['status']); ?></td>
                    <td>
                        <?php if (null !== $ticket['rating']) : ?>
                            <span style="letter-spacing:1px;color:#e0a800;font-size:14px">
                                <?php echo esc_html(str_repeat('★', (int) $ticket['rating']) . str_repeat('☆', 5 - (int) $ticket['rating'])); ?>
                            </span>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html((string) $ticket['updated_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="title" style="margin-top:24px">➕ ثبت تیکت برای مشتری</h2>
        <p class="description">برای مواردی که تلفنی یا حضوری گزارش شده‌اند — مشتری دقیقاً مثل یک پاسخ عادی پشتیبانی، با پیامک مطلع می‌شود.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:14px 18px;max-width:640px">
            <input type="hidden" name="action" value="vkl_ticket_create">
            <?php $this->security->admin_nonce_field(); ?>

            <p class="form-row">
                <label style="display:block;font-weight:600">مشتری</label>
                <?php if (class_exists('WooCommerce')) : ?>
                    <select class="wc-customer-search" name="vkl_user_id" style="min-width:320px"
                            data-placeholder="جست‌وجوی مشتری با نام، ایمیل یا شناسه…"
                            data-allow_clear="true" required></select>
                <?php else : ?>
                    <input type="number" min="1" name="vkl_user_id" required>
                <?php endif; ?>
            </p>
            <p class="form-row">
                <label style="display:block;font-weight:600">موضوع</label>
                <input type="text" class="regular-text" name="vkl_subject" maxlength="190" required>
            </p>
            <?php $vkl_new_categories = array_filter(array_map('trim', explode("\n", $this->config->get_string('ticket_categories')))); ?>
            <?php if ([] !== $vkl_new_categories) : ?>
                <p class="form-row">
                    <label style="display:block;font-weight:600">دپارتمان</label>
                    <select name="vkl_category">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ($vkl_new_categories as $vkl_cat) : ?>
                            <option value="<?php echo esc_attr($vkl_cat); ?>"><?php echo esc_html($vkl_cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
            <p class="form-row">
                <label style="display:block;font-weight:600">اولویت</label>
                <select name="vkl_priority">
                    <?php foreach (VKL_Ticket_Account::priority_labels() as $vkl_pv => $vkl_pl) : ?>
                        <option value="<?php echo esc_attr($vkl_pv); ?>" <?php selected('normal', $vkl_pv); ?>><?php echo esc_html($vkl_pl); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-row">
                <label style="display:block;font-weight:600">پیام</label>
                <textarea class="large-text" name="vkl_content" rows="4" required></textarea>
            </p>
            <?php submit_button('فرستادن', 'primary', 'submit', false); ?>
        </form>

        <h2 class="title" style="margin-top:24px"><?php esc_html_e('Ticket departments', 'vereskala-login'); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields(self::SETTINGS_GROUP); ?>
            <textarea class="large-text" rows="4"
                      name="<?php echo esc_attr($this->config->option_name('ticket_categories')); ?>"><?php echo esc_textarea($this->config->get_string('ticket_categories')); ?></textarea>
            <p class="description"><?php esc_html_e('One department per line (e.g. sales, technical, billing). Shown as a selector on the customer ticket form.', 'vereskala-login'); ?></p>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_single(int $ticket_id): void
    {
        $ticket = $this->repository->find($ticket_id);

        if (!$ticket) {
            echo '<p>' . esc_html__('Ticket not found.', 'vereskala-login') . '</p>';

            return;
        }
        ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=vkl-tickets')); ?>">&larr; <?php esc_html_e('Back to tickets', 'vereskala-login'); ?></a></p>

        <h2><?php echo esc_html((string) $ticket['subject']); ?> <small>(<?php echo esc_html((string) $ticket['status']); ?>)</small></h2>

        <p>
            <?php if ('' !== (string) ($ticket['category'] ?? '')) : ?>
                <strong><?php esc_html_e('Department', 'vereskala-login'); ?>:</strong>
                <?php echo esc_html((string) $ticket['category']); ?> —
            <?php endif; ?>
            <strong><?php esc_html_e('Priority', 'vereskala-login'); ?>:</strong>
            <?php echo esc_html(VKL_Ticket_Account::priority_labels()[(string) ($ticket['priority'] ?? 'normal')] ?? (string) $ticket['priority']); ?>
        </p>

        <?php if (null !== $ticket['rating']) : ?>
            <?php $vkl_rated = VKL_Ticket_Account::rating_levels()[(int) $ticket['rating']] ?? null; ?>
            <?php if ($vkl_rated) : ?>
                <p>
                    <strong>امتیاز مشتری:</strong>
                    <span style="letter-spacing:1px;color:#e0a800;font-size:18px;vertical-align:middle">
                        <?php echo esc_html(str_repeat('★', (int) $ticket['rating']) . str_repeat('☆', 5 - (int) $ticket['rating'])); ?>
                    </span>
                    <?php echo esc_html($vkl_rated['label']); ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($this->repository->replies($ticket_id) as $reply) : ?>
            <div style="border:1px solid #ddd;border-radius:6px;padding:10px 14px;margin-bottom:10px;max-width:800px;<?php echo $reply['is_admin'] ? 'background:#f0f6fc' : ''; ?>">
                <strong><?php $reply['is_admin'] ? esc_html_e('Support', 'vereskala-login') : esc_html_e('Customer', 'vereskala-login'); ?></strong>
                — <small><?php echo esc_html((string) $reply['created_at']); ?> UTC</small>
                <p><?php echo wp_kses_post(wpautop(esc_html((string) $reply['content']))); ?></p>
            </div>
        <?php endforeach; ?>

        <?php if (VKL_Ticket_Repository::STATUS_CLOSED !== $ticket['status']) : ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:800px">
                <input type="hidden" name="action" value="vkl_ticket_reply">
                <input type="hidden" name="vkl_ticket_id" value="<?php echo esc_attr((string) $ticket_id); ?>">
                <?php $this->security->admin_nonce_field(); ?>

                <textarea name="vkl_content" rows="5" class="large-text" required></textarea>
                <?php submit_button(__('Send reply', 'vereskala-login')); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vkl_ticket_close">
                <input type="hidden" name="vkl_ticket_id" value="<?php echo esc_attr((string) $ticket_id); ?>">
                <?php $this->security->admin_nonce_field(); ?>
                <?php submit_button(__('Close ticket', 'vereskala-login'), 'secondary'); ?>
            </form>

        <?php endif; ?>
        <?php
    }
}
