<?php

namespace App\Services\Compliance;

use App\Models\ReportComplianceConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogService;

class ComplianceConfirmationService
{
    public function __construct(private readonly OverdueReportService $reports, private readonly AuditLogService $auditLogs) {}

    public function confirm(Model $source, User $user, ?string $remarks = null): ReportComplianceConfirmation
    {
        if (! $this->reports->sourceIsSubmitted($source)) {
            throw ValidationException::withMessages([
                'source' => 'The report must be submitted before Records can confirm it.',
            ]);
        }

        $latest = $this->latestEvent($source);
        if ($latest?->event_type === ReportComplianceConfirmation::EVENT_CONFIRMED) {
            throw ValidationException::withMessages([
                'source' => 'This report already has an active Records confirmation. The original audit evidence was not changed.',
            ]);
        }

        $confirmation = ReportComplianceConfirmation::query()->create([
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'event_type' => ReportComplianceConfirmation::EVENT_CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE),
            'confirmed_by' => $user->id,
            'remarks' => $remarks,
            'snapshot' => $this->reports->confirmationSnapshot($source),
        ]);
        $this->auditLogs->record('compliance_alerts', 'Records Confirmation Recorded', $source::class, $source->getKey(), 'Compliance Alerts', 'Recorded Records confirmation for a submitted report.', ['confirmation_id' => $confirmation->id, 'remarks' => $remarks], $user->id);

        return $confirmation;
    }

    public function unconfirm(Model $source, User $user, string $reason): ReportComplianceConfirmation
    {
        $latest = $this->latestEvent($source);
        if (! $latest || $latest->event_type !== ReportComplianceConfirmation::EVENT_CONFIRMED) {
            throw ValidationException::withMessages([
                'source' => 'This report does not have an active Records confirmation to revoke.',
            ]);
        }

        $revocation = ReportComplianceConfirmation::query()->create([
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'event_type' => ReportComplianceConfirmation::EVENT_REVOKED,
            'confirmed_at' => $latest->confirmed_at,
            'confirmed_by' => $latest->confirmed_by,
            'remarks' => $latest->remarks,
            'snapshot' => $latest->snapshot,
            'original_confirmation_id' => $latest->id,
            'revoked_at' => CarbonImmutable::now(ComplianceAlertSettingsService::TIMEZONE),
            'revoked_by' => $user->id,
            'revocation_reason' => $reason,
        ]);
        $this->auditLogs->record('compliance_alerts', 'Records Confirmation Revoked', $source::class, $source->getKey(), 'Compliance Alerts', 'Revoked Records confirmation for a submitted report.', ['confirmation_id' => $revocation->id, 'reason' => $reason], $user->id);

        return $revocation;
    }

    private function latestEvent(Model $source): ?ReportComplianceConfirmation
    {
        return ReportComplianceConfirmation::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->latest('id')
            ->first();
    }
}
