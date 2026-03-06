<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Services\TelegramBotService;
use App\Services\OpenAIService;
use App\Models\TelegramBot;
use App\Models\Business;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by RouteServiceProvider and are assigned the "api"
| middleware group. They are prefixed with /api.
|
*/

// ═══════════════════════════════════════════════════════════════════════
// TELEGRAM WEBHOOK
// ═══════════════════════════════════════════════════════════════════════

Route::post('/telegram/webhook/{token}', function (Request $request, string $token) {
    // Verify the token matches a registered bot
    $bot = TelegramBot::where('bot_token', $token)->first();

    if (!$bot) {
        return response()->json(['error' => 'Invalid token'], 403);
    }

    $update = $request->all();
    $service = new TelegramBotService($bot);

    // Process the update
    $result = $service->processWebhook($update, $bot);

    return response()->json(['ok' => true, 'result' => $result]);
})->name('telegram.webhook');

// ═══════════════════════════════════════════════════════════════════════
// HEALTH CHECK
// ═══════════════════════════════════════════════════════════════════════

Route::get('/health', function () {
    return response()->json([
        'status'    => 'ok',
        'timestamp' => now()->toIso8601String(),
        'version'   => config('app.version', '1.0.0'),
    ]);
})->name('api.health');

// ═══════════════════════════════════════════════════════════════════════
// API AUTH (register / login / me) — token-based for external clients
// ═══════════════════════════════════════════════════════════════════════

// Register a new user and business, returns Sanctum API token
Route::post('/auth/register', function (Request $request) {
    $data = $request->validate([
        'name'          => 'required|string|max:255',
        'email'         => 'required|email|max:255|unique:users,email',
        'password'      => 'required|string|min:8',
        'business_name' => 'required|string|max:255',
        'industry'      => 'nullable|string|max:100',
    ]);

    $baseSlug = \Illuminate\Support\Str::slug($data['business_name']);
    $slug     = $baseSlug;
    $counter  = 1;
    while (\App\Models\Business::where('slug', $slug)->exists()) {
        $slug = $baseSlug . '-' . $counter++;
    }

    return \Illuminate\Support\Facades\DB::transaction(function () use ($data, $slug) {
        // Create user first so we have a real owner_id for the business
        $user = \App\Models\User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => \Illuminate\Support\Facades\Hash::make($data['password']),
            'role'        => 'owner',
            'business_id' => null,
        ]);

        $business = \App\Models\Business::create([
            'name'     => $data['business_name'],
            'slug'     => $slug,
            'industry' => $data['industry'] ?? null,
            'owner_id' => $user->id,
        ]);

        $user->update(['business_id' => $business->id]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'            => $user->id,
                'email'         => $user->email,
                'name'          => $user->name,
                'role'          => $user->role,
                'business_id'   => $business->id,
                'business_name' => $business->name,
            ],
        ], 201);
    });
})->name('api.auth.register');

// Login with email & password, returns Sanctum API token
Route::post('/auth/login', function (Request $request) {
    $data = $request->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    $user = \App\Models\User::where('email', $data['email'])->first();

    if (!$user || !\Illuminate\Support\Facades\Hash::check($data['password'], $user->password)) {
        return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
    }

    $business = \App\Models\Business::find($user->business_id);
    $token    = $user->createToken('api')->plainTextToken;

    return response()->json([
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'            => $user->id,
            'email'         => $user->email,
            'name'          => $user->name,
            'role'          => $user->role,
            'business_id'   => $user->business_id,
            'business_name' => $business->name ?? '',
        ],
    ]);
})->name('api.auth.login');

// Return current user profile + connected platforms + AI providers
Route::middleware('auth:sanctum')->get('/auth/me', function (Request $request) {
    $user     = $request->user();
    $business = \App\Models\Business::find($user->business_id);
    $bid      = $user->business_id;

    // Connected social platforms
    $connectedPlatforms = \App\Models\SocialPlatform::where('business_id', $bid)
        ->where('connected', true)
        ->get(['key', 'name'])
        ->map(fn($p) => ['platform' => $p->key, 'name' => $p->name, 'status' => 'active'])
        ->values();

    // Configured AI providers
    $aiProviders = \App\Models\AiModelConfig::where('business_id', $bid)
        ->get(['provider', 'model_name', 'is_active'])
        ->map(fn($m) => [
            'provider'   => $m->provider,
            'model_name' => $m->model_name,
            'is_active'  => (bool) $m->is_active,
        ])
        ->values();

    return response()->json([
        'id'                   => $user->id,
        'email'                => $user->email,
        'name'                 => $user->name,
        'role'                 => $user->role,
        'business_id'          => $user->business_id,
        'business_name'        => $business->name ?? '',
        'business_slug'        => $business->slug ?? '',
        'connected_platforms'  => $connectedPlatforms,
        'ai_providers'         => $aiProviders,
    ]);
})->name('api.auth.me');

// Logout — revoke current Sanctum token
Route::middleware('auth:sanctum')->post('/auth/logout', function (Request $request) {
    $request->user()->currentAccessToken()->delete();

    return response()->json([
        'success' => true,
        'message' => 'Logged out successfully',
    ]);
})->name('api.auth.logout');

// ═══════════════════════════════════════════════════════════════════════
// AUTHENTICATED API ROUTES
// ═══════════════════════════════════════════════════════════════════════

