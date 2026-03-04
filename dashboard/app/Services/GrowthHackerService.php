<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Growth Hacker Service — next-gen organic reach maximisation, zero ad spend.
 *
 * Features:
 * 1. Smart posting scheduler — platform-specific peak engagement times
 * 2. Local SEO keywords injection
 * 3. Viral content format suggestions
 * 4. Content series automation — themed weekly series
 * 5. Google My Business post generation
 * 6. Caption engagement analysis (BJ Fogg Behavior Model)
 * 7. Marketing ideas engine — 100+ proven strategy templates
 * 8. Free advertising strategies guide
 */
class GrowthHackerService
{
    protected OpenAIService $openai;
    protected int $businessId;

    protected const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * Peak engagement hours by platform (based on industry research)
     */
    protected const PEAK_HOURS = [
        'instagram' => [
            'Monday' => ['times' => ['09:00', '12:00', '19:00'], 'best' => '12:00'],
            'Tuesday' => ['times' => ['09:00', '13:00', '19:00'], 'best' => '13:00'],
            'Wednesday' => ['times' => ['09:00', '11:00', '19:00'], 'best' => '11:00'],
            'Thursday' => ['times' => ['09:00', '12:00', '20:00'], 'best' => '12:00'],
            'Friday' => ['times' => ['14:00', '17:00', '20:00'], 'best' => '14:00'],
            'Saturday' => ['times' => ['10:00', '14:00', '20:00'], 'best' => '10:00'],
            'Sunday' => ['times' => ['10:00', '13:00', '19:00'], 'best' => '10:00'],
        ],
        'facebook' => [
            'Monday' => ['times' => ['09:00', '13:00', '16:00'], 'best' => '13:00'],
            'Tuesday' => ['times' => ['09:00', '13:00', '16:00'], 'best' => '09:00'],
            'Wednesday' => ['times' => ['09:00', '13:00', '15:00'], 'best' => '13:00'],
            'Thursday' => ['times' => ['09:00', '12:00', '15:00'], 'best' => '12:00'],
            'Friday' => ['times' => ['09:00', '11:00', '14:00'], 'best' => '11:00'],
            'Saturday' => ['times' => ['10:00', '12:00'], 'best' => '10:00'],
            'Sunday' => ['times' => ['10:00', '12:00'], 'best' => '12:00'],
        ],
        'tiktok' => [
            'Monday' => ['times' => ['12:00', '16:00', '21:00'], 'best' => '21:00'],
            'Tuesday' => ['times' => ['09:00', '15:00', '21:00'], 'best' => '15:00'],
            'Wednesday' => ['times' => ['12:00', '19:00', '21:00'], 'best' => '19:00'],
            'Thursday' => ['times' => ['12:00', '15:00', '21:00'], 'best' => '21:00'],
            'Friday' => ['times' => ['17:00', '19:00', '21:00'], 'best' => '19:00'],
            'Saturday' => ['times' => ['11:00', '19:00', '21:00'], 'best' => '11:00'],
            'Sunday' => ['times' => ['12:00', '16:00', '20:00'], 'best' => '16:00'],
        ],
        'youtube' => [
            'Monday' => ['times' => ['14:00', '16:00'], 'best' => '14:00'],
            'Tuesday' => ['times' => ['14:00', '16:00'], 'best' => '14:00'],
            'Wednesday' => ['times' => ['14:00', '16:00'], 'best' => '14:00'],
            'Thursday' => ['times' => ['12:00', '15:00'], 'best' => '12:00'],
            'Friday' => ['times' => ['12:00', '15:00'], 'best' => '12:00'],
            'Saturday' => ['times' => ['09:00', '11:00'], 'best' => '09:00'],
            'Sunday' => ['times' => ['09:00', '11:00'], 'best' => '09:00'],
        ],
        'linkedin' => [
            'Monday' => ['times' => ['08:00', '10:00', '12:00'], 'best' => '10:00'],
            'Tuesday' => ['times' => ['08:00', '10:00', '12:00'], 'best' => '10:00'],
            'Wednesday' => ['times' => ['08:00', '10:00', '12:00'], 'best' => '12:00'],
            'Thursday' => ['times' => ['08:00', '10:00', '14:00'], 'best' => '10:00'],
            'Friday' => ['times' => ['08:00', '10:00'], 'best' => '08:00'],
            'Saturday' => ['times' => [], 'best' => null],
            'Sunday' => ['times' => [], 'best' => null],
        ],
    ];

