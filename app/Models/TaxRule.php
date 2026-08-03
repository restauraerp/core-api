<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class TaxRule extends Model
{
    use BelongsToTenant;

    // Previously absent, which left the model totally guarded: the create() in
    // TaxRuleController threw MassAssignmentException on every call.
    protected $fillable = [
        'name',
        'percentage',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
