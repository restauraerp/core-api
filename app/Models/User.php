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

#[Fillable(['name', 'email', 'password', 'location_id', 'phone', 'image_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    public function location()
    {
        return $this->belongsTo(Location::class);
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
        ];
    }

    /**
     * Deliberately absent from #[Fillable]: platform access must never be
     * grantable through a mass-assigned request body.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }
}
