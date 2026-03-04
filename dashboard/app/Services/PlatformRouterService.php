<?php

namespace App\Services;

/**
 * Platform Router Service — rule-based content routing, zero token cost.
 * Converted from Python: agents/platform_router.py
 *
 * Decides which platforms each piece of media should be posted to,
 * based on dimensions, duration, content category, and algorithm intelligence.
 *
 * Marketing Strategy Knowledge:
 * - Platform algorithm awareness (Reels 2x reach, LinkedIn first-hour, etc.)
 * - Buyer-stage routing (Awareness → Consideration → Decision)
 * - Content-type optimization (Searchable vs Shareable framework)
 */
class PlatformRouterService
{
    // ═══════════════════════════════════════════════════════════════════════
    // PLATFORM SPECIFICATIONS
    // ═══════════════════════════════════════════════════════════════════════

    private const PLATFORM_SPECS = [
        'instagram' => [
            'max_video_duration' => 90,      // Reels: 90s
            'max_image_size_mb'  => 8,
            'ideal_ratios'       => ['4:5', '1:1', '9:16'],
            'supports_carousel'  => true,
            'supports_stories'   => true,
            'supports_reels'     => true,
            'vertical_preferred' => true,
        ],
        'facebook' => [
            'max_video_duration' => 240,     // 4 minutes for feed
            'max_image_size_mb'  => 8,
            'ideal_ratios'       => ['1.91:1', '1:1', '4:5'],
            'supports_carousel'  => true,
            'supports_stories'   => true,
            'supports_reels'     => true,
            'vertical_preferred' => false,
        ],
        'youtube' => [
            'max_video_duration' => 43200,   // 12 hours
            'max_image_size_mb'  => 2,       // Thumbnail
            'ideal_ratios'       => ['16:9', '9:16'],
            'supports_shorts'    => true,
            'shorts_max_duration'=> 60,
            'vertical_preferred' => false,   // Except Shorts
        ],
        'linkedin' => [
            'max_video_duration' => 600,     // 10 minutes
            'max_image_size_mb'  => 5,
            'ideal_ratios'       => ['1.91:1', '1:1'],
            'supports_carousel'  => true,
            'vertical_preferred' => false,
        ],
        'tiktok' => [
            'max_video_duration' => 180,     // 3 minutes
            'max_image_size_mb'  => 5,
            'ideal_ratios'       => ['9:16'],
            'supports_carousel'  => false,
            'vertical_required'  => true,
        ],
        'twitter' => [
            'max_video_duration' => 140,
            'max_image_size_mb'  => 5,
            'ideal_ratios'       => ['16:9', '1:1'],
            'supports_carousel'  => true,
            'vertical_preferred' => false,
        ],
        'pinterest' => [
            'max_video_duration' => 60,
            'max_image_size_mb'  => 10,
            'ideal_ratios'       => ['2:3', '1:1'],
            'vertical_preferred' => true,
        ],
        'snapchat' => [
            'max_video_duration' => 60,
            'max_image_size_mb'  => 5,
            'ideal_ratios'       => ['9:16'],
            'vertical_required'  => true,
        ],
    ];

    // Content category → platform affinity (0-10 scale)
    private const CATEGORY_AFFINITY = [
        'educational' => [
            'youtube'   => 9,
            'linkedin'  => 8,
            'instagram' => 6,
            'tiktok'    => 7,
            'facebook'  => 5,
            'twitter'   => 5,
        ],
        'promotional' => [
            'instagram' => 9,
            'facebook'  => 8,
            'tiktok'    => 7,
            'linkedin'  => 5,
            'youtube'   => 6,
        ],
        'behind_scenes' => [
            'instagram' => 9,
            'tiktok'    => 9,
            'facebook'  => 7,
            'youtube'   => 6,
            'linkedin'  => 4,
        ],
        'testimonial' => [
            'instagram' => 8,
            'facebook'  => 8,
            'linkedin'  => 7,
            'youtube'   => 7,
        ],
        'product' => [
            'instagram' => 9,
            'pinterest' => 8,
            'tiktok'    => 7,
            'facebook'  => 6,
        ],
        'job_posting' => [
            'linkedin'  => 10,
            'twitter'   => 6,
            'facebook'  => 5,
            'instagram' => 4,
        ],
        'event' => [
            'instagram' => 8,
            'facebook'  => 9,
            'linkedin'  => 6,
            'twitter'   => 7,
        ],
    ];

