<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_offices', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 150);
            $table->string('office_type', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['office_type', 'is_active']);
        });

        Schema::create('protected_area_office_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained('protected_areas')->cascadeOnDelete();
            $table->foreignId('organizational_office_id')->constrained('organizational_offices')->restrictOnDelete();
            $table->string('assignment_type', 30)->default('supervising');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['protected_area_id', 'assignment_type'], 'pa_office_assignment_type_unique');
            $table->index(['organizational_office_id', 'assignment_type'], 'pa_office_assignment_scope_idx');
        });

        $now = now();
        DB::table('organizational_offices')->insert([
            ['code' => 'cenro_baganga', 'name' => 'CENRO Baganga', 'office_type' => 'cenro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'cenro_cateel', 'name' => 'CENRO Cateel', 'office_type' => 'cenro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'cenro_lupon', 'name' => 'CENRO Lupon', 'office_type' => 'cenro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'cenro_mati', 'name' => 'CENRO Mati', 'office_type' => 'cenro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'penro_davao_oriental', 'name' => 'PENRO Davao Oriental', 'office_type' => 'penro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'penro_mati', 'name' => 'PENRO Mati', 'office_type' => 'penro', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('protected_area_office_assignments');
        Schema::dropIfExists('organizational_offices');
    }
};
