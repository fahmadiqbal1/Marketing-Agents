<?php

namespace App\Services;

/**
 * Hashtag Researcher Service — platform-specific hashtag suggestions.
 *
 * Features:
 * 1. Pre-built hashtag database by category and platform
 * 2. Optimal hashtag count recommendations
 * 3. Mix of popular, niche, and branded hashtags
 * 4. Platform-specific best practices
 */
class HashtagResearcherService
{
    /**
     * Pre-built hashtag database by category and platform
     */
    protected const SEED_HASHTAGS = [
        'opd' => [
            'instagram' => ['healthcare', 'doctor', 'clinic', 'checkup', 'healthcheckup', 'medicalcare', 'doctorvisit', 'wellness', 'healthylifestyle', 'preventivecare', 'familydoctor', 'healthclinic', 'OPD', 'consultation', 'healthmatters', 'digitalhealth'],
            'facebook' => ['healthcare', 'clinic', 'doctor', 'healthcheckup', 'wellness'],
            'linkedin' => ['healthcare', 'medicalservices', 'primarycare', 'healthtech', 'clinicmanagement'],
            'tiktok' => ['doctor', 'clinic', 'healthcheck', 'wellness', 'healthcare'],
            'youtube' => ['doctor visit', 'health checkup', 'OPD', 'clinic tour', 'medical consultation', 'healthcare', 'health tips'],
            'twitter' => ['healthcare', 'health', 'doctor', 'wellness'],
        ],
        'laboratory' => [
            'instagram' => ['labtest', 'bloodtest', 'healthtest', 'diagnostics', 'laboratory', 'CBC', 'thyroid', 'cliniclab', 'pathology', 'healthscreening', 'medicallab', 'bloodwork', 'healthawareness', 'labreport'],
            'facebook' => ['labtest', 'bloodtest', 'diagnostics', 'healthscreening', 'laboratory'],
            'linkedin' => ['diagnostics', 'pathology', 'clinicallaboratory', 'medtech', 'healthscreening'],
            'tiktok' => ['labtest', 'bloodtest', 'healthcheck', 'diagnostics', 'medical'],
            'youtube' => ['blood test', 'lab test', 'health screening', 'diagnostics', 'pathology', 'health checkup', 'medical tests explained'],
            'twitter' => ['labtest', 'health', 'bloodtest', 'medical'],
        ],
        'hydrafacial' => [
            'instagram' => ['hydrafacial', 'skincare', 'glowingskin', 'facialtreatment', 'clearskin', 'skincareroutine', 'beautytreatment', 'dermatology', 'skinclinic', 'glowup', 'hydrafaciallove', 'skinhealth', 'facialist', 'skingoals', 'hydration', 'deepcleansing', 'skincaregoals', 'beautycare'],
            'facebook' => ['hydrafacial', 'skincare', 'facialtreatment', 'glowingskin', 'beautytreatment'],
            'linkedin' => ['aestheticmedicine', 'skincare', 'dermatology', 'beautyindustry', 'medicalesthetics'],
            'tiktok' => ['hydrafacial', 'skincare', 'glowup', 'beforeandafter', 'skintok'],
            'youtube' => ['hydrafacial', 'facial treatment', 'skincare routine', 'skin clinic', 'glowing skin', 'before and after', 'skin treatment'],
            'twitter' => ['skincare', 'beauty', 'hydrafacial', 'glowingskin'],
        ],
        'laser_hair_removal' => [
            'instagram' => ['laserhairremoval', 'hairremoval', 'smoothskin', 'lasertreatment', 'painlesshairremoval', 'permanenthairremoval', 'skincare', 'beautytreatment', 'dermatology', 'hairfree', 'laserclinic', 'bodycare', 'laserskincare', 'grooming'],
            'facebook' => ['laserhairremoval', 'hairremoval', 'smoothskin', 'beautytreatment', 'skincare'],
            'linkedin' => ['aestheticmedicine', 'lasertreatment', 'dermatology', 'beautytech', 'medicalesthetics'],
            'tiktok' => ['laserhairremoval', 'smoothskin', 'hairfree', 'beautytreatment', 'skintok'],
            'youtube' => ['laser hair removal', 'permanent hair removal', 'painless laser', 'hair removal treatment', 'beauty treatment', 'laser clinic'],
            'twitter' => ['laserhairremoval', 'skincare', 'beauty', 'hairfree'],
        ],
        'xray' => [
            'instagram' => ['xray', 'digitalxray', 'diagnosticimaging', 'radiology', 'healthcare', 'medicalimaging', 'bonehealth', 'healthdiagnostics'],
            'facebook' => ['xray', 'diagnosticimaging', 'healthcare', 'radiology', 'medicalimaging'],
            'linkedin' => ['radiology', 'diagnosticimaging', 'medicalimaging', 'healthtech', 'healthcare'],
            'tiktok' => ['xray', 'medical', 'healthcare', 'radiology'],
            'youtube' => ['x-ray', 'diagnostic imaging', 'radiology', 'medical imaging', 'healthcare'],
            'twitter' => ['xray', 'healthcare', 'radiology', 'medical'],
        ],
        'ultrasound_echo' => [
            'instagram' => ['ultrasound', 'echocardiography', 'echo', 'heartcheck', 'cardiology', 'diagnosticimaging', 'hearthealth', 'cardiac', 'healthscreening', 'medicalimaging', 'heartcare'],
            'facebook' => ['ultrasound', 'echo', 'heartcheck', 'cardiology', 'hearthealth'],
            'linkedin' => ['echocardiography', 'cardiology', 'diagnosticimaging', 'hearthealth', 'medicaltech'],
            'tiktok' => ['heartcheck', 'ultrasound', 'echo', 'cardiology', 'health'],
            'youtube' => ['ultrasound', 'echocardiography', 'heart health', 'cardiac test', 'diagnostic imaging'],
            'twitter' => ['ultrasound', 'hearthealth', 'cardiology', 'medical'],
        ],
        'ecg' => [
            'instagram' => ['ecg', 'electrocardiogram', 'hearttest', 'cardiology', 'hearthealth', 'heartcheckup', 'cardiaccare', 'healthtest', 'medicaltest'],
            'facebook' => ['ecg', 'hearttest', 'cardiology', 'hearthealth', 'healthcare'],
            'linkedin' => ['cardiology', 'hearthealth', 'medicaldevices', 'healthtech', 'diagnostics'],
            'tiktok' => ['ecg', 'hearttest', 'health', 'medical', 'cardiology'],
            'youtube' => ['ECG test', 'heart health', 'cardiac screening', 'electrocardiogram', 'medical tests'],
            'twitter' => ['ecg', 'hearthealth', 'cardiology', 'health'],
        ],
        'team' => [
            'instagram' => ['meettheteam', 'teamwork', 'healthcare', 'ourteam', 'behindthescenes', 'healthcareheroes', 'doctors', 'nurses', 'medicalstaff', 'teamfriday', 'workfamily'],
            'facebook' => ['meettheteam', 'team', 'healthcare', 'ourstaff', 'behindthescenes'],
            'linkedin' => ['team', 'healthcare', 'leadership', 'teamspotlight', 'companyculture', 'employeespotlight'],
            'tiktok' => ['team', 'dayinthelife', 'behindthescenes', 'worklife', 'healthcare'],
            'youtube' => ['meet the team', 'behind the scenes', 'healthcare team', 'clinic staff', 'medical professionals'],
            'twitter' => ['team', 'healthcare', 'behindthescenes', 'staff'],
        ],
        'facility' => [
            'instagram' => ['clinic', 'healthcarefacility', 'modernhealthcare', 'clinictour', 'medicalequipment', 'cleanclinic', 'healthcare', 'hospital', 'medicalcenter'],
            'facebook' => ['clinic', 'facility', 'healthcare', 'hospital', 'medicalcenter'],
            'linkedin' => ['healthcare', 'medicalfacility', 'healthtech', 'modernhealthcare', 'clinicdesign'],
            'tiktok' => ['clinic', 'hospital', 'tour', 'modern', 'healthcare'],
            'youtube' => ['clinic tour', 'healthcare facility', 'medical center', 'hospital tour', 'modern clinic'],
            'twitter' => ['clinic', 'healthcare', 'hospital', 'medical'],
        ],
        'promotional' => [
            'instagram' => ['offer', 'discount', 'sale', 'limitedtime', 'specialoffer', 'deals', 'healthcare', 'wellness', 'healthpackage', 'savings'],
            'facebook' => ['offer', 'discount', 'specialoffer', 'deals', 'healthcare'],
            'linkedin' => ['offer', 'healthcare', 'wellness', 'corporatewellness', 'healthpackages'],
            'tiktok' => ['offer', 'discount', 'deal', 'savings', 'limitedtime'],
            'youtube' => ['special offer', 'discount', 'healthcare offer', 'wellness package', 'health deal'],
            'twitter' => ['offer', 'discount', 'deal', 'healthcare'],
        ],
        'general' => [
            'instagram' => ['health', 'healthcare', 'wellness', 'healthylifestyle', 'medical', 'healthtips', 'selfcare', 'wellbeing', 'healthy', 'lifestyle', 'healthyliving', 'motivation', 'fitness', 'care'],
            'facebook' => ['health', 'healthcare', 'wellness', 'healthtips', 'lifestyle'],
            'linkedin' => ['healthcare', 'wellness', 'healthtech', 'medical', 'healthindustry'],
            'tiktok' => ['health', 'healthcare', 'wellness', 'healthtips', 'medical'],
            'youtube' => ['health', 'healthcare', 'wellness', 'health tips', 'medical advice', 'healthy lifestyle'],
            'twitter' => ['health', 'healthcare', 'wellness', 'healthtips'],
        ],
    ];

