<?php

use App\Models\ReportComplianceConfirmation;
use App\Services\Compliance\OverdueReportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'report_compliance_confirmations_source_unique';

    public function up(): void
    {
        Schema::table('report_compliance_confirmations', function (Blueprint $table): void {
            $table->string('event_type', 20)->default('confirmed')->after('source_id');
            $table->json('snapshot')->nullable()->after('remarks');
            $table->unsignedBigInteger('original_confirmation_id')->nullable()->after('snapshot');
            $table->timestamp('revoked_at')->nullable()->after('original_confirmation_id');
            $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_at');
            $table->text('revocation_reason')->nullable()->after('revoked_by');
            $table->index(['source_type', 'source_id', 'id'], 'rcc_source_event_lookup');
            $table->foreign('original_confirmation_id', 'rcc_original_confirmation_fk')
                ->references('id')->on('report_compliance_confirmations')->nullOnDelete();
            $table->foreign('revoked_by', 'rcc_revoked_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if ($this->indexExists('report_compliance_confirmations', self::LEGACY_UNIQUE)) {
            Schema::table('report_compliance_confirmations', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }

        $reports = app(OverdueReportService::class);
        ReportComplianceConfirmation::query()
            ->whereNull('snapshot')
            ->orderBy('id')
            ->each(function (ReportComplianceConfirmation $confirmation) use ($reports): void {
                $source = $reports->findSource($confirmation->source_type, (int) $confirmation->source_id);
                $snapshot = $source
                    ? $reports->confirmationSnapshot($source)
                    : [
                        'source_type' => $confirmation->source_type,
                        'source_id' => (int) $confirmation->source_id,
                        'module' => 'Unknown report source',
                        'protected_area_name' => 'Source record no longer exists',
                        'target_office' => 'Source record no longer exists',
                        'activity' => 'Source record no longer exists',
                        'document_type' => 'Unavailable',
                        'reporting_period' => null,
                        'deadline' => null,
                        'submission_date' => null,
                        'submission_status' => 'Report Submitted',
                    ];

                DB::table('report_compliance_confirmations')->where('id', $confirmation->id)->update([
                    'event_type' => 'confirmed',
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('report_compliance_confirmations')->where('event_type', 'revoked')->delete();

        if (! $this->indexExists('report_compliance_confirmations', self::LEGACY_UNIQUE)) {
            Schema::table('report_compliance_confirmations', function (Blueprint $table): void {
                $table->unique(['source_type', 'source_id'], self::LEGACY_UNIQUE);
            });
        }

        Schema::table('report_compliance_confirmations', function (Blueprint $table): void {
            $table->dropForeign('rcc_original_confirmation_fk');
            $table->dropForeign('rcc_revoked_by_fk');
            $table->dropIndex('rcc_source_event_lookup');
            $table->dropColumn([
                'event_type', 'snapshot', 'original_confirmation_id', 'revoked_at', 'revoked_by', 'revocation_reason',
            ]);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }
};
