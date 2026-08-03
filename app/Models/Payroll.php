<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class Payroll extends Model
{
    use BelongsToTenant;

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
