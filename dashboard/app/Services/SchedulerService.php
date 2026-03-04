<?php

namespace App\Services;

use App\Models\Post;
use App\Services\Security\PublishGateService;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler Service — publishes scheduled posts and retries failed ones.
 *
 *
 * Designed to be called from Laravel's task scheduler (Console\Kernel).
 * Checks the database for:
 *   1. Posts with scheduled_at <= now() and status = 'approved'
 *   2. Posts with status = 'failed' and retry_count < max, next_retry_at <= now()
 */
class SchedulerService
{
    protected PublisherService $publisher;
    protected PublishGateService $publishGate;
    protected EventBusService $eventBus;

    protected int $maxRetries = 3;
    protected int $retryDelaySeconds = 300; // 5 minutes
    protected float $retryBackoffMultiplier = 2.0;

    public function __construct()
    {
        $this->publisher = new PublisherService(0);
        $this->publishGate = new PublishGateService();
        $this->eventBus = new EventBusService();
    }

    /**
     * Check and publish all due scheduled posts.
     * Call this from the Laravel scheduler every minute.
     */
    public function checkScheduledPosts(): array
    {
        $results = [
            'checked'   => 0,
            'published' => 0,
            'failed'    => 0,
            'skipped'   => 0,
        ];

        $duePosts = Post::where('status', 'approved')
            ->where('scheduled_at', '<=', now())
            ->limit(10) // Process in batches
            ->get();

        $results['checked'] = $duePosts->count();

        foreach ($duePosts as $post) {
            try {
                $result = $this->publishPost($post);

                if ($result['success']) {
                    $results['published']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                Log::error('Scheduler error publishing post', [
                    'post_id' => $post->id,
                    'error'   => $e->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        Log::info('Scheduler check completed', $results);
        return $results;
    }

    /**
     * Check and retry failed posts.
     */
    public function checkRetryPosts(): array
    {
        $results = [
            'checked'   => 0,
            'retried'   => 0,
            'succeeded' => 0,
            'gave_up'   => 0,
        ];

        $failedPosts = Post::where('status', 'failed')
            ->where('retry_count', '<', $this->maxRetries)
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                      ->orWhere('next_retry_at', '<=', now());
            })
            ->limit(5)
            ->get();

        $results['checked'] = $failedPosts->count();

        foreach ($failedPosts as $post) {
            try {
                $results['retried']++;
                $result = $this->publishPost($post, isRetry: true);

                if ($result['success']) {
                    $results['succeeded']++;
                }
            } catch (\Exception $e) {
                Log::error('Scheduler retry error', [
                    'post_id' => $post->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Publish a single post.
     */
    protected function publishPost(Post $post, bool $isRetry = false): array
    {
        $filePath = $post->edited_file_path ?? $post->file_path;

        // Pre-publish safety gate
        if ($filePath) {
            $gateResult = $this->publishGate->check(
                $filePath,
                $post->caption ?? '',
                $post->platform,
                $post->media_type ?? 'photo',
                $post->id,
                $post->description,
                null,
                $post->business_id
            );

            if (!$gateResult['cleared']) {
                $this->markPostFailed($post, implode('; ', $gateResult['blocked_reasons']));
                return ['success' => false, 'error' => 'Blocked by publish gate'];
            }
        }

        // Initialize publisher for this business
        $this->publisher = new PublisherService($post->business_id);

        // Attempt to publish
        $result = $this->publisher->publish(
            $post->platform,
            $filePath ?? '',
            $post->caption ?? '',
            $post->hashtags ?? [],
            $post->media_type ?? 'photo',
            $post->title,
            $post->description,
            $post->id
        );

        if ($result['success']) {
            $this->markPostPublished($post, $result);
        } else {
            $this->markPostFailed($post, $result['error'] ?? 'Unknown error', $isRetry);
        }

        return $result;
    }

    /**
     * Mark a post as successfully published.
     */
    protected function markPostPublished(Post $post, array $result): void
    {
        $post->update([
            'status'           => 'published',
            'published_at'     => now(),
            'platform_post_id' => $result['post_id'] ?? null,
            'platform_url'     => $result['url'] ?? null,
            'last_error'       => null,
        ]);

        $this->eventBus->postPublished(
            $post->id,
            $post->platform,
            $result['url'] ?? null,
            $post->business_id
        );

        Log::info("Post {$post->id} published successfully", [
            'platform' => $post->platform,
            'url'      => $result['url'] ?? null,
        ]);
    }

    /**
     * Mark a post as failed with retry scheduling.
     */
    protected function markPostFailed(Post $post, string $error, bool $isRetry = false): void
    {
        $retryCount = $post->retry_count + 1;
        $nextRetry = null;

        if ($retryCount < $this->maxRetries) {
            // Exponential backoff
            $delay = $this->retryDelaySeconds * pow($this->retryBackoffMultiplier, $retryCount - 1);
            $nextRetry = now()->addSeconds($delay);
        }

        $post->update([
            'status'        => 'failed',
            'retry_count'   => $retryCount,
            'last_error'    => substr($error, 0, 1000),
            'next_retry_at' => $nextRetry,
        ]);

        $this->eventBus->postFailed(
            $post->id,
            substr($error, 0, 200),
            $retryCount,
            $post->business_id
        );

        Log::warning("Post {$post->id} failed", [
            'attempt'      => $retryCount,
            'max_retries'  => $this->maxRetries,
            'error'        => substr($error, 0, 100),
            'next_retry'   => $nextRetry?->toIso8601String(),
        ]);
    }

    /**
     * Get upcoming scheduled posts.
     */
    public function getUpcomingPosts(int $businessId, int $hours = 24): \Illuminate\Database\Eloquent\Collection
    {
        return Post::where('business_id', $businessId)
            ->where('status', 'approved')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now()->addHours($hours))
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Get failed posts awaiting retry.
     */
    public function getFailedPosts(int $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Post::where('business_id', $businessId)
            ->where('status', 'failed')
            ->where('retry_count', '<', $this->maxRetries)
            ->orderBy('next_retry_at')
            ->get();
    }

    /**
     * Get permanently failed posts (exceeded max retries).
     */
    public function getPermanentlyFailed(int $businessId): \Illuminate\Database\Eloquent\Collection
    {
        return Post::where('business_id', $businessId)
            ->where('status', 'failed')
            ->where('retry_count', '>=', $this->maxRetries)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();
    }

    /**
     * Manually retry a failed post.
     */
    public function retryPost(int $postId): array
    {
        $post = Post::find($postId);

        if (!$post) {
            return ['success' => false, 'error' => 'Post not found'];
        }

        if ($post->status !== 'failed') {
            return ['success' => false, 'error' => 'Post is not in failed status'];
        }

        // Reset retry count for manual retry
        $post->update(['retry_count' => 0, 'next_retry_at' => null]);

        return $this->publishPost($post, isRetry: true);
    }
}