    /**
     * Maximum recommended hashtags per platform
     */
    protected const MAX_HASHTAGS = [
        'instagram' => 30,
        'facebook' => 5,
        'linkedin' => 5,
        'tiktok' => 5,
        'youtube' => 15,
        'twitter' => 3,
        'snapchat' => 0,
    ];

    /**
     * Optimal hashtag counts per platform
     */
    protected const OPTIMAL_HASHTAGS = [
        'instagram' => 10,
        'facebook' => 3,
        'linkedin' => 3,
        'tiktok' => 4,
        'youtube' => 8,
        'twitter' => 2,
        'snapchat' => 0,
    ];

    /**
     * Get hashtags for a category and platform.
     */
    public function getHashtags(
        string $category,
        string $platform,
        int $maxCount = 0
    ): array {
        $categoryLower = strtolower($category);
        $platformLower = strtolower($platform);

        // Get category-specific hashtags or fallback to general
        $categoryHashtags = self::SEED_HASHTAGS[$categoryLower] ?? self::SEED_HASHTAGS['general'];
        $hashtags = $categoryHashtags[$platformLower] ?? $categoryHashtags['instagram'] ?? [];

        // If no specific limit, use optimal count for platform
        $limit = $maxCount > 0 ? $maxCount : (self::OPTIMAL_HASHTAGS[$platformLower] ?? 10);

        // Shuffle for variety and limit
        $shuffled = $hashtags;
        shuffle($shuffled);

        return array_slice($shuffled, 0, $limit);
    }

