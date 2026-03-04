<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_listing_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('resume_url')->nullable();
            $table->text('cover_letter')->nullable();
            $table->string('status')->default('new');   // new, reviewing, interview, offer, rejected, hired
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_listing_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_candidates');
    }
};
