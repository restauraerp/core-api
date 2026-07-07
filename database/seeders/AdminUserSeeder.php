<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $location = \App\Models\Location::updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Main Branch',
                'type' => 'head_office',
                'address' => 'Dhaka, Bangladesh',
                'phone' => '+8801234567890',
                'is_active' => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'toaihimel@gmail.com'],
            [
                'name' => 'Himel (Admin)',
                'password' => Hash::make('Admin@PosBoss2026!'),
                'email_verified_at' => now(),
                'location_id' => $location->id,
            ]
        );
    }
}
