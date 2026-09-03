<?php

namespace App\Services\SubmissionTracking;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;

/** Centralizes capability checks for the generic custody state machine. */
final class DocumentRoutingAccessService
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function canView(User $user, Model $record, string $sourceKey, ?string $ability = null): bool
    {
        if (! $this->organization->canAccessUnit($user, OrganizationalAccessService::CONSERVATION)) return false;
        if ($this->organization->isGlobal($user)) return true;
        if (! $this->organization->isGlobal($user) && $ability && ! $user->can($ability)) return false;
        return $this->organization->canAccessProtectedAreaRecord($user, $record);
    }

    /** @param array<string,mixed> $action */
    public function canPerform(User $user, Model $record, string $sourceKey, array $action, ?string $ability = null): bool
    {
        if (! $this->canView($user, $record, $sourceKey, $ability)) return false;
        if ($this->organization->isGlobal($user)) return true;

        $category = $this->organization->effectiveCategory($user);
        if (! in_array($category, $action['categories'] ?? [], true)) return false;

        if (in_array($category, [OrganizationalAccessService::CENRO_FOCAL, OrganizationalAccessService::CENRO_CHIEF, OrganizationalAccessService::CENRO_RECORDS], true)
            && ! $this->same($user->office_designated, $record->getAttribute('target_office'))) return false;
        if (in_array($category, [OrganizationalAccessService::PENRO_RECORDS, OrganizationalAccessService::PENRO_FOCAL, OrganizationalAccessService::PENRO_CHIEF], true)
            && ! str_starts_with(mb_strtolower(trim((string) $user->office_designated)), 'penro ')) return false;
        if ($category === OrganizationalAccessService::PAMO && ! $this->organization->canAccessProtectedArea($user, $record->getAttribute('protected_area_id'))) return false;

        return true;
    }

    /** @return array<string,bool> */
    public function capabilities(User $user, Model $record, string $sourceKey, array $actions, ?string $ability = null): array
    {
        $allowed = array_filter($actions, fn (array $action): bool => $this->canPerform($user, $record, $sourceKey, $action, $ability));
        $types = collect($allowed)->pluck('event_key');
        return [
            'canView' => $this->canView($user, $record, $sourceKey, $ability),
            'canForward' => $types->contains('forwarded') || $types->contains('endorsed'),
            'canReceive' => $types->contains('received'),
            'canReview' => false,
            'canCorrect' => $user->can('submission-tracking.correct-routing'),
            'canRelease' => $types->contains('released'),
            'canEndorse' => $types->contains('endorsed') || $types->contains('released'),
        ];
    }

    private function same(?string $left, ?string $right): bool
    {
        return filled($left) && filled($right) && mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }
}
