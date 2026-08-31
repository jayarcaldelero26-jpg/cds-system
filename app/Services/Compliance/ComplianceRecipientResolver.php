<?php

namespace App\Services\Compliance;

use App\Models\ComplianceAlertRecipient;
use Illuminate\Support\Collection;

class ComplianceRecipientResolver
{
    public function __construct(
        private readonly TargetOfficeNormalizer $offices,
        private readonly ComplianceAlertTemplateResolver $templates,
        private readonly ComplianceAlertSettingsService $settings,
    ) {}
    private ?Collection $activeMappings = null;
    private ?array $mappingIndexes = null;
    public function resolve(OverdueReport $report): ?ResolvedComplianceRecipient
    {
        $mappings = $this->activeMappings();

        return $this->resolveFromMappings($report, $mappings);
    }

    /** @param Collection<int, ComplianceAlertRecipient> $mappings */
    private function resolveFromMappings(OverdueReport $report, Collection $mappings): ?ResolvedComplianceRecipient
    {

        $office = $this->offices->normalize($report->targetOffice);
        $indexes = $this->mappingIndexes();
        $mapping = $report->protectedAreaId
            ? ($indexes['protected_area'][(string) $report->protectedAreaId] ?? null)
            : null;
        $mapping ??= $indexes['target_office'][$office['key'] ?? ''] ?? null;

        if ($mapping && filter_var($mapping->recipient_email, FILTER_VALIDATE_EMAIL)) {
            $presentation = $this->templates->recipientDefaultsFor($report, $this->settings->effective());

            return new ResolvedComplianceRecipient(
                key: 'mapping:'.$mapping->id,
                email: $mapping->recipient_email,
                ccEmails: $this->emails($mapping->cc_emails),
                name: $this->designation($mapping->recipient_name, $presentation['default_to']),
                source: $mapping->protected_area_id ? 'protected_area' : 'target_office',
                attentionLine: $this->designation($mapping->attention_line, $presentation['default_attention']),
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
        $mappings = $this->activeMappings();

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

    public function logicalKey(OverdueReport $report): string
    {
        if ($report->protectedAreaId) {
            return 'pa:'.$report->protectedAreaId;
        }

        return 'office:'.($this->offices->normalize($report->targetOffice)['key'] ?? 'unassigned');
    }
    /** @param Collection<int, OverdueReport> $reports @return Collection<int, array<string,mixed>> */
    public function readiness(Collection $reports): Collection
    {
        $mappings = $this->activeMappings();

        return $reports->groupBy(fn (OverdueReport $report): string => $this->logicalKey($report))
            ->map(function (Collection $group) use ($mappings): array {
                $first = $group->first();
                $recipient = $this->resolveFromMappings($first, $mappings);
                $status = $recipient ? 'ready' : 'unmapped';

                return [
                    'key' => $this->logicalKey($first),
                    'protected_area_name' => $first->protectedAreaName,
                    'target_office' => $first->targetOffice,
                    'report_count' => $group->count(),
                    'status' => $status,
                    'recipient' => $recipient?->toArray(),
                ];
            })->values();
    }

    /** @param Collection<int,array<string,mixed>> $references */
    public function coverage(Collection $references): Collection
    {
        $mappings = $this->activeMappings();
        return $references->groupBy(fn (array $r) => $r['protected_area_id'] ? 'pa:'.$r['protected_area_id'] : 'office:'.($this->offices->normalize($r['target_office'] ?? null)['key'] ?? 'unassigned'))->map(function (Collection $items, string $key) use ($mappings): array {
            $first = $items->first(); $office = $this->offices->normalize($first['target_office'] ?? null);
            $indexes = $this->mappingIndexes();
            $mapping = $first['protected_area_id'] ? ($indexes['protected_area'][(string) $first['protected_area_id']] ?? null) : null;
            $mapping ??= $indexes['target_office'][$office['key'] ?? ''] ?? null;
            return ['key'=>$key,'destination'=>$first['protected_area_id'] ? $first['protected_area_name'] : ($office['label'] ?? $first['target_office']),'type'=>$first['protected_area_id'] ? 'Protected Area' : 'Implementing / Target Office','scope'=>$first['protected_area_id'] ? 'protected_area' : 'target_office','protected_area_id'=>$first['protected_area_id'],'target_office'=>$office['label'] ?? $first['target_office'],'report_count'=>$items->count(),'modules'=>$items->pluck('module_name')->unique()->values()->all(),'status'=>$mapping ? 'mapped' : 'unmapped','recipient'=>$mapping ? ['id'=>$mapping->id,'email'=>$mapping->recipient_email] : null];
        })->values();
    }


    /** @return Collection<int,ComplianceAlertRecipient> */
    private function activeMappings(): Collection
    {
        return $this->activeMappings ??= ComplianceAlertRecipient::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'protected_area_id', 'target_office', 'target_office_key', 'recipient_email', 'cc_emails', 'recipient_name', 'attention_line', 'is_active']);
    }

    /** @return array{protected_area:array<string,ComplianceAlertRecipient>,target_office:array<string,ComplianceAlertRecipient>} */
    private function mappingIndexes(): array
    {
        return $this->mappingIndexes ??= [
            'protected_area' => $this->activeMappings()->filter(fn (ComplianceAlertRecipient $mapping): bool => $mapping->protected_area_id !== null)->keyBy(fn (ComplianceAlertRecipient $mapping): string => (string) $mapping->protected_area_id)->all(),
            'target_office' => $this->activeMappings()->filter(fn (ComplianceAlertRecipient $mapping): bool => $mapping->protected_area_id === null)->mapWithKeys(fn (ComplianceAlertRecipient $mapping): array => [(string) ($mapping->target_office_key ?: $this->offices->normalize($mapping->target_office)['key']) => $mapping])->all(),
        ];
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
            $this->templates->familyFor($report),
            $this->offices->normalize($report->targetOffice)['key'] ?? '',
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
