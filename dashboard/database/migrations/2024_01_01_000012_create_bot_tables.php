<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_personalities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name')->default('Marketing Bot');
            $table->string('tone')->default('professional');
            $table->string('language')->default('en');
            $table->text('personality')->nullable();
            $table->text('system_prompt')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
        });

        Schema::create('bot_training_pairs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->text('question');
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('business_id');
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('source_type');     // manual, url, file
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('url')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('bot_training_pairs');
        Schema::dropIfExists('bot_personalities');
    }
};
