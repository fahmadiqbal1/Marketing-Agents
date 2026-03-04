<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('platform');
            $table->string('metric_type');      // reach, engagement, followers, impressions, etc.
            $table->decimal('value', 15, 2)->default(0);
            $table->date('period_date');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'platform', 'metric_type', 'period_date'], 'am_biz_platform_metric_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_metrics');
    }
};
