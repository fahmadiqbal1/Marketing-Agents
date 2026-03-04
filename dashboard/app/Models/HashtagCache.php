<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pre-built hashtag database per category and platform.
 */
class HashtagCache extends Model
{
    protected $table = 'hashtag_cache';

    protected $fillable = [
        'category',
        'platform',
        'hashtag',
        'relevance_score',
        'is_trending',
    ];

    protected $casts = [
        'relevance_score' => 'float',
        'is_trending'     => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeTrending($query)
    {
        return $query->where('is_trending', true);
    }

    public function scopeTopRelevance($query, int $limit = 15)
    {
        return $query->orderByDesc('relevance_score')->limit($limit);
    }

    // ── Static Helpers ────────────────────────────────────────────

    /**
     * Get hashtags for a specific category and platform.
     */
    public static function getHashtagsFor(string $category, string $platform, int $max = 15): array
    {
        return self::forCategory($category)
            ->forPlatform($platform)
            ->topRelevance($max)
            ->pluck('hashtag')
            ->toArray();
    }
}
