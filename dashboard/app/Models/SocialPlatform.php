<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPlatform extends Model
{
    protected $fillable = [
        'business_id', 'key', 'platform', 'name', 'connected',
        'credentials',
        'access_token', 'refresh_token', 'client_id', 'client_secret',
        'extra_data', 'scopes',
        'status', 'connected_at',
        'last_tested_at', 'last_test_status', 'last_test_message',
        'last_used_at', 'expires_at', 'last_error',
    ];

    protected $casts = [
        'connected'      => 'boolean',
        'credentials'    => 'encrypted:array',
        'last_tested_at' => 'datetime',
        'connected_at'   => 'datetime',
        'last_used_at'   => 'datetime',
        'expires_at'     => 'datetime',
    ];

    protected $hidden = ['credentials', 'access_token', 'refresh_token', 'client_id', 'client_secret'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function statusBadgeClass(): string
    {
        if (! $this->connected) return 'bg-secondary';
        return $this->last_test_status === 'ok' ? 'bg-success' : 'bg-warning text-dark';
    }
}
