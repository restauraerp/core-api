<?php

namespace App\Support\Tenancy;

/**
 * The canonical permission catalogue and role templates.
 *
 * Single source of truth shared by RolePermissionSeeder (which installs the
 * global catalogue) and TenantProvisioner (which stamps the per-tenant roles
 * out of these templates). They used to be duplicated in the seeder; keeping
 * them apart is how a new permission ends up granted on one path and not the
 * other.
 *
 * Permission *names* are global and system-defined - they map 1:1 to the
 * hardcoded route guards in front/src/app/(admin)/layout.tsx, so a tenant
 * inventing its own would guard nothing. Roles are per-tenant and editable.
 */
class RoleDefinitions
{
    /** Platform-level role. Lives at tenant_id = NULL and is shared by all tenants. */
    public const SUPER_ADMIN = 'super_admin';

    /** The restaurant owner. Created per tenant at provisioning time. */
    public const RESTAURANT_ADMIN = 'restaurant_admin';

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            'view_dashboard',

            'view_pos', 'create_pos_order',

            'view_orders', 'update_order_status', 'delete_order', 'trash_order',

            'view_catalog', 'create_catalog_item', 'update_catalog_item', 'delete_catalog_item',

            'view_inventory', 'create_inventory_item', 'update_inventory_item', 'delete_inventory_item',

            'view_kitchen_kiosk', 'update_kiosk_status',

            'view_hr', 'manage_employees', 'manage_attendance', 'manage_leaves', 'manage_payroll', 'manage_roles_permissions',

            'view_delivery', 'update_delivery_status',

            'view_crm', 'manage_customers', 'manage_loyalty_settings',

            'view_locations', 'create_location', 'update_location', 'delete_location',

            'view_accounting', 'manage_ledgers', 'manage_expenses', 'manage_incomes',

            'view_website', 'manage_website_content',

            'view_settings', 'manage_system_settings',

            'view_reporting',
        ];
    }

    /**
     * Roles created inside every new tenant.
     *
     * `null` means "every permission" - used for the restaurant owner, who
     * should automatically pick up any permission added in a later release
     * rather than silently losing access to a new module.
     *
     * @return array<string, list<string>|null>
     */
    public static function tenantRoles(): array
    {
        return [
            self::RESTAURANT_ADMIN => null,

            'branch_manager' => [
                'view_dashboard',
                'view_pos', 'create_pos_order',
                'view_orders', 'update_order_status',
                'view_inventory', 'update_inventory_item',
                'view_hr', 'manage_employees', 'manage_attendance', 'manage_leaves', 'manage_payroll',
                'view_reporting',
            ],

            'pos_manager' => [
                'view_dashboard',
                'view_pos', 'create_pos_order',
                'view_orders', 'update_order_status',
            ],

            'chef' => [
                'view_kitchen_kiosk', 'update_kiosk_status',
            ],

            'rider' => [
                'view_delivery', 'update_delivery_status',
            ],

            'accountant' => [
                'view_dashboard',
                'view_accounting', 'manage_ledgers', 'manage_expenses', 'manage_incomes',
                'view_reporting',
            ],
        ];
    }
}
