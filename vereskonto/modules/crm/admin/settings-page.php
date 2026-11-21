<?php
/**
 * CRM → Settings: tags and categories, and general CRM options.
 * (Pipelines and stages are edited here from phase 3.)
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Settings_Page
 */
final class VKL_Crm_Settings_Page
{
    private VKL_Crm_Tags $tags;
    private VKL_Crm_Access $access;
    private VKL_Config $config;

    public function __construct(VKL_Crm_Tags $tags, VKL_Crm_Access $access, VKL_Config $config)
    {
        $this->tags   = $tags;
        $this->access = $access;
        $this->config = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_vkl_crm_tag_save', [$this, 'handle_tag_save']);
        add_action('admin_post_vkl_crm_tag_delete', [$this, 'handle_tag_delete']);
        add_action('admin_post_vkl_crm_settings_save', [$this, 'handle_settings']);
    }

    public function menu(): void
    {
        $hook = add_submenu_page(
            VKL_Admin_Menu::PARENT,
            __('CRM settings', 'vereskala-login'),
            __('CRM settings', 'vereskala-login'),
            VKL_Crm_Access::CAP_SETTINGS,
            VKL_Crm_Ui::SETTINGS_PAGE,
            [$this, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [VKL_Crm_Ui::class, 'enqueue']);
        }
    }

