<?php

namespace App\Services\SubmissionTracking;

use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

/** Canonical office/Protected Area choices and validation for submission forms. */
final class SubmissionFormScopeService
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    /** @return array{targetOffices:list<array{id:int,name:string,label:string}>,protectedAreasByOffice:array<string,list<array{id:int,name:string,short_name:?string}>>} */
    public function options(User $user): array
    {
        $offices = $this->officeOptions($user);
        $areas = $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')
            ->with('supervisingOfficeAssignment.office')->orderBy('name')->get(['id', 'name', 'short_name']);
        $byOffice = [];
        foreach ($offices as $office) $byOffice[(string) $office['id']] = [];
        foreach ($areas as $area) {
            $assignedName = $area->supervisingOfficeAssignment?->office?->name;
            $canonicalName = filled($assignedName)
                ? $this->organization->normalizeOffice($assignedName)
                : $this->organization->supervisingOfficeNameForProtectedArea((int) $area->id);
            $office = collect($offices)->first(fn (array $candidate): bool => $this->organization->normalizeOffice($candidate['name']) === $this->organization->normalizeOffice($canonicalName));
            if ($office) $byOffice[(string) $office['id']][] = ['id' => (int) $area->id, 'name' => $area->name, 'short_name' => $area->short_name];
        }
        return ['targetOffices' => $offices, 'protectedAreasByOffice' => $byOffice];
    }

    /** @return list<array{id:int,name:string,label:string}> */
    public function officeOptions(User $user): array
    {
        if ($this->organization->isGlobal($user)) {
            return array_map(fn (array $office): array => ['id' => (int) $office['id'], 'name' => $office['name'], 'label' => $office['label']], $this->organization->officeOptions());
        }

        $category = $this->organization->effectiveCategory($user);
        $canonical = $category === OrganizationalAccessService::PAMO
            ? $this->organization->supervisingOfficeNameForProtectedArea($user->protected_area_id ? (int) $user->protected_area_id : null)
            : $this->organization->normalizeOffice($user->office_designated);
        if (! $canonical) return [];

        return OrganizationalOffice::query()->where('is_active', true)->where('name', $canonical)
            ->whereIn('name', [...$this->organization->cenroOffices(), ...$this->organization->penroOffices()])
            ->get(['id', 'name'])->map(fn (OrganizationalOffice $office): array => ['id' => (int) $office->id, 'name' => $office->name, 'label' => $office->name])->all();
    }

    /** Validate the selected office and PA together; return the canonical office name for legacy storage columns. */
    public function resolve(User $user, mixed $officeValue, mixed $protectedAreaId): string
    {
        $offices = $this->officeOptions($user);
        $selected = collect($offices)->first(fn (array $office): bool => (string) $office['id'] === trim((string) $officeValue)
            || $this->organization->normalizeOffice($office['name']) === $this->organization->normalizeOffice((string) $officeValue));

        if (! $selected) {
            if (! $this->organization->isGlobal($user)) abort(403);
            throw ValidationException::withMessages(['target_office' => 'Select an office within your authorized organizational scope.']);
        }

        if ($protectedAreaId !== null && $protectedAreaId !== '') {
            $area = ProtectedArea::query()->find($protectedAreaId);
            $scopedArea = $area && $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id')->whereKey($area->id)->exists();
            $canonicalOffice = $area ? $this->organization->supervisingOfficeNameForProtectedArea((int) $area->id) : null;
            if (! $scopedArea || $this->organization->normalizeOffice($canonicalOffice) !== $this->organization->normalizeOffice($selected['name'])) {
                if (! $this->organization->isGlobal($user)) abort(403);
                throw ValidationException::withMessages(['protected_area_id' => 'Select a Protected Area assigned to the selected office and within your authorized scope.']);
            }
        }

        return $selected['name'];
    }

    public function normalizeRequest(Request $request, string $officeField = 'target_office', string $areaField = 'protected_area_id'): void
    {
        if (! $request->filled($officeField)) return;
        $request->merge([$officeField => $this->resolve($request->user(), $request->input($officeField), $request->input($areaField))]);
    }
}
