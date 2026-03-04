<?php

namespace App\Services;

use App\Models\PromotionalPackage;
use App\Models\Business;

/**
 * Package Brain Service — proposes service bundles and seasonal promotions.
 *
 * Marketing Psychology Applied:
 * - Charm Pricing: Rs 1,999 instead of Rs 2,000 (left-digit effect)
 * - Anchoring: Show original price first, then discounted
 * - Decoy Effect: 3 tiers where middle tier is the obvious best value
 * - Loss Aversion: Frame as "don't miss" instead of "get this"
 * - Bundle Framing: "Save Rs X when you bundle" vs individual prices
 */
class PackageBrainService
{
    private int $businessId;
    private ?OpenAIService $openai = null;

    // Health awareness calendar by month
    private const HEALTH_CALENDAR = [
        1  => 'New Year wellness resolutions, cervical cancer awareness',
        2  => 'Heart health month, American Heart Month',
        3  => 'Colorectal cancer awareness, nutrition month',
        4  => 'Autism awareness, alcohol awareness',
        5  => 'Mental health awareness month, skin cancer awareness',
        6  => 'Men\'s health month, migraine awareness',
        7  => 'UV safety month, juvenile arthritis awareness',
        8  => 'Immunization awareness, psoriasis awareness',
        9  => 'Prostate cancer awareness, childhood cancer awareness',
        10 => 'Breast cancer awareness, mental health week',
        11 => 'Diabetes awareness month, lung cancer awareness',
        12 => 'AIDS awareness, safe toys and gifts month',
    ];

