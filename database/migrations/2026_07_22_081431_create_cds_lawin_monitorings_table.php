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
            Schema::create('cds_lawin_monitorings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('patrol_area'); // Protected Area o Patrol Area
                $table->date('patrol_date');
                $table->string('team_leader')->nullable();
                $table->integer('team_members_count')->nullable();
                $table->string('ecoregion')->nullable();
                $table->text('threats_observed')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cds_lawin_monitorings');
    }
};
