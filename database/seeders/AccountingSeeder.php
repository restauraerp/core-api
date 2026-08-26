<?php

namespace Database\Seeders;

use App\Models\AccountingHeader;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Accounting Headers — income and expense categories used by the
        //    ledger and expense forms. These are seeded before OrderSeeder runs
        //    so that demo expenses and sales entries can reference them.
        $headersData = [
            ['name' => 'Food & Beverage Sales', 'type' => 'income',  'description' => 'Revenue from food and drink sales'],
            ['name' => 'Hall & Event Rental',     'type' => 'income',  'description' => 'Private hall bookings, parties and corporate events'],
            ['name' => 'Other Income',            'type' => 'income',  'description' => 'Scrap sales, vendor commissions and one-off receipts'],
            ['name' => 'Rent & Property',        'type' => 'expense', 'description' => 'Monthly rent, lease, and property costs'],
            ['name' => 'Staff & Salaries',        'type' => 'expense', 'description' => 'Employee wages, salaries, and payroll'],
            ['name' => 'Utilities & Services',    'type' => 'expense', 'description' => 'Electricity, gas, water, internet, and other utility bills'],
            ['name' => 'Inventory Purchases',     'type' => 'expense', 'description' => 'Stock purchased via purchase orders'],
        ];

        foreach ($headersData as $header) {
            AccountingHeader::firstOrCreate(
                ['name' => $header['name']],
                array_merge($header, ['is_active' => true]),
            );
        }

        $this->command->info('✅ AccountingSeeder: Seeded '.count($headersData).' accounting headers.');

        // 2. Seed Suppliers (Required for Purchase Orders in OrderSeeder)
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
        
        // Via the model, not DB::table: BelongsToTenant stamps tenant_id on
        // create, and updateOrCreate keeps this idempotent across the two demo
        // tenants (and across re-runs).
        foreach ($suppliersData as $supplier) {
            Supplier::updateOrCreate(['name' => $supplier['name']], $supplier);
        }

        $this->command->info('✅ AccountingSeeder: Seeded '.count($suppliersData).' suppliers.');
    }
}
