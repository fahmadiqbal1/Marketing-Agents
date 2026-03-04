<?php

namespace App\Services;

use App\Models\MediaItem;
use App\Models\ContentCalendar;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * Content Memory Service — tracks media inventory, detects accumulation patterns,
 * and manages content similarity for collages and compilation videos.
 *
 * Converted from Python: memory/content_memory.py
 *
 * Key Features:
 * - Category-based content tracking
 * - Accumulation detection (triggers for collages/compilations)
 * - Content gap analysis for balanced posting
 * - Content calendar logging
 */
class ContentMemoryService
{
    /**
     * Default thresholds for content accumulation triggers.
     */
    protected const PHOTO_COLLAGE_THRESHOLD = 4;
    protected const VIDEO_COMPILATION_THRESHOLD = 3;

    protected int $businessId;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ─── Content Tracking ─────────────────────────────────────────────────────

    /**
     * Count unposted media items per category from the last N days.
     */
    public function getCategoryCounts(int $sinceDays = 30): array
    {
        $cutoff = now()->subDays($sinceDays);

        $results = MediaItem::where('business_id', $this->businessId)
            ->where('created_at', '>=', $cutoff)
            ->where('is_used_in_collage', false)
            ->where('is_used_in_compilation', false)
            ->select('content_category', DB::raw('COUNT(*) as count'))
            ->groupBy('content_category')
            ->get();

        $counts = [];
        foreach ($results as $row) {
            $category = $row->content_category ?? 'general';
            $counts[$category] = $row->count;
        }

        return $counts;
    }

