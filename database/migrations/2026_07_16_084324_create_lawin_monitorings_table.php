<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lawin_monitorings', function (Blueprint $table) {
            $table->id();
            // Relasyon sa Protected Area
            $table->foreignId('protected_area_id')->constrained()->cascadeOnDelete();

            // Detalye sa Patrol
            $table->date('patrol_date');
            $table->decimal('patrol_distance', 8, 2)->default(0.00); // Distansya in kilometers (E.g., 12.50 km)
            $table->decimal('patrol_hours', 5, 2)->default(0.00);    // Oras sa patrol (E.g., 4.5 hours)
            $table->integer('patrol_members_count')->default(1);     // Pila kabuok nagpatrol

            // Findings ug Threats
            $table->text('threats_observed')->nullable();            // Unsay nakita nga hulga (illegal logging, hunting, kaingin, etc.)
            $table->text('remarks')->nullable();                     // Uban pang obserbasyon

            // Status ug Spatial Data / PDF Report
            $table->string('status')->default('Under Review');       // Under Review, Approved
            $table->string('attachment')->nullable();                // PDF o GPX file (gikan sa Lawin app)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lawin_monitorings');
    }
};
