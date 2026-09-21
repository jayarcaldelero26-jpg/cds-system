<?php

use App\Services\Compliance\TargetOfficeNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const KNOWN_LEGACY_VALUES = [
        'baganga',
        'cenro baganga',
        'baganga cenro',
        'mati',
        'cenro mati',
        'mati cenro',
        'hamiguitan',
        'mhrws',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('compliance_alert_recipients')
            || ! Schema::hasColumn('compliance_alert_recipients', 'target_office_key')) {
            return;
        }

        $normalizer = app(TargetOfficeNormalizer::class);
        $legacyValues = self::KNOWN_LEGACY_VALUES;

        DB::table('compliance_alert_recipients')
            ->whereNull('protected_area_id')
            ->where(function ($query) use ($legacyValues): void {
                $query->whereIn(DB::raw('LOWER(TRIM(target_office))'), $legacyValues)
                    ->orWhereIn('target_office_key', $legacyValues);
            })
            ->orderBy('id')
            ->get(['id', 'target_office', 'target_office_key', 'is_active'])
            ->each(function (object $mapping) use ($normalizer): void {
                $office = $normalizer->normalize($mapping->target_office);

                if (! $office['key']) {
                    return;
                }

                $active = (bool) $mapping->is_active;
                $duplicate = $active && DB::table('compliance_alert_recipients')
                    ->whereNull('protected_area_id')
                    ->where('target_office_key', $office['key'])
                    ->where('is_active', true)
                    ->where('id', '<', $mapping->id)
                    ->exists();

                if ($duplicate) {
                    $active = false;
                }

                DB::table('compliance_alert_recipients')
                    ->where('id', $mapping->id)
                    ->update([
                        'target_office_key' => $office['key'],
                        'is_active' => $active,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Preserve canonicalized operational mappings on rollback.
    }
};
