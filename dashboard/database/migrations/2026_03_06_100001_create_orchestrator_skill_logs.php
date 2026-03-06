<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orchestrator_skill_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->string('skill_domain', 100);          // e.g. marketing, designing, copywriting
            $table->string('source_provider', 100)->nullable(); // which AI model taught this
            $table->text('insight');                       // the learned insight/capability
            $table->unsignedInteger('confidence')->default(50); // 0-100
            $table->timestamps();

            $table->index(['business_id', 'skill_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orchestrator_skill_logs');
    }
};
