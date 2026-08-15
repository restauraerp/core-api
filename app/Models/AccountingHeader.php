<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AccountingHeader extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function ledgers()
    {
        return $this->hasMany(AccountingLedger::class, 'header_id');
    }
}