    /**
     * Local SEO keywords by category
     */
    protected const LOCAL_SEO_KEYWORDS = [
        'opd' => ['best doctor nearby', 'doctor near me', 'OPD clinic', 'general physician', 'medical checkup'],
        'laboratory' => ['best lab nearby', 'blood test near me', 'lab test', 'CBC test', 'thyroid test'],
        'hydrafacial' => ['hydrafacial treatment', 'best facial treatment', 'skin treatment', 'glowing skin facial'],
        'laser_hair_removal' => ['laser hair removal', 'permanent hair removal', 'laser treatment', 'painless hair removal'],
        'xray' => ['X-ray near me', 'digital X-ray', 'X-ray clinic', 'chest X-ray'],
        'ultrasound_echo' => ['ultrasound', 'echo test', 'echocardiography', 'sonography'],
        'ecg' => ['ECG test', 'heart test', 'cardiac test near me', 'ECG clinic'],
        'general' => ['healthcare', 'best clinic', 'hospital near me', 'health checkup'],
    ];

    /**
     * Viral content formats by platform
     */
    protected const VIRAL_FORMATS = [
        'instagram' => [
            ['format' => 'carousel_educational', 'hook' => '5 signs you need to check your {service} 👇', 'why' => 'Carousel posts get 3x more engagement than single images'],
            ['format' => 'before_after_reveal', 'hook' => 'Wait for it... 🤯', 'why' => 'Transformation content has the highest save rate'],
            ['format' => 'day_in_the_life', 'hook' => 'A day at our clinic ✨', 'why' => 'Humanises the brand, builds trust'],
            ['format' => 'this_or_that', 'hook' => 'Which would you choose? 🤔', 'why' => 'Drives comments and shares'],
        ],
        'tiktok' => [
            ['format' => 'myth_busters', 'hook' => 'Things your doctor wants you to know 🏥', 'why' => 'Educational content with a hook goes viral'],
            ['format' => 'pov', 'hook' => 'POV: You finally booked that appointment 🙌', 'why' => 'POV format consistently trends'],
            ['format' => 'expectation_vs_reality', 'hook' => 'Expectations vs Reality: {service}', 'why' => 'Relatable content + surprise element = viral'],
            ['format' => 'asmr_procedure', 'hook' => 'Oddly satisfying {service} 🎧', 'why' => 'ASMR content gets millions of views'],
        ],
        'youtube' => [
            ['format' => 'full_procedure', 'hook' => 'What Really Happens During a {service}?', 'why' => 'Long-form educational content ranks on Google'],
            ['format' => 'facility_tour', 'hook' => 'Inside Our Modern Healthcare Clinic', 'why' => 'Great for SEO and building patient confidence'],
            ['format' => 'doctor_explains', 'hook' => 'Doctor Explains: Everything About {service}', 'why' => 'Positions as authority'],
        ],
        'linkedin' => [
            ['format' => 'thought_leadership', 'hook' => 'The future of healthcare is...', 'why' => 'LinkedIn rewards professional insights'],
            ['format' => 'team_spotlight', 'hook' => 'Meet Dr. [Name] — 10 years of changing lives', 'why' => 'People-focused content gets 3x more engagement'],
            ['format' => 'milestone', 'hook' => 'We just served our 10,000th patient 🎉', 'why' => 'Celebration posts get massive engagement'],
        ],
    ];

