<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Payroll;
use Carbon\Carbon;

class DemoHrSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            // Seed Attendances for the last 5 days
            for ($i = 1; $i <= 5; $i++) {
                $date = Carbon::now()->subDays($i);
                
                // Randomly assign a status (present, absent, late)
                $statusRoll = rand(1, 100);
                if ($statusRoll <= 70) {
                    $status = 'Present';
                    $checkIn = $date->copy()->setTime(rand(8, 9), rand(0, 59));
                    $checkOut = $date->copy()->setTime(rand(17, 18), rand(0, 59));
                } elseif ($statusRoll <= 90) {
                    $status = 'Late';
                    $checkIn = $date->copy()->setTime(rand(10, 11), rand(0, 59));
                    $checkOut = $date->copy()->setTime(rand(17, 18), rand(0, 59));
                } else {
                    $status = 'Absent';
                    $checkIn = null;
                    $checkOut = null;
                }

                Attendance::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'date' => $date->toDateString(),
                    ],
                    [
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'status' => $status,
                    ]
                );
            }

            // Seed Leaves (1 leave request per user randomly)
            if (rand(1, 100) <= 50) {
                $start = Carbon::now()->addDays(rand(1, 10));
                $end = $start->copy()->addDays(rand(1, 3));
                $statuses = ['Pending', 'Approved', 'Rejected'];
                
                Leave::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'start_date' => $start->toDateString(),
                        'end_date' => $end->toDateString(),
                    ],
                    [
                        'reason' => 'Annual Leave or Medical reason',
                        'status' => $statuses[array_rand($statuses)],
                    ]
                );
            }

            // Seed Payrolls (for the current month)
            $month = Carbon::now()->format('F');
            $year = Carbon::now()->year;
            
            $basic = rand(30000, 80000);
            $bonus = rand(0, 5000);
            $overtime = rand(0, 2000);
            $deductions = rand(0, 1000);
            $net = $basic + $bonus + $overtime - $deductions;
            $statuses = ['Paid', 'Pending'];

            Payroll::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'month' => $month,
                    'year' => $year,
                ],
                [
                    'basic_salary' => $basic,
                    'bonus' => $bonus,
                    'overtime_pay' => $overtime,
                    'deductions' => $deductions,
                    'net_pay' => $net,
                    'status' => $statuses[array_rand($statuses)],
                ]
            );
        }
    }
}
