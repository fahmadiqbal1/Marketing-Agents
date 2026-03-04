<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotTrainingPair extends Model
{
    protected $fillable = ['business_id', 'question', 'answer', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
