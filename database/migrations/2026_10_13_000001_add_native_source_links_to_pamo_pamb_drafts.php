<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const SOURCES = [
        'bms_report_submission_id' => 'bms_report_submissions',
        'bams_report_submission_id' => 'bams_report_submissions',
        'imea_report_submission_id' => 'imea_report_submissions',
        'imea_facility_maintenance_report_id' => 'imea_facility_maintenance_reports',
        'aws_report_id' => 'aws',
        'ipaf_management_report_id' => 'ipaf_management_reports',
        'ipaf_revenue_collection_id' => 'ipaf_revenue_collections',
    ];

    public function up(): void
    {
        $required = ['pamo_pamb_drafts', ...array_values(self::SOURCES)];
        $missing = array_values(array_filter($required, fn (string $table): bool => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('PAMO native-source linkage prerequisites are missing: '.implode(', ', $missing));
        }

        $present = array_filter(array_keys(self::SOURCES), fn (string $column): bool => Schema::hasColumn('pamo_pamb_drafts', $column));
        if (count($present) === count(self::SOURCES)) {
            $this->installNativeLinkGuards();
            return;
        }
        if ($present !== []) {
            throw new RuntimeException('PAMO native-source linkage is partially installed; inspect the schema and restore before retrying.');
        }

        Schema::table('pamo_pamb_drafts', function (Blueprint $table): void {
            foreach (self::SOURCES as $column => $sourceTable) {
                $table->foreignId($column)->nullable()->unique('pamo_draft_'.$column.'_uniq')
                    ->constrained($sourceTable)->restrictOnDelete();
            }
        });

        $this->installNativeLinkGuards();
    }

    public function down(): void
    {
        throw new RuntimeException('PAMO native-source provenance is retained; use a reviewed forward migration to correct it.');
    }

    private function installNativeLinkGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('PAMO native-source link guards support SQLite and MySQL only.');
        }

        // The legacy Conservation link remains valid by itself. A draft may
        // never point at both it and one of the newly linked native sources.
        $nativeColumns = array_keys(self::SOURCES);
        $sourceColumns = $nativeColumns;
        if (Schema::hasColumn('pamo_pamb_drafts', 'conservation_report_submission_id')) {
            array_unshift($sourceColumns, 'conservation_report_submission_id');
        }
        $count = implode(' + ', array_map(fn (string $column): string => "CASE WHEN ".$column." IS NULL THEN 0 ELSE 1 END", $sourceColumns));
        $nativeCount = implode(' + ', array_map(fn (string $column): string => "CASE WHEN ".$column." IS NULL THEN 0 ELSE 1 END", $nativeColumns));
        $workflow = implode(' OR ', array_map(fn (string $column, string $table): string => '(' . $column . " IS NOT NULL AND workflow_key <> '" . $this->workflowForTable($table) . "')", array_keys(self::SOURCES), array_values(self::SOURCES)));
        $invalid = '(('.$count.') > 1 OR ('.$workflow.') OR (('.$nativeCount.') > 0 AND status <> \'forwarded\'))';

        if ($driver === 'sqlite') {
            $newInvalid = $invalid;
            foreach ($sourceColumns as $column) {
                $newInvalid = preg_replace('/\\b'.preg_quote($column, '/').'\\b/', 'NEW.'.$column, $newInvalid);
            }
            $newInvalid = preg_replace('/\\bworkflow_key\\b/', 'NEW.workflow_key', $newInvalid);
            $newInvalid = preg_replace('/\\bstatus\\b/', 'NEW.status', $newInvalid);
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $operation) {
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS pamo_native_link_{$suffix} BEFORE {$operation} ON pamo_pamb_drafts FOR EACH ROW WHEN {$newInvalid} BEGIN SELECT RAISE(ABORT, 'invalid PAMO native source linkage'); END");
            }
            $immutability = implode(' OR ', array_merge(
                array_map(fn (string $column): string => '(OLD.'.$column.' IS NOT NULL AND NEW.'.$column.' IS NOT OLD.'.$column.')', $sourceColumns),
                ['OLD.workflow_key IS NOT NEW.workflow_key', 'OLD.protected_area_id IS NOT NEW.protected_area_id', 'OLD.pamo_office_id IS NOT NEW.pamo_office_id'],
            ));
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS pamo_native_link_immutable BEFORE UPDATE ON pamo_pamb_drafts FOR EACH ROW WHEN {$immutability} BEGIN SELECT RAISE(ABORT, 'PAMO native source links are immutable'); END");
            return;
        }

        $table = 'pamo_pamb_drafts';
        $constraint = 'pamo_native_source_workflow_check';
        $existing = DB::selectOne('SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', [$constraint]);
        if (! $existing) DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$invalid} = 0)");

        $trigger = DB::selectOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', ['pamo_native_link_immutable']);
        if (! $trigger) {
            $changed = implode(' OR ', array_merge(
                array_map(fn (string $column): string => '(OLD.`'.$column.'` IS NOT NULL AND NOT (NEW.`'.$column.'` <=> OLD.`'.$column.'`))', $sourceColumns),
                ['NOT (NEW.`workflow_key` <=> OLD.`workflow_key`)', 'NOT (NEW.`protected_area_id` <=> OLD.`protected_area_id`)', 'NOT (NEW.`pamo_office_id` <=> OLD.`pamo_office_id`)'],
            ));
            DB::unprepared("CREATE TRIGGER `pamo_native_link_immutable` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN IF {$changed} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAMO native source links are immutable'; END IF; END");
        }
    }

    private function workflowForTable(string $table): string
    {
        return match ($table) {
            'bms_report_submissions' => 'bms',
            'bams_report_submissions' => 'bams',
            'imea_report_submissions' => 'imea',
            'imea_facility_maintenance_reports' => 'imea_facility_maintenance',
            'aws' => 'automated_weather_station',
            'ipaf_management_reports' => 'ipaf_management',
            'ipaf_revenue_collections' => 'revenue_collection',
        };
    }
};
