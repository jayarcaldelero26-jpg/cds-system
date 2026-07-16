<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_monitorings', function (Blueprint $table) {
            $table->id();
            // Relasyon sa Protected Area
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();

            // Mga Detalye sa Issue
            $table->text('issue_description'); // Unsay problema/issue
            $table->text('findings');          // Unsay nakita nga detalye o ebidensya
            $table->date('date_observed');

            // Resolusyon ug Aksyon
            $table->text('recommendations')->nullable();
            $table->text('action_taken')->nullable(); // Unsa nay nabuhat nga aksyon

            // Status ug File
            $table->string('status')->default('Pending'); // Pending, Ongoing, Resolved
            $table->string('attachment')->nullable();     // PDF report

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_monitorings');
    }
};
