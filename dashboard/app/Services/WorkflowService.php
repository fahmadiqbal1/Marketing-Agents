<?php

namespace App\Services;

use App\Models\MediaItem;
use App\Models\Post;
use App\Models\ContentCalendar;
use App\Services\Security\AuditLogService;
use App\Services\Security\PiiRedactorService;
use App\Services\Security\PromptGuardService;
use App\Services\Security\PublishGateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Workflow Orchestrator Service — central workflow connecting all agents.
 *
 * Converted from Python: orchestrator/workflow.py
 *
 * Workflow stages:
 * 1. analyze → Vision analysis of media
 * 2. route → Platform routing (rule-based)
 * 3. edit → Media processing for each platform
 * 4. music → Background music selection (videos only)
 * 5. caption → AI caption and hashtag generation
 * 6. quality_gate → Security + engagement scoring
 * 7. preview → Human-in-the-loop approval
 * 8. publish → Post to approved platforms
 */
class WorkflowService
{
    protected int $businessId;
    protected VisionAnalyzerService $visionAnalyzer;
    protected PlatformRouterService $platformRouter;
    protected MediaEditorService $mediaEditor;
    protected CaptionWriterService $captionWriter;
    protected HashtagResearcherService $hashtagResearcher;
    protected PublisherService $publisher;
    protected GrowthHackerService $growthHacker;
    protected MusicResearcherService $musicResearcher;
    protected ContentMemoryService $contentMemory;
    protected PiiRedactorService $piiRedactor;
    protected PromptGuardService $promptGuard;
    protected AuditLogService $auditLog;
    protected PublishGateService $publishGate;

    /**
     * Workflow state — persisted in the posts table.
     */
    protected array $state = [];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;

