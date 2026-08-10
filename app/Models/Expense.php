<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;

class Expense extends Model
{
    use BelongsToTenant;
    use TracksUploadedAssets;

    protected $guarded = [];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['receipt_url'];
    }
}
