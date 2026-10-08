<?php

namespace App\Services\SubmissionTracking;

use App\Models\ConservationReportSubmission;
use App\Models\SubmissionRoutingOverride;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Authorization\OrganizationalAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final class AdminRoutingOverrideService
{
    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly DocumentRoutingTransitionService $generic, private readonly PambRoutingTimelineService $pamb, private readonly OrganizationalAccessService $organization, private readonly AuditLogService $audit, private readonly VerifyPasskey $verifyPasskey, private readonly RoutingTransitionLifecycle $transitionLifecycle) {}
    public function canUse(User $user): bool { return $this->organization->isGlobal($user) && $user->is_active && $user->can('submission-tracking.admin-override'); }

    /** @return array<string,mixed> */
    public function available(string $source, int $recordId, User $admin): array
    {
        abort_unless($this->canUse($admin), 403); $config = $this->tracking->source($source); abort_unless($config && $source !== 'engp', 404);
        abort_unless($this->organization->canUseSubmissionTrackingSource($admin, $source, $config['ability']), 403);
        $record = $config['model']::query()->with($source === 'conservation' ? 'protectedArea' : [])->findOrFail($recordId); abort_unless($this->organization->canAccessProtectedAreaRecord($admin, $record), 403);
        if ($record instanceof ConservationReportSubmission && $this->pamb->applies($record) && ! $this->tracking->usesGenericRouting($source, $recordId)) return $this->pambOptions($record);
        $state = $this->generic->state($record, $source, null, null); $current = (string) $state['stage'];
        $actions = collect($state['actions'])->filter(fn (array $action): bool => $action['from'] === $current && ! ($action['receipt_correction_context'] ?? false))->map(fn (array $action): array => $this->action($action['key'], $action['label'], $action['action_label'], $action['correction'] ?? false, $action['categories'][0] ?? null, $action['from_office'] ?? null))->values()->all();
        $stateToken = hash('sha256', json_encode([
            'source' => $source,
            'record' => $record->getKey(),
            'stage' => $current,
            'active_cycle' => $state['active_cycle'] ?? null,
            'route_position' => [
                'snapshot_id' => data_get($state, 'route_position.snapshot_id'),
                'version' => data_get($state, 'route_position.version'),
                'setting_version_id' => data_get($state, 'route_position.setting_version_id'),
                'graph_version' => data_get($state, 'route_position.graph_version'),
                'office_penro_enabled' => data_get($state, 'route_position.office_penro_enabled'),
                'penro_tsd_chief_enabled' => data_get($state, 'route_position.penro_tsd_chief_enabled'),
            ],
            'events' => collect($state['events'] ?? [])->map(fn ($event): array => [
                $event->id, $event->event_key, $event->from_stage, $event->to_stage,
                $event->occurred_at?->toIso8601String(), $event->recorded_by, $event->metadata,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
        return ['available' => $actions !== [], 'engine' => 'generic', 'source' => $source, 'source_id' => $record->getKey(), 'current_stage' => $current, 'state_token' => $stateToken, 'current_location' => str_starts_with($current, 'transit_') ? 'In transit' : $record->getAttribute('target_office'), 'accountable_category' => $actions[0]['accountable_category'] ?? null, 'accountable_office' => $actions[0]['accountable_office'] ?? null, 'actions' => $actions, 'protected_area_id' => $record->getAttribute('protected_area_id')];
    }

    /** @return array<string,mixed> */
    private function pambOptions(ConservationReportSubmission $record): array
    {
        $presentation = $this->pamb->present($record); $current = collect($presentation['timeline'])->firstWhere('status', 'current'); $stage = (string) ($current['stage_key'] ?? $current['key'] ?? ''); $canonical = $this->pamb->canonicalStageKey($stage); $actions = [];
        if ($canonical === PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL) {
            $actions[] = $this->action(PambRoutingTimelineService::PENRO_FINAL_RETURNED_FOR_CORRECTION, 'Return Report to PENRO CDS Focal for Correction', 'Return Report to PENRO CDS Focal', true, OrganizationalAccessService::OFFICE_PENRO, 'Office of the PENRO');
            $actions[] = $this->action(PambRoutingTimelineService::PENRO_FINAL_APPROVED_FOR_REGIONAL, 'Approve for Regional Release', 'Approve for Regional Release', false, OrganizationalAccessService::OFFICE_PENRO, 'Office of the PENRO');
        } elseif (($current['can_record'] ?? false) && ($category = $this->pambCategory($canonical)) !== null) {
            $actions[] = $this->action($stage, $current['label'] ?? 'Record Routing Event', $current['action_label'] ?? 'Record Routing Event', false, $category, $this->pambOffice($record, $category));
        } elseif ($canonical === SubmissionTrackingService::CENRO_RELEASE && (app(ProtectedAreaRoutingPolicy::class)->isDirectPenro($record) || app(PambMovProcessingService::class)->status($record) !== PambMovProcessingService::READY_FOR_RELEASE)) {
            $actions = [];
        } elseif (in_array($canonical, [SubmissionTrackingService::CENRO_RELEASE, PambRoutingTimelineService::RECORDS_RECEIVED, PambRoutingTimelineService::RELEASED_TO_REGIONAL], true) && ($category = $this->pambCategory($canonical)) !== null) {
            $key = $canonical === PambRoutingTimelineService::RECORDS_RECEIVED ? SubmissionTrackingService::PENRO_RECEIPT : ($canonical === PambRoutingTimelineService::RELEASED_TO_REGIONAL ? SubmissionTrackingService::REGIONAL_ENDORSEMENT : SubmissionTrackingService::CENRO_RELEASE);
            $actions[] = $this->action($key, $current['label'] ?? 'Record Routing Event', $current['action_label'] ?? 'Record Routing Event', false, $category, $this->pambOffice($record, $category));
        }
        $stateToken = hash('sha256', json_encode([
            'source' => 'conservation',
            'record' => $record->getKey(),
            'stage' => $stage,
            'timeline' => collect($presentation['timeline'] ?? [])->map(fn (array $item): array => [
                $item['stage_key'] ?? $item['key'] ?? null,
                $item['routing_event_id'] ?? null,
                $item['occurred_at'] ?? null,
                $item['recorded_by'] ?? null,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
        return ['available' => $actions !== [], 'engine' => 'pamb', 'source' => 'conservation', 'source_id' => $record->getKey(), 'current_stage' => $stage, 'state_token' => $stateToken, 'current_location' => $presentation['current_document_location'], 'accountable_category' => $actions[0]['accountable_category'] ?? null, 'accountable_office' => $actions[0]['accountable_office'] ?? null, 'actions' => $actions, 'protected_area_id' => $record->getAttribute('protected_area_id')];
    }

    private function action(string $key, string $label, string $actionLabel, bool $correction, ?string $category, ?string $office): array { return ['key' => $key, 'label' => $label, 'action_label' => $actionLabel, 'correction' => $correction, 'remarks_required' => $correction, 'accountable_category' => $this->organization->categoryLabel($category), 'accountable_category_key' => $category, 'accountable_office' => $office]; }
    private function pambCategory(string $stage): ?string { return match ($stage) {
        PambRoutingTimelineService::FORWARDED_RECORDS_TO_PENRO, PambRoutingTimelineService::RECEIVED_BY_RECORDS_FINAL, PambRoutingTimelineService::RELEASED_TO_REGIONAL => OrganizationalAccessService::PENRO_RECORDS,
        PambRoutingTimelineService::RECEIVED_BY_PENRO, PambRoutingTimelineService::RECEIVED_BY_PENRO_FINAL, PambRoutingTimelineService::FORWARDED_PENRO_TO_TSD, PambRoutingTimelineService::FORWARDED_PENRO_TO_RECORDS => OrganizationalAccessService::OFFICE_PENRO,
        PambRoutingTimelineService::RECEIVED_BY_TSD, PambRoutingTimelineService::FORWARDED_TSD_TO_CDS => OrganizationalAccessService::PENRO_TSD_CHIEF,
        PambRoutingTimelineService::RECEIVED_BY_CDS, PambRoutingTimelineService::FORWARDED_CDS_FOCAL_TO_CHIEF => OrganizationalAccessService::PENRO_FOCAL,
        PambRoutingTimelineService::RECEIVED_BY_CDS_CHIEF, PambRoutingTimelineService::FORWARDED_CDS_TO_PENRO => OrganizationalAccessService::PENRO_CHIEF,
        SubmissionTrackingService::CENRO_RELEASE => OrganizationalAccessService::CENRO_RECORDS, default => null,
    }; }
    private function pambOffice(ConservationReportSubmission $record, string $category): string { return in_array($category, [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_RECORDS], true) ? (string) $record->target_office : 'PENRO'; }

    public function execute(string $source, int $recordId, string $actionKey, User $admin, string $reason, PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?string $expectedStage = null, ?string $expectedStateToken = null): SubmissionRoutingOverride
    {
        abort_unless($this->canUse($admin), 403); $reason = trim($reason); if ($reason === '') throw ValidationException::withMessages(['reason' => 'An override reason is required.']);
        $before = $this->available($source, $recordId, $admin);
        if (($expectedStage !== null && $before['current_stage'] !== $expectedStage)
            || ($expectedStateToken !== null && ! hash_equals($expectedStateToken, $before['state_token']))) {
            throw ValidationException::withMessages(['stage' => 'The record changed while passkey verification was in progress. Refresh and try again.']);
        }
        $selected = collect($before['actions'])->firstWhere('key', $actionKey); if (! $selected) throw ValidationException::withMessages(['action' => 'That override action is no longer valid.']);
        $passkey = ($this->verifyPasskey)($credential, $options, $admin); $record = $this->tracking->source($source)['model']::query()->findOrFail($recordId);
        return DB::transaction(function () use ($source, $recordId, $actionKey, $admin, $reason, $passkey, $before, $selected, $record): SubmissionRoutingOverride {
            $fresh = $this->available($source, $recordId, $admin); if ($fresh['current_stage'] !== $before['current_stage'] || $fresh['state_token'] !== $before['state_token'] || ! collect($fresh['actions'])->contains('key', $actionKey)) throw ValidationException::withMessages(['stage' => 'The record changed while passkey verification was in progress. Refresh and try again.']);
            $eventKey = null; $resulting = null; $event = null;
            if ($before['engine'] === 'generic') { $event = $this->generic->transitionAsOverride($record, $source, $actionKey, $admin, ['override_for_category' => $selected['accountable_category_key'], 'override_for_office' => $selected['accountable_office'], 'override_reason' => $reason], $reason); $eventKey = $event->event_key; $resulting = $event->to_stage; }
            elseif ($this->pamb->isInternalStageKey($actionKey)) { $event = $this->pamb->record($record, $actionKey, CarbonImmutable::now()->toDateTimeString(), $admin->id, $reason); $eventKey = $event->stage_key; $resulting = $event->stage_key; }
            else { $this->tracking->transitionPambAsOverride($record, $actionKey, $admin->id); $eventKey = $actionKey; $resulting = $actionKey; }
            $override = SubmissionRoutingOverride::query()->create(['source' => $source, 'source_record_id' => $recordId, 'engine' => $before['engine'], 'action_key' => $actionKey, 'event_key' => $eventKey, 'actual_actor_user_id' => $admin->id, 'actual_actor_category' => $this->organization->effectiveCategory($admin), 'overridden_accountable_category' => $selected['accountable_category_key'], 'overridden_office' => $selected['accountable_office'], 'protected_area_id' => $record->getAttribute('protected_area_id'), 'reason' => $reason, 'authentication_method' => 'webauthn_passkey', 'passkey_id' => $passkey->getKey(), 'previous_stage' => $before['current_stage'], 'resulting_stage' => $resulting, 'metadata' => ['administrative_override' => true, 'action_label' => $selected['action_label']]]);
            $this->audit->record('submission_tracking', 'Submission Tracking Administrative Override', $source, $recordId, $source, 'Administrative override executed by '.$admin->name.'.', ['administrative_override' => true, 'action_key' => $actionKey, 'override_for_category' => $selected['accountable_category_key'], 'override_for_office' => $selected['accountable_office'], 'reason' => $reason, 'authentication_method' => 'webauthn_passkey', 'override_id' => $override->id], $admin->id);
            if ($event instanceof \App\Models\DocumentRoutingEvent || $event instanceof \App\Models\PambRoutingEvent) {
                $this->transitionLifecycle->afterTransition($event, $admin);
            }
            return $override;
        });
    }
}
