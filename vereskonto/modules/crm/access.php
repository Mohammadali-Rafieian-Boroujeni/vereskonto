<?php
/**
 * CRM roles, capabilities and record-level access.
 *
 * Every "may this user see / change this record?" question in the CRM —
 * admin screens, REST API, background jobs — is answered here, so the
 * rules live in exactly one place.
 *
 *   vkl_crm_access    open the CRM at all
 *   vkl_crm_edit_own  create records, edit the ones they own
 *   vkl_crm_edit_all  see and edit everyone's records, reassign owners
 *   vkl_crm_delete    delete records
 *   vkl_crm_reports   reports and dashboard figures for the whole team
 *   vkl_crm_export    CSV export
 *   vkl_crm_settings  pipelines, stages, tags, sources, CRM settings
 *
 * @package Vereskonto\Modules\Crm
 * @since   2.16.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Crm_Access
 */
final class VKL_Crm_Access
{
    public const CAP_ACCESS   = 'vkl_crm_access';
    public const CAP_EDIT_OWN = 'vkl_crm_edit_own';
    public const CAP_EDIT_ALL = 'vkl_crm_edit_all';
    public const CAP_DELETE   = 'vkl_crm_delete';
    public const CAP_REPORTS  = 'vkl_crm_reports';
    public const CAP_EXPORT   = 'vkl_crm_export';
    public const CAP_SETTINGS = 'vkl_crm_settings';

    public const ROLE_AGENT   = 'vkl_sales_agent';
    public const ROLE_MANAGER = 'vkl_sales_manager';

    /**
     * Who gets which capability. Existing roles only gain capabilities;
     * nothing they already have is changed.
     *
     * @return array<string, string[]>
     */
    public static function matrix(): array
    {
        $all     = self::all_caps();
        $manager = array_values(array_diff($all, [self::CAP_SETTINGS]));

        return [
            self::ROLE_AGENT   => [self::CAP_ACCESS, self::CAP_EDIT_OWN],
            self::ROLE_MANAGER => $manager,
            'shop_manager'     => $manager,
            'administrator'    => $all,
        ];
    }

    /** @return string[] */
    public static function all_caps(): array
    {
        return [
            self::CAP_ACCESS, self::CAP_EDIT_OWN, self::CAP_EDIT_ALL, self::CAP_DELETE,
            self::CAP_REPORTS, self::CAP_EXPORT, self::CAP_SETTINGS,
        ];
    }