    /**
     * Get hashtags formatted as a string with # prefix.
     */
    public function getHashtagsFormatted(
        string $category,
        string $platform,
        int $maxCount = 0
    ): string {
        $hashtags = $this->getHashtags($category, $platform, $maxCount);
        return implode(' ', array_map(fn($tag) => '#' . $tag, $hashtags));
    }

    /**
     * Get maximum hashtags for a platform.
     */
    public function getMaxHashtags(string $platform): int
    {
        return self::MAX_HASHTAGS[strtolower($platform)] ?? 10;
    }

    /**
     * Get optimal hashtag count for a platform.
     */
    public function getOptimalCount(string $platform): int
    {
        return self::OPTIMAL_HASHTAGS[strtolower($platform)] ?? 10;
    }

    /**
     * Get hashtag strategy recommendations for a platform.
     */
    public function getHashtagStrategy(string $platform): array
    {
        $strategies = [
            'instagram' => [
                'max' => 30,
                'optimal' => 10,
                'strategy' => 'Mix popular (100K-1M), niche (10K-100K), and branded hashtags. Place in caption or first comment.',
                'tips' => [
                    'Use 3-4 high-volume hashtags for reach',
                    'Use 4-5 niche hashtags for targeted audience',
                    '1-2 branded hashtags for identity',
                    'Avoid banned or spam hashtags',
                    'Rotate hashtags to avoid shadowban',
                ],
            ],
            'facebook' => [
                'max' => 5,
                'optimal' => 3,
                'strategy' => 'Less is more. Use 2-3 relevant hashtags only. Over-hashtagging looks spammy.',
                'tips' => [
                    'Keep it simple — 2-3 hashtags max',
                    'Use broad, popular hashtags',
                    'Focus on content quality over hashtags',
                ],
            ],
            'linkedin' => [
                'max' => 5,
                'optimal' => 3,
                'strategy' => 'Professional, industry-specific hashtags. 3-5 max. Place at the end of the post.',
                'tips' => [
                    'Use industry-specific hashtags',
                    'Include trending professional topics',
                    'Keep it professional — no trendy tags',
                ],
            ],
            'tiktok' => [
                'max' => 5,
                'optimal' => 4,
                'strategy' => 'Mix trending sounds + niche hashtags. Keep it short and trendy.',
                'tips' => [
                    'Always include a trending hashtag if relevant',
                    'Use FYP, viral, trending sparingly',
                    'Niche hashtags help reach your target audience',
                ],
            ],
            'youtube' => [
                'max' => 15,
                'optimal' => 8,
                'strategy' => 'SEO-focused hashtags. Include in title (up to 3) and description (5-15).',
                'tips' => [
                    'First 3 hashtags appear above title',
                    'Use exact-match keywords as hashtags',
                    'Include location hashtags for local SEO',
                ],
            ],
            'twitter' => [
                'max' => 3,
                'optimal' => 2,
                'strategy' => 'Maximum 2 hashtags per tweet. Integrate naturally into the text.',
                'tips' => [
                    '1-2 hashtags is optimal',
                    'More than 2 reduces engagement',
                    'Integrate into sentence when possible',
                ],
            ],
        ];

        return $strategies[strtolower($platform)] ?? $strategies['instagram'];
    }