    private function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => VKL_Crm_Ui::SETTINGS_PAGE], $args), admin_url('admin.php'));
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_SETTINGS)) {
            return;
        }

        $edit = isset($_GET['tag']) ? $this->tags->find(absint($_GET['tag'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $all  = $this->tags->all();
        ?>
        <div class="wrap vkl-crm">
            <h1><?php esc_html_e('CRM settings', 'vereskala-login'); ?></h1>
            <?php VKL_Crm_Ui::notices(); ?>

            <div class="vkl-crm-settings">
                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Categories and tags', 'vereskala-login'); ?></h2>
                    <p class="description"><?php esc_html_e('Categories group customers by type (e.g. wholesale, retail); tags are free labels (e.g. VIP, follow up). A contact can have several of each.', 'vereskala-login'); ?></p>

                    <table class="widefat striped vkl-crm-tag-table">
                        <thead><tr>
                            <th><?php esc_html_e('Name', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Kind', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Contacts', 'vereskala-login'); ?></th>
                            <th><span class="screen-reader-text"><?php esc_html_e('Actions', 'vereskala-login'); ?></span></th>
                        </tr></thead>
                        <tbody>
                        <?php if ([] === $all) : ?>
                            <tr><td colspan="4"><?php esc_html_e('No categories or tags yet.', 'vereskala-login'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($all as $t) : ?>
                            <tr>
                                <td><?php echo VKL_Crm_Ui::tag_chips([$t]); // phpcs:ignore ?></td>
                                <td><?php echo esc_html('category' === $t['type'] ? __('Category', 'vereskala-login') : __('Tag', 'vereskala-login')); ?></td>
                                <td><a href="<?php echo esc_url(VKL_Crm_Ui::contacts_url(['tag_id' => (int) $t['id']])); ?>"><?php echo esc_html(number_format_i18n((int) $t['contacts'])); ?></a></td>
                                <td class="vkl-crm-row-actions">
                                    <a href="<?php echo esc_url($this->url(['tag' => (int) $t['id']])); ?>"><?php esc_html_e('Edit', 'vereskala-login'); ?></a>
                                    <a class="vkl-crm-danger-link" data-confirm="tag" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'vkl_crm_tag_delete', 'tag' => (int) $t['id']], admin_url('admin-post.php')), 'vkl_crm_tag_delete_' . (int) $t['id'])); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3><?php echo esc_html(null !== $edit ? __('Edit tag', 'vereskala-login') : __('Add a category or tag', 'vereskala-login')); ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-crm-inline-form">
                        <input type="hidden" name="action" value="vkl_crm_tag_save">
                        <input type="hidden" name="tag" value="<?php echo esc_attr((string) ($edit['id'] ?? 0)); ?>">
                        <?php wp_nonce_field('vkl_crm_tag_save'); ?>
                        <label><span><?php esc_html_e('Name', 'vereskala-login'); ?></span><input type="text" name="name" required maxlength="100" value="<?php echo esc_attr((string) ($edit['name'] ?? '')); ?>"></label>
                        <label><span><?php esc_html_e('Kind', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('type', ['tag' => __('Tag', 'vereskala-login'), 'category' => __('Category', 'vereskala-login')], (string) ($edit['type'] ?? 'tag')); // phpcs:ignore ?></label>
                        <label><span><?php esc_html_e('Colour', 'vereskala-login'); ?></span><input type="color" name="color" value="<?php echo esc_attr('' !== (string) ($edit['color'] ?? '') ? (string) $edit['color'] : '#2271b1'); ?>"></label>
                        <button class="button button-primary"><?php echo esc_html(null !== $edit ? __('Save', 'vereskala-login') : __('Add', 'vereskala-login')); ?></button>
                        <?php if (null !== $edit) : ?>
                            <a class="button" href="<?php echo esc_url($this->url()); ?>"><?php esc_html_e('Cancel', 'vereskala-login'); ?></a>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="vkl-crm-card">
                    <h2><?php esc_html_e('Access', 'vereskala-login'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="vkl_crm_settings_save">
                        <?php wp_nonce_field('vkl_crm_settings_save'); ?>
                        <label class="vkl-crm-check">
                            <input type="checkbox" name="agents_see_unassigned" value="1" <?php checked($this->config->get_bool('crm_agents_see_unassigned', true)); ?>>
                            <?php esc_html_e('Sales agents see contacts nobody is assigned to (so they can pick up new leads)', 'vereskala-login'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Give staff the "Sales agent" role (own contacts only) or "Sales manager" role (everyone\'s contacts) under Users. Shop managers have manager access; only administrators change these settings.', 'vereskala-login'); ?>
                        </p>
                        <?php submit_button(__('Save settings', 'vereskala-login')); ?>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    public function handle_tag_save(): void
    {
        check_admin_referer('vkl_crm_tag_save');
        $this->require_settings();

        $id    = isset($_POST['tag']) ? absint($_POST['tag']) : 0;
        $name  = isset($_POST['name']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['name']))) : '';
        $type  = isset($_POST['type']) && 'category' === $_POST['type'] ? 'category' : 'tag';
        $color = isset($_POST['color']) ? (string) sanitize_hex_color(wp_unslash((string) $_POST['color'])) : '';

        if ('' === $name) {
            VKL_Crm_Ui::redirect($this->url(), 'invalid');
        }

        if ($id > 0 && null !== $this->tags->find($id)) {
            $slug  = VKL_Crm_Tags::slugify($name);
            $clash = array_filter($this->tags->all($type), static fn(array $t): bool => $t['slug'] === $slug && (int) $t['id'] !== $id);

            if ([] !== $clash) {
                VKL_Crm_Ui::redirect($this->url(['tag' => $id]), 'invalid');
            }

            $this->tags->update($id, ['name' => mb_substr($name, 0, 100), 'slug' => $slug, 'type' => $type, 'color' => (string) $color]);
        } else {
            $this->tags->ensure($name, $type, (string) $color);
        }

        VKL_Crm_Ui::redirect($this->url(), 'tag_saved');
    }

    public function handle_tag_delete(): void
    {
        $id = isset($_GET['tag']) ? absint($_GET['tag']) : 0;
        check_admin_referer('vkl_crm_tag_delete_' . $id);
        $this->require_settings();

        $this->tags->delete_tag($id);
        VKL_Crm_Ui::redirect($this->url(), 'tag_deleted');
    }

    public function handle_settings(): void
    {
        check_admin_referer('vkl_crm_settings_save');
        $this->require_settings();

        $this->config->set('crm_agents_see_unassigned', empty($_POST['agents_see_unassigned']) ? '0' : '1');
        VKL_Crm_Ui::redirect($this->url(), 'settings');
    }

    private function require_settings(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_SETTINGS)) {
            VKL_Crm_Ui::redirect($this->url(), 'denied');
        }
    }
}