    /**
     * Create the two CRM roles and grant capabilities. Idempotent; called
     * by the schema installer and safe to call again after changes here.
     */
    public static function sync_roles(): void
    {
        $labels = [
            self::ROLE_AGENT   => 'کارشناس فروش',
            self::ROLE_MANAGER => 'مدیر فروش',
        ];

        foreach ($labels as $role => $label) {
            if (null === get_role($role)) {
                add_role($role, $label, ['read' => true]);
            }
        }

        foreach (self::matrix() as $role_name => $caps) {
            $role = get_role($role_name);

            if (null === $role) {
                continue; // e.g. shop_manager when WooCommerce is off.
            }

            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Undo sync_roles(): drop the CRM capabilities from every role and
     * delete the two CRM roles. Users who had only a CRM role are moved
     * to "customer" (or "subscriber") so they keep a working account.
     */
    public static function remove_roles(): void
    {
        $fallback = null !== get_role('customer') ? 'customer' : 'subscriber';

        foreach ([self::ROLE_AGENT, self::ROLE_MANAGER] as $role) {
            foreach (get_users(['role' => $role, 'fields' => 'ID']) as $user_id) {
                $user = get_userdata((int) $user_id);

                if ($user instanceof WP_User) {
                    $user->remove_role($role);

                    if ([] === $user->roles) {
                        $user->add_role($fallback);
                    }
                }
            }

            remove_role($role);
        }

        foreach (wp_roles()->role_objects as $role) {
            foreach (self::all_caps() as $cap) {
                if ($role->has_cap($cap)) {
                    $role->remove_cap($cap);
                }
            }
        }
    }

    /* ------------------------------------------------------------------ *
     *  Record-level checks
     * ------------------------------------------------------------------ */

    public function can(string $cap, int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        return $user_id > 0 && user_can($user_id, $cap);
    }

    /**
     * May the user see this record? Records are contacts, deals or tasks
     * (anything with owner_id / assignee_id).
     *
     * @param array<string, mixed> $record
     */
    public function can_view(array $record, int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        if (!$this->can(self::CAP_ACCESS, $user_id)) {
            return false;
        }

        if ($this->can(self::CAP_EDIT_ALL, $user_id)) {
            return true;
        }

        $owner = self::owner_of($record);

        return $owner === $user_id || (0 === $owner && $this->agents_see_unassigned());
    }

    /**
     * May the user change this record? Agents change only what they own;
     * an unassigned record must be claimed first (claim = assign to self).
     *
     * @param array<string, mixed> $record
     */
    public function can_edit(array $record, int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        if ($this->can(self::CAP_EDIT_ALL, $user_id)) {
            return true;
        }

        return $this->can(self::CAP_EDIT_OWN, $user_id) && self::owner_of($record) === $user_id;
    }

    /**
     * May the user take an unassigned record for themselves?
     *
     * @param array<string, mixed> $record
     */
    public function can_claim(array $record, int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        return 0 === self::owner_of($record)
            && $this->can(self::CAP_EDIT_OWN, $user_id)
            && $this->can_view($record, $user_id);
    }

    /**
     * May the user give this record to $new_owner? Managers may give to
     * anyone with CRM access; agents only to themselves (claiming).
     *
     * @param array<string, mixed> $record
     */
    public function can_assign(array $record, int $new_owner, int $user_id = 0): bool
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        if ($new_owner > 0 && !$this->can(self::CAP_ACCESS, $new_owner)) {
            return false;
        }

        if ($this->can(self::CAP_EDIT_ALL, $user_id)) {
            return true;
        }

        return $new_owner === $user_id && $this->can_claim($record, $user_id);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function can_delete(array $record, int $user_id = 0): bool
    {
        return $this->can(self::CAP_DELETE, $user_id) && $this->can_view($record, $user_id);
    }

    /**
     * WHERE fragment limiting a list query to what the user may see.
     * Repositories append it to their own WHERE; the column is the
     * table's owner column (owner_id or assignee_id).
     *
     * @return array{0: string, 1: array<int, int>} [sql, args]
     */
    public function scope_sql(string $column, int $user_id = 0): array
    {
        $user_id = $user_id > 0 ? $user_id : get_current_user_id();

        if (!preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $column)) {
            return ['1=0', []];
        }

        if (!$this->can(self::CAP_ACCESS, $user_id)) {
            return ['1=0', []];
        }

        if ($this->can(self::CAP_EDIT_ALL, $user_id)) {
            return ['1=1', []];
        }

        if ($this->agents_see_unassigned()) {
            return ["({$column} = %d OR {$column} = 0)", [$user_id]];
        }

        return ["{$column} = %d", [$user_id]];
    }

    /**
     * Users that records can be assigned to (anyone with CRM access).
     *
     * @return array<int, string> user id => display name
     */
    public function assignable_users(): array
    {
        $out = [];

        foreach (get_users(['capability' => self::CAP_ACCESS, 'orderby' => 'display_name', 'fields' => ['ID', 'display_name']]) as $u) {
            $out[(int) $u->ID] = (string) $u->display_name;
        }

        return $out;
    }

    private function agents_see_unassigned(): bool
    {
        return function_exists('vkl') ? vkl('config')->get_bool('crm_agents_see_unassigned', true) : true;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function owner_of(array $record): int
    {
        return (int) ($record['owner_id'] ?? $record['assignee_id'] ?? 0);
    }

    /**
     * Let CRM staff into wp-admin. WooCommerce otherwise redirects any
     * user without edit_posts/manage_woocommerce back to My Account, and
     * hides their admin bar.
     */
    public static function register_admin_access(): void
    {
        $allow = static fn(bool $prevent): bool => $prevent && !current_user_can(self::CAP_ACCESS);

        add_filter('woocommerce_prevent_admin_access', $allow);
        add_filter('woocommerce_disable_admin_bar', $allow);
    }
}