    // Buyer journey stage → platform boost
    private const STAGE_PLATFORM_BOOST = [
        'awareness' => [
            'tiktok'    => 2,
            'instagram' => 1.5,
            'youtube'   => 1.5,
        ],
        'consideration' => [
            'youtube'   => 2,
            'linkedin'  => 1.5,
            'facebook'  => 1,
        ],
        'decision' => [
            'instagram' => 2,
            'facebook'  => 1.5,
            'linkedin'  => 1,
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // ROUTING METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Route media to suitable platforms (simplified interface for WorkflowService).
     *
     * @param string $mediaType 'photo' or 'video'
     * @param int|null $width Media width
     * @param int|null $height Media height
     * @param float|null $durationSeconds Video duration
     * @param array|null $analysis Vision analysis result
     * @return array List of platform names suitable for this content
     */
    public function route(
        string $mediaType,
        ?int $width = null,
        ?int $height = null,
        ?float $durationSeconds = null,
        ?array $analysis = null
    ): array {
        $contentCategory = $analysis['content_category'] ?? 'general';

        $results = $this->routeMedia(
            $mediaType,
            $width,
            $height,
            $durationSeconds,
            $contentCategory
        );

        // Return just the platform names
        return array_map(fn($r) => $r['platform'], $results);
    }

    /**
     * Route media to suitable platforms based on characteristics.
     */
    public function routeMedia(
        string $mediaType,
        ?int $width = null,
        ?int $height = null,
        ?float $durationSeconds = null,
        ?string $contentCategory = null,
        array $recentlyPostedPlatforms = [],
        float $minAffinity = 5.0
    ): array {
        $suitable = [];

        foreach (self::PLATFORM_SPECS as $platform => $spec) {
            $score = $this->calculatePlatformScore(
                $platform,
                $spec,
                $mediaType,
                $width,
                $height,
                $durationSeconds,
                $contentCategory,
                $recentlyPostedPlatforms
            );

            if ($score >= $minAffinity) {
                $suitable[] = [
                    'platform' => $platform,
                    'score'    => round($score, 1),
                    'reason'   => $this->getRoutingReason($platform, $mediaType, $contentCategory),
                ];
            }
        }

        // Sort by score descending
        usort($suitable, fn($a, $b) => $b['score'] <=> $a['score']);

        return $suitable;
    }

    /**
     * Get platform specification.
     */
    public function getPlatformSpec(string $platform): array
    {
        return self::PLATFORM_SPECS[$platform] ?? [];
    }

    /**
     * Get algorithm tips for a platform.
     */
    public function getAlgorithmTips(string $platform): array
    {
        $tips = [
            'instagram' => [
                'boost'  => ['Reels get 2-3x more reach', 'Posting in Stories drives saves'],
                'avoid'  => ['External links in captions kill reach'],
                'timing' => 'First 30 minutes of engagement matter most',
            ],
            'tiktok' => [
                'boost'  => ['Trending sounds boost reach 30-50%', 'Hook in first 1 second'],
                'avoid'  => ['Horizontal video gets buried'],
                'timing' => 'Consistent posting (1-3x daily) trains the algorithm',
            ],
            'linkedin' => [
                'boost'  => ['Document posts get 3x engagement', 'First-hour engagement is critical'],
                'avoid'  => ['Links in post body reduce reach'],
                'timing' => 'Post Tuesday-Thursday, 8-10am local time',
            ],
            'youtube' => [
                'boost'  => ['First 48 hours determine video success', 'Shorts have separate discovery'],
                'avoid'  => ['Clickbait titles hurt retention'],
                'timing' => 'Upload consistently at the same time',
            ],
            'facebook' => [
                'boost'  => ['Native video gets 10x reach vs links', 'Groups content gets 3x reach'],
                'avoid'  => ['Over-posting (more than 1-2x daily)'],
                'timing' => 'Noon-1pm and 7-9pm are peak',
            ],
            'twitter' => [
                'boost'  => ['Images increase engagement 150%', 'Threads for longer content'],
                'avoid'  => ['More than 2 hashtags looks spammy'],
                'timing' => '8-9am (news check) and 12-1pm (lunch)',
            ],
        ];

        return $tips[$platform] ?? [
            'boost'  => ['Consistent posting helps'],
            'avoid'  => ['Low quality content'],
            'timing' => 'Test different times for your audience',
        ];
    }

    /**
     * Get buyer journey stage for content category.
     */
    public function getBuyerStage(string $contentCategory): string
    {
        $stageMap = [
            'educational'   => 'awareness',
            'trending'      => 'awareness',
            'behind_scenes' => 'consideration',
            'testimonial'   => 'consideration',
            'promotional'   => 'decision',
            'offer'         => 'decision',
        ];

        return $stageMap[$contentCategory] ?? 'awareness';
    }

    /**
     * Get routing context with buyer stage and tips.
     */
    public function getRoutingContext(string $contentCategory, array $platforms): array
    {
        $stage = $this->getBuyerStage($contentCategory);

        $platformTips = [];
        foreach ($platforms as $platform) {
            $platformTips[$platform] = $this->getAlgorithmTips($platform);
        }

        return [
            'buyer_stage'    => $stage,
            'stage_focus'    => match($stage) {
                'awareness'     => 'Maximize reach — focus on discovery platforms',
                'consideration' => 'Build trust — share detailed, educational content',
                'decision'      => 'Drive action — strong CTAs, social proof',
                default         => 'Engage your audience authentically',
            },
            'platform_tips'  => $platformTips,
            'boosts'         => self::STAGE_PLATFORM_BOOST[$stage] ?? [],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function calculatePlatformScore(
        string $platform,
        array $spec,
        string $mediaType,
        ?int $width,
        ?int $height,
        ?float $duration,
        ?string $category,
        array $recentlyPosted
    ): float {
        $score = 5.0; // Base score

        // Video duration check
        if ($mediaType === 'video' && $duration !== null) {
            $maxDuration = $spec['max_video_duration'] ?? 60;
            if ($duration > $maxDuration) {
                $score -= 3; // Too long for platform
            } elseif ($duration <= 60 && ($spec['supports_reels'] ?? false || $spec['supports_shorts'] ?? false)) {
                $score += 2; // Good for short-form
            }
        }

        // Aspect ratio check
        if ($width && $height) {
            $isVertical = $height > $width;

            if ($spec['vertical_required'] ?? false) {
                $score += $isVertical ? 2 : -3;
            } elseif ($spec['vertical_preferred'] ?? false) {
                $score += $isVertical ? 1 : 0;
            }
        }

        // Category affinity
        if ($category && isset(self::CATEGORY_AFFINITY[$category][$platform])) {
            $affinity = self::CATEGORY_AFFINITY[$category][$platform];
            $score = ($score + $affinity) / 2; // Average with affinity
        }

        // Reduce score if recently posted to this platform
        if (in_array($platform, $recentlyPosted)) {
            $score -= 1;
        }

        return max(0, min(10, $score));
    }

    private function getRoutingReason(string $platform, string $mediaType, ?string $category): string
    {
        $reasons = [];

        if ($mediaType === 'video') {
            if (in_array($platform, ['tiktok', 'instagram'])) {
                $reasons[] = 'Great for short-form video';
            } elseif ($platform === 'youtube') {
                $reasons[] = 'Best for long-form video content';
            }
        }

        if ($category === 'educational' && $platform === 'linkedin') {
            $reasons[] = 'Professional audience values educational content';
        }

        if ($category === 'behind_scenes' && in_array($platform, ['instagram', 'tiktok'])) {
            $reasons[] = 'Audiences love authentic BTS content here';
        }

        return implode('. ', $reasons) ?: 'Good fit for this content type';
    }
}
