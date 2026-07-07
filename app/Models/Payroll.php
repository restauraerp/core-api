<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payroll extends Model
{
    protected $fillable = [
        'user_id',
        'month',
        'year',
        'basic_salary',
        'bonus',
        'overtime_pay',
        'deductions',
        'net_pay',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
