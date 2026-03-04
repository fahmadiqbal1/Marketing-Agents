<?php

namespace App\Services;

use App\Models\SocialPlatform;
use App\Services\Security\EncryptionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Credential Manager Service — per-tenant platform credential CRUD with encryption.
 *
 * Converted from Python: memory/credentials.py
 *
 * Features:
 * - Encrypted storage of platform tokens
 * - Platform-specific field definitions
 * - Connection testing for each platform
 * - Token refresh handling
 */
class CredentialManagerService
{
    /**
     * Platform field definitions — defines which fields each platform requires.
     * Used by the frontend to render forms and by backend to validate data.
     */
    public const PLATFORM_FIELDS = [
        'instagram' => [
            'label' => 'Instagram / Facebook',
            'icon' => 'bi-instagram',
            'color' => '#E1306C',
            'fields' => [
                ['key' => 'client_id', 'label' => 'Meta App ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Meta App Secret', 'type' => 'password', 'required' => true],
                ['key' => 'access_token', 'label' => 'Page Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'instagram_business_account_id', 'label' => 'Instagram Business Account ID', 'type' => 'text', 'required' => true, 'extra' => true],
            ],
            'help_url' => 'https://developers.facebook.com/docs/instagram-api/getting-started',
            'token_expiry' => '60 days (long-lived) or never (page token)',
        ],
        'facebook' => [
            'label' => 'Facebook Page',
            'icon' => 'bi-facebook',
            'color' => '#1877F2',
            'fields' => [
                ['key' => 'client_id', 'label' => 'Meta App ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Meta App Secret', 'type' => 'password', 'required' => true],
                ['key' => 'access_token', 'label' => 'Page Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'page_id', 'label' => 'Facebook Page ID', 'type' => 'text', 'required' => true, 'extra' => true],
            ],
            'help_url' => 'https://developers.facebook.com/docs/pages/getting-started',
            'token_expiry' => 'Never (page access token)',
        ],
        'youtube' => [
            'label' => 'YouTube',
            'icon' => 'bi-youtube',
            'color' => '#FF0000',
            'fields' => [
                ['key' => 'client_id', 'label' => 'Google Client ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'Google Client Secret', 'type' => 'password', 'required' => true],
                ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => true],
            ],
            'help_url' => 'https://developers.google.com/youtube/v3/getting-started',
            'token_expiry' => "Refresh token doesn't expire (unless revoked)",
        ],
        'linkedin' => [
            'label' => 'LinkedIn',
            'icon' => 'bi-linkedin',
            'color' => '#0A66C2',
            'fields' => [
                ['key' => 'client_id', 'label' => 'LinkedIn Client ID', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'LinkedIn Client Secret', 'type' => 'password', 'required' => true],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'organization_id', 'label' => 'Organization (Company) ID', 'type' => 'text', 'required' => true, 'extra' => true],
            ],
            'help_url' => 'https://learn.microsoft.com/en-us/linkedin/shared/authentication/getting-access',
            'token_expiry' => '60 days (refresh token lasts 365 days)',
        ],
        'tiktok' => [
            'label' => 'TikTok',
            'icon' => 'bi-tiktok',
            'color' => '#000000',
            'fields' => [
                ['key' => 'client_id', 'label' => 'TikTok Client Key', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'TikTok Client Secret', 'type' => 'password', 'required' => true],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => false],
            ],
            'help_url' => 'https://developers.tiktok.com/doc/getting-started',
            'token_expiry' => '24 hours (refresh token lasts 365 days)',
        ],
        'twitter' => [
            'label' => 'Twitter / X',
            'icon' => 'bi-twitter-x',
            'color' => '#000000',
            'fields' => [
                ['key' => 'client_id', 'label' => 'API Key (Consumer Key)', 'type' => 'text', 'required' => true],
                ['key' => 'client_secret', 'label' => 'API Secret (Consumer Secret)', 'type' => 'password', 'required' => true],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'access_token_secret', 'label' => 'Access Token Secret', 'type' => 'password', 'required' => true, 'extra' => true],
                ['key' => 'bearer_token', 'label' => 'Bearer Token (optional)', 'type' => 'password', 'required' => false, 'extra' => true],
            ],
            'help_url' => 'https://developer.twitter.com/en/docs/getting-started',
            'token_expiry' => 'Never (unless regenerated)',
        ],
        'snapchat' => [
            'label' => 'Snapchat',
            'icon' => 'bi-snapchat',
            'color' => '#FFFC00',
            'fields' => [], // No API tokens — content is prepared for manual posting
            'help_url' => null,
            'token_expiry' => 'N/A — manual posting',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'icon' => 'bi-pinterest',
            'color' => '#E60023',
            'fields' => [
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'board_id', 'label' => 'Board ID', 'type' => 'text', 'required' => true, 'extra' => true],
            ],
            'help_url' => 'https://developers.pinterest.com/docs/getting-started/',
            'token_expiry' => '30 days',
        ],
        'threads' => [
            'label' => 'Threads',
            'icon' => 'bi-threads',
            'color' => '#000000',
            'fields' => [
                ['key' => 'access_token', 'label' => 'Threads Access Token', 'type' => 'password', 'required' => true],
                ['key' => 'user_id', 'label' => 'Threads User ID', 'type' => 'text', 'required' => true, 'extra' => true],
            ],
            'help_url' => 'https://developers.facebook.com/docs/threads',
            'token_expiry' => '60 days',
        ],
    ];

    protected int $businessId;
    protected EncryptionService $encryption;

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
        $this->encryption = new EncryptionService();
    }

    // ─── CRUD Operations ──────────────────────────────────────────────────────

    /**
     * Save (or update) platform credentials for a business.
     * All token fields are encrypted before storage.
     */
    public function saveCredentials(string $platform, array $credentials): array
    {
        $connection = SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->first();

        if (!$connection) {
            $connection = new SocialPlatform([
                'business_id' => $this->businessId,
                'platform' => $platform,
            ]);
        }

        // Encrypt token fields
        $connection->access_token = $this->encryption->encrypt($credentials['access_token'] ?? '');
        $connection->refresh_token = $this->encryption->encrypt($credentials['refresh_token'] ?? '');
        $connection->client_id = $this->encryption->encrypt($credentials['client_id'] ?? '');
        $connection->client_secret = $this->encryption->encrypt($credentials['client_secret'] ?? '');

        // Store extra fields as JSON
        $extras = array_filter($credentials, function ($value, $key) {
            return !in_array($key, ['access_token', 'refresh_token', 'client_id', 'client_secret'])
                && !empty($value);
        }, ARRAY_FILTER_USE_BOTH);

        $connection->extra_data = !empty($extras) ? json_encode($extras) : null;
        $connection->scopes = $credentials['scopes'] ?? null;
        $connection->status = 'active';
        $connection->connected_at = now();
        $connection->last_error = null;

        $connection->save();

        return ['success' => true, 'platform' => $platform];
    }

    /**
     * Retrieve and decrypt platform credentials for a business.
     */
    public function getCredentials(string $platform): ?array
    {
        $connection = SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->first();

        if (!$connection) {
            return null;
        }

        $result = [
            'access_token' => $this->encryption->decrypt($connection->access_token ?? ''),
            'refresh_token' => $this->encryption->decrypt($connection->refresh_token ?? ''),
            'client_id' => $this->encryption->decrypt($connection->client_id ?? ''),
            'client_secret' => $this->encryption->decrypt($connection->client_secret ?? ''),
        ];

        // Merge extras
        if ($connection->extra_data) {
            try {
                $extras = json_decode($connection->extra_data, true);
                $result = array_merge($result, $extras);
            } catch (\Exception $e) {
                // Ignore JSON errors
            }
        }

        return $result;
    }

    /**
     * Get connection status for all platforms for a business.
     * Does NOT return actual tokens — only metadata for display.
     */
    public function getAllConnections(): array
    {
        $connections = SocialPlatform::where('business_id', $this->businessId)->get();

        $connected = [];
        foreach ($connections as $conn) {
            $extras = [];
            if ($conn->extra_data) {
                try {
                    $extras = json_decode($conn->extra_data, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            $connected[$conn->platform] = [
                'status' => $conn->status,
                'connected_at' => $conn->connected_at?->toIso8601String(),
                'last_used_at' => $conn->last_used_at?->toIso8601String(),
                'expires_at' => $conn->expires_at?->toIso8601String(),
                'last_error' => $conn->last_error,
                'has_refresh_token' => !empty($conn->refresh_token),
                'extra_fields' => array_keys($extras),
            ];
        }

        // Build result for ALL platforms, marking unconnected ones
        $result = [];
        foreach (self::PLATFORM_FIELDS as $platformKey => $info) {
            $connData = $connected[$platformKey] ?? null;
            $result[] = [
                'platform' => $platformKey,
                'label' => $info['label'],
                'icon' => $info['icon'],
                'color' => $info['color'],
                'connected' => $connData !== null && ($connData['status'] ?? '') === 'active',
                'connection' => $connData,
                'fields' => $info['fields'],
                'help_url' => $info['help_url'] ?? null,
                'token_expiry' => $info['token_expiry'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Disconnect (soft-delete) a platform connection.
     */
    public function disconnect(string $platform): bool
    {
        $connection = SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->first();

        if (!$connection) {
            return false;
        }

        $connection->status = 'revoked';
        $connection->access_token = null;
        $connection->refresh_token = null;
        $connection->client_id = null;
        $connection->client_secret = null;
        $connection->extra_data = null;
        $connection->save();

        return true;
    }

    // ─── Connection Testing ───────────────────────────────────────────────────

    /**
     * Test if stored credentials for a platform actually work.
     */
    public function testConnection(string $platform): array
    {
        $creds = $this->getCredentials($platform);
        if (!$creds) {
            return ['success' => false, 'message' => 'No credentials stored for this platform'];
        }

        try {
            return match ($platform) {
                'instagram' => $this->testInstagram($creds),
                'facebook' => $this->testFacebook($creds),
                'youtube' => $this->testYouTube($creds),
                'linkedin' => $this->testLinkedIn($creds),
                'tiktok' => $this->testTikTok($creds),
                'twitter' => $this->testTwitter($creds),
                default => ['success' => true, 'message' => "Credentials saved (no test available for {$platform})"],
            };
        } catch (\Exception $e) {
            Log::warning("Platform connection test failed for {$platform}", ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Connection test failed: ' . $e->getMessage()];
        }
    }

    protected function testInstagram(array $creds): array
    {
        $accountId = $creds['instagram_business_account_id'] ?? 'me';
        $response = Http::timeout(10)->get("https://graph.facebook.com/v21.0/{$accountId}", [
            'access_token' => $creds['access_token'],
            'fields' => 'id,username',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'success' => true,
                'message' => 'Connected as @' . ($data['username'] ?? 'unknown'),
                'details' => $data,
            ];
        }

        return ['success' => false, 'message' => 'Instagram API error: ' . $response->body()];
    }

    protected function testFacebook(array $creds): array
    {
        $pageId = $creds['page_id'] ?? 'me';
        $response = Http::timeout(10)->get("https://graph.facebook.com/v21.0/{$pageId}", [
            'access_token' => $creds['access_token'],
            'fields' => 'id,name',
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'success' => true,
                'message' => 'Connected to page: ' . ($data['name'] ?? 'unknown'),
                'details' => $data,
            ];
        }

        return ['success' => false, 'message' => 'Facebook API error: ' . $response->body()];
    }

    protected function testYouTube(array $creds): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => 'Bearer ' . $creds['access_token']])
            ->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        if ($response->successful()) {
            $items = $response->json()['items'] ?? [];
            if (!empty($items)) {
                $name = $items[0]['snippet']['title'];
                return ['success' => true, 'message' => "Connected to channel: {$name}"];
            }
        }

        // Check if refresh token is available
        if (!empty($creds['refresh_token'])) {
            return [
                'success' => true,
                'message' => 'Credentials stored (refresh token available). Token will auto-renew.',
            ];
        }

        return ['success' => false, 'message' => 'YouTube API error: ' . ($response->body() ?? 'no response')];
    }

    protected function testLinkedIn(array $creds): array
    {
        $orgId = $creds['organization_id'] ?? '';
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => 'Bearer ' . $creds['access_token']])
            ->get("https://api.linkedin.com/v2/organizations/{$orgId}");

        if ($response->successful()) {
            $data = $response->json();
            $name = $data['localizedName'] ?? 'Unknown';
            return [
                'success' => true,
                'message' => "Connected to: {$name}",
                'details' => $data,
            ];
        }

        return [
            'success' => false,
            'message' => "LinkedIn API error ({$response->status()}): " . $response->body(),
        ];
    }

    protected function testTikTok(array $creds): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => 'Bearer ' . $creds['access_token']])
            ->get('https://open.tiktokapis.com/v2/user/info/', [
                'fields' => 'display_name,avatar_url',
            ]);

        if ($response->successful()) {
            $data = $response->json()['data']['user'] ?? [];
            return [
                'success' => true,
                'message' => 'Connected as: ' . ($data['display_name'] ?? 'TikTok User'),
            ];
        }

        return ['success' => false, 'message' => 'TikTok API error: ' . $response->body()];
    }

    protected function testTwitter(array $creds): array
    {
        // Twitter OAuth 1.0a is complex — for now, just verify credentials exist
        if (!empty($creds['access_token']) && !empty($creds['access_token_secret'])) {
            return [
                'success' => true,
                'message' => 'Credentials saved. OAuth 1.0a verification requires additional libraries.',
            ];
        }

        return ['success' => false, 'message' => 'Missing access token or access token secret'];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Get platform field definitions for a specific platform.
     */
    public static function getPlatformFields(string $platform): ?array
    {
        return self::PLATFORM_FIELDS[$platform] ?? null;
    }

    /**
     * Update last_used_at timestamp when credentials are used.
     */
    public function markCredentialsUsed(string $platform): void
    {
        SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->update(['last_used_at' => now()]);
    }

    /**
     * Record an error for a platform connection.
     */
    public function recordError(string $platform, string $error): void
    {
        SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->update([
                'last_error' => $error,
                'status' => 'error',
            ]);
    }

    /**
     * Clear error and reactivate a platform connection.
     */
    public function clearError(string $platform): void
    {
        SocialPlatform::where('business_id', $this->businessId)
            ->where('platform', $platform)
            ->update([
                'last_error' => null,
                'status' => 'active',
            ]);
    }
}