    /**
     * Get unused photo items of a specific category for collage creation.
     */
    public function getItemsForCollage(string $category, int $limit = 4): array
    {
        return MediaItem::where('business_id', $this->businessId)
            ->where('content_category', $category)
            ->where('is_used_in_collage', false)
            ->where('media_type', 'photo')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get unused video items of a specific category for compilation creation.
     */
    public function getItemsForCompilation(string $category, int $limit = 5): array
    {
        return MediaItem::where('business_id', $this->businessId)
            ->where('content_category', $category)
            ->where('is_used_in_compilation', false)
            ->where('media_type', 'video')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Mark media items as used in a collage.
     */
    public function markUsedInCollage(array $mediaIds): int
    {
        return MediaItem::whereIn('id', $mediaIds)
            ->where('business_id', $this->businessId)
            ->update(['is_used_in_collage' => true]);
    }

    /**
     * Mark media items as used in a compilation.
     */
    public function markUsedInCompilation(array $mediaIds): int
    {
        return MediaItem::whereIn('id', $mediaIds)
            ->where('business_id', $this->businessId)
            ->update(['is_used_in_compilation' => true]);
    }

    // ─── Accumulation Detection ───────────────────────────────────────────────

    /**
     * Check if any content category has accumulated enough items
     * to propose a collage or compilation.
     *
     * @return array List of proposals with type, category, count, and media_ids
     */
    public function checkAccumulationTriggers(
        int $photoCollageThreshold = self::PHOTO_COLLAGE_THRESHOLD,
        int $videoCompilationThreshold = self::VIDEO_COMPILATION_THRESHOLD
    ): array {
        $proposals = [];
        $counts = $this->getCategoryCounts();

        foreach (array_keys($counts) as $category) {
            // Check photos for collage
            $photos = $this->getItemsForCollage($category, $photoCollageThreshold);
            if (count($photos) >= $photoCollageThreshold) {
                $proposals[] = [
                    'type' => 'collage',
                    'category' => $category,
                    'count' => count($photos),
                    'media_ids' => array_column($photos, 'id'),
                ];
            }

            // Check videos for compilation
            $videos = $this->getItemsForCompilation($category, $videoCompilationThreshold);
            if (count($videos) >= $videoCompilationThreshold) {
                $proposals[] = [
                    'type' => 'compilation',
                    'category' => $category,
                    'count' => count($videos),
                    'media_ids' => array_column($videos, 'id'),
                ];
            }
        }

        return $proposals;
    }

    // ─── Content Calendar Helpers ─────────────────────────────────────────────

    /**
     * Get categories posted in the last N days to avoid repetition.
     */
    public function getRecentPostCategories(int $days = 7): array
    {
        $cutoff = now()->subDays($days);

        return ContentCalendar::where('business_id', $this->businessId)
            ->where('posted_at', '>=', $cutoff)
            ->distinct()
            ->pluck('content_category')
            ->toArray();
    }

    /**
     * Identify which service categories haven't been posted about recently,
     * suggesting content gaps to fill.
     */
    public function getContentGapSuggestions(): array
    {
        $allCategories = [
            'product', 'service', 'behind_the_scenes', 'educational',
            'promotional', 'event', 'testimonial', 'team', 'facility',
            'before_after', 'lifestyle', 'news',
        ];

        $recent = $this->getRecentPostCategories(14);

        return array_values(array_diff($allCategories, $recent));
    }

    /**
     * Record a post in the content calendar for tracking.
     */
    public function logPostToCalendar(
        int $postId,
        string $platform,
        string $category
    ): ContentCalendar {
        return ContentCalendar::create([
            'business_id' => $this->businessId,
            'post_id' => $postId,
            'platform' => $platform,
            'content_category' => $category,
            'posted_at' => now(),
        ]);
    }

    /**
     * Get content calendar entries for a date range.
     */
    public function getCalendarEntries(
        ?Carbon $startDate = null,
        ?Carbon $endDate = null
    ): array {
        $start = $startDate ?? now()->startOfMonth();
        $end = $endDate ?? now()->endOfMonth();

        return ContentCalendar::where('business_id', $this->businessId)
            ->whereBetween('posted_at', [$start, $end])
            ->with('post')
            ->orderBy('posted_at')
            ->get()
            ->toArray();
    }

    // ─── Content Similarity (Simple Implementation) ──────────────────────────

    /**
     * Find potentially similar content based on category and keywords.
     * Note: For full semantic similarity, integrate with a vector database.
     */
    public function findSimilarContent(
        string $description,
        ?string $category = null,
        int $limit = 5
    ): array {
        $query = MediaItem::where('business_id', $this->businessId);

        if ($category) {
            $query->where('content_category', $category);
        }

        // Extract keywords from description
        $words = array_filter(
            preg_split('/\s+/', strtolower($description)),
            fn($w) => strlen($w) > 3
        );

        if (empty($words)) {
            return [];
        }

        // Search in analysis_json for keyword matches
        foreach (array_slice($words, 0, 5) as $word) {
            $query->orWhere('analysis_json', 'LIKE', '%' . $word . '%');
        }

        return $query->limit($limit)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Check if very similar content was already posted recently.
     * Basic implementation using category and keyword matching.
     */
    public function isDuplicateContent(
        string $description,
        ?string $category = null,
        float $similarityThreshold = 0.7
    ): bool {
        $similar = $this->findSimilarContent($description, $category, 1);

        if (empty($similar)) {
            return false;
        }

        // Basic word overlap check
        $descWords = array_flip(str_word_count(strtolower($description), 1));
        $existingWords = array_flip(str_word_count(strtolower($similar[0]['analysis_json'] ?? ''), 1));

        $overlap = count(array_intersect_key($descWords, $existingWords));
        $total = max(1, count($descWords));

        return ($overlap / $total) >= $similarityThreshold;
    }

    // ─── Statistics ──────────────────────────────────────────────────────────

    /**
     * Get content statistics for the business.
     */
    public function getContentStats(): array
    {
        $totalMedia = MediaItem::where('business_id', $this->businessId)->count();
        $unusedPhotos = MediaItem::where('business_id', $this->businessId)
            ->where('media_type', 'photo')
            ->where('is_used_in_collage', false)
            ->count();
        $unusedVideos = MediaItem::where('business_id', $this->businessId)
            ->where('media_type', 'video')
            ->where('is_used_in_compilation', false)
            ->count();
        $totalPosts = Post::where('business_id', $this->businessId)->count();
        $publishedPosts = Post::where('business_id', $this->businessId)
            ->where('status', 'published')
            ->count();

        $categoryCounts = $this->getCategoryCounts();
        $contentGaps = $this->getContentGapSuggestions();
        $accumulationTriggers = $this->checkAccumulationTriggers();

        return [
            'total_media_items' => $totalMedia,
            'unused_photos' => $unusedPhotos,
            'unused_videos' => $unusedVideos,
            'total_posts' => $totalPosts,
            'published_posts' => $publishedPosts,
            'category_distribution' => $categoryCounts,
            'content_gaps' => $contentGaps,
            'accumulation_proposals' => $accumulationTriggers,
        ];
    }
}
