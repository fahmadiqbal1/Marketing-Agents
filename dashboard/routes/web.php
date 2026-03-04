<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;

// ── Public Auth Routes ───────────────────────────────────────────────────────

Route::get('/login',    [AuthController::class, 'showLogin'])->name('login');
Route::post('/login',   [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register',[AuthController::class, 'register']);
Route::post('/logout',  [AuthController::class, 'logout'])->name('logout');

// ── Dashboard Routes (auth required) ────────────────────────────────────────

Route::middleware(['auth'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Setup Wizard
    Route::get('/setup',  [AuthController::class, 'showSetup'])->name('dashboard.setup');
    Route::post('/setup', [AuthController::class, 'handleSetup'])->name('dashboard.setup.handle');

    // Upload
    Route::get('/upload',  [DashboardController::class, 'upload'])->name('dashboard.upload');
    Route::post('/upload', [DashboardController::class, 'handleUpload'])->name('dashboard.upload.handle');

    // Posts
    Route::get('/posts', [DashboardController::class, 'posts'])->name('dashboard.posts');
    Route::post('/posts/{id}/approve', [DashboardController::class, 'approvePost'])->name('dashboard.posts.approve');
    Route::post('/posts/{id}/deny',    [DashboardController::class, 'denyPost'])->name('dashboard.posts.deny');

    // Analytics
    Route::get('/analytics', [DashboardController::class, 'analytics'])->name('dashboard.analytics');

    // Platforms
    Route::get('/platforms', [DashboardController::class, 'platforms'])->name('dashboard.platforms');
    Route::post('/platforms/{platform}/connect',    [DashboardController::class, 'connectPlatform'])->name('dashboard.platforms.connect');
    Route::post('/platforms/{platform}/test',       [DashboardController::class, 'testPlatform'])->name('dashboard.platforms.test');
    Route::post('/platforms/{platform}/disconnect', [DashboardController::class, 'disconnectPlatform'])->name('dashboard.platforms.disconnect');
    Route::post('/platforms/telegram/configure',    [DashboardController::class, 'configureTelegram'])->name('dashboard.platforms.telegram.configure');
    Route::post('/platforms/telegram/test',         [DashboardController::class, 'testTelegram'])->name('dashboard.platforms.telegram.test');

    // AI Models
    Route::get('/ai-models',                  [DashboardController::class, 'listAiModels'])->name('dashboard.ai-models');
    Route::post('/ai-models',                 [DashboardController::class, 'saveAiModel'])->name('dashboard.ai-models.save');
    Route::post('/ai-models/{provider}/test', [DashboardController::class, 'testAiModel'])->name('dashboard.ai-models.test');
    Route::delete('/ai-models/{provider}',    [DashboardController::class, 'deleteAiModel'])->name('dashboard.ai-models.delete');

    // Strategy, Settings, Calendar
    Route::get('/strategy', [DashboardController::class, 'strategy'])->name('dashboard.strategy');
    Route::get('/settings',  [DashboardController::class, 'settings'])->name('dashboard.settings');
    Route::post('/settings', [DashboardController::class, 'updateSettings'])->name('dashboard.settings.update');
    Route::get('/calendar', [DashboardController::class, 'calendar'])->name('dashboard.calendar');

    // Jobs
    Route::get('/jobs',    [DashboardController::class, 'jobs'])->name('dashboard.jobs');
    Route::post('/jobs/{job}/candidates/{candidate}/approve', [DashboardController::class, 'approveCandidate'])->name('dashboard.jobs.approve');
    Route::post('/jobs/{job}/candidates/{candidate}/reject',  [DashboardController::class, 'rejectCandidate'])->name('dashboard.jobs.reject');

    // Feature pages
    Route::get('/agents',  [DashboardController::class, 'agents'])->name('dashboard.agents');
    Route::get('/seo',     [DashboardController::class, 'seo'])->name('dashboard.seo');
    Route::get('/hr',      [DashboardController::class, 'hr'])->name('dashboard.hr');
    Route::get('/billing', [DashboardController::class, 'billing'])->name('dashboard.billing');

    // Bot Training
    Route::get('/bot-training',                         [DashboardController::class, 'botTraining'])->name('dashboard.bot-training');
    Route::get('/bot-training/personality',             [DashboardController::class, 'getBotPersonality'])->name('dashboard.bot-training.personality');
    Route::put('/bot-training/personality',             [DashboardController::class, 'updateBotPersonality'])->name('dashboard.bot-training.personality.update');
    Route::post('/bot-training/train',                  [DashboardController::class, 'trainBot'])->name('dashboard.bot-training.train');
    Route::post('/bot-training/test',                   [DashboardController::class, 'testBotResponse'])->name('dashboard.bot-training.test');
    Route::post('/bot-training/upload',                 [DashboardController::class, 'uploadTrainingFile'])->name('dashboard.bot-training.upload');
    Route::post('/bot-training/train-url',              [DashboardController::class, 'trainFromUrl'])->name('dashboard.bot-training.train-url');
    Route::get('/bot-training/knowledge',               [DashboardController::class, 'getKnowledgeBase'])->name('dashboard.bot-training.knowledge');
    Route::delete('/bot-training/knowledge/{sourceId}', [DashboardController::class, 'deleteKnowledgeSource'])->name('dashboard.bot-training.knowledge.delete');

    // Multi-Business
    Route::get('/businesses',                     [DashboardController::class, 'listBusinesses'])->name('dashboard.businesses');
    Route::post('/businesses',                    [DashboardController::class, 'createBusiness'])->name('dashboard.businesses.create');
    Route::post('/businesses/{businessId}/switch',[DashboardController::class, 'switchBusiness'])->name('dashboard.businesses.switch');

    // Insights + AI Assistant
    Route::get('/insights',      [DashboardController::class, 'insights'])->name('dashboard.insights');
    Route::post('/ai-assistant', [DashboardController::class, 'aiAssistant'])->name('dashboard.ai-assistant');
});