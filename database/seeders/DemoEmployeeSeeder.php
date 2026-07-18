<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Location;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as Faker;

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
            $phone = $prefix . str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            
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
    }
}
