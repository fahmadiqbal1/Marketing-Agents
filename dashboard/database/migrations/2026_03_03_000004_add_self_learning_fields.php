<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add self-learning and self-improvement fields to platform_agents
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_agents', function (Blueprint $table) {
            // Self-learning: store winning patterns from engagement data
            $table->json('learned_patterns')->nullable()->after('rag_collection_id');
            
            // General config storage
            $table->json('config')->nullable()->after('learned_patterns');
            
            // Track when last learned
            $table->timestamp('last_learned_at')->nullable()->after('skill_version');
        });

        // Add OAuth state storage for platform connections
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('platform', 30);
            $table->string('state', 128)->unique();    // CSRF state token
            $table->string('code_verifier', 256)->nullable(); // PKCE code verifier
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['business_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::table('platform_agents', function (Blueprint $table) {
            $table->dropColumn(['learned_patterns', 'config', 'last_learned_at']);
        });

        Schema::dropIfExists('oauth_states');
    }
};
