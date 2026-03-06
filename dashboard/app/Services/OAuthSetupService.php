<?php

namespace App\Services;

use App\Models\Business;
use App\Models\SocialPlatform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth Setup Service — handles OAuth 2.0 authorization for all social platforms.
 *
 * Platforms supported: TikTok, Google (YouTube), LinkedIn, Twitter/X, Meta (Instagram/Facebook)
 */
class OAuthSetupService
{
    protected int $businessId;
    protected string $redirectBase;

    // ═══════════════════════════════════════════════════════════════════════
    // PLATFORM OAUTH CONFIGURATIONS
    // ═══════════════════════════════════════════════════════════════════════

    protected const PLATFORM_CONFIG = [
        'tiktok' => [
            'auth_url'       => 'https://www.tiktok.com/v2/auth/authorize/',
            'token_url'      => 'https://open.tiktokapis.com/v2/oauth/token/',
            'scopes'         => 'user.info.basic,video.list,video.publish,video.upload',
            'docs_url'       => 'https://developers.tiktok.com/',
            'instructions'   => [
                'Go to https://developers.tiktok.com/',
                'Sign in and click "Manage apps" → "Connect an app"',
                'Set App name and upload icon',
                'Add Products: Content Posting API + Login Kit',
                'Copy Client Key and Client Secret',
            ],
            'required_fields' => ['client_key', 'client_secret'],
        ],
        'google' => [
            'auth_url'       => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url'      => 'https://oauth2.googleapis.com/token',
            'scopes'         => 'https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/userinfo.email',
            'docs_url'       => 'https://console.cloud.google.com/apis',
            'instructions'   => [
                'Go to Google Cloud Console',
                'Create new project or select existing',
                'Enable YouTube Data API v3',
                'Go to Credentials → Create OAuth Client ID',
                'Set Application Type: Web Application',
                'Add authorized redirect URI',
                'Copy Client ID and Client Secret',
            ],
            'required_fields' => ['client_id', 'client_secret'],
        ],
        'linkedin' => [
            'auth_url'       => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url'      => 'https://www.linkedin.com/oauth/v2/accessToken',
            'scopes'         => 'r_liteprofile r_emailaddress w_member_social',
            'docs_url'       => 'https://www.linkedin.com/developers/apps',
            'instructions'   => [
                'Go to LinkedIn Developer Portal',
                'Create new app',
                'Add products: Share on LinkedIn, Sign In with LinkedIn',
                'In Auth tab, add redirect URL',
                'Copy Client ID and Client Secret',
            ],
            'required_fields' => ['client_id', 'client_secret'],
        ],
        'twitter' => [
            'auth_url'       => 'https://twitter.com/i/oauth2/authorize',
            'token_url'      => 'https://api.twitter.com/2/oauth2/token',
            'scopes'         => 'tweet.read tweet.write users.read offline.access',
            'docs_url'       => 'https://developer.twitter.com/en/portal/dashboard',
            'instructions'   => [
                'Go to Twitter Developer Portal',
                'Create new project and app',
                'Set up OAuth 2.0 with PKCE',
                'Add callback URL',
                'Copy Client ID (OAuth 2.0)',
                'Generate API Key and Secret (OAuth 1.0a)',
            ],
            'required_fields' => ['client_id', 'api_key', 'api_secret'],
        ],
        'meta' => [
            'auth_url'       => 'https://www.facebook.com/v18.0/dialog/oauth',
            'token_url'      => 'https://graph.facebook.com/v18.0/oauth/access_token',
            'scopes'         => 'instagram_basic instagram_content_publish pages_show_list pages_read_engagement',
            'docs_url'       => 'https://developers.facebook.com/apps/',
            'instructions'   => [
                'Go to Meta Developer Portal',
                'Create new app (Business type)',
                'Add Instagram Graph API product',
                'Set up Instagram Basic Display',
                'Copy App ID and App Secret',
                'Get Page Access Token for publishing',
            ],
            'required_fields' => ['app_id', 'app_secret'],
        ],
        'snapchat' => [
            'auth_url'       => 'https://accounts.snapchat.com/accounts/oauth2/auth',
            'token_url'      => 'https://accounts.snapchat.com/accounts/oauth2/token',
            'scopes'         => 'snapchat-marketing-api',
            'docs_url'       => 'https://business.snapchat.com/',
            'instructions'   => [
                'Go to Snapchat Business Manager',
                'Create developer app',
                'Add OAuth redirect URI',
                'Copy Client ID and Client Secret',
            ],
            'required_fields' => ['client_id', 'client_secret'],
        ],
        'pinterest' => [
            'auth_url'       => 'https://www.pinterest.com/oauth/',
            'token_url'      => 'https://api.pinterest.com/v5/oauth/token',
            'scopes'         => 'boards:read boards:write pins:read pins:write user_accounts:read',
            'docs_url'       => 'https://developers.pinterest.com/',
            'instructions'   => [
                'Go to Pinterest Developers',
                'Create new app',
                'Add redirect URI',
                'Copy App ID and App Secret',
            ],
            'required_fields' => ['app_id', 'app_secret'],
        ],
    ];

