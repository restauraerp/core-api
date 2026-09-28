<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\NormalisesPhone;
use App\Models\Concerns\TracksUploadedAssets;

#[Fillable(['name', 'email', 'password', 'location_id', 'phone', 'image_url', 'can_login'])]
// telegram_chat_id is hidden: it is only ever needed server-side, to send.
#[Hidden(['password', 'remember_token', 'telegram_chat_id'])]
class User extends Authenticatable
{
    use BelongsToTenant, NormalisesPhone;
    use TracksUploadedAssets;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /** Whether this user has linked the restaurant's Telegram bot. */
    public function hasTelegram(): bool
    {
        return $this->telegram_chat_id !== null && $this->telegram_chat_id !== '';
    }

    /** Forget the Telegram link - the rider blocked the bot, or was unlinked. */
    public function unlinkTelegram(): void
    {
        $this->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'can_login' => 'boolean',
            'telegram_linked_at' => 'datetime',
        ];
    }

    /**
     * Whether this account may sign in. A user created only to be assigned
     * work (a rider, a waiter credited with sales) exists without ever
     * opening the app. Null reads as allowed, so a row written before the
     * column existed keeps working.
     */
    public function canLogIn(): bool
    {
        return $this->can_login !== false;
    }

    /**
     * Deliberately absent from #[Fillable]: platform access must never be
     * grantable through a mass-assigned request body.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * @return list<string>
     */
    public function uploadedAssetColumns(): array
    {
        return ['image_url'];
    }
}
