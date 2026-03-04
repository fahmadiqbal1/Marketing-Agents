<?php

namespace App\Services;

use App\Models\Post;
use App\Models\ContentCalendar;
use App\Models\Business;

/**
 * Content Strategist Service — weekly planning, gap detection, priority scoring.
 *
 * Features:
 * - Plan weekly content based on pillar balance and buyer journey
 * - Detect content gaps (underserved platforms, missing categories)
 * - Score content priority using Searchable vs Shareable framework
 * - Track posting frequency per platform
 * - Generate weekly strategy briefs
 */
class ContentStrategistService
{
    private int $businessId;
    private ?OpenAIService $openai = null;

    // ═══════════════════════════════════════════════════════════════════════
    // WEEKLY CONTENT PLAN TEMPLATE
    // ═══════════════════════════════════════════════════════════════════════

    private const WEEKLY_PLAN_TEMPLATE = [
        [
            'day'       => 'Monday',
            'theme'     => 'Motivation Monday',
            'pillar'    => 'inspirational',
            'platforms' => ['instagram', 'linkedin'],
            'content'   => 'Start the week with an inspiring message or win',
        ],
        [
            'day'       => 'Tuesday',
            'theme'     => 'Tip Tuesday',
            'pillar'    => 'educational',
            'platforms' => ['instagram', 'tiktok', 'youtube'],
            'content'   => 'Quick actionable tip or how-to',
        ],
        [
            'day'       => 'Wednesday',
            'theme'     => 'Wisdom Wednesday',
            'pillar'    => 'educational',
            'platforms' => ['linkedin', 'twitter'],
            'content'   => 'Industry insight or thought leadership',
        ],
        [
            'day'       => 'Thursday',
            'theme'     => 'Throwback Thursday',
            'pillar'    => 'behind_scenes',
            'platforms' => ['instagram', 'facebook'],
            'content'   => 'Before/after, transformation, or journey post',
        ],
        [
            'day'       => 'Friday',
            'theme'     => 'Feature Friday',
            'pillar'    => 'promotional',
            'platforms' => ['instagram', 'facebook', 'tiktok'],
            'content'   => 'Highlight a service or team member',
        ],
        [
            'day'       => 'Saturday',
            'theme'     => 'Story Saturday',
            'pillar'    => 'personal',
            'platforms' => ['instagram', 'facebook'],
            'content'   => 'Customer story or testimonial',
        ],
        [
            'day'       => 'Sunday',
            'theme'     => 'Self-Care Sunday',
            'pillar'    => 'lifestyle',
            'platforms' => ['instagram', 'tiktok'],
            'content'   => 'Relaxed, lifestyle-focused content',
        ],
    ];

