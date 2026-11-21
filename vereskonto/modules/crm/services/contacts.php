<?php
/**
 * Contact service: validation, de-duplication, linking contacts to
 * WordPress users and WooCommerce orders, and purchase statistics.
 *
 * Screens, the REST API and the backfill all create and change contacts
 * through this class — never through the repository directly — so the
 * same rules (unique mobile, valid email, allowed lifecycle) apply
 * everywhere.
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Contact_Service
 */
final class VKL_Crm_Contact_Service
{
    /** Order meta linking a guest order to its CRM contact. */
    public const ORDER_META = '_vkl_crm_contact';

    private VKL_Crm_Contacts $contacts;

    public function __construct(VKL_Crm_Contacts $contacts)
    {
        $this->contacts = $contacts;
    }

    /**
     * Validate and normalise contact input. Only keys present in $input
     * are returned, so it serves both create and partial update.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function sanitize(array $input)
    {
        $out = [];

        foreach (['first_name' => 100, 'last_name' => 100, 'company' => 190, 'city' => 100] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $out[$key] = mb_substr(trim(sanitize_text_field((string) $input[$key])), 0, $max);
            }
        }

        if (array_key_exists('mobile', $input)) {
            $raw = trim(vkl_latin_digits((string) $input['mobile']));

            if ('' === $raw) {
                $out['mobile'] = null;
            } else {
                $mobile = vkl_normalize_mobile($raw);

                if (!vkl_is_mobile($mobile)) {
                    return new WP_Error('vkl_crm_mobile', __('The mobile number is not valid.', 'vereskala-login'), ['status' => 422, 'field' => 'mobile']);
                }

                $out['mobile'] = $mobile;
            }
        }

        if (array_key_exists('email', $input)) {
            $email = sanitize_email((string) $input['email']);

            if ('' !== trim((string) $input['email']) && !is_email($email)) {
                return new WP_Error('vkl_crm_email', __('The email address is not valid.', 'vereskala-login'), ['status' => 422, 'field' => 'email']);
            }

            $out['email'] = strtolower($email);
        }

        if (array_key_exists('lifecycle', $input)) {
            if (!in_array($input['lifecycle'], VKL_Crm_Contacts::LIFECYCLES, true)) {
                return new WP_Error('vkl_crm_lifecycle', __('Unknown lifecycle stage.', 'vereskala-login'), ['status' => 422, 'field' => 'lifecycle']);
            }

            $out['lifecycle'] = $input['lifecycle'];
        }

        if (array_key_exists('source', $input)) {
            $out['source'] = substr(sanitize_key((string) $input['source']), 0, 40);
        }

        if (array_key_exists('owner_id', $input)) {
            $out['owner_id'] = max(0, (int) $input['owner_id']);
        }

        if (array_key_exists('score', $input)) {
            $out['score'] = max(-1000, min(1000, (int) $input['score']));
        }

        if (array_key_exists('birthday', $input)) {
            $out['birthday'] = $this->date_or_null((string) $input['birthday']);

            if (false === $out['birthday']) {
                return new WP_Error('vkl_crm_birthday', __('The birthday is not a valid date.', 'vereskala-login'), ['status' => 422, 'field' => 'birthday']);
            }
        }

        return $out;
    }

    /**
     * Create a contact. A soft-deleted contact with the same mobile is
     * restored (and updated) instead — the mobile is unique.
     *
     * @param array<string, mixed> $input
     * @return int|WP_Error New or restored contact id.
     */
    public function create(array $input)
    {
        $data = $this->sanitize($input + ['lifecycle' => 'lead']);

        if (is_wp_error($data)) {
            return $data;
        }

        if ('' === trim(($data['first_name'] ?? '') . ($data['last_name'] ?? '') . ($data['company'] ?? '')) && empty($data['mobile']) && empty($data['email'])) {
            return new WP_Error('vkl_crm_empty', __('Enter at least a name, mobile or email.', 'vereskala-login'), ['status' => 422]);
        }

        if (!empty($data['mobile'])) {
            $existing = $this->contacts->find_by_mobile($data['mobile']);

            if (null !== $existing) {
                if (null === $existing['deleted_at']) {
                    return new WP_Error('vkl_crm_duplicate', __('A contact with this mobile number already exists.', 'vereskala-login'), ['status' => 409, 'contact_id' => (int) $existing['id']]);
                }

                $this->contacts->restore((int) $existing['id']);
                $this->contacts->update((int) $existing['id'], $data);

                return (int) $existing['id'];
            }
        }

        $id = $this->contacts->create($data);

        return $id > 0 ? $id : new WP_Error('vkl_crm_db', __('The contact could not be saved.', 'vereskala-login'), ['status' => 500]);
    }