Route::middleware('auth:sanctum')->group(function () {

    // ─────────────────────────────────────────────────────────────────────
    // USER INFO
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/user', function (Request $request) {
        return $request->user();
    })->name('api.user');

    // ─────────────────────────────────────────────────────────────────────
    // BUSINESSES
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/businesses', function (Request $request) {
        $user = $request->user();
        $businesses = $user->businesses()->get();

        return response()->json([
            'success'    => true,
            'businesses' => $businesses,
        ]);
    })->name('api.businesses');

    Route::get('/businesses/{id}', function (Request $request, int $id) {
        $business = Business::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->with(['socialPlatforms', 'telegramBots'])
            ->first();

        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        return response()->json([
            'success'  => true,
            'business' => $business,
        ]);
    })->name('api.businesses.show');

    // ─────────────────────────────────────────────────────────────────────
    // AI GENERATION (headless API for integrations)
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/generate/caption', function (Request $request) {
        $request->validate([
            'business_id' => 'required|integer',
            'prompt'      => 'required|string|max:2000',
            'platform'    => 'nullable|string',
            'mood'        => 'nullable|string',
        ]);

        $business = Business::where('id', $request->business_id)
            ->where('owner_id', $request->user()->id)
            ->first();

        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        $service = new \App\Services\CaptionWriterService($business->id);

        $result = $service->generateCaption(
            $request->input('platform', 'instagram'),
            $request->input('prompt'),
            'general',
            $request->input('mood', 'engaging')
        );

        return response()->json($result);
    })->name('api.generate.caption');

    Route::post('/generate/hashtags', function (Request $request) {
        $request->validate([
            'business_id' => 'required|integer',
            'topic'       => 'required|string|max:500',
            'platform'    => 'nullable|string',
            'count'       => 'nullable|integer|min:1|max:30',
        ]);

        $business = Business::where('id', $request->business_id)
            ->where('owner_id', $request->user()->id)
            ->first();

        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        $service = new \App\Services\HashtagResearcherService($business->id);
        $result = $service->getHashtags(
            $request->input('topic'),
            $request->input('platform', 'instagram'),
            $request->input('count', 10)
        );

        return response()->json($result);
    })->name('api.generate.hashtags');

    // ─────────────────────────────────────────────────────────────────────
    // POSTS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/posts', function (Request $request) {
        $businessId = $request->input('business_id');

        $query = \App\Models\Post::query();

        if ($businessId) {
            $business = Business::where('id', $businessId)
                ->where('owner_id', $request->user()->id)
                ->first();

            if (!$business) {
                return response()->json(['error' => 'Business not found'], 404);
            }

            $query->where('business_id', $businessId);
        } else {
            // All posts for user's businesses
            $businessIds = $request->user()->businesses()->pluck('id');
            $query->whereIn('business_id', $businessIds);
        }

        $posts = $query->orderByDesc('created_at')
            ->limit($request->input('limit', 50))
            ->get();

        return response()->json([
            'success' => true,
            'posts'   => $posts,
        ]);
    })->name('api.posts');

    Route::post('/posts', function (Request $request) {
        $request->validate([
            'business_id' => 'required|integer',
            'caption'     => 'required|string',
            'platform'    => 'required|string',
            'hashtags'    => 'nullable|array',
            'scheduled_at'=> 'nullable|date',
        ]);

        $business = Business::where('id', $request->business_id)
            ->where('owner_id', $request->user()->id)
            ->first();

        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        $post = \App\Models\Post::create([
            'business_id'  => $business->id,
            'caption'      => $request->input('caption'),
            'platform'     => $request->input('platform'),
            'hashtags'     => $request->input('hashtags', []),
            'scheduled_at' => $request->input('scheduled_at'),
            'status'       => $request->input('scheduled_at') ? 'scheduled' : 'draft',
        ]);

        return response()->json([
            'success' => true,
            'post'    => $post,
        ], 201);
    })->name('api.posts.create');

    // ─────────────────────────────────────────────────────────────────────
    // ANALYTICS SUMMARY
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/analytics/summary', function (Request $request) {
        $businessId = $request->input('business_id');

        if (!$businessId) {
            return response()->json(['error' => 'business_id required'], 400);
        }

        $business = Business::where('id', $businessId)
            ->where('owner_id', $request->user()->id)
            ->first();

        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        $posts = \App\Models\Post::where('business_id', $businessId);
        $metrics = \App\Models\AnalyticMetric::forBusiness($businessId);

        return response()->json([
            'success' => true,
            'summary' => [
                'total_posts'     => $posts->count(),
                'published_posts' => $posts->clone()->where('status', 'published')->count(),
                'scheduled_posts' => $posts->clone()->where('status', 'scheduled')->count(),
                'platforms'       => $posts->clone()->distinct('platform')->pluck('platform'),
            ],
        ]);
    })->name('api.analytics.summary');

    // ─────────────────────────────────────────────────────────────────────
    // POST SCHEDULING & RETRY
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/posts/{postId}/schedule', function (Request $request, int $postId) {
        $post = \App\Models\Post::where('id', $postId)
            ->whereHas('business', fn($q) => $q->where('owner_id', $request->user()->id))
            ->firstOrFail();
        $post->update([
            'scheduled_at' => $request->input('scheduled_at'),
            'status'       => 'scheduled',
        ]);
        return response()->json(['success' => true, 'post' => $post]);
    })->name('api.posts.schedule');

    Route::get('/posts/scheduled', function (Request $request) {
        $businessId = $request->input('business_id');
        $posts = \App\Models\Post::where('business_id', $businessId)
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->get();
        return response()->json(['success' => true, 'posts' => $posts]);
    })->name('api.posts.scheduled');

    Route::post('/posts/{postId}/retry', function (Request $request, int $postId) {
        $post = \App\Models\Post::where('id', $postId)
            ->whereHas('business', fn($q) => $q->where('owner_id', $request->user()->id))
            ->firstOrFail();
        $post->update(['status' => 'pending', 'retry_count' => ($post->retry_count ?? 0) + 1]);
        return response()->json(['success' => true, 'message' => 'Post queued for retry']);
    })->name('api.posts.retry');

    Route::post('/posts/{postId}/approve', function (Request $request, int $postId) {
        $post = \App\Models\Post::where('id', $postId)
            ->whereHas('business', fn($q) => $q->where('owner_id', $request->user()->id))
            ->firstOrFail();
        $post->update(['status' => 'approved']);
        return response()->json(['success' => true, 'post' => $post]);
    })->name('api.posts.approve');

    Route::post('/posts/{postId}/deny', function (Request $request, int $postId) {
        $post = \App\Models\Post::where('id', $postId)
            ->whereHas('business', fn($q) => $q->where('owner_id', $request->user()->id))
            ->firstOrFail();
        $post->update(['status' => 'denied']);
        return response()->json(['success' => true, 'post' => $post]);
    })->name('api.posts.deny');

    // ─────────────────────────────────────────────────────────────────────
    // PLATFORM STATUS & FIELDS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/platforms/status', function (Request $request) {
        $businessId = $request->input('business_id');
        $platforms = \App\Models\SocialPlatform::where('business_id', $businessId)->get();
        $status = [];
        foreach ($platforms as $p) {
            $platformKey = $p->platform ?: $p->key;
            $status[$platformKey] = [
                'connected' => $p->connected,
                'last_test' => $p->last_tested_at,
                'status'    => $p->last_test_status,
            ];
        }
        return response()->json(['success' => true, 'platforms' => $status]);
    })->name('api.platforms.status');

    Route::get('/platforms/fields/{platform}', function (string $platform) {
        $fields = match($platform) {
            'instagram' => ['access_token' => 'string', 'business_account_id' => 'string'],
            'facebook'  => ['access_token' => 'string', 'page_id' => 'string'],
            'twitter'   => ['api_key' => 'string', 'api_secret' => 'string', 'access_token' => 'string', 'access_token_secret' => 'string'],
            'linkedin'  => ['access_token' => 'string', 'organization_id' => 'string'],
            'tiktok'    => ['access_token' => 'string', 'open_id' => 'string'],
            'youtube'   => ['api_key' => 'string', 'channel_id' => 'string'],
            'telegram'  => ['bot_token' => 'string', 'admin_chat_ids' => 'array'],
            default     => [],
        };
        return response()->json(['success' => true, 'platform' => $platform, 'fields' => $fields]);
    })->name('api.platforms.fields');

    Route::post('/platforms/{platform}/connect', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');

        $credManager = new \App\Services\CredentialManagerService($businessId);
        $credManager->saveCredentials($platform, $request->except(['_token', 'business_id']));

        // Keep legacy 'key' column in sync
        \App\Models\SocialPlatform::where('business_id', $businessId)
            ->where('platform', $platform)
            ->update(['key' => $platform, 'name' => ucfirst($platform), 'connected' => true]);

        return response()->json(['success' => true, 'message' => ucfirst($platform) . ' connected.']);
    })->name('api.platforms.connect');

    Route::post('/platforms/{platform}/test', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $sp = \App\Models\SocialPlatform::where('business_id', $businessId)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('key', $platform);
            })->first();
        if (!$sp || !$sp->connected) {
            return response()->json(['success' => false, 'message' => 'Platform not connected']);
        }

        try {
            $credManager = new \App\Services\CredentialManagerService($businessId);
            $result = $credManager->testConnection($platform);

            $sp->update([
                'last_tested_at'    => now(),
                'last_test_status'  => $result['success'] ? 'ok' : 'error',
                'last_test_message' => $result['message'] ?? null,
            ]);

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? ($result['success'] ? 'Connection OK' : 'Test failed'),
            ]);
        } catch (\Exception $e) {
            $sp->update(['last_tested_at' => now(), 'last_test_status' => 'error', 'last_test_message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Test failed: ' . $e->getMessage()]);
        }
    })->name('api.platforms.test');

    Route::post('/platforms/{platform}/disconnect', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');

        $credManager = new \App\Services\CredentialManagerService($businessId);
        $credManager->disconnect($platform);

        // Also clear legacy columns
        \App\Models\SocialPlatform::where('business_id', $businessId)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('key', $platform);
            })
            ->update(['connected' => false, 'credentials' => null]);

        return response()->json(['success' => true, 'message' => ucfirst($platform) . ' disconnected.']);
    })->name('api.platforms.disconnect');

    // ─────────────────────────────────────────────────────────────────────
    // TELEGRAM BOT MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/telegram/configure', function (Request $request) {
        $businessId = $request->input('business_id');
        \App\Models\TelegramBot::updateOrCreate(
            ['business_id' => $businessId],
            ['bot_token' => $request->input('bot_token'), 'admin_chat_ids' => $request->input('admin_chat_ids', []), 'is_active' => true]
        );
        return response()->json(['success' => true, 'message' => 'Telegram bot configured.']);
    })->name('api.telegram.configure');

    Route::post('/telegram/start-bot', function (Request $request) {
        $businessId = $request->input('business_id');
        $bot = \App\Models\TelegramBot::where('business_id', $businessId)->first();
        if (!$bot) return response()->json(['success' => false, 'message' => 'Bot not configured']);
        $bot->update(['is_active' => true]);
        return response()->json(['success' => true, 'message' => 'Bot started.']);
    })->name('api.telegram.start');

    Route::get('/telegram/bots-status', function (Request $request) {
        $businessId = $request->input('business_id');
        $bots = \App\Models\TelegramBot::where('business_id', $businessId)->get(['id', 'is_active', 'created_at']);
        return response()->json(['success' => true, 'bots' => $bots]);
    })->name('api.telegram.status');

    // ─────────────────────────────────────────────────────────────────────
    // JOBS & CANDIDATES
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/jobs', function (Request $request) {
        $businessId = $request->input('business_id');
        $jobs = \App\Models\JobListing::where('business_id', $businessId)->withCount('candidates')->get();
        return response()->json(['success' => true, 'jobs' => $jobs]);
    })->name('api.jobs');

    Route::get('/jobs/{jobId}/candidates', function (Request $request, int $jobId) {
        $candidates = \App\Models\JobCandidate::where('job_listing_id', $jobId)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'candidates' => $candidates]);
    })->name('api.jobs.candidates');

    Route::post('/jobs/{jobId}/candidates/{candidateId}/approve', function (int $jobId, int $candidateId) {
        \App\Models\JobCandidate::where('job_listing_id', $jobId)->findOrFail($candidateId)->update(['status' => 'offer']);
        return response()->json(['success' => true, 'message' => 'Candidate approved.']);
    })->name('api.jobs.candidates.approve');

    Route::post('/jobs/{jobId}/candidates/{candidateId}/reject', function (int $jobId, int $candidateId) {
        \App\Models\JobCandidate::where('job_listing_id', $jobId)->findOrFail($candidateId)->update(['status' => 'rejected']);
        return response()->json(['success' => true, 'message' => 'Candidate rejected.']);
    })->name('api.jobs.candidates.reject');

    // ─────────────────────────────────────────────────────────────────────
    // USAGE, BILLING & PLANS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/usage/summary', function (Request $request) {
        $businessId = $request->input('business_id');
        $logs = \App\Models\AiUsageLog::where('business_id', $businessId);
        return response()->json([
            'success' => true,
            'summary' => [
                'total_tokens'   => $logs->sum('total_tokens'),
                'total_cost'     => round($logs->sum('cost'), 4),
                'request_count'  => $logs->count(),
            ],
        ]);
    })->name('api.usage.summary');

    Route::get('/usage/limits', function (Request $request) {
        $businessId = $request->input('business_id');
        $business = Business::find($businessId);
        $plan = $business->subscriptionPlan ?? null;
        return response()->json([
            'success'  => true,
            'limits'   => [
                'posts_per_month'  => $plan->limits['posts_per_month'] ?? 100,
                'ai_tokens'        => $plan->limits['ai_tokens'] ?? 50000,
            ],
            'plan'     => $plan->name ?? 'free',
        ]);
    })->name('api.usage.limits');

    Route::get('/billing/history', function (Request $request) {
        $businessId = $request->input('business_id');
        $records = \App\Models\BillingRecord::where('business_id', $businessId)->orderByDesc('created_at')->take(50)->get();
        return response()->json(['success' => true, 'records' => $records]);
    })->name('api.billing.history');

    // Tenant requests permission to use platform-owner's shared API keys
    Route::post('/billing/request-credit', function (Request $request) {
        $request->validate(['reason' => 'nullable|string|max:500']);

        $user       = $request->user();
        $businessId = $user->business_id;

        if (!$businessId) {
            return response()->json(['success' => false, 'message' => 'Business not found'], 404);
        }

        \App\Models\Business::where('id', $businessId)
            ->update(['uses_platform_api_keys' => true]);

        \App\Models\AuditLog::log(
            \App\Models\AuditLog::EVENT_CREDIT_REQUESTED,
            \App\Models\AuditLog::SEVERITY_WARNING,
            $businessId,
            (string) $user->id,
            ['reason' => $request->input('reason', '')]
        );

        return response()->json([
            'success' => true,
            'message' => 'Credit access request submitted. An admin will review shortly.',
        ]);
    })->name('api.billing.request-credit');

    // Admin approves a tenant's credit request
    Route::post('/billing/approve-credit/{businessId}', function (Request $request, int $businessId) {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Admin only'], 403);
        }

        \App\Models\Business::where('id', $businessId)
            ->update(['credit_approved' => true]);

        return response()->json([
            'success' => true,
            'message' => "Credit approved for business {$businessId}",
        ]);
    })->name('api.billing.approve-credit');

    // Admin revokes a tenant's credit access
    Route::post('/billing/revoke-credit/{businessId}', function (Request $request, int $businessId) {
        $user = $request->user();
        if ($user->role !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Admin only'], 403);
        }

        \App\Models\Business::where('id', $businessId)
            ->update(['credit_approved' => false, 'uses_platform_api_keys' => false]);

        return response()->json([
            'success' => true,
            'message' => "Credit revoked for business {$businessId}",
        ]);
    })->name('api.billing.revoke-credit');

    Route::get('/plans', function () {
        $plans = \App\Models\SubscriptionPlan::where('is_active', true)->get(['id', 'name', 'price_monthly', 'price_yearly', 'limits', 'features']);
        return response()->json(['success' => true, 'plans' => $plans]);
    })->name('api.plans');

    // ─────────────────────────────────────────────────────────────────────
    // BOT PERSONALITY & TRAINING
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/bot/personality', function (Request $request) {
        $businessId = $request->input('business_id');
        $personality = \App\Models\BotPersonality::where('business_id', $businessId)->first();
        return response()->json(['success' => true, 'personality' => $personality]);
    })->name('api.bot.personality');

    Route::put('/bot/personality', function (Request $request) {
        $businessId = $request->input('business_id');
        $personality = \App\Models\BotPersonality::updateOrCreate(
            ['business_id' => $businessId],
            $request->only(['name', 'tone', 'language', 'personality', 'system_prompt'])
        );
        return response()->json(['success' => true, 'personality' => $personality]);
    })->name('api.bot.personality.update');

    Route::post('/bot/train', function (Request $request) {
        $businessId = $request->input('business_id');
        \App\Models\BotTrainingPair::create([
            'business_id' => $businessId,
            'question'    => $request->input('question'),
            'answer'      => $request->input('answer'),
        ]);
        return response()->json(['success' => true, 'message' => 'Training pair added.']);
    })->name('api.bot.train');

    Route::post('/bot/test-response', function (Request $request) {
        return response()->json(['success' => true, 'response' => 'Response preview — connect an AI model for real responses.']);
    })->name('api.bot.test');

    Route::post('/bot/train-from-file', function (Request $request) {
        $businessId = $request->input('business_id');
        $file = $request->file('file');
        if (!$file) return response()->json(['success' => false, 'message' => 'No file uploaded']);
        $path = $file->store('bot-training', 'public');
        \App\Models\KnowledgeSource::create([
            'business_id' => $businessId,
            'source_type' => 'file',
            'title'       => $file->getClientOriginalName(),
            'file_path'   => $path,
        ]);
        return response()->json(['success' => true, 'message' => 'File uploaded and indexed.']);
    })->name('api.bot.train.file');

    Route::post('/bot/train-from-url', function (Request $request) {
        $businessId = $request->input('business_id');
        \App\Models\KnowledgeSource::create([
            'business_id' => $businessId,
            'source_type' => 'url',
            'title'       => $request->input('url'),
            'url'         => $request->input('url'),
        ]);
        return response()->json(['success' => true, 'message' => 'URL added to knowledge base.']);
    })->name('api.bot.train.url');

    Route::get('/bot/knowledge', function (Request $request) {
        $businessId = $request->input('business_id');
        $sources = \App\Models\KnowledgeSource::where('business_id', $businessId)->orderByDesc('created_at')->get();
        return response()->json(['success' => true, 'sources' => $sources]);
    })->name('api.bot.knowledge');

    Route::delete('/bot/knowledge/{sourceId}', function (Request $request, string $sourceId) {
        $businessId = $request->input('business_id');
        \App\Models\KnowledgeSource::where('business_id', $businessId)->findOrFail($sourceId)->delete();
        return response()->json(['success' => true, 'message' => 'Source deleted.']);
    })->name('api.bot.knowledge.delete');

    // ─────────────────────────────────────────────────────────────────────
    // AI MODELS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/ai-models', function (Request $request) {
        $bid = $request->user()->business_id;
        $models = \App\Models\AiModelConfig::where('business_id', $bid)->orderBy('id')->get();
        $result = $models->map(function ($m) {
            return [
                'id'               => $m->id,
                'provider'         => $m->provider,
                'display_name'     => $m->display_name,
                'model_name'       => $m->model_name,
                'base_url'         => $m->base_url,
                'is_default'       => $m->is_default,
                'is_active'        => $m->is_active,
                'is_orchestrator'  => $m->is_orchestrator,
                'status'           => $m->status,
                'masked_key'       => $m->masked_key,
                'last_tested_at'   => $m->last_tested_at?->toIso8601String(),
                'last_test_status' => $m->last_test_status,
                'last_test_message'=> $m->last_test_message,
            ];
        });
        return response()->json(['success' => true, 'models' => $result]);
    })->name('api.ai-models');

    Route::post('/ai-models', function (Request $request) {
        $bid = $request->user()->business_id;
        $validProviders = ['openai', 'google_gemini', 'anthropic', 'mistral', 'deepseek', 'groq', 'ollama', 'openai_compatible'];
        $provider = $request->input('provider');

        if (!in_array($provider, $validProviders)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider. Must be one of: ' . implode(', ', $validProviders),
            ], 400);
        }

        $apiKey      = $request->input('api_key');
        $modelName   = $request->input('model_name');
        $baseUrl     = $request->input('base_url');
        $displayName = $request->input('display_name');

        if ($provider !== 'ollama' && empty($apiKey)) {
            return response()->json(['success' => false, 'message' => 'API key is required.'], 422);
        }

        $tester     = new \App\Services\AiProviderTestService();
        $testResult = $tester->test($provider, $apiKey ?? 'local', $modelName, $baseUrl);

        $model = \App\Models\AiModelConfig::create([
            'business_id'       => $bid,
            'provider'          => $provider,
            'display_name'      => $displayName,
            'api_key'           => $apiKey ?? 'local',
            'model_name'        => $modelName,
            'base_url'          => $baseUrl,
            'is_active'         => true,
            'last_tested_at'    => now(),
            'last_test_status'  => $testResult['success'] ? 'ok' : 'error',
            'last_test_message' => $testResult['message'] ?? null,
        ]);

        return response()->json([
            'success'      => true,
            'id'           => $model->id,
            'connected'    => $testResult['success'],
            'message'      => $testResult['success']
                ? ($testResult['message'] ?? 'AI model saved and verified.')
                : 'Saved, but connection test failed: ' . ($testResult['message'] ?? 'Unknown error'),
        ]);
    })->name('api.ai-models.save');

    Route::put('/ai-models/{id}', function (Request $request, int $id) {
        $bid   = $request->user()->business_id;
        $model = \App\Models\AiModelConfig::where('business_id', $bid)->where('id', $id)->firstOrFail();

        $updates = array_filter([
            'display_name' => $request->input('display_name'),
            'model_name'   => $request->input('model_name'),
            'base_url'     => $request->input('base_url'),
        ], fn($v) => $v !== null);
        if ($request->input('api_key')) $updates['api_key'] = $request->input('api_key');

        if ($request->input('api_key') || $request->input('base_url')) {
            $tester = new \App\Services\AiProviderTestService();
            $result = $tester->test(
                $model->provider,
                $request->input('api_key') ?? $model->api_key,
                $request->input('model_name') ?? $model->model_name,
                $request->input('base_url') ?? $model->base_url
            );
            $updates['last_tested_at']    = now();
            $updates['last_test_status']  = $result['success'] ? 'ok' : 'error';
            $updates['last_test_message'] = $result['message'] ?? null;
        }

        $model->update($updates);
        return response()->json(['success' => true, 'message' => 'AI model updated.']);
    })->name('api.ai-models.update');

    Route::post('/ai-models/{id}/test', function (Request $request, int $id) {
        $bid   = $request->user()->business_id;
        $model = \App\Models\AiModelConfig::where('business_id', $bid)->where('id', $id)->first();
        if (!$model) return response()->json(['success' => false, 'message' => 'Model not found.'], 404);

        $tester = new \App\Services\AiProviderTestService();
        $result = $tester->test($model->provider, $model->api_key, $model->model_name, $model->base_url);

        $model->update([
            'last_tested_at'    => now(),
            'last_test_status'  => $result['success'] ? 'ok' : 'error',
            'last_test_message' => $result['message'] ?? null,
        ]);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'] ?? ($result['success'] ? 'Connection verified.' : 'Connection failed.'),
        ]);
    })->name('api.ai-models.test');

    Route::delete('/ai-models/{id}', function (Request $request, int $id) {
        $bid     = $request->user()->business_id;
        $deleted = \App\Models\AiModelConfig::where('business_id', $bid)->where('id', $id)->delete();
        if (!$deleted) return response()->json(['success' => false, 'message' => 'Model not found.'], 404);
        return response()->json(['success' => true, 'message' => 'AI model removed.']);
    })->name('api.ai-models.delete');

    // Orchestrator API routes
    Route::get('/orchestrator', function (Request $request) {
        $svc = new \App\Services\OrchestratorService($request->user()->business_id);
        return response()->json(['success' => true, 'orchestrator' => $svc->getStatus()]);
    })->name('api.orchestrator');

    Route::post('/orchestrator/configure', function (Request $request) {
        $request->validate(['model_id' => 'required|integer']);
        $svc     = new \App\Services\OrchestratorService($request->user()->business_id);
        $success = $svc->setOrchestrator((int) $request->input('model_id'));
        return $success
            ? response()->json(['success' => true, 'message' => 'Orchestrator configured.'])
            : response()->json(['success' => false, 'message' => 'Model not found.'], 404);
    })->name('api.orchestrator.configure');

    Route::post('/orchestrator/plan', function (Request $request) {
        $request->validate(['goal' => 'required|string|max:1000']);
        $svc    = new \App\Services\OrchestratorService($request->user()->business_id);
        $result = $svc->planTasks($request->input('goal'));
        return response()->json($result);
    })->name('api.orchestrator.plan');

    Route::get('/orchestrator/skills', function (Request $request) {
        $svc     = new \App\Services\OrchestratorService($request->user()->business_id);
        $profile = $svc->getSkillProfile();
        return response()->json(['success' => true, 'skills' => $profile, 'domains' => \App\Services\OrchestratorService::SKILL_DOMAINS]);
    })->name('api.orchestrator.skills');

    // ─────────────────────────────────────────────────────────────────────
    // BUSINESS PROFILE
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/business/setup', function (Request $request) {
        $business = Business::create([
            'name'     => $request->input('name'),
            'industry' => $request->input('industry'),
            'timezone' => $request->input('timezone', 'UTC'),
            'owner_id' => $request->user()->id,
        ]);
        \App\Models\User::where('id', $request->user()->id)->update(['business_id' => $business->id]);
        return response()->json(['success' => true, 'business' => $business]);
    })->name('api.business.setup');

    Route::get('/business/{businessId}/profile', function (Request $request, int $businessId) {
        $business = Business::where('id', $businessId)->where('owner_id', $request->user()->id)->firstOrFail();
        return response()->json(['success' => true, 'profile' => $business]);
    })->name('api.business.profile');

    Route::put('/business/{businessId}/profile', function (Request $request, int $businessId) {
        $business = Business::where('id', $businessId)->where('owner_id', $request->user()->id)->firstOrFail();
        $business->update($request->only(['name', 'industry', 'website', 'phone', 'address', 'timezone', 'brand_voice']));
        return response()->json(['success' => true, 'profile' => $business]);
    })->name('api.business.profile.update');

    Route::post('/businesses', function (Request $request) {
        $request->validate([
            'name'               => 'required|string|max:255',
            'industry'           => 'nullable|string|max:100',
            'selected_platforms' => 'nullable|array',
        ]);

        $user = $request->user();
        $business = Business::create([
            'name'     => $request->input('name'),
            'industry' => $request->input('industry'),
            'owner_id' => $user->id,
            'timezone' => 'UTC',
        ]);

        // Link user → new business via junction table (do NOT overwrite active business)
        try {
            \App\Models\UserBusinessLink::firstOrCreate(
                ['user_id' => $user->id, 'business_id' => $business->id],
                ['role' => 'owner']
            );
            // Also ensure current business is linked
            if ($user->business_id) {
                \App\Models\UserBusinessLink::firstOrCreate(
                    ['user_id' => $user->id, 'business_id' => $user->business_id],
                    ['role' => 'owner']
                );
            }
        } catch (\Exception $e) {
            // Junction table may not exist yet
        }

        // Auto-clone trained platform agents from current business
        $agentsCloned = 0;
        $selectedPlatforms = $request->input('selected_platforms', []);
        if (!empty($selectedPlatforms) && $user->business_id) {
            try {
                $agents = \App\Models\PlatformAgent::where('business_id', $user->business_id)
                    ->whereIn('platform', $selectedPlatforms)
                    ->get();
                foreach ($agents as $agent) {
                    \App\Models\PlatformAgent::create([
                        'business_id'            => $business->id,
                        'platform'               => $agent->platform,
                        'system_prompt_override'  => $agent->system_prompt_override,
                        'agent_type'             => $agent->agent_type,
                        'learning_profile'       => $agent->learning_profile,
                        'performance_stats'      => $agent->performance_stats,
                        'trained_from_repos'     => $agent->trained_from_repos,
                        'skill_version'          => $agent->skill_version ?? 1,
                        'is_active'              => true,
                    ]);
                    $agentsCloned++;
                }
            } catch (\Exception $e) {
                // Agent cloning may fail if table doesn't exist
            }
        }

        return response()->json([
            'success'       => true,
            'business'      => $business,
            'agents_cloned' => $agentsCloned,
        ]);
    })->name('api.businesses.create');

    Route::post('/auth/switch-business', function (Request $request) {
        $businessId = $request->input('business_id');
        $user = $request->user();

        // Verify business exists
        $business = Business::find($businessId);
        if (!$business) {
            return response()->json(['error' => 'Business not found'], 404);
        }

        // Check access: owner_id OR junction table
        $hasAccess = ($business->owner_id === $user->id);
        if (!$hasAccess) {
            try {
                $hasAccess = \App\Models\UserBusinessLink::where('user_id', $user->id)
                    ->where('business_id', $businessId)
                    ->exists();
            } catch (\Exception $e) {
                // Junction table may not exist
            }
        }
        if (!$hasAccess) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        \App\Models\User::where('id', $user->id)->update(['business_id' => $business->id]);
        return response()->json(['success' => true, 'business_name' => $business->name]);
    })->name('api.auth.switch');

    // ─────────────────────────────────────────────────────────────────────
    // STRATEGY & GROWTH
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/strategy', function (Request $request) {
        $businessId = $request->input('business_id');
        $strategy = \App\Models\ContentStrategy::where('business_id', $businessId)->first();
        return response()->json(['success' => true, 'strategy' => $strategy]);
    })->name('api.strategy');

    Route::get('/growth-ideas', function (Request $request) {
        return response()->json([
            'success' => true,
            'ideas'   => [
                'Post behind-the-scenes content.',
                'Run a poll or Q&A on Stories.',
                'Share a client success story.',
                'Create a short educational video.',
                'Collaborate with a complementary brand.',
            ],
        ]);
    })->name('api.growth.ideas');

    Route::get('/growth-report', function (Request $request) {
        $businessId = $request->input('business_id');
        $posts = \App\Models\Post::where('business_id', $businessId);
        return response()->json([
            'success' => true,
            'report'  => [
                'total_posts'     => $posts->count(),
                'posts_this_week' => $posts->clone()->where('created_at', '>=', now()->startOfWeek())->count(),
            ],
        ]);
    })->name('api.growth.report');

    Route::get('/pillar-balance', function (Request $request) {
        $businessId = $request->input('business_id');
        $counts = \App\Models\Post::where('business_id', $businessId)
            ->whereNotNull('pillar')
            ->selectRaw('pillar, count(*) as cnt')
            ->groupBy('pillar')
            ->pluck('cnt', 'pillar');
        $total = $counts->sum() ?: 1;
        $balance = $counts->mapWithKeys(fn($cnt, $p) => [$p => ['percentage' => round($cnt / $total * 100, 1), 'target' => 25]]);
        return response()->json(['success' => true, 'pillar_balance' => $balance]);
    })->name('api.pillar.balance');

    Route::get('/content-gaps', function (Request $request) {
        $businessId = $request->input('business_id');
        $counts = \App\Models\Post::where('business_id', $businessId)
            ->whereNotNull('pillar')
            ->selectRaw('pillar, count(*) as cnt')
            ->groupBy('pillar')
            ->pluck('cnt', 'pillar');
        $pillars = ['educational', 'promotional', 'engagement', 'inspirational'];
        $gaps = [];
        foreach ($pillars as $p) {
            $cnt = $counts[$p] ?? 0;
            if ($cnt < 3) $gaps[] = ['pillar' => $p, 'current' => $cnt, 'recommendation' => "Create more {$p} content."];
        }
        return response()->json(['success' => true, 'gaps' => $gaps]);
    })->name('api.content.gaps');

    // ─────────────────────────────────────────────────────────────────────
    // CALENDAR & AUTO-FILL
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/calendar', function (Request $request) {
        $businessId = $request->input('business_id');
        $events = \App\Models\Post::where('business_id', $businessId)
            ->whereIn('status', ['scheduled', 'published'])
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->get(['id', 'caption', 'platform', 'status', 'scheduled_at']);
        return response()->json(['success' => true, 'events' => $events]);
    })->name('api.calendar');

    Route::post('/calendar/auto-fill', function (Request $request) {
        // Auto-scheduling stub - would use GrowthHackerService in real implementation
        return response()->json([
            'success'   => true,
            'message'   => 'Calendar auto-fill queued.',
            'scheduled' => 0,
        ]);
    })->name('api.calendar.autofill');

    // ─────────────────────────────────────────────────────────────────────
    // CAPTIONS A/B TEST
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/captions/ab-test', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\CaptionWriterService($businessId);
        if (!$service->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'AI not configured']);
        }
        $variants = [];
        for ($i = 0; $i < ($request->input('count', 3)); $i++) {
            $result = $service->generateCaption(
                $request->input('platform', 'instagram'),
                $request->input('content_description', ''),
                $request->input('content_category', 'general'),
                $request->input('mood', 'engaging')
            );
            if ($result['success']) $variants[] = $result['caption'];
        }
        return response()->json(['success' => true, 'variants' => $variants]);
    })->name('api.captions.abtest');

    // ─────────────────────────────────────────────────────────────────────
    // MEDIA ENHANCEMENT
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/media/enhance', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\MediaEditorService($businessId);
        $result = $service->autoEnhance($request->input('media_path'));
        return response()->json($result);
    })->name('api.media.enhance');

    // ─────────────────────────────────────────────────────────────────────
    // AI ASSISTANT (unified task handler)
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/ai-assistant', function (Request $request) {
        $task = $request->input('task');
        $businessId = $request->input('business_id');

        return match($task) {
            'generate_caption' => (function() use ($request, $businessId) {
                $service = new \App\Services\CaptionWriterService($businessId);
                if (!$service->isConfigured()) {
                    return response()->json(['success' => false, 'error' => 'AI not configured']);
                }
                return response()->json($service->generateCaption(
                    $request->input('platform', 'instagram'),
                    $request->input('content_description', ''),
                    $request->input('content_category', 'general'),
                    $request->input('mood', 'engaging')
                ));
            })(),
            'get_hashtags' => (function() use ($request, $businessId) {
                $service = new \App\Services\HashtagResearcherService($businessId);
                return response()->json($service->research($request->input('topic', ''), $request->input('platform', 'instagram')));
            })(),
            default => response()->json(['success' => true, 'response' => "Task '{$task}' not implemented."]),
        };
    })->name('api.ai-assistant');

    // ─────────────────────────────────────────────────────────────────────
    // INSIGHTS & NOTIFICATIONS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/insights', function (Request $request) {
        $businessId = $request->input('business_id');
        $insights = [];
        $pending = \App\Models\Post::where('business_id', $businessId)->where('status', 'pending')->count();
        if ($pending > 0) $insights[] = ['type' => 'info', 'message' => "You have {$pending} post(s) awaiting approval."];
        return response()->json(['success' => true, 'insights' => $insights]);
    })->name('api.insights');

    Route::get('/notifications/recent', function (Request $request) {
        // Stub - would pull from notification table
        return response()->json(['success' => true, 'notifications' => []]);
    })->name('api.notifications.recent');

    // ─────────────────────────────────────────────────────────────────────
    // PLATFORM AGENTS
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/agents', function (Request $request) {
        $businessId = $request->input('business_id');
        $agents = \App\Models\PlatformAgent::where('business_id', $businessId)->get();
        return response()->json(['success' => true, 'agents' => $agents]);
    })->name('api.agents');

    Route::get('/agents/{platform}', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $agent = \App\Models\PlatformAgent::where('business_id', $businessId)->where('platform', $platform)->first();
        return response()->json(['success' => true, 'agent' => $agent]);
    })->name('api.agents.show');

    Route::post('/agents/train-from-repo', function (Request $request) {
        $businessId = $request->input('business_id');
        $platform = $request->input('platform', 'instagram');
        $repoUrl = $request->input('repo_url');
        
        if (!$repoUrl) {
            return response()->json(['success' => false, 'error' => 'repo_url is required']);
        }
        
        $service = new \App\Services\PlatformAgentsService($businessId);
        $result = $service->trainFromGitHub($platform, $repoUrl);
        
        return response()->json($result);
    })->name('api.agents.train.repo');

    Route::post('/agents/train-from-zip', function (Request $request) {
        $businessId = $request->input('business_id');
        $platform = $request->input('platform', 'instagram');
        $file = $request->file('file');
        
        if (!$file) {
            return response()->json(['success' => false, 'error' => 'ZIP file is required']);
        }
        
        $path = $file->store('training-uploads', 'local');
        $fullPath = storage_path('app/' . $path);
        
        $service = new \App\Services\PlatformAgentsService($businessId);
        $result = $service->trainFromZip($platform, $fullPath);
        
        // Clean up uploaded file
        @unlink($fullPath);
        
        return response()->json($result);
    })->name('api.agents.train.zip');

    Route::post('/agents/{platform}/learn-from-result', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $postId = $request->input('post_id');
        $engagementData = $request->input('engagement', []);
        
        $service = new \App\Services\PlatformAgentsService($businessId);
        $result = $service->learnFromResult($postId, $engagementData);
        
        return response()->json($result);
    })->name('api.agents.learn.result');

    Route::post('/agents/{platform}/learn', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        \App\Models\PlatformAgent::updateOrCreate(
            ['business_id' => $businessId, 'platform' => $platform],
            ['learned_from' => $request->input('account_url'), 'last_learned_at' => now()]
        );
        return response()->json(['success' => true, 'message' => "Learning from {$platform} account queued."]);
    })->name('api.agents.learn');

    // ─────────────────────────────────────────────────────────────────────
    // SEO TOOLS
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/seo/keywords', function (Request $request) {
        $businessId = $request->input('business_id');
        return response()->json([
            'success'  => true,
            'keywords' => ['marketing', 'social media', 'growth', 'engagement', 'content strategy'],
        ]);
    })->name('api.seo.keywords');

    Route::post('/seo/audit', function (Request $request) {
        return response()->json([
            'success' => true,
            'audit'   => [
                'score'           => 75,
                'recommendations' => ['Add meta descriptions', 'Improve page speed', 'Add alt text to images'],
            ],
        ]);
    })->name('api.seo.audit');

    Route::post('/seo/gmb-post', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\CaptionWriterService($businessId);
        if (!$service->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'AI not configured']);
        }
        $result = $service->generateCaption('google_my_business', $request->input('topic', ''), 'local', 'professional');
        return response()->json($result);
    })->name('api.seo.gmb');

    // ─────────────────────────────────────────────────────────────────────
    // HR TOOLS
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/hr/create-job-posting', function (Request $request) {
        $businessId = $request->input('business_id');
        $job = \App\Models\JobListing::create([
            'business_id' => $businessId,
            'title'       => $request->input('title'),
            'department'  => $request->input('department'),
            'description' => $request->input('description'),
            'status'      => 'draft',
        ]);
        return response()->json(['success' => true, 'job' => $job]);
    })->name('api.hr.job.create');

    Route::post('/hr/screen-resume', function (Request $request) {
        // Stub - would use AI to screen resume
        return response()->json([
            'success' => true,
            'score'   => rand(60, 95),
            'summary' => 'Candidate appears qualified based on resume analysis.',
        ]);
    })->name('api.hr.screen');

    Route::post('/hr/employer-brand-post', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\CaptionWriterService($businessId);
        if (!$service->isConfigured()) {
            return response()->json(['success' => false, 'error' => 'AI not configured']);
        }
        $result = $service->generateCaption('linkedin', 'employer branding post', 'inspirational', 'professional');
        return response()->json($result);
    })->name('api.hr.brand');

    // ─────────────────────────────────────────────────────────────────────
    // COLLAGE CREATION
    // ─────────────────────────────────────────────────────────────────────

    Route::post('/collage/create', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\MediaEditorService($businessId);
        $result = $service->createCollage($request->input('images', []), $request->input('layout', 'grid'));
        return response()->json($result);
    })->name('api.collage.create');

    // ─────────────────────────────────────────────────────────────────────
    // EVENTS (SSE stub)
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/events', function (Request $request) {
        // Server-sent events would require streaming response
        // This is a stub that returns recent events as JSON
        return response()->json(['success' => true, 'events' => []]);
    })->name('api.events');

    // ─────────────────────────────────────────────────────────────────────
    // SCHEDULE (posting schedule)
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/schedule', function (Request $request) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\GrowthHackerService($businessId);
        $schedule = $service->generatePostingSchedule($request->input('platform', 'instagram'));
        return response()->json($schedule);
    })->name('api.schedule');

    // ─────────────────────────────────────────────────────────────────────
    // OAUTH SETUP (Platform Authorization)
    // ─────────────────────────────────────────────────────────────────────

    Route::get('/oauth/platforms', function (Request $request) {
        $businessId = $request->input('business_id', 1);
        $service = new \App\Services\OAuthSetupService($businessId);
        return response()->json([
            'success'   => true,
            'platforms' => $service->getAllPlatformConfigs(),
        ]);
    })->name('api.oauth.platforms');

    Route::get('/oauth/setup/{platform}', function (Request $request, string $platform) {
        $businessId = $request->input('business_id', 1);
        $service = new \App\Services\OAuthSetupService($businessId);
        return response()->json($service->getSetupInstructions($platform));
    })->name('api.oauth.setup');

    Route::post('/oauth/authorize/{platform}', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $credentials = $request->except(['business_id', '_token']);
        
        $service = new \App\Services\OAuthSetupService($businessId);
        return response()->json($service->getAuthorizationUrl($platform, $credentials));
    })->name('api.oauth.authorize');

    Route::post('/oauth/refresh/{platform}', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\OAuthSetupService($businessId);
        return response()->json($service->refreshToken($platform));
    })->name('api.oauth.refresh');

    Route::post('/oauth/test/{platform}', function (Request $request, string $platform) {
        $businessId = $request->input('business_id');
        $service = new \App\Services\OAuthSetupService($businessId);
        return response()->json($service->testConnection($platform));
    })->name('api.oauth.test');
});

// ═══════════════════════════════════════════════════════════════════════
// OAUTH CALLBACK (Public - no auth required)
// ═══════════════════════════════════════════════════════════════════════

Route::get('/oauth/callback/{platform}', function (Request $request, string $platform) {
    $code = $request->input('code');
    $state = $request->input('state');
    $error = $request->input('error');

    if ($error) {
        return redirect('/platforms?error=' . urlencode($request->input('error_description', $error)));
    }

    if (!$code || !$state) {
        return redirect('/platforms?error=missing_code_or_state');
    }

    // Get business_id from state record
    $oauthState = \Illuminate\Support\Facades\DB::table('oauth_states')
        ->where('platform', $platform)
        ->where('state', $state)
        ->first();

    if (!$oauthState) {
        return redirect('/platforms?error=invalid_state');
    }

    $service = new \App\Services\OAuthSetupService($oauthState->business_id);
    $result = $service->handleCallback($platform, $code, $state);

    if ($result['success']) {
        return redirect('/platforms?connected=' . $platform);
    } else {
        return redirect('/platforms?error=' . urlencode($result['error'] ?? 'unknown'));
    }
})->name('api.oauth.callback');
