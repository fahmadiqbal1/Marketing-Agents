<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks engagement events across platforms for analytics.
 */
class EngagementEvent extends Model
{
    protected $table = 'engagement_events';

    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'business_id',
        'platform',
        'post_id',
        'event_type',
        'count',
        'extra_data',
        'created_at',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'created_at' => 'datetime',
    ];

    // ── Event Type Constants ──────────────────────────────────────

    const TYPE_LIKE    = 'like';
    const TYPE_COMMENT = 'comment';
    const TYPE_SHARE   = 'share';
    const TYPE_SAVE    = 'save';
    const TYPE_CLICK   = 'click';
    const TYPE_VIEW    = 'view';
    const TYPE_FOLLOW  = 'follow';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeLastDays($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Static Logging Methods ────────────────────────────────────

    /**
     * Log an engagement event.
     */
    public static function log(
        int $businessId,
        string $platform,
        string $eventType,
        int $count = 1,
        ?string $postId = null,
        array $extraData = []
    ): self {
        return self::create([
            'business_id' => $businessId,
            'platform'    => $platform,
            'event_type'  => $eventType,
            'count'       => $count,
            'post_id'     => $postId,
            'extra_data'  => !empty($extraData) ? $extraData : null,
            'created_at'  => now(),
        ]);
    }

    /**
     * Get engagement summary for a business.
     */
    public static function getSummary(int $businessId, int $days = 7): array
    {
        $events = self::forBusiness($businessId)
            ->lastDays($days)
            ->selectRaw('event_type, SUM(count) as total')
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->toArray();

        return [
            'likes'    => $events[self::TYPE_LIKE] ?? 0,
            'comments' => $events[self::TYPE_COMMENT] ?? 0,
            'shares'   => $events[self::TYPE_SHARE] ?? 0,
            'saves'    => $events[self::TYPE_SAVE] ?? 0,
            'clicks'   => $events[self::TYPE_CLICK] ?? 0,
            'views'    => $events[self::TYPE_VIEW] ?? 0,
        ];
    }
}
