<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Junction table for multi-business user access
        if (! Schema::hasTable('user_business_links')) {
            Schema::create('user_business_links', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('business_id')->index();
                $table->string('role', 20)->default('owner'); // owner, admin, viewer
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['user_id', 'business_id'], 'uq_user_business');

                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            });
        }

        // Add base_url column to ai_model_configs for local/custom endpoints
        if (Schema::hasTable('ai_model_configs') && ! Schema::hasColumn('ai_model_configs', 'base_url')) {
            Schema::table('ai_model_configs', function (Blueprint $table) {
                $table->string('base_url', 500)->nullable()->after('model_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_business_links');

        if (Schema::hasTable('ai_model_configs') && Schema::hasColumn('ai_model_configs', 'base_url')) {
            Schema::table('ai_model_configs', function (Blueprint $table) {
                $table->dropColumn('base_url');
            });
        }
    }
};
