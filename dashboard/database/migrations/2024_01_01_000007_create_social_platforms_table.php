<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_platforms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('key');                        // instagram, facebook, twitter, etc.
            $table->string('name');
            $table->boolean('connected')->default(false);
            $table->json('credentials')->nullable();       // encrypted credentials stored as JSON
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable(); // ok, error
            $table->text('last_test_message')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'key']);
            $table->index('business_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_platforms');
    }
};
