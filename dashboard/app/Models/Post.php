<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $fillable = [
        'business_id', 'created_by', 'title', 'caption', 'content',
        'platform', 'pillar', 'status', 'media_url', 'thumbnail_url',
        'thread_id', 'scheduled_at', 'published_at', 'meta',
    ];

    protected $casts = [
        'scheduled_at'  => 'datetime',
        'published_at'  => 'datetime',
        'meta'          => 'array',
    ];

    // ── Scopes ──────────────────────────────────────────────────

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled')
                     ->where('scheduled_at', '>', now());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->where('status', 'scheduled')
                     ->whereBetween('scheduled_at', [now(), now()->addDays($days)])
                     ->orderBy('scheduled_at');
    }

    // ── Relationships ────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'published' => 'bg-success',
            'approved'  => 'bg-primary',
            'pending'   => 'bg-warning text-dark',
            'scheduled' => 'bg-info text-dark',
            'denied'    => 'bg-secondary',
            'failed'    => 'bg-danger',
            default     => 'bg-secondary',
        };
    }
}
