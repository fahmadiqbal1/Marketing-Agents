<?php

namespace App\Services;

use App\Models\SocialPlatform;
use App\Models\Post;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publisher Service — posts content to social media platforms.
 *
 * Each platform has its own publishing method with API-specific logic.
 * Credentials are loaded from the SocialPlatform model (encrypted).
 */
class PublisherService
{
    private int $businessId;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // UNIFIED PUBLISHER
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to a platform (expanded interface for WorkflowService).
     *
     * @param string $platform Target platform
     * @param string $filePath Path to media file
     * @param string $caption Post caption
     * @param array $hashtags Hashtags to include
     * @param string $mediaType 'photo' or 'video'
     * @param string $title Title (for YouTube)
     * @param string $description Description (for YouTube)
     * @return array Publishing result with 'success', 'platform_id', 'url', etc.
     */
    public function publishToPlatform(
        string $platform,
        string $filePath,
        string $caption,
        array $hashtags = [],
        string $mediaType = 'photo',
        string $title = '',
        string $description = ''
    ): array {
        // Build content array for the publish method
        $hashtagString = !empty($hashtags)
            ? ' ' . implode(' ', array_map(fn($h) => '#' . ltrim($h, '#'), $hashtags))
            : '';

        $content = [
            'file_path'   => $filePath,
            'media_url'   => $this->getPublicUrl($filePath),
            'caption'     => $caption . $hashtagString,
            'is_video'    => $mediaType === 'video',
            'title'       => $title,
            'description' => $description,
        ];

        return $this->publish($platform, $content);
    }

    /**
     * Get a publicly accessible URL for a file.
     */
    protected function getPublicUrl(string $filePath): string
    {
        // If already a URL, return as-is
        if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
            return $filePath;
        }

