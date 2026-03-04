<?php

namespace App\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Event Bus — simple pub/sub for real-time notifications.
 *
 * Converted from Python: services/event_bus.py
 *
 * Components emit events; the dashboard receives them via polling or SSE.
 * Uses Laravel's event system and cache for recent events buffer.
 */
class EventBusService
{
    protected const CACHE_KEY = 'event_bus:recent_events';
    protected const MAX_RECENT = 50;
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Emit an event to all subscribers.
     */
    public function emit(string $eventType, array $data = [], ?int $businessId = null): void
    {
        $event = [
            'type'        => $eventType,
            'data'        => $data,
            'business_id' => $businessId,
            'timestamp'   => microtime(true),
            'created_at'  => now()->toIso8601String(),
        ];

        // Add to recent buffer
        $this->addToRecentBuffer($event);

        // Dispatch Laravel event for listeners
        Event::dispatch('marketing.event', [$event]);

        Log::debug("Event emitted: {$eventType}", ['business_id' => $businessId]);
    }

    /**
     * Get recent events (for initial page load or polling).
     */
    public function getRecentEvents(int $count = 20, ?int $businessId = null): array
    {
        $events = Cache::get(self::CACHE_KEY, []);

        if ($businessId) {
            $events = array_filter(
                $events,
                fn($e) => !isset($e['business_id']) || $e['business_id'] === $businessId
            );
        }

        return array_slice(array_values($events), -$count);
    }

    /**
     * Get events since a specific timestamp.
     */
    public function getEventsSince(float $timestamp, ?int $businessId = null): array
    {
        $events = $this->getRecentEvents(self::MAX_RECENT, $businessId);

        return array_filter(
            $events,
            fn($e) => ($e['timestamp'] ?? 0) > $timestamp
        );
    }

    /**
     * Add event to recent buffer.
     */
    protected function addToRecentBuffer(array $event): void
    {
        $events = Cache::get(self::CACHE_KEY, []);
        $events[] = $event;

        // Keep only last N events
        if (count($events) > self::MAX_RECENT) {
            $events = array_slice($events, -self::MAX_RECENT);
        }

        Cache::put(self::CACHE_KEY, $events, self::CACHE_TTL);
    }

    /**
     * Clear events buffer.
     */
    public function clearEvents(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONVENIENCE EMITTERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Emit post published event.
     */
    public function postPublished(int $postId, string $platform, ?string $url, ?int $businessId): void
    {
        $this->emit('post_published', [
            'post_id'  => $postId,
            'platform' => $platform,
            'url'      => $url,
        ], $businessId);
    }

    /**
     * Emit post failed event.
     */
    public function postFailed(int $postId, string $error, int $retryCount, ?int $businessId): void
    {
        $this->emit('post_failed', [
            'post_id'     => $postId,
            'error'       => $error,
            'retry_count' => $retryCount,
        ], $businessId);
    }

    /**
     * Emit media uploaded event.
     */
    public function mediaUploaded(int $mediaId, string $contentType, ?int $businessId): void
    {
        $this->emit('media_uploaded', [
            'media_id'     => $mediaId,
            'content_type' => $contentType,
        ], $businessId);
    }

    /**
     * Emit AI task completed event.
     */
    public function aiTaskCompleted(string $taskType, array $result, ?int $businessId): void
    {
        $this->emit('ai_task_completed', [
            'task_type' => $taskType,
            'success'   => $result['success'] ?? true,
        ], $businessId);
    }

    /**
     * Emit quota warning event.
     */
    public function quotaWarning(int $usagePercent, ?int $businessId): void
    {
        $this->emit('quota_warning', [
            'usage_percent' => $usagePercent,
            'message'       => "AI token usage at {$usagePercent}%",
        ], $businessId);
    }

    /**
     * Emit job status change event.
     */
    public function jobStatusChanged(int $jobId, string $status, ?int $businessId): void
    {
        $this->emit('job_status_changed', [
            'job_id' => $jobId,
            'status' => $status,
        ], $businessId);
    }

    /**
     * Emit security alert event.
     */
    public function securityAlert(string $alertType, string $message, ?int $businessId): void
    {
        $this->emit('security_alert', [
            'alert_type' => $alertType,
            'message'    => $message,
        ], $businessId);
    }
}
