<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spatial_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protected_area_id')->constrained('protected_areas')->cascadeOnDelete();
            $table->string('name');
            $table->string('layer_type')->nullable();
            $table->string('source_format', 20);
            $table->longText('geojson');
            $table->string('original_filename')->nullable();
            $table->string('geometry_type')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['protected_area_id', 'created_at']);
        });

        // Preserve the old single-value records as independent layers. The old
        // column is deliberately retained for a safe, reversible migration.
        if (Schema::hasColumn('protected_areas', 'spatial_data')) {
            DB::table('protected_areas')
                ->whereNotNull('spatial_data')
                ->orderBy('id')
                ->eachById(function (object $area): void {
                    $decoded = json_decode($area->spatial_data, true);

                    if (! is_array($decoded)) {
                        return;
                    }

                    DB::table('spatial_layers')->insert([
                        'protected_area_id' => $area->id,
                        'name' => ($area->name ?: 'Protected Area') . ' Spatial Layer',
                        'layer_type' => 'Boundary',
                        'source_format' => 'geojson',
                        'geojson' => json_encode($decoded, JSON_THROW_ON_ERROR),
                        'original_filename' => null,
                        'geometry_type' => null,
                        'created_by' => $area->created_by,
                        'created_at' => $area->updated_at ?? now(),
                        'updated_at' => $area->updated_at ?? now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spatial_layers');
    }
};
