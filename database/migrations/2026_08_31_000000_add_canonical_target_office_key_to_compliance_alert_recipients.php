<?php

use App\Services\Compliance\TargetOfficeNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void {
            $table->string('target_office_key', 191)->nullable()->after('target_office');
        });
        $normalizer = app(TargetOfficeNormalizer::class);
        $seen = [];
        foreach (DB::table('compliance_alert_recipients')->orderBy('id')->get() as $mapping) {
            $office = $normalizer->normalize($mapping->target_office);
            $scope = $mapping->protected_area_id ? 'pa:'.(int) $mapping->protected_area_id : ($office['key'] ? 'office:'.$office['key'] : null);
            $active = (bool) $mapping->is_active;
            if ($active && $scope && isset($seen[$scope])) {
                $active = false;
                $scope = null;
            }
            if ($active && $scope) $seen[$scope] = true;
            DB::table('compliance_alert_recipients')->where('id', $mapping->id)->update([
                'target_office_key' => $office['key'], 'target_office' => $office['label'] ?? $mapping->target_office,
                'is_active' => $active, 'active_scope_key' => $active ? $scope : null, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('compliance_alert_recipients', function (Blueprint $table): void { $table->dropColumn('target_office_key'); });
    }
};
