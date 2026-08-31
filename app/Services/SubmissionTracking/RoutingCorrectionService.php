<?php

namespace App\Services\SubmissionTracking;

use App\Models\EngpReportSubmission;
use App\Models\SubmissionRoutingCorrection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogService;

final class RoutingCorrectionService
{
    public function __construct(
        private readonly SubmissionTrackingService $tracking,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
    ) {}

    /**
     * @param array<string, mixed> $dates
     * @param array<string, mixed> $releaseEvents
     */
    public function correct(string $sourceKey, int $id, array $dates, array $releaseEvents, string $reason, int $userId): void
    {
        $source = $this->tracking->source($sourceKey);
        abort_unless($source, 404);
        $record = $source['model']::query()->with($sourceKey === 'engp' ? 'releaseEvents' : 'protectedArea')->findOrFail($id);
        $changes = [];
        $audits = [];

        if ($record instanceof EngpReportSubmission) {
            $events = $record->releaseEvents->keyBy('id');
            $releases = $events->mapWithKeys(fn ($event): array => [(int) $event->id => $this->date($event, 'date_report_released_cenro')]);
            foreach ($releaseEvents as $eventId => $value) {
                $event = $events->get((int) $eventId);
                if (! $event) throw ValidationException::withMessages(['release_events' => 'A release event does not belong to this report.']);
                $new = $this->nullableDate($value);
                $old = $this->date($event, 'date_report_released_cenro');
                $releases[(int) $eventId] = $new;
                if ($old !== $new) {
                    $changes[] = [$event, 'date_report_released_cenro', $new];
                    $audits[] = ['field' => 'release_events.'.(int) $eventId.'.date_report_released_cenro', 'original_value' => $old, 'corrected_value' => $new];
                }
            }
            $oldReceipt = $this->date($record, 'date_received_penro');
            $newReceipt = array_key_exists('date_received_penro', $dates) ? $this->nullableDate($dates['date_received_penro']) : $oldReceipt;
            if ($oldReceipt !== $newReceipt) {
                $changes[] = [$record, 'date_received_penro', $newReceipt];
                $audits[] = ['field' => 'date_received_penro', 'original_value' => $oldReceipt, 'corrected_value' => $newReceipt];
            }
            $latestRelease = collect($releases)->filter()->sort()->last();
            if ($latestRelease && $newReceipt && $latestRelease > $newReceipt) {
                throw ValidationException::withMessages(['dates.date_received_penro' => 'PENRO receipt cannot be earlier than the latest CENRO release.']);
            }
        } else {
            $receiptField = ($source['receipt_field'] ?? 'date_received_penro');
            $current = [
                'date_report_released_cenro' => $this->date($record, 'date_report_released_cenro'),
                'date_received_penro' => $this->date($record, $receiptField),
                'date_endorsed_regional' => $this->date($record, 'date_endorsed_regional'),
            ];
            foreach ($dates as $field => $value) {
                if (! array_key_exists($field, $current)) throw ValidationException::withMessages(['dates' => 'A routing field is not valid for this workflow.']);
                if ($field === 'date_report_released_cenro' && $this->routingPolicy->isDirectPenro($record) && $value !== null && $value !== '') {
                    throw ValidationException::withMessages(['dates.date_report_released_cenro' => 'CENRO release is not applicable to this PENRO-managed protected area.']);
                }
                $new = $this->nullableDate($value);
                $old = $current[$field];
                $current[$field] = $new;
                if ($old !== $new) {
                    $audits[] = ['field' => $field, 'original_value' => $old, 'corrected_value' => $new];
                    $changes[] = [$record, $field === 'date_received_penro' ? $receiptField : $field, $new];
                }
            }
            $this->validateChronology($record, $current);
        }

        if ($audits === []) throw ValidationException::withMessages(['dates' => 'At least one routing date must be changed.']);

        DB::transaction(function () use ($record, $changes, $audits, $sourceKey, $reason, $userId): void {
            foreach ($changes as [$target, $field, $value]) {
                $target->update([$field => $value, ...($target === $record && $record->getConnection()->getSchemaBuilder()->hasColumn($record->getTable(), 'updated_by') ? ['updated_by' => $userId] : [])]);
            }
            foreach ($audits as $audit) {
                $correction = SubmissionRoutingCorrection::create([
                    'source' => $sourceKey,
                    'source_id' => $record->getKey(),
                    'field' => $audit['field'],
                    'original_value' => $audit['original_value'],
                    'corrected_value' => $audit['corrected_value'],
                    'reason' => $reason,
                    'corrected_by' => $userId,
                    'corrected_at' => now(),
                ]);
                app(AuditLogService::class)->record('submission_tracking', 'Routing Date Corrected', $sourceKey, $record->getKey(), $sourceKey, 'Corrected '.$audit['field'].' for '.$sourceKey.' record #'.$record->getKey().'.', [
                    'field' => $audit['field'], 'old' => $audit['original_value'], 'new' => $audit['corrected_value'], 'reason' => $reason,
                    'correction_id' => $correction->id,
                ], $userId);
            }
        });
    }

    private function validateChronology(Model $record, array $dates): void
    {
        if ($this->routingPolicy->isDirectPenro($record)) $dates['date_report_released_cenro'] = null;
        $release = $dates['date_report_released_cenro'] ?? null;
        $receipt = $dates['date_received_penro'] ?? null;
        $endorsement = $dates['date_endorsed_regional'] ?? null;
        if ($release && $receipt && $release > $receipt) throw ValidationException::withMessages(['dates' => 'PENRO receipt cannot be earlier than CENRO release.']);
        if ($receipt && $endorsement && $receipt > $endorsement) throw ValidationException::withMessages(['dates' => 'Regional endorsement cannot be earlier than PENRO receipt.']);
        if ($release && $endorsement && $release > $endorsement) throw ValidationException::withMessages(['dates' => 'Regional endorsement cannot be earlier than CENRO release.']);
    }

    private function date(Model $record, string $field): ?string
    {
        $value = $record->getAttribute($field);
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }
}
