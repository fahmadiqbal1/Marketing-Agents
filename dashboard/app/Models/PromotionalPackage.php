<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI-proposed promotional packages for business services.
 */
class PromotionalPackage extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'tagline',
        'description',
        'services_included',
        'discount_details',
        'target_audience',
        'occasion',
        'suggested_price',
        'content_ideas',
        'status',
        'graphic_path',
    ];

    protected $casts = [
        'services_included' => 'array',
        'content_ideas'     => 'array',
    ];

    // ── Status Constants ──────────────────────────────────────────

    const STATUS_PROPOSED = 'proposed';
    const STATUS_APPROVED = 'approved';
    const STATUS_DENIED   = 'denied';
    const STATUS_POSTED   = 'posted';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeProposed($query)
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function approve(): void
    {
        $this->update(['status' => self::STATUS_APPROVED]);
    }

    public function deny(): void
    {
        $this->update(['status' => self::STATUS_DENIED]);
    }

    public function markPosted(): void
    {
        $this->update(['status' => self::STATUS_POSTED]);
    }
}