    /**
     * @param array<string, mixed> $input
     * @return true|WP_Error
     */
    public function update(int $id, array $input)
    {
        $data = $this->sanitize($input);

        if (is_wp_error($data)) {
            return $data;
        }

        if (!empty($data['mobile'])) {
            $other = $this->contacts->find_by_mobile($data['mobile']);

            if (null !== $other && (int) $other['id'] !== $id) {
                return new WP_Error('vkl_crm_duplicate', __('A contact with this mobile number already exists.', 'vereskala-login'), ['status' => 409, 'contact_id' => (int) $other['id']]);
            }
        }

        return $this->contacts->update($id, $data)
            ? true
            : new WP_Error('vkl_crm_db', __('The contact could not be saved.', 'vereskala-login'), ['status' => 500]);
    }

    /**
     * The contact for a WordPress user, created (or linked) on first use.
     *
     * Order of preference: the contact already linked to the user; an
     * unlinked contact with the user's mobile (a lead who later signed
     * up — linked, not duplicated); otherwise a new contact. If the
     * mobile belongs to another user's contact, the new contact is saved
     * without it and the clash is noted in its meta.
     */
    public function for_user(int $user_id): int
    {
        $user = get_userdata($user_id);

        if (!$user instanceof WP_User) {
            return 0;
        }

        $linked = $this->contacts->find_by_user($user_id);

        if (null !== $linked) {
            if (null !== $linked['deleted_at']) {
                $this->contacts->restore((int) $linked['id']);
            }

            return (int) $linked['id'];
        }

        $profile = $this->profile_of($user);
        $mobile  = $profile['mobile'];
        unset($profile['mobile']);

        if (null !== $mobile) {
            $by_mobile = $this->contacts->find_by_mobile($mobile);

            if (null !== $by_mobile && empty($by_mobile['user_id'])) {
                $id = (int) $by_mobile['id'];

                if (null !== $by_mobile['deleted_at']) {
                    $this->contacts->restore($id);
                }

                $this->contacts->update($id, ['user_id' => $user_id] + $this->only_empty($by_mobile, $profile));

                return $id;
            }

            if (null === $by_mobile) {
                $profile['mobile'] = $mobile;
            }
        }

        $id = $this->contacts->create($profile + [
            'user_id'   => $user_id,
            'lifecycle' => 'lead',
            'source'    => 'signup',
        ]);

        if ($id > 0 && null !== $mobile && !isset($profile['mobile'])) {
            $this->contacts->set_meta($id, 'duplicate_mobile', $mobile);
        }

        return $id;
    }

    /**
     * The contact for a mobile number (guest checkout, chatbot, …),
     * created as a lead when unknown.
     *
     * @param array<string, mixed> $defaults Fields for a new contact.
     */
    public function for_mobile(string $mobile, array $defaults = []): int
    {
        $mobile = vkl_normalize_mobile(vkl_latin_digits($mobile));

        if (!vkl_is_mobile($mobile)) {
            return 0;
        }

        $existing = $this->contacts->find_by_mobile($mobile);

        if (null !== $existing) {
            if (null !== $existing['deleted_at']) {
                $this->contacts->restore((int) $existing['id']);
            }

            return (int) $existing['id'];
        }

        $id = $this->create(['mobile' => $mobile] + $defaults);

        return is_wp_error($id) ? 0 : (int) $id;
    }

    /**
     * The contact an order belongs to: its customer account, else the
     * billing mobile of a guest order. 0 when neither is usable.
     */
    public function for_order(WC_Order $order): int
    {
        $user_id = (int) $order->get_customer_id();

        if ($user_id > 0) {
            return $this->for_user($user_id);
        }

        $contact_id = $this->for_mobile((string) $order->get_billing_phone(), [
            'first_name' => (string) $order->get_billing_first_name(),
            'last_name'  => (string) $order->get_billing_last_name(),
            'email'      => (string) $order->get_billing_email(),
            'city'       => (string) $order->get_billing_city(),
            'source'     => 'guest_order',
        ]);

        // WooCommerce keeps the billing phone exactly as typed ("0919 111
        // 2222", "+98…", Persian digits), so guest orders cannot be found
        // again by the normalised number. Stamp the contact on the order
        // instead. save_meta_data() writes only the meta — no status
        // hooks — so it is safe inside a status-change handler.
        if ($contact_id > 0 && (int) $order->get_meta(self::ORDER_META) !== $contact_id) {
            $order->update_meta_data(self::ORDER_META, (string) $contact_id);
            $order->save_meta_data();
        }

        return $contact_id;
    }

