<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'business_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ── Relationships ────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function ownedBusinesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_id');
    }

    /**
     * Alias for ownedBusinesses — used by API routes.
     */
    public function businesses(): HasMany
    {
        return $this->ownedBusinesses();
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function isOwner(): bool   { return $this->role === 'owner'; }
    public function isAdmin(): bool   { return in_array($this->role, ['owner', 'admin']); }
    public function isEditor(): bool  { return in_array($this->role, ['owner', 'admin', 'editor']); }
}
