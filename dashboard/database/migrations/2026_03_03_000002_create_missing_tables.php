<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates all missing tables from the Python SQLAlchemy models.
 * Converted from memory/models.py to Laravel MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════════════
        // TELEGRAM BOTS — per-business Telegram bot configuration
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->unique();
            $table->text('bot_token');                          // encrypted
            $table->string('bot_username')->nullable();
            $table->text('admin_chat_ids')->nullable();         // JSON list
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // MEDIA ITEMS — photos/videos received from Telegram or uploaded
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('telegram_file_id')->nullable();
            $table->string('file_path', 500);
            $table->string('file_name');
            $table->string('media_type', 20);                   // photo, video, document
            $table->string('mime_type', 100)->nullable();

            // Dimensions & metadata
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();

            // AI analysis results
            $table->string('content_category', 50)->nullable();
            $table->text('analysis_json')->nullable();
            $table->float('quality_score')->nullable();

            // Tracking
            $table->boolean('is_used_in_collage')->default(false);
            $table->boolean('is_used_in_compilation')->default(false);
            $table->timestamps();

            $table->index('business_id');
            $table->index('media_type');
            $table->index('content_category');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // HASHTAG CACHE — pre-built hashtag database per category and platform
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('hashtag_cache', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);
            $table->string('platform', 30);
            $table->string('hashtag', 100);
            $table->float('relevance_score')->default(1.0);
            $table->boolean('is_trending')->default(false);
            $table->timestamps();

            $table->index(['category', 'platform']);
            $table->index('is_trending');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // PROMOTIONAL PACKAGES — AI-proposed promotional packages
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('promotional_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description');
            $table->text('services_included');                  // JSON list
            $table->text('discount_details')->nullable();
            $table->text('target_audience')->nullable();
            $table->string('occasion')->nullable();             // e.g., "World Heart Day"
            $table->string('suggested_price')->nullable();
            $table->text('content_ideas')->nullable();          // JSON list
            $table->string('status', 20)->default('proposed');  // proposed, approved, denied, posted
            $table->string('graphic_path', 500)->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('status');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // MUSIC TRACKS — background music library (royalty-free + trending)
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('artist')->nullable();
            $table->string('platform', 50)->default('all');     // tiktok/instagram/youtube/all

            // Mood / genre / category tags
            $table->string('mood', 100)->nullable();
            $table->string('genre', 100)->nullable();
            $table->string('categories', 500)->nullable();      // comma-separated

            // Files & source
            $table->string('local_filename')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->float('duration_seconds')->nullable();

            // Rights
            $table->boolean('is_royalty_free')->default(false);
            $table->string('license_info')->nullable();

            // Trending
            $table->float('trending_score')->default(0.0);
            $table->boolean('is_trending')->default(false);

            $table->string('note', 500)->nullable();
            $table->timestamp('last_verified')->nullable();
            $table->timestamps();

            $table->index('platform');
            $table->index('mood');
            $table->index('is_trending');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // PLATFORM AGENTS — per-business, per-platform AI agent configuration
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('platform_agents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('platform', 30);

            // Agent customization
            $table->text('system_prompt_override')->nullable();
            $table->string('agent_type', 50)->default('social'); // social, seo, hr

            // RAG-based self-learning
            $table->text('learning_profile')->nullable();       // JSON: best_times, top_hashtags, etc.
            $table->text('performance_stats')->nullable();      // JSON: avg_engagement, post_count, etc.
            $table->string('rag_collection_id')->nullable();    // ChromaDB collection name

            // GitHub training
            $table->text('trained_from_repos')->nullable();     // JSON list of repo URLs
            $table->integer('skill_version')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'platform'], 'uq_agent_business_platform');
            $table->index('business_id');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // AUDIT LOG — centralized security audit log
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->nullable();
            $table->string('event_type', 100);
            $table->string('severity', 20);                     // info, warning, error, critical
            $table->string('actor', 100)->default('system');
            $table->text('details_json')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('business_id');
            $table->index('event_type');
            $table->index('severity');
            $table->index('created_at');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // SUBSCRIPTION PLANS — available subscription plans and limits
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('display_name', 100);
            $table->integer('monthly_token_limit')->default(100000);
            $table->decimal('monthly_cost_usd', 10, 2)->default(0.00);
            $table->integer('max_platforms')->default(3);
            $table->integer('max_posts_per_month')->default(50);
            $table->integer('max_ai_calls_per_day')->default(100);
            $table->text('features_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ═══════════════════════════════════════════════════════════════════════
        // BILLING RECORDS — monthly billing records per tenant
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('billing_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->integer('ai_tokens_used')->default(0);
            $table->decimal('ai_cost_usd', 10, 4)->default(0.0000);
            $table->unsignedBigInteger('platform_owner_id')->nullable();
            $table->string('status', 20)->default('pending');   // pending, paid, overdue
            $table->timestamps();

            $table->index('business_id');
            $table->index('status');
            $table->index('period_start');
        });

        // ═══════════════════════════════════════════════════════════════════════
        // ENGAGEMENT EVENTS — tracks engagement events for analytics
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('engagement_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('platform', 30);
            $table->string('post_id')->nullable();
            $table->string('event_type', 50);                   // like, comment, share, save, click
            $table->integer('count')->default(1);
            $table->text('extra_data')->nullable();             // JSON
            $table->timestamp('created_at')->useCurrent();

            $table->index(['business_id', 'created_at']);
            $table->index(['platform', 'event_type']);
        });

        // ═══════════════════════════════════════════════════════════════════════
        // CONTENT CALENDAR — tracks what was posted when
        // ═══════════════════════════════════════════════════════════════════════
        Schema::create('content_calendar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('post_id')->nullable();
            $table->string('platform', 30);
            $table->string('content_category', 50);
            $table->dateTime('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'posted_at']);
            $table->index('platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_calendar');
        Schema::dropIfExists('engagement_events');
        Schema::dropIfExists('billing_records');
        Schema::dropIfExists('subscription_plans');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('platform_agents');
        Schema::dropIfExists('music_tracks');
        Schema::dropIfExists('promotional_packages');
        Schema::dropIfExists('hashtag_cache');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('telegram_bots');
    }
};