    // Buyer journey stage mapping
    private const BUYER_STAGE_MAP = [
        // Awareness stage
        'educational'   => 'awareness',
        'trending'      => 'awareness',
        'lifestyle'     => 'awareness',
        // Consideration stage
        'behind_scenes' => 'consideration',
        'testimonial'   => 'consideration',
        'comparison'    => 'consideration',
        // Decision stage
        'promotional'   => 'decision',
        'offer'         => 'decision',
        'cta'           => 'decision',
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WEEKLY PLANNING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get the weekly content plan template.
     */
    public function getWeeklyPlan(): array
    {
        return self::WEEKLY_PLAN_TEMPLATE;
    }

    /**
     * Get today's suggested content plan.
     */
    public function getTodayPlan(): ?array
    {
        $dayOfWeek = now()->format('l'); // Monday, Tuesday, etc.

        foreach (self::WEEKLY_PLAN_TEMPLATE as $plan) {
            if ($plan['day'] === $dayOfWeek) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Auto-fill content calendar with AI suggestions.
     */
    public function autoFillCalendar(int $daysAhead = 7, array $themes = []): array
    {
        $openai = $this->getOpenAI();
        $business = Business::find($this->businessId);

        if (!$openai || !$openai->isConfigured() || !$business) {
            return $this->stubCalendar($daysAhead);
        }

        $dates = [];
        for ($i = 0; $i < $daysAhead; $i++) {
            $dates[] = now()->addDays($i)->format('Y-m-d');
        }

        $prompt = "Generate a content calendar for a {$business->industry} business.\n\n"
                . "Dates: " . implode(', ', $dates) . "\n"
                . "Brand voice: {$business->brand_voice}\n"
                . ($themes ? "Themes to incorporate: " . implode(', ', $themes) . "\n" : "")
                . "\nFor each day, suggest:\n"
                . "- platform (instagram, facebook, linkedin, tiktok, youtube)\n"
                . "- content_type (photo, video, carousel, story, reel)\n"
                . "- topic (specific content idea)\n"
                . "- suggested_time (HH:MM 24h format)\n"
                . "- pillar (educational, promotional, behind_scenes, personal, trending)\n\n"
                . "Return JSON: {\"calendar\": [{\"date\": \"...\", \"platform\": \"...\", ...}]}";

        $result = $openai->chatCompletion($prompt, 'content_strategist', 'calendar');

        if ($result['success']) {
            $parsed = json_decode($result['content'], true);
            return $parsed['calendar'] ?? $this->stubCalendar($daysAhead);
        }

        return $this->stubCalendar($daysAhead);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GAP DETECTION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Detect content gaps in recent posts.
     */
    public function detectContentGaps(): array
    {
        $days = 14;
        $gaps = [
            'platforms'   => [],
            'categories'  => [],
            'suggestions' => [],
        ];

        // Platform frequency analysis
        $platformCounts = Post::forBusiness($this->businessId)
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('platform, COUNT(*) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();

        $allPlatforms = ['instagram', 'facebook', 'linkedin', 'tiktok', 'youtube', 'twitter'];
        foreach ($allPlatforms as $platform) {
            $count = $platformCounts[$platform] ?? 0;
            if ($count < 2) {
                $gaps['platforms'][] = [
                    'platform' => $platform,
                    'posts'    => $count,
                    'message'  => "Only {$count} posts on {$platform} in the last {$days} days",
                ];
            }
        }

        // Category analysis
        $categoryCounts = Post::forBusiness($this->businessId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('pillar')
            ->selectRaw('pillar, COUNT(*) as count')
            ->groupBy('pillar')
            ->pluck('count', 'pillar')
            ->toArray();

        $allCategories = ['educational', 'promotional', 'behind_scenes', 'testimonial', 'personal'];
        foreach ($allCategories as $category) {
            if (!isset($categoryCounts[$category])) {
                $gaps['categories'][] = $category;
                $gaps['suggestions'][] = "Create {$category} content to balance your mix";
            }
        }

        return $gaps;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONTENT PRIORITY SCORING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Score content priority based on multiple factors.
     */
    public function scoreContentPriority(
        string $category,
        string $platform,
        bool $isTrending = false,
        bool $isTimeSensitive = false,
        bool $isPeakTime = false
    ): array {
        $score = 50; // Base score
        $factors = [];

        // Category scores
        $categoryScores = [
            'promotional' => 20,
            'testimonial' => 15,
            'educational' => 10,
            'trending'    => 25,
        ];
        if (isset($categoryScores[$category])) {
            $score += $categoryScores[$category];
            $factors[] = "Category boost: +{$categoryScores[$category]}";
        }

        // Platform priority (based on typical ROI)
        $platformBoost = [
            'instagram' => 10,
            'tiktok'    => 15,
            'linkedin'  => 8,
            'youtube'   => 12,
        ];
        if (isset($platformBoost[$platform])) {
            $score += $platformBoost[$platform];
            $factors[] = "Platform boost: +{$platformBoost[$platform]}";
        }

        // Trending content bonus
        if ($isTrending) {
            $score += 20;
            $factors[] = 'Trending: +20';
        }

        // Time-sensitive bonus
        if ($isTimeSensitive) {
            $score += 15;
            $factors[] = 'Time-sensitive: +15';
        }

        // Peak time bonus
        if ($isPeakTime) {
            $score += 10;
            $factors[] = 'Peak posting time: +10';
        }

        // Check content gaps
        $gaps = $this->detectContentGaps();
        if (in_array($category, $gaps['categories'])) {
            $score += 15;
            $factors[] = 'Fills content gap: +15';
        }

        return [
            'score'        => min($score, 100),
            'priority'     => $score >= 80 ? 'high' : ($score >= 50 ? 'medium' : 'low'),
            'factors'      => $factors,
            'buyer_stage'  => self::BUYER_STAGE_MAP[$category] ?? 'awareness',
        ];
    }

    /**
     * Generate weekly strategy brief.
     */
    public function generateStrategyBrief(): array
    {
        $gaps = $this->detectContentGaps();
        $todayPlan = $this->getTodayPlan();
        $pillarBalance = $this->getPillarBalance();

        return [
            'today'            => $todayPlan,
            'content_gaps'     => $gaps,
            'pillar_balance'   => $pillarBalance,
            'recommendations'  => $this->generateRecommendations($gaps, $pillarBalance),
            'weekly_plan'      => self::WEEKLY_PLAN_TEMPLATE,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function getPillarBalance(): array
    {
        $counts = Post::forBusiness($this->businessId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('pillar')
            ->selectRaw('pillar, COUNT(*) as count')
            ->groupBy('pillar')
            ->pluck('count', 'pillar')
            ->toArray();

        $total = array_sum($counts) ?: 1;
        $balance = [];

        foreach ($counts as $pillar => $count) {
            $balance[$pillar] = round(($count / $total) * 100, 1);
        }

        return $balance;
    }

    private function generateRecommendations(array $gaps, array $pillarBalance): array
    {
        $recommendations = [];

        if (!empty($gaps['platforms'])) {
            $platforms = array_column($gaps['platforms'], 'platform');
            $recommendations[] = "Increase posting on: " . implode(', ', array_slice($platforms, 0, 3));
        }

        if (!empty($gaps['categories'])) {
            $recommendations[] = "Add more content for: " . implode(', ', array_slice($gaps['categories'], 0, 3));
        }

        if (($pillarBalance['promotional'] ?? 0) > 40) {
            $recommendations[] = "Reduce promotional content — aim for 25% max";
        }

        if (($pillarBalance['educational'] ?? 0) < 20) {
            $recommendations[] = "Add more educational content — aim for 30%";
        }

        if (empty($recommendations)) {
            $recommendations[] = "Great job! Content mix looks balanced.";
        }

        return $recommendations;
    }

    private function stubCalendar(int $days): array
    {
        $calendar = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->addDays($i);
            $dayPlan = self::WEEKLY_PLAN_TEMPLATE[$date->dayOfWeek] ?? self::WEEKLY_PLAN_TEMPLATE[0];
            $calendar[] = [
                'date'           => $date->format('Y-m-d'),
                'platform'       => $dayPlan['platforms'][0] ?? 'instagram',
                'content_type'   => 'photo',
                'topic'          => $dayPlan['content'],
                'suggested_time' => '10:00',
                'pillar'         => $dayPlan['pillar'],
            ];
        }
        return $calendar;
    }

    private function getOpenAI(): ?OpenAIService
    {
        if ($this->openai === null) {
            $this->openai = new OpenAIService($this->businessId);
        }
        return $this->openai;
    }
}
