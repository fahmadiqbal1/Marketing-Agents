<?php

namespace App\Services;

use App\Models\Post;
use App\Models\ContentCalendar;

/**
 * Content Recycler Service — get 6x more out of every piece of content.
 *
 * Features:
 * - Find top-performing content for repurposing
 * - Transform content into new formats for different platforms
 * - Schedule reposts at staggered times
 * - Create variations (different hooks, CTAs, captions)
 * - Track content pillar allocation (30/25/25/15/5 rule)
 * - Generate strategic repurposing chains
 */
class ContentRecyclerService
{
    private int $businessId;
    private ?OpenAIService $openai = null;

    // ═══════════════════════════════════════════════════════════════════════
    // CONTENT REPURPOSING MATRIX
    // ═══════════════════════════════════════════════════════════════════════

    private const REPURPOSE_MAP = [
        'blog_post' => [
            ['target' => 'twitter_thread', 'steps' => 'Extract 5-7 key points → Create thread with hook'],
            ['target' => 'linkedin_carousel', 'steps' => 'Design 8-10 slides with main insights'],
            ['target' => 'instagram_carousel', 'steps' => 'Visualize main points with graphics'],
            ['target' => 'youtube_short', 'steps' => 'Record 60-sec summary of key takeaway'],
            ['target' => 'email_newsletter', 'steps' => 'Adapt to email format with CTA'],
        ],
        'video' => [
            ['target' => 'youtube_short', 'steps' => 'Extract best 60 seconds'],
            ['target' => 'instagram_reel', 'steps' => 'Edit to 30-sec highlight'],
            ['target' => 'tiktok', 'steps' => 'Add trending sound, vertical crop'],
            ['target' => 'blog_post', 'steps' => 'Transcribe and expand as article'],
            ['target' => 'quote_graphic', 'steps' => 'Pull best quote, create graphic'],
        ],
        'podcast' => [
            ['target' => 'blog_post', 'steps' => 'Transcribe and edit into article'],
            ['target' => 'audiogram', 'steps' => 'Create waveform video with captions'],
            ['target' => 'quote_cards', 'steps' => 'Extract 3-5 quotable moments'],
            ['target' => 'twitter_thread', 'steps' => 'Summarize key takeaways'],
        ],
        'instagram_post' => [
            ['target' => 'facebook_post', 'steps' => 'Adapt caption for FB audience'],
            ['target' => 'linkedin_post', 'steps' => 'Professionalize tone'],
            ['target' => 'pinterest_pin', 'steps' => 'Resize to 2:3, add text overlay'],
            ['target' => 'story', 'steps' => 'Resize to 9:16, add stickers'],
        ],
    ];

    // Content Pillar Framework: ideal ratios
    private const CONTENT_PILLARS = [
        'educational'   => ['target' => 30, 'desc' => 'Tips, how-tos, tutorials'],
        'behind_scenes' => ['target' => 25, 'desc' => 'BTS, team stories, process'],
        'promotional'   => ['target' => 25, 'desc' => 'Services, offers, testimonials'],
        'personal'      => ['target' => 15, 'desc' => 'Founder stories, values'],
        'trending'      => ['target' => 5, 'desc' => 'Memes, trends, challenges'],
    ];

    // Evergreen content that can be recycled monthly
    private const EVERGREEN_TEMPLATES = [
        [
            'title'      => 'Our Story',
            'category'   => 'personal',
            'platforms'  => ['instagram', 'facebook', 'linkedin'],
            'recycle_days' => 90,
        ],
        [
            'title'      => 'Top 5 Tips',
            'category'   => 'educational',
            'platforms'  => ['instagram', 'tiktok', 'youtube'],
            'recycle_days' => 30,
        ],
        [
            'title'      => 'Customer Success Story',
            'category'   => 'testimonial',
            'platforms'  => ['instagram', 'facebook', 'linkedin'],
            'recycle_days' => 45,
        ],
        [
            'title'      => 'FAQ Answers',
            'category'   => 'educational',
            'platforms'  => ['instagram', 'tiktok'],
            'recycle_days' => 60,
        ],
        [
            'title'      => 'Behind the Scenes',
            'category'   => 'behind_scenes',
            'platforms'  => ['instagram', 'tiktok', 'facebook'],
            'recycle_days' => 14,
        ],
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // REPURPOSING METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get repurposing plan for a source format.
     */
    public function getRepurposePlan(string $sourceFormat): array
    {
        return self::REPURPOSE_MAP[$sourceFormat] ?? [];
    }

    /**
     * Create content variation using AI.
     */
    public function createContentVariation(
        string $originalCaption,
        string $originalPlatform,
        string $targetPlatform
    ): array {
        $openai = $this->getOpenAI();

        if (!$openai || !$openai->isConfigured()) {
            return $this->stubVariation($originalCaption, $targetPlatform);
        }

        $prompt = "Adapt this {$originalPlatform} caption for {$targetPlatform}:\n\n"
                . "Original: {$originalCaption}\n\n"
                . "Rules:\n"
                . "- Maintain core message\n"
                . "- Adjust tone for {$targetPlatform} audience\n"
                . "- Optimize length for platform\n"
                . "- Include appropriate CTA\n\n"
                . "Return JSON: {\"caption\": \"...\", \"hashtags\": [...]}";

        $result = $openai->chatCompletion($prompt, 'content_recycler', 'variation');

        return $result['success']
            ? ['success' => true, 'variation' => json_decode($result['content'], true)]
            : ['success' => false, 'error' => $result['error'] ?? 'Failed to create variation'];
    }

    /**
     * Get top performing posts for recycling.
     */
    public function getTopPerformingPosts(int $days = 14, int $limit = 5): array
    {
        return Post::forBusiness($this->businessId)
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn($post) => [
                'id'       => $post->id,
                'platform' => $post->platform,
                'caption'  => $post->caption,
                'pillar'   => $post->pillar,
            ])
            ->toArray();
    }

