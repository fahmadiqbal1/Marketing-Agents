<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelConfig extends Model
{
    protected $fillable = [
        'business_id', 'provider', 'api_key', 'model_name', 'base_url', 'is_default', 'is_active',
        'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected $casts = [
        'is_default'     => 'boolean',
        'is_active'      => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['api_key'];

    /**
     * Return a masked version of the API key for display.
     */
    public function getMaskedKeyAttribute(): string
    {
        $key = $this->api_key ?? '';
        if (strlen($key) <= 8) {
            return str_repeat('•', max(strlen($key), 4));
        }
        return substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }

    /**
     * Computed status based on test results.
     */
    public function getStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->last_test_status === 'ok') {
            return 'active';
        }
        if ($this->last_test_status === 'error') {
            return 'error';
        }
        return 'configured';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