    public function __construct(int $businessId)
    {
        $this->businessId = $businessId;
        $this->redirectBase = config('app.url') . '/api/oauth/callback';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET SETUP INSTRUCTIONS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get OAuth setup instructions for a platform.
     */
    public function getSetupInstructions(string $platform): array
    {
        $config = self::PLATFORM_CONFIG[$platform] ?? null;
        if (!$config) {
            return ['success' => false, 'error' => 'Unknown platform'];
        }

        return [
            'success'         => true,
            'platform'        => $platform,
            'docs_url'        => $config['docs_url'],
            'instructions'    => $config['instructions'],
            'required_fields' => $config['required_fields'],
            'redirect_uri'    => $this->redirectBase . '/' . $platform,
            'scopes'          => $config['scopes'],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // OAUTH FLOW
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate OAuth authorization URL for a platform.
     */
    public function getAuthorizationUrl(string $platform, array $credentials): array
    {
        $config = self::PLATFORM_CONFIG[$platform] ?? null;
        if (!$config) {
            return ['success' => false, 'error' => 'Unknown platform'];
        }

        // Validate required credentials
        foreach ($config['required_fields'] as $field) {
            if (empty($credentials[$field])) {
                return ['success' => false, 'error' => "Missing required field: {$field}"];
            }
        }

        // Generate state and PKCE tokens
        $state = Str::random(64);
        $codeVerifier = Str::random(128);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Store state for callback verification
        DB::table('oauth_states')->insert([
            'business_id'   => $this->businessId,
            'platform'      => $platform,
            'state'         => $state,
            'code_verifier' => $codeVerifier,
            'scopes'        => json_encode(explode(' ', $config['scopes'])),
            'expires_at'    => now()->addMinutes(10),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Store app credentials temporarily
        SocialPlatform::updateOrCreate(
            ['business_id' => $this->businessId, 'key' => $platform],
            [
                'platform'    => $platform,
                'name'        => ucfirst($platform),
                'connected'   => false,
                'status'      => 'active',
                'credentials' => array_merge($credentials, ['pending_oauth' => true]),
            ]
        );

        // Build authorization URL
        $redirectUri = $this->redirectBase . '/' . $platform;

        $params = match($platform) {
            'tiktok' => [
                'client_key'     => $credentials['client_key'],
                'scope'          => $config['scopes'],
                'response_type'  => 'code',
                'redirect_uri'   => $redirectUri,
                'state'          => $state,
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
            ],
            'google', 'linkedin', 'twitter', 'meta', 'snapchat', 'pinterest' => [
                'client_id'      => $credentials['client_id'] ?? $credentials['app_id'],
                'scope'          => $config['scopes'],
                'response_type'  => 'code',
                'redirect_uri'   => $redirectUri,
                'state'          => $state,
                'access_type'    => 'offline',
                'prompt'         => 'consent',
            ],
            default => [],
        };

        $authUrl = $config['auth_url'] . '?' . http_build_query($params);

        return [
            'success'       => true,
            'auth_url'      => $authUrl,
            'state'         => $state,
            'redirect_uri'  => $redirectUri,
            'expires_in'    => 600,
        ];
    }

    /**
     * Handle OAuth callback and exchange code for tokens.
     */
    public function handleCallback(string $platform, string $code, string $state): array
    {
        // Verify state
        $oauthState = DB::table('oauth_states')
            ->where('platform', $platform)
            ->where('state', $state)
            ->where('expires_at', '>', now())
            ->first();

        if (!$oauthState) {
            return ['success' => false, 'error' => 'Invalid or expired state'];
        }

        // Clean up state
        DB::table('oauth_states')->where('id', $oauthState->id)->delete();

        $config = self::PLATFORM_CONFIG[$platform] ?? null;
        if (!$config) {
            return ['success' => false, 'error' => 'Unknown platform'];
        }

        // Get stored credentials
        $socialPlatform = SocialPlatform::where('business_id', $oauthState->business_id)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('key', $platform);
            })
            ->first();

        if (!$socialPlatform) {
            return ['success' => false, 'error' => 'Platform credentials not found'];
        }

        $credentials = $socialPlatform->credentials;
        $redirectUri = $this->redirectBase . '/' . $platform;

        // Exchange code for tokens
        try {
            $tokenParams = match($platform) {
                'tiktok' => [
                    'client_key'    => $credentials['client_key'],
                    'client_secret' => $credentials['client_secret'],
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirectUri,
                    'code_verifier' => $oauthState->code_verifier,
                ],
                'google' => [
                    'client_id'     => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirectUri,
                ],
                'linkedin' => [
                    'client_id'     => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirectUri,
                ],
                'twitter' => [
                    'client_id'     => $credentials['client_id'],
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirectUri,
                    'code_verifier' => $oauthState->code_verifier,
                ],
                'meta' => [
                    'client_id'     => $credentials['app_id'],
                    'client_secret' => $credentials['app_secret'],
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                ],
                default => [],
            };

            $response = Http::asForm()->post($config['token_url'], $tokenParams);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error'   => 'Token exchange failed: ' . $response->body(),
                ];
            }

            $tokens = $response->json();

            // Store tokens securely
            unset($credentials['pending_oauth']);
            $credentials['access_token'] = $tokens['access_token'] ?? null;
            $credentials['refresh_token'] = $tokens['refresh_token'] ?? null;
            $credentials['token_expires_at'] = isset($tokens['expires_in'])
                ? now()->addSeconds($tokens['expires_in'])->toIso8601String()
                : null;
            $credentials['open_id'] = $tokens['open_id'] ?? null;  // TikTok

            // Encrypt sensitive tokens before storing
            $encryptionService = new \App\Services\Security\EncryptionService();
            if (!empty($credentials['access_token'])) {
                $credentials['access_token_encrypted'] = $encryptionService->encrypt($credentials['access_token']);
            }
            if (!empty($credentials['refresh_token'])) {
                $credentials['refresh_token_encrypted'] = $encryptionService->encrypt($credentials['refresh_token']);
            }

            $socialPlatform->update([
                'connected'       => true,
                'platform'        => $platform,
                'status'          => 'active',
                'credentials'     => $credentials,
                'last_tested_at'  => now(),
                'last_test_status'=> 'ok',
            ]);

            return [
                'success'     => true,
                'platform'    => $platform,
                'connected'   => true,
                'expires_at'  => $credentials['token_expires_at'] ?? null,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Token exchange failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Refresh expired access token.
     */
    public function refreshToken(string $platform): array
    {
        $socialPlatform = SocialPlatform::where('business_id', $this->businessId)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('key', $platform);
            })
            ->first();

        if (!$socialPlatform || empty($socialPlatform->credentials['refresh_token'])) {
            return ['success' => false, 'error' => 'No refresh token available'];
        }

        $config = self::PLATFORM_CONFIG[$platform] ?? null;
        if (!$config) {
            return ['success' => false, 'error' => 'Unknown platform'];
        }

        $credentials = $socialPlatform->credentials;
        $encryptionService = new \App\Services\Security\EncryptionService();

        // Decrypt refresh token
        $refreshToken = !empty($credentials['refresh_token_encrypted'])
            ? $encryptionService->decrypt($credentials['refresh_token_encrypted'])
            : $credentials['refresh_token'];

        try {
            $tokenParams = [
                'client_id'     => $credentials['client_id'] ?? $credentials['client_key'] ?? $credentials['app_id'],
                'client_secret' => $credentials['client_secret'] ?? $credentials['app_secret'],
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ];

            $response = Http::asForm()->post($config['token_url'], $tokenParams);

            if (!$response->successful()) {
                return ['success' => false, 'error' => 'Token refresh failed'];
            }

            $tokens = $response->json();

            // Update stored tokens
            $credentials['access_token'] = $tokens['access_token'];
            $credentials['access_token_encrypted'] = $encryptionService->encrypt($tokens['access_token']);
            if (!empty($tokens['refresh_token'])) {
                $credentials['refresh_token'] = $tokens['refresh_token'];
                $credentials['refresh_token_encrypted'] = $encryptionService->encrypt($tokens['refresh_token']);
            }
            $credentials['token_expires_at'] = isset($tokens['expires_in'])
                ? now()->addSeconds($tokens['expires_in'])->toIso8601String()
                : null;

            $socialPlatform->update(['credentials' => $credentials]);

            return [
                'success'    => true,
                'expires_at' => $credentials['token_expires_at'],
            ];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Token refresh failed: ' . $e->getMessage()];
        }
    }

    /**
     * Get all platform configs (for frontend setup wizard).
     */
    public function getAllPlatformConfigs(): array
    {
        $configs = [];
        foreach (self::PLATFORM_CONFIG as $platform => $config) {
            $configs[$platform] = [
                'name'            => ucfirst($platform),
                'docs_url'        => $config['docs_url'],
                'required_fields' => $config['required_fields'],
                'scopes'          => $config['scopes'],
                'redirect_uri'    => $this->redirectBase . '/' . $platform,
            ];
        }
        return $configs;
    }

    /**
     * Test platform connection.
     */
    public function testConnection(string $platform): array
    {
        $socialPlatform = SocialPlatform::where('business_id', $this->businessId)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('key', $platform);
            })
            ->first();

        if (!$socialPlatform || !$socialPlatform->connected) {
            return ['success' => false, 'error' => 'Platform not connected'];
        }

        $credentials = $socialPlatform->credentials;

        // Check if token is expired
        if (!empty($credentials['token_expires_at'])) {
            $expiresAt = \Carbon\Carbon::parse($credentials['token_expires_at']);
            if ($expiresAt->isPast()) {
                // Attempt refresh
                $refresh = $this->refreshToken($platform);
                if (!$refresh['success']) {
                    return ['success' => false, 'error' => 'Token expired and refresh failed'];
                }
            }
        }

        // Platform-specific API test
        try {
            $encryptionService = new \App\Services\Security\EncryptionService();
            $accessToken = !empty($credentials['access_token_encrypted'])
                ? $encryptionService->decrypt($credentials['access_token_encrypted'])
                : $credentials['access_token'];

            $testResult = match($platform) {
                'tiktok' => Http::withToken($accessToken)
                    ->get('https://open.tiktokapis.com/v2/user/info/')
                    ->successful(),
                'google' => Http::withToken($accessToken)
                    ->get('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true')
                    ->successful(),
                'linkedin' => Http::withToken($accessToken)
                    ->get('https://api.linkedin.com/v2/me')
                    ->successful(),
                'twitter' => Http::withToken($accessToken)
                    ->get('https://api.twitter.com/2/users/me')
                    ->successful(),
                'meta' => Http::get('https://graph.facebook.com/me', [
                        'access_token' => $accessToken,
                    ])->successful(),
                default => true,
            };

            $socialPlatform->update([
                'last_tested_at'   => now(),
                'last_test_status' => $testResult ? 'ok' : 'failed',
            ]);

            return [
                'success' => $testResult,
                'message' => $testResult ? 'Connection verified' : 'API test failed',
            ];

        } catch (\Exception $e) {
            $socialPlatform->update([
                'last_tested_at'   => now(),
                'last_test_status' => 'error',
                'last_test_message'=> $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
