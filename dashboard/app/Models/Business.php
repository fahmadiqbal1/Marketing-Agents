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
        'name', 'slug', 'industry', 'website', 'phone', 'address',
        'timezone', 'brand_voice', 'logo_url', 'owner_id',
        'custom_categories', 'subscription_plan',
        'uses_platform_api_keys', 'credit_approved', 'is_active',
    ];

    protected $casts = [
        'uses_platform_api_keys' => 'boolean',
        'credit_approved'        => 'boolean',
        'is_active'              => 'boolean',
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

    public function mediaItems(): HasMany
    {
        return $this->hasMany(MediaItem::class);
    }

    public function promotionalPackages(): HasMany
    {
        return $this->hasMany(PromotionalPackage::class);
    }

    public function engagementEvents(): HasMany
    {
        return $this->hasMany(EngagementEvent::class);
    }

    public function contentCalendars(): HasMany
    {
        return $this->hasMany(ContentCalendar::class);
    }

    public function platformAgents(): HasMany
    {
        return $this->hasMany(PlatformAgent::class);
    }

    public function aiUsageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function billingRecords(): HasMany
    {
        return $this->hasMany(BillingRecord::class);
    }
}
