<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksUploadedAssets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Income extends Model
{
    use BelongsToTenant;
    use SoftDeletes;
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

    public function trashedByUser()
    {
        return $this->belongsTo(User::class, 'trashed_by');
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
