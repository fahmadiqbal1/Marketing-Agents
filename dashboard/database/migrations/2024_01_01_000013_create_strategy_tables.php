<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_strategies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('goal')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('brand_voice')->nullable();
            $table->json('content_pillars')->nullable();       // array of pillar names + percentages
            $table->json('posting_schedule')->nullable();      // days/times per platform
            $table->integer('posts_per_week')->default(7);
            $table->json('platforms')->nullable();             // enabled platforms
            $table->timestamps();

            $table->index('business_id');
        });

        Schema::create('content_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('title');
            $table->text('idea')->nullable();
            $table->string('pillar')->nullable();
            $table->string('platform')->nullable();
            $table->string('status')->default('idea');         // idea, draft, approved, scheduled, published
            $table->date('target_date')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_plans');
        Schema::dropIfExists('content_strategies');
    }
};
