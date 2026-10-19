<?php

namespace App\Services\SubmissionTracking;

use App\Models\ConservationReportSubmission;
use App\Models\SubmissionRoutingSnapshot;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Support\Facades\Schema;

/** Centralizes capability checks for the generic custody state machine. */
final class DocumentRoutingAccessService
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function canView(User $user, Model $record, string $sourceKey, ?string $ability = null): bool
    {
        if ($this->organization->isGlobal($user)) return true;
        if ($ability && ! $this->organization->canUseSubmissionTrackingSource($user, $sourceKey, $ability)) return false;
        if ($this->organization->effectiveCategory($user) === OrganizationalAccessService::PAMO) {
            return $sourceKey === 'conservation'
                && $record instanceof ConservationReportSubmission
                && app(\App\Services\Conservation\ConservationReportWorkflowRegistry::class)->supportsPamoSubmission((string) $record->workflow_key)
                && $this->organization->canViewSubmission($user, $record);
        }
        return $sourceKey === 'engp'
            ? $this->organization->canViewDevelopmentRecord($user, $record)
            : $this->organization->canAccessProtectedAreaRecord($user, $record);
    }

    /** @param array<string,mixed> $action */
    public function canPerform(User $user, Model $record, string $sourceKey, array $action, ?string $ability = null): bool
    {
        if (! $this->canView($user, $record, $sourceKey, $ability)) return false;

        $category = $this->organization->effectiveCategory($user);
        if (! in_array($category, $action['categories'] ?? [], true)) return false;

        if (in_array($category, [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_RECORDS], true)
            && ! $this->same($user->office_designated, $sourceKey === 'engp' ? $record->getAttribute('office') : $record->getAttribute('target_office'))) return false;
        if (in_array($category, [
            OrganizationalAccessService::PENRO_RECORDS,
            OrganizationalAccessService::OFFICE_PENRO,
            OrganizationalAccessService::PENRO_TSD_CHIEF,
            OrganizationalAccessService::PENRO_FOCAL,
            OrganizationalAccessService::PENRO_CHIEF,
        ], true)
            && ! str_starts_with(mb_strtolower(trim((string) $user->office_designated)), 'penro ')) return false;
        if ($category === OrganizationalAccessService::PAMO) {
            if (! $this->organization->canAccessProtectedArea($user, $record->getAttribute('protected_area_id'))) return false;
            $facts = $this->organization->pamoAssignmentForProtectedArea((int) $record->getAttribute('protected_area_id'));
            if (! $facts) return false;
            $position = Schema::hasTable('submission_routing_snapshots')
                ? SubmissionRoutingSnapshot::query()->where('source_key', $sourceKey)->where('source_id', $record->getKey())->first()
                : null;
            $profile = $position?->profile ?? $facts['profile'];
            if ($action['key'] === 'forward_from_pamo' && ! in_array($profile, ['direct', 'pamo_direct'], true)) return false;
            if ($action['key'] === 'forward_from_pamo_to_cenro_focal'
                && ($profile !== 'pamo_regular' || $position?->origin_kind !== 'pamo_pamb')) return false;
            if (in_array($action['key'], ['forward_from_pamo', 'forward_from_pamo_to_cenro_focal', 'receive_correction'], true)
                && $position?->origin_kind === 'pamo_pamb'
                && (int) $position->pamo_office_id !== (int) $facts['pamo_office_id']) return false;
            if ($action['key'] === 'receive_correction'
                && ($position?->origin_kind !== 'pamo_pamb'
                    || ! in_array($position?->profile, ['pamo_regular', 'pamo_direct'], true))) return false;
        }

        return true;
    }

    /** @param list<array{key:mixed,categories:array}> $alreadyAuthorizedActions From this same actor/record/source/ability presentation call only.
     *  @return array<string,bool>
     */
    public function capabilities(User $user, Model $record, string $sourceKey, array $actions, ?string $ability = null, array $alreadyAuthorizedActions = []): array
    {
        $allowed = array_filter($actions, fn (array $action): bool => in_array([
            'key' => $action['key'] ?? null,
            'categories' => $action['categories'] ?? [],
        ], $alreadyAuthorizedActions, true)
            || $this->canPerform($user, $record, $sourceKey, $action, $ability));
        $types = collect($allowed)->pluck('event_key');
        return [
            'canView' => $this->canView($user, $record, $sourceKey, $ability),
            'canForward' => $types->contains('forwarded') || $types->contains('endorsed'),
            'canReceive' => $types->contains('received'),
            'canReview' => false,
            'canCorrect' => $user->can('submission-tracking.correct-routing'),
            'canRelease' => $types->contains('released'),
            'canEndorse' => $types->contains('endorsed') || $types->contains('released'),
            'canReturnForCorrection' => $types->contains('returned_for_correction'),
        ];
    }

    private function same(?string $left, ?string $right): bool
    {
        return filled($left) && filled($right) && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }
}
