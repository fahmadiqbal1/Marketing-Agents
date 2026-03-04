<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->longText('content')->nullable();
            $table->string('platform');                           // instagram, facebook, etc.
            $table->string('pillar')->nullable();                  // educational, promotional, etc.
            $table->string('status')->default('pending');          // pending, approved, published, denied, failed, scheduled
            $table->string('media_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('thread_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();                      // platform-specific extra data
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'platform']);
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