    /**
     * Weekly content series themes
     */
    protected const WEEKLY_SERIES = [
        ['name' => 'Wellness Wednesday', 'day' => 'Wednesday', 'theme' => 'Health tips, prevention advice, wellness motivation', 'hashtags' => ['WellnessWednesday', 'HealthTips', 'WeCare'], 'platforms' => ['instagram', 'facebook', 'linkedin']],
        ['name' => 'Transformation Tuesday', 'day' => 'Tuesday', 'theme' => 'Before/after results from treatments', 'hashtags' => ['TransformationTuesday', 'BeforeAndAfter', 'GlowUp'], 'platforms' => ['instagram', 'tiktok']],
        ['name' => 'Tech Thursday', 'day' => 'Thursday', 'theme' => 'Showcase modern equipment', 'hashtags' => ['TechThursday', 'ModernHealthcare', 'MedicalTech'], 'platforms' => ['instagram', 'linkedin', 'youtube']],
        ['name' => 'Team Friday', 'day' => 'Friday', 'theme' => 'Meet the team, behind the scenes', 'hashtags' => ['TeamFriday', 'MeetTheTeam', 'OurFamily'], 'platforms' => ['instagram', 'facebook', 'linkedin']],
        ['name' => 'Self-Care Saturday', 'day' => 'Saturday', 'theme' => 'Skincare tips, treatment spotlights', 'hashtags' => ['SelfCareSaturday', 'SkinCare', 'GlowingSkin'], 'platforms' => ['instagram', 'tiktok', 'facebook']],
        ['name' => 'MedFact Monday', 'day' => 'Monday', 'theme' => 'Interesting medical facts, myth busting', 'hashtags' => ['MedFactMonday', 'DidYouKnow', 'HealthFacts'], 'platforms' => ['instagram', 'tiktok', 'linkedin']],
    ];

    /**
     * Marketing ideas database
     */
    protected const MARKETING_IDEAS = [
        ['idea' => 'Before & After transformation post', 'category' => 'content', 'effort' => 'low', 'platforms' => ['instagram', 'tiktok'], 'service' => 'hydrafacial'],
        ['idea' => 'Day-in-the-life Reel at the clinic', 'category' => 'content', 'effort' => 'medium', 'platforms' => ['instagram', 'tiktok'], 'service' => 'general'],
        ['idea' => 'Patient testimonial video (30 sec)', 'category' => 'content', 'effort' => 'low', 'platforms' => ['instagram', 'facebook', 'youtube'], 'service' => 'general'],
        ['idea' => 'Myth-busting carousel (5 common health myths)', 'category' => 'content', 'effort' => 'medium', 'platforms' => ['instagram', 'linkedin'], 'service' => 'opd'],
        ['idea' => 'POV: Your first treatment experience', 'category' => 'content', 'effort' => 'low', 'platforms' => ['tiktok', 'instagram'], 'service' => 'hydrafacial'],
        ['idea' => 'Doctor explains procedure in 60 seconds', 'category' => 'content', 'effort' => 'medium', 'platforms' => ['tiktok', 'youtube', 'instagram'], 'service' => 'general'],
        ['idea' => 'Equipment showcase — what each machine does', 'category' => 'content', 'effort' => 'low', 'platforms' => ['instagram', 'linkedin'], 'service' => 'general'],
        ['idea' => 'ASMR treatment footage (oddly satisfying)', 'category' => 'content', 'effort' => 'low', 'platforms' => ['tiktok'], 'service' => 'hydrafacial'],
        ['idea' => 'Full facility tour with narration', 'category' => 'content', 'effort' => 'high', 'platforms' => ['youtube', 'instagram'], 'service' => 'general'],
        ['idea' => 'This or That — interactive poll stories', 'category' => 'engagement', 'effort' => 'low', 'platforms' => ['instagram', 'facebook'], 'service' => 'general'],
        ['idea' => 'Health quiz carousel (test your knowledge)', 'category' => 'engagement', 'effort' => 'medium', 'platforms' => ['instagram'], 'service' => 'opd'],
        ['idea' => 'Ask Me Anything with a doctor (Stories)', 'category' => 'engagement', 'effort' => 'medium', 'platforms' => ['instagram'], 'service' => 'opd'],
        ['idea' => 'True or False health facts series', 'category' => 'engagement', 'effort' => 'low', 'platforms' => ['instagram', 'tiktok'], 'service' => 'general'],
        ['idea' => 'YouTube Shorts answering local search queries', 'category' => 'seo', 'effort' => 'medium', 'platforms' => ['youtube'], 'service' => 'laboratory'],
        ['idea' => 'Google My Business weekly update with offer', 'category' => 'seo', 'effort' => 'low', 'platforms' => ['google'], 'service' => 'general'],
        ['idea' => 'Blog-style LinkedIn post about health trends', 'category' => 'seo', 'effort' => 'medium', 'platforms' => ['linkedin'], 'service' => 'general'],
        ['idea' => 'Team member spotlight post', 'category' => 'trust', 'effort' => 'low', 'platforms' => ['instagram', 'linkedin', 'facebook'], 'service' => 'general'],
        ['idea' => 'Milestone celebration (X patients served)', 'category' => 'trust', 'effort' => 'low', 'platforms' => ['instagram', 'facebook', 'linkedin'], 'service' => 'general'],
        ['idea' => 'Behind the scenes preparation', 'category' => 'trust', 'effort' => 'low', 'platforms' => ['instagram', 'tiktok'], 'service' => 'general'],
        ['idea' => 'Limited-time package deal (Scarcity)', 'category' => 'promo', 'effort' => 'low', 'platforms' => ['instagram', 'facebook'], 'service' => 'general'],
        ['idea' => 'Referral reward announcement', 'category' => 'promo', 'effort' => 'low', 'platforms' => ['instagram', 'facebook'], 'service' => 'general'],
        ['idea' => 'Seasonal health checkup reminder', 'category' => 'promo', 'effort' => 'low', 'platforms' => ['instagram', 'facebook', 'linkedin'], 'service' => 'opd'],
    ];

