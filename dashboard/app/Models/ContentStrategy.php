<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentStrategy extends Model
{
    protected $fillable = [
        'business_id', 'goal', 'target_audience', 'brand_voice',
        'content_pillars', 'posting_schedule', 'posts_per_week', 'platforms',
    ];

    protected $casts = [
        'content_pillars'  => 'array',
        'posting_schedule' => 'array',
        'platforms'        => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
