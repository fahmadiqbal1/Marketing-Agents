<?php

namespace App\Services;

use App\Models\AiModelConfig;
use App\Models\BotTrainingPair;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrator Service — the central brain of the Marketing Agents platform.
 *
 * The Orchestrator is a smart router and coordinator that:
 * - Selects the best AI model for each task type
 * - Learns marketing, designing, and agent-management skills from interactions
 * - Does NOT dump everything from each model; it only extracts reusable capability insights
 * - Maintains a skill profile that grows over time (self-learning)
 * - Coordinates all platform sub-agents by decomposing high-level goals into tasks
 */
class OrchestratorService
{
    protected int $businessId;

    // Skill domains the orchestrator focuses on
    public const SKILL_DOMAINS = [
        'marketing'         => 'Marketing strategy, audience targeting, funnel optimisation',
        'copywriting'       => 'Caption writing, headlines, calls-to-action, tone',
        'designing'         => 'Visual direction, color, layout, image prompts',
        'seo'               => 'Keywords, metadata, discoverability',
        'growth'            => 'Engagement tactics, viral hooks, A/B insights',
        'agent_management'  => 'Task routing, model selection, workflow orchestration',
        'analytics'         => 'Performance interpretation, trend recognition',
    ];

    /** UI colour for each skill domain — single source of truth for both backend and frontend. */
    public const DOMAIN_COLORS = [
        'platform_specific' => '#f59e0b',
        'marketing'         => '#10b981',
        'copywriting'       => '#7c3aed',
        'designing'         => '#ec4899',
        'seo'               => '#0ea5e9',
        'growth'            => '#f97316',
        'agent_management'  => '#a78bfa',
        'analytics'         => '#6366f1',
    ];

    /** Maximum characters stored per skill insight. */
    protected const MAX_INSIGHT_LENGTH = 2000;

    /** Maximum skill log entries returned in a profile query. */
    protected const MAX_SKILL_LOG_ROWS = 200;

    /** Default token budget for orchestrator chat responses. */
    protected const CHAT_MAX_TOKENS = 1500;

