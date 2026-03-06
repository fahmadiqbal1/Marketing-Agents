<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add injected_skills column to platform_agents so the Orchestrator
        // can push selected capability knowledge down to each sub-agent.
        if (Schema::hasTable('platform_agents') && ! Schema::hasColumn('platform_agents', 'injected_skills')) {
            Schema::table('platform_agents', function (Blueprint $table) {
                $table->json('injected_skills')->nullable()->after('learned_patterns');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_agents') && Schema::hasColumn('platform_agents', 'injected_skills')) {
            Schema::table('platform_agents', function (Blueprint $table) {
                $table->dropColumn('injected_skills');
            });
        }
    }
};
