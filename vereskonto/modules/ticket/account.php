<?php
/**
 * Ticket My Account endpoint — customers create, view, and reply to
 * their own tickets. Ownership enforced on every read and write.
 *
 * @package Vereskonto\Modules\Ticket
 * @since   1.5.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Ticket_Account
 */
final class VKL_Ticket_Account
{
    public const ENDPOINT = 'tickets';

    /**
     * Slug used before 1.6.0; kept registered so old links redirect
     * instead of 404ing.
     */
    public const LEGACY_ENDPOINT = 'vkl-tickets';

    private const NONCE = 'vkl_ticket_front';

    private VKL_Ticket_Repository $repository;

    private VKL_Validator $validator;

    private VKL_Config $config;

    public function __construct(VKL_Ticket_Repository $repository, VKL_Validator $validator, VKL_Config $config)
    {
        $this->repository = $repository;
        $this->validator  = $validator;
        $this->config     = $config;
    }

    /**
     * Admin-defined ticket departments, one per line.
     *
     * @return string[]
     */
    public function categories(): array
    {
        $raw   = $this->config->get_string('ticket_categories');
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        return array_values(array_unique($lines));
    }

    /**
     * Translated priority labels keyed by stored value.
     *
     * @return array<string, string>
     */
    public static function priority_labels(): array
    {
        return [
            'low'    => __('Low', 'vereskala-login'),
            'normal' => __('Normal', 'vereskala-login'),
            'high'   => __('High', 'vereskala-login'),
            'urgent' => __('Urgent', 'vereskala-login'),
        ];
    }

    /**
     * Satisfaction-rating scale, worst to best: emoji + label keyed by
     * the stored 1-5 value.
     *
     * @return array<int, array{emoji: string, label: string}>
     */
    public static function rating_levels(): array
    {
        return [
            1 => ['emoji' => '😠', 'label' => 'ناراضی'],
            2 => ['emoji' => '🙁', 'label' => 'ضعیف'],
            3 => ['emoji' => '😐', 'label' => 'متوسط'],
            4 => ['emoji' => '🙂', 'label' => 'خوب'],
            5 => ['emoji' => '😄', 'label' => 'بسیار خوب'],
        ];
    }