        // For local files, generate a public URL via storage
        $publicPath = str_replace(storage_path('app/public/'), '', $filePath);
        return asset('storage/' . $publicPath);
    }

    /**
     * Publish content to a specific platform.
     */
    public function publish(string $platform, array $content): array
    {
        return match($platform) {
            'instagram' => $this->publishToInstagram($content),
            'facebook'  => $this->publishToFacebook($content),
            'youtube'   => $this->publishToYouTube($content),
            'linkedin'  => $this->publishToLinkedIn($content),
            'tiktok'    => $this->publishToTikTok($content),
            'twitter'   => $this->publishToTwitter($content),
            'snapchat'  => $this->prepareForSnapchat($content),
            default     => ['success' => false, 'error' => "Unknown platform: {$platform}"],
        };
    }

    /**
     * Publish to multiple platforms.
     */
    public function publishToAll(array $platforms, array $content): array
    {
        $results = [];

        foreach ($platforms as $platform) {
            $results[$platform] = $this->publish($platform, $content);
        }

        return [
            'results'    => $results,
            'success'    => collect($results)->every(fn($r) => $r['success']),
            'failed'     => collect($results)->filter(fn($r) => !$r['success'])->keys()->toArray(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // INSTAGRAM (via Meta Graph API)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to Instagram via Meta Graph API.
     * Requires Business Account + Facebook Page.
     */
    public function publishToInstagram(array $content): array
    {
        $creds = $this->getCredentials('instagram');

        if (!$creds || empty($creds['access_token']) || empty($creds['instagram_account_id'])) {
            return ['success' => false, 'error' => 'Instagram not connected or missing credentials'];
        }

        try {
            $accountId = $creds['instagram_account_id'];
            $token = $creds['access_token'];
            $mediaUrl = $content['media_url'] ?? null;
            $caption = $content['caption'] ?? '';
            $isVideo = $content['is_video'] ?? false;

            // Step 1: Create media container
            $containerParams = [
                'access_token' => $token,
                'caption'      => $caption,
            ];

            if ($isVideo) {
                $containerParams['media_type'] = 'VIDEO';
                $containerParams['video_url'] = $mediaUrl;
            } else {
                $containerParams['image_url'] = $mediaUrl;
            }

            $container = Http::post(
                "https://graph.facebook.com/v18.0/{$accountId}/media",
                $containerParams
            )->json();

            if (!isset($container['id'])) {
                return ['success' => false, 'error' => $container['error']['message'] ?? 'Failed to create media container'];
            }

            // Step 2: Publish the container
            $publish = Http::post(
                "https://graph.facebook.com/v18.0/{$accountId}/media_publish",
                [
                    'access_token'       => $token,
                    'creation_id'        => $container['id'],
                ]
            )->json();

            if (isset($publish['id'])) {
                return [
                    'success'     => true,
                    'platform_id' => $publish['id'],
                    'url'         => "https://instagram.com/p/{$publish['id']}",
                ];
            }

            return ['success' => false, 'error' => $publish['error']['message'] ?? 'Failed to publish'];

        } catch (\Exception $e) {
            Log::error("Instagram publish error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // FACEBOOK (via Graph API)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to Facebook Page via Graph API.
     */
    public function publishToFacebook(array $content): array
    {
        $creds = $this->getCredentials('facebook');

        if (!$creds || empty($creds['access_token']) || empty($creds['page_id'])) {
            return ['success' => false, 'error' => 'Facebook not connected'];
        }

        try {
            $pageId = $creds['page_id'];
            $token = $creds['page_access_token'] ?? $creds['access_token'];
            $mediaUrl = $content['media_url'] ?? null;
            $message = $content['caption'] ?? '';

            if ($mediaUrl) {
                // Photo post
                $response = Http::post(
                    "https://graph.facebook.com/v18.0/{$pageId}/photos",
                    [
                        'access_token' => $token,
                        'url'          => $mediaUrl,
                        'caption'      => $message,
                    ]
                )->json();
            } else {
                // Text post
                $response = Http::post(
                    "https://graph.facebook.com/v18.0/{$pageId}/feed",
                    [
                        'access_token' => $token,
                        'message'      => $message,
                    ]
                )->json();
            }

            if (isset($response['id'])) {
                return [
                    'success'     => true,
                    'platform_id' => $response['id'],
                    'url'         => "https://facebook.com/{$response['id']}",
                ];
            }

            return ['success' => false, 'error' => $response['error']['message'] ?? 'Failed to publish'];

        } catch (\Exception $e) {
            Log::error("Facebook publish error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // YOUTUBE (via YouTube Data API v3)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Upload video to YouTube.
     * Note: Requires OAuth2 and file path (not URL).
     */
    public function publishToYouTube(array $content): array
    {
        $creds = $this->getCredentials('youtube');

        if (!$creds || empty($creds['access_token'])) {
            return ['success' => false, 'error' => 'YouTube not connected'];
        }

        // YouTube requires resumable upload with actual file
        // This is a simplified stub — full implementation needs Google API client

        return [
            'success' => false,
            'error'   => 'YouTube upload requires Google API client library. See documentation.',
            'hint'    => 'Install google/apiclient package and implement resumable upload.',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // LINKEDIN (via Community Management API)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to LinkedIn via Community Management API.
     */
    public function publishToLinkedIn(array $content): array
    {
        $creds = $this->getCredentials('linkedin');

        if (!$creds || empty($creds['access_token'])) {
            return ['success' => false, 'error' => 'LinkedIn not connected'];
        }

        try {
            $token = $creds['access_token'];
            $authorId = $creds['organization_id'] ?? $creds['person_id'] ?? null;
            $message = $content['caption'] ?? '';
            $mediaUrl = $content['media_url'] ?? null;

            if (!$authorId) {
                return ['success' => false, 'error' => 'Missing LinkedIn author ID'];
            }

            $postData = [
                'author'           => "urn:li:organization:{$authorId}",
                'lifecycleState'   => 'PUBLISHED',
                'specificContent'  => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $message],
                        'shareMediaCategory' => $mediaUrl ? 'IMAGE' : 'NONE',
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ];

            $response = Http::withToken($token)
                ->post('https://api.linkedin.com/v2/ugcPosts', $postData)
                ->json();

            if (isset($response['id'])) {
                return [
                    'success'     => true,
                    'platform_id' => $response['id'],
                ];
            }

            return ['success' => false, 'error' => $response['message'] ?? 'Failed to publish'];

        } catch (\Exception $e) {
            Log::error("LinkedIn publish error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TIKTOK (via Content Posting API)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to TikTok via Content Posting API.
     */
    public function publishToTikTok(array $content): array
    {
        $creds = $this->getCredentials('tiktok');

        if (!$creds || empty($creds['access_token'])) {
            return ['success' => false, 'error' => 'TikTok not connected'];
        }

        // TikTok Content Posting API has specific requirements
        // This is a simplified implementation

        return [
            'success' => false,
            'error'   => 'TikTok API requires video file upload and app approval.',
            'hint'    => 'Visit developers.tiktok.com to set up Content Posting API.',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // TWITTER/X (via API v2)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Publish to Twitter/X via API v2.
     */
    public function publishToTwitter(array $content): array
    {
        $creds = $this->getCredentials('twitter');

        if (!$creds) {
            return ['success' => false, 'error' => 'Twitter not connected'];
        }

        try {
            // Twitter v2 uses OAuth 1.0a — simplified version here
            $message = $content['caption'] ?? '';

            // Would need OAuth1 signature — this is a placeholder
            return [
                'success' => false,
                'error'   => 'Twitter requires OAuth 1.0a signing. Use abraham/twitteroauth package.',
            ];

        } catch (\Exception $e) {
            Log::error("Twitter publish error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SNAPCHAT (Manual — prepare content only)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Prepare content for Snapchat (no API publish).
     */
    public function prepareForSnapchat(array $content): array
    {
        return [
            'success' => true,
            'type'    => 'manual',
            'message' => 'Snapchat content prepared for manual posting.',
            'content' => [
                'caption'   => $content['caption'] ?? '',
                'media_url' => $content['media_url'] ?? null,
            ],
            'instructions' => [
                'Save the media to your device',
                'Open Snapchat and create a new Story',
                'Add the media and caption',
                'Post to your Story',
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get credentials for a platform.
     */
    private function getCredentials(string $platform): ?array
    {
        $social = SocialPlatform::where('business_id', $this->businessId)
            ->where('key', $platform)
            ->where('connected', true)
            ->first();

        if (!$social) {
            return null;
        }

        return $social->credentials;
    }

    /**
     * Update post status after publishing.
     */
    public function updatePostStatus(int $postId, string $platform, array $result): void
    {
        $post = Post::forBusiness($this->businessId)->find($postId);

        if ($post) {
            $meta = $post->meta ?? [];
            $meta['publish_result'][$platform] = $result;

            if ($result['success']) {
                $post->update([
                    'status'       => 'published',
                    'published_at' => now(),
                    'meta'         => $meta,
                ]);
            } else {
                $post->update([
                    'status' => 'failed',
                    'meta'   => $meta,
                ]);
            }
        }
    }
}