    /**
     * Get evergreen content due for reposting.
     */
    public function getDueEvergreenContent(): array
    {
        $due = [];
        $recentCategories = ContentCalendar::getRecentCategories($this->businessId, 30);

        foreach (self::EVERGREEN_TEMPLATES as $template) {
            // Check if this category was posted recently
            if (!in_array($template['category'], $recentCategories)) {
                $due[] = $template;
            }
        }

        return $due;
    }

    /**
     * Create cross-platform post schedule.
     */
    public function createCrossPostSchedule(string $originalPlatform, \DateTime $originalTime): array
    {
        $schedule = [];
        $delays = [
            'instagram' => 0,
            'facebook'  => 30,      // 30 min later
            'linkedin'  => 120,     // 2 hours later
            'twitter'   => 60,      // 1 hour later
            'tiktok'    => 240,     // 4 hours later
        ];

        foreach ($delays as $platform => $delayMinutes) {
            if ($platform === $originalPlatform) {
                continue;
            }

            $postTime = (clone $originalTime)->modify("+{$delayMinutes} minutes");
            $schedule[] = [
                'platform'       => $platform,
                'scheduled_at'   => $postTime->format('Y-m-d H:i:s'),
                'delay_minutes'  => $delayMinutes,
            ];
        }

        return $schedule;
    }

    /**
     * Analyze content pillar balance.
     */
    public function analyzePillarBalance(): array
    {
        $counts = Post::forBusiness($this->businessId)
            ->whereNotNull('pillar')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('pillar, COUNT(*) as count')
            ->groupBy('pillar')
            ->pluck('count', 'pillar')
            ->toArray();

        $total = array_sum($counts) ?: 1;
        $analysis = [];
        $suggestions = [];

        foreach (self::CONTENT_PILLARS as $pillar => $config) {
            $actual = round((($counts[$pillar] ?? 0) / $total) * 100, 1);
            $target = $config['target'];
            $diff = $actual - $target;

            $analysis[$pillar] = [
                'actual_pct' => $actual,
                'target_pct' => $target,
                'diff'       => $diff,
                'status'     => $diff < -10 ? 'under' : ($diff > 10 ? 'over' : 'balanced'),
            ];

            if ($diff < -10) {
                $suggestions[] = "Post more {$config['desc']} content ({$pillar})";
            }
        }

        return [
            'pillars'     => $analysis,
            'suggestions' => $suggestions,
            'total_posts' => array_sum($counts),
        ];
    }

    /**
     * Suggest next episode in a content series.
     */
    public function suggestNextInSeries(string $seriesName, int $episodeCount): array
    {
        $seriesTemplates = [
            'transformation_tuesday' => [
                'topics' => [
                    'Before/after results',
                    'Patient journey highlight',
                    'Process explanation',
                    'Team member spotlight',
                ],
            ],
            'wellness_wednesday' => [
                'topics' => [
                    'Health tip of the week',
                    'Preventive care advice',
                    'Lifestyle recommendations',
                    'Seasonal health tips',
                ],
            ],
            'friday_facts' => [
                'topics' => [
                    'Industry myth busting',
                    'Little-known facts',
                    'Research highlights',
                    'Quick tips compilation',
                ],
            ],
        ];

        $key = strtolower(str_replace(' ', '_', $seriesName));
        $template = $seriesTemplates[$key] ?? null;

        if (!$template) {
            return [
                'series'          => $seriesName,
                'episode'         => $episodeCount + 1,
                'suggested_topic' => 'Continue the series with fresh content',
            ];
        }

        $topicIndex = $episodeCount % count($template['topics']);

        return [
            'series'          => $seriesName,
            'episode'         => $episodeCount + 1,
            'suggested_topic' => $template['topics'][$topicIndex],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function getOpenAI(): ?OpenAIService
    {
        if ($this->openai === null) {
            $this->openai = new OpenAIService($this->businessId);
        }
        return $this->openai;
    }

    private function stubVariation(string $original, string $platform): array
    {
        $shortened = substr($original, 0, 200);
        return [
            'success'   => true,
            'variation' => [
                'caption'  => "[{$platform}] {$shortened}...",
                'hashtags' => ['marketing', 'content', 'repurposed'],
                'note'     => 'AI model not configured — this is a placeholder',
            ],
        ];
    }
}
