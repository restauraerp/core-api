<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_dashboard',
            
            'view_pos', 'create_pos_order',
            
            'view_orders', 'update_order_status', 'delete_order',
            
            'view_catalog', 'create_catalog_item', 'update_catalog_item', 'delete_catalog_item',
            
            'view_inventory', 'create_inventory_item', 'update_inventory_item', 'delete_inventory_item',
            
            'view_kitchen_kiosk', 'update_kiosk_status',
            
            'view_hr', 'manage_employees', 'manage_attendance', 'manage_payroll', 'manage_roles_permissions',
            
            'view_delivery', 'update_delivery_status',
            
            'view_crm', 'manage_customers', 'manage_loyalty_settings',
            
            'view_locations', 'create_location', 'update_location', 'delete_location',
            
            'view_accounting', 'manage_ledgers', 'manage_expenses',
            
            'view_website', 'manage_website_content',
            
            'view_settings', 'manage_system_settings'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->givePermissionTo(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->givePermissionTo(Permission::all());

        $chef = Role::firstOrCreate(['name' => 'chef']);
        $chef->givePermissionTo(['view_kitchen_kiosk', 'update_kiosk_status']);

        $posManager = Role::firstOrCreate(['name' => 'pos_manager']);
        $posManager->givePermissionTo([
            'view_dashboard', 
            'view_pos', 'create_pos_order',
            'view_orders', 'update_order_status'
        ]);

        $branchManager = Role::firstOrCreate(['name' => 'branch_manager']);
        $branchManager->givePermissionTo([
            'view_dashboard',
            'view_pos', 'create_pos_order',
            'view_orders', 'update_order_status',
            'view_inventory', 'update_inventory_item',
            'view_hr', 'manage_attendance'
        ]);
    }
}