    /**
     * Get all available categories.
     */
    public function getCategories(): array
    {
        return array_keys(self::SEED_HASHTAGS);
    }

    /**
     * Suggest hashtags based on caption content.
     */
    public function suggestFromCaption(string $caption, string $platform): array
    {
        $captionLower = strtolower($caption);
        $matchedCategory = 'general';

        // Simple keyword matching to detect category
        $categoryKeywords = [
            'hydrafacial' => ['hydrafacial', 'facial', 'glow', 'skin treatment', 'skincare'],
            'laser_hair_removal' => ['laser', 'hair removal', 'smooth skin', 'hairfree'],
            'laboratory' => ['lab', 'blood test', 'cbc', 'thyroid', 'test result'],
            'xray' => ['x-ray', 'xray', 'bone', 'radiology'],
            'ultrasound_echo' => ['ultrasound', 'echo', 'cardiac', 'heart'],
            'ecg' => ['ecg', 'heart test', 'electrocardiogram'],
            'team' => ['team', 'staff', 'doctor', 'meet our'],
            'facility' => ['clinic', 'facility', 'tour', 'equipment'],
            'promotional' => ['offer', 'discount', 'sale', 'limited', 'special'],
            'opd' => ['opd', 'consultation', 'checkup', 'doctor visit'],
        ];

        foreach ($categoryKeywords as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($captionLower, $keyword)) {
                    $matchedCategory = $category;
                    break 2;
                }
            }
        }

        return [
            'detected_category' => $matchedCategory,
            'hashtags' => $this->getHashtags($matchedCategory, $platform),
            'formatted' => $this->getHashtagsFormatted($matchedCategory, $platform),
        ];
    }

    /**
     * Research hashtags for a given topic (wrapper for AI API compatibility).
     */
    public function research(string $topic, string $platform = 'instagram'): array
    {
        // If topic matches a known category, use that
        $categories = array_keys(self::SEED_HASHTAGS);
        $topicLower = strtolower($topic);

        $matchedCategory = 'general';
        foreach ($categories as $category) {
            if (str_contains($topicLower, str_replace('_', ' ', $category)) ||
                str_contains(str_replace('_', ' ', $category), $topicLower)) {
                $matchedCategory = $category;
                break;
            }
        }

        $hashtags = $this->getHashtags($matchedCategory, $platform);
        $strategy = $this->getHashtagStrategy($platform);

        return [
            'success'  => true,
            'topic'    => $topic,
            'category' => $matchedCategory,
            'hashtags' => $hashtags,
            'formatted' => $this->getHashtagsFormatted($matchedCategory, $platform),
            'strategy' => $strategy,
            'optimal_count' => $this->getOptimalCount($platform),
        ];
    }
}
