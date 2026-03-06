<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds individual encrypted credential columns to social_platforms table.
 *
 * The CredentialManagerService stores tokens in individual encrypted columns
 * rather than the legacy 'credentials' JSON blob. This migration adds the
 * columns that the service expects so that connectPlatform() and
 * testConnection() use the same storage layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_platforms', function (Blueprint $table) {
            // Platform identifier used by CredentialManagerService (mirrors 'key' but kept
            // as a separate column so legacy code continues to work during transition)
            if (!Schema::hasColumn('social_platforms', 'platform')) {
                $table->string('platform')->nullable()->after('key');
            }

            // Individual encrypted token fields
            if (!Schema::hasColumn('social_platforms', 'access_token')) {
                $table->text('access_token')->nullable()->after('credentials');
            }
            if (!Schema::hasColumn('social_platforms', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('access_token');
            }
            if (!Schema::hasColumn('social_platforms', 'client_id')) {
                $table->text('client_id')->nullable()->after('refresh_token');
            }
            if (!Schema::hasColumn('social_platforms', 'client_secret')) {
                $table->text('client_secret')->nullable()->after('client_id');
            }

            // Extra platform-specific fields (JSON)
            if (!Schema::hasColumn('social_platforms', 'extra_data')) {
                $table->text('extra_data')->nullable()->after('client_secret');
            }

            // Connection metadata
            if (!Schema::hasColumn('social_platforms', 'status')) {
                $table->string('status', 20)->default('active')->after('connected');
            }
            if (!Schema::hasColumn('social_platforms', 'scopes')) {
                $table->text('scopes')->nullable()->after('extra_data');
            }
            if (!Schema::hasColumn('social_platforms', 'connected_at')) {
                $table->timestamp('connected_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('social_platforms', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->after('last_test_message');
            }
            if (!Schema::hasColumn('social_platforms', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('last_used_at');
            }
            if (!Schema::hasColumn('social_platforms', 'last_error')) {
                $table->text('last_error')->nullable()->after('expires_at');
            }
        });

        // Back-fill: set 'platform' = 'key' for existing rows so CredentialManagerService can find them
        // Use Eloquent chunk to be cross-database compatible (avoids raw SQL column quoting issues)
        \App\Models\SocialPlatform::whereNull('platform')->each(function ($row) {
            $row->update(['platform' => $row->key]);
        });
    }

    public function down(): void
    {
        Schema::table('social_platforms', function (Blueprint $table) {
            $columns = [
                'platform', 'access_token', 'refresh_token', 'client_id',
                'client_secret', 'extra_data', 'status', 'scopes',
                'connected_at', 'last_used_at', 'expires_at', 'last_error',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('social_platforms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
