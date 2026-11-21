<?php
/**
 * Return requests — admin side. A meta box on the order edit screen
 * (classic and HPOS) shows what the customer asked to send back and
 * lets the shop approve or reject it; a submenu page lists every
 * request across all orders so nothing needs discovering order by
 * order. Approving does not itself refund anything — the shop still
 * uses WooCommerce's own per-item refund tool for the money, now
 * knowing exactly which items and quantities were agreed.
 *
 * @package Vereskonto\Modules\Returns
 * @since   2.9.20
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Returns_Admin
 */
final class VKL_Returns_Admin
{
    private const SETTINGS_GROUP = 'vkl_returns_settings';

    private VKL_Returns_Repository $repository;

    private VKL_Security $security;

    private VKL_Validator $validator;

    private VKL_Config $config;

    public function __construct(VKL_Returns_Repository $repository, VKL_Security $security, VKL_Validator $validator, VKL_Config $config)
    {
        $this->repository = $repository;
        $this->security   = $security;
        $this->validator  = $validator;
        $this->config     = $config;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_order_metabox'], 10, 2);
        add_action('admin_post_vkl_return_approve', [$this, 'handle_approve']);
        add_action('admin_post_vkl_return_reject', [$this, 'handle_reject']);
    }

    public function register_settings(): void
    {
        register_setting(self::SETTINGS_GROUP, $this->config->option_name('returns_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('returns_window_days'), [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 7,
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('returns_sms_enabled'), [
            'type'              => 'string',
            'sanitize_callback' => static fn(mixed $v): string => '1' === (string) $v ? '1' : '0',
            'default'           => '0',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('returns_pattern_admin'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting(self::SETTINGS_GROUP, $this->config->option_name('returns_pattern_decided'), [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function add_menu(): void
    {
        $pending = $this->repository->pending_count();
        $label   = 'درخواست برگشت از خرید';

        if ($pending > 0) {
            $label .= ' <span class="awaiting-mod">' . (int) $pending . '</span>';
        }

        add_submenu_page(
            'vereskala-login',
            'درخواست‌های برگشت از خرید',
            $label,
            VKL_Security::ADMIN_CAP,
            'vkl-returns',
            [$this, 'render_list']
        );
    }

    /**
     * Order-screen meta box (classic post-based and HPOS).
     *
     * @param string $screen Screen id.
     * @param mixed  $object Post or order object.
     */
    public function add_order_metabox(string $screen, mixed $object): void
    {
        if (!in_array($screen, ['shop_order', 'woocommerce_page_wc-orders'], true)) {
            return;
        }

        add_meta_box(
            'vkl_returns_box',
            'درخواست‌های برگشت از خرید این سفارش',
            function (mixed $box_object): void {
                $order = $box_object instanceof WC_Order
                    ? $box_object
                    : wc_get_order(is_object($box_object) ? (int) ($box_object->ID ?? 0) : 0);

                if ($order instanceof WC_Order) {
                    $this->render_order_box($order);
                }
            },
            $screen,
            'normal'
        );
    }

    /**
     * One request card, with Approve/Reject for pending ones.
     */
    private function render_order_box(WC_Order $order): void
    {
        $requests = $this->repository->for_order($order->get_id());

        if ([] === $requests) {
            echo '<p class="description">هنوز درخواست برگشت از خریدی برای این سفارش ثبت نشده است.</p>';

            return;
        }

        $labels = [
            VKL_Returns_Repository::STATUS_PENDING  => 'در انتظار بررسی',
            VKL_Returns_Repository::STATUS_APPROVED => 'تأیید شده',
            VKL_Returns_Repository::STATUS_REJECTED => 'رد شده',
        ];

        foreach ($requests as $request) {
            $status = (string) $request['status'];
            ?>
            <div style="border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;margin-bottom:10px">
                <p style="margin:0 0 6px">
                    <strong><?php echo esc_html($labels[$status] ?? $status); ?></strong>
                    <span class="description" style="margin-inline-start:8px" dir="ltr">
                        <?php echo esc_html((string) $request['created_at']); ?> UTC
                    </span>
                </p>
                <ul style="margin:0 0 6px">
                    <?php foreach ((array) $request['items'] as $ri) : ?>
                        <?php $product = wc_get_product((int) $ri['product_id']); ?>
                        <li>• <?php echo esc_html($product ? $product->get_name() : ('#' . (int) $ri['product_id'])); ?>
                            × <?php echo esc_html((string) (int) $ri['qty']); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ('' !== (string) $request['reason']) : ?>
                    <p style="margin:0 0 6px"><strong>دلیل مشتری:</strong> <?php echo esc_html((string) $request['reason']); ?></p>
                <?php endif; ?>
                <?php if ('' !== (string) $request['admin_note']) : ?>
                    <p style="margin:0 0 6px"><strong>یادداشت فروشگاه:</strong> <?php echo esc_html((string) $request['admin_note']); ?></p>
                <?php endif; ?>

                <?php if (VKL_Returns_Repository::STATUS_PENDING === $status) : ?>
                    <?php
                    // Plain links, not forms: this box sits inside the order
                    // screen's own big <form id="post">, and a nested <form>
                    // is invalid HTML — browsers silently drop it (or fold
                    // its fields into the OUTER form), so the button ends up
                    // just re-saving the order instead of hitting our
                    // handler. Same pitfall already worked around elsewhere
                    // in this plugin (the چت‌بات reply form).
                    $nonce_action = 'vkl_return_decide_' . (int) $request['id'];
                    $approve_url  = wp_nonce_url(
                        admin_url('admin-post.php?action=vkl_return_approve&vkl_return_id=' . (int) $request['id']),
                        $nonce_action
                    );
                    $reject_url = wp_nonce_url(
                        admin_url('admin-post.php?action=vkl_return_reject&vkl_return_id=' . (int) $request['id']),
                        $nonce_action
                    );
                    ?>
                    <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
                        <a href="<?php echo esc_url($approve_url); ?>" class="button button-primary"
                           onclick="return confirm('این درخواست برگشت از خرید تأیید شود؟');">تأیید درخواست</a>
                        <a href="#" class="button vkl-return-reject" data-url="<?php echo esc_url($reject_url); ?>">رد درخواست</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        <script>
        (function () {
            document.querySelectorAll('.vkl-return-reject').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var note = window.prompt('دلیل رد (اختیاری):', '');
                    if (note === null) { return; }
                    window.location.href = a.dataset.url + '&vkl_admin_note=' + encodeURIComponent(note);
                });
            });
        })();
        </script>
        <?php
        echo '<p class="description">تأیید این‌جا فقط درخواست را می‌پذیرد — برای بازگشت واقعی وجه، از دکمهٔ استاندارد «بازپرداخت» ووکامرس در همین صفحه برای همان کالاها استفاده کنید.</p>';
    }

    public function handle_approve(): void
    {
        if (!$this->security->current_user_can_admin()) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $return_id = isset($_GET['vkl_return_id']) ? absint($_GET['vkl_return_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        check_admin_referer('vkl_return_decide_' . $return_id);

        $this->decide($return_id, VKL_Returns_Repository::STATUS_APPROVED, '');
    }

    public function handle_reject(): void
    {
        if (!$this->security->current_user_can_admin()) {
            wp_die(esc_html__('You are not allowed to do that.', 'vereskala-login'));
        }

        $return_id = isset($_GET['vkl_return_id']) ? absint($_GET['vkl_return_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        check_admin_referer('vkl_return_decide_' . $return_id);

        $note = isset($_GET['vkl_admin_note']) ? sanitize_text_field(wp_unslash($_GET['vkl_admin_note'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $this->decide($return_id, VKL_Returns_Repository::STATUS_REJECTED, $note);
    }

    private function decide(int $return_id, string $status, string $note): void
    {
        $request = $this->repository->find($return_id);

        if ($request && $this->repository->set_status($return_id, $status, $note)) {
            $user_id = (int) $request['user_id'];
            $mobile  = $this->validator->normalize_mobile((string) get_user_meta($user_id, 'billing_phone', true));

            vkl_dispatch('return.' . $status, [
                'return_id' => $return_id,
                'order_id'  => (int) $request['order_id'],
                'user_id'   => $user_id,
                'mobile'    => $this->validator->is_mobile($mobile) ? $mobile : '',
                'note'      => $note,
            ]);
        }

        $order    = $request ? wc_get_order((int) $request['order_id']) : false;
        $redirect = $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url('admin.php?page=vkl-returns');

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Overview list across all orders + settings.
     */
    public function render_list(): void
    {
        if (!$this->security->current_user_can_admin()) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $status = isset($_GET['vkl_status']) ? sanitize_key($_GET['vkl_status']) : '';
        // phpcs:enable

        if (!in_array($status, ['', VKL_Returns_Repository::STATUS_PENDING, VKL_Returns_Repository::STATUS_APPROVED, VKL_Returns_Repository::STATUS_REJECTED], true)) {
            $status = '';
        }

        $labels = [
            VKL_Returns_Repository::STATUS_PENDING  => 'در انتظار بررسی',
            VKL_Returns_Repository::STATUS_APPROVED => 'تأیید شده',
            VKL_Returns_Repository::STATUS_REJECTED => 'رد شده',
        ];
        ?>
        <div class="wrap">
            <h1>درخواست‌های برگشت از خرید</h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=vkl-returns')); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>">همه</a> |</li>
                <?php $tab_i = 0; ?>
                <?php foreach ($labels as $tab_status => $tab_label) : $tab_i++; ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'vkl-returns', 'vkl_status' => $tab_status], admin_url('admin.php'))); ?>"
                           class="<?php echo $status === $tab_status ? 'current' : ''; ?>"><?php echo esc_html($tab_label); ?></a><?php echo $tab_i < count($labels) ? ' |' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <table class="widefat striped" style="max-width:1100px;margin-top:10px">
                <thead><tr>
                    <th style="width:70px">سفارش</th>
                    <th>مشتری</th>
                    <th>کالاها</th>
                    <th style="width:120px">وضعیت</th>
                    <th style="width:150px">تاریخ (UTC)</th>
                </tr></thead>
                <tbody>
                <?php $rows = $this->repository->latest(50, $status); ?>
                <?php if ([] === $rows) : ?>
                    <tr><td colspan="5"><p class="description" style="margin:8px 0">درخواستی یافت نشد.</p></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <?php $order = wc_get_order((int) $row['order_id']); ?>
                    <tr>
                        <td>
                            <?php if ($order instanceof WC_Order) : ?>
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                            <?php else : ?>
                                #<?php echo (int) $row['order_id']; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $user = get_userdata((int) $row['user_id']); ?>
                            <?php echo esc_html($user ? $user->display_name : ('#' . (int) $row['user_id'])); ?>
                        </td>
                        <td>
                            <?php foreach ((array) $row['items'] as $ri) : ?>
                                <?php $product = wc_get_product((int) $ri['product_id']); ?>
                                <div>• <?php echo esc_html($product ? $product->get_name() : ('#' . (int) $ri['product_id'])); ?>
                                    × <?php echo esc_html((string) (int) $ri['qty']); ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html($labels[(string) $row['status']] ?? (string) $row['status']); ?></td>
                        <td dir="ltr" style="font-size:12px"><?php echo esc_html((string) $row['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2 class="title" style="margin-top:24px">تنظیمات</h2>
            <form method="post" action="options.php" style="background:#fff;border:1px solid #e2e2e2;border-radius:10px;padding:14px 18px;max-width:640px">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">فعال‌سازی درخواست برگشت از خرید</th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('returns_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('returns_enabled')); ?>" value="1"
                                    <?php checked($this->config->get_bool('returns_enabled', false)); ?>>
                                مشتری‌ها بتوانند از پیشخوان، برگشت هر کالا را جداگانه درخواست دهند.
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">مهلت درخواست برگشت از خرید (روز)</th>
                        <td>
                            <input type="number" min="0" step="1" style="width:100px"
                                   name="<?php echo esc_attr($this->config->option_name('returns_window_days')); ?>"
                                   value="<?php echo (int) $this->config->get_int('returns_window_days', 7); ?>">
                            <p class="description">از تاریخ تکمیل سفارش. عدد ۰ یعنی بدون محدودیت زمانی.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">📨 اطلاع‌رسانی پیامکی</th>
                        <td>
                            <label style="display:block;margin-bottom:8px">
                                <input type="hidden" name="<?php echo esc_attr($this->config->option_name('returns_sms_enabled')); ?>" value="0">
                                <input type="checkbox" name="<?php echo esc_attr($this->config->option_name('returns_sms_enabled')); ?>" value="1"
                                    <?php checked($this->config->get_bool('returns_sms_enabled', false)); ?>>
                                فعال باشد
                            </label>
                            <p><label>پترن اطلاع به ادمین (درخواست جدید) — متغیر: order</label><br>
                                <input type="text" dir="ltr" style="width:200px" placeholder="wmw7nfeze5unxh9"
                                       name="<?php echo esc_attr($this->config->option_name('returns_pattern_admin')); ?>"
                                       value="<?php echo esc_attr($this->config->get_string('returns_pattern_admin')); ?>"></p>
                            <p><label>پترن اطلاع به مشتری (نتیجهٔ بررسی) — متغیرها: name، order، status</label><br>
                                <input type="text" dir="ltr" style="width:200px" placeholder="wmw7nfeze5unxh9"
                                       name="<?php echo esc_attr($this->config->option_name('returns_pattern_decided')); ?>"
                                       value="<?php echo esc_attr($this->config->get_string('returns_pattern_decided')); ?>"></p>
                            <p class="description">شماره تماس ادمین از تنظیمات مشترک «admin_mobile» خوانده می‌شود.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