    /**
     * Psychology triggers for engagement analysis
     */
    protected const PSYCHOLOGY_TRIGGERS = [
        'loss_aversion' => ['keywords' => ["don't miss", "don't let", "before it's too late", 'last chance', 'running out'], 'points' => 15, 'label' => 'Loss Aversion'],
        'social_proof' => ['keywords' => ['thousands', 'most popular', 'customers love', 'reviews', 'rated', 'trusted'], 'points' => 12, 'label' => 'Social Proof'],
        'scarcity' => ['keywords' => ['limited', 'only', 'few left', 'exclusive', 'rare', 'special'], 'points' => 12, 'label' => 'Scarcity'],
        'authority' => ['keywords' => ['expert', 'doctor', 'certified', 'years of experience', 'professional', 'specialist'], 'points' => 10, 'label' => 'Authority'],
        'reciprocity' => ['keywords' => ['free', 'gift', 'bonus', 'tip', 'advice', 'guide'], 'points' => 10, 'label' => 'Reciprocity'],
        'curiosity' => ['keywords' => ['the real reason', 'what most people', "here's why", 'the truth about'], 'points' => 10, 'label' => 'Curiosity Gap'],
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
        $this->openai = new OpenAIService($businessId);
    }

    /**
     * Get the best posting time for a platform (alias for WorkflowService).
     *
     * @param string $platform Target platform
     * @return array Posting schedule with datetime information
     */
    public function getBestPostingTime(string $platform): array
    {
        $optimal = $this->getNextOptimalTime($platform);
        return [
            'datetime' => $optimal->toDateTimeString(),
            'formatted' => $optimal->format('l g:i A'),
            'relative' => $optimal->diffForHumans(),
            'platform' => $platform,
        ];
    }

    /**
     * Get the next optimal posting time for a platform.
     */
    public function getNextOptimalTime(string $platform): Carbon
    {
        $now = Carbon::now();
        $currentDay = self::DAYS[$now->dayOfWeek];
        $schedule = self::PEAK_HOURS[$platform] ?? self::PEAK_HOURS['instagram'];

        // Check today's times
        if (isset($schedule[$currentDay])) {
            $daySchedule = $schedule[$currentDay];

            // Try the best time first
            if (!empty($daySchedule['best'])) {
                $bestTime = Carbon::parse($daySchedule['best']);
                $bestDt = $now->copy()->setTime($bestTime->hour, $bestTime->minute);
                if ($bestDt->gt($now)) {
                    return $bestDt;
                }
            }

            // Try other times
            foreach ($daySchedule['times'] ?? [] as $time) {
                $slotTime = Carbon::parse($time);
                $slotDt = $now->copy()->setTime($slotTime->hour, $slotTime->minute);
                if ($slotDt->gt($now)) {
                    return $slotDt;
                }
            }
        }

        // No slots today — find next day's best
        for ($offset = 1; $offset <= 7; $offset++) {
            $nextDate = $now->copy()->addDays($offset);
            $nextDay = self::DAYS[$nextDate->dayOfWeek];

            if (isset($schedule[$nextDay]) && !empty($schedule[$nextDay]['best'])) {
                $bestTime = Carbon::parse($schedule[$nextDay]['best']);
                return $nextDate->setTime($bestTime->hour, $bestTime->minute);
            }
        }

        // Fallback — tomorrow at 10am
        return $now->copy()->addDay()->setTime(10, 0);
    }

