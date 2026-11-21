<?php
/**
 * CRM → Settings: sales pipelines and stages, categories and tags,
 * automatic leads, lost reasons, and access options.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0 (pipelines, leads and lost reasons since 2.17.0)
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
    private VKL_Crm_Pipelines $pipelines;

    public function __construct(VKL_Crm_Tags $tags, VKL_Crm_Access $access, VKL_Config $config, VKL_Crm_Pipelines $pipelines)
    {
        $this->tags      = $tags;
        $this->access    = $access;
        $this->config    = $config;
        $this->pipelines = $pipelines;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);

        $handlers = [
            'tag_save', 'tag_delete', 'settings_save',
            'pipeline_save', 'pipeline_default', 'pipeline_delete',
            'stage_save', 'stage_shift', 'stage_delete',
        ];

        foreach ($handlers as $h) {
            add_action('admin_post_vkl_crm_' . $h, [$this, 'handle_' . $h]);
        }
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

    private function post_link(string $action, array $args, string $nonce): string
    {
        return wp_nonce_url(add_query_arg(array_merge(['action' => 'vkl_crm_' . $action], $args), admin_url('admin-post.php')), $nonce);
    }

    public function render(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_SETTINGS)) {
            return;
        }

        $tab  = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'pipelines'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tabs = [
            'pipelines' => __('Sales pipelines', 'vereskala-login'),
            'tags'      => __('Categories and tags', 'vereskala-login'),
            'general'   => __('Leads and access', 'vereskala-login'),
        ];
        $tab = isset($tabs[$tab]) ? $tab : 'pipelines';
        ?>
        <div class="wrap vkl-crm">
            <h1><?php esc_html_e('CRM settings', 'vereskala-login'); ?></h1>
            <hr class="wp-header-end">
            <?php VKL_Crm_Ui::notices(); ?>
            <nav class="nav-tab-wrapper vkl-crm-settings-tabs">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->url(['tab' => $key])); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="vkl-crm-settings">
                <?php
                if ('tags' === $tab) {
                    $this->render_tags();
                } elseif ('general' === $tab) {
                    $this->render_general();
                } else {
                    $this->render_pipelines();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------- pipelines */

    private function render_pipelines(): void
    {
        $pipes = $this->pipelines->all();
        $pid   = isset($_GET['pipeline']) ? absint($_GET['pipeline']) : (int) ($this->pipelines->default_pipeline()['id'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $pipe  = $this->pipelines->find($pid) ?? ($pipes[0] ?? null);
        $kinds = ['open' => __('In progress', 'vereskala-login'), 'won' => __('Won', 'vereskala-login'), 'lost' => __('Lost', 'vereskala-login')];
        ?>
        <section class="vkl-crm-card">
            <h2><?php esc_html_e('Pipelines', 'vereskala-login'); ?></h2>
            <p class="description"><?php esc_html_e('Most shops need one pipeline. Add another for a different kind of sale (e.g. wholesale vs. installment purchases).', 'vereskala-login'); ?></p>
            <ul class="vkl-crm-pipes">
                <?php foreach ($pipes as $p) : ?>
                    <li class="<?php echo null !== $pipe && (int) $p['id'] === (int) $pipe['id'] ? 'is-active' : ''; ?>">
                        <a href="<?php echo esc_url($this->url(['pipeline' => (int) $p['id']])); ?>"><?php echo esc_html($p['name']); ?></a>
                        <?php if ((int) $p['is_default']) : ?>
                            <span class="vkl-crm-pill"><?php esc_html_e('Default', 'vereskala-login'); ?></span>
                        <?php else : ?>
                            <a class="vkl-crm-small" href="<?php echo esc_url($this->post_link('pipeline_default', ['pipeline' => (int) $p['id']], 'vkl_crm_pipeline_default_' . (int) $p['id'])); ?>"><?php esc_html_e('Make default', 'vereskala-login'); ?></a>
                            <a class="vkl-crm-small vkl-crm-danger-link" data-confirm="pipeline" href="<?php echo esc_url($this->post_link('pipeline_delete', ['pipeline' => (int) $p['id']], 'vkl_crm_pipeline_delete_' . (int) $p['id'])); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-crm-inline-form">
                <input type="hidden" name="action" value="vkl_crm_pipeline_save">
                <?php wp_nonce_field('vkl_crm_pipeline_save'); ?>
                <label><span><?php esc_html_e('New pipeline', 'vereskala-login'); ?></span><input type="text" name="name" required maxlength="100"></label>
                <button class="button"><?php esc_html_e('Add', 'vereskala-login'); ?></button>
            </form>
        </section>

        <?php if (null === $pipe) {
            return;
        }
        $stages = $this->pipelines->stages((int) $pipe['id']);
        ?>
        <section class="vkl-crm-card">
            <h2><?php echo esc_html(sprintf(/* translators: %s: pipeline name */ __('Stages of "%s"', 'vereskala-login'), $pipe['name'])); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-crm-inline-form vkl-crm-rename">
                <input type="hidden" name="action" value="vkl_crm_pipeline_save">
                <input type="hidden" name="pipeline" value="<?php echo esc_attr((string) $pipe['id']); ?>">
                <?php wp_nonce_field('vkl_crm_pipeline_save'); ?>
                <label><span><?php esc_html_e('Pipeline name', 'vereskala-login'); ?></span><input type="text" name="name" required maxlength="100" value="<?php echo esc_attr($pipe['name']); ?>"></label>
                <button class="button"><?php esc_html_e('Rename', 'vereskala-login'); ?></button>
            </form>

            <p class="description"><?php esc_html_e('Probability is how likely a deal in that stage is to close; it weights the pipeline value. A pipeline needs at least one "in progress", one "won" and one "lost" stage.', 'vereskala-login'); ?></p>
            <div class="vkl-crm-stage-rows">
                <?php foreach ($stages as $i => $s) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-crm-stage-row" style="--stage:<?php echo esc_attr('' !== $s['color'] ? $s['color'] : '#8c8f94'); ?>">
                        <input type="hidden" name="action" value="vkl_crm_stage_save">
                        <input type="hidden" name="pipeline" value="<?php echo esc_attr((string) $pipe['id']); ?>">
                        <input type="hidden" name="stage" value="<?php echo esc_attr((string) $s['id']); ?>">
                        <?php wp_nonce_field('vkl_crm_stage_save'); ?>
                        <span class="vkl-crm-stage-order">
                            <?php if ($i > 0) : ?><a href="<?php echo esc_url($this->post_link('stage_shift', ['stage' => (int) $s['id'], 'dir' => -1], 'vkl_crm_stage_shift_' . (int) $s['id'])); ?>" title="<?php esc_attr_e('Move up', 'vereskala-login'); ?>">▲</a><?php endif; ?>
                            <?php if ($i < count($stages) - 1) : ?><a href="<?php echo esc_url($this->post_link('stage_shift', ['stage' => (int) $s['id'], 'dir' => 1], 'vkl_crm_stage_shift_' . (int) $s['id'])); ?>" title="<?php esc_attr_e('Move down', 'vereskala-login'); ?>">▼</a><?php endif; ?>
                        </span>
                        <label><span class="screen-reader-text"><?php esc_html_e('Name', 'vereskala-login'); ?></span><input type="text" name="name" required maxlength="100" value="<?php echo esc_attr($s['name']); ?>"></label>
                        <label><span class="screen-reader-text"><?php esc_html_e('Kind', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('kind', $kinds, $s['kind']); // phpcs:ignore ?></label>
                        <label class="vkl-crm-prob"><input type="number" name="probability" min="0" max="100" value="<?php echo esc_attr((string) $s['probability']); ?>"> ٪</label>
                        <label><span class="screen-reader-text"><?php esc_html_e('Colour', 'vereskala-login'); ?></span><input type="color" name="color" value="<?php echo esc_attr('' !== $s['color'] ? $s['color'] : '#8c8f94'); ?>"></label>
                        <button class="button button-small"><?php esc_html_e('Save', 'vereskala-login'); ?></button>
                        <a class="vkl-crm-small vkl-crm-danger-link" data-confirm="stage" href="<?php echo esc_url($this->post_link('stage_delete', ['stage' => (int) $s['id']], 'vkl_crm_stage_delete_' . (int) $s['id'])); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
                    </form>
                <?php endforeach; ?>
            </div>

            <h3><?php esc_html_e('Add a stage', 'vereskala-login'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-crm-inline-form">
                <input type="hidden" name="action" value="vkl_crm_stage_save">
                <input type="hidden" name="pipeline" value="<?php echo esc_attr((string) $pipe['id']); ?>">
                <?php wp_nonce_field('vkl_crm_stage_save'); ?>
                <label><span><?php esc_html_e('Name', 'vereskala-login'); ?></span><input type="text" name="name" required maxlength="100"></label>
                <label><span><?php esc_html_e('Kind', 'vereskala-login'); ?></span><?php echo VKL_Crm_Ui::select('kind', $kinds, 'open'); // phpcs:ignore ?></label>
                <label><span><?php esc_html_e('Probability', 'vereskala-login'); ?></span><input type="number" name="probability" min="0" max="100" value="50"></label>
                <label><span><?php esc_html_e('Colour', 'vereskala-login'); ?></span><input type="color" name="color" value="#2271b1"></label>
                <button class="button button-primary"><?php esc_html_e('Add', 'vereskala-login'); ?></button>
            </form>
        </section>
        <?php
    }

    public function handle_pipeline_save(): void
    {
        check_admin_referer('vkl_crm_pipeline_save');
        $this->require_settings();

        $id   = isset($_POST['pipeline']) ? absint($_POST['pipeline']) : 0;
        $name = isset($_POST['name']) ? wp_unslash((string) $_POST['name']) : '';

        if ($id > 0) {
            $ok = $this->pipelines->rename($id, $name);
        } else {
            $id = $this->pipelines->create_pipeline($name);
            $ok = $id > 0;

            // A new pipeline starts with the three stages it cannot do without.
            if ($ok) {
                $this->pipelines->save_stage($id, ['name' => __('New', 'vereskala-login'), 'probability' => 10, 'kind' => 'open', 'color' => '#2271b1']);
                $this->pipelines->save_stage($id, ['name' => __('Won', 'vereskala-login'), 'probability' => 100, 'kind' => 'won', 'color' => '#00a32a']);
                $this->pipelines->save_stage($id, ['name' => __('Lost', 'vereskala-login'), 'probability' => 0, 'kind' => 'lost', 'color' => '#50575e']);
            }
        }

        VKL_Crm_Ui::redirect($this->url(['pipeline' => $id]), $ok ? 'settings' : 'invalid');
    }

    public function handle_pipeline_default(): void
    {
        $id = isset($_GET['pipeline']) ? absint($_GET['pipeline']) : 0;
        check_admin_referer('vkl_crm_pipeline_default_' . $id);
        $this->require_settings();

        $this->pipelines->set_default($id);
        VKL_Crm_Ui::redirect($this->url(['pipeline' => $id]), 'settings');
    }

    public function handle_pipeline_delete(): void
    {
        $id = isset($_GET['pipeline']) ? absint($_GET['pipeline']) : 0;
        check_admin_referer('vkl_crm_pipeline_delete_' . $id);
        $this->require_settings();

        VKL_Crm_Ui::redirect($this->url(), $this->pipelines->delete_pipeline($id) ? 'settings' : 'pipeline_busy');
    }

    public function handle_stage_save(): void
    {
        check_admin_referer('vkl_crm_stage_save');
        $this->require_settings();

        $pid   = isset($_POST['pipeline']) ? absint($_POST['pipeline']) : 0;
        $sid   = isset($_POST['stage']) ? absint($_POST['stage']) : 0;
        $stage = $sid > 0 ? $this->pipelines->stage($sid) : null;

        if (null === $this->pipelines->find($pid) || ($sid > 0 && (null === $stage || (int) $stage['pipeline_id'] !== $pid))) {
            VKL_Crm_Ui::redirect($this->url(), 'invalid');
        }

        $data = [
            'name'        => isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '',
            'kind'        => isset($_POST['kind']) ? sanitize_key((string) $_POST['kind']) : 'open',
            'probability' => isset($_POST['probability']) ? (int) $_POST['probability'] : 0,
            'color'       => isset($_POST['color']) ? (string) sanitize_hex_color(wp_unslash((string) $_POST['color'])) : '',
        ];

        // Changing a stage's kind would leave the pipeline without one it needs.
        if (null !== $stage && $stage['kind'] !== $data['kind'] && 1 === $this->count_kind($pid, (string) $stage['kind'])) {
            VKL_Crm_Ui::redirect($this->url(['pipeline' => $pid]), 'stage_needed');
        }

        $ok = $this->pipelines->save_stage($pid, $data, $sid) > 0;
        VKL_Crm_Ui::redirect($this->url(['pipeline' => $pid]), $ok ? 'settings' : 'invalid');
    }

    public function handle_stage_shift(): void
    {
        $sid = isset($_GET['stage']) ? absint($_GET['stage']) : 0;
        check_admin_referer('vkl_crm_stage_shift_' . $sid);
        $this->require_settings();

        $stage = $this->pipelines->stage($sid);
        $this->pipelines->shift_stage($sid, isset($_GET['dir']) ? (int) $_GET['dir'] : 1);
        VKL_Crm_Ui::redirect($this->url(['pipeline' => (int) ($stage['pipeline_id'] ?? 0)]), 'settings');
    }

    public function handle_stage_delete(): void
    {
        $sid = isset($_GET['stage']) ? absint($_GET['stage']) : 0;
        check_admin_referer('vkl_crm_stage_delete_' . $sid);
        $this->require_settings();

        $stage = $this->pipelines->stage($sid);
        $pid   = (int) ($stage['pipeline_id'] ?? 0);

        if (null === $stage || 1 === $this->count_kind($pid, (string) $stage['kind'])) {
            VKL_Crm_Ui::redirect($this->url(['pipeline' => $pid]), 'stage_needed');
        }

        VKL_Crm_Ui::redirect($this->url(['pipeline' => $pid]), $this->pipelines->delete_stage($sid) ? 'settings' : 'stage_busy');
    }

    private function count_kind(int $pid, string $kind): int
    {
        return count(array_filter($this->pipelines->stages($pid), static fn(array $s): bool => $s['kind'] === $kind));
    }

    /* --------------------------------------------------------------- tags */

    private function render_tags(): void
    {
        $edit = isset($_GET['tag']) ? $this->tags->find(absint($_GET['tag'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $all  = $this->tags->all();
        ?>
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
                            <a href="<?php echo esc_url($this->url(['tab' => 'tags', 'tag' => (int) $t['id']])); ?>"><?php esc_html_e('Edit', 'vereskala-login'); ?></a>
                            <a class="vkl-crm-danger-link" data-confirm="tag" href="<?php echo esc_url($this->post_link('tag_delete', ['tag' => (int) $t['id']], 'vkl_crm_tag_delete_' . (int) $t['id'])); ?>"><?php esc_html_e('Delete', 'vereskala-login'); ?></a>
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
                    <a class="button" href="<?php echo esc_url($this->url(['tab' => 'tags'])); ?>"><?php esc_html_e('Cancel', 'vereskala-login'); ?></a>
                <?php endif; ?>
            </form>
        </section>
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
        $back  = $this->url(['tab' => 'tags']);

        if ('' === $name) {
            VKL_Crm_Ui::redirect($back, 'invalid');
        }

        if ($id > 0 && null !== $this->tags->find($id)) {
            $slug  = VKL_Crm_Tags::slugify($name);
            $clash = array_filter($this->tags->all($type), static fn(array $t): bool => $t['slug'] === $slug && (int) $t['id'] !== $id);

            if ([] !== $clash) {
                VKL_Crm_Ui::redirect($this->url(['tab' => 'tags', 'tag' => $id]), 'invalid');
            }

            $this->tags->update($id, ['name' => mb_substr($name, 0, 100), 'slug' => $slug, 'type' => $type, 'color' => (string) $color]);
        } else {
            $this->tags->ensure($name, $type, (string) $color);
        }

        VKL_Crm_Ui::redirect($back, 'tag_saved');
    }

    public function handle_tag_delete(): void
    {
        $id = isset($_GET['tag']) ? absint($_GET['tag']) : 0;
        check_admin_referer('vkl_crm_tag_delete_' . $id);
        $this->require_settings();

        $this->tags->delete_tag($id);
        VKL_Crm_Ui::redirect($this->url(['tab' => 'tags']), 'tag_deleted');
    }

    /* ------------------------------------------------------------ general */

    private function render_general(): void
    {
        $checks = [
            'crm_lead_chatbot' => [true, __('Create a lead when a guest leaves their mobile number in the chatbot', 'vereskala-login')],
            'crm_lead_cart'    => [true, __('Create a lead (or note on the existing contact) for each abandoned cart with a mobile number', 'vereskala-login')],
            'crm_deal_cart'    => [false, __('…and open a follow-up deal for it', 'vereskala-login')],
            'crm_deal_credit'  => [false, __('Open a deal for each purchase-credit request', 'vereskala-login')],
        ];
        $state = vkl('crm.leads')->state();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="vkl_crm_settings_save">
            <?php wp_nonce_field('vkl_crm_settings_save'); ?>

            <section class="vkl-crm-card">
                <h2><?php esc_html_e('Automatic leads', 'vereskala-login'); ?></h2>
                <?php foreach ($checks as $key => [$default, $label]) : ?>
                    <p><label class="vkl-crm-check"><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked($this->config->get_bool($key, $default)); ?>> <?php echo esc_html($label); ?></label></p>
                <?php endforeach; ?>
                <p class="description">
                    <?php esc_html_e('Checked every hour (and when staff open the admin). New leads are unassigned so any agent can pick them up. Existing customers are never duplicated: a known mobile number only adds a timeline entry.', 'vereskala-login'); ?>
                    <?php if ((int) $state['last_run'] > 0) : ?>
                        <?php echo esc_html(sprintf(/* translators: %s: time ago */ __('Last checked %s.', 'vereskala-login'), VKL_Crm_Ui::ago(gmdate('Y-m-d H:i:s', (int) $state['last_run'])))); ?>
                    <?php endif; ?>
                </p>
            </section>

            <section class="vkl-crm-card">
                <h2><?php esc_html_e('Reasons for losing a deal', 'vereskala-login'); ?></h2>
                <textarea name="crm_lost_reasons" rows="6" class="large-text"><?php echo esc_textarea(implode("\n", VKL_Crm_Deals_Page::lost_reasons())); ?></textarea>
                <p class="description"><?php esc_html_e('One per line. Offered when a deal is marked as lost; the reports group lost deals by these.', 'vereskala-login'); ?></p>
            </section>

            <section class="vkl-crm-card">
                <h2><?php esc_html_e('Access', 'vereskala-login'); ?></h2>
                <label class="vkl-crm-check">
                    <input type="checkbox" name="crm_agents_see_unassigned" value="1" <?php checked($this->config->get_bool('crm_agents_see_unassigned', true)); ?>>
                    <?php esc_html_e('Sales agents see contacts nobody is assigned to (so they can pick up new leads)', 'vereskala-login'); ?>
                </label>
                <p class="description">
                    <?php esc_html_e('Give staff the "Sales agent" role (own contacts only) or "Sales manager" role (everyone\'s contacts) under Users. Shop managers have manager access; only administrators change these settings.', 'vereskala-login'); ?>
                </p>
            </section>

            <?php submit_button(__('Save settings', 'vereskala-login')); ?>
        </form>
        <?php
    }

    public function handle_settings_save(): void
    {
        check_admin_referer('vkl_crm_settings_save');
        $this->require_settings();

        foreach (['crm_agents_see_unassigned', 'crm_lead_chatbot', 'crm_lead_cart', 'crm_deal_cart', 'crm_deal_credit'] as $key) {
            $this->config->set($key, empty($_POST[$key]) ? '0' : '1');
        }

        $reasons = isset($_POST['crm_lost_reasons']) ? sanitize_textarea_field(wp_unslash((string) $_POST['crm_lost_reasons'])) : '';
        $reasons = implode("\n", array_slice(array_values(array_filter(array_map(static fn(string $r): string => mb_substr(trim($r), 0, 190), explode("\n", $reasons)))), 0, 30));
        $this->config->set('crm_lost_reasons', $reasons);

        VKL_Crm_Ui::redirect($this->url(['tab' => 'general']), 'settings');
    }

    private function require_settings(): void
    {
        if (!$this->access->can(VKL_Crm_Access::CAP_SETTINGS)) {
            VKL_Crm_Ui::redirect($this->url(), 'denied');
        }
    }
}