    /**
     * Recount a contact's paid orders, net spend and last order date.
     * Wallet top-ups and instalment repayments are not sales and are
     * skipped (vkl_is_carrier_order). A lead or prospect with a paid
     * order becomes a customer; lifecycle is never downgraded here.
     */
    public function refresh_stats(int $contact_id): void
    {
        $contact = $this->contacts->find($contact_id, true);

        if (null === $contact || !function_exists('wc_get_orders')) {
            return;
        }

        $base = ['status' => self::paid_statuses(), 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order'];

        // Guest orders, found through the contact stamp (for_order) —
        // also for a former guest who has since created an account.
        $ids = (array) wc_get_orders($base + [
            'customer_id' => 0,
            'meta_query'  => [['key' => self::ORDER_META, 'value' => (string) $contact_id]], // phpcs:ignore WordPress.DB.SlowDBQuery
        ]);

        if (!empty($contact['user_id'])) {
            $ids = array_merge($ids, (array) wc_get_orders($base + ['customer_id' => (int) $contact['user_id']]));
        }

        $count = 0;
        $spent = 0.0;
        $last  = 0;

        foreach (array_unique(array_map('intval', $ids)) as $order_id) {
            if (vkl_is_carrier_order((int) $order_id)) {
                continue;
            }

            $order = wc_get_order((int) $order_id);

            if (!$order instanceof WC_Order) {
                continue;
            }

            // A stamp is trusted only while the order's phone still
            // matches this contact: after the CRM is removed and
            // reinstalled, ids restart and an old stamp could point at a
            // different person.
            if (0 === (int) $order->get_customer_id()
                && vkl_normalize_mobile(vkl_latin_digits((string) $order->get_billing_phone())) !== (string) $contact['mobile']
            ) {
                continue;
            }

            ++$count;
            $spent += (float) $order->get_total() - (float) $order->get_total_refunded();
            $created = $order->get_date_created();
            $last    = max($last, $created ? $created->getTimestamp() : 0);
        }

        $data = [
            'orders_count'  => $count,
            'total_spent'   => round($spent, 4),
            'last_order_at' => $last > 0 ? gmdate('Y-m-d H:i:s', $last) : null,
        ];

        if ($count > 0 && in_array($contact['lifecycle'], ['lead', 'prospect'], true)) {
            $data['lifecycle'] = 'customer';
        }

        $this->contacts->update($contact_id, $data);
    }

    /* ------------------------------------------------------------------ *
     *  Keeping contacts in step with WordPress and WooCommerce
     * ------------------------------------------------------------------ */

    /** @var int[] Users registered during this request, handled at shutdown. */
    private array $pending_users = [];

    public function register_sync(): void
    {
        add_action('user_register', [$this, 'on_user_register'], 20);
        add_action('profile_update', [$this, 'on_profile_update'], 20);
        add_action('added_user_meta', [$this, 'on_user_meta'], 20, 3);
        add_action('updated_user_meta', [$this, 'on_user_meta'], 20, 3);
        add_action('deleted_user', [$this, 'on_user_deleted']);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 20, 4);
        add_action('woocommerce_order_refunded', [$this, 'on_order_refunded'], 20);
        add_action('shutdown', [$this, 'flush_pending'], 1);
    }

    /**
     * Deferred to the end of the request: SMS sign-in creates the account
     * first and stores the mobile (billing_phone) only afterwards, so at
     * this moment the mobile — needed to link an existing lead instead
     * of duplicating it — is not there yet.
     */
    public function on_user_register(int $user_id): void
    {
        $this->pending_users[$user_id] = $user_id;
    }

    public function flush_pending(): void
    {
        foreach ($this->pending_users as $user_id) {
            unset($this->pending_users[$user_id]);

            if (!self::is_staff($user_id)) {
                $this->safely(fn() => $this->for_user($user_id));
            }
        }
    }

    /**
     * A later change of the account's billing phone / name fills the
     * contact's empty fields (new sign-ups are left to flush_pending).
     *
     * @param mixed $meta_id
     */
    public function on_user_meta($meta_id, int $user_id, string $meta_key): void
    {
        if (isset($this->pending_users[$user_id])) {
            return;
        }

        if (in_array($meta_key, ['billing_phone', 'billing_first_name', 'billing_last_name', 'billing_city'], true)) {
            $this->on_profile_update($user_id);
        }
    }