    // Psychology-based pricing templates
    private const PRICING_PSYCHOLOGY = [
        'charm_endings'  => [99, 95, 97, 49, 79],
        'anchor_discount' => 0.20, // Show 20% savings
        'decoy_markup'    => 1.15, // High tier is 15% more than ideal
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PACKAGE GENERATION
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate promotional package proposals.
     */
    public function generatePackageProposals(
        array $recentCategories = [],
        string $customContext = ''
    ): array {
        $openai = $this->getOpenAI();
        $business = Business::find($this->businessId);

        $month = now()->month;
        $monthName = now()->format('F');
        $healthEvents = self::HEALTH_CALENDAR[$month] ?? '';

        if ($openai && $openai->isConfigured() && $business) {
            $prompt = $this->buildPrompt($business, $monthName, $healthEvents, $recentCategories, $customContext);
            $result = $openai->chatCompletion($prompt, 'package_brain', 'generate');

            if ($result['success']) {
                $parsed = json_decode($result['content'], true);

                if (is_array($parsed)) {
                    return [
                        'success'  => true,
                        'packages' => $this->applyPricingPsychology($parsed),
                    ];
                }
            }
        }

        // Fallback to template-based packages
        return [
            'success'  => true,
            'packages' => $this->generateTemplatePackages($monthName, $healthEvents),
        ];
    }

    /**
     * Save a package proposal.
     */
    public function savePackage(array $package): PromotionalPackage
    {
        return PromotionalPackage::create([
            'business_id'       => $this->businessId,
            'name'              => $package['name'],
            'tagline'           => $package['tagline'] ?? null,
            'description'       => $package['description'] ?? '',
            'services_included' => $package['services_included'] ?? [],
            'discount_details'  => $package['discount_details'] ?? null,
            'target_audience'   => $package['target_audience'] ?? null,
            'occasion'          => $package['occasion'] ?? null,
            'suggested_price'   => $package['suggested_price'] ?? null,
            'content_ideas'     => $package['content_ideas'] ?? [],
            'status'            => 'proposed',
        ]);
    }

    /**
     * Get proposed packages for review.
     */
    public function getProposedPackages(): array
    {
        return PromotionalPackage::forBusiness($this->businessId)
            ->proposed()
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    /**
     * Approve a package.
     */
    public function approvePackage(int $packageId): bool
    {
        $package = PromotionalPackage::forBusiness($this->businessId)->find($packageId);
        if ($package) {
            $package->approve();
            return true;
        }
        return false;
    }

    /**
     * Deny a package.
     */
    public function denyPackage(int $packageId): bool
    {
        $package = PromotionalPackage::forBusiness($this->businessId)->find($packageId);
        if ($package) {
            $package->deny();
            return true;
        }
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRICING PSYCHOLOGY
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Apply charm pricing to a value.
     */
    public function applyCharmPricing(float $price): string
    {
        // Round to nearest charm ending
        $base = floor($price / 100) * 100;
        $charm = 99;

        // Use appropriate charm ending based on price level
        if ($price > 10000) {
            $charm = 999;
            $base = floor($price / 1000) * 1000;
        } elseif ($price > 1000) {
            $charm = 99;
            $base = floor($price / 100) * 100;
        } else {
            $charm = 9;
            $base = floor($price / 10) * 10;
        }

        return number_format($base + $charm - 100 + $charm, 0);
    }

    /**
     * Create anchored pricing display.
     */
    public function createAnchoredPricing(float $bundlePrice, float $individualTotal): array
    {
        $savings = $individualTotal - $bundlePrice;
        $savingsPercent = round(($savings / $individualTotal) * 100);

        return [
            'original_price'   => $this->applyCharmPricing($individualTotal),
            'bundle_price'     => $this->applyCharmPricing($bundlePrice),
            'you_save'         => $this->applyCharmPricing($savings),
            'savings_percent'  => $savingsPercent,
            'display'          => "~~{$this->applyCharmPricing($individualTotal)}~~ **{$this->applyCharmPricing($bundlePrice)}** (Save {$savingsPercent}%)",
        ];
    }

    /**
     * Create decoy pricing (3-tier).
     */
    public function createDecoyPricing(string $serviceName, float $basePrice): array
    {
        $markup = self::PRICING_PSYCHOLOGY['decoy_markup'];

        return [
            'basic' => [
                'name'  => "{$serviceName} Basic",
                'price' => $this->applyCharmPricing($basePrice * 0.7),
                'features' => ['Core service only'],
                'is_decoy' => true, // Deliberately weak
            ],
            'standard' => [
                'name'  => "{$serviceName} Complete",
                'price' => $this->applyCharmPricing($basePrice),
                'features' => ['Full service', 'Follow-up included', 'Priority booking'],
                'is_decoy' => false, // This is the target
                'recommended' => true,
            ],
            'premium' => [
                'name'  => "{$serviceName} Premium",
                'price' => $this->applyCharmPricing($basePrice * $markup),
                'features' => ['Full service', 'All follow-ups', 'VIP treatment', 'Extra consultation'],
                'is_decoy' => true, // Too expensive on purpose
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function buildPrompt(
        Business $business,
        string $monthName,
        string $healthEvents,
        array $recentCategories,
        string $customContext
    ): string {
        $recent = !empty($recentCategories)
            ? "Recent content categories (avoid over-promoting): " . implode(', ', $recentCategories)
            : '';

        return <<<PROMPT
You are the promotional strategist for {$business->name} ({$business->industry}).

Today's date: {$monthName} {now()->day}, {now()->year}
Relevant health awareness events: {$healthEvents}
{$recent}
{$customContext}

╔═══ PRICING PSYCHOLOGY (apply to EVERY package) ═══╗

1. CHARM PRICING: End prices in 9 (Rs 1,999 not Rs 2,000)
2. ANCHORING: Show "individual price" first (higher), then bundle price
3. DECOY EFFECT: When possible, propose 3 tiers with middle as best value
4. LOSS AVERSION: Frame urgency as what they'll LOSE
5. BUNDLE FRAMING: Show "You save Rs X" explicitly
6. SCARCITY: Add ethical urgency — "Limited to 50 slots this month"

╚═══════════════════════════════════════════════════╝

Propose 2-3 promotional packages that are timely and appealing.
For each package, provide:
- name: catchy package name
- tagline: one-liner marketing hook
- services_included: list of specific services in bundle
- discount_details: what discount or value-add to offer
- target_audience: who this package is for
- occasion: what event/season this ties to (if any)
- suggested_price: ballpark price with charm pricing
- content_ideas: 3-4 specific social media post ideas

Return a JSON array of package objects.
PROMPT;
    }

    private function applyPricingPsychology(array $packages): array
    {
        foreach ($packages as &$package) {
            // Ensure charm pricing on suggested_price
            if (!empty($package['suggested_price'])) {
                $price = preg_replace('/[^0-9.]/', '', $package['suggested_price']);
                if ($price) {
                    $package['suggested_price'] = 'Rs ' . $this->applyCharmPricing((float)$price);
                }
            }

            // Add urgency framing if not present
            if (empty($package['scarcity_message'])) {
                $package['scarcity_message'] = 'Limited slots available — book this ' . now()->format('F') . '!';
            }
        }

        return $packages;
    }

    private function generateTemplatePackages(string $monthName, string $healthEvents): array
    {
        return [
            [
                'name'              => "{$monthName} Wellness Package",
                'tagline'           => 'Start your wellness journey this season',
                'services_included' => ['Health consultation', 'Basic checkup', 'Follow-up call'],
                'discount_details'  => '20% off when you book this month',
                'target_audience'   => 'Health-conscious individuals',
                'occasion'          => $healthEvents ?: "{$monthName} special",
                'suggested_price'   => 'Rs 2,999',
                'content_ideas'     => [
                    "🏥 {$monthName} is here! Time to prioritize your health.",
                    "Limited wellness packages available — don't miss out!",
                    "What's included in our {$monthName} package? Let us show you.",
                ],
                'scarcity_message'  => 'Only 50 packages available this month!',
            ],
            [
                'name'              => 'Family Care Bundle',
                'tagline'           => 'Because family health matters most',
                'services_included' => ['Family consultation', 'Health screening x4', 'Report analysis'],
                'discount_details'  => 'Save Rs 2,000 vs individual bookings',
                'target_audience'   => 'Families with children',
                'occasion'          => 'Family wellness',
                'suggested_price'   => 'Rs 7,999',
                'content_ideas'     => [
                    "👨‍👩‍👧‍👦 Family health check made affordable!",
                    "Book together, save together — Family Care Bundle.",
                    "Protect your loved ones with our comprehensive family package.",
                ],
                'scarcity_message'  => 'Weekend slots filling fast!',
            ],
        ];
    }

    private function getOpenAI(): ?OpenAIService
    {
        if ($this->openai === null) {
            $this->openai = new OpenAIService($this->businessId);
        }
        return $this->openai;
    }
}
