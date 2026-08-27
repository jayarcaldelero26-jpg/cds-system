<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOT_UNIQUE = 'compliance_notification_run_reports_unique';

    public function up(): void
    {
        if (! Schema::hasTable('compliance_notification_run_report_duplicate_archives')) {
            Schema::create('compliance_notification_run_report_duplicate_archives', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('original_id');
                $table->unsignedBigInteger('compliance_notification_run_id');
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->json('snapshot')->nullable();
                $table->timestamp('original_created_at')->nullable();
                $table->timestamp('original_updated_at')->nullable();
                $table->string('archive_reason');
                $table->timestamp('archived_at');

                $table->index('compliance_notification_run_id', 'cnrrda_run_lookup');
            });
        }

        $duplicates = DB::table('compliance_notification_run_reports')
            ->select('compliance_notification_run_id', 'source_type', 'source_id', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('compliance_notification_run_id', 'source_type', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('compliance_notification_run_reports')
                ->where('compliance_notification_run_id', $duplicate->compliance_notification_run_id)
                ->where('source_type', $duplicate->source_type)
                ->where('source_id', $duplicate->source_id)
                ->orderBy('id')
                ->get();

            foreach ($rows->skip(1) as $row) {
                DB::table('compliance_notification_run_report_duplicate_archives')->insert([
                    'original_id' => $row->id,
                    'compliance_notification_run_id' => $row->compliance_notification_run_id,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'snapshot' => $row->snapshot,
                    'original_created_at' => $row->created_at,
                    'original_updated_at' => $row->updated_at,
                    'archive_reason' => 'Duplicate source identity archived before enforcing the notification snapshot unique constraint.',
                    'archived_at' => now(),
                ]);
                DB::table('compliance_notification_run_reports')->where('id', $row->id)->delete();
            }
        }

        if (! $this->indexExists('compliance_notification_run_reports', self::SNAPSHOT_UNIQUE)) {
            Schema::table('compliance_notification_run_reports', function (Blueprint $table): void {
                $table->unique(
                    ['compliance_notification_run_id', 'source_type', 'source_id'],
                    self::SNAPSHOT_UNIQUE,
                );
            });
        }

        if (! Schema::hasColumn('compliance_notification_runs', 'idempotency_key')) {
            Schema::table('compliance_notification_runs', function (Blueprint $table): void {
                $table->string('idempotency_key', 64)->nullable()->after('recipient_key');
                $table->index('idempotency_key', 'compliance_notification_runs_idempotency_lookup');
            });
        }

        if (! Schema::hasTable('compliance_delivery_claims')) {
            Schema::create('compliance_delivery_claims', function (Blueprint $table): void {
                $table->id();
                $table->string('idempotency_key', 64)->unique('compliance_delivery_claims_key_unique');
                $table->string('run_type', 20);
                $table->date('business_date');
                $table->string('recipient_key', 128);
                $table->string('delivery_fingerprint', 64);
                $table->string('status', 20)->default('processing');
                $table->unsignedInteger('attempts')->default(1);
                $table->timestamp('acquired_at');
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('last_run_id')->nullable();
                $table->timestamps();

                $table->index(
                    ['business_date', 'run_type', 'recipient_key'],
                    'compliance_delivery_claims_delivery_lookup',
                );
                $table->foreign('last_run_id', 'cdc_last_run_fk')
                    ->references('id')->on('compliance_notification_runs')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_delivery_claims');

        if (Schema::hasColumn('compliance_notification_runs', 'idempotency_key')) {
            Schema::table('compliance_notification_runs', function (Blueprint $table): void {
                $table->dropIndex('compliance_notification_runs_idempotency_lookup');
                $table->dropColumn('idempotency_key');
            });
        }

        if ($this->indexExists('compliance_notification_run_reports', self::SNAPSHOT_UNIQUE)) {
            Schema::table('compliance_notification_run_reports', function (Blueprint $table): void {
                $table->dropUnique(self::SNAPSHOT_UNIQUE);
            });
        }

        Schema::dropIfExists('compliance_notification_run_report_duplicate_archives');
    }

    private function indexExists(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }
};
