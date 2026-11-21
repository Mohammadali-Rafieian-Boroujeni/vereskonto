<?php
/**
 * Settings search: a box in the green header of every Vereskonto screen
 * that finds a screen, a settings section or a message by name and jumps
 * there — "where was the OTP length again?" no longer means opening
 * screens one by one.
 *
 * The index is built on the server from the shared menu, the settings
 * navigation, the message table and a curated list of frequently looked
 * up settings; filtering runs in the browser.
 *
 * @package Vereskonto\Core
 * @since   2.12.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Admin_Search
 */
final class VKL_Admin_Search
{
    public function register(): void
    {
        add_action('admin_footer', [$this, 'render']);
    }

    /**
     * Searchable entries.
     *
     * @return array<int, array{t: string, g: string, u: string, k?: string}>
     */
    public static function index(): array
    {
        $u   = static fn(string $q): string => admin_url('admin.php?page=' . $q);
        $out = [];

        foreach (VKL_Admin_Menu::groups() as $group) {
            foreach ($group['items'] as $slug => $label) {
                $out[] = ['t' => $label, 'g' => $group['label'], 'u' => $u($slug)];
            }
        }

        $settings = __('Settings', 'vereskala-login');
        $curated  = [
            [__('Sign-in page', 'vereskala-login'), $settings, 'vereskala-login&tab=login', 'OTP logo login code ورود کد یک‌بارمصرف لوگو'],
            [__('National ID matching', 'vereskala-login'), $settings, 'vereskala-login&tab=identity', 'shahkar کد ملی شاهکار'],
            [__('SMS settings', 'vereskala-login'), $settings, 'vereskala-login&tab=sms', 'melipayamak gateway sender proxy ملی‌پیامک درگاه'],
            [__('Notifications & Bale', 'vereskala-login'), $settings, 'vereskala-login&tab=bale', 'bale push pwa app بله اعلان مرورگر اپ'],
            [__('Mobile API', 'vereskala-login'), $settings, 'vereskala-login&tab=api', 'app token api اپ موبایل'],
            [__('Admin mobile', 'vereskala-login'), __('SMS Notifications', 'vereskala-login'), 'vkl-notifications', 'admin mobile موبایل مدیر'],
            [__('WhatsApp gateway (Meta Cloud API)', 'vereskala-login'), __('SMS Notifications', 'vereskala-login'), 'vkl-notifications', 'whatsapp واتس‌اپ'],
            [__('Birthday greetings', 'vereskala-login'), __('SMS Notifications', 'vereskala-login'), 'vkl-notifications', 'birthday coupon تولد'],
            [__('Review requests', 'vereskala-login'), __('SMS Notifications', 'vereskala-login'), 'vkl-notifications', 'review نظر'],
            [__('Wallet', 'vereskala-login') . ' — ' . $settings, __('Wallet & credit', 'vereskala-login'), 'vkl-wallet&view=settings', 'top-up checkout شارژ'],
            [__('Installments & credit', 'vereskala-login') . ' — ' . $settings, __('Wallet & credit', 'vereskala-login'), 'vkl-credit-customers&view=settings', 'late fee penalty reminder جریمه یادآوری'],
            [__('Festival credit', 'vereskala-login'), __('Wallet & credit', 'vereskala-login'), 'vkl-wallet&view=festival', 'promo جشنواره'],
            [__('Customer club', 'vereskala-login') . ' — ' . $settings, __('Marketing', 'vereskala-login'), 'vkl-loyalty&view=settings', 'points tier referral امتیاز سطح معرفی دوست'],
            [__('Surveys', 'vereskala-login'), __('Marketing', 'vereskala-login'), 'vkl-loyalty&view=surveys', 'survey نظرسنجی'],
            [__('Smart shop', 'vereskala-login'), $settings, 'vkl-smart', 'chatbot ai wheel popup گفبات هوشواره گردونه پاپ‌آپ هوش مصنوعی'],
            [__('System status', 'vereskala-login'), $settings, 'vkl-status', 'cron environment وضعیت'],
        ];

        foreach ($curated as [$title, $group, $page, $keywords]) {
            $out[] = ['t' => $title, 'g' => $group, 'u' => $u($page), 'k' => $keywords];
        }

        if (class_exists('VKL_Messages_Admin')) {
            $messages = __('All messages', 'vereskala-login');

            foreach (VKL_Messages_Admin::definitions() as $group) {
                $rows  = implode(' ', array_column($group['rows'], 'label'));
                $out[] = ['t' => $group['label'], 'g' => $messages, 'u' => $u(VKL_Messages_Admin::PAGE), 'k' => $rows . ' sms pattern پیامک پترن'];
            }
        }

        return $out;
    }

