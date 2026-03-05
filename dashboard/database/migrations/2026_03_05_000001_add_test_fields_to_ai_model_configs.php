<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_model_configs')) {
            Schema::table('ai_model_configs', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_model_configs', 'last_tested_at')) {
                    $table->timestamp('last_tested_at')->nullable()->after('is_active');
                }
                if (! Schema::hasColumn('ai_model_configs', 'last_test_status')) {
                    $table->string('last_test_status', 20)->nullable()->after('last_tested_at');
                }
                if (! Schema::hasColumn('ai_model_configs', 'last_test_message')) {
                    $table->text('last_test_message')->nullable()->after('last_test_status');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_model_configs')) {
            Schema::table('ai_model_configs', function (Blueprint $table) {
                $cols = ['last_tested_at', 'last_test_status', 'last_test_message'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('ai_model_configs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
