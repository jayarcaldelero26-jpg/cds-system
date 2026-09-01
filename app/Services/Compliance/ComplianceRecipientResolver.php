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
    public function resolve(OverdueReport $report, ?string $alertType = null): ?ResolvedComplianceRecipient
    {
        $mappings = $this->activeMappings();

        return $this->resolveFromMappings($report, $mappings, $alertType);
    }

    public function destinationKeyForMapping(ComplianceAlertRecipient $mapping): ?string
    {
        if ($mapping->protected_area_id) {
            return 'pa:'.(int) $mapping->protected_area_id;
        }

        $key = trim((string) ($mapping->target_office_key ?: $this->offices->normalize($mapping->target_office)['key']));

        return $key === '' ? null : 'office:'.$key;
    }

    public function destinationForMapping(ComplianceAlertRecipient $mapping): ?string
    {
        if ($mapping->protected_area_id) {
            $destination = trim((string) $mapping->protectedArea?->name);

            return $destination !== '' ? $destination : null;
        }

        $office = $this->offices->normalize($mapping->target_office);

        return $office['key'] && $office['label'] && mb_strtolower($office['label']) !== 'unassigned office'
            ? $office['label']
            : null;
    }

    public function mappingForDestinationKey(string $destinationKey): ?ComplianceAlertRecipient
    {
        return $this->activeMappings()->first(fn (ComplianceAlertRecipient $mapping): bool => $this->destinationKeyForMapping($mapping) === $destinationKey);
    }

    /** @param Collection<int,array<string,mixed>> $references @param Collection<int,OverdueReport> $dueSoon @param Collection<int,OverdueReport> $overdue */
    public function destinationCards(Collection $references, Collection $dueSoon, Collection $overdue): Collection
    {
        $mappings = $this->activeMappings();

        return $mappings->map(function (ComplianceAlertRecipient $mapping) use ($references, $dueSoon, $overdue): array {
            $key = $this->destinationKeyForMapping($mapping);
            $destination = $this->destinationForMapping($mapping);
            $referenceItems = $references->filter(fn (array $reference): bool => $this->referenceKey($reference) === $key);
            $dueSoonItems = $dueSoon->filter(fn (OverdueReport $report): bool => $this->logicalKey($report) === $key);
            $overdueItems = $overdue->filter(fn (OverdueReport $report): bool => $this->logicalKey($report) === $key);
            $isEngp = $mapping->protected_area_id === null;

            return [
                'destination_key' => $key,
                'mapping_id' => $mapping->id,
                'destination' => $destination,
                'destination_type' => $isEngp ? 'Development / ENGP Office' : 'Protected Area',
                'protected_area_id' => $mapping->protected_area_id,
                'target_office' => $mapping->target_office,
                'modules' => $referenceItems->pluck('module_name')->unique()->values()->all(),
                'report_count' => $referenceItems->count(),
                'due_soon_count' => $dueSoonItems->count(),
                'overdue_count' => $overdueItems->count(),
                'status' => $destination && filter_var($mapping->recipient_email, FILTER_VALIDATE_EMAIL) ? 'mapped' : 'unmapped',
                'recipient' => [
                    'name' => $mapping->recipient_name ?: ($isEngp ? 'The concerned CENR Officer' : 'The OIC, PASu'),
                    'attention_line' => trim((string) $mapping->attention_line) ?: null,
                    'email' => $mapping->recipient_email,
                    'cc_emails' => $this->emails($mapping->cc_emails),
                ],
                'notes' => $mapping->notes,
            ];
        })->filter(fn (array $card): bool => $card['destination_key'] !== null)->values();
    }

    /** @param Collection<int, ComplianceAlertRecipient> $mappings */
    private function resolveFromMappings(OverdueReport $report, Collection $mappings, ?string $alertType = null): ?ResolvedComplianceRecipient
    {

        $office = $this->offices->normalize($report->targetOffice);
        $indexes = $this->mappingIndexes();
        $mapping = $report->protectedAreaId
            ? ($indexes['protected_area'][(string) $report->protectedAreaId] ?? null)
            : ($indexes['target_office'][$office['key'] ?? ''] ?? null);

        $destination = $this->destination($report, $mapping);

        if ($mapping && $destination !== null && filter_var($mapping->recipient_email, FILTER_VALIDATE_EMAIL)) {
            $presentation = $this->templates->recipientDefaultsFor($report, $this->settings->effective(), $alertType);

            return new ResolvedComplianceRecipient(
                key: 'mapping:'.$mapping->id,
                email: $mapping->recipient_email,
                ccEmails: $this->emails($mapping->cc_emails),
                name: $this->designation($mapping->recipient_name, $presentation['default_to']),
                source: $mapping->protected_area_id ? 'protected_area' : 'target_office',
                attentionLine: $this->designation($mapping->attention_line, $presentation['default_attention']),
                mappingId: $mapping->id,
                destination: $destination,
            );
        }

        return null;
    }

    /** @param Collection<int, OverdueReport> $reports @return array{deliveries: Collection<int, array<string,mixed>>, unmapped: Collection<int, OverdueReport>} */
    public function plans(Collection $reports, ?string $alertType = null): array
    {
        $unmapped = collect();
        $deliveries = [];
        $mappings = $this->activeMappings();

        foreach ($reports as $report) {
            $recipient = $this->resolveFromMappings($report, $mappings, $alertType);
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
                    destination: $recipient->destination,
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
    public function readiness(Collection $reports, ?string $alertType = null): Collection
    {
        $mappings = $this->activeMappings();

        return $reports->groupBy(fn (OverdueReport $report): string => $this->logicalKey($report))
            ->map(function (Collection $group) use ($mappings, $alertType): array {
                $first = $group->first();
                $recipient = $this->resolveFromMappings($first, $mappings, $alertType);
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
            $mapping = $first['protected_area_id']
                ? ($indexes['protected_area'][(string) $first['protected_area_id']] ?? null)
                : ($indexes['target_office'][$office['key'] ?? ''] ?? null);
            $destination = $first['protected_area_id']
                ? trim((string) $first['protected_area_name'])
                : ($office['label'] ?? trim((string) ($first['target_office'] ?? '')));
            $mappingReady = $mapping
                && filter_var($mapping->recipient_email, FILTER_VALIDATE_EMAIL)
                && $destination !== ''
                && mb_strtolower($destination) !== 'protected area not specified'
                && mb_strtolower($destination) !== 'unassigned office';

            return ['key'=>$key,'destination'=>$destination,'type'=>$first['protected_area_id'] ? 'Protected Area' : 'Implementing / Target Office','scope'=>$first['protected_area_id'] ? 'protected_area' : 'target_office','protected_area_id'=>$first['protected_area_id'],'target_office'=>$office['label'] ?? $first['target_office'],'report_count'=>$items->count(),'modules'=>$items->pluck('module_name')->unique()->values()->all(),'status'=>$mappingReady ? 'mapped' : 'unmapped','recipient'=>$mappingReady ? ['id'=>$mapping->id,'email'=>$mapping->recipient_email] : null];
        })->values();
    }


    /** @return Collection<int,ComplianceAlertRecipient> */
    public function activeMappings(): Collection
    {
        return $this->activeMappings ??= ComplianceAlertRecipient::query()
            ->where('is_active', true)
            ->with('protectedArea:id,name')
            ->orderBy('id')
            ->get(['id', 'protected_area_id', 'target_office', 'target_office_key', 'recipient_email', 'cc_emails', 'recipient_name', 'attention_line', 'is_active', 'notes']);
    }

    /** @return array{protected_area:array<string,ComplianceAlertRecipient>,target_office:array<string,ComplianceAlertRecipient>} */
    private function mappingIndexes(): array
    {
        return $this->mappingIndexes ??= [
            'protected_area' => $this->activeMappings()->filter(fn (ComplianceAlertRecipient $mapping): bool => $mapping->protected_area_id !== null)->keyBy(fn (ComplianceAlertRecipient $mapping): string => (string) $mapping->protected_area_id)->all(),
            'target_office' => $this->activeMappings()->filter(fn (ComplianceAlertRecipient $mapping): bool => $mapping->protected_area_id === null)->mapWithKeys(fn (ComplianceAlertRecipient $mapping): array => [(string) ($mapping->target_office_key ?: $this->offices->normalize($mapping->target_office)['key']) => $mapping])->all(),
        ];
    }
    private function designation(?string $value, string $fallback = 'The OIC, PASu'): string
    {
        $designation = trim((string) $value);

        return $designation !== '' ? $designation : $fallback;
    }

    private function deliveryKey(OverdueReport $report, ResolvedComplianceRecipient $recipient): string
    {
        return 'delivery:'.hash('sha256', implode('|', [
            $this->templates->familyFor($report),
            $this->logicalKey($report),
            $recipient->destination ?? '',
            $this->offices->normalize($report->targetOffice)['key'] ?? '',
            mb_strtolower($recipient->email),
            implode(',', $recipient->ccEmails),
        ]));
    }

    private function destination(OverdueReport $report, ?ComplianceAlertRecipient $mapping): ?string
    {
        if (! $mapping) {
            return null;
        }

        if ($report->protectedAreaId) {
            $destination = trim((string) $mapping->protectedArea?->name);

            return $destination !== '' && mb_strtolower($destination) !== 'protected area not specified'
                ? $destination
                : null;
        }

        $office = $this->offices->normalize($mapping->target_office ?: $report->targetOffice);

        return $office['key'] && $office['label'] && mb_strtolower($office['label']) !== 'unassigned office'
            ? $office['label']
            : null;
    }

    /** @return array<int, string> */
    private function emails(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $values)));
    }

    /** @param array<string,mixed> $reference */
    private function referenceKey(array $reference): ?string
    {
        if (! empty($reference['protected_area_id'])) {
            return 'pa:'.(int) $reference['protected_area_id'];
        }

        $office = $this->offices->normalize($reference['target_office'] ?? null);

        return $office['key'] ? 'office:'.$office['key'] : null;
    }
}
