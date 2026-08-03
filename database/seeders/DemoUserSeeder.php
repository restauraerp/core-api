<?php

namespace Database\Seeders;

use App\Support\Tenancy\RoleDefinitions;
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
        // Was 'admin'; that role is now called restaurant_admin, to read
        // unambiguously next to the platform-level super_admin.
        $admin->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

        // A second owner-level login, useful for demoing two people managing
        // the same restaurant. Deliberately NOT super_admin: that is a platform
        // role now, and handing it to a demo account would imply cross-tenant
        // reach this user must not have. The real platform admin comes from
        // AdminUserSeeder.
        $secondOwner = User::updateOrCreate(
            ['email' => 'superadmin@demo.com'],
            [
                'name' => 'Demo Owner',
                'password' => $password,
                'email_verified_at' => now(),
                'location_id' => $locations->first()?->id,
                'phone' => '01711000000',
            ]
        );
        $secondOwner->assignRole(RoleDefinitions::RESTAURANT_ADMIN);

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

            // Riders (2 per location)
            $bengaliNames = [
                'Rahim Uddin', 'Abdul Karim', 'Rafiqul Islam', 'Nazmul Hasan', 
                'Kamrul Islam', 'Ariful Haque', 'Tarikul Islam', 'Faisal Ahmed',
                'Imran Hossain', 'Habibur Rahman', 'Ashraful Islam', 'Mehedi Hasan',
                'Zakir Hossain', 'Mahmudul Hasan', 'Shahriar Nafees', 'Tamim Iqbal'
            ];
            for ($i = 1; $i <= 2; $i++) {
                $riderName = $bengaliNames[array_rand($bengaliNames)];
                $rider = User::updateOrCreate(
                    ['email' => "rider{$index}_{$i}@demo.com"],
                    [
                        'name' => $riderName,
                        'password' => $password,
                        'email_verified_at' => now(),
                        'location_id' => $location->id,
                        'phone' => '01711' . str_pad((string) rand(1, 999999), 6, '0', STR_PAD_LEFT),
                    ]
                );
                $rider->assignRole('rider');
            }
        }
    }
}
