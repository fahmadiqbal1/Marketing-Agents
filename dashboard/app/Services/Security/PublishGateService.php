<?php

namespace App\Services\Security;

use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publish Gate — the **last line of defence** before content reaches
 * a live social media account.
 *
 *
 * Runs immediately before every publish operation and performs comprehensive
 * checks on both the media file and the caption/description text.
 */
class PublishGateService
{
    protected PiiRedactorService $piiRedactor;
    protected PromptGuardService $promptGuard;
    protected AuditLogService $auditLog;
    protected ?int $businessId;

    public function __construct(?int $businessId = null)
    {
        $this->businessId = $businessId;
        $this->piiRedactor = new PiiRedactorService();
        $this->promptGuard = new PromptGuardService();
        $this->auditLog = new AuditLogService();
    }

    /**
     * Validate content before publishing (alias for WorkflowService).
     *
     * @param string $filePath Path to the media file
     * @param string $caption Post caption
     * @return array Validation result with 'is_allowed', 'blocking_issues', 'warnings'
     */
    public function validate(string $filePath, string $caption): array
    {
        $result = $this->check(
            $filePath,
            $caption,
            'generic', // platform
            'photo',   // media_type (will be determined by extension)
            null,      // postId
            null,      // description
            null,      // expectedFileHash
            $this->businessId
        );

        return [
            'is_allowed' => $result['cleared'] ?? false,
            'blocking_issues' => $result['blocked_reasons'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ];
    }

    /**
     * Pre-publish safety gate result.
     */
    public function check(
        string $filePath,
        string $caption,
        string $platform,
        string $mediaType,
        ?int $postId = null,
        ?string $description = null,
        ?string $expectedFileHash = null,
        ?int $businessId = null
    ): array {
        $decision = [
            'cleared'         => true,
            'blocked_reasons' => [],
            'warnings'        => [],
        ];

        // ── 1. File exists ────────────────────────────────────────────────
        if (!file_exists($filePath)) {
            $decision['cleared'] = false;
            $decision['blocked_reasons'][] = "File not found: {$filePath}";
            return $this->logAndReturn($decision, $postId, $businessId);
        }

        // ── 2. File integrity (hash check) ────────────────────────────────
        if ($expectedFileHash) {
            $actualHash = $this->computeFileHash($filePath);
            if ($actualHash !== $expectedFileHash) {
                $decision['cleared'] = false;
                $decision['blocked_reasons'][] = sprintf(
                    "File hash mismatch — expected %s… got %s… — possible tampering",
                    substr($expectedFileHash, 0, 16),
                    substr($actualHash, 0, 16)
                );
                return $this->logAndReturn($decision, $postId, $businessId);
            }
        }

        // ── 3. File size check ────────────────────────────────────────────
        $fileSize = filesize($filePath);
        $maxSize = $this->getMaxFileSize($platform, $mediaType);
        if ($fileSize > $maxSize) {
            $decision['cleared'] = false;
            $decision['blocked_reasons'][] = sprintf(
                "File too large: %s (max %s for %s %s)",
                $this->formatBytes($fileSize),
                $this->formatBytes($maxSize),
                $platform,
                $mediaType
            );
        }

        // ── 4. PII check on caption ───────────────────────────────────────
        if ($this->piiRedactor->hasPii($caption)) {
            $decision['cleared'] = false;
            $decision['blocked_reasons'][] = "Caption contains PII — must be redacted before publishing";
        }

        // Check description (YouTube)
        if ($description && $this->piiRedactor->hasPii($description)) {
            $decision['cleared'] = false;
            $decision['blocked_reasons'][] = "Description contains PII — must be redacted before publishing";
        }

        // ── 5. Prompt injection check ─────────────────────────────────────
        if ($this->promptGuard->hasInjection($caption)) {
            $decision['warnings'][] = "Caption may contain injection patterns — review recommended";
        }

        // ── 6. Caption length check ───────────────────────────────────────
        $maxCaptionLength = $this->getMaxCaptionLength($platform);
        if (mb_strlen($caption) > $maxCaptionLength) {
            $decision['cleared'] = false;
            $decision['blocked_reasons'][] = sprintf(
                "Caption too long: %d chars (max %d for %s)",
                mb_strlen($caption),
                $maxCaptionLength,
                $platform
            );
        }

        // ── 7. Approval status ────────────────────────────────────────────
        if ($postId) {
            $post = Post::find($postId);
            if ($post && $post->status !== 'approved' && $post->status !== 'published') {
                $decision['cleared'] = false;
                $decision['blocked_reasons'][] = "Post {$postId} has not been explicitly approved";
            }
        }

        // ── 8. Platform-specific checks ───────────────────────────────────
        $platformWarnings = $this->platformSpecificChecks($platform, $caption, $mediaType);
        $decision['warnings'] = array_merge($decision['warnings'], $platformWarnings);

        return $this->logAndReturn($decision, $postId, $businessId);
    }

    /**
     * Compute SHA-256 hash of a file.
     */
    public function computeFileHash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    /**
     * Get max file size for platform/media type.
     */
    protected function getMaxFileSize(string $platform, string $mediaType): int
    {
        $limits = [
            'instagram' => ['photo' => 8 * 1024 * 1024, 'video' => 4 * 1024 * 1024 * 1024],
            'facebook'  => ['photo' => 4 * 1024 * 1024, 'video' => 10 * 1024 * 1024 * 1024],
            'tiktok'    => ['video' => 4 * 1024 * 1024 * 1024],
            'youtube'   => ['video' => 128 * 1024 * 1024 * 1024],
            'linkedin'  => ['photo' => 8 * 1024 * 1024, 'video' => 5 * 1024 * 1024 * 1024],
            'twitter'   => ['photo' => 5 * 1024 * 1024, 'video' => 512 * 1024 * 1024],
            'pinterest' => ['photo' => 20 * 1024 * 1024, 'video' => 2 * 1024 * 1024 * 1024],
            'snapchat'  => ['photo' => 5 * 1024 * 1024, 'video' => 300 * 1024 * 1024],
        ];

        return $limits[$platform][$mediaType] ?? 100 * 1024 * 1024; // 100MB default
    }

    /**
     * Get max caption length for platform.
     */
    protected function getMaxCaptionLength(string $platform): int
    {
        return match ($platform) {
            'instagram' => 2200,
            'facebook'  => 63206,
            'tiktok'    => 2200,
            'youtube'   => 5000,
            'linkedin'  => 3000,
            'twitter'   => 280,
            'pinterest' => 500,
            'snapchat'  => 250,
            'threads'   => 500,
            default     => 5000,
        };
    }

    /**
     * Platform-specific checks.
     */
    protected function platformSpecificChecks(string $platform, string $caption, string $mediaType): array
    {
        $warnings = [];

        // Instagram
        if ($platform === 'instagram') {
            $hashtagCount = substr_count($caption, '#');
            if ($hashtagCount > 30) {
                $warnings[] = "Too many hashtags ({$hashtagCount}) — Instagram allows max 30";
            }
        }

        // Twitter
        if ($platform === 'twitter') {
            // Check for excessive hashtags
            $hashtagCount = substr_count($caption, '#');
            if ($hashtagCount > 5) {
                $warnings[] = "Too many hashtags for Twitter — recommend 1-3";
            }
        }

        // YouTube
        if ($platform === 'youtube' && $mediaType === 'video') {
            if (mb_strlen($caption) < 50) {
                $warnings[] = "YouTube description is very short — recommend 200+ chars for SEO";
            }
        }

        // TikTok
        if ($platform === 'tiktok') {
            $hashtagCount = substr_count($caption, '#');
            if ($hashtagCount > 5) {
                $warnings[] = "TikTok performs best with 3-5 hashtags";
            }
        }

        return $warnings;
    }

    /**
     * Format bytes to human-readable string.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Log the decision and return it.
     */
    protected function logAndReturn(array $decision, ?int $postId, ?int $businessId): array
    {
        $this->auditLog->logPublishGate(
            $postId ?? 0,
            $decision['cleared'],
            [
                'blocked_reasons' => $decision['blocked_reasons'],
                'warnings'        => $decision['warnings'],
            ],
            $businessId
        );

        return $decision;
    }

    /**
     * Quick check if content is safe to publish (no full details).
     */
    public function isCleared(
        string $filePath,
        string $caption,
        string $platform,
        string $mediaType
    ): bool {
        $result = $this->check($filePath, $caption, $platform, $mediaType);
        return $result['cleared'];
    }
}
