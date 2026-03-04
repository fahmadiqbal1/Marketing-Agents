<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds missing columns to the businesses table.
 * These columns are required for billing/credit management, multi-tenant
 * subscription handling, and slugged URL routing.
 *
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            // Unique slug for URL routing (e.g., /b/my-clinic)
            $table->string('slug', 100)->nullable()->unique()->after('name');

            // Custom content categories defined by the business (JSON list)
            $table->text('custom_categories')->nullable()->after('brand_voice');

            // Subscription plan name: free, starter, pro, enterprise
            $table->string('subscription_plan', 50)->default('free')->after('custom_categories');

            // Billing: tenant has requested to use platform owner's shared API keys
            $table->boolean('uses_platform_api_keys')->default(false)->after('subscription_plan');

            // Billing: platform owner has approved the credit request
            $table->boolean('credit_approved')->default(false)->after('uses_platform_api_keys');

            // Soft-delete / account suspension flag
            $table->boolean('is_active')->default(true)->after('credit_approved');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'custom_categories',
                'subscription_plan',
                'uses_platform_api_keys',
                'credit_approved',
                'is_active',
            ]);
        });
    }
};
