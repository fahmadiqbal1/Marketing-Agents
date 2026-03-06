<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-business, per-platform AI agent configuration and learning data.
 */
class PlatformAgent extends Model
{
    protected $fillable = [
        'business_id',
        'platform',
        'system_prompt_override',
        'agent_type',
        'learning_profile',
        'performance_stats',
        'rag_collection_id',
        'trained_from_repos',
        'learned_patterns',
        'injected_skills',
        'skill_version',
        'last_learned_at',
        'is_active',
        'config',
    ];

    protected $casts = [
        'learning_profile'   => 'array',
        'performance_stats'  => 'array',
        'trained_from_repos' => 'array',
        'learned_patterns'   => 'array',
        'injected_skills'    => 'array',
        'config'             => 'array',
        'last_learned_at'    => 'datetime',
        'is_active'          => 'boolean',
    ];

    // ── Agent Type Constants ──────────────────────────────────────

    const TYPE_SOCIAL = 'social';
    const TYPE_SEO    = 'seo';
    const TYPE_HR     = 'hr';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Get the best posting times from learning profile.
     */
    public function getBestTimes(): array
    {
        return $this->learning_profile['best_times'] ?? [];
    }

    /**
     * Get top performing hashtags from learning profile.
     */
    public function getTopHashtags(): array
    {
        return $this->learning_profile['top_hashtags'] ?? [];
    }

    /**
     * Get average engagement rate from performance stats.
     */
    public function getAvgEngagement(): float
    {
        return (float) ($this->performance_stats['avg_engagement'] ?? 0);
    }

    /**
     * Update learning profile with new data.
     */
    public function updateLearning(array $data): void
    {
        $profile = $this->learning_profile ?? [];
        $this->update([
            'learning_profile' => array_merge($profile, $data),
        ]);
    }

    /**
     * Return a flat list of skill titles injected from the Orchestrator.
     */
    public function getInjectedSkillTitles(): array
    {
        return array_column($this->injected_skills ?? [], 'title');
    }

    /**
     * Build a compact skill context string suitable for prepending to any
     * system prompt so the sub-agent is aware of its transferred capabilities.
     */
    public function buildSkillContext(): string
    {
        $skills = $this->injected_skills ?? [];
        if (empty($skills)) {
            return '';
        }

        $lines = [];
        foreach ($skills as $skill) {
            $lines[] = '- ' . ($skill['title'] ?? 'Skill') . ': ' . ($skill['description'] ?? '');
        }

        return "## Capabilities transferred by Orchestrator\n" . implode("\n", $lines) . "\n\n";
    }
}