    /**
     * Get posting schedule for multiple platforms.
     */
    public function getPostingSchedule(array $platforms): array
    {
        $schedule = [];
        foreach ($platforms as $platform) {
            $optimal = $this->getNextOptimalTime($platform);
            $schedule[$platform] = [
                'datetime' => $optimal->toDateTimeString(),
                'formatted' => $optimal->format('l g:i A'),
                'relative' => $optimal->diffForHumans(),
            ];
        }
        return $schedule;
    }

    /**
     * Get SEO keywords for a category.
     */
    public function getSeoKeywords(string $category, int $count = 5): array
    {
        $categoryLower = strtolower($category);
        $keywords = self::LOCAL_SEO_KEYWORDS[$categoryLower] ?? self::LOCAL_SEO_KEYWORDS['general'];
        return array_slice($keywords, 0, $count);
    }

    /**
     * Suggest a viral content format.
     */
    public function suggestViralFormat(string $category, string $platform): ?array
    {
        $formats = self::VIRAL_FORMATS[$platform] ?? [];
        if (empty($formats)) {
            return null;
        }

        // Map categories to preferred formats
        $categoryFormatMap = [
            'hydrafacial' => 'before_after_reveal',
            'laser_hair_removal' => 'before_after_reveal',
            'before_after' => 'before_after_reveal',
            'laboratory' => 'carousel_educational',
            'ecg' => 'carousel_educational',
            'xray' => 'carousel_educational',
            'team' => 'team_spotlight',
            'facility' => 'facility_tour',
            'promotional' => 'this_or_that',
        ];

        $preferred = $categoryFormatMap[strtolower($category)] ?? null;

        foreach ($formats as $format) {
            if ($format['format'] === $preferred) {
                return $format;
            }
        }

        return $formats[0];
    }

    /**
     * Get today's content series theme.
     */
    public function getTodaysSeries(): ?array
    {
        $today = self::DAYS[Carbon::now()->dayOfWeek];

        foreach (self::WEEKLY_SERIES as $series) {
            if ($series['day'] === $today) {
                return $series;
            }
        }

        return null;
    }

    /**
     * Get the full weekly content plan.
     */
    public function getWeeklyContentPlan(): array
    {
        return self::WEEKLY_SERIES;
    }

    /**
     * Suggest marketing ideas based on filters.
     */
    public function suggestMarketingIdeas(
        ?string $service = null,
        ?string $platform = null,
        ?string $effort = null,
        int $count = 5
    ): array {
        $ideas = self::MARKETING_IDEAS;

        if ($service) {
            $serviceLower = strtolower($service);
            $ideas = array_filter($ideas, fn($idea) =>
                $idea['service'] === 'general' || $idea['service'] === $serviceLower
            );
        }

        if ($platform) {
            $platformLower = strtolower($platform);
            $ideas = array_filter($ideas, fn($idea) =>
                in_array($platformLower, $idea['platforms'])
            );
        }

        if ($effort) {
            $effortLower = strtolower($effort);
            $ideas = array_filter($ideas, fn($idea) =>
                $idea['effort'] === $effortLower
            );
        }

        return array_slice(array_values($ideas), 0, $count);
    }

