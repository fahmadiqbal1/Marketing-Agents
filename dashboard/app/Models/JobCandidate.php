<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCandidate extends Model
{
    protected $fillable = [
        'job_listing_id', 'name', 'email', 'phone',
        'resume_url', 'cover_letter', 'status', 'notes',
    ];

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'new'        => 'bg-info text-dark',
            'reviewing'  => 'bg-primary',
            'interview'  => 'bg-warning text-dark',
            'offer'      => 'bg-success',
            'hired'      => 'bg-success',
            'rejected'   => 'bg-danger',
            default      => 'bg-secondary',
        };
    }
}
