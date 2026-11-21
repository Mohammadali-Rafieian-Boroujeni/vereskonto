<?php
/**
 * CRM → Contacts: list, add/edit form, profile with timeline, and the
 * form handlers behind them.
 *
 * Every handler checks, in this order: nonce, capability, then access to
 * the specific record (VKL_Crm_Access). Input goes through the contact
 * service's validation; output is escaped where it is printed.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Contacts_Page
 */
final class VKL_Crm_Contacts_Page
{
    private const FORM_STASH = 'vkl_crm_form_';

    private VKL_Crm_Contacts $contacts;
    private VKL_Crm_Contact_Service $service;
    private VKL_Crm_Tags $tags;
    private VKL_Crm_Activities $activities;
    private VKL_Crm_Access $access;
    private VKL_Crm_Timeline $timeline;
    private VKL_Crm_Timeline_View $view;
    private VKL_Crm_Deals $deals;
    private VKL_Crm_Pipelines $pipelines;
    private VKL_Crm_Task_View $task_view;

    private ?VKL_Crm_Contacts_Table $table = null;

    public function __construct(
        VKL_Crm_Contacts $contacts,
        VKL_Crm_Contact_Service $service,
        VKL_Crm_Tags $tags,
        VKL_Crm_Activities $activities,
        VKL_Crm_Access $access,
        VKL_Crm_Timeline $timeline,
        VKL_Crm_Timeline_View $view,
        VKL_Crm_Deals $deals,
        VKL_Crm_Pipelines $pipelines,
        VKL_Crm_Task_View $task_view
    ) {
        $this->contacts   = $contacts;
        $this->service    = $service;
        $this->tags       = $tags;
        $this->activities = $activities;
        $this->access     = $access;
        $this->timeline   = $timeline;
        $this->view       = $view;
        $this->deals      = $deals;
        $this->pipelines  = $pipelines;
        $this->task_view  = $task_view;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);

        foreach (['save', 'delete', 'restore', 'claim'] as $action) {
            add_action('admin_post_vkl_crm_contact_' . $action, [$this, 'handle_' . $action]);
        }

