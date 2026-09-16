<?php

namespace App\Console\Commands;

use App\Models\ConservationReportSubmission;
use App\Models\DocumentRoutingEvent;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\SubmissionTracking\DocumentRoutingProfileRegistry;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RepairLegacyPenroRecordsHandoffs extends Command
{
    protected $signature = 'routing:repair-legacy-penro-records-handoffs {--apply : Persist missing Office PENRO handoff events}';

    protected $description = 'Find or explicitly repair legacy generic PENRO Records receipts that predate the atomic Office PENRO handoff.';

    public function handle(SubmissionTrackingService $tracking): int
    {
        $candidates = DocumentRoutingEvent::query()
            ->where('source_type', '!=', 'engp')
            ->where('to_stage', DocumentRoutingProfileRegistry::PENRO_RECORDS)
            ->orderBy('source_type')->orderBy('source_id')->orderByDesc('occurred_at')->orderByDesc('id')
            ->get()
            ->unique(fn (DocumentRoutingEvent $event): string => $event->source_type.':'.$event->source_id)
            ->filter(fn (DocumentRoutingEvent $event): bool => data_get($event->metadata, 'action_key') === 'receive_at_penro_records');

        $count = 0;
        foreach ($candidates as $candidate) {
            $source = $tracking->source($candidate->source_type);
            if (! $source) continue;
            /** @var Model|null $record */
            $record = $source['model']::query()->find($candidate->source_id);
            if (! $record || ($record instanceof ConservationReportSubmission && app(PambComplianceCalculator::class)->applies((string) $record->workflow_key))) continue;

            $latest = DocumentRoutingEvent::query()
                ->where('source_type', $candidate->source_type)->where('source_id', $candidate->source_id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->first();
            if (! $latest || $latest->id !== $candidate->id) continue;
            $count++;

            if (! $this->option('apply')) continue;
            DB::transaction(function () use ($candidate): void {
                $latest = DocumentRoutingEvent::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $hasHandoff = DocumentRoutingEvent::query()
                    ->where('source_type', $latest->source_type)->where('source_id', $latest->source_id)
                    ->where('metadata->action_key', 'forward_to_office_penro')->exists();
                if ($hasHandoff) return;

                DocumentRoutingEvent::query()->create([
                    'source_type' => $latest->source_type, 'source_id' => $latest->source_id, 'workflow_key' => $latest->workflow_key,
                    'event_key' => 'forwarded', 'from_stage' => DocumentRoutingProfileRegistry::PENRO_RECORDS,
                    'to_stage' => DocumentRoutingProfileRegistry::TRANSIT_OFFICE_PENRO,
                    'from_office' => 'PENRO Records Unit', 'to_office' => 'Office of the PENRO',
                    'occurred_at' => $latest->occurred_at, 'recorded_by' => $latest->recorded_by,
                    'metadata' => ['state_source' => 'legacy_compatibility_repair', 'action_key' => 'forward_to_office_penro', 'repaired_after_event_id' => $latest->id],
                ]);
            });
        }

        $this->info($this->option('apply')
            ? "Repaired {$count} legacy PENRO Records handoff candidate(s)."
            : "Found {$count} legacy PENRO Records handoff candidate(s). Run with --apply to repair.");

        return self::SUCCESS;
    }
}
