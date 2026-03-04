<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPlatform extends Model
{
    protected $fillable = [
        'business_id', 'key', 'name', 'connected',
        'credentials', 'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected $casts = [
        'connected'      => 'boolean',
        'credentials'    => 'encrypted:array',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

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