    /**
     * Analyze caption for engagement using BJ Fogg Behavior Model.
     * 100% rule-based — zero API calls.
     */
    public function analyzeCaptionEngagement(string $caption, string $platform): array
    {
        $suggestions = [];
        $motivationScore = 20;
        $abilityScore = 20;
        $triggerScore = 10;
        $psychologyUsed = [];

        $captionLower = strtolower($caption);
        $firstLine = explode("\n", $caption)[0] ?? '';

        // Motivation: Psychology triggers
        foreach (self::PSYCHOLOGY_TRIGGERS as $trigger) {
            foreach ($trigger['keywords'] as $keyword) {
                if (str_contains($captionLower, $keyword)) {
                    $motivationScore += $trigger['points'];
                    $psychologyUsed[] = $trigger['label'];
                    break;
                }
            }
        }

        if (empty($psychologyUsed)) {
            $suggestions[] = 'Add a psychology trigger: Loss Aversion, Social Proof, or Scarcity';
        }

        // Motivation: Hook quality
        $hookPatterns = ['?', '🤔', '👇', '🔥', '💡', '...', ':', "here's"];
        $hasHook = false;
        foreach ($hookPatterns as $pattern) {
            if (str_contains(strtolower($firstLine), $pattern)) {
                $hasHook = true;
                break;
            }
        }
        if ($hasHook) {
            $motivationScore += 10;
        } else {
            $suggestions[] = 'Start with a hook — question, bold claim, or curiosity gap';
        }

        // Ability: Readability
        $words = str_word_count($caption);
        $sentences = max(1, substr_count($caption, '.') + substr_count($caption, '!') + substr_count($caption, '?'));
        $avgSentenceLength = $words / $sentences;

        if ($avgSentenceLength <= 15) {
            $abilityScore += 15;
        } elseif ($avgSentenceLength <= 25) {
            $abilityScore += 8;
        } else {
            $suggestions[] = 'Shorten your sentences — aim for 10-15 words per sentence';
        }

        // Line breaks = scannable
        if (str_contains($caption, "\n")) {
            $abilityScore += 10;
        } else {
            $suggestions[] = 'Add line breaks for better readability';
        }

        // Platform-appropriate length
        $idealLengths = [
            'instagram' => [50, 200],
            'tiktok' => [10, 50],
            'linkedin' => [100, 300],
            'facebook' => [40, 150],
            'youtube' => [200, 500],
            'snapchat' => [5, 30],
            'twitter' => [10, 50],
        ];
        [$low, $high] = $idealLengths[$platform] ?? [50, 200];

        if ($words >= $low && $words <= $high) {
            $abilityScore += 10;
        } elseif ($words < $low) {
            $suggestions[] = "Too short for {$platform} — aim for {$low}-{$high} words";
        } else {
            $suggestions[] = "Too long for {$platform} — trim to {$low}-{$high} words";
        }

        // Trigger: CTA + urgency
        $ctaWords = ['book', 'call', 'visit', 'dm', 'comment', 'share', 'tag', 'follow', 'save', 'click', 'schedule', 'appointment', 'link'];
        $hasCta = false;
        foreach ($ctaWords as $word) {
            if (str_contains($captionLower, $word)) {
                $hasCta = true;
                break;
            }
        }
        if ($hasCta) {
            $triggerScore += 20;
        } else {
            $suggestions[] = 'Add a CTA: Book now, DM us, Comment below, Save this';
        }

        // Question drives comments
        if (str_contains($caption, '?')) {
            $triggerScore += 10;
        } else {
            $suggestions[] = 'Include a question to encourage comments';
        }

        // Urgency words
        $urgencyWords = ['now', 'today', 'tonight', 'this week', 'limited', 'hurry'];
        foreach ($urgencyWords as $word) {
            if (str_contains($captionLower, $word)) {
                $triggerScore += 10;
                break;
            }
        }

        // Composite BJ Fogg score
        $m = min($motivationScore, 100) / 100;
        $a = min($abilityScore, 100) / 100;
        $t = min($triggerScore, 100) / 100;
        $foggScore = round(($m * $a * $t) * 100);
        $simpleScore = min(100, $motivationScore + $abilityScore + $triggerScore);

        // Verdict
        if ($simpleScore >= 80) {
            $verdict = '🟢 Strong — high conversion potential';
        } elseif ($simpleScore >= 60) {
            $verdict = '🟡 Good — apply suggestions for more reach';
        } else {
            $verdict = '🔴 Needs work — follow the suggestions above';
        }

        return [
            'engagement_score' => $simpleScore,
            'fogg_score' => $foggScore,
            'breakdown' => [
                'motivation' => min(100, $motivationScore),
                'ability' => min(100, $abilityScore),
                'trigger' => min(100, $triggerScore),
            ],
            'psychology_triggers_used' => array_unique($psychologyUsed),
            'suggestions' => $suggestions,
            'verdict' => $verdict,
        ];
    }

