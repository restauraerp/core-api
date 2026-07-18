<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Location;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $location = Location::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Banani Branch',
                'type' => 'head_office',
                'address' => 'Dhaka, Bangladesh',
                'phone' => '+8801234567890',
                'is_active' => true,
            ]
        );

        $user = User::updateOrCreate(
            ['email' => config('app.admin_email', 'toaihimel@gmail.com')],
            [
                'name' => 'Aftabul Islam',
                'password' => Hash::make(config('app.admin_password', 'Admin@PosBoss2026!')),
                'email_verified_at' => now(),
                'location_id' => $location->id,
            ]
        );

        $user->assignRole('super_admin');
    }
}
