<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAlertRecipient;
use Illuminate\Support\Collection;

class ComplianceRecipientResolver
{
    public function resolve(OverdueReport $report): ?ResolvedComplianceRecipient
    {
        $mappings = ComplianceAlertRecipient::query()->where('is_active', true)->orderBy('id')->get();

        return $this->resolveFromMappings($report, $mappings);
    }

    /** @param Collection<int, ComplianceAlertRecipient> $mappings */
    private function resolveFromMappings(OverdueReport $report, Collection $mappings): ?ResolvedComplianceRecipient
    {

        $mapping = $report->protectedAreaId
            ? $mappings->first(fn (ComplianceAlertRecipient $item) => $item->protected_area_id === $report->protectedAreaId)
            : null;

        $mapping ??= $mappings->first(fn (ComplianceAlertRecipient $item) => ! $item->protected_area_id && $item->target_office
            && $this->normaliseOffice($item->target_office) === $this->normaliseOffice($report->targetOffice));

        if ($mapping && filter_var($mapping->recipient_email, FILTER_VALIDATE_EMAIL)) {
            return new ResolvedComplianceRecipient(
                key: 'mapping:'.$mapping->id,
                email: $mapping->recipient_email,
                ccEmails: $this->emails($mapping->cc_emails),
                name: $this->designation($mapping->recipient_name),
                source: $mapping->protected_area_id ? 'protected_area' : 'target_office',
                attentionLine: $this->designation($mapping->attention_line, ''),
                mappingId: $mapping->id,
            );
        }

        return null;
    }

    /** @param Collection<int, OverdueReport> $reports @return array{deliveries: Collection<int, array<string,mixed>>, unmapped: Collection<int, OverdueReport>} */
    public function plans(Collection $reports): array
    {
        $unmapped = collect();
        $deliveries = [];
        $mappings = ComplianceAlertRecipient::query()->where('is_active', true)->orderBy('id')->get();

        foreach ($reports as $report) {
            $recipient = $this->resolveFromMappings($report, $mappings);
            if (! $recipient) {
                $unmapped->push($report);
                continue;
            }

            $deliveryKey = $this->deliveryKey($report, $recipient);
            if (! isset($deliveries[$deliveryKey])) {
                $deliveries[$deliveryKey] = ['recipient' => new ResolvedComplianceRecipient(
                    key: $deliveryKey,
                    email: $recipient->email,
                    ccEmails: $recipient->ccEmails,
                    name: $recipient->name,
                    source: $recipient->source,
                    attentionLine: $recipient->attentionLine,
                    mappingId: $recipient->mappingId,
                ), 'reports' => collect()];
            }
            $deliveries[$deliveryKey]['reports']->push($report);
        }

        return ['deliveries' => collect($deliveries)->values(), 'unmapped' => $unmapped];
    }

    /** @param Collection<int, OverdueReport> $reports @return Collection<int, array<string,mixed>> */
    public function readiness(Collection $reports): Collection
    {
        $mappings = ComplianceAlertRecipient::query()->where('is_active', true)->orderBy('id')->get();

        return $reports->groupBy(fn (OverdueReport $report) => $report->targetOffice.'|'.$report->protectedAreaName)
            ->map(function (Collection $group) use ($mappings): array {
                $first = $group->first();
                $recipient = $this->resolveFromMappings($first, $mappings);
                $status = $recipient ? 'ready' : 'unmapped';

                return [
                    'key' => $first->targetOffice.'|'.$first->protectedAreaName,
                    'protected_area_name' => $first->protectedAreaName,
                    'target_office' => $first->targetOffice,
                    'report_count' => $group->count(),
                    'status' => $status,
                    'recipient' => $recipient?->toArray(),
                ];
            })->values();
    }

    private function normaliseOffice(?string $office): string
    {
        return mb_strtolower(trim((string) $office));
    }

    private function designation(?string $value, string $fallback = 'The OIC, PASu'): string
    {
        $designation = trim((string) $value);
        if ($designation === '') {
            return $fallback;
        }

        return preg_match('/\b(pasu|oic|chief|officer|director|superintendent|section|unit|head|manager)\b/i', $designation)
            ? $designation
            : $fallback;
    }

    private function deliveryKey(OverdueReport $report, ResolvedComplianceRecipient $recipient): string
    {
        return 'delivery:'.hash('sha256', implode('|', [
            $this->normaliseOffice($report->targetOffice),
            mb_strtolower($recipient->email),
            implode(',', $recipient->ccEmails),
        ]));
    }

    /** @return array<int, string> */
    private function emails(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $values)));
    }
}
