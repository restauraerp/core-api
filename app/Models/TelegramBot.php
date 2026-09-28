<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant's own Telegram bot, the one its riders are notified through.
 */
class TelegramBot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'token',
        'bot_user_id',
        'username',
        'name',
        'webhook_secret',
        'last_error',
    ];

    /** Never serialised: either one lets somebody act as the bot. */
    protected $hidden = [
        'token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
        ];
    }

    /** t.me link a rider opens to link their account. */
    public function startLink(string $payload): string
    {
        return 'https://t.me/'.$this->username.'?start='.$payload;
    }
}
