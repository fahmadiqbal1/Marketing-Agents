<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_states', function (Blueprint $table) {
            $table->id();
            $table->string('thread_id', 64)->unique();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('media_item_id')->constrained()->onDelete('cascade');
            $table->json('state_json');
            $table->string('current_step', 50)->default('analyze');
            $table->timestamps();

            $table->index('business_id');
            $table->index('current_step');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_states');
    }
};