    /**
     * Fill contact fields that are still empty from the account. Fields
     * staff already filled in the CRM are never overwritten.
     */
    public function on_profile_update(int $user_id): void
    {
        $this->safely(function () use ($user_id): void {
            $contact = $this->contacts->find_by_user($user_id);
            $user    = get_userdata($user_id);

            if (null === $contact || !$user instanceof WP_User) {
                return;
            }

            $profile = $this->profile_of($user);
            $mobile  = $profile['mobile'];
            unset($profile['mobile']);

            $data = $this->only_empty($contact, $profile);

            if (null !== $mobile && empty($contact['mobile'])) {
                $owner = $this->contacts->find_by_mobile($mobile);

                if (null === $owner) {
                    $data['mobile'] = $mobile;
                } elseif ((int) $owner['id'] !== (int) $contact['id']) {
                    // Another contact already has this number: flag it for
                    // a person to merge rather than guessing.
                    $this->contacts->set_meta((int) $contact['id'], 'duplicate_mobile', $mobile);
                }
            }

            if ([] !== $data) {
                $this->contacts->update((int) $contact['id'], $data);
            }
        });
    }

    /**
     * The account is gone; the contact (and its history) stays.
     */
    public function on_user_deleted(int $user_id): void
    {
        $this->safely(function () use ($user_id): void {
            $contact = $this->contacts->find_by_user($user_id);

            if (null !== $contact) {
                $this->contacts->update((int) $contact['id'], ['user_id' => null]);
            }
        });
    }

    /**
     * @param mixed $order
     */
    public function on_order_status_changed(int $order_id, string $from, string $to, $order = null): void
    {
        $this->safely(function () use ($order_id, $order): void {
            $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);

            if (!$order instanceof WC_Order || vkl_is_carrier_order($order_id)) {
                return;
            }

            $contact_id = $this->for_order($order);

            if ($contact_id > 0) {
                $this->refresh_stats($contact_id);
            }
        });
    }

    public function on_order_refunded(int $order_id): void
    {
        $this->on_order_status_changed($order_id, '', '');
    }

    /**
     * Staff accounts are not customers.
     */
    public static function is_staff(int $user_id): bool
    {
        $user = get_userdata($user_id);

        return $user instanceof WP_User
            && [] !== array_intersect((array) $user->roles, ['administrator', 'shop_manager', VKL_Crm_Access::ROLE_AGENT, VKL_Crm_Access::ROLE_MANAGER]);
    }

    /**
     * @return string[]
     */
    public static function paid_statuses(): array
    {
        return function_exists('wc_get_is_paid_statuses') ? (array) wc_get_is_paid_statuses() : ['processing', 'completed'];
    }

    /**
     * Contact fields from a user account. mobile is null when the stored
     * billing phone is missing or not a valid Iranian mobile.
     *
     * @return array{first_name: string, last_name: string, email: string, city: string, mobile: ?string}
     */
    private function profile_of(WP_User $user): array
    {
        $uid    = (int) $user->ID;
        $phone  = vkl_normalize_mobile(vkl_latin_digits((string) get_user_meta($uid, 'billing_phone', true)));
        $first  = (string) ($user->first_name ?: get_user_meta($uid, 'billing_first_name', true));
        $last   = (string) ($user->last_name ?: get_user_meta($uid, 'billing_last_name', true));
        $email  = (string) $user->user_email;

        // Accounts created by SMS login get a placeholder address; it is
        // not a way to reach the customer.
        if ('' !== $email && (str_ends_with($email, '.local') || str_ends_with($email, '.invalid') || str_contains($email, '@example.'))) {
            $email = '';
        }

        return [
            'first_name' => mb_substr(trim($first), 0, 100),
            'last_name'  => mb_substr(trim($last), 0, 100),
            'email'      => strtolower(trim($email)),
            'city'       => mb_substr(trim((string) get_user_meta($uid, 'billing_city', true)), 0, 100),
            'mobile'     => vkl_is_mobile($phone) ? $phone : null,
        ];
    }

    /**
     * The subset of $fresh whose fields are empty in $contact.
     *
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $fresh
     * @return array<string, mixed>
     */
    private function only_empty(array $contact, array $fresh): array
    {
        $out = [];

        foreach ($fresh as $key => $value) {
            if ('' !== (string) $value && '' === trim((string) ($contact[$key] ?? ''))) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Gregorian or Jalali date → 'Y-m-d'; null for empty; false if invalid.
     *
     * @return string|null|false
     */
    private function date_or_null(string $value)
    {
        $value = trim(vkl_latin_digits($value));

        if ('' === $value) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && (int) $m[1] > 1700) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : false;
        }

        $g = VKL_Jalali::parse($value);

        return '' === $g ? false : $g;
    }

    /**
     * Sync runs inside other plugins' hooks (sign-up, checkout). A CRM
     * failure must never break those, so it is logged and swallowed.
     */
    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (function_exists('vkl')) {
                vkl('logger')->error('CRM sync failed.', ['error' => $e->getMessage()]);
            }
        }
    }
}
