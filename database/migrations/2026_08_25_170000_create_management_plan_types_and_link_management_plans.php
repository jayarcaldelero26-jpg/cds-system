<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_plan_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('management_plans', function (Blueprint $table): void {
            $table->foreignId('management_plan_type_id')->nullable()->after('protected_area_id')->constrained('management_plan_types')->restrictOnDelete();
            $table->index(['management_plan_type_id', 'semester'], 'management_plans_type_semester_index');
        });

        $legacyTypes = DB::table('management_plans')
            ->whereNotNull('plan_type')
            ->whereRaw("TRIM(plan_type) <> ''")
            ->selectRaw('TRIM(plan_type) as name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name');

        foreach ($legacyTypes as $legacyName) {
            $name = trim((string) $legacyName);
            $existing = DB::table('management_plan_types')->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

            if ($existing) {
                $typeId = $existing->id;
            } else {
                $baseSlug = Str::slug($name) ?: 'plan';
                $slug = $baseSlug;
                $suffix = 2;
                while (DB::table('management_plan_types')->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix++;
                }

                $typeId = DB::table('management_plan_types')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('management_plans')
                ->whereRaw('LOWER(TRIM(plan_type)) = ?', [Str::lower($name)])
                ->update(['management_plan_type_id' => $typeId]);
        }
    }

    public function down(): void
    {
        Schema::table('management_plans', function (Blueprint $table): void {
            $table->dropForeign(['management_plan_type_id']);
            $table->dropIndex('management_plans_type_semester_index');
            $table->dropColumn('management_plan_type_id');
        });

        Schema::dropIfExists('management_plan_types');
    }
};
