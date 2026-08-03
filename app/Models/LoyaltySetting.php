<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class LoyaltySetting extends Model
{
    use BelongsToTenant;

    // Previously absent, which left the model totally guarded: the create() in
    // LoyaltySettingController threw MassAssignmentException on every call.
    protected $fillable = [
        'points_per_amount',
        'cash_per_point',
        'tier_thresholds',
    ];

    protected $casts = [
        'tier_thresholds' => 'array',
    ];
}
