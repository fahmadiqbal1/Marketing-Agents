<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_model_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('provider');     // openai, anthropic, gemini, etc.
            $table->text('api_key');        // store encrypted
            $table->string('model_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'provider']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_model_configs');
    }
};
