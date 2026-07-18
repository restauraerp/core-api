<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Location;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $locations = Location::all();
        $password = Hash::make(config('app.demo_password', 'password'));

        // Create Admin
        $admin = User::updateOrCreate(
            ['email' => config('app.demo_username', 'admin@demo.com')],
            [
                'name' => 'Demo Admin',
                'password' => $password,
                'email_verified_at' => now(),
                'location_id' => $locations->first()?->id,
                'phone' => '01711000001',
            ]
        );
        $admin->assignRole('admin');

        // Create Super Admin for demo purposes (optional since AdminUserSeeder creates the main one)
        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@demo.com'],
            [
                'name' => 'Demo Super Admin',
                'password' => $password,
                'email_verified_at' => now(),
                'location_id' => $locations->first()?->id,
                'phone' => '01711000000',
            ]
        );
        $superAdmin->assignRole('super_admin');

        // Create Branch Managers, POS Managers, and Chefs for each location
        foreach ($locations as $index => $location) {
            // Branch Manager
            $branchManager = User::updateOrCreate(
                ['email' => "manager{$index}@demo.com"],
                [
                    'name' => "Branch Manager {$location->name}",
                    'password' => $password,
                    'email_verified_at' => now(),
                    'location_id' => $location->id,
                    'phone' => '01711' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
                ]
            );
            $branchManager->assignRole('branch_manager');

            // POS Manager
            $posManager = User::updateOrCreate(
                ['email' => "pos{$index}@demo.com"],
                [
                    'name' => "POS Manager {$location->name}",
                    'password' => $password,
                    'email_verified_at' => now(),
                    'location_id' => $location->id,
                    'phone' => '01711' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
                ]
            );
            $posManager->assignRole('pos_manager');

            // Chef
            $chef = User::updateOrCreate(
                ['email' => "chef{$index}@demo.com"],
                [
                    'name' => "Chef {$location->name}",
                    'password' => $password,
                    'email_verified_at' => now(),
                    'location_id' => $location->id,
                    'phone' => '01711' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
                ]
            );
            $chef->assignRole('chef');
        }
    }
}
