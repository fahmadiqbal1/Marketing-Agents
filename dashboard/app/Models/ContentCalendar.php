<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks what was posted when for balanced content scheduling.
 */
class ContentCalendar extends Model
{
    protected $table = 'content_calendar';

    protected $fillable = [
        'business_id',
        'post_id',
        'platform',
        'content_category',
        'posted_at',
        'notes',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('content_category', $category);
    }

    public function scopePostedBetween($query, $start, $end)
    {
        return $query->whereBetween('posted_at', [$start, $end]);
    }

    public function scopeLastDays($query, int $days = 7)
    {
        return $query->where('posted_at', '>=', now()->subDays($days));
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    // ── Static Helpers ────────────────────────────────────────────

    /**
     * Get recent post categories for content gap analysis.
     */
    public static function getRecentCategories(int $businessId, int $days = 14): array
    {
        return self::forBusiness($businessId)
            ->lastDays($days)
            ->distinct()
            ->pluck('content_category')
            ->toArray();
    }

    /**
     * Get category balance for a business.
     */
    public static function getCategoryBalance(int $businessId, int $days = 30): array
    {
        $counts = self::forBusiness($businessId)
            ->lastDays($days)
            ->selectRaw('content_category, COUNT(*) as total')
            ->groupBy('content_category')
            ->pluck('total', 'content_category')
            ->toArray();

        $total = array_sum($counts) ?: 1;
        $balance = [];

        foreach ($counts as $category => $count) {
            $balance[$category] = [
                'count'      => $count,
                'percentage' => round(($count / $total) * 100, 1),
            ];
        }

        return $balance;
    }
}
