<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotPersonality extends Model
{
    protected $fillable = [
        'business_id', 'name', 'tone', 'language', 'personality', 'system_prompt', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