    // Task → best provider mapping hints (overridden by learned skills)
    protected const TASK_ROUTING_HINTS = [
        'caption'      => ['openai', 'anthropic', 'google_gemini'],
        'hashtag'      => ['groq', 'mistral', 'openai'],
        'strategy'     => ['anthropic', 'openai', 'google_gemini'],
        'image_prompt' => ['openai', 'anthropic'],
        'seo'          => ['google_gemini', 'openai', 'mistral'],
        'code'         => ['deepseek', 'openai', 'groq'],
        'general'      => ['openai', 'google_gemini', 'anthropic', 'mistral', 'groq'],
        'local'        => ['ollama', 'openai_compatible'],
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // =========================================================================
    // ORCHESTRATOR CONFIG
    // =========================================================================

    /**
     * Get the orchestrator AI model config (is_orchestrator = true).
     * Falls back to the default model, then to any active model.
     */
    public function getOrchestratorModel(): ?AiModelConfig
    {
        return AiModelConfig::where('business_id', $this->businessId)
            ->where('is_orchestrator', true)
            ->where('is_active', true)
            ->first()
            ?? AiModelConfig::where('business_id', $this->businessId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first()
            ?? AiModelConfig::where('business_id', $this->businessId)
                ->where('is_active', true)
                ->first();
    }

    /**
     * Set a specific AI model as the orchestrator.
     */
    public function setOrchestrator(int $modelId): bool
    {
        // Clear existing orchestrator flags
        AiModelConfig::where('business_id', $this->businessId)
            ->update(['is_orchestrator' => false]);

        $updated = AiModelConfig::where('business_id', $this->businessId)
            ->where('id', $modelId)
            ->update(['is_orchestrator' => true, 'is_active' => true]);

        return $updated > 0;
    }

    // =========================================================================
    // TASK ROUTING
    // =========================================================================

    /**
     * Route a task to the most suitable configured AI model.
     * Priority: explicit task-type hints → learned skills → first active model.
     */
    public function routeTask(string $taskType): ?AiModelConfig
    {
        $activeModels = AiModelConfig::where('business_id', $this->businessId)
            ->where('is_active', true)
            ->where('last_test_status', 'ok')
            ->get()
            ->keyBy('provider');

        if ($activeModels->isEmpty()) {
            return null;
        }

        // Check routing hints
        $hints = self::TASK_ROUTING_HINTS[$taskType] ?? self::TASK_ROUTING_HINTS['general'];
        foreach ($hints as $provider) {
            if ($activeModels->has($provider)) {
                return $activeModels->get($provider);
            }
        }

        return $activeModels->first();
    }

    /**
     * Get a summary of all available models and their best use-cases.
     */
    public function getModelCapabilitiesMap(): array
    {
        $models = AiModelConfig::where('business_id', $this->businessId)
            ->where('is_active', true)
            ->get();

        $map = [];
        foreach ($models as $m) {
            $map[] = [
                'id'           => $m->id,
                'provider'     => $m->provider,
                'display_name' => $m->display_name ?: $m->provider,
                'model_name'   => $m->model_name,
                'status'       => $m->status,
                'best_for'     => $this->getProviderStrengths($m->provider),
                'is_orchestrator' => $m->is_orchestrator,
            ];
        }

        return $map;
    }

    // =========================================================================
    // SKILL LEARNING
    // =========================================================================

    /**
     * Record a new insight/skill the orchestrator learned from an interaction.
     * Keeps the skill log lean — only high-value, actionable marketing insights.
     */
    public function learnSkill(string $domain, string $insight, string $sourceProvider, int $confidence = 70): void
    {
        if (! array_key_exists($domain, self::SKILL_DOMAINS)) {
            return; // Only learn within defined domains
        }

        try {
            \DB::table('orchestrator_skill_logs')->insert([
                'business_id'     => $this->businessId,
                'skill_domain'    => $domain,
                'source_provider' => $sourceProvider,
                'insight'         => substr($insight, 0, self::MAX_INSIGHT_LENGTH),
                'confidence'      => min(100, max(0, $confidence)),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('OrchestratorService: could not store skill', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Retrieve the orchestrator's current skill profile.
     */
    public function getSkillProfile(): array
    {
        try {
            $rows = \DB::table('orchestrator_skill_logs')
                ->where('business_id', $this->businessId)
                ->orderByDesc('confidence')
                ->orderByDesc('created_at')
                ->limit(self::MAX_SKILL_LOG_ROWS)
                ->get();

            $profile = [];
            foreach (array_keys(self::SKILL_DOMAINS) as $domain) {
                $domainInsights = $rows->where('skill_domain', $domain)->values();
                $profile[$domain] = [
                    'description' => self::SKILL_DOMAINS[$domain],
                    'insight_count' => $domainInsights->count(),
                    'top_insights'  => $domainInsights->take(5)->pluck('insight')->toArray(),
                    'avg_confidence' => $domainInsights->isNotEmpty()
                        ? (int) round($domainInsights->avg('confidence'))
                        : 0,
                ];
            }

            return $profile;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Build a system prompt for the orchestrator that includes its learned skills
     * and the available sub-agent roster.
     */
    public function buildOrchestratorSystemPrompt(): string
    {
        $skills = $this->getSkillProfile();
        $models = $this->getModelCapabilitiesMap();

        $skillSummary = '';
        foreach ($skills as $domain => $info) {
            if ($info['insight_count'] > 0) {
                $skillSummary .= "\n- **{$domain}** ({$info['insight_count']} insights, confidence {$info['avg_confidence']}%): " .
                    implode('; ', array_slice($info['top_insights'], 0, 2));
            }
        }

        $modelList = '';
        foreach ($models as $m) {
            $modelList .= "\n- {$m['display_name']} ({$m['provider']}): best for " . implode(', ', $m['best_for']);
        }

        return <<<PROMPT
You are the Marketing Orchestrator — the central intelligence of a multi-agent marketing automation platform.

Your role:
1. Understand high-level marketing goals and decompose them into concrete tasks.
2. Route each task to the best available AI sub-agent based on their strengths.
3. Synthesize results into a cohesive marketing strategy.
4. Continuously learn and refine your marketing, designing, and copywriting skills.
5. Never extract or store irrelevant technical information — focus only on marketing value.

Available AI sub-agents:{$modelList}

Your learned skill profile:{$skillSummary}

When given a goal, respond with:
- A brief strategic plan (2-3 sentences)
- A numbered list of tasks, each tagged with which sub-agent should handle it
- Any immediate insights or recommendations

Stay focused on marketing, content creation, audience growth, and brand building.
PROMPT;
    }

    // =========================================================================
    // SKILL TRANSFER TO SUB-AGENTS
    // =========================================================================

    /**
     * Platform-specific skill relevance map.
     * Keys are platform names; values are arrays of skill domain + capability keywords
     * that are most relevant to that platform's sub-agent.
     */
    public const PLATFORM_SKILL_MAP = [
        'instagram' => [
            'domains'      => ['designing', 'copywriting', 'growth'],
            'capabilities' => [
                'photo_editing'     => 'Photo enhancement & filter techniques for high-engagement visuals',
                'stories'           => 'Instagram Stories creation: polls, stickers, countdowns, swipe-up CTAs',
                'collage'           => 'Multi-photo collage and carousel layout design',
                'reels'             => 'Short-form vertical video editing for Reels (hooks, cuts, music sync)',
                'hashtag_strategy'  => 'Hashtag clustering and niche/micro-hashtag targeting',
                'caption_hooks'     => 'Opening hooks and caption structures that stop the scroll',
            ],
        ],
        'tiktok' => [
            'domains'      => ['designing', 'growth', 'copywriting'],
            'capabilities' => [
                'trending_music'    => 'Finding and selecting trending audio/music on TikTok For You Page',
                'video_editing'     => 'Jump-cut editing, transitions, and text-overlay techniques for viral short videos',
                'hooks'             => 'First-3-second hook formulas proven to retain viewers',
                'duet_stitch'       => 'Duet and Stitch strategies for riding trending content',
                'fyp_algorithm'     => 'TikTok FYP optimisation: completion rate, replays, saves tactics',
                'sound_sync'        => 'Beat-sync editing to match video cuts with audio beats',
            ],
        ],
        'youtube' => [
            'domains'      => ['designing', 'seo', 'copywriting'],
            'capabilities' => [
                'thumbnail_design'  => 'High-CTR thumbnail design: contrast, faces, bold text, emotional expression',
                'seo_titles'        => 'YouTube SEO: keyword-rich titles, descriptions, and chapter timestamps',
                'video_editing'     => 'Long-form video structure: hook (0-30s), value delivery, retention loops',
                'end_screens'       => 'End-screen card placement and subscribe CTA positioning',
                'shorts'            => 'YouTube Shorts repurposing from long-form content',
            ],
        ],
        'facebook' => [
            'domains'      => ['copywriting', 'marketing', 'growth'],
            'capabilities' => [
                'ad_copy'           => 'Facebook ad copywriting: attention-interest-desire-action (AIDA) structure',
                'audience_targeting'=> 'Custom audience creation and lookalike audience strategies',
                'video_captions'    => 'Auto-caption and subtitle optimisation for silent video viewers',
                'group_engagement'  => 'Facebook Group posting strategies for organic community growth',
            ],
        ],
        'linkedin' => [
            'domains'      => ['copywriting', 'marketing', 'seo'],
            'capabilities' => [
                'thought_leadership'=> 'Long-form article structure and thought-leadership positioning',
                'document_posts'    => 'LinkedIn carousel/document post creation for authority building',
                'connection_hooks'  => 'Opening line formulas that generate profile visits and connection requests',
                'seo_profile'       => 'LinkedIn profile and post keyword optimisation for discoverability',
            ],
        ],
        'twitter' => [
            'domains'      => ['copywriting', 'growth'],
            'capabilities' => [
                'thread_structure'  => 'Thread writing: strong opener, numbered points, call-to-action finale',
                'engagement_bait'   => 'Ethical engagement tactics: polls, questions, hot-takes',
                'timing'            => 'Optimal tweet timing and reply-chain engagement strategies',
            ],
        ],
        'pinterest' => [
            'domains'      => ['designing', 'seo'],
            'capabilities' => [
                'pin_design'        => 'Vertical pin design (2:3 ratio), typography, and color psychology',
                'seo_keywords'      => 'Pinterest keyword research and board SEO optimisation',
                'rich_pins'         => 'Rich Pin metadata setup for product and recipe pins',
            ],
        ],
        'snapchat' => [
            'domains'      => ['designing', 'copywriting'],
            'capabilities' => [
                'story_design'      => 'Ephemeral Story design with bold visuals and instant-value hooks',
                'ar_filters'        => 'AR lens and filter strategy for brand awareness',
                'spotlight'         => "Spotlight content optimisation for Snapchat's discovery feed",
            ],
        ],
        'threads' => [
            'domains'      => ['copywriting', 'growth'],
            'capabilities' => [
                'conversation_hooks'=> 'Threads post openers designed to spark replies and reposts',
                'cross_promote'     => 'Instagram cross-promotion strategy linking Threads activity',
            ],
        ],
        'google_my_business' => [
            'domains'      => ['seo', 'marketing'],
            'capabilities' => [
                'local_seo'         => 'Google Maps ranking factors: review velocity, keyword-rich updates',
                'post_formats'      => 'GMB post types: offers, events, product announcements',
            ],
        ],
        'telegram' => [
            'domains'      => ['copywriting', 'growth'],
            'capabilities' => [
                'bot_commands'      => 'Telegram bot command design for subscriber engagement',
                'broadcast_copy'    => 'Broadcast message copy that drives clicks without feeling spammy',
            ],
        ],
    ];

    /**
     * Determine which skills from the Orchestrator's profile are most relevant
     * for a given platform sub-agent, using the PLATFORM_SKILL_MAP.
     *
     * @return array<array{title: string, description: string, domain: string, confidence: int}>
     */
    public function getSkillsForAgent(string $platform): array
    {
        $platformKey = strtolower(str_replace([' ', '-'], '_', $platform));
        $platformDef = self::PLATFORM_SKILL_MAP[$platformKey] ?? null;

        // Always include platform-specific built-in capabilities
        $capabilities = [];
        if ($platformDef) {
            foreach ($platformDef['capabilities'] as $capKey => $capDesc) {
                $capabilities[] = [
                    'title'       => $capKey,
                    'description' => $capDesc,
                    'domain'      => 'platform_specific',
                    'confidence'  => 85,
                    'source'      => 'orchestrator_map',
                ];
            }
        }

        // Layer in learned skill-log insights that match the platform's relevant domains
        $relevantDomains = $platformDef['domains'] ?? array_keys(self::SKILL_DOMAINS);
        try {
            $rows = \DB::table('orchestrator_skill_logs')
                ->where('business_id', $this->businessId)
                ->whereIn('skill_domain', $relevantDomains)
                ->orderByDesc('confidence')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            foreach ($rows as $row) {
                // Generate a descriptive title from the first ~50 chars of the insight text
                $shortTitle = rtrim(substr(preg_replace('/\s+/', ' ', $row->insight), 0, 50));
                if (strlen($row->insight) > 50) {
                    $shortTitle .= '…';
                }
                $capabilities[] = [
                    'title'       => $row->skill_domain . ': ' . $shortTitle,
                    'description' => $row->insight,
                    'domain'      => $row->skill_domain,
                    'confidence'  => $row->confidence,
                    'source'      => $row->source_provider ?? 'orchestrator',
                ];
            }
        } catch (\Exception $e) {
            Log::warning('OrchestratorService::getSkillsForAgent failed to load skill logs', ['error' => $e->getMessage()]);
        }

        return $capabilities;
    }

    /**
     * Transfer (inject) relevant Orchestrator skills into the given platform sub-agent.
     * Merges new skills with any existing injected_skills; deduplicates by title.
     *
     * @return array{success: bool, platform: string, skills_injected: int, message: string}
     */
    public function transferSkillsToAgent(string $platform): array
    {
        $skills = $this->getSkillsForAgent($platform);

        if (empty($skills)) {
            return [
                'success'         => false,
                'platform'        => $platform,
                'skills_injected' => 0,
                'message'         => "No skills available to transfer to the {$platform} agent.",
            ];
        }

        try {
            $agent = \App\Models\PlatformAgent::firstOrCreate(
                ['business_id' => $this->businessId, 'platform' => $platform],
                ['is_active' => true, 'agent_type' => 'social']
            );

            // Merge and deduplicate by title
            $existing  = $agent->injected_skills ?? [];
            $existingTitles = array_flip(array_column($existing, 'title'));
            $added     = 0;
            foreach ($skills as $skill) {
                if (! isset($existingTitles[$skill['title']])) {
                    $existing[] = $skill;
                    $added++;
                }
            }

            $agent->update([
                'injected_skills' => $existing,
                'last_learned_at' => now(),
            ]);

            // Log this as an orchestrator skill-management action
            $this->learnSkill(
                'agent_management',
                "Transferred {$added} skills to {$platform} agent",
                'orchestrator',
                80
            );

            return [
                'success'         => true,
                'platform'        => $platform,
                'skills_injected' => $added,
                'total_skills'    => count($existing),
                'message'         => "Successfully injected {$added} new skill(s) into the {$platform} agent. Total: " . count($existing) . " capabilities.",
            ];
        } catch (\Exception $e) {
            Log::error('OrchestratorService::transferSkillsToAgent failed', [
                'platform' => $platform,
                'error'    => $e->getMessage(),
            ]);
            return [
                'success'         => false,
                'platform'        => $platform,
                'skills_injected' => 0,
                'message'         => 'Transfer failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Transfer skills to ALL known platforms in one go.
     *
     * @return array<string, array>
     */
    public function transferSkillsToAllAgents(): array
    {
        $results = [];
        foreach (array_keys(self::PLATFORM_SKILL_MAP) as $platform) {
            $results[$platform] = $this->transferSkillsToAgent($platform);
        }
        return $results;
    }

    /**
     * Clear all injected skills from a given agent (reset).
     */
    public function clearAgentSkills(string $platform): bool
    {
        try {
            \App\Models\PlatformAgent::where('business_id', $this->businessId)
                ->where('platform', $platform)
                ->update(['injected_skills' => null]);
            return true;
        } catch (\Exception $e) {
            Log::warning('OrchestratorService::clearAgentSkills failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get a summary of how many skills each agent currently holds.
     *
     * @return array<string, int>
     */
    public function getAgentSkillCounts(): array
    {
        try {
            $agents = \App\Models\PlatformAgent::where('business_id', $this->businessId)
                ->get(['platform', 'injected_skills']);
            $counts = [];
            foreach ($agents as $agent) {
                $counts[$agent->platform] = count($agent->injected_skills ?? []);
            }
            return $counts;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Ask the orchestrator to plan tasks for a given marketing goal.
     * The orchestrator uses its configured AI model + skill profile.
     */
    public function planTasks(string $goal): array
    {
        $model = $this->getOrchestratorModel();
        if (! $model) {
            return [
                'success' => false,
                'message' => 'No AI model configured. Please add at least one AI model on the Platforms page.',
                'plan'    => null,
            ];
        }

        $systemPrompt = $this->buildOrchestratorSystemPrompt();

        try {
            $response = $this->callAnyProvider($model, $systemPrompt, "Marketing goal: {$goal}");

            // Opportunistically learn from this interaction
            $this->learnSkill(
                'agent_management',
                "Planned tasks for goal: " . substr($goal, 0, 100),
                $model->provider,
                65
            );

            return ['success' => true, 'plan' => $response, 'model_used' => $model->display_name ?: $model->provider];
        } catch (\Exception $e) {
            Log::error('OrchestratorService::planTasks failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Orchestrator error: ' . $e->getMessage(), 'plan' => null];
        }
    }

    /**
     * Generic chat method that works with any configured provider using OpenAI-compatible API format.
     * Covers: openai, openai_compatible, ollama, groq, deepseek, mistral (all support /v1/chat/completions).
     * Falls back to a basic prompt for providers with different APIs.
     */
    protected function callAnyProvider(AiModelConfig $model, string $systemPrompt, string $userMessage): string
    {
        $provider = $model->provider;
        $apiKey   = $model->api_key;
        $modelId  = $model->model_name;
        $baseUrl  = $model->base_url;

        $headers  = ['Content-Type' => 'application/json'];
        $endpoint = '';

        switch ($provider) {
            case 'openai':
                $endpoint = 'https://api.openai.com/v1/chat/completions';
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                $modelId = $modelId ?: 'gpt-4o-mini';
                break;
            case 'groq':
                $endpoint = 'https://api.groq.com/openai/v1/chat/completions';
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                $modelId = $modelId ?: 'llama-3.1-70b-versatile';
                break;
            case 'deepseek':
                $endpoint = 'https://api.deepseek.com/v1/chat/completions';
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                $modelId = $modelId ?: 'deepseek-chat';
                break;
            case 'mistral':
                $endpoint = 'https://api.mistral.ai/v1/chat/completions';
                $headers['Authorization'] = 'Bearer ' . $apiKey;
                $modelId = $modelId ?: 'mistral-large-latest';
                break;
            case 'ollama':
                $base = rtrim($baseUrl ?: 'http://localhost:11434', '/');
                $endpoint = $base . '/v1/chat/completions';
                $modelId = $modelId ?: 'llama3';
                break;
            case 'openai_compatible':
                $base = rtrim($baseUrl ?: 'http://localhost:8080', '/');
                $endpoint = $base . '/v1/chat/completions';
                if ($apiKey && $apiKey !== 'local') {
                    $headers['Authorization'] = 'Bearer ' . $apiKey;
                }
                $modelId = $modelId ?: 'default';
                break;
            case 'google_gemini':
                // Gemini uses a different API format — fall back to a simplified stub
                return $this->callGeminiProvider($model, $systemPrompt, $userMessage);
            case 'anthropic':
                return $this->callAnthropicProvider($model, $systemPrompt, $userMessage);
            default:
                throw new \RuntimeException("Provider {$provider} is not yet supported by the Orchestrator for chat. Please configure an OpenAI, Groq, Ollama, or OpenAI-compatible model.");
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->timeout(60)
            ->post($endpoint, [
                'model'    => $modelId,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'max_tokens'  => self::CHAT_MAX_TOKENS,
                'temperature' => 0.7,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("API call failed ({$response->status()}): " . $response->body());
        }

        return $response->json('choices.0.message.content') ?? 'No response received.';
    }

    protected function callGeminiProvider(AiModelConfig $model, string $systemPrompt, string $userMessage): string
    {
        $modelId  = $model->model_name ?: 'gemini-2.0-flash';
        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelId}:generateContent?key={$model->api_key}", [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\n" . $userMessage]]],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Gemini API error ({$response->status()}): " . $response->body());
        }

        return $response->json('candidates.0.content.parts.0.text') ?? 'No response received.';
    }

    protected function callAnthropicProvider(AiModelConfig $model, string $systemPrompt, string $userMessage): string
    {
        $modelId  = $model->model_name ?: 'claude-3-5-sonnet-20241022';
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'x-api-key'         => $model->api_key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
            'model'      => $modelId,
            'max_tokens' => self::CHAT_MAX_TOKENS,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userMessage]],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Anthropic API error ({$response->status()}): " . $response->body());
        }

        return $response->json('content.0.text') ?? 'No response received.';
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function getProviderStrengths(string $provider): array
    {
        $map = [
            'openai'           => ['general writing', 'captions', 'strategy'],
            'google_gemini'    => ['SEO', 'research', 'long-form content'],
            'anthropic'        => ['nuanced writing', 'strategy', 'analysis'],
            'mistral'          => ['fast generation', 'multilingual', 'captions'],
            'deepseek'         => ['reasoning', 'code', 'structured output'],
            'groq'             => ['ultra-fast inference', 'hashtags', 'short copy'],
            'ollama'           => ['private/local', 'general', 'customizable'],
            'openai_compatible' => ['custom endpoint', 'configurable'],
        ];

        return $map[$provider] ?? ['general'];
    }

    /**
     * Status summary for the UI.
     */
    public function getStatus(): array
    {
        $orchestratorModel = $this->getOrchestratorModel();
        $totalModels = AiModelConfig::where('business_id', $this->businessId)->where('is_active', true)->count();
        $verifiedModels = AiModelConfig::where('business_id', $this->businessId)
            ->where('is_active', true)
            ->where('last_test_status', 'ok')
            ->count();

        $skillProfile    = $this->getSkillProfile();
        $totalInsights   = array_sum(array_column($skillProfile, 'insight_count'));
        $agentSkillCounts = $this->getAgentSkillCounts();

        return [
            'has_orchestrator'        => $orchestratorModel !== null,
            'orchestrator_provider'   => $orchestratorModel?->provider,
            'orchestrator_model_name' => $orchestratorModel?->model_name,
            'orchestrator_display'    => $orchestratorModel?->display_name ?: ($orchestratorModel?->provider ?? 'Not configured'),
            'orchestrator_id'         => $orchestratorModel?->id,
            'total_models'            => $totalModels,
            'verified_models'         => $verifiedModels,
            'total_skill_insights'    => $totalInsights,
            'skill_domains'           => array_keys(self::SKILL_DOMAINS),
            'skill_profile'           => $skillProfile,
            'capabilities_map'        => $this->getModelCapabilitiesMap(),
            'agent_skill_counts'      => $agentSkillCounts,
            'platform_skill_map'      => self::PLATFORM_SKILL_MAP,
        ];
    }
}