        add_filter('set_screen_option_vkl_crm_contacts_per_page', static fn($keep, $option, $value) => max(5, min(200, (int) $value)), 10, 3);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('Contacts', 'vereskala-login'),
            __('Contacts', 'vereskala-login'),
            VKL_Crm_Access::CAP_ACCESS,
            VKL_Crm_Ui::CONTACTS_PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [$this, 'on_load']);
        }
    }

    /**
     * Before any output: assets, screen options, the ?user= shortcut,
     * bulk actions, and the list table (it must exist before the screen
     * options panel is printed).
     */
    public function on_load(): void
    {
        VKL_Crm_Ui::enqueue();

        // ?user=ID → that account's contact (created if missing).
        if (isset($_GET['user'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $contact_id = $this->service->for_user(absint($_GET['user'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $contact    = $contact_id > 0 ? $this->contacts->find($contact_id) : null;

            if (null === $contact || !$this->access->can_view($contact)) {
                VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), null === $contact ? 'missing' : 'denied');
            }

            wp_safe_redirect(VKL_Crm_Ui::contact_url($contact_id));
            exit;
        }

        if ($this->is_list_view()) {
            add_screen_option('per_page', ['label' => __('Contacts per page', 'vereskala-login'), 'default' => 20, 'option' => 'vkl_crm_contacts_per_page']);
            add_filter('default_hidden_columns', static fn(array $hidden, WP_Screen $screen): array => $screen->id === get_current_screen()->id ? VKL_Crm_Contacts_Table::DEFAULT_HIDDEN : $hidden, 10, 2);

            $this->table = new VKL_Crm_Contacts_Table($this->contacts, $this->tags, $this->access);
            $this->maybe_bulk();
        }
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_ACCESS)) {
            return;
        }

        $id     = isset($_GET['contact']) ? absint($_GET['contact']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        echo '<div class="wrap vkl-crm">';

        if ('new' === $action) {
            $this->render_form(null);
        } elseif ($id > 0) {
            $contact = $this->contacts->find($id, $this->access->can(VKL_Crm_Access::CAP_DELETE));

            if (null === $contact || !$this->access->can_view($contact)) {
                echo '<h1>' . esc_html__('Contacts', 'vereskala-login') . '</h1>';
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html(null === $contact ? __('That contact no longer exists.', 'vereskala-login') : __('You are not allowed to see this contact.', 'vereskala-login')));
            } elseif ('edit' === $action && $this->access->can_edit($contact)) {
                $this->render_form($contact);
            } else {
                $this->render_profile($contact);
            }
        } else {
            $this->render_list();
        }

        echo '</div>';
    }

    private function is_list_view(): bool
    {
        // "action" is also WP_List_Table's bulk-action parameter.
        return empty($_GET['contact']) && !in_array($_GET['action'] ?? '', ['new', 'edit'], true); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /* ================================================================== *
     *  List
     * ================================================================== */

    private function render_list(): void
    {
        $f = VKL_Crm_Contacts_Table::read_filters();
        ?>
        <div class="vkl-crm-head">
            <h1 class="wp-heading-inline"><?php esc_html_e('Contacts', 'vereskala-login'); ?></h1>
            <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) : ?>
                <a href="<?php echo esc_url(VKL_Crm_Ui::contacts_url(['action' => 'new'])); ?>" class="page-title-action"><?php esc_html_e('Add contact', 'vereskala-login'); ?></a>
            <?php endif; ?>
            <?php if ($this->access->can(VKL_Crm_Access::CAP_EXPORT)) : ?>
                <a href="<?php echo esc_url(VKL_Crm_Reports_Page::export_url('contacts')); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'vereskala-login'); ?></a>
            <?php endif; ?>
            <?php $this->backfill_notice(); ?>
        </div>
        <hr class="wp-header-end">
        <?php VKL_Crm_Ui::notices(); ?>

        <form method="get" class="vkl-crm-filters" role="search">
            <input type="hidden" name="page" value="<?php echo esc_attr(VKL_Crm_Ui::CONTACTS_PAGE); ?>">
            <?php if (!empty($_GET['deleted'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <input type="hidden" name="deleted" value="1">
            <?php endif; ?>
            <div class="vkl-crm-search">
                <label class="screen-reader-text" for="vkl-crm-q"><?php esc_html_e('Search contacts', 'vereskala-login'); ?></label>
                <input type="search" id="vkl-crm-q" name="q" value="<?php echo esc_attr((string) ($f['q'] ?? '')); ?>" placeholder="<?php esc_attr_e('Name, mobile, email or company…', 'vereskala-login'); ?>">
                <button class="button button-primary"><?php esc_html_e('Search', 'vereskala-login'); ?></button>
                <?php if ([] !== $f) : ?>
                    <a class="button" href="<?php echo esc_url(VKL_Crm_Ui::contacts_url()); ?>"><?php esc_html_e('Clear filters', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </div>
            <details class="vkl-crm-advanced" <?php echo count(array_diff_key($f, ['q' => 1, 'lifecycle' => 1])) > 0 ? 'open' : ''; ?>>
                <summary><?php esc_html_e('Advanced filters', 'vereskala-login'); ?></summary>
                <div class="vkl-crm-filter-grid">
                    <?php if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) : ?>
                        <label><span><?php esc_html_e('Owner', 'vereskala-login'); ?></span>
                            <?php
                            $owners = ['' => __('Anyone', 'vereskala-login'), '-1' => __('Unassigned', 'vereskala-login')];
                            foreach ($this->access->assignable_users() as $uid => $name) {
                                $owners[(string) $uid] = $name;
                            }
                            echo VKL_Crm_Ui::select('owner_id', $owners, (string) ($f['owner_id'] ?? '')); // phpcs:ignore
                            ?>
                        </label>
                    <?php endif; ?>
                    <label><span><?php esc_html_e('Stage', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('lifecycle', VKL_Crm_Ui::lifecycles(), (string) ($f['lifecycle'] ?? ''), __('Any', 'vereskala-login')); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Source', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('source', VKL_Crm_Ui::sources(), (string) ($f['source'] ?? ''), __('Any', 'vereskala-login')); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Tag', 'vereskala-login'); ?></span>
                        <?php
                        $tag_opts = [];
                        foreach ($this->tags->all() as $t) {
                            $tag_opts[(string) $t['id']] = $t['name'];
                        }
                        echo VKL_Crm_Ui::select('tag_id', $tag_opts, (string) ($f['tag_id'] ?? ''), __('Any', 'vereskala-login')); // phpcs:ignore
                        ?>
                    </label>
                    <label><span><?php esc_html_e('City', 'vereskala-login'); ?></span><input type="text" name="city" value="<?php echo esc_attr((string) ($f['city'] ?? '')); ?>"></label>
                    <label><span><?php esc_html_e('Account', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('has_user', ['1' => __('Has a site account', 'vereskala-login'), '0' => __('No account', 'vereskala-login')], (string) ($f['has_user'] ?? ''), __('Any', 'vereskala-login')); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Spent at least', 'vereskala-login'); ?></span><input type="number" min="0" step="any" name="min_spent" value="<?php echo esc_attr((string) ($f['min_spent'] ?? '')); ?>"></label>
                    <label><span><?php esc_html_e('Spent at most', 'vereskala-login'); ?></span><input type="number" min="0" step="any" name="max_spent" value="<?php echo esc_attr((string) ($f['max_spent'] ?? '')); ?>"></label>
                    <label><span><?php esc_html_e('Orders at least', 'vereskala-login'); ?></span><input type="number" min="0" name="min_orders" value="<?php echo esc_attr((string) ($f['min_orders'] ?? '')); ?>"></label>
                    <?php
                    $dates = [
                        'created_from' => __('Added from', 'vereskala-login'),
                        'created_to'   => __('Added until', 'vereskala-login'),
                        'active_from'  => __('Active from', 'vereskala-login'),
                        'active_to'    => __('Active until', 'vereskala-login'),
                    ];
                    foreach ($dates as $key => $label) :
                        ?>
                        <label><span><?php echo esc_html($label); ?></span><input type="text" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr(isset($f[$key]) ? VKL_Jalali::format($f[$key] . ' 12:00:00', 'short') : ''); ?>" placeholder="1405/09/01" inputmode="numeric" dir="ltr"></label>
                    <?php endforeach; ?>
                </div>
                <p><button class="button"><?php esc_html_e('Apply filters', 'vereskala-login'); ?></button></p>
            </details>
        </form>

        <?php if (null === $this->table) {
            return;
        }

        $this->table->prepare_items();
        $this->table->views();
        ?>
        <form method="get" class="vkl-crm-table-form">
            <input type="hidden" name="page" value="<?php echo esc_attr(VKL_Crm_Ui::CONTACTS_PAGE); ?>">
            <?php
            foreach ($f as $key => $value) {
                printf('<input type="hidden" name="%1$s" value="%2$s">', esc_attr($key), esc_attr((string) $value));
            }
            if (!empty($_GET['deleted'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                echo '<input type="hidden" name="deleted" value="1">';
            }
            $this->table->display();
            ?>
        </form>
        <?php
    }

    /**
     * While the one-time import of existing customers runs, say so.
     */
    private function backfill_notice(): void
    {
        $state = vkl('crm.backfill')->state();

        if (in_array($state['stage'], ['users', 'guests'], true)) {
            printf(
                '<span class="vkl-crm-importing"><span class="spinner is-active"></span>%s</span>',
                esc_html(sprintf(
                    /* translators: %s: number of customers imported so far */
                    __('Importing existing customers… %s so far', 'vereskala-login'),
                    number_format_i18n((int) $state['users'] + (int) $state['guests'])
                ))
            );
        }
    }

    /**
     * Bulk actions arrive by GET with WP_List_Table's nonce.
     */
    private function maybe_bulk(): void
    {
        $action = $this->table->current_action();

        if (!$action || empty($_GET['ids'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        check_admin_referer('bulk-contacts');

        $ids   = array_filter(array_map('absint', (array) wp_unslash($_GET['ids'])));
        $me    = get_current_user_id();
        $count = 0;

        $owner     = isset($_GET['bulk_owner']) ? absint($_GET['bulk_owner']) : 0;
        $lifecycle = isset($_GET['bulk_lifecycle']) ? sanitize_key((string) $_GET['bulk_lifecycle']) : '';
        $tag       = isset($_GET['bulk_tag']) ? absint($_GET['bulk_tag']) : 0;

        if (('lifecycle' === $action && !in_array($lifecycle, VKL_Crm_Contacts::LIFECYCLES, true)) || (in_array($action, ['tag', 'untag'], true) && null === $this->tags->find($tag))) {
            VKL_Crm_Ui::redirect($this->list_url_from_request(), 'invalid');
        }

        foreach (array_slice($ids, 0, 500) as $id) {
            $c = $this->contacts->find($id, 'restore' === $action);

            if (null === $c) {
                continue;
            }

            switch ($action) {
                case 'claim':
                    if ($this->access->can_claim($c)) {
                        $this->set_owner($c, $me);
                        ++$count;
                    }
                    break;
                case 'assign':
                    if ($this->access->can_assign($c, $owner)) {
                        $this->set_owner($c, $owner);
                        ++$count;
                    }
                    break;
                case 'lifecycle':
                    if ($this->access->can_edit($c) && $c['lifecycle'] !== $lifecycle) {
                        $this->contacts->update($id, ['lifecycle' => $lifecycle]);
                        $this->log_lifecycle($id, $c['lifecycle'], $lifecycle);
                        ++$count;
                    }
                    break;
                case 'tag':
                case 'untag':
                    if ($this->access->can_edit($c)) {
                        'tag' === $action ? $this->tags->attach($id, $tag) : $this->tags->detach($id, $tag);
                        ++$count;
                    }
                    break;
                case 'delete':
                    if ($this->access->can_delete($c) && $this->contacts->soft_delete($id)) {
                        ++$count;
                    }
                    break;
                case 'restore':
                    if ($this->access->can(VKL_Crm_Access::CAP_DELETE) && null !== $c['deleted_at'] && $this->contacts->restore($id)) {
                        ++$count;
                    }
                    break;
            }
        }

        VKL_Crm_Ui::redirect($this->list_url_from_request(), 'bulk', ['vkl_count' => $count]);
    }

    /**
     * The list URL with the current filters, minus bulk-action noise.
     */
    private function list_url_from_request(): string
    {
        $keep = array_intersect_key(wp_unslash($_GET), array_flip(array_merge(VKL_Crm_Contacts_Table::FILTERS, ['deleted', 'paged', 'orderby', 'order']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return VKL_Crm_Ui::contacts_url(array_map(static fn($v) => sanitize_text_field((string) $v), $keep));
    }

    /* ================================================================== *
     *  Add / edit form
     * ================================================================== */

    /**
     * @param array<string, mixed>|null $contact
     */
    private function render_form(?array $contact): void
    {
        if (null === $contact && !$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            echo '<p>' . esc_html__('You are not allowed to add contacts.', 'vereskala-login') . '</p>';

            return;
        }

        $id    = null === $contact ? 0 : (int) $contact['id'];
        $stash = get_transient(self::FORM_STASH . get_current_user_id());
        $error = '';
        $dupe  = 0;

        if (is_array($stash) && (int) ($stash['id'] ?? -1) === $id && isset($_GET['vkl_err'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $contact = array_merge((array) $contact, (array) $stash['input']);
            $error   = (string) $stash['error'];
            $dupe    = (int) ($stash['duplicate'] ?? 0);
            delete_transient(self::FORM_STASH . get_current_user_id());
        }

        $v = static fn(string $key): string => (string) ($contact[$key] ?? '');

        $current_tags = isset($contact['tag_ids']) ? array_map('intval', (array) $contact['tag_ids']) : ($id > 0 ? array_map(static fn(array $t): int => (int) $t['id'], $this->tags->for_contact($id)) : []);
        $birthday     = $v('birthday');
        $birthday     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) ? VKL_Jalali::format($birthday . ' 12:00:00', 'short') : $birthday;
        $back         = $id > 0 ? VKL_Crm_Ui::contact_url($id) : VKL_Crm_Ui::contacts_url();
        $owner        = null === $contact || !isset($contact['owner_id']) ? get_current_user_id() : (int) $contact['owner_id'];
        ?>
        <a class="vkl-crm-back" href="<?php echo esc_url($back); ?>">→ <?php echo esc_html($id > 0 ? __('Back to contact', 'vereskala-login') : __('All contacts', 'vereskala-login')); ?></a>
        <h1><?php echo esc_html($id > 0 ? sprintf(/* translators: %s: contact name */ __('Edit %s', 'vereskala-login'), VKL_Crm_Ui::contact_name((array) $contact)) : __('Add contact', 'vereskala-login')); ?></h1>
        <hr class="wp-header-end">

        <?php if ('' !== $error) : ?>
            <div class="notice notice-error"><p>
                <?php echo esc_html($error); ?>
                <?php if ($dupe > 0) : ?>
                    <a href="<?php echo esc_url(VKL_Crm_Ui::contact_url($dupe)); ?>"><?php esc_html_e('Open the existing contact', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(VKL_Crm_Ui::post_url()); ?>" class="vkl-crm-form" novalidate>
            <input type="hidden" name="action" value="vkl_crm_contact_save">
            <input type="hidden" name="contact" value="<?php echo esc_attr((string) $id); ?>">
            <input type="hidden" name="_vkl_back" value="<?php echo esc_attr($back); ?>">
            <?php wp_nonce_field('vkl_crm_save_' . $id); ?>

            <div class="vkl-crm-card">
                <h2><?php esc_html_e('Contact details', 'vereskala-login'); ?></h2>
                <div class="vkl-crm-fields">
                    <label><span><?php esc_html_e('First name', 'vereskala-login'); ?></span><input type="text" name="first_name" maxlength="100" value="<?php echo esc_attr($v('first_name')); ?>" autocomplete="off"></label>
                    <label><span><?php esc_html_e('Last name', 'vereskala-login'); ?></span><input type="text" name="last_name" maxlength="100" value="<?php echo esc_attr($v('last_name')); ?>" autocomplete="off"></label>
                    <label><span><?php esc_html_e('Mobile', 'vereskala-login'); ?></span><input type="tel" name="mobile" dir="ltr" inputmode="tel" value="<?php echo esc_attr($v('mobile')); ?>" placeholder="09xxxxxxxxx"></label>
                    <label><span><?php esc_html_e('Email', 'vereskala-login'); ?></span><input type="email" name="email" dir="ltr" maxlength="190" value="<?php echo esc_attr($v('email')); ?>"></label>
                    <label><span><?php esc_html_e('Company', 'vereskala-login'); ?></span><input type="text" name="company" maxlength="190" value="<?php echo esc_attr($v('company')); ?>"></label>
                    <label><span><?php esc_html_e('City', 'vereskala-login'); ?></span><input type="text" name="city" maxlength="100" value="<?php echo esc_attr($v('city')); ?>"></label>
                    <label><span><?php esc_html_e('Birthday', 'vereskala-login'); ?></span><input type="text" name="birthday" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($birthday); ?>" placeholder="1370/06/15"></label>
                </div>
                <p class="description"><?php esc_html_e('At least a name, mobile or email. The mobile number must be unique.', 'vereskala-login'); ?></p>
            </div>

            <div class="vkl-crm-card">
                <h2><?php esc_html_e('Sales', 'vereskala-login'); ?></h2>
                <div class="vkl-crm-fields">
                    <label><span><?php esc_html_e('Stage', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('lifecycle', VKL_Crm_Ui::lifecycles(), '' !== $v('lifecycle') ? $v('lifecycle') : 'lead'); // phpcs:ignore ?></label>
                    <label><span><?php esc_html_e('Source', 'vereskala-login'); ?></span>
                        <?php
                        $sources = VKL_Crm_Ui::sources();
                        if ('' !== $v('source') && !isset($sources[$v('source')])) {
                            $sources[$v('source')] = $v('source');
                        }
                        echo VKL_Crm_Ui::select('source', $sources, '' !== $v('source') ? $v('source') : 'manual'); // phpcs:ignore
                        ?>
                    </label>
                    <label><span><?php esc_html_e('Owner', 'vereskala-login'); ?></span>
                        <?php
                        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL)) {
                            echo VKL_Crm_Ui::owner_select('owner_id', $owner); // phpcs:ignore
                        } else {
                            echo '<input type="text" readonly value="' . esc_attr(VKL_Crm_Ui::user_name(0 === $id ? get_current_user_id() : $owner)) . '">';
                        }
                        ?>
                    </label>
                    <label><span><?php esc_html_e('Score', 'vereskala-login'); ?></span><input type="number" name="score" min="-1000" max="1000" value="<?php echo esc_attr('' !== $v('score') ? $v('score') : '0'); ?>"></label>
                </div>
            </div>

            <?php $all_tags = $this->tags->all(); ?>
            <div class="vkl-crm-card">
                <h2><?php esc_html_e('Categories and tags', 'vereskala-login'); ?></h2>
                <?php if ([] === $all_tags) : ?>
                    <p class="description"><?php esc_html_e('No tags yet. Type new ones below, separated by commas.', 'vereskala-login'); ?></p>
                <?php else : ?>
                    <?php foreach (VKL_Crm_Tags::TYPES as $type) :
                        $of_type = array_filter($all_tags, static fn(array $t): bool => $t['type'] === $type);
                        if ([] === $of_type) {
                            continue;
                        }
                        ?>
                        <fieldset class="vkl-crm-tagset">
                            <legend><?php echo esc_html('category' === $type ? __('Categories', 'vereskala-login') : __('Tags', 'vereskala-login')); ?></legend>
                            <?php foreach ($of_type as $t) : ?>
                                <label class="vkl-crm-check"><input type="checkbox" name="tag_ids[]" value="<?php echo esc_attr((string) $t['id']); ?>" <?php checked(in_array((int) $t['id'], $current_tags, true)); ?>> <?php echo VKL_Crm_Ui::tag_chips([$t]); // phpcs:ignore ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($this->access->can(VKL_Crm_Access::CAP_SETTINGS)) : ?>
                    <label class="vkl-crm-wide"><span><?php esc_html_e('New tags', 'vereskala-login'); ?></span><input type="text" name="new_tags" value="<?php echo esc_attr($v('new_tags')); ?>" placeholder="<?php esc_attr_e('e.g. VIP, wholesale', 'vereskala-login'); ?>"></label>
                <?php endif; ?>
            </div>

            <p class="submit">
                <button class="button button-primary button-large"><?php echo esc_html($id > 0 ? __('Save changes', 'vereskala-login') : __('Add contact', 'vereskala-login')); ?></button>
                <a class="button button-large" href="<?php echo esc_url($back); ?>"><?php esc_html_e('Cancel', 'vereskala-login'); ?></a>
            </p>
        </form>
        <?php
    }

    public function handle_save(): void
    {
        $id = isset($_POST['contact']) ? absint($_POST['contact']) : 0;
        check_admin_referer('vkl_crm_save_' . $id);

        $me       = get_current_user_id();
        $existing = $id > 0 ? $this->contacts->find($id) : null;

        if ($id > 0 && (null === $existing || !$this->access->can_edit($existing))) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), null === $existing ? 'missing' : 'denied');
        }

        if (0 === $id && !$this->access->can(VKL_Crm_Access::CAP_EDIT_OWN)) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), 'denied');
        }

        $fields = ['first_name', 'last_name', 'mobile', 'email', 'company', 'city', 'birthday', 'lifecycle', 'source', 'score'];
        $input  = [];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $input[$field] = wp_unslash((string) $_POST[$field]);
            }
        }

        // Owner: managers choose (anyone with CRM access); agents always
        // own what they create and cannot change it here.
        if ($this->access->can(VKL_Crm_Access::CAP_EDIT_ALL) && isset($_POST['owner_id'])) {
            $new_owner = absint($_POST['owner_id']);

            if ($this->access->can_assign((array) $existing, $new_owner)) {
                $input['owner_id'] = $new_owner;
            }
        } elseif (0 === $id) {
            $input['owner_id'] = $me;
        }

        $tag_ids = isset($_POST['tag_ids']) ? array_map('absint', (array) wp_unslash($_POST['tag_ids'])) : [];

        if (!empty($_POST['new_tags']) && $this->access->can(VKL_Crm_Access::CAP_SETTINGS)) {
            foreach (explode(',', str_replace('،', ',', wp_unslash((string) $_POST['new_tags']))) as $name) {
                $tag_id = $this->tags->ensure($name);

                if ($tag_id > 0) {
                    $tag_ids[] = $tag_id;
                }
            }
        }

        // Only tags that exist.
        $valid   = array_map(static fn(array $t): int => (int) $t['id'], $this->tags->all());
        $tag_ids = array_values(array_intersect(array_unique($tag_ids), $valid));

        if (0 === $id) {
            $result = $this->service->create($input + ['source' => 'manual']);
        } else {
            $result = $this->service->update($id, $input);
        }

        if (is_wp_error($result)) {
            set_transient(self::FORM_STASH . $me, [
                'id'        => $id,
                'input'     => $input + ['tag_ids' => $tag_ids, 'new_tags' => isset($_POST['new_tags']) ? sanitize_text_field(wp_unslash((string) $_POST['new_tags'])) : ''],
                'error'     => $result->get_error_message(),
                'duplicate' => (int) ($result->get_error_data()['contact_id'] ?? 0),
            ], 300);

            $form = 0 === $id ? VKL_Crm_Ui::contacts_url(['action' => 'new']) : VKL_Crm_Ui::contact_url($id, ['action' => 'edit']);
            wp_safe_redirect(add_query_arg('vkl_err', '1', $form));
            exit;
        }

        $contact_id = 0 === $id ? (int) $result : $id;
        $this->tags->set_for_contact($contact_id, $tag_ids);

        if (0 === $id) {
            $this->timeline->system($contact_id, __('Contact created', 'vereskala-login'));
        } else {
            if (isset($input['owner_id']) && (int) $existing['owner_id'] !== (int) $input['owner_id']) {
                $this->log_owner($contact_id, (int) $input['owner_id']);
            }

            if (isset($input['lifecycle']) && $existing['lifecycle'] !== $input['lifecycle']) {
                $this->log_lifecycle($contact_id, (string) $existing['lifecycle'], (string) $input['lifecycle']);
            }
        }

        VKL_Crm_Ui::redirect(VKL_Crm_Ui::contact_url($contact_id), 0 === $id ? 'created' : 'saved');
    }

    /* ================================================================== *
     *  Profile
     * ================================================================== */

    /**
     * @param array<string, mixed> $c
     */
    private function render_profile(array $c): void
    {
        $id       = (int) $c['id'];
        $can_edit = $this->access->can_edit($c);
        $deleted  = null !== $c['deleted_at'];
        $tags     = $this->tags->for_contact($id);
        $types    = isset($_GET['types']) ? array_intersect(explode(',', sanitize_text_field(wp_unslash((string) $_GET['types']))), array_keys(VKL_Crm_Ui::activity_types())) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $before   = isset($_GET['before']) ? absint($_GET['before']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $items    = $this->activities->timeline($id, 30, $before, $types);
        $back     = VKL_Crm_Ui::contact_url($id);
        ?>
        <a class="vkl-crm-back" href="<?php echo esc_url(VKL_Crm_Ui::contacts_url()); ?>">→ <?php esc_html_e('All contacts', 'vereskala-login'); ?></a>
        <hr class="wp-header-end"><?php // other plugins' notices land here, not inside the header card ?>
        <?php VKL_Crm_Ui::notices(); ?>

        <?php if ($deleted) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This contact is in the deleted list.', 'vereskala-login'); ?>
                <?php if ($this->access->can(VKL_Crm_Access::CAP_DELETE)) : ?>
                    <a href="<?php echo esc_url(VKL_Crm_Ui::action_url('restore', $id)); ?>"><?php esc_html_e('Restore', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </p></div>
        <?php endif; ?>

        <header class="vkl-crm-profile-head">
            <span class="vkl-crm-avatar is-large" aria-hidden="true"><?php echo esc_html(VKL_Crm_Ui::initial($c)); ?></span>
            <div class="vkl-crm-who">
                <h1><?php echo esc_html(VKL_Crm_Ui::contact_name($c)); ?></h1>
                <div class="vkl-crm-meta-line">
                    <?php echo VKL_Crm_Ui::lifecycle_pill((string) $c['lifecycle']); // phpcs:ignore ?>
                    <span class="vkl-crm-owner"><span class="dashicons dashicons-businessperson" aria-hidden="true"></span><?php echo esc_html(VKL_Crm_Ui::user_name((int) $c['owner_id'])); ?></span>
                    <?php echo VKL_Crm_Ui::tag_chips($tags); // phpcs:ignore ?>
                </div>
            </div>
            <div class="vkl-crm-actions">
                <?php if (!empty($c['mobile'])) : ?>
                    <a class="button" href="tel:<?php echo esc_attr((string) $c['mobile']); ?>"><span class="dashicons dashicons-phone" aria-hidden="true"></span> <?php esc_html_e('Call', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <?php if ($can_edit && !$deleted) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(VKL_Crm_Ui::contact_url($id, ['action' => 'edit'])); ?>"><?php esc_html_e('Edit', 'vereskala-login'); ?></a>
                <?php elseif ($this->access->can_claim($c) && !$deleted) : ?>
                    <a class="button button-primary" href="<?php echo esc_url(VKL_Crm_Ui::action_url('claim', $id)); ?>"><?php esc_html_e('Assign to me', 'vereskala-login'); ?></a>
                <?php endif; ?>
                <?php if (!$deleted && $this->access->can_delete($c)) : ?>
                    <a class="button vkl-crm-danger" data-confirm="delete" href="<?php echo esc_url(VKL_Crm_Ui::action_url('delete', $id)); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </div>
        </header>

        <div class="vkl-crm-profile">
            <aside class="vkl-crm-side">
                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Details', 'vereskala-login'); ?></h2>
                    <dl class="vkl-crm-dl">
                        <?php
                        $rows = [
                            __('Mobile', 'vereskala-login')  => empty($c['mobile']) ? '' : '<a dir="ltr" href="tel:' . esc_attr((string) $c['mobile']) . '">' . esc_html((string) $c['mobile']) . '</a>',
                            __('Email', 'vereskala-login')   => empty($c['email']) ? '' : '<a dir="ltr" href="mailto:' . esc_attr((string) $c['email']) . '">' . esc_html((string) $c['email']) . '</a>',
                            __('Company', 'vereskala-login') => esc_html((string) $c['company']),
                            __('City', 'vereskala-login')    => esc_html((string) $c['city']),
                            __('Birthday', 'vereskala-login')=> empty($c['birthday']) ? '' : esc_html(VKL_Jalali::format($c['birthday'] . ' 12:00:00')),
                            __('Source', 'vereskala-login')  => esc_html(VKL_Crm_Ui::label(VKL_Crm_Ui::sources(), (string) $c['source'])),
                            __('Score', 'vereskala-login')   => esc_html(number_format_i18n((int) $c['score'])),
                            __('Added', 'vereskala-login')   => VKL_Crm_Ui::date((string) $c['created_at']),
                        ];
                        foreach ($rows as $label => $value) {
                            if ('' !== $value && '—' !== $value) {
                                echo '<dt>' . esc_html($label) . '</dt><dd>' . $value . '</dd>'; // phpcs:ignore -- values escaped above.
                            }
                        }
                        ?>
                    </dl>
                    <?php $dup = $this->contacts->get_meta($id, 'duplicate_mobile'); ?>
                    <?php if (null !== $dup && '' !== $dup) : ?>
                        <p class="vkl-crm-warn"><?php echo esc_html(sprintf(/* translators: %s: mobile number */ __('Another contact already uses this customer\'s account mobile (%s). Check for a duplicate.', 'vereskala-login'), $dup)); ?></p>
                    <?php endif; ?>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Purchases', 'vereskala-login'); ?></h2>
                    <div class="vkl-crm-stats">
                        <div><strong><?php echo esc_html(number_format_i18n((int) $c['orders_count'])); ?></strong><span><?php esc_html_e('Orders', 'vereskala-login'); ?></span></div>
                        <div><strong><?php echo VKL_Crm_Ui::money((float) $c['total_spent']); // phpcs:ignore ?></strong><span><?php esc_html_e('Total spent', 'vereskala-login'); ?></span></div>
                    </div>
                    <?php $this->orders_list($c); ?>
                    <?php if (!empty($c['user_id']) && current_user_can('manage_options')) : ?>
                        <p class="vkl-crm-links">
                            <a href="<?php echo esc_url(VKL_Customers_Admin::profile_url((int) $c['user_id'])); ?>"><?php esc_html_e('Wallet, installments and club', 'vereskala-login'); ?> ←</a>
                            <a href="<?php echo esc_url(get_edit_user_link((int) $c['user_id'])); ?>"><?php esc_html_e('Site account', 'vereskala-login'); ?> ←</a>
                        </p>
                    <?php endif; ?>
                </section>
                <?php $this->deals_card($c, $can_edit && !$deleted); ?>
                <?php $this->task_view->card($id, 0, $back, $can_edit && !$deleted); ?>
            </aside>

            <main class="vkl-crm-main">
                <?php if ($can_edit && !$deleted) : ?>
                    <?php $this->view->composer($id, $back); ?>
                <?php elseif (!$deleted && $this->access->can_claim($c)) : ?>
                    <div class="vkl-crm-card vkl-crm-hint"><?php esc_html_e('Assign this contact to yourself to add notes, calls and meetings.', 'vereskala-login'); ?></div>
                <?php endif; ?>

                <section class="vkl-crm-card">
                    <div class="vkl-crm-timeline-head">
                        <h2><?php esc_html_e('Timeline', 'vereskala-login'); ?></h2>
                        <nav class="vkl-crm-chips" aria-label="<?php esc_attr_e('Filter timeline', 'vereskala-login'); ?>">
                            <?php
                            $filters = [
                                ''                     => __('Everything', 'vereskala-login'),
                                'note'                 => __('Notes', 'vereskala-login'),
                                'call,meeting'         => __('Calls and meetings', 'vereskala-login'),
                                'order,return'         => __('Orders', 'vereskala-login'),
                                'ticket'               => __('Support', 'vereskala-login'),
                                'wallet,installment'   => __('Money', 'vereskala-login'),
                                'system,stage,task'    => __('Changes', 'vereskala-login'),
                            ];
                            $active = implode(',', $types);
                            foreach ($filters as $key => $label) {
                                printf(
                                    '<a href="%1$s" class="%2$s">%3$s</a>',
                                    esc_url('' === $key ? $back : add_query_arg('types', $key, $back)),
                                    $active === $key ? 'is-active' : '',
                                    esc_html($label)
                                );
                            }
                            ?>
                        </nav>
                    </div>
                    <?php $this->view->timeline_list($items, $c, $back); ?>
                    <?php if (30 === count($items)) : ?>
                        <p class="vkl-crm-more"><a class="button" href="<?php echo esc_url(add_query_arg(['before' => (int) end($items)['id'], 'types' => $active ?: null], $back)); ?>"><?php esc_html_e('Older entries', 'vereskala-login'); ?></a></p>
                    <?php endif; ?>
                    <?php if ($before > 0) : ?>
                        <p class="vkl-crm-more"><a href="<?php echo esc_url($back); ?>"><?php esc_html_e('Back to the newest', 'vereskala-login'); ?></a></p>
                    <?php endif; ?>
                </section>
            </main>
        </div>
        <?php
    }
    /**
     * @param array<string, mixed> $c
     */
    private function orders_list(array $c): void
    {
        $orders = $this->service->orders($c, [], 5);

        if ([] === $orders) {
            echo '<p class="description">' . esc_html__('No orders yet.', 'vereskala-login') . '</p>';

            return;
        }

        $can_open = current_user_can('edit_shop_orders');

        echo '<ul class="vkl-crm-orders">';

        foreach ($orders as $o) {
            $label = '#' . $o->get_order_number();
            printf(
                '<li>%1$s <span class="vkl-crm-pill is-order">%2$s</span> <span class="vkl-crm-order-total">%3$s</span> <span class="description">%4$s</span></li>',
                $can_open ? '<a href="' . esc_url((string) $o->get_edit_order_url()) . '">' . esc_html($label) . '</a>' : esc_html($label),
                esc_html(wc_get_order_status_name($o->get_status())),
                VKL_Crm_Ui::money((float) $o->get_total()),
                $o->get_date_created() ? esc_html(VKL_Jalali::format(gmdate('Y-m-d H:i:s', $o->get_date_created()->getTimestamp()))) : ''
            );
        }

        echo '</ul>';
    }

    /* ================================================================== *
     *  Handlers
     * ================================================================== */

    public function handle_delete(): void
    {
        $c = $this->guarded_row_action('delete');

        if (!$this->access->can_delete($c)) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), 'denied');
        }

        $this->contacts->soft_delete((int) $c['id']);
        VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), 'deleted');
    }

    public function handle_restore(): void
    {
        $c = $this->guarded_row_action('restore', true);

        if (!$this->access->can(VKL_Crm_Access::CAP_DELETE)) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), 'denied');
        }

        $this->contacts->restore((int) $c['id']);
        VKL_Crm_Ui::redirect(VKL_Crm_Ui::contact_url((int) $c['id']), 'restored');
    }

    public function handle_claim(): void
    {
        $c = $this->guarded_row_action('claim');

        if (!$this->access->can_claim($c)) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contact_url((int) $c['id']), 'denied');
        }

        $this->set_owner($c, get_current_user_id());
        VKL_Crm_Ui::redirect(VKL_Crm_Ui::contact_url((int) $c['id']), 'claimed');
    }

    /**
     * Nonce + existence check shared by the single-contact GET actions.
     *
     * @return array<string, mixed>
     */
    private function guarded_row_action(string $action, bool $with_deleted = false): array
    {
        $id = isset($_GET['contact']) ? absint($_GET['contact']) : 0;
        check_admin_referer('vkl_crm_' . $action . '_' . $id);

        $c = $this->contacts->find($id, $with_deleted);

        if (null === $c || !$this->access->can_view($c)) {
            VKL_Crm_Ui::redirect(VKL_Crm_Ui::contacts_url(), null === $c ? 'missing' : 'denied');
        }

        return $c;
    }

    /* ================================================================== */

    /**
     * @param array<string, mixed> $c
     */
    private function set_owner(array $c, int $owner): void
    {
        if ((int) $c['owner_id'] === $owner) {
            return;
        }

        $this->contacts->update((int) $c['id'], ['owner_id' => $owner]);
        $this->log_owner((int) $c['id'], $owner);
    }

    private function log_owner(int $contact_id, int $owner): void
    {
        $this->timeline->system($contact_id, $owner > 0
            ? sprintf(/* translators: %s: staff member */ __('Assigned to %s', 'vereskala-login'), VKL_Crm_Ui::user_name($owner))
            : __('Unassigned', 'vereskala-login'));
    }

    private function log_lifecycle(int $contact_id, string $from, string $to): void
    {
        $labels = VKL_Crm_Ui::lifecycles();
        $this->timeline->system($contact_id, sprintf(
            /* translators: 1: old stage, 2: new stage */
            __('Stage changed: %1$s → %2$s', 'vereskala-login'),
            VKL_Crm_Ui::label($labels, $from),
            VKL_Crm_Ui::label($labels, $to)
        ));
    }
    /**
     * The contact's deals, newest first, with a button to add one.
     *
     * @param array<string, mixed> $c
     */
    private function deals_card(array $c, bool $can_add): void
    {
        $rows = $this->deals->search(['contact_id' => (int) $c['id']], $this->access->scope_sql('t.owner_id'), 1, 20)['rows'];
        ?>
        <section class="vkl-crm-card">
            <h2><?php esc_html_e('Deals', 'vereskala-login'); ?></h2>
            <?php if ([] === $rows) : ?>
                <p class="description"><?php esc_html_e('No deals yet.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <ul class="vkl-crm-deal-mini">
                    <?php foreach ($rows as $d) :
                        $stage = $this->pipelines->stage((int) $d['stage_id']);
                        ?>
                        <li>
                            <a href="<?php echo esc_url(VKL_Crm_Deals_Page::deal_url((int) $d['id'])); ?>"><?php echo esc_html((string) $d['title']); ?></a>
                            <span class="vkl-crm-pill is-deal-<?php echo esc_attr((string) $d['status']); ?>" style="--stage:<?php echo esc_attr((string) ($stage['color'] ?? '') ?: '#8c8f94'); ?>"><?php echo esc_html((string) ($stage['name'] ?? '')); ?></span>
                            <span class="vkl-crm-order-total"><?php echo VKL_Crm_Ui::money((float) $d['value']); // phpcs:ignore ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($can_add) : ?>
                <p><a class="button" href="<?php echo esc_url(VKL_Crm_Deals_Page::url(['action' => 'new', 'contact' => (int) $c['id']])); ?>"><?php esc_html_e('Add deal', 'vereskala-login'); ?></a></p>
            <?php endif; ?>
        </section>
        <?php
    }
}
