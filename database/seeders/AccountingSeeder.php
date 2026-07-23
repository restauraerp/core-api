<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Suppliers (Required for Purchase Orders in OrderSeeder)
        $suppliersData = [
            [
                'name' => 'Bengal Fresh Produce',
                'contact_name' => 'Karim Rahman',
                'phone' => '01711223344',
                'email' => 'contact@bengalfresh.com',
                'address' => 'Kawran Bazar, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Italian Imports BD',
                'contact_name' => 'Luigi Rossi',
                'phone' => '01811556677',
                'email' => 'sales@italianimports.com.bd',
                'address' => 'Gulshan 2, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Prime Meats & Poultry',
                'contact_name' => 'Abul Hasan',
                'phone' => '01911998877',
                'email' => 'orders@primemeats.bd',
                'address' => 'Mirpur, Dhaka',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];
        
        DB::table('suppliers')->insert($suppliersData);
        $this->command->info('✅ AccountingSeeder: Seeded Suppliers.');
    }
}