    public function render(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ('' === $page || (VKL_Admin_Menu::PARENT !== $page && 0 !== strpos($page, 'vkl-'))) {
            return;
        }
        ?>
        <style>
            .vkl-find{position:relative;margin-inline-start:auto}
            .vkl-find input{width:240px;max-width:52vw;border-radius:999px;border:0;padding:5px 14px;font-size:13px;background:rgba(255,255,255,.95)}
            .vkl-find ul{position:absolute;inset-inline-end:0;top:calc(100% + 6px);z-index:1000;min-width:300px;max-height:60vh;overflow:auto;margin:0;padding:6px;list-style:none;background:#fff;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.2)}
            .vkl-find ul[hidden]{display:none}
            .vkl-find li a{display:block;padding:7px 10px;border-radius:7px;color:#1d2327;text-decoration:none}
            .vkl-find li a small{display:block;color:#646970;font-size:11.5px}
            .vkl-find li a:hover,.vkl-find li a.is-on{background:#f1faf4;color:#157f3a}
            .vkl-find .none{padding:8px 10px;color:#646970}
        </style>
        <script>
        (function () {
            var brand = document.querySelector('.vkl-header-brand');
            if (!brand) { return; }
            var data = <?php echo wp_json_encode(self::index(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            var norm = function (s) {
                return String(s || '').toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[‌ً-ٟ]/g, '').replace(/\s+/g, ' ');
            };
            data.forEach(function (d) { d.n = norm(d.t + ' ' + d.g + ' ' + (d.k || '')); });

            var box = document.createElement('div');
            box.className = 'vkl-find';
            box.setAttribute('role', 'search');
            box.innerHTML = '<input type="search" id="vkl-find-q" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="vkl-find-list" aria-autocomplete="list">'
                + '<ul id="vkl-find-list" role="listbox" hidden></ul>';
            var input = box.firstChild, list = box.lastChild;
            input.placeholder = <?php echo wp_json_encode(__('Find a setting…', 'vereskala-login')); ?>;
            input.setAttribute('aria-label', <?php echo wp_json_encode(__('Find a setting', 'vereskala-login')); ?>);
            brand.style.flexWrap = 'wrap';
            brand.appendChild(box);
            var active = -1;

            function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; }

            function draw() {
                var q = norm(input.value).trim();
                list.innerHTML = '';
                if (!q) { close(); return; }
                var words = q.split(' ');
                var hits = data.filter(function (d) {
                    return words.every(function (w) { return d.n.indexOf(w) !== -1; });
                }).slice(0, 12);
                if (!hits.length) {
                    var li = document.createElement('li');
                    li.className = 'none';
                    li.textContent = <?php echo wp_json_encode(__('Nothing found', 'vereskala-login')); ?>;
                    list.appendChild(li);
                }
                hits.forEach(function (d, i) {
                    var li = document.createElement('li');
                    var a = document.createElement('a');
                    a.href = d.u;
                    a.id = 'vkl-find-' + i;
                    a.setAttribute('role', 'option');
                    a.textContent = d.t;
                    var small = document.createElement('small');
                    small.textContent = d.g;
                    a.appendChild(small);
                    li.appendChild(a);
                    list.appendChild(li);
                });
                list.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                active = -1;
            }

            function move(step) {
                var links = list.querySelectorAll('a');
                if (!links.length) { return; }
                if (active >= 0) { links[active].classList.remove('is-on'); }
                active = (active + step + links.length) % links.length;
                links[active].classList.add('is-on');
                input.setAttribute('aria-activedescendant', links[active].id);
                links[active].scrollIntoView({block: 'nearest'});
            }

            input.addEventListener('input', draw);
            input.addEventListener('keydown', function (e) {
                if ('ArrowDown' === e.key) { e.preventDefault(); move(1); }
                else if ('ArrowUp' === e.key) { e.preventDefault(); move(-1); }
                else if ('Enter' === e.key) {
                    var links = list.querySelectorAll('a');
                    var target = links[active >= 0 ? active : 0];
                    if (target) { e.preventDefault(); location.href = target.href; }
                } else if ('Escape' === e.key) { close(); }
            });
            document.addEventListener('click', function (e) { if (!box.contains(e.target)) { close(); } });
            document.addEventListener('keydown', function (e) {
                if ('/' === e.key && !/input|textarea|select/i.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) {
                    e.preventDefault();
                    input.focus();
                }
            });
        })();
        </script>
        <?php
    }
}