        // Initialize all services
        $this->visionAnalyzer = new VisionAnalyzerService($businessId);
        $this->platformRouter = new PlatformRouterService();
        $this->mediaEditor = new MediaEditorService();
        $this->captionWriter = new CaptionWriterService($businessId);
        $this->hashtagResearcher = new HashtagResearcherService();
        $this->publisher = new PublisherService($businessId);
        $this->growthHacker = new GrowthHackerService($businessId);
        $this->musicResearcher = new MusicResearcherService($businessId);
        $this->contentMemory = new ContentMemoryService($businessId);
        $this->piiRedactor = new PiiRedactorService();
        $this->promptGuard = new PromptGuardService();
        $this->auditLog = new AuditLogService();
        $this->publishGate = new PublishGateService($businessId);
    }

    /**
     * Start a new media processing workflow.
     *
     * @param int $mediaItemId The media item to process
     * @return string Thread/workflow ID for tracking
     */
    public function startWorkflow(int $mediaItemId): string
    {
        $mediaItem = MediaItem::findOrFail($mediaItemId);
        $threadId = 'wf_' . $mediaItemId . '_' . Str::random(8);

        $this->state = [
            'thread_id' => $threadId,
            'business_id' => $this->businessId,
            'media_item_id' => $mediaItemId,
            'file_path' => $mediaItem->file_path,
            'media_type' => $mediaItem->media_type,
            'width' => $mediaItem->width ?? 0,
            'height' => $mediaItem->height ?? 0,
            'duration_seconds' => $mediaItem->duration_seconds ?? 0,
            'current_step' => 'analyze',
            'error' => null,
            'analysis' => null,
            'content_category' => null,
            'target_platforms' => [],
            'edited_files' => [],
            'captions' => [],
            'hashtags' => [],
            'music_tracks' => [],
            'engagement_analysis' => [],
            'posting_schedule' => [],
            'approval_status' => 'pending',
            'approved_platforms' => [],
            'denied_platforms' => [],
            'publish_results' => [],
            'accumulation_proposals' => [],
        ];

        // Store workflow state in database
        $this->persistState();

        // Run workflow up to preview stage
        return $this->runToPreview($threadId);
    }

    /**
     * Run workflow from current step up to preview (human-in-the-loop).
     */
    protected function runToPreview(string $threadId): string
    {
        try {
            // Stage 1: Analyze
            $this->state = $this->analyzeNode($this->state);
            if ($this->state['error']) {
                $this->persistState();
                return $threadId;
            }

            // Stage 2: Route
            $this->state = $this->routeNode($this->state);

            // Stage 3: Edit (per platform)
            $this->state = $this->editNode($this->state);

            // Stage 4: Music (videos only)
            if ($this->state['media_type'] === 'video') {
                $this->state = $this->musicNode($this->state);
            }

            // Stage 5: Caption generation
            $this->state = $this->captionNode($this->state);

            // Stage 6: Quality gate
            $this->state = $this->qualityGateNode($this->state);

            // Stage 7: Check accumulation triggers
            $this->state = $this->checkAccumulationNode($this->state);

            // Stage 8: Preview (pause for approval)
            $this->state = $this->previewNode($this->state);

            $this->persistState();

        } catch (\Exception $e) {
            Log::error('Workflow error', [
                'thread_id' => $threadId,
                'step' => $this->state['current_step'],
                'error' => $e->getMessage(),
            ]);
            $this->state['error'] = $e->getMessage();
            $this->state['current_step'] = 'error';
            $this->persistState();
        }

        return $threadId;
    }

    /**
     * Resume workflow after human approval.
     */
    public function resumeWithApproval(
        string $threadId,
        array $approvedPlatforms,
        array $deniedPlatforms = []
    ): array {
        $this->loadState($threadId);

        if (!$this->state) {
            return ['error' => 'Workflow not found'];
        }

        // Update approval state
        $this->state['approved_platforms'] = $approvedPlatforms;
        $this->state['denied_platforms'] = $deniedPlatforms;

        if (!empty($approvedPlatforms) && empty($deniedPlatforms)) {
            $this->state['approval_status'] = 'approved';
        } elseif (!empty($approvedPlatforms) && !empty($deniedPlatforms)) {
            $this->state['approval_status'] = 'partial';
        } else {
            $this->state['approval_status'] = 'denied';
            $this->persistState();
            return ['status' => 'denied', 'platforms' => $deniedPlatforms];
        }

        try {
            // Stage 9: Publish
            $this->state = $this->publishNode($this->state);
            $this->persistState();

            return $this->state['publish_results'];

        } catch (\Exception $e) {
            Log::error('Publish error', [
                'thread_id' => $threadId,
                'error' => $e->getMessage(),
            ]);
            $this->state['error'] = $e->getMessage();
            $this->persistState();
            return ['error' => $e->getMessage()];
        }
    }

    // ─── Workflow Nodes ───────────────────────────────────────────────────────

    /**
     * Analyze media using vision AI.
     */
    protected function analyzeNode(array $state): array
    {
        $state['current_step'] = 'analyze';

        try {
            $analysis = $this->visionAnalyzer->analyze(
                $state['file_path'],
                $state['media_type']
            );

            // Check safety assessment
            if (isset($analysis['safety_assessment']['is_safe']) && !$analysis['safety_assessment']['is_safe']) {
                $concerns = $analysis['safety_assessment']['concerns'] ?? [];
                $this->auditLog->audit(
                    AuditLogService::EVENT_NSFW_BLOCKED,
                    AuditLogService::SEVERITY_HIGH,
                    'workflow',
                    ['concerns' => $concerns],
                    $state['media_item_id']
                );

                $state['error'] = 'Content flagged as unsafe: ' . implode(', ', $concerns);
                $state['current_step'] = 'error';
                return $state;
            }

            $state['analysis'] = $analysis;
            $state['content_category'] = $analysis['content_category'] ?? 'general';
            $state['current_step'] = 'route';

            // Update media item with analysis
            MediaItem::where('id', $state['media_item_id'])
                ->update([
                    'content_category' => $state['content_category'],
                    'analysis_json' => json_encode($analysis),
                    'quality_score' => $analysis['quality_score'] ?? null,
                ]);

            return $state;

        } catch (\Exception $e) {
            $state['error'] = 'Analysis failed: ' . $e->getMessage();
            $state['current_step'] = 'error';
            return $state;
        }
    }

    /**
     * Route media to appropriate platforms.
     */
    protected function routeNode(array $state): array
    {
        $state['current_step'] = 'route';

        $platforms = $this->platformRouter->route(
            $state['media_type'],
            $state['width'],
            $state['height'],
            $state['duration_seconds'],
            $state['analysis']
        );

        $state['target_platforms'] = $platforms;
        $state['current_step'] = 'edit';

        return $state;
    }

    /**
     * Edit media for each target platform.
     */
    protected function editNode(array $state): array
    {
        $state['current_step'] = 'edit';
        $editedFiles = [];

        foreach ($state['target_platforms'] as $platform) {
            try {
                $editedPath = $this->mediaEditor->editForPlatform(
                    $state['file_path'],
                    $platform,
                    $state['media_type'],
                    $state['width'],
                    $state['height'],
                    $state['analysis']['quality_score'] ?? 7,
                    $state['analysis']['improvement_tips'] ?? []
                );
                $editedFiles[$platform] = $editedPath;
            } catch (\Exception $e) {
                // Use original file if editing fails
                $editedFiles[$platform] = $state['file_path'];
                Log::warning("Edit failed for {$platform}", ['error' => $e->getMessage()]);
            }
        }

        $state['edited_files'] = $editedFiles;
        $state['current_step'] = 'music';

        return $state;
    }

    /**
     * Select and mix background music for video content.
     */
    protected function musicNode(array $state): array
    {
        $state['current_step'] = 'music';
        $musicTracks = [];

        foreach ($state['target_platforms'] as $platform) {
            try {
                $recommendation = $this->musicResearcher->recommendForContent(
                    $state['content_category'],
                    $state['analysis']['description'] ?? '',
                    $state['analysis']['mood'] ?? 'professional',
                    $platform
                );

                if ($recommendation && isset($recommendation['path'])) {
                    // Mix music into video
                    $mixedPath = $this->mediaEditor->mixBackgroundMusic(
                        $state['edited_files'][$platform],
                        $recommendation['path']
                    );
                    $state['edited_files'][$platform] = $mixedPath;
                    $musicTracks[$platform] = $recommendation;
                }
            } catch (\Exception $e) {
                Log::debug("Music skipped for {$platform}", ['error' => $e->getMessage()]);
            }
        }

        $state['music_tracks'] = $musicTracks;
        $state['current_step'] = 'caption';

        return $state;
    }

    /**
     * Generate captions and hashtags for each platform.
     */
    protected function captionNode(array $state): array
    {
        $state['current_step'] = 'caption';
        $captions = [];
        $hashtags = [];

        foreach ($state['target_platforms'] as $platform) {
            try {
                // Generate caption
                $caption = $this->captionWriter->generate(
                    $platform,
                    $state['analysis']['description'] ?? 'Content for publishing',
                    $state['content_category'],
                    $state['analysis']['mood'] ?? 'professional',
                    $state['analysis']['healthcare_services'] ?? []
                );

                // Generate hashtags
                $platformHashtags = $this->hashtagResearcher->getHashtags(
                    $state['content_category'],
                    $platform,
                    config('services.agents.max_hashtags', 20)
                );

                // Merge AI and cached hashtags
                $mergedHashtags = array_unique(array_merge(
                    $caption['hashtags'] ?? [],
                    $platformHashtags
                ));

                $captions[$platform] = $caption;
                $hashtags[$platform] = array_slice($mergedHashtags, 0, 30);

            } catch (\Exception $e) {
                Log::warning("Caption failed for {$platform}", ['error' => $e->getMessage()]);
                $captions[$platform] = ['caption' => 'Check out our latest update!'];
                $hashtags[$platform] = ['marketing', 'business'];
            }
        }

        $state['captions'] = $captions;
        $state['hashtags'] = $hashtags;
        $state['current_step'] = 'quality_gate';

        return $state;
    }

    /**
     * Quality gate — security scanning and engagement scoring.
     */
    protected function qualityGateNode(array $state): array
    {
        $state['current_step'] = 'quality_gate';

        foreach ($state['target_platforms'] as $platform) {
            $captionData = $state['captions'][$platform] ?? [];
            $captionText = $captionData['caption'] ?? '';

            // Security: PII redaction
            if ($this->piiRedactor->hasPii($captionText)) {
                $redacted = $this->piiRedactor->redact($captionText);
                $state['captions'][$platform]['caption'] = $redacted;

                $this->auditLog->audit(
                    AuditLogService::EVENT_PII_REDACTED,
                    AuditLogService::SEVERITY_WARNING,
                    'workflow',
                    ['platform' => $platform, 'field' => 'caption'],
                    $state['media_item_id']
                );
            }

            // Security: Prompt injection check
            $sanitizedCaption = $this->promptGuard->sanitize($state['captions'][$platform]['caption'] ?? '');
            $state['captions'][$platform]['caption'] = $sanitizedCaption;

            // Quality: engagement scoring
            $engagementResult = $this->growthHacker->analyzeCaptionEngagement(
                $state['captions'][$platform]['caption'] ?? '',
                $platform
            );

            // Regenerate weak captions
            if (($engagementResult['engagement_score'] ?? 0) < 50) {
                try {
                    $improved = $this->captionWriter->generate(
                        $platform,
                        ($state['analysis']['description'] ?? '') .
                            ' | MUST include: strong hook + psychology trigger + clear CTA',
                        $state['content_category'],
                        $state['analysis']['mood'] ?? 'professional'
                    );
                    $state['captions'][$platform] = $improved;
                } catch (\Exception $e) {
                    // Keep original if regeneration fails
                }
            }
        }

        $state['current_step'] = 'check_accumulation';
        return $state;
    }

    /**
     * Check accumulation triggers for collages/compilations.
     */
    protected function checkAccumulationNode(array $state): array
    {
        $proposals = $this->contentMemory->checkAccumulationTriggers();
        $state['accumulation_proposals'] = $proposals;
        $state['current_step'] = 'preview';
        return $state;
    }

    /**
     * Prepare preview data for human approval.
     */
    protected function previewNode(array $state): array
    {
        $state['current_step'] = 'awaiting_approval';
        $engagementAnalysis = [];
        $postingSchedule = [];

        foreach ($state['target_platforms'] as $platform) {
            // Engagement analysis
            $captionText = $state['captions'][$platform]['caption'] ?? '';
            if ($captionText) {
                $engagementAnalysis[$platform] = $this->growthHacker->analyzeCaptionEngagement(
                    $captionText,
                    $platform
                );
            }

            // Posting schedule
            $postingSchedule[$platform] = $this->growthHacker->getBestPostingTime($platform);
        }

        $state['engagement_analysis'] = $engagementAnalysis;
        $state['posting_schedule'] = $postingSchedule;
        $state['approval_status'] = 'pending';

        return $state;
    }

    /**
     * Publish approved content to platforms.
     */
    protected function publishNode(array $state): array
    {
        $state['current_step'] = 'publish';
        $results = [];

        foreach ($state['approved_platforms'] as $platform) {
            $captionData = $state['captions'][$platform] ?? [];
            $editedPath = $state['edited_files'][$platform] ?? $state['file_path'];
            $platformHashtags = $state['hashtags'][$platform] ?? [];

            try {
                // Pre-publish gate check
                $gateResult = $this->publishGate->validate($editedPath, $captionData['caption'] ?? '');
                if (!$gateResult['is_allowed']) {
                    $results[$platform] = [
                        'success' => false,
                        'error' => 'Pre-publish gate blocked: ' . implode(', ', $gateResult['blocking_issues']),
                    ];
                    continue;
                }

                // Publish
                $result = $this->publisher->publishToPlatform(
                    $platform,
                    $editedPath,
                    $captionData['caption'] ?? '',
                    $platformHashtags,
                    $state['media_type'],
                    $captionData['title'] ?? '',
                    $captionData['description'] ?? ''
                );

                $results[$platform] = $result;

                // Log to content calendar
                if ($result['success'] ?? false) {
                    $this->contentMemory->logPostToCalendar(
                        $state['media_item_id'],
                        $platform,
                        $state['content_category']
                    );
                }

            } catch (\Exception $e) {
                $results[$platform] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $state['publish_results'] = $results;
        $state['current_step'] = 'done';

        return $state;
    }

    // ─── State Persistence ────────────────────────────────────────────────────

    /**
     * Persist workflow state to database.
     */
    protected function persistState(): void
    {
        DB::table('workflow_states')->updateOrInsert(
            ['thread_id' => $this->state['thread_id']],
            [
                'business_id' => $this->businessId,
                'media_item_id' => $this->state['media_item_id'],
                'state_json' => json_encode($this->state),
                'current_step' => $this->state['current_step'],
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Load workflow state from database.
     */
    protected function loadState(string $threadId): void
    {
        $record = DB::table('workflow_states')
            ->where('thread_id', $threadId)
            ->first();

        if ($record) {
            $this->state = json_decode($record->state_json, true);
        }
    }

    /**
     * Get current workflow state.
     */
    public function getState(string $threadId): ?array
    {
        $this->loadState($threadId);
        return $this->state ?: null;
    }

    /**
     * Get workflow status.
     */
    public function getStatus(string $threadId): string
    {
        $this->loadState($threadId);
        return $this->state['current_step'] ?? 'unknown';
    }
}
