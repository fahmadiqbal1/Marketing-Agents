<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Background music track (royalty-free library + trending discoveries).
 * Converted from Python: memory/models.py → MusicTrack
 */
class MusicTrack extends Model
{
    protected $fillable = [
        'title',
        'artist',
        'platform',
        'mood',
        'genre',
        'categories',
        'local_filename',
        'source_url',
        'duration_seconds',
        'is_royalty_free',
        'license_info',
        'trending_score',
        'is_trending',
        'note',
        'last_verified',
    ];

    protected $casts = [
        'duration_seconds' => 'float',
        'trending_score'   => 'float',
        'is_royalty_free'  => 'boolean',
        'is_trending'      => 'boolean',
        'last_verified'    => 'datetime',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where(function ($q) use ($platform) {
            $q->where('platform', $platform)
              ->orWhere('platform', 'all');
        });
    }

    public function scopeForMood($query, string $mood)
    {
        return $query->where('mood', $mood);
    }

    public function scopeRoyaltyFree($query)
    {
        return $query->where('is_royalty_free', true);
    }

    public function scopeTrending($query)
    {
        return $query->where('is_trending', true)
                     ->orderByDesc('trending_score');
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('categories', 'LIKE', "%{$category}%");
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Get the full local file path.
     */
    public function localPath(): ?string
    {
        if (!$this->local_filename) {
            return null;
        }
        return storage_path('app/public/music_library/' . $this->local_filename);
    }

    /**
     * Check if the local file exists.
     */
    public function hasLocalFile(): bool
    {
        $path = $this->localPath();
        return $path && file_exists($path);
    }

    /**
     * Get categories as array.
     */
    public function getCategoriesArray(): array
    {
        if (!$this->categories) {
            return [];
        }
        return array_map('trim', explode(',', $this->categories));
    }
}