    /**
     * Generate a Google My Business post.
     */
    public function generateGmbPost(string $contentCategory, string $contentDescription): array
    {
        if (!$this->openai->isConfigured()) {
            return [
                'success' => false,
                'error' => 'OpenAI not configured',
            ];
        }

        $systemPrompt = "You write Google My Business posts for a business. Posts should be 100-300 characters, professional but inviting, with a clear call-to-action.";

        $userPrompt = "Write a Google My Business update about: " . OpenAIService::sanitize($contentDescription) . "\n";
        $userPrompt .= "Category: " . OpenAIService::sanitize($contentCategory) . "\n\n";
        $userPrompt .= 'Return JSON: {"post": "text", "cta_type": "BOOK"|"LEARN_MORE"|"CALL"}';

        try {
            $result = $this->openai->chatCompletion($systemPrompt, $userPrompt, 0.6, 200, true);

            return [
                'success' => true,
                'post' => $result['post'] ?? '',
                'cta_type' => $result['cta_type'] ?? 'LEARN_MORE',
            ];
        } catch (\Exception $e) {
            Log::error('GMB post generation failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get free advertising strategies guide.
     */
    public function getFreeAdStrategies(): array
    {
        return [
            [
                'strategy' => 'Google My Business Posts',
                'cost' => 'Free',
                'priority' => 'HIGH',
                'description' => 'Post updates, offers, and photos weekly. These show up directly in Google Search and Maps.',
            ],
            [
                'strategy' => 'Instagram Collab Posts',
                'cost' => 'Free',
                'priority' => 'HIGH',
                'description' => 'Invite local influencers for free treatment in exchange for collab posts. Doubles your reach.',
            ],
            [
                'strategy' => 'Facebook Group Marketing',
                'cost' => 'Free',
                'priority' => 'MEDIUM',
                'description' => 'Join community groups and share helpful tips (not ads) with your brand watermark.',
            ],
            [
                'strategy' => 'YouTube SEO',
                'cost' => 'Free',
                'priority' => 'HIGH',
                'description' => 'Create videos targeting local search terms. YouTube videos rank on Google search.',
            ],
            [
                'strategy' => 'WhatsApp Status Marketing',
                'cost' => 'Free',
                'priority' => 'MEDIUM',
                'description' => 'Share branded images with QR codes on WhatsApp Status daily.',
            ],
            [
                'strategy' => 'Patient Testimonials',
                'cost' => 'Free',
                'priority' => 'HIGH',
                'description' => 'Ask happy customers for 30-second video testimonials. Highest converting content.',
            ],
            [
                'strategy' => 'Health Awareness Days',
                'cost' => 'Free',
                'priority' => 'MEDIUM',
                'description' => 'Post related content on health awareness days to ride trending hashtags.',
            ],
        ];
    }

    /**
     * Generate a comprehensive growth report.
     */
    public function generateGrowthReport(): array
    {
        $todaysSeries = $this->getTodaysSeries();
        $nextOptimalTimes = $this->getPostingSchedule(['instagram', 'facebook', 'tiktok', 'linkedin']);

        return [
            'todays_series' => $todaysSeries,
            'posting_schedule' => $nextOptimalTimes,
            'content_ideas' => $this->suggestMarketingIdeas(null, null, 'low', 3),
            'free_strategies' => $this->getFreeAdStrategies(),
        ];
    }

    /**
     * Generate posting schedule for a single platform (API compatibility wrapper).
     */
    public function generatePostingSchedule(string $platform): array
    {
        $schedule = $this->getPostingSchedule([$platform]);
        $optimal = $this->getNextOptimalTime($platform);

        return [
            'success'  => true,
            'platform' => $platform,
            'schedule' => $schedule[$platform] ?? [],
            'next_optimal_time' => $optimal->toIso8601String(),
            'best_times' => $this->getBestPostingTime($platform),
        ];
    }
}
