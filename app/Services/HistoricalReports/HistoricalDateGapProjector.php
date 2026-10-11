<?php

namespace App\Services\HistoricalReports;

use App\Models\HistoricalReportOrigin;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\DateConductedRangeService;
use App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Read-only projection of date-only historical evidence into separate gaps. */
final class HistoricalDateGapProjector
{
    public const PREPARATION = 'preparation';
    public const PROCESSING = 'processing';
    public const HANDOFF = 'handoff';

    public function __construct(
        private readonly DateConductedRangeService $dateRanges,
        private readonly PambComplianceCalculator $pamb,
        private readonly ProtectedAreaRoutingPolicy $routingPolicy,
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function project(string $source, Model $record, HistoricalReportOrigin $origin): array
    {
        $workflow = (string) ($origin->workflow_key ?: $record->getAttribute('workflow_key'));
        $milestones = data_get($origin->original_milestones, 'milestones', []);
        $conducted = $this->conductedStart($record);
        $accomplished = $this->sourceDate($record, 'date_accomplished');
        $release = $this->originalDate($milestones, 'date_report_released_cenro');
        $receipt = $this->originalDate($milestones, 'date_received_penro');

        $preparation = $this->interval(
            self::PREPARATION,
            $conducted['label'] ?? 'Date Conducted',
            $conducted,
            'Date Accomplished',
            $accomplished,
        );

        $authoritativeAccomplishment = $accomplished;
        $startBasis = 'Date Accomplished';
        if ($source === 'conservation' && $this->pamb->isMeeting($workflow) && $accomplished['status'] === 'missing') {
            $authoritativeAccomplishment = $this->sourceDate($record, 'date_conducted');
            $startBasis = 'Date Conducted (per PAMB accomplished-date fallback)';
        }

        $directPenro = $source !== 'engp' && $this->routingPolicy->isDirectPenro($record);
        $processing = $directPenro && $release['status'] === 'missing'
            ? $this->unavailable(self::PROCESSING, 'Date Accomplished', 'Original CENRO Release', 'not_applicable', 'This PA routes directly to PENRO and has no original CENRO release date.')
            : $this->interval(self::PROCESSING, $startBasis, $authoritativeAccomplishment, 'Original CENRO Release', $release);

        $handoff = $directPenro && $release['status'] === 'missing'
            ? $this->unavailable(self::HANDOFF, 'Original CENRO Release', 'Original PENRO Receipt', 'not_applicable', 'This PA routes directly to PENRO and has no original CENRO release date.')
            : $this->interval(self::HANDOFF, 'Original CENRO Release', $release, 'Original PENRO Receipt', $receipt);

        return [
            self::PREPARATION => $preparation,
            self::PROCESSING => $processing,
            self::HANDOFF => $handoff,
        ];
    }

    /** @return array{status:string,date:?string,reason:?string,label?:string} */
    private function conductedStart(Model $record): array
    {
        $attributes = method_exists($record, 'getAttributes') ? $record->getAttributes() : [];
        if (array_key_exists('date_conducted_ranges', $attributes)) {
            $ranges = $record->getAttribute('date_conducted_ranges');
            if (is_array($ranges) && $ranges !== []) {
                try {
                    $normalized = $this->dateRanges->normalize($ranges) ?? [];
                } catch (ValidationException) {
                    return ['status' => 'invalid', 'date' => null, 'reason' => 'The structured Date Conducted ranges are invalid.'];
                }
                if ($normalized === []) {
                    return [...$this->sourceDate($record, 'date_conducted'), 'label' => 'Date Conducted (legacy source date)'];
                }

                $starts = array_column($normalized, 'from');
                sort($starts, SORT_STRING);
                return ['status' => 'complete', 'date' => $starts[0], 'reason' => null, 'label' => 'Date Conducted (earliest structured range)'];
            }
        }

        return [...$this->sourceDate($record, 'date_conducted'), 'label' => 'Date Conducted'];
    }

    /** @return array{status:string,date:?string,reason:?string} */
    private function sourceDate(Model $record, string $field): array
    {
        $attributes = method_exists($record, 'getAttributes') ? $record->getAttributes() : [];
        if (! array_key_exists($field, $attributes)) {
            return ['status' => 'unsupported', 'date' => null, 'reason' => "This source has no {$field} field."];
        }

        return $this->normalizeDate($attributes[$field], "No {$this->label($field)} was recorded.");
    }

    /** @param array<string,mixed> $milestones
     *  @return array{status:string,date:?string,reason:?string}
     */
    private function originalDate(array $milestones, string $field): array
    {
        if (! array_key_exists($field, $milestones) || ! filled($milestones[$field])) {
            return ['status' => 'missing', 'date' => null, 'reason' => "No {$this->label($field)} was recorded in the immutable original-date evidence."];
        }

        return $this->normalizeDate($milestones[$field], "The original {$this->label($field)} is invalid.");
    }

    /** @return array{status:string,date:?string,reason:?string} */
    private function normalizeDate(mixed $value, string $missingReason): array
    {
        if (! filled($value)) return ['status' => 'missing', 'date' => null, 'reason' => $missingReason];
        if ($value instanceof DateTimeInterface) return ['status' => 'complete', 'date' => $value->format('Y-m-d'), 'reason' => null];

        $date = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['status' => 'invalid', 'date' => null, 'reason' => 'Date-only historical evidence must use YYYY-MM-DD.'];
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Manila');
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            return ['status' => 'invalid', 'date' => null, 'reason' => 'The historical date is not a valid calendar date.'];
        }

        return ['status' => 'complete', 'date' => $date, 'reason' => null];
    }

