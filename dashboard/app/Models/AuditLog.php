<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centralized security audit log.
 * Converted from Python: memory/models.py → AuditLog
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'business_id',
        'event_type',
        'severity',
        'actor',
        'details_json',
        'related_id',
        'created_at',
    ];

    protected $casts = [
        'details_json' => 'array',
        'created_at'   => 'datetime',
    ];

    // ── Severity Constants ────────────────────────────────────────

    const SEVERITY_INFO     = 'info';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_ERROR    = 'error';
    const SEVERITY_CRITICAL = 'critical';

    // ── Event Type Constants ──────────────────────────────────────

    const EVENT_LOGIN           = 'user.login';
    const EVENT_LOGOUT          = 'user.logout';
    const EVENT_PASSWORD_CHANGE = 'user.password_change';
    const EVENT_API_KEY_CREATED = 'api.key_created';
    const EVENT_API_KEY_REVOKED = 'api.key_revoked';
    const EVENT_PUBLISH_SUCCESS = 'publish.success';
    const EVENT_PUBLISH_FAILED  = 'publish.failed';
    const EVENT_SECURITY_BLOCK  = 'security.blocked';
    const EVENT_RATE_LIMIT      = 'security.rate_limit';

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeOfSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ── Relationships ─────────────────────────────────────────────

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // ── Static Logging Methods ────────────────────────────────────

    /**
     * Log an event.
     */
    public static function log(
        string $eventType,
        string $severity = self::SEVERITY_INFO,
        ?int $businessId = null,
        string $actor = 'system',
        array $details = [],
        ?int $relatedId = null
    ): self {
        return self::create([
            'business_id'  => $businessId,
            'event_type'   => $eventType,
            'severity'     => $severity,
            'actor'        => $actor,
            'details_json' => !empty($details) ? $details : null,
            'related_id'   => $relatedId,
            'created_at'   => now(),
        ]);
    }

    /**
     * Log an info event.
     */
    public static function info(string $eventType, ?int $businessId = null, array $details = []): self
    {
        return self::log($eventType, self::SEVERITY_INFO, $businessId, 'system', $details);
    }

    /**
     * Log a warning event.
     */
    public static function warning(string $eventType, ?int $businessId = null, array $details = []): self
    {
        return self::log($eventType, self::SEVERITY_WARNING, $businessId, 'system', $details);
    }

    /**
     * Log an error event.
     */
    public static function error(string $eventType, ?int $businessId = null, array $details = []): self
    {
        return self::log($eventType, self::SEVERITY_ERROR, $businessId, 'system', $details);
    }
}
