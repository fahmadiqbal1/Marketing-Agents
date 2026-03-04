<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Media item (photo/video) received from Telegram or uploaded.
 */
class MediaItem extends Model
{
    protected $fillable = [
        'business_id',
        'telegram_file_id',
        'file_path',
        'file_name',
        'media_type',
        'mime_type',
        'width',
        'height',
        'duration_seconds',
        'file_size_bytes',
        'content_category',
        'analysis_json',
        'quality_score',
        'is_used_in_collage',
        'is_used_in_compilation',
    ];

    protected $casts = [
        'analysis_json'         => 'array',
        'quality_score'         => 'float',
        'duration_seconds'      => 'float',
        'is_used_in_collage'    => 'boolean',
        'is_used_in_compilation'=> 'boolean',
    ];

    // ── Constants ─────────────────────────────────────────────────

    const TYPE_PHOTO    = 'photo';
    const TYPE_VIDEO    = 'video';
    const TYPE_DOCUMENT = 'document';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopePhotos($query)
    {
        return $query->where('media_type', self::TYPE_PHOTO);
    }

    public function scopeVideos($query)
    {
        return $query->where('media_type', self::TYPE_VIDEO);
    }

    public function scopeUnused($query)
    {
        return $query->where('is_used_in_collage', false)
                     ->where('is_used_in_compilation', false);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isVertical(): bool
    {
        if (!$this->width || !$this->height) {
            return false;
        }
        return $this->height > $this->width;
    }

    public function aspectRatio(): ?float
    {
        if (!$this->width || !$this->height) {
            return null;
        }
        return round($this->width / $this->height, 2);
    }
}
