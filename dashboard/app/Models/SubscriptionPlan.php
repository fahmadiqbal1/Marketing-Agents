<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Defines available subscription plans and their limits.
 */
class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'monthly_token_limit',
        'monthly_cost_usd',
        'max_platforms',
        'max_posts_per_month',
        'max_ai_calls_per_day',
        'features_json',
        'is_active',
    ];

    protected $casts = [
        'monthly_token_limit'  => 'integer',
        'monthly_cost_usd'     => 'float',
        'max_platforms'        => 'integer',
        'max_posts_per_month'  => 'integer',
        'max_ai_calls_per_day' => 'integer',
        'features_json'        => 'array',
        'is_active'            => 'boolean',
    ];

    // ── Plan Name Constants ───────────────────────────────────────

    const PLAN_FREE       = 'free';
    const PLAN_STARTER    = 'starter';
    const PLAN_PRO        = 'pro';
    const PLAN_ENTERPRISE = 'enterprise';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Check if a feature is included in this plan.
     */
    public function hasFeature(string $feature): bool
    {
        $features = $this->features_json ?? [];
        return in_array($feature, $features);
    }

    /**
     * Get the plan by name.
     */
    public static function byName(string $name): ?self
    {
        return self::where('name', $name)->first();
    }

    /**
     * Seed default plans.
     */
    public static function seedDefaults(): void
    {
        $plans = [
            [
                'name'                 => self::PLAN_FREE,
                'display_name'         => 'Free',
                'monthly_token_limit'  => 10000,
                'monthly_cost_usd'     => 0.00,
                'max_platforms'        => 2,
                'max_posts_per_month'  => 10,
                'max_ai_calls_per_day' => 20,
                'features_json'        => ['caption_generation', 'hashtag_research'],
            ],
            [
                'name'                 => self::PLAN_STARTER,
                'display_name'         => 'Starter',
                'monthly_token_limit'  => 100000,
                'monthly_cost_usd'     => 19.99,
                'max_platforms'        => 5,
                'max_posts_per_month'  => 50,
                'max_ai_calls_per_day' => 100,
                'features_json'        => ['caption_generation', 'hashtag_research', 'auto_engagement', 'growth_hacker'],
            ],
            [
                'name'                 => self::PLAN_PRO,
                'display_name'         => 'Professional',
                'monthly_token_limit'  => 500000,
                'monthly_cost_usd'     => 49.99,
                'max_platforms'        => 10,
                'max_posts_per_month'  => 200,
                'max_ai_calls_per_day' => 500,
                'features_json'        => ['caption_generation', 'hashtag_research', 'auto_engagement', 'growth_hacker', 'job_manager', 'content_recycler', 'package_brain'],
            ],
            [
                'name'                 => self::PLAN_ENTERPRISE,
                'display_name'         => 'Enterprise',
                'monthly_token_limit'  => 2000000,
                'monthly_cost_usd'     => 199.99,
                'max_platforms'        => -1, // unlimited
                'max_posts_per_month'  => -1, // unlimited
                'max_ai_calls_per_day' => -1, // unlimited
                'features_json'        => ['all'],
            ],
        ];

        foreach ($plans as $plan) {
            self::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }
    }
}
