<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoEmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();
        $locations = Location::all();
        $password = Hash::make('password');

        if ($locations->isEmpty()) {
            return;
        }

        // Available roles we might assign
        $roles = ['pos_manager', 'chef', 'branch_manager']; // Can add more if needed
        $bdPrefixes = ['017', '018', '019', '016', '015', '013', '014'];

        for ($i = 1; $i <= 20; $i++) {
            $location = $locations->random();
            $prefix = $bdPrefixes[array_rand($bdPrefixes)];
            $phone = $prefix.str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);

            $employee = User::updateOrCreate(
                ['email' => "employee{$i}@demo.com"],
                [
                    'name' => $faker->name,
                    'password' => $password,
                    'email_verified_at' => now(),
                    'location_id' => $location->id,
                    'phone' => $phone,
                ]
            );

            $employee->assignRole($roles[array_rand($roles)]);
        }

        // Waiter accounts: staff members who serve tables but do not log in to the system
        $waiters = [
            ['name' => 'Kabir Hossain', 'phone' => '01712000001'],
            ['name' => 'Sumon Mia', 'phone' => '01712000002'],
            ['name' => 'Rashedul Islam', 'phone' => '01712000003'],
            ['name' => 'Abul Kalam', 'phone' => '01712000004'],
            ['name' => 'Mehedi Hasan', 'phone' => '01712000005'],
        ];

        foreach ($waiters as $w) {
            $location = $locations->random();
            $waiter = User::updateOrCreate(
                ['phone' => $w['phone']],
                [
                    'name' => $w['name'],
                    'email' => null,
                    'password' => Hash::make(Str::random(48)),
                    'can_login' => false,
                    'location_id' => $location->id,
                ]
            );

            $waiter->assignRole('waiter');
        }
    }
}
