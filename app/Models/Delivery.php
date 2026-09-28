<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Observers\DeliveryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

// Keeps riders' Telegram chats in step with the board - see the observer.
#[ObservedBy(DeliveryObserver::class)]
class Delivery extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** Server-side only: the rider's chat, needed to edit the message later. */
    protected $hidden = ['telegram_chat_id'];

    protected function casts(): array
    {
        return [
            'telegram_sent_at' => 'datetime',
            'telegram_redacted_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }
}
