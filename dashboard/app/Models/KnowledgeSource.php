<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSource extends Model
{
    protected $fillable = [
        'business_id', 'source_type', 'title', 'content', 'url', 'file_path', 'status',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
