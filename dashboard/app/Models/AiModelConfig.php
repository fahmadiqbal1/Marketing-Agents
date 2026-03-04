<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelConfig extends Model
{
    protected $fillable = [
        'business_id', 'provider', 'api_key', 'model_name', 'base_url', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    protected $hidden = ['api_key'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
