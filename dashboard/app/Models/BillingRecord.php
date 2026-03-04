<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly billing record per tenant — tracks AI usage costs.
 * Converted from Python: memory/models.py → BillingRecord
 */
class BillingRecord extends Model
{
    protected $fillable = [
        'business_id',
        'period_start',
        'period_end',
        'ai_tokens_used',
        'ai_cost_usd',
        'platform_owner_id',
        'status',
    ];

    protected $casts = [
        'period_start'    => 'datetime',
        'period_end'      => 'datetime',
        'ai_tokens_used'  => 'integer',
        'ai_cost_usd'     => 'float',
    ];

    // ── Status Constants ──────────────────────────────────────────

    const STATUS_PENDING = 'pending';
    const STATUS_PAID    = 'paid';
    const STATUS_OVERDUE = 'overdue';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->where('period_start', '>=', $start)
                     ->where('period_end', '<=', $end);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Mark the record as paid.
     */
    public function markPaid(): void
    {
        $this->update(['status' => self::STATUS_PAID]);
    }

    /**
     * Mark the record as overdue.
     */
    public function markOverdue(): void
    {
        $this->update(['status' => self::STATUS_OVERDUE]);
    }

    /**
     * Add token usage to this billing period.
     */
    public function addUsage(int $tokens, float $costUsd): void
    {
        $this->increment('ai_tokens_used', $tokens);
        $this->increment('ai_cost_usd', $costUsd);
    }

    /**
     * Get or create the current billing record for a business.
     */
    public static function currentPeriod(int $businessId): self
    {
        $start = now()->startOfMonth();
        $end   = now()->endOfMonth();

        return self::firstOrCreate(
            [
                'business_id'  => $businessId,
                'period_start' => $start,
                'period_end'   => $end,
            ],
            [
                'ai_tokens_used' => 0,
                'ai_cost_usd'    => 0.0,
                'status'         => self::STATUS_PENDING,
            ]
        );
    }
}
