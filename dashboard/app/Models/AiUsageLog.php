<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Usage Log — tracks every AI API call for billing and analytics.
 *
 * @property int $id
 * @property int|null $business_id
 * @property int|null $user_id
 * @property string $agent_name
 * @property string $model
 * @property string $operation
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost_usd
 * @property \Carbon\Carbon $created_at
 */
class AiUsageLog extends Model
{
    use HasFactory;

    protected $table = 'ai_usage_logs';

    public $timestamps = false;

    protected $fillable = [
        'business_id',
        'user_id',
        'agent_name',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'cost_usd',
    ];

    protected $casts = [
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'cost_usd'      => 'float',
        'created_at'    => 'datetime',
    ];

    /**
     * Boot method to set created_at on create.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    /**
     * Business relationship.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * User relationship.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for filtering by business.
     */
    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * Scope for filtering by agent name.
     */
    public function scopeByAgent($query, string $agentName)
    {
        return $query->where('agent_name', $agentName);
    }

    /**
     * Scope for current month.
     */
    public function scopeCurrentMonth($query)
    {
        return $query->where('created_at', '>=', now()->startOfMonth());
    }

    /**
     * Get total tokens.
     */
    public function getTotalTokensAttribute(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }
}
