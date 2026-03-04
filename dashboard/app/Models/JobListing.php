<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobListing extends Model
{
    protected $fillable = [
        'business_id', 'title', 'description', 'requirements',
        'department', 'location', 'type', 'status', 'salary_min', 'salary_max',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(JobCandidate::class);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'open'   => 'bg-success',
            'closed' => 'bg-secondary',
            'draft'  => 'bg-warning text-dark',
            default  => 'bg-secondary',
        };
    }
}
