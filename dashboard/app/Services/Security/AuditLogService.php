<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Audit Logger — centralized, structured logging for all security-relevant events.
 *
 *
 * All events are written to both:
 *   1. The audit_logs MySQL table (queryable, persistent)
 *   2. The Laravel logger (stdout / file)
 */
class AuditLogService
{
    // ═══════════════════════════════════════════════════════════════════════
    // EVENT TYPES
    // ═══════════════════════════════════════════════════════════════════════

    // File security
    public const FILE_UPLOAD = 'file_upload';
    public const FILE_REJECTED = 'file_rejected';
    public const MALWARE_DETECTED = 'malware_detected';
    public const EXIF_STRIPPED = 'exif_stripped';

    // Content scanning
    public const NSFW_SCAN = 'nsfw_scan';
    public const NSFW_BLOCKED = 'nsfw_blocked';
    public const TOXICITY_SCAN = 'toxicity_scan';
    public const TOXICITY_BLOCKED = 'toxicity_blocked';
    public const COMPLIANCE_FLAG = 'compliance_flag';

    // Prompt injection
    public const PROMPT_INJECTION = 'prompt_injection_detected';

    // PII
    public const PII_REDACTED = 'pii_redacted';

    // Publishing
    public const PUBLISH_GATE_PASS = 'publish_gate_pass';
    public const PUBLISH_GATE_BLOCK = 'publish_gate_block';
    public const POST_PUBLISHED = 'post_published';
    public const POST_APPROVED = 'post_approved';
    public const POST_DENIED = 'post_denied';

    // Auth
    public const AUTH_SUCCESS = 'auth_success';
    public const AUTH_FAILURE = 'auth_failure';
    public const RATE_LIMITED = 'rate_limited';

    // System
    public const QUARANTINE = 'file_quarantined';
    public const AGENT_ERROR = 'agent_error';
    public const CREDIT_REQUEST = 'credit_request';

    // ═══════════════════════════════════════════════════════════════════════
    // SEVERITY LEVELS
    // ═══════════════════════════════════════════════════════════════════════

    public const INFO = 'info';
    public const WARNING = 'warning';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    // Aliases for WorkflowService compatibility
    public const SEVERITY_INFO = self::INFO;
    public const SEVERITY_WARNING = self::WARNING;
    public const SEVERITY_HIGH = self::HIGH;
    public const SEVERITY_CRITICAL = self::CRITICAL;

    // Event aliases
    public const EVENT_NSFW_BLOCKED = self::NSFW_BLOCKED;
    public const EVENT_PII_REDACTED = self::PII_REDACTED;

    // ═══════════════════════════════════════════════════════════════════════
    // LOGGING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Record a security event.
     *
     * Writes to the database (best-effort) and always logs.
     */
    public function audit(
        string $event,
        string $severity = self::INFO,
        string $actor = 'system',
        ?array $details = null,
        ?int $relatedId = null,
        ?int $businessId = null,
        ?int $userId = null
    ): void {
        $detailsJson = json_encode($details ?? []);

        // Always log to Laravel logger
        $logLine = "[AUDIT] {$severity} | {$event} | actor={$actor} | details={$detailsJson}";
        $this->logAtLevel($severity, $logLine);

        // Write to database (best-effort — never crash the caller)
        try {
            AuditLog::create([
                'business_id'  => $businessId,
                'user_id'      => $userId,
                'event_type'   => $event,
                'severity'     => $severity,
                'actor'        => $actor,
                'details'      => $details,
                'related_id'   => $relatedId,
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write audit log to DB', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send an immediate Telegram alert to the admin for critical events.
     */
    public function sendCriticalAlert(string $event, string $message): void
    {
        try {
            $botToken = config('services.telegram.bot_token');
            $adminChatId = config('services.telegram.admin_chat_id');

            if (!$botToken || !$adminChatId) {
                return;
            }

            $alertText = "🚨 *CRITICAL SECURITY ALERT*\n\n"
                       . "*Event:* `{$event}`\n"
                       . "*Time:* " . now()->toIso8601String() . "\n\n"
                       . $message;

            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id'    => $adminChatId,
                'text'       => $alertText,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send critical alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Log a file upload event.
     */
    public function logFileUpload(
        string $filename,
        string $result,
        ?int $businessId = null,
        ?array $metadata = null
    ): void {
        $this->audit(
            self::FILE_UPLOAD,
            self::INFO,
            'telegram_bot',
            array_merge(['filename' => $filename, 'result' => $result], $metadata ?? []),
            null,
            $businessId
        );
    }

    /**
     * Log a publish gate decision.
     */
    public function logPublishGate(
        int $postId,
        bool $cleared,
        array $details,
        ?int $businessId = null
    ): void {
        $this->audit(
            $cleared ? self::PUBLISH_GATE_PASS : self::PUBLISH_GATE_BLOCK,
            $cleared ? self::INFO : self::WARNING,
            'publish_gate',
            $details,
            $postId,
            $businessId
        );
    }

    /**
     * Log an authentication event.
     */
    public function logAuth(bool $success, string $method, ?int $userId = null): void
    {
        $this->audit(
            $success ? self::AUTH_SUCCESS : self::AUTH_FAILURE,
            $success ? self::INFO : self::WARNING,
            'auth',
            ['method' => $method],
            null,
            null,
            $userId
        );
    }

    /**
     * Log PII redaction event.
     */
    public function logPiiRedaction(array $summary, ?int $businessId = null): void
    {
        $this->audit(
            self::PII_REDACTED,
            self::INFO,
            'pii_redactor',
            $summary,
            null,
            $businessId
        );
    }

    /**
     * Get recent audit events for a business.
     */
    public function getRecentEvents(
        ?int $businessId = null,
        int $limit = 50,
        ?string $severity = null,
        ?string $eventType = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = AuditLog::orderByDesc('created_at');

        if ($businessId) {
            $query->where('business_id', $businessId);
        }

        if ($severity) {
            $query->where('severity', $severity);
        }

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Log at appropriate Laravel log level.
     */
    protected function logAtLevel(string $severity, string $message): void
    {
        match ($severity) {
            self::CRITICAL => Log::critical($message),
            self::HIGH     => Log::error($message),
            self::WARNING  => Log::warning($message),
            default        => Log::info($message),
        };
    }
}
