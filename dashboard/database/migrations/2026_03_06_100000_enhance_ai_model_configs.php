<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add display_name and is_orchestrator columns
        Schema::table('ai_model_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_model_configs', 'display_name')) {
                $table->string('display_name', 150)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('ai_model_configs', 'is_orchestrator')) {
                $table->boolean('is_orchestrator')->default(false)->after('is_default');
            }
        });

        // Drop the unique constraint so multiple custom endpoints are allowed per provider
        try {
            Schema::table('ai_model_configs', function (Blueprint $table) {
                $table->dropUnique(['business_id', 'provider']);
            });
        } catch (\Exception $e) {
            // Constraint may not exist or may have a different name — ignore
        }
    }

    public function down(): void
    {
        Schema::table('ai_model_configs', function (Blueprint $table) {
            foreach (['display_name', 'is_orchestrator'] as $col) {
                if (Schema::hasColumn('ai_model_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
