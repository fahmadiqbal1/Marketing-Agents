<?php

namespace App\Services;

use App\Models\MusicTrack;

/**
 * Music Researcher Service — discovers background music for short-form video.
 * Converted from Python: agents/music_researcher.py
 *
 * Features:
 * - Curated royalty-free library tagged by mood/genre
 * - Mood → content category matching (zero tokens)
 * - Platform-specific music recommendations
 * - Trending music discovery
 */
class MusicResearcherService
{
    private int $businessId;

    // ═══════════════════════════════════════════════════════════════════════
    // MOOD ↔ CONTENT-CATEGORY MAPPING (zero tokens)
    // ═══════════════════════════════════════════════════════════════════════

    private const CATEGORY_MOOD_MAP = [
        // Healthcare/wellness
        'treatment'      => ['calm', 'hopeful', 'uplifting'],
        'result'         => ['triumphant', 'uplifting', 'inspiring'],
        'facility'       => ['professional', 'calm', 'modern'],
        'team'           => ['friendly', 'upbeat', 'warm'],
        'testimonial'    => ['emotional', 'inspiring', 'warm'],
        // General marketing
        'educational'    => ['calm', 'professional', 'focused'],
        'promotional'    => ['upbeat', 'energetic', 'exciting'],
        'behind_scenes'  => ['casual', 'friendly', 'fun'],
        'product'        => ['modern', 'upbeat', 'sleek'],
        'event'          => ['energetic', 'exciting', 'festive'],
    ];

    // Platform-specific preferences
    private const PLATFORM_PREFERENCES = [
        'tiktok' => [
            'max_duration' => 60,
            'prefer_trending' => true,
            'genres' => ['pop', 'hip-hop', 'electronic', 'viral'],
        ],
        'instagram' => [
            'max_duration' => 90,
            'prefer_trending' => true,
            'genres' => ['pop', 'indie', 'electronic', 'chill'],
        ],
        'youtube' => [
            'max_duration' => 0, // No limit
            'prefer_trending' => false,
            'genres' => ['cinematic', 'ambient', 'corporate', 'uplifting'],
            'must_be_royalty_free' => true, // For monetization
        ],
        'facebook' => [
            'max_duration' => 120,
            'prefer_trending' => false,
            'genres' => ['pop', 'acoustic', 'indie', 'chill'],
        ],
    ];

    // Seed music library (royalty-free tracks)
    private const SEED_MUSIC = [
        [
            'title'           => 'Corporate Uplifting',
            'artist'          => 'Royalty Free Music',
            'mood'            => 'uplifting',
            'genre'           => 'corporate',
            'categories'      => 'promotional,business,corporate',
            'duration_seconds' => 120,
            'is_royalty_free' => true,
            'note'            => 'Great for company videos',
        ],
        [
            'title'           => 'Calm Piano',
            'artist'          => 'Relaxation Sounds',
            'mood'            => 'calm',
            'genre'           => 'ambient',
            'categories'      => 'wellness,treatment,relaxation',
            'duration_seconds' => 180,
            'is_royalty_free' => true,
            'note'            => 'Perfect for spa/wellness content',
        ],
        [
            'title'           => 'Energetic Pop Beat',
            'artist'          => 'Beat Makers',
            'mood'            => 'energetic',
            'genre'           => 'pop',
            'categories'      => 'promotional,event,product',
            'duration_seconds' => 90,
            'is_royalty_free' => true,
            'note'            => 'High energy for promotions',
        ],
        [
            'title'           => 'Inspiring Acoustic',
            'artist'          => 'Strings & Things',
            'mood'            => 'inspiring',
            'genre'           => 'acoustic',
            'categories'      => 'testimonial,story,behind_scenes',
            'duration_seconds' => 150,
            'is_royalty_free' => true,
            'note'            => 'Emotional storytelling',
        ],
        [
            'title'           => 'Modern Tech',
            'artist'          => 'Digital Beats',
            'mood'            => 'modern',
            'genre'           => 'electronic',
            'categories'      => 'product,technology,innovation',
            'duration_seconds' => 100,
            'is_royalty_free' => true,
            'note'            => 'Tech product showcases',
        ],
        [
            'title'           => 'Happy Ukulele',
            'artist'          => 'Sunny Tunes',
            'mood'            => 'friendly',
            'genre'           => 'acoustic',
            'categories'      => 'team,behind_scenes,casual',
            'duration_seconds' => 120,
            'is_royalty_free' => true,
            'note'            => 'Fun, friendly content',
        ],
    ];

    public function __construct(int $businessId = 0)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MUSIC RECOMMENDATIONS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Recommend music for content (alias for WorkflowService compatibility).
     *
     * @param string $contentCategory Content category
     * @param string $description Content description
     * @param string $mood Desired mood
     * @param string $platform Target platform
     * @return array|null Music recommendation with 'path', 'title', etc.
     */
    public function recommendForContent(
        string $contentCategory,
        string $description,
        string $mood,
        string $platform
    ): ?array {
        $result = $this->recommendMusicForContent($contentCategory, $platform, $mood, 1);

        $tracks = $result['tracks'] ?? [];
        if (empty($tracks)) {
            return null;
        }

        $track = is_array($tracks[0]) ? $tracks[0] : $tracks[0]->toArray();

        // Return in format expected by WorkflowService
        return [
            'title' => $track['title'] ?? 'Unknown',
            'artist' => $track['artist'] ?? 'Unknown',
            'path' => $track['file_path'] ?? null,
            'mood' => $track['mood'] ?? $mood,
            'genre' => $track['genre'] ?? 'unknown',
            'duration_seconds' => $track['duration_seconds'] ?? 120,
        ];
    }

