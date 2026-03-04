<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticMetric extends Model
{
    protected $table = 'analytics_metrics';

    protected $fillable = [
        'business_id', 'platform', 'metric_type', 'value', 'period_date', 'meta',
    ];

    protected $casts = [
        'period_date' => 'date',
        'value'       => 'float',
        'meta'        => 'array',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeForBusiness($query, $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeLastDays($query, int $days)
    {
        return $query->where('period_date', '>=', now()->subDays($days)->toDateString());
    }
}