    /** @param array{status:string,date:?string,reason:?string} $start
     *  @param array{status:string,date:?string,reason:?string} $end
     *  @return array<string,mixed>
     */
    private function interval(string $key, string $startLabel, array $start, string $endLabel, array $end): array
    {
        $base = [
            'key' => $key,
            'start_label' => $startLabel,
            'start_date' => $start['date'],
            'end_label' => $endLabel,
            'end_date' => $end['date'],
            'unit' => 'calendar days',
            'elapsed_calendar_days' => null,
            'status' => 'missing',
            'reason' => null,
        ];

        if ($start['status'] === 'unsupported' || $end['status'] === 'unsupported') {
            $unsupported = $start['status'] === 'unsupported' ? $start : $end;
            return [...$base, 'status' => 'unsupported', 'reason' => $unsupported['reason']];
        }
        if ($start['status'] !== 'complete' || $end['status'] !== 'complete') {
            $missing = $start['status'] !== 'complete' ? $start : $end;
            return [...$base, 'status' => $missing['status'] === 'invalid' ? 'invalid' : 'missing', 'reason' => $missing['reason']];
        }

        $startDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $start['date'], 'Asia/Manila');
        $endDate = CarbonImmutable::createFromFormat('!Y-m-d', (string) $end['date'], 'Asia/Manila');
        if ($endDate->lessThan($startDate)) {
            return [...$base, 'status' => 'invalid', 'reason' => 'The end date precedes the start date.'];
        }

        return [...$base, 'status' => 'complete', 'elapsed_calendar_days' => (int) $startDate->diffInDays($endDate), 'reason' => null];
    }

    /** @return array<string,mixed> */
    private function unavailable(string $key, string $startLabel, string $endLabel, string $status, string $reason): array
    {
        return [
            'key' => $key,
            'start_label' => $startLabel,
            'start_date' => null,
            'end_label' => $endLabel,
            'end_date' => null,
            'unit' => 'calendar days',
            'elapsed_calendar_days' => null,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function label(string $field): string
    {
        return match ($field) {
            'date_conducted' => 'Date Conducted',
            'date_accomplished' => 'Date Accomplished',
            'date_report_released_cenro' => 'CENRO Release date',
            'date_received_penro' => 'PENRO Receipt date',
            default => str($field)->replace('_', ' ')->title()->toString(),
        };
    }
}
