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
            'manage_pos',
            'manage_orders',
            'manage_catalog',
            'manage_inventory',
            'view_kitchen_kiosk',
            'manage_hr',
            'manage_delivery',
            'manage_crm',
            'manage_locations',
            'manage_accounting',
            'manage_website',
            'manage_settings'
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        $superAdmin->givePermissionTo(Permission::all());

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->givePermissionTo(Permission::all());

        $chef = Role::firstOrCreate(['name' => 'chef']);
        $chef->givePermissionTo(['view_kitchen_kiosk']);

        $posManager = Role::firstOrCreate(['name' => 'pos_manager']);
        $posManager->givePermissionTo(['view_dashboard', 'manage_pos', 'manage_orders']);

        $branchManager = Role::firstOrCreate(['name' => 'branch_manager']);
        $branchManager->givePermissionTo([
            'view_dashboard',
            'manage_pos',
            'manage_orders',
            'manage_inventory',
            'manage_hr'
        ]);
    }
}