    /**
     * Get music recommendations for content.
     */
    public function recommendMusicForContent(
        string $contentCategory,
        string $platform,
        ?string $mood = null,
        int $maxCount = 5
    ): array {
        // Determine mood from category if not provided
        $moods = $mood
            ? [$mood]
            : (self::CATEGORY_MOOD_MAP[$contentCategory] ?? ['uplifting']);

        $platformPrefs = self::PLATFORM_PREFERENCES[$platform] ?? [];

        // Build query
        $query = MusicTrack::query();

        // Filter by mood
        $query->where(function ($q) use ($moods) {
            foreach ($moods as $m) {
                $q->orWhere('mood', $m);
            }
        });

        // Platform-specific filters
        if (!empty($platformPrefs['must_be_royalty_free'])) {
            $query->where('is_royalty_free', true);
        }

        if (!empty($platformPrefs['max_duration'])) {
            $query->where('duration_seconds', '<=', $platformPrefs['max_duration']);
        }

        if (!empty($platformPrefs['prefer_trending'])) {
            $query->orderByDesc('is_trending')->orderByDesc('trending_score');
        }

        // Get results
        $tracks = $query->limit($maxCount)->get();

        // If no results from DB, use seed data
        if ($tracks->isEmpty()) {
            $tracks = $this->getSeedTracksForMood($moods, $maxCount);
        }

        return [
            'category'        => $contentCategory,
            'platform'        => $platform,
            'suggested_moods' => $moods,
            'tracks'          => $tracks->toArray(),
            'count'           => count($tracks),
        ];
    }

    /**
     * Get trending music for a platform.
     */
    public function getTrendingMusic(string $platform, int $count = 10): array
    {
        $tracks = MusicTrack::forPlatform($platform)
            ->trending()
            ->limit($count)
            ->get();

        return [
            'platform' => $platform,
            'tracks'   => $tracks->toArray(),
            'count'    => count($tracks),
        ];
    }

    /**
     * Get royalty-free music for YouTube.
     */
    public function getRoyaltyFreeMusic(?string $mood = null, int $count = 10): array
    {
        $query = MusicTrack::royaltyFree();

        if ($mood) {
            $query->forMood($mood);
        }

        return $query->limit($count)->get()->toArray();
    }

    /**
     * Search music by criteria.
     */
    public function searchMusic(array $criteria): array
    {
        $query = MusicTrack::query();

        if (!empty($criteria['mood'])) {
            $query->forMood($criteria['mood']);
        }

        if (!empty($criteria['genre'])) {
            $query->where('genre', $criteria['genre']);
        }

        if (!empty($criteria['category'])) {
            $query->forCategory($criteria['category']);
        }

        if (!empty($criteria['platform'])) {
            $query->forPlatform($criteria['platform']);
        }

        if (!empty($criteria['royalty_free'])) {
            $query->royaltyFree();
        }

        if (!empty($criteria['max_duration'])) {
            $query->where('duration_seconds', '<=', $criteria['max_duration']);
        }

        return $query->limit($criteria['limit'] ?? 20)->get()->toArray();
    }

    /**
     * Get mood suggestions for a content category.
     */
    public function getMoodSuggestions(string $category): array
    {
        return self::CATEGORY_MOOD_MAP[$category] ?? ['uplifting', 'calm', 'energetic'];
    }

    /**
     * Get platform preferences.
     */
    public function getPlatformPreferences(string $platform): array
    {
        return self::PLATFORM_PREFERENCES[$platform] ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DATABASE SEEDING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Seed the music tracks table.
     */
    public function seedMusicLibrary(): int
    {
        $count = 0;

        foreach (self::SEED_MUSIC as $track) {
            MusicTrack::updateOrCreate(
                ['title' => $track['title'], 'artist' => $track['artist']],
                $track
            );
            $count++;
        }

        return $count;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FORMATTING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Format music recommendations for display.
     */
    public function formatForDisplay(array $recommendations): string
    {
        $lines = ["🎵 **Music Recommendations**\n"];
        $lines[] = "Category: {$recommendations['category']}";
        $lines[] = "Platform: " . ucfirst($recommendations['platform']);
        $lines[] = "Suggested moods: " . implode(', ', $recommendations['suggested_moods']);
        $lines[] = "";

        foreach ($recommendations['tracks'] as $i => $track) {
            $emoji = $track['is_royalty_free'] ? '✅' : '⚠️';
            $lines[] = ($i + 1) . ". **{$track['title']}** by {$track['artist']}";
            $lines[] = "   Mood: {$track['mood']} | Genre: {$track['genre']}";
            $lines[] = "   {$emoji} " . ($track['is_royalty_free'] ? 'Royalty Free' : 'Check licensing');
            if ($track['note']) {
                $lines[] = "   💡 {$track['note']}";
            }
            $lines[] = "";
        }

        return implode("\n", $lines);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function getSeedTracksForMood(array $moods, int $count): \Illuminate\Support\Collection
    {
        $matching = collect(self::SEED_MUSIC)->filter(function ($track) use ($moods) {
            return in_array($track['mood'], $moods);
        });

        if ($matching->isEmpty()) {
            $matching = collect(self::SEED_MUSIC);
        }

        return $matching->take($count)->map(function ($track) {
            return (object) $track;
        });
    }
}
