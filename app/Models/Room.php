<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One hotel room, by number, of a room type. */
class Room extends Model
{
    use BelongsToTenant;

    protected $fillable = ['room_type_id', 'number', 'floor', 'in_service', 'notes'];

    protected $casts = ['in_service' => 'boolean'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }
}
