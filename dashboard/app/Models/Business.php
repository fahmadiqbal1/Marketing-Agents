<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'industry', 'website', 'phone', 'address',
        'timezone', 'brand_voice', 'logo_url', 'owner_id',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function socialPlatforms(): HasMany
    {
        return $this->hasMany(SocialPlatform::class);
    }

    public function analyticsMetrics(): HasMany
    {
        return $this->hasMany(AnalyticMetric::class);
    }

    public function jobListings(): HasMany
    {
        return $this->hasMany(JobListing::class);
    }

    public function aiModelConfigs(): HasMany
    {
        return $this->hasMany(AiModelConfig::class);
    }

    public function botPersonalities(): HasMany
    {
        return $this->hasMany(BotPersonality::class);
    }

    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function contentStrategy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ContentStrategy::class);
    }

    public function telegramBots(): HasMany
    {
        return $this->hasMany(TelegramBot::class);
    }
}
