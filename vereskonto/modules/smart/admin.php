<?php
/**
 * Smart shop settings — chatbot, product insights, engagement popup.
 *
 * @package Vereskonto\Modules\Smart
 * @since   1.9.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Smart_Admin
 */
final class VKL_Smart_Admin
{
    private const SETTINGS_GROUP = 'vkl_smart_settings';

    /**
     * Separate settings group for canned replies. It lives on its own
     * page (the گفبات inbox) in its own <form>, which never contains the
     * other Smart-shop fields — if it shared SETTINGS_GROUP, WordPress's
     * options.php would blank every option registered to that group but
     * missing from whichever form was actually submitted (and the main
     * settings form, in turn, would blank the canned replies). Each group
     * must be saved only from a form that carries every field registered
     * to it.
     */
    private const CANNED_GROUP = 'vkl_smart_canned_settings';

    /**
     * Config.
     */
    private VKL_Config $config;

    /**
     * Conversation history.
     */
    private VKL_Smart_Chatlog $chatlog;

    /**
     * @param VKL_Config        $config  Config.
     * @param VKL_Smart_Chatlog $chatlog History repository.
     */
    public function __construct(VKL_Config $config, VKL_Smart_Chatlog $chatlog)
    {
        $this->config  = $config;
        $this->chatlog = $chatlog;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 60);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vkl_chat_delete', [$this, 'handle_chat_delete']);
        add_action('admin_post_vkl_chat_clear', [$this, 'handle_chat_clear']);
        add_action('admin_post_vkl_chat_staff_reply', [$this, 'handle_chat_staff_reply']);
        add_action('admin_post_vkl_chat_forget_learned', [$this, 'handle_forget_learned']);
        add_action('admin_post_vkl_ai_test', [$this, 'handle_ai_test']);
        add_action('wp_ajax_vkl_chat_admin_poll', [$this, 'ajax_admin_poll']);
    }

    /**
     * Add the submenus: settings, and the separate live-inbox page
     * ("گفبات") staff actually work from all day — kept apart from the
     * settings screen so a reply form is never sitting inside the same
     * <form> as options.php (it was, once, and nested forms are invalid
     * HTML that browsers handle unpredictably).
     */
    public function menu(): void
    {
        add_submenu_page(
            'vereskala-login',
            __('Smart shop', 'vereskala-login'),
            __('Smart shop', 'vereskala-login'),
            VKL_Security::ADMIN_CAP,
            'vkl-smart',
            [$this, 'render']
        );

        add_submenu_page(
            'vereskala-login',
            'گفبات',
            'گفبات',
            VKL_Security::ADMIN_CAP,
            'vkl-chatbot-inbox',
            [$this, 'render_chatlog']
        );
    }

    /**
     * Register settings.
     */
    public function register_settings(): void
    {
        $bool = static fn(mixed $v): string => '1' === (string) $v ? '1' : '0';
        $text = 'sanitize_text_field';

        $keys = [
            'chatbot_enabled'  => $bool,
            'chatbot_history'  => $bool,
            'chatbot_guests'   => $bool,
            'chatbot_title'    => $text,
            'chatbot_greeting' => $text,
            'chatbot_notify_staff'     => $bool,
            'chatbot_pattern_notify'   => $text,
            'chatbot_pattern_escalate' => $text,
            'chatbot_reply_sms'        => $bool,
            'chatbot_pattern_reply'    => $text,
            'chatbot_hours_start'      => 'absint',
            'chatbot_hours_end'        => 'absint',
            'insights_enabled' => $bool,
            'popup_enabled'    => $bool,
            'popup_minutes'    => 'absint',
            'popup_text'       => $text,
            'popup_coupon'     => $text,
            'popup_link'       => 'esc_url_raw',
            'popup_link_smart' => $bool,
            'popup_repeat_hours' => 'absint',
            'wheel_enabled'      => $bool,
            'wheel_title'        => $text,
            'wheel_minutes'      => 'absint',
            'wheel_repeat_hours' => 'absint',
            'wheel_credit_days'  => 'absint',
            'support_whatsapp'   => $text,
            'support_phone'      => $text,
            'shop_extra_info'    => 'sanitize_textarea_field',
            'shop_delivery_time' => $text,
            'ai_enabled'         => $bool,
            'ai_mode'            => static fn(mixed $v): string => 'always' === (string) $v ? 'always' : 'fallback',
            'ai_model'           => $text,
            'ai_backup_model'    => $text,
            'ai_base_url'        => 'esc_url_raw',
            'ai_system_prompt'   => 'sanitize_textarea_field',
            'ai_hourly_limit'    => 'absint',
            'ai_daily_cap'       => 'absint',
        ];

        foreach ($keys as $key => $sanitize) {
            register_setting(self::SETTINGS_GROUP, $this->config->option_name($key), [
                'type'              => 'string',
                'sanitize_callback' => $sanitize,
            ]);
        }

        // Picking the service also stores the key typed for it (each service
        // keeps its own key, so switching back and forth loses nothing).
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('ai_provider'), [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_ai_provider'],
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('wheel_slices'), [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_wheel_slices'],
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('chatbot_staff'), [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_staff_rows'],
        ]);

        register_setting(self::CANNED_GROUP, $this->config->option_name('chatbot_canned_replies'), [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_canned_rows'],
        ]);
    }

    /**
     * Validate the chosen service and store the key typed for it. The key
     * field is write-only: an empty submit keeps the stored key (the form
     * never prints it back), a tick on "remove" clears it.
     */
    public function sanitize_ai_provider(mixed $value): string
    {
        $provider = (string) $value;

        if (!isset(VKL_Smart_Ai::providers()[$provider])) {
            $provider = 'openai';
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- options.php verified the settings nonce.
        $new   = isset($_POST['vkl_ai_key_new']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['vkl_ai_key_new']))) : '';
        $clear = !empty($_POST['vkl_ai_key_clear']);
        // phpcs:enable

        if ($clear) {
            $this->config->set('ai_api_key_' . $provider, '');
            $this->config->set('ai_api_key', ''); // pre-picker shared key
        } elseif ('' !== $new) {
            $this->config->set('ai_api_key_' . $provider, $new);
        }

        return $provider;
    }

    /**
     * Ready-made staff replies, with placeholders filled from the shop's
     * own settings when picked: {free_min}, {ship_fee}.
     *
     * @return array<int, array{title: string, body: string}>
     */
    private function default_canned(): array
    {
        return [
            ['title' => 'هزینهٔ ارسال', 'body' => '{ship_summary}'],
            ['title' => 'زمان رسیدن سفارش', 'body' => 'سفارش‌ها معمولاً ظرف ۱ تا ۳ روز کاری ارسال می‌شوند و پس از ارسال، کد رهگیری برایتان پیامک می‌شود. همچنین در «حساب من ← سفارش‌ها» قابل پیگیری است.'],
            ['title' => 'خرید اقساطی', 'body' => 'برای خرید اقساطی از «حساب من ← وام و اعتبار» درخواست اعتبار ثبت کنید (فقط تصویر روی و پشت کارت ملی لازم است). پس از تأیید، «خرید اقساطی ورسکالا» هنگام پرداخت نمایش داده می‌شود.'],
            ['title' => 'پرداخت قسط', 'body' => 'اقساط را از «حساب من ← کیف پول» می‌توانید پرداخت کنید؛ قسط هر ماه تا روز سررسید بدون جریمه است. اقساط ماه‌های آینده را هم می‌توانید زودتر بپردازید.'],
            ['title' => 'برگشت از خرید', 'body' => 'درخواست برگشت از خرید را از «حساب من ← سفارش‌ها» برای هر کالا جداگانه ثبت کنید؛ پس از بررسی، نتیجه را پیامک می‌کنیم.'],
            ['title' => 'اصالت کالا', 'body' => 'همهٔ کالاهای ورسکالا اصل و دارای ضمانت اصالت هستند. اگر دربارهٔ کالای مشخصی نگرانی دارید، نام یا لینک آن را بفرستید تا بررسی کنیم.'],
            ['title' => 'کیف پول و اعتبار جشنواره', 'body' => 'موجودی کیف پول و اعتبار جشنواره را در «حساب من ← کیف پول» می‌بینید. اعتبار جشنواره تا تاریخ انقضا قابل استفاده است و هنگام پرداخت خودکار پیش از موجودی کیف پول مصرف می‌شود.'],
            ['title' => 'پایان گفتگو', 'body' => 'خوشحال می‌شویم کمک کرده باشیم. اگر سؤال دیگری داشتید، همین‌جا بپرسید. 🌿'],
        ];
    }

    /**
     * Fill the shop-policy placeholders of a canned reply.
     */
    private function expand_placeholders(string $body): string
    {
        // Same live source as the bot: WooCommerce zones + checkout logic.
        return strtr($body, (new VKL_Smart_Shipping($this->config))->placeholders());
    }

    /**
     * "Test connection" button of the AI section.
     */
    public function handle_ai_test(): void
    {
        vkl('security')->verify_admin_request();

        $ai     = new VKL_Smart_Ai($this->config, $this->chatlog);
        $result = $ai->test();

        set_transient('vkl_ai_test_result', $result, MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('admin.php?page=vkl-smart&vkl_ai_tested=1'));
        exit;
    }

    /**
     * Sanitize the staff name+mobile rows into a compact JSON array. Rows
     * missing either field are dropped — a name with no number cannot be
     * texted, and a number with no name would break a pattern that
     * expects the recipient's own name filled in.
     */
    public function sanitize_staff_rows(mixed $value): string
    {
        $rows = is_string($value) ? json_decode($value, true) : $value;
        $rows = is_array($rows) ? $rows : [];

        $clean = [];

        foreach ($rows as $row) {
            $name   = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
            $mobile = isset($row['mobile']) ? (string) (preg_replace('/[^0-9]/', '', (string) $row['mobile']) ?? '') : '';

            if ('' === $name || '' === $mobile) {
                continue;
            }

            $clean[] = ['name' => $name, 'mobile' => $mobile];
        }

        return (string) wp_json_encode(array_values($clean));
    }

    /**
     * Sanitize the canned-reply rows into a compact JSON array. Rows
     * missing either field are dropped.
     */
    public function sanitize_canned_rows(mixed $value): string
    {
        $rows = is_string($value) ? json_decode($value, true) : $value;
        $rows = is_array($rows) ? $rows : [];

        $clean = [];

        foreach ($rows as $row) {
            $title = isset($row['title']) ? sanitize_text_field((string) $row['title']) : '';
            $body  = isset($row['body']) ? sanitize_textarea_field((string) $row['body']) : '';

            if ('' === $title || '' === $body) {
                continue;
            }

            $clean[] = ['title' => $title, 'body' => $body];
        }

        return (string) wp_json_encode(array_values($clean));
    }

    /**
     * Sanitize the wheel slices coming from the repeatable rows into a
     * compact JSON array. Empty-label rows are dropped.
     */
    public function sanitize_wheel_slices(mixed $value): string
    {
        // Same rules as the wheel itself — deliberately not a second copy.
        $rows = VKL_Smart_Wheel::sanitize_slice_rows(
            is_string($value) ? json_decode($value, true) : $value
        );

        return (string) wp_json_encode($rows);
    }

    /**
     * Checkbox row helper.
     */
    private function toggle(string $key, string $label, string $hint = '', bool $default = false): void
    {
        $name = $this->config->option_name($key);
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label>';
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($this->config->get_bool($key, $default), true, false) . '> ';
        esc_html_e('Enabled', 'vereskala-login');
        echo '</label>';

        if ('' !== $hint) {
            echo '<p class="description">' . esc_html($hint) . '</p>';
        }

        echo '</td></tr>';
    }

    /**
     * Text row helper.
     */
    private function text(string $key, string $label, string $hint = '', string $type = 'text'): void
    {
        $name  = $this->config->option_name($key);
        $value = 'number' === $type ? (string) $this->config->get_int($key, 0) : $this->config->get_string($key);

        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<input type="' . esc_attr($type) . '" class="regular-text" ' . ('number' === $type ? 'min="1" step="1" ' : '') . 'name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';

        if ('' !== $hint) {
            echo '<p class="description">' . esc_html($hint) . '</p>';
        }

        echo '</td></tr>';
    }

    /**
     * Render the page.
     */
    public function render(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Smart shop', 'vereskala-login'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>

                <h2 class="title"><?php esc_html_e('Shop chatbot', 'vereskala-login'); ?></h2>
                <p class="description"><?php esc_html_e('Answers from live shop data on this server: product search, prices, best sellers, matching accessories, order status and wallet balance. No external AI service; no data leaves the site.', 'vereskala-login'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('chatbot_enabled', __('Shop chatbot', 'vereskala-login'));
                    $this->toggle('chatbot_guests', __('Show to guests', 'vereskala-login'), __('When off, only logged-in customers see the chat button.', 'vereskala-login'), true);
                    $this->text('chatbot_title', __('Chat title', 'vereskala-login'));
                    $this->text('chatbot_greeting', __('Greeting message', 'vereskala-login'));
                    $this->toggle('chatbot_history', __('Store conversations', 'vereskala-login'), __('Keeps visitor questions and bot replies for 90 days so you can read what customers really ask (shown below).', 'vereskala-login'), true);
                    ?>
                </table>

                <h3>پیامک و پشتیبانی انسانی</h3>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('chatbot_notify_staff', 'اطلاع‌رسانی پیامکی', 'به ازای هر پیام مشتری در گفتگو، به پشتیبانان زیر پیامک می‌رود.');
                    $this->text('chatbot_pattern_notify', 'کد پترن: پیام جدید در گفتگو', 'پترن پیامک برای اطلاع از هر پیام مشتری. متغیرها: staff_name، name، session، message');
                    $this->text('chatbot_pattern_escalate', 'کد پترن: نیاز به پشتیبان انسانی', 'وقتی مشتری پاسخ ربات را مفید ندانست، این پیامک با اولویت فرستاده می‌شود. متغیرها: staff_name، name، session، message');
                    $this->text('chatbot_hours_start', 'ساعات پشتیبانی — شروع', 'برای پیامی که به مشتری نشان داده می‌شود (مثلاً 8).', 'number');
                    $this->text('chatbot_hours_end', 'ساعات پشتیبانی — پایان', 'مثلاً 22.', 'number');
                    ?>
                </table>

                <h3>پیامک پاسخ به مشتری</h3>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('chatbot_reply_sms', 'پیامک پاسخ کارشناس', 'وقتی کارشناس در صندوق گفبات به مشتری پاسخ می‌دهد، متن پاسخ برای موبایل مشتری هم پیامک می‌شود (مشتری واردشده: شمارهٔ حسابش؛ مهمان: شمارهٔ ثبت‌شده در گفتگو).');
                    $this->text('chatbot_pattern_reply', 'کد پترن: پاسخ کارشناس', 'متغیرها: name (نام مشتری؛ بدون نام «ورسکالایی»)، reply (متن پاسخ، حداکثر ۲۰۰ نویسه و تک‌خطی)، session (شمارهٔ گفتگو). متن پاسخ نباید آدرس سایت داشته باشد؛ درگاه پیامک آن را نمی‌پذیرد.');
                    ?>
                </table>

                <h2 class="title" data-vkl-trailing>هوشواره — اتصال به هوش مصنوعی</h2>
                <p class="description" style="max-width:760px">
                    در حالت «هوشواره در اولویت» (پیشنهادی) هر پاسخ را هوش مصنوعی می‌نویسد: قانون‌های ربات اطلاعات درست و زنده
                    (قیمت، موجودی، هزینهٔ ارسال از ووکامرس، محصولات) را آماده می‌کنند و هوشواره آن را گرم، طبیعی و متناسب با گفتگو به مشتری می‌گوید.
                    پاسخ‌هایی که اطلاعات حساب مشتری دارند (سفارش، کیف پول، امتیاز، اعتبار) و ارجاع به پشتیبان بدون تغییر می‌مانند؛
                    اطلاعات حساب مشتری (نام، موبایل، سفارش، کیف پول) هرگز به سرویس فرستاده نمی‌شود. اگر سرویس در دسترس نباشد، پاسخ ربات خودکار نمایش داده می‌شود.
                </p>
                <?php
                $vkl_ai          = new VKL_Smart_Ai($this->config, $this->chatlog);
                $vkl_providers   = VKL_Smart_Ai::providers();
                $vkl_provider    = $vkl_ai->provider();
                $vkl_key_info    = $vkl_ai->key_info();
                $vkl_live_models = $vkl_ai->cached_models();
                $vkl_ai_mode     = $this->config->get_string('ai_mode');
                $vkl_ai_err      = VKL_Smart_Ai::last_error();
                $vkl_model_name  = $this->config->option_name('ai_model');
                $vkl_backup_name = $this->config->option_name('ai_backup_model');
                ?>
                <style>
                    .vkl-prov{display:flex;flex-wrap:wrap;gap:10px;max-width:900px}
                    .vkl-prov label{flex:1 1 260px;border:1px solid #dcdcde;border-radius:10px;padding:10px 12px;background:#fff;cursor:pointer}
                    .vkl-prov label:has(input:checked){border-color:#157f3a;background:#f1faf4;box-shadow:0 0 0 1px #157f3a}
                    .vkl-prov small{display:block;color:#50575e;margin-top:4px;line-height:1.7}
                    .vkl-sugg{display:none;margin:8px 0 0;max-width:900px}
                    .vkl-sugg.is-on{display:block}
                    .vkl-sugg table{border-collapse:collapse}
                    .vkl-sugg td{padding:4px 8px 4px 0;vertical-align:middle}
                    .vkl-sugg code{direction:ltr;display:inline-block}
                    .vkl-live{margin-top:8px;color:#50575e}
                    .vkl-live button{margin:2px}
                </style>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var radios = document.querySelectorAll('input[name="<?php echo esc_js($this->config->option_name('ai_provider')); ?>"]');
                    var model  = document.getElementById('vkl-ai-model');
                    var backup = document.getElementById('vkl-ai-backup');
                    var base   = document.getElementById('vkl-ai-base-row');

                    radios.forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            var id = radio.value;

                            document.querySelectorAll('.vkl-sugg').forEach(function (box) {
                                box.classList.toggle('is-on', box.getAttribute('data-provider') === id);
                            });

                            if (base) { base.style.display = 'custom' === id ? '' : 'none'; }

                            var list = document.querySelector('div.vkl-sugg[data-provider="' + id + '"]');
                            var ids  = list ? JSON.parse(list.getAttribute('data-models') || '[]') : [];

                            if (model && backup) {
                                model.value  = ids[0] || '';
                                backup.value = ids[1] || '';
                            }
                        });
                    });

                    document.querySelectorAll('.vkl-use').forEach(function (btn) {
                        btn.addEventListener('click', function (e) {
                            var toBackup = 'backup' === btn.getAttribute('data-target') || e.shiftKey;
                            var field    = toBackup ? backup : model;

                            if (field) {
                                field.value = btn.getAttribute('data-model');
                                field.focus();
                            }
                        });
                    });
                });
                </script>
                <table class="form-table" role="presentation">
                    <?php $this->toggle('ai_enabled', 'اتصال هوش مصنوعی', 'بدون کلید API معتبر کاری انجام نمی‌دهد.'); ?>
                    <tr>
                        <th scope="row">کدام هوشواره؟</th>
                        <td>
                            <div class="vkl-prov">
                                <?php foreach ($vkl_providers as $vkl_pid => $vkl_p) : ?>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr($this->config->option_name('ai_provider')); ?>" value="<?php echo esc_attr($vkl_pid); ?>" <?php checked($vkl_provider, $vkl_pid); ?>>
                                        <strong><?php echo esc_html($vkl_p['label']); ?></strong>
                                        <small><?php echo esc_html($vkl_p['note']); ?></small>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل‌های پیشنهادی</th>
                        <td>
                            <?php foreach ($vkl_providers as $vkl_pid => $vkl_p) : ?>
                                <div class="vkl-sugg<?php echo $vkl_pid === $vkl_provider ? ' is-on' : ''; ?>" data-provider="<?php echo esc_attr($vkl_pid); ?>"
                                     data-models="<?php echo esc_attr((string) wp_json_encode(array_column($vkl_p['models'], 0))); ?>">
                                    <?php if ([] === $vkl_p['models']) : ?>
                                        <p class="description">برای سرویس سفارشی، نام مدل را خودتان بنویسید.</p>
                                    <?php else : ?>
                                        <table>
                                            <?php foreach ($vkl_p['models'] as $vkl_i => $vkl_m) : ?>
                                                <tr>
                                                    <td><?php echo $vkl_i + 1; ?>.</td>
                                                    <td><code><?php echo esc_html($vkl_m[0]); ?></code></td>
                                                    <td><?php echo esc_html($vkl_m[1]); ?></td>
                                                    <td>
                                                        <button type="button" class="button button-small vkl-use" data-target="main" data-model="<?php echo esc_attr($vkl_m[0]); ?>">مدل اصلی</button>
                                                        <button type="button" class="button button-small vkl-use" data-target="backup" data-model="<?php echo esc_attr($vkl_m[0]); ?>">پشتیبان</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    <?php endif; ?>
                                    <?php if ($vkl_pid === $vkl_provider && [] !== $vkl_live_models) : ?>
                                        <div class="vkl-live">
                                            در دسترس کلید شما (زنده از خود سرویس؛ برای انتخاب کلیک کنید — کلیک معمولی: مدل اصلی، Shift+کلیک: پشتیبان):<br>
                                            <?php foreach (array_slice($vkl_live_models, 0, 10) as $vkl_lm) : ?>
                                                <button type="button" class="button button-small vkl-use" data-target="main" data-model="<?php echo esc_attr($vkl_lm); ?>" dir="ltr"><?php echo esc_html($vkl_lm); ?></button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <p class="description">با عوض کردن سرویس، مدل اصلی و پشتیبان خودکار از پیشنهادهای همان سرویس پر می‌شوند؛ سپس «ذخیره» بزنید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">کلید API</th>
                        <td>
                            <?php if ('' !== $vkl_key_info['source']) : ?>
                                <p><strong>✅ کلید <?php echo esc_html($vkl_providers[$vkl_provider]['label']); ?> از <?php echo esc_html($vkl_key_info['source']); ?> خوانده می‌شود.</strong></p>
                            <?php else : ?>
                                <p style="color:#996800"><strong>کلیدی برای <?php echo esc_html($vkl_providers[$vkl_provider]['label']); ?> پیدا نشد.</strong></p>
                            <?php endif; ?>
                            <input type="password" class="regular-text" dir="ltr" autocomplete="new-password" name="vkl_ai_key_new" value=""
                                   placeholder="کلید تازهٔ سرویس انتخاب‌شده (خالی = بدون تغییر)">
                            <label style="display:block;margin-top:6px"><input type="checkbox" name="vkl_ai_key_clear" value="1"> حذف کلید ذخیره‌شدهٔ این سرویس</label>
                            <?php foreach ($vkl_providers as $vkl_pid => $vkl_p) : ?>
                                <p class="description vkl-sugg<?php echo $vkl_pid === $vkl_provider ? ' is-on' : ''; ?>" data-provider="<?php echo esc_attr($vkl_pid); ?>">
                                    <?php if ('' !== $vkl_p['signup']) : ?>
                                        گرفتن کلید: <a href="<?php echo esc_url($vkl_p['signup']); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html((string) wp_parse_url($vkl_p['signup'], PHP_URL_HOST)); ?></a>
                                        <?php echo '' !== $vkl_p['key_hint'] ? '(شکل کلید: <code dir="ltr">' . esc_html($vkl_p['key_hint']) . '</code>)' : ''; ?><br>
                                    <?php endif; ?>
                                    امن‌تر: در wp-config.php بگذارید: <code dir="ltr">define('<?php echo esc_html($vkl_p['constant']); ?>', '…');</code>
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">حالت کار</th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr($this->config->option_name('ai_mode')); ?>" value="always" <?php checked('fallback' !== $vkl_ai_mode); ?>> هوشواره در اولویت — هوش مصنوعی همهٔ پاسخ‌ها را می‌نویسد (بهترین تجربهٔ مشتری، پیشنهادی)</label><br>
                            <label><input type="radio" name="<?php echo esc_attr($this->config->option_name('ai_mode')); ?>" value="fallback" <?php checked('fallback' === $vkl_ai_mode); ?>> صرفه‌جویی — فقط وقتی قانون‌ها پاسخی ندارند (کم‌هزینه‌تر)</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل اصلی</th>
                        <td>
                            <input type="text" class="regular-text" dir="ltr" id="vkl-ai-model" name="<?php echo esc_attr($vkl_model_name); ?>" value="<?php echo esc_attr($this->config->get_string('ai_model')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مدل پشتیبان (اختیاری)</th>
                        <td>
                            <input type="text" class="regular-text" dir="ltr" id="vkl-ai-backup" name="<?php echo esc_attr($vkl_backup_name); ?>" value="<?php echo esc_attr($this->config->get_string('ai_backup_model')); ?>">
                            <p class="description">اگر مدل اصلی شلوغ بود (۵۰۳/۴۲۹) یا دیگر وجود نداشت (۴۰۴)، همان لحظه این مدل امتحان می‌شود.</p>
                        </td>
                    </tr>
                    <tr id="vkl-ai-base-row"<?php echo 'custom' === $vkl_provider ? '' : ' style="display:none"'; ?>>
                        <th scope="row">نشانی سرویس</th>
                        <td>
                            <input type="url" class="regular-text" dir="ltr" name="<?php echo esc_attr($this->config->option_name('ai_base_url')); ?>" value="<?php echo esc_attr($this->config->get_string('ai_base_url')); ?>" placeholder="https://…/v1">
                            <p class="description">فقط برای «سفارشی»؛ نشانی پایهٔ سازگار با OpenAI (بدون /chat/completions).</p>
                        </td>
                    </tr>
                    <?php
                    $this->text('ai_hourly_limit', 'سقف پرسش هوشمند هر بازدیدکننده در ساعت', 'برای جلوگیری از سوءاستفاده؛ مثلاً ۲۰.', 'number');
                    $this->text('ai_daily_cap', 'سقف پرسش هوشمند کل سایت در روز', 'هزینهٔ روزانه را محدود می‌کند؛ مثلاً ۳۰۰.', 'number');
                    ?>
                    <tr>
                        <th scope="row">راهنمای رفتار (اختیاری)</th>
                        <td>
                            <textarea class="large-text" rows="4" name="<?php echo esc_attr($this->config->option_name('ai_system_prompt')); ?>" placeholder="مثلاً: لحن دوستانه و محترمانه؛ همیشه در پایان پیشنهاد کمک بیشتر بده."><?php echo esc_textarea($this->config->get_string('ai_system_prompt')); ?></textarea>
                        </td>
                    </tr>
                    <?php $this->text('shop_delivery_time', 'زمان تحویل', 'ووکامرس زمان تحویل را نمی‌داند؛ یک خط بنویسید تا ربات و هوشواره به پرسش «کی می‌رسه؟» درست پاسخ دهند. مثلاً: اصفهان همان روز با پیک؛ شهرستان‌ها ۲ تا ۵ روز کاری با پست.'); ?>
                    <tr>
                        <th scope="row">اطلاعات فروشگاه برای هوشواره</th>
                        <td>
                            <textarea class="large-text" rows="5" name="<?php echo esc_attr($this->config->option_name('shop_extra_info')); ?>" placeholder="مثلاً: ساعت کاری، آدرس فروشگاه، شرایط گارانتی، زمان تحویل در تهران…"><?php echo esc_textarea($this->config->get_string('shop_extra_info')); ?></textarea>
                            <p class="description">هر خط یک واقعیت دربارهٔ فروشگاه. هزینه و شرایط ارسال لازم نیست؛ خودکار از ووکامرس و افزونهٔ تسویه‌حساب خوانده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">وضعیت</th>
                        <td>
                            <?php if ($vkl_ai->enabled()) : ?>
                                <span style="color:#157f3a">● فعال</span>
                            <?php else : ?>
                                <span style="color:#996800">● غیرفعال (کلید یا کلید «اتصال» را بررسی کنید)</span>
                            <?php endif; ?>
                            <?php if ('' !== $vkl_ai_err) : ?>
                                <p style="color:#b32d2e;margin:6px 0 0">آخرین خطا: <?php echo esc_html($vkl_ai_err); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php
                $vkl_staff_raw  = $this->config->get_string('chatbot_staff');
                $vkl_staff_rows = [];

                if ('' !== $vkl_staff_raw) {
                    $vkl_staff_decoded = json_decode($vkl_staff_raw, true);
                    $vkl_staff_rows    = is_array($vkl_staff_decoded) ? $vkl_staff_decoded : [];
                }
                ?>
                <div style="max-width:600px;background:#fff;border:1px solid #dceee2;border-radius:12px;padding:14px 16px;margin-bottom:20px">
                    <strong>پشتیبانان (نام و شماره)</strong>
                    <p class="description">هر پیامک با نام همان شخص برایش فرستاده می‌شود — پترن پیامک اگر متغیر «staff_name» داشته باشد، بدون نام هیچ پیامکی ارسال نمی‌شود.</p>

                    <table class="widefat" id="vkl-staff-rows" style="margin:10px 0">
                        <thead><tr>
                            <th>نام</th>
                            <th>شماره موبایل</th>
                            <th></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="button" id="vkl-staff-add">+ افزودن پشتیبان</button>

                    <input type="hidden" name="<?php echo esc_attr($this->config->option_name('chatbot_staff')); ?>" id="vkl-staff-json" value="<?php echo esc_attr($vkl_staff_raw); ?>">
                </div>

                <script>
                (function () {
                    var seed = <?php echo wp_json_encode(array_values($vkl_staff_rows)); ?>;
                    var tbody = document.querySelector('#vkl-staff-rows tbody');
                    var addBtn = document.getElementById('vkl-staff-add');
                    var json = document.getElementById('vkl-staff-json');
                    var form = json.closest('form');
                    if (!tbody || !addBtn || !json) { return; }

                    function row(d) {
                        d = d || { name: '', mobile: '' };
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td><input type="text" class="regular-text vkl-s-name" value=""></td>' +
                            '<td><input type="text" dir="ltr" class="regular-text vkl-s-mobile" value="" placeholder="09121234567"></td>' +
                            '<td><button type="button" class="button-link vkl-s-del" style="color:#b32d2e">حذف</button></td>';
                        tbody.appendChild(tr);
                        tr.querySelector('.vkl-s-name').value = d.name || '';
                        tr.querySelector('.vkl-s-mobile').value = d.mobile || '';
                        tr.querySelector('.vkl-s-del').addEventListener('click', function () { tr.remove(); });
                    }

                    if (seed.length) { seed.forEach(row); } else { row(); }

                    addBtn.addEventListener('click', function () { row(); });

                    if (form) {
                        form.addEventListener('submit', function () {
                            var rows = [];
                            tbody.querySelectorAll('tr').forEach(function (tr) {
                                var name = tr.querySelector('.vkl-s-name').value.trim();
                                var mobile = tr.querySelector('.vkl-s-mobile').value.trim();
                                if (!name || !mobile) { return; }
                                rows.push({ name: name, mobile: mobile });
                            });
                            json.value = JSON.stringify(rows);
                        });
                    }
                })();
                </script>

                <h2 class="title"><?php esc_html_e('Product insights', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('insights_enabled', __('Product insights', 'vereskala-login'), __('On the single product page: daily/weekly view counts, a summary of customer reviews with star distribution, and a guidance line. External review feeds can be attached via the vkl_insights_extra_lines filter.', 'vereskala-login'));
                    ?>
                </table>

                <h2 class="title"><?php esc_html_e('Engagement popup', 'vereskala-login'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('popup_enabled', __('Engagement popup', 'vereskala-login'), __('A slide-up bar with your offer, shown once per browser session after the visitor has spent the set time on the site. Hidden on the checkout page.', 'vereskala-login'));
                    $this->text('popup_minutes', __('Minutes on site before showing', 'vereskala-login'), '', 'number');
                    $this->text('popup_repeat_hours', __('Show again after (hours)', 'vereskala-login'), __('Once shown, the popup stays hidden for this many hours. Changing the offer text or coupon shows it again immediately.', 'vereskala-login'), 'number');
                    $this->text('popup_text', __('Offer text', 'vereskala-login'));
                    $this->text('popup_coupon', __('Coupon code (optional)', 'vereskala-login'), __('Shown as a click-to-copy code. Create the coupon in WooCommerce first.', 'vereskala-login'));
                    $this->text('popup_link', __('Button link (optional)', 'vereskala-login'), __('Defaults to the shop page.', 'vereskala-login'), 'url');
                    $this->toggle('popup_link_smart', __('Smart button link', 'vereskala-login'), __('Points the button at the product search for whatever this visitor searched most (site search and chatbot). Falls back to the link above.', 'vereskala-login'), true);
                    ?>
                </table>

                <h2 class="title">🆘 <?php esc_html_e('Reaching a human', 'vereskala-login'); ?></h2>
                <p class="description" style="max-width:820px"><?php esc_html_e('When the chatbot is asked something it should not guess at — whether goods are genuine, warranty, damage, complaints — it hands the customer these contacts instead of inventing an answer. The ticket link is always offered; add the others if you want them shown too.', 'vereskala-login'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->text('support_whatsapp', __('Support WhatsApp number', 'vereskala-login'), __('For example 09131234567 — it is turned into a wa.me link automatically. Leave empty to hide.', 'vereskala-login'));
                    $this->text('support_phone', __('Support phone number', 'vereskala-login'), __('Shown as plain text. Leave empty to hide.', 'vereskala-login'));
                    ?>
                </table>

                <h2 class="title">🎡 <?php esc_html_e('Spin the wheel', 'vereskala-login'); ?></h2>
                <p class="description" style="max-width:820px"><?php esc_html_e('A wheel-of-fortune shown to visitors instead of the popup. Define 2–6 slices; the winner is chosen on the server by weight, and wallet-credit prizes are added automatically for logged-in customers.', 'vereskala-login'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->toggle('wheel_enabled', __('Enable the wheel', 'vereskala-login'), __('Turn the spin-the-wheel on. If both the wheel and the popup are on, only enable the one you want visitors to see.', 'vereskala-login'));
                    $this->text('wheel_title', __('Wheel title', 'vereskala-login'), __('Shown at the top of the wheel dialog (e.g. “Spin & win!”).', 'vereskala-login'));
                    $this->text('wheel_minutes', __('Minutes on site before showing', 'vereskala-login'), __('0 shows it immediately.', 'vereskala-login'), 'number');
                    $this->text('wheel_repeat_hours', __('Show again after (hours)', 'vereskala-login'), __('Once a visitor has seen the wheel, it stays hidden for this long. Changing the slices shows it again.', 'vereskala-login'), 'number');
                    $this->text('wheel_credit_days', __('Credit validity (days)', 'vereskala-login'), __('How long a wallet-credit prize stays usable.', 'vereskala-login'), 'number');
                    ?>
                </table>

                <?php
                $vkl_wheel_slices = [];
                $vkl_wheel_raw    = $this->config->get_string('wheel_slices');

                if ('' !== $vkl_wheel_raw) {
                    $vkl_decoded = json_decode($vkl_wheel_raw, true);
                    $vkl_wheel_slices = is_array($vkl_decoded) ? $vkl_decoded : [];
                }
                ?>
                <div style="max-width:820px;background:#fff;border:1px solid #dceee2;border-radius:12px;padding:14px 16px">
                    <strong><?php esc_html_e('Wheel slices (2–6)', 'vereskala-login'); ?></strong>
                    <p class="description"><?php esc_html_e('Label = what the customer sees. Type: “credit” adds wallet credit (set the amount), “coupon” reveals a code, “none” is a blank/try-again. Weight = relative chance (bigger = more likely).', 'vereskala-login'); ?></p>

                    <table class="widefat" id="vkl-wheel-rows" style="margin:10px 0">
                        <thead><tr>
                            <th><?php esc_html_e('Label', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Type', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Amount', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Coupon code', 'vereskala-login'); ?></th>
                            <th><?php esc_html_e('Weight', 'vereskala-login'); ?></th>
                            <th></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="button" id="vkl-wheel-add">+ <?php esc_html_e('Add slice', 'vereskala-login'); ?></button>

                    <input type="hidden" name="<?php echo esc_attr($this->config->option_name('wheel_slices')); ?>" id="vkl-wheel-json" value="<?php echo esc_attr($vkl_wheel_raw); ?>">
                </div>

                <script>
                (function () {
                    var seed = <?php echo wp_json_encode(array_values($vkl_wheel_slices)); ?>;
                    var tbody = document.querySelector('#vkl-wheel-rows tbody');
                    var addBtn = document.getElementById('vkl-wheel-add');
                    var json = document.getElementById('vkl-wheel-json');
                    var form = json.closest('form');
                    if (!tbody || !addBtn || !json) { return; }

                    var L = {
                        credit: <?php echo wp_json_encode(__('Wallet credit', 'vereskala-login')); ?>,
                        coupon: <?php echo wp_json_encode(__('Coupon code', 'vereskala-login')); ?>,
                        none: <?php echo wp_json_encode(__('Try again / blank', 'vereskala-login')); ?>,
                        remove: <?php echo wp_json_encode(__('Remove', 'vereskala-login')); ?>
                    };

                    function row(d) {
                        d = d || { label: '', type: 'none', value: 0, code: '', weight: 1 };
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td><input type="text" class="regular-text vkl-w-label" value=""></td>' +
                            '<td><select class="vkl-w-type">' +
                                '<option value="credit">' + L.credit + '</option>' +
                                '<option value="coupon">' + L.coupon + '</option>' +
                                '<option value="none">' + L.none + '</option>' +
                            '</select></td>' +
                            '<td><input type="number" min="0" step="1" style="width:90px" class="vkl-w-value" value=""></td>' +
                            '<td><input type="text" style="width:120px" class="vkl-w-code" value=""></td>' +
                            '<td><input type="number" min="1" step="1" style="width:70px" class="vkl-w-weight" value=""></td>' +
                            '<td><button type="button" class="button-link vkl-w-del" style="color:#b32d2e">' + L.remove + '</button></td>';
                        tbody.appendChild(tr);
                        tr.querySelector('.vkl-w-label').value = d.label || '';
                        tr.querySelector('.vkl-w-type').value = d.type || 'none';
                        tr.querySelector('.vkl-w-value').value = d.value || 0;
                        tr.querySelector('.vkl-w-code').value = d.code || '';
                        tr.querySelector('.vkl-w-weight').value = d.weight || 1;
                        tr.querySelector('.vkl-w-del').addEventListener('click', function () { tr.remove(); });
                    }

                    if (seed.length) { seed.forEach(row); } else { row(); row(); }

                    addBtn.addEventListener('click', function () {
                        if (tbody.children.length >= 6) { return; }
                        row();
                    });

                    if (form) {
                        form.addEventListener('submit', function () {
                            var rows = [];
                            tbody.querySelectorAll('tr').forEach(function (tr) {
                                var label = tr.querySelector('.vkl-w-label').value.trim();
                                if (!label) { return; }
                                rows.push({
                                    label: label,
                                    type: tr.querySelector('.vkl-w-type').value,
                                    value: parseInt(tr.querySelector('.vkl-w-value').value, 10) || 0,
                                    code: tr.querySelector('.vkl-w-code').value.trim(),
                                    weight: parseInt(tr.querySelector('.vkl-w-weight').value, 10) || 1
                                });
                            });
                            json.value = JSON.stringify(rows);
                        });
                    }
                })();
                </script>

                <?php submit_button(); ?>
            </form>

            <?php
            $vkl_tested = isset($_GET['vkl_ai_tested']) ? get_transient('vkl_ai_test_result') : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <?php if (is_array($vkl_tested)) : ?>
                <div class="notice notice-<?php echo !empty($vkl_tested['ok']) ? 'success' : 'error'; ?>"><p><?php echo esc_html((string) ($vkl_tested['message'] ?? '')); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 20px">
                <input type="hidden" name="action" value="vkl_ai_test">
                <?php vkl('security')->admin_nonce_field(); ?>
                <button type="submit" class="button">🔌 آزمایش اتصال هوش مصنوعی (با کلید ذخیره‌شده)</button>
                <span class="description">ابتدا تنظیمات را ذخیره کنید، سپس آزمایش کنید.</span>
            </form>
        </div>
        <?php
    }

    /**
     * The live inbox ("گفبات"): conversations waiting on a human, the
     * full recent-conversation log, and the canned-reply library staff
     * manage — kept on its own page so a reply <form> is never nested
     * inside the settings page's own <form action="options.php"> (it
     * was, once; nested forms are invalid HTML browsers handle
     * unpredictably).
     */
    public function render_chatlog(): void
    {
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            return;
        }
        ?>
        <div class="wrap" id="vkl-chatlog-wrap" data-vkl-latest-id="<?php echo (int) $this->chatlog->latest_message_id_global(); ?>">
            <h1>گفبات</h1>

            <?php
            $vkl_reply_sms = isset($_GET['vkl_reply_sms']) ? sanitize_key((string) $_GET['vkl_reply_sms']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $vkl_sms_notes = [
                'sent'    => ['success', 'پاسخ شما در گفتگو ثبت و برای مشتری پیامک شد.'],
                'failed'  => ['warning', 'پاسخ در گفتگو ثبت شد ولی پیامک آن ارسال نشد (علت را در گزارش پیامک ببینید).'],
                'nophone' => ['warning', 'پاسخ در گفتگو ثبت شد ولی شمارهٔ موبایل معتبری برای این مشتری ثبت نیست، پس پیامکی نرفت.'],
            ];
            ?>
            <?php if (isset($vkl_sms_notes[$vkl_reply_sms])) : ?>
                <div class="notice notice-<?php echo esc_attr($vkl_sms_notes[$vkl_reply_sms][0]); ?> is-dismissible"><p><?php echo esc_html($vkl_sms_notes[$vkl_reply_sms][1]); ?></p></div>
            <?php elseif (isset($_GET['vkl_chat'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Conversations deleted.', 'vereskala-login'); ?></p></div>
            <?php endif; ?>

            <?php
            $vkl_canned_raw  = $this->config->get_string('chatbot_canned_replies');
            $vkl_canned_rows = [];

            if ('' !== $vkl_canned_raw) {
                $vkl_canned_decoded = json_decode($vkl_canned_raw, true);
                $vkl_canned_rows    = is_array($vkl_canned_decoded) ? $vkl_canned_decoded : [];
            }

            // Nothing saved yet: offer the ready-made set (editable, and
            // stored the first time the list is saved).
            $vkl_canned_is_default = [] === $vkl_canned_rows;

            if ($vkl_canned_is_default) {
                $vkl_canned_rows = $this->default_canned();
            }

            // What the reply forms insert: placeholders already filled.
            $vkl_canned_ready = array_map(
                fn(array $r): array => ['title' => (string) ($r['title'] ?? ''), 'body' => $this->expand_placeholders((string) ($r['body'] ?? ''))],
                array_values($vkl_canned_rows)
            );
            ?>

            <details style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:10px 16px;margin-bottom:16px;max-width:820px">
                <summary style="cursor:pointer;font-weight:600">💬 پاسخ‌های آماده (<?php echo (int) count($vkl_canned_rows); ?>)</summary>
                <p class="description">این‌ها در فرم پاسخ زیر هر گفتگو، در یک منوی کشویی برای درج سریع در دسترس‌اند.
                    در متن می‌توانید <code dir="ltr">{free_min}</code> (حداقل مبلغ ارسال رایگان)، <code dir="ltr">{ship_fee}</code> (کمترین هزینهٔ ارسال) و <code dir="ltr">{ship_summary}</code> (جدول کامل هزینهٔ ارسال) بنویسید؛ هنگام درج، با مقدار تنظیمات جایگزین می‌شوند.
                    <?php if ($vkl_canned_is_default) : ?><strong>اینها نمونه‌های آماده‌اند؛ برای ذخیرهٔ دائمی (و ویرایش)، دکمهٔ «ذخیره» را بزنید.</strong><?php endif; ?></p>
                <form method="post" action="options.php">
                    <?php settings_fields(self::CANNED_GROUP); ?>
                    <table class="widefat" id="vkl-canned-rows" style="margin:10px 0">
                        <thead><tr>
                            <th style="width:220px">عنوان (فقط برای خودتان)</th>
                            <th>متن پاسخ</th>
                            <th></th>
                        </tr></thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="button" id="vkl-canned-add">+ افزودن پاسخ آماده</button>
                    <input type="hidden" name="<?php echo esc_attr($this->config->option_name('chatbot_canned_replies')); ?>" id="vkl-canned-json" value="<?php echo esc_attr($vkl_canned_raw); ?>">
                    <p><?php submit_button('ذخیره پاسخ‌های آماده', 'primary', 'submit', false); ?></p>
                </form>
                <script>
                (function () {
                    var seed = <?php echo wp_json_encode(array_values($vkl_canned_rows)); ?>;
                    var tbody = document.querySelector('#vkl-canned-rows tbody');
                    var addBtn = document.getElementById('vkl-canned-add');
                    var json = document.getElementById('vkl-canned-json');
                    var form = json.closest('form');
                    if (!tbody || !addBtn || !json) { return; }

                    function row(d) {
                        d = d || { title: '', body: '' };
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td><input type="text" class="regular-text vkl-c-title" value=""></td>' +
                            '<td><textarea class="large-text vkl-c-body" rows="2"></textarea></td>' +
                            '<td><button type="button" class="button-link vkl-c-del" style="color:#b32d2e">حذف</button></td>';
                        tbody.appendChild(tr);
                        tr.querySelector('.vkl-c-title').value = d.title || '';
                        tr.querySelector('.vkl-c-body').value = d.body || '';
                        tr.querySelector('.vkl-c-del').addEventListener('click', function () { tr.remove(); });
                    }

                    if (seed.length) { seed.forEach(row); } else { row(); }

                    addBtn.addEventListener('click', function () { row(); });

                    if (form) {
                        form.addEventListener('submit', function () {
                            var rows = [];
                            tbody.querySelectorAll('tr').forEach(function (tr) {
                                var title = tr.querySelector('.vkl-c-title').value.trim();
                                var body = tr.querySelector('.vkl-c-body').value.trim();
                                if (!title || !body) { return; }
                                rows.push({ title: title, body: body });
                            });
                            json.value = JSON.stringify(rows);
                        });
                    }

                    // Shared with the reply forms below.
                    window.vklCannedReplies = <?php echo wp_json_encode($vkl_canned_ready); ?>;
                })();
                </script>
            </details>

            <?php $vkl_needing = $this->chatlog->needing_human(); ?>
            <?php if ([] !== $vkl_needing) : ?>
                <h2 class="title" style="color:#b32d2e">🆘 در انتظار پاسخ پشتیبان (<?php echo (int) count($vkl_needing); ?>)</h2>
                <?php foreach ($vkl_needing as $vkl_wait) : ?>
                    <?php $vkl_wait_session = (string) ($vkl_wait['session'] ?? ''); ?>
                    <details open style="background:#fff8f0;border:2px solid #e8a23a;border-radius:12px;padding:10px 14px;margin-bottom:10px;max-width:960px">
                        <summary style="cursor:pointer;font-weight:600">
                            گفتگوی #<?php echo esc_html((string) ($vkl_wait['conversation_number'] ?? '')); ?> —
                            منتظر پاسخ از <?php echo esc_html((string) ($vkl_wait['updated_at'] ?? '')); ?>
                        </summary>
                        <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
                            <?php foreach ($this->chatlog->messages($vkl_wait_session) as $vkl_entry) : ?>
                                <?php
                                $vkl_role = (string) ($vkl_entry['role'] ?? 'user');
                                $vkl_bg   = 'staff' === $vkl_role ? 'background:#eaf3ff;border:1px solid #cfe0fb' : ('bot' === $vkl_role ? 'background:#f1faf4;border:1px solid #dceee2' : 'background:#157f3a;color:#fff');
                                $vkl_icon = 'staff' === $vkl_role ? '🧑‍💼 پشتیبان' : ('bot' === $vkl_role ? '🤖 ربات' : '👤 مشتری');
                                ?>
                                <div style="display:flex;<?php echo 'user' === $vkl_role ? 'justify-content:flex-end' : ''; ?>">
                                    <div style="max-width:78%;padding:8px 12px;border-radius:12px;line-height:1.9;font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere;<?php echo esc_attr($vkl_bg); ?>">
                                        <div style="font-size:10.5px;opacity:.75;margin-bottom:2px"><?php echo esc_html($vkl_icon); ?></div>
                                        <?php echo nl2br(esc_html((string) ($vkl_entry['message'] ?? ''))); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="vkl-reply-form" style="margin-top:10px;display:flex;gap:8px">
                            <input type="hidden" name="action" value="vkl_chat_staff_reply">
                            <input type="hidden" name="vkl_session" value="<?php echo esc_attr($vkl_wait_session); ?>">
                            <?php vkl('security')->admin_nonce_field(); ?>
                            <select class="vkl-canned-select" style="max-width:160px">
                                <option value="">پاسخ آماده…</option>
                            </select>
                            <input type="text" name="vkl_reply" class="regular-text vkl-reply-input" style="flex:1" placeholder="پاسخ شما برای مشتری…" required>
                            <?php submit_button('ارسال پاسخ', 'primary', 'submit', false); ?>
                        </form>
                    </details>
                <?php endforeach; ?>
                <script>
                (function () {
                    var replies = window.vklCannedReplies || [];
                    document.querySelectorAll('.vkl-reply-form').forEach(function (form) {
                        var select = form.querySelector('.vkl-canned-select');
                        var input = form.querySelector('.vkl-reply-input');
                        if (!select || !input) { return; }
                        replies.forEach(function (r, i) {
                            var opt = document.createElement('option');
                            opt.value = String(i);
                            opt.textContent = r.title;
                            select.appendChild(opt);
                        });
                        select.addEventListener('change', function () {
                            if (select.value === '') { return; }
                            var picked = replies[parseInt(select.value, 10)];
                            if (picked) { input.value = picked.body; }
                            select.value = '';
                            input.focus();
                        });
                    });
                })();
                </script>
            <?php endif; ?>

            <?php $vkl_learned = $this->chatlog->all_learned(50); ?>
            <?php if ([] !== $vkl_learned) : ?>
                <details style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:10px 16px;margin-bottom:16px;max-width:820px">
                    <summary style="cursor:pointer;font-weight:600">🧠 آنچه ربات از پشتیبانی یاد گرفته (<?php echo (int) count($vkl_learned); ?>)</summary>
                    <p class="description">هر بار که یک پشتیبان به سؤالی پاسخ می‌دهد، آن سؤال و پاسخ اینجا ذخیره می‌شود تا اگر مشتری دیگری سؤال مشابهی پرسید، ربات از همین پاسخ استفاده کند. اگر پاسخی اشتباه یا قدیمی شد، می‌توانید حذفش کنید.</p>
                    <table class="widefat striped">
                        <thead><tr>
                            <th>سؤال</th>
                            <th>پاسخ</th>
                            <th style="width:60px">استفاده</th>
                            <th style="width:80px"></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($vkl_learned as $vkl_row) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($vkl_row['question'] ?? '')); ?></td>
                                    <td><?php echo esc_html(wp_trim_words((string) ($vkl_row['answer'] ?? ''), 20, '…')); ?></td>
                                    <td><?php echo (int) ($vkl_row['hits'] ?? 0); ?></td>
                                    <td>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                              onsubmit="return confirm('این پاسخ آموخته‌شده حذف شود؟');">
                                            <input type="hidden" name="action" value="vkl_chat_forget_learned">
                                            <input type="hidden" name="vkl_learned_id" value="<?php echo (int) ($vkl_row['id'] ?? 0); ?>">
                                            <?php vkl('security')->admin_nonce_field(); ?>
                                            <?php submit_button('حذف', 'delete small', 'submit', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <h2 class="title"><?php esc_html_e('Recent conversations', 'vereskala-login'); ?></h2>

            <?php $sessions = $this->chatlog->recent_sessions(15); ?>
            <?php if ([] === $sessions) : ?>
                <p><?php esc_html_e('No conversations recorded yet.', 'vereskala-login'); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 10px"
                      onsubmit="return confirm('<?php echo esc_js(__('Delete ALL recorded conversations?', 'vereskala-login')); ?>');">
                    <input type="hidden" name="action" value="vkl_chat_clear">
                    <?php vkl('security')->admin_nonce_field(); ?>
                    <?php submit_button(__('Delete all conversations', 'vereskala-login'), 'delete small', 'submit', false); ?>
                </form>

                <?php foreach ($sessions as $session) : ?>
                    <details style="background:#fff;border:1px solid #dceee2;border-radius:12px;padding:10px 14px;margin-bottom:8px;max-width:960px">
                        <summary style="cursor:pointer">
                            <?php if ((int) ($session['conversation_number'] ?? 0) > 0) : ?>
                                #<?php echo esc_html((string) $session['conversation_number']); ?> —
                            <?php endif; ?>
                            <?php
                            printf(
                                /* translators: 1: last activity time, 2: message count. */
                                esc_html__('%1$s — %2$s messages', 'vereskala-login'),
                                esc_html((string) ($session['last_at'] ?? '')),
                                esc_html((string) ($session['messages'] ?? ''))
                            );

                            if ((int) ($session['user_id'] ?? 0) > 0) {
                                $user = get_userdata((int) $session['user_id']);
                                echo ' · ' . esc_html($user ? $user->display_name : ('#' . (int) $session['user_id']));
                            } else {
                                echo ' · ' . esc_html__('Guest', 'vereskala-login');
                            }

                            // The visitor's own number, captured at the phone
                            // gate (guests) or their account's billing phone
                            // (logged in) — this is the callback number when
                            // the bot itself could not help. A guest with no
                            // number on file is flagged instead: that
                            // conversation cannot be followed up at all.
                            $vkl_chat_phone = (string) ($session['phone'] ?? '');

                            if ('' !== $vkl_chat_phone) {
                                printf(
                                    ' · <a href="tel:+98%1$s" dir="ltr" style="background:#eaf3ff;color:#1f6feb;border-radius:10px;padding:1px 8px;font-size:11px;text-decoration:none;font-weight:600">📞 %2$s</a>',
                                    esc_attr($vkl_chat_phone),
                                    esc_html('0' . $vkl_chat_phone)
                                );
                            } elseif ((int) ($session['user_id'] ?? 0) <= 0) {
                                echo ' <span style="background:#fdf3f3;color:#8a2b2b;border-radius:10px;padding:1px 8px;font-size:11px">بدون شماره تماس</span>';
                            }

                            foreach ($this->chatlog->session_topics((string) ($session['session'] ?? '')) as $topic) {
                                echo ' <span style="background:#eaf9ef;color:#157f3a;border-radius:10px;padding:1px 8px;font-size:11px">' . esc_html($topic) . '</span>';
                            }
                            ?>
                        </summary>

                        <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
                            <?php foreach ($this->chatlog->messages((string) ($session['session'] ?? '')) as $entry) : ?>
                                <?php
                                $vkl_role = (string) ($entry['role'] ?? 'user');
                                $vkl_bg   = 'staff' === $vkl_role
                                    ? 'background:#eaf3ff;border:1px solid #cfe0fb;border-start-start-radius:4px'
                                    : ('bot' === $vkl_role ? 'background:#f1faf4;border:1px solid #dceee2;border-start-start-radius:4px' : 'background:#157f3a;color:#fff;border-start-end-radius:4px');
                                $vkl_label = 'staff' === $vkl_role
                                    ? '🧑‍💼 پشتیبان'
                                    : ('bot' === $vkl_role ? '🤖 ' . esc_html__('Bot', 'vereskala-login') : '👤 ' . esc_html__('Customer', 'vereskala-login'));
                                ?>
                                <div style="display:flex;<?php echo 'user' === $vkl_role ? 'justify-content:flex-end' : ''; ?>">
                                    <div style="max-width:78%;padding:8px 12px;border-radius:12px;line-height:1.9;font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere;<?php echo esc_attr($vkl_bg); ?>">
                                        <div style="font-size:10.5px;opacity:.75;margin-bottom:2px">
                                            <?php echo $vkl_label; ?>
                                            · <span dir="ltr"><?php echo esc_html((string) ($entry['created_at'] ?? '')); ?></span>
                                        </div>
                                        <?php echo nl2br(esc_html((string) ($entry['message'] ?? ''))); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px"
                              onsubmit="return confirm('<?php echo esc_js(__('Delete this conversation?', 'vereskala-login')); ?>');">
                            <input type="hidden" name="action" value="vkl_chat_delete">
                            <input type="hidden" name="vkl_session" value="<?php echo esc_attr((string) ($session['session'] ?? '')); ?>">
                            <?php vkl('security')->admin_nonce_field(); ?>
                            <?php submit_button(__('Delete conversation', 'vereskala-login'), 'delete small', 'submit', false); ?>
                        </form>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            // Auto-refresh: reload the moment a NEW customer message
            // arrives anywhere, so staff never have to remember to hit
            // refresh. Checked server-side (one cheap MAX(id) query), not
            // on a blind timer — a fixed "reload every N seconds" would
            // interrupt someone mid-reply for no reason when nothing new
            // has actually happened.
            var wrap = document.getElementById('vkl-chatlog-wrap');
            if (!wrap) { return; }
            var knownId = parseInt(wrap.dataset.vklLatestId || '0', 10);
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce = <?php echo wp_json_encode(wp_create_nonce('vkl_chat_admin_poll')); ?>;

            setInterval(function () {
                // Do not yank the page out from under someone who is in
                // the middle of typing a reply.
                var typing = document.querySelector('.vkl-reply-input:focus, .vkl-c-body:focus, .vkl-c-title:focus');
                if (typing) { return; }

                var body = new URLSearchParams();
                body.append('action', 'vkl_chat_admin_poll');
                body.append('nonce', nonce);
                body.append('after', String(knownId));

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success && res.data && res.data.newer) {
                            window.location.reload();
                        }
                    })
                    .catch(function () {});
            }, 8000);
        })();
        </script>
        <?php
    }

    /**
     * Delete one conversation (admin-post).
     */
    public function handle_chat_delete(): void
    {
        vkl('security')->verify_admin_request();

        $this->chatlog->delete_session(
            isset($_POST['vkl_session']) ? sanitize_text_field(wp_unslash($_POST['vkl_session'])) : '' // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );

        wp_safe_redirect(admin_url('admin.php?page=vkl-chatbot-inbox&vkl_chat=1'));
        exit;
    }

    /**
     * Delete all conversations (admin-post).
     */
    public function handle_chat_clear(): void
    {
        vkl('security')->verify_admin_request();

        $this->chatlog->delete_all();

        wp_safe_redirect(admin_url('admin.php?page=vkl-chatbot-inbox&vkl_chat=1'));
        exit;
    }

    /**
     * A staff member answers a customer directly from the live inbox. The
     * reply is stored with the SAME session id the customer's widget is
     * already polling, so it appears there within one poll tick — no
     * separate delivery channel to keep in sync.
     */
    public function handle_chat_staff_reply(): void
    {
        vkl('security')->verify_admin_request();

        $session = isset($_POST['vkl_session']) ? sanitize_text_field(wp_unslash((string) $_POST['vkl_session'])) : '';
        $reply   = isset($_POST['vkl_reply']) ? sanitize_textarea_field(wp_unslash((string) $_POST['vkl_reply'])) : '';

        $sms = '';

        if ('' !== $session && '' !== $reply) {
            $this->chatlog->add_staff_reply($session, get_current_user_id(), $reply);
            $sms = $this->sms_reply_to_customer($session, $reply);
        }

        $args = ['page' => 'vkl-chatbot-inbox', 'vkl_chat' => 1];

        if ('' !== $sms) {
            $args['vkl_reply_sms'] = $sms;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Text the staff answer to the customer too, so a visitor who closed
     * the chat tab still gets it.
     *
     * @return string 'sent', 'failed', or '' when the feature is off or
     *                not applicable (nothing to report to the admin).
     */
    private function sms_reply_to_customer(string $session, string $reply): string
    {
        if (!$this->config->get_bool('chatbot_reply_sms', false) || !vkl()->has('sms.sender')) {
            return '';
        }

        $pattern = $this->config->get_string('chatbot_pattern_reply');

        if ('' === $pattern) {
            return '';
        }

        $contact = $this->chatlog->session_contact($session);
        $mobile  = vkl('validator')->normalize_mobile($contact['phone']);

        if ('' === $contact['phone'] || !vkl('validator')->is_mobile($mobile)) {
            return 'nophone';
        }

        // Pattern variables are single-line and length-limited.
        $text = trim((string) preg_replace('/\s+/u', ' ', $reply));

        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 197) . '...';
        }

        $name = $contact['user_id'] > 0 ? vkl('sms.sender')->resolve_name($contact['user_id']) : 'ورسکالایی';

        $ok = vkl('sms.sender')->send($mobile, 'chatbot.reply', $pattern, [
            'name'    => $name,
            'reply'   => $text,
            'session' => (string) $contact['number'],
        ]);

        return $ok ? 'sent' : 'failed';
    }

    /**
     * Admin correction: an outdated or wrong learned answer.
     */
    public function handle_forget_learned(): void
    {
        vkl('security')->verify_admin_request();

        $this->chatlog->forget_learned(
            isset($_POST['vkl_learned_id']) ? absint($_POST['vkl_learned_id']) : 0
        );

        wp_safe_redirect(admin_url('admin.php?page=vkl-chatbot-inbox'));
        exit;
    }

    /**
     * The live-inbox page's auto-refresh check: has any message arrived
     * anywhere since the id it last knew about? One cheap MAX(id) query —
     * cheaper than reloading the whole page speculatively on a timer.
     */
    public function ajax_admin_poll(): void
    {
        check_ajax_referer('vkl_chat_admin_poll', 'nonce');

        // wp_ajax_ (no _nopriv_ counterpart) reaches any logged-in user,
        // not only admins — the nonce alone only proves the request came
        // from this site's own admin screen, not that the requester is
        // allowed to see it.
        if (!current_user_can(VKL_Security::ADMIN_CAP)) {
            wp_send_json_error();

            return;
        }

        $after = isset($_POST['after']) ? absint($_POST['after']) : 0;

        wp_send_json_success(['newer' => $this->chatlog->latest_message_id_global() > $after]);
    }
}
