<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $firstNames = ['Rahim', 'Karim', 'Jamil', 'Hasan', 'Tariq', 'Rafiq', 'Shafiq', 'Nasir', 'Faruq', 'Mahmud', 'Salman', 'Sajid', 'Sakib', 'Rakib', 'Mehdi', 'Imran', 'Faisal', 'Habib', 'Kamal', 'Jamal', 'Anis', 'Ashiq', 'Arif', 'Asif', 'Tushar', 'Fatema', 'Ayesha', 'Khadija', 'Sumaiya', 'Sadia', 'Nusrat', 'Tania', 'Sharmin', 'Farhana', 'Sonia', 'Ruma', 'Rina', 'Shirin', 'Nasrin', 'Farida'];
        $lastNames = ['Rahman', 'Hossain', 'Ahmed', 'Ali', 'Islam', 'Chowdhury', 'Khan', 'Mia', 'Sikder', 'Haque', 'Talukder', 'Hasan', 'Uddin', 'Begum', 'Akter', 'Khatun'];
        $locations = ['Banani, Dhaka', 'Gulshan, Dhaka', 'Dhanmondi, Dhaka', 'Uttara, Dhaka', 'Mirpur, Dhaka', 'Mohakhali, Dhaka', 'Badda, Dhaka', 'Tejgaon, Dhaka', 'Motijheel, Dhaka', 'Paltan, Dhaka'];
        $tiers = ['Bronze', 'Silver', 'Gold', 'Platinum'];

        $customers = [];

        // Always keep the first few specific ones if needed, or just generate 50.
        for ($i = 1; $i <= 50; $i++) {
            $name = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
            $phone = '017' . str_pad((string)(11000000 + $i), 8, '0', STR_PAD_LEFT);
            $email = strtolower(str_replace(' ', '.', $name)) . $i . '@example.com';
            $address = $locations[array_rand($locations)];
            $loyalty = rand(0, 2000);
            
            $tier = 'Bronze';
            if ($loyalty > 1000) $tier = 'Platinum';
            elseif ($loyalty > 500) $tier = 'Gold';
            elseif ($loyalty > 200) $tier = 'Silver';

            $customers[] = [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'loyalty_points' => $loyalty,
                'tier' => $tier,
            ];
        }

        foreach ($customers as $customer) {
            Customer::updateOrCreate(['phone' => $customer['phone']], $customer);
        }
    }
}