    public function register(): void
    {
        add_action('init', static function (): void {
            add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
            add_rewrite_endpoint(self::LEGACY_ENDPOINT, EP_ROOT | EP_PAGES);
        });
        add_filter('woocommerce_get_query_vars', [$this, 'query_var']);
        add_filter('woocommerce_account_menu_items', [$this, 'menu_item']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'render']);
        add_action('template_redirect', [$this, 'redirect_legacy'], 5);
        add_action('template_redirect', [$this, 'handle_post']);
    }

    /** Permanent redirect from the pre-1.6.0 slug to the clean one. */
    public function redirect_legacy(): void
    {
        global $wp_query;

        if (isset($wp_query->query_vars[self::LEGACY_ENDPOINT])) {
            wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT), 301);
            exit;
        }
    }

    /** @param array<string, string> $vars */
    public function query_var(array $vars): array
    {
        $vars[self::ENDPOINT]        = self::ENDPOINT;
        $vars[self::LEGACY_ENDPOINT] = self::LEGACY_ENDPOINT;

        return $vars;
    }

    /** @param array<string, string> $items */
    public function menu_item(array $items): array
    {
        $logout = $items['customer-logout'] ?? null;
        unset($items['customer-logout']);

        $items[self::ENDPOINT] = __('Support tickets', 'vereskala-login');

        if (null !== $logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    /** Handle create/reply POSTs on the front end. */
    public function handle_post(): void
    {
        if (!is_user_logged_in() || empty($_POST['vkl_ticket_action'])) {
            return;
        }

        $nonce = isset($_POST['_vkl_ticket_nonce']) ? sanitize_text_field(wp_unslash($_POST['_vkl_ticket_nonce'])) : '';

        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return;
        }

        $user_id = get_current_user_id();
        $action  = sanitize_text_field(wp_unslash($_POST['vkl_ticket_action']));
        $content = trim(sanitize_textarea_field(wp_unslash($_POST['vkl_content'] ?? '')));

        if ('create' === $action) {

            $subject  = trim(sanitize_text_field(wp_unslash($_POST['vkl_subject'] ?? '')));
            $category = trim(sanitize_text_field(wp_unslash($_POST['vkl_category'] ?? '')));
            $priority = sanitize_text_field(wp_unslash($_POST['vkl_priority'] ?? VKL_Ticket_Repository::DEFAULT_PRIORITY));

            // Only admin-defined departments are accepted.
            if ('' !== $category && !in_array($category, $this->categories(), true)) {
                $category = '';
            }

            if ('' !== $subject && '' !== $content) {
                $ticket_id = $this->repository->create($user_id, $subject, $content, $category, $priority);

                vkl_dispatch('ticket.created', [
                    'ticket_id' => $ticket_id,
                    'user_id'   => $user_id,
                    'subject'   => $subject,
                    'category'  => $category,
                    'priority'  => $priority,
                ]);

                wc_add_notice(__('Your ticket was submitted.', 'vereskala-login'));
            } else {
                wc_add_notice(__('Subject and message are required.', 'vereskala-login'), 'error');
            }
        }

        if ('reply' === $action) {

            $ticket_id = (int) ($_POST['vkl_ticket_id'] ?? 0);
            $ticket    = $this->repository->find($ticket_id);

            // Ownership + not closed.
            if ($ticket
                && (int) $ticket['user_id'] === $user_id
                && VKL_Ticket_Repository::STATUS_CLOSED !== $ticket['status']
                && '' !== $content
            ) {
                $this->repository->add_reply($ticket_id, $user_id, false, $content);

                vkl_dispatch('ticket.customer_replied', [
                    'ticket_id' => $ticket_id,
                    'user_id'   => $user_id,
                ]);

                wc_add_notice(__('Your reply was added.', 'vereskala-login'));
            }
        }

        if ('rate' === $action) {

            $ticket_id = (int) ($_POST['vkl_ticket_id'] ?? 0);
            $rating    = (int) ($_POST['vkl_rating'] ?? 0);
            $ticket    = $this->repository->find($ticket_id);

            // Ownership, closed, and not already rated — rating is a
            // one-time customer answer, not an editable setting.
            if ($ticket
                && (int) $ticket['user_id'] === $user_id
                && VKL_Ticket_Repository::STATUS_CLOSED === $ticket['status']
                && null === $ticket['rating']
                && $this->repository->rate($ticket_id, $rating)
            ) {
                wc_add_notice('از بازخورد شما سپاسگزاریم.');
            }
        }

        wp_safe_redirect(wc_get_account_endpoint_url(self::ENDPOINT));
        exit;
    }

    /** Render list or single-ticket view. */
    public function render(): void
    {
        $ticket_id = isset($_GET['ticket']) ? (int) $_GET['ticket'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($ticket_id > 0) {
            $this->render_single($ticket_id);

            return;
        }

        $this->render_list();
    }

    private function status_label(string $status): string
    {
        $map = [
            VKL_Ticket_Repository::STATUS_OPEN     => __('Open', 'vereskala-login'),
            VKL_Ticket_Repository::STATUS_ANSWERED => __('Answered', 'vereskala-login'),
            VKL_Ticket_Repository::STATUS_CLOSED   => __('Closed', 'vereskala-login'),
        ];

        return $map[$status] ?? $status;
    }

    private function render_list(): void
    {
        ?>
        <h3><?php esc_html_e('Support tickets', 'vereskala-login'); ?></h3>

        <table class="woocommerce-table shop_table">
            <thead><tr>
                <th><?php esc_html_e('Subject', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Department', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Priority', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Status', 'vereskala-login'); ?></th>
                <th><?php esc_html_e('Last update', 'vereskala-login'); ?></th>
            </tr></thead>
            <tbody>
            <?php $priorities = self::priority_labels(); ?>
            <?php foreach ($this->repository->for_user(get_current_user_id()) as $ticket) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg('ticket', (int) $ticket['id'], wc_get_account_endpoint_url(self::ENDPOINT))); ?>">
                            <?php echo esc_html((string) $ticket['subject']); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html((string) ($ticket['category'] ?? '')); ?></td>
                    <td><?php echo esc_html($priorities[(string) ($ticket['priority'] ?? 'normal')] ?? (string) $ticket['priority']); ?></td>
                    <td><?php echo esc_html($this->status_label((string) $ticket['status'])); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime((string) $ticket['updated_at']))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h4><?php esc_html_e('New ticket', 'vereskala-login'); ?></h4>

        <form method="post">
            <input type="hidden" name="vkl_ticket_action" value="create">
            <?php wp_nonce_field(self::NONCE, '_vkl_ticket_nonce'); ?>

            <p class="form-row">
                <label><?php esc_html_e('Subject', 'vereskala-login'); ?></label>
                <input type="text" class="input-text" name="vkl_subject" required maxlength="190">
            </p>
            <?php $categories = $this->categories(); ?>
            <?php if ([] !== $categories) : ?>
                <p class="form-row">
                    <label><?php esc_html_e('Department', 'vereskala-login'); ?></label>
                    <select name="vkl_category" class="input-text">
                        <option value=""><?php esc_html_e('— Select —', 'vereskala-login'); ?></option>
                        <?php foreach ($categories as $category) : ?>
                            <option value="<?php echo esc_attr($category); ?>"><?php echo esc_html($category); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
            <p class="form-row">
                <label><?php esc_html_e('Priority', 'vereskala-login'); ?></label>
                <select name="vkl_priority" class="input-text">
                    <?php foreach (self::priority_labels() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected('normal', $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-row">
                <label><?php esc_html_e('Message', 'vereskala-login'); ?></label>
                <textarea class="input-text" name="vkl_content" rows="5" required></textarea>
            </p>
            <p class="form-row">
                <button type="submit" class="button"><?php esc_html_e('Submit ticket', 'vereskala-login'); ?></button>
            </p>
        </form>
        <?php
    }

    private function render_single(int $ticket_id): void
    {
        $ticket = $this->repository->find($ticket_id);

        // Ownership check on read.
        if (!$ticket || (int) $ticket['user_id'] !== get_current_user_id()) {
            echo '<p>' . esc_html__('Ticket not found.', 'vereskala-login') . '</p>';

            return;
        }
        ?>
        <p><a href="<?php echo esc_url(wc_get_account_endpoint_url(self::ENDPOINT)); ?>">&larr; <?php esc_html_e('Back to tickets', 'vereskala-login'); ?></a></p>

        <h3><?php echo esc_html((string) $ticket['subject']); ?>
            <small>(<?php echo esc_html($this->status_label((string) $ticket['status'])); ?>)</small>
        </h3>

        <p>
            <?php if ('' !== (string) ($ticket['category'] ?? '')) : ?>
                <strong><?php esc_html_e('Department', 'vereskala-login'); ?>:</strong>
                <?php echo esc_html((string) $ticket['category']); ?> —
            <?php endif; ?>
            <strong><?php esc_html_e('Priority', 'vereskala-login'); ?>:</strong>
            <?php echo esc_html(self::priority_labels()[(string) ($ticket['priority'] ?? 'normal')] ?? (string) $ticket['priority']); ?>
        </p>

        <?php foreach ($this->repository->replies($ticket_id) as $reply) : ?>
            <div style="border:1px solid #ddd;border-radius:6px;padding:10px 14px;margin-bottom:10px;<?php echo $reply['is_admin'] ? 'background:#f5f9ff' : ''; ?>">
                <strong>
                    <?php $reply['is_admin'] ? esc_html_e('Support', 'vereskala-login') : esc_html_e('You', 'vereskala-login'); ?>
                </strong>
                — <small><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime((string) $reply['created_at']))); ?></small>
                <p><?php echo wp_kses_post(wpautop(esc_html((string) $reply['content']))); ?></p>
            </div>
        <?php endforeach; ?>

        <?php if (VKL_Ticket_Repository::STATUS_CLOSED !== $ticket['status']) : ?>
            <form method="post">
                <input type="hidden" name="vkl_ticket_action" value="reply">
                <input type="hidden" name="vkl_ticket_id" value="<?php echo esc_attr((string) $ticket_id); ?>">
                <?php wp_nonce_field(self::NONCE, '_vkl_ticket_nonce'); ?>

                <p class="form-row">
                    <label><?php esc_html_e('Your reply', 'vereskala-login'); ?></label>
                    <textarea class="input-text" name="vkl_content" rows="4" required></textarea>
                </p>
                <p class="form-row">
                    <button type="submit" class="button"><?php esc_html_e('Send reply', 'vereskala-login'); ?></button>
                </p>
            </form>
        <?php elseif (null !== $ticket['rating']) : ?>
            <?php $vkl_rated = self::rating_levels()[(int) $ticket['rating']] ?? null; ?>
            <?php if ($vkl_rated) : ?>
                <p style="font-size:15px">
                    امتیاز شما:
                    <span style="font-size:22px;vertical-align:middle"><?php echo esc_html($vkl_rated['emoji']); ?></span>
                    <strong><?php echo esc_html($vkl_rated['label']); ?></strong>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <form method="post" style="margin-top:10px">
                <input type="hidden" name="vkl_ticket_action" value="rate">
                <input type="hidden" name="vkl_ticket_id" value="<?php echo esc_attr((string) $ticket_id); ?>">
                <?php wp_nonce_field(self::NONCE, '_vkl_ticket_nonce'); ?>

                <p class="form-row">
                    <label>چقدر از پشتیبانی این تیکت راضی بودید؟</label>
                </p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (self::rating_levels() as $vkl_value => $vkl_level) : ?>
                        <button type="submit" name="vkl_rating" value="<?php echo (int) $vkl_value; ?>" class="button"
                                style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 14px">
                            <span style="font-size:26px"><?php echo esc_html($vkl_level['emoji']); ?></span>
                            <span style="font-size:12px"><?php echo esc_html($vkl_level['label']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>
        <?php
    }
}
