<?php

namespace App\Services;

use App\Models\Post;
use App\Models\AnalyticMetric;
use App\Models\ContentCalendar;
use Illuminate\Support\Facades\DB;

/**
 * Auto-Insights Generator — periodic analysis of engagement data.
 *
 * Converted from Python: services/insights_generator.py
 *
 * Generates actionable insights like:
 *   - Best performing platform / content category
 *   - Optimal posting times based on actual engagement
 *   - Content gap alerts
 *   - Growth trends (week-over-week)
 */
class InsightsGeneratorService
{
    protected int $businessId;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    /**
     * Generate a full insights report for a business.
     */
    public function generate(int $days = 7): array
    {
        $now = now();
        $start = $now->copy()->subDays($days);
        $prevStart = $start->copy()->subDays($days);

        $insights = [
            'period_days'           => $days,
            'generated_at'          => $now->toIso8601String(),
            'summary'               => [],
            'platform_performance'  => [],
            'content_performance'   => [],
            'best_times'            => [],
            'growth_trends'         => [],
            'recommendations'       => [],
        ];

        // ── Total posts this period ──────────────────────────────────
        $currentPeriod = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->where('published_at', '>=', $start)
            ->selectRaw('
                COUNT(*) as cnt,
                COALESCE(SUM(likes), 0) as total_likes,
                COALESCE(SUM(views), 0) as total_views,
                COALESCE(SUM(shares), 0) as total_shares,
                COALESCE(SUM(comments_count), 0) as total_comments
            ')
            ->first();

        $totalPosts = (int) $currentPeriod->cnt;
        $totalLikes = (int) $currentPeriod->total_likes;
        $totalViews = (int) $currentPeriod->total_views;
        $totalShares = (int) $currentPeriod->total_shares;
        $totalComments = (int) $currentPeriod->total_comments;

        // ── Previous period for comparison ───────────────────────────
        $previousPeriod = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->whereBetween('published_at', [$prevStart, $start])
            ->selectRaw('
                COUNT(*) as cnt,
                COALESCE(SUM(likes), 0) as total_likes,
                COALESCE(SUM(views), 0) as total_views
            ')
            ->first();

        $prevPosts = (int) $previousPeriod->cnt;
        $prevLikes = (int) $previousPeriod->total_likes;
        $prevViews = (int) $previousPeriod->total_views;

        // Engagement rate
        $engagementRate = $totalViews > 0
            ? round((($totalLikes + $totalComments + $totalShares) / $totalViews) * 100, 2)
            : 0;

        $insights['summary'] = [
            'total_posts'     => $totalPosts,
            'total_likes'     => $totalLikes,
            'total_views'     => $totalViews,
            'total_shares'    => $totalShares,
            'total_comments'  => $totalComments,
            'engagement_rate' => $engagementRate,
        ];

        $insights['growth_trends'] = [
            'posts_change' => $this->pctChange($totalPosts, $prevPosts),
            'likes_change' => $this->pctChange($totalLikes, $prevLikes),
            'views_change' => $this->pctChange($totalViews, $prevViews),
            'direction'    => $totalLikes > $prevLikes ? 'up' : ($totalLikes < $prevLikes ? 'down' : 'flat'),
        ];

        // ── Per-platform performance ─────────────────────────────────
        $platformStats = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->where('published_at', '>=', $start)
            ->selectRaw('
                platform,
                COUNT(*) as cnt,
                COALESCE(SUM(likes), 0) as lk,
                COALESCE(SUM(views), 0) as vw,
                COALESCE(SUM(shares), 0) as sh,
                COALESCE(SUM(comments_count), 0) as cm
            ')
            ->groupBy('platform')
            ->orderByDesc('lk')
            ->get();

        foreach ($platformStats as $row) {
            $views = max((int) $row->vw, 1);
            $engagement = ((int) $row->lk + (int) $row->cm + (int) $row->sh);

            $insights['platform_performance'][] = [
                'platform'        => $row->platform,
                'posts'           => (int) $row->cnt,
                'likes'           => (int) $row->lk,
                'views'           => (int) $row->vw,
                'shares'          => (int) $row->sh,
                'comments'        => (int) $row->cm,
                'engagement_rate' => round(($engagement / $views) * 100, 2),
            ];
        }

        // ── Best posting times ───────────────────────────────────────
        $bestTimes = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->where('published_at', '>=', $start)
            ->whereNotNull('published_at')
            ->selectRaw('
                HOUR(published_at) as hour,
                DAYOFWEEK(published_at) as dow,
                AVG(likes) as avg_likes,
                COUNT(*) as post_count
            ')
            ->groupBy('hour', 'dow')
            ->having('post_count', '>=', 2)
            ->orderByDesc('avg_likes')
            ->limit(5)
            ->get();

        $dayNames = ['', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        foreach ($bestTimes as $row) {
            $insights['best_times'][] = [
                'day'          => $dayNames[(int) $row->dow] ?? 'Unknown',
                'hour'         => sprintf('%02d:00', (int) $row->hour),
                'avg_likes'    => round($row->avg_likes, 1),
                'sample_size'  => (int) $row->post_count,
            ];
        }

        // ── Recommendations ──────────────────────────────────────────
        $insights['recommendations'] = $this->generateRecommendations($insights);

        return $insights;
    }

    /**
     * Generate actionable recommendations based on insights.
     */
    protected function generateRecommendations(array $insights): array
    {
        $recommendations = [];

        // Check growth trends
        if (($insights['growth_trends']['likes_change'] ?? 0) < -10) {
            $recommendations[] = [
                'type'     => 'warning',
                'category' => 'engagement',
                'message'  => 'Engagement is down — consider trying new content formats or posting times',
                'priority' => 'high',
            ];
        }

        // Check for inactive platforms
        $platforms = collect($insights['platform_performance']);
        if ($platforms->count() < 3) {
            $recommendations[] = [
                'type'     => 'opportunity',
                'category' => 'platforms',
                'message'  => 'You\'re only active on ' . $platforms->count() . ' platforms — consider expanding to reach more audiences',
                'priority' => 'medium',
            ];
        }

        // Best performing platform
        $topPlatform = $platforms->sortByDesc('engagement_rate')->first();
        if ($topPlatform) {
            $recommendations[] = [
                'type'     => 'insight',
                'category' => 'platforms',
                'message'  => ucfirst($topPlatform['platform']) . ' has your highest engagement rate (' . $topPlatform['engagement_rate'] . '%) — prioritize content here',
                'priority' => 'low',
            ];
        }

        // Posting frequency
        $totalPosts = $insights['summary']['total_posts'] ?? 0;
        $days = $insights['period_days'] ?? 7;
        $postsPerDay = $days > 0 ? $totalPosts / $days : 0;

        if ($postsPerDay < 1) {
            $recommendations[] = [
                'type'     => 'suggestion',
                'category' => 'frequency',
                'message'  => 'Consider posting more frequently — aim for at least 1 post per day',
                'priority' => 'medium',
            ];
        }

        // Best posting time
        if (!empty($insights['best_times'])) {
            $bestTime = $insights['best_times'][0];
            $recommendations[] = [
                'type'     => 'insight',
                'category' => 'timing',
                'message'  => "Your best performing time is {$bestTime['day']} at {$bestTime['hour']} — schedule important posts then",
                'priority' => 'low',
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate percentage change between two values.
     */
    protected function pctChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Get content gap analysis.
     */
    public function getContentGaps(int $days = 30): array
    {
        $categories = [
            'educational', 'promotional', 'behind_the_scenes', 'testimonial',
            'tips', 'product', 'team', 'announcement', 'entertainment',
        ];

        $posted = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->where('published_at', '>=', now()->subDays($days))
            ->pluck('content_category')
            ->filter()
            ->countBy()
            ->toArray();

        $gaps = [];
        foreach ($categories as $category) {
            $count = $posted[$category] ?? 0;
            if ($count < 2) {
                $gaps[] = [
                    'category' => $category,
                    'posts'    => $count,
                    'suggestion' => "Consider creating more {$category} content",
                ];
            }
        }

        return $gaps;
    }

    /**
     * Get competitor benchmark (placeholder — would need external data).
     */
    public function getIndustryBenchmark(): array
    {
        // This would typically come from an external API or database
        return [
            'avg_engagement_rate' => 3.5,
            'avg_posts_per_week'  => 7,
            'top_content_types'   => ['video', 'carousel', 'image'],
            'note' => 'Industry benchmarks are estimates based on general data',
        ];
    }
}
