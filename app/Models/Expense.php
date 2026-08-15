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

    public function header()
    {
        return $this->belongsTo(AccountingHeader::class);
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['receipt_url'];
    }
}
