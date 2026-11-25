<?php

namespace Database\Seeders;

use App\Models\Partner;
use Database\Seeders\Concerns\SeedsTenantData;
use Illuminate\Database\Seeder;

class PartnerSeeder extends Seeder
{
    use SeedsTenantData;

    public function run(): void
    {
        $partners = [
            [
                'name' => 'Foodpanda',
                'commission_rate' => 20.00,
                'contact_name' => 'Merchant Operations',
                'phone' => '01711000101',
                'email' => 'merchants@foodpanda.com.bd',
                'is_active' => true,
                'notes' => 'Primary delivery aggregator, bi-weekly settlement.',
            ],
            [
                'name' => 'Pathao Food',
                'commission_rate' => 15.00,
                'contact_name' => 'Food Partner Desk',
                'phone' => '01711000102',
                'email' => 'restaurants@pathao.com',
                'is_active' => true,
                'notes' => 'City delivery partner, weekly settlement.',
            ],
            [
                'name' => 'HungryNaki',
                'commission_rate' => 18.00,
                'contact_name' => 'Partner Support',
                'phone' => '01711000103',
                'email' => 'partners@hungrynaki.com',
                'is_active' => true,
                'notes' => 'Aggregator channel for evening deliveries.',
            ],
        ];

        foreach ($partners as $partnerData) {
            Partner::updateOrCreate(
                ['name' => $partnerData['name']],
                $partnerData
            );
        }

        $this->command?->info('✅ PartnerSeeder: Seeded Foodpanda, Pathao Food, and HungryNaki aggregators.');
    }
}
