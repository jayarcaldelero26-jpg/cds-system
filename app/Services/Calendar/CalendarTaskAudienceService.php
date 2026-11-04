<?php

namespace App\Services\Calendar;

use App\Models\CalendarTask;
use App\Models\OrganizationalOffice;
use App\Models\ProtectedArea;
use App\Models\ProtectedAreaOfficeAssignment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CalendarTaskAudienceService
{
    private const TASK_TABLE = 'calendar_tasks';
    private const AUDIENCE_TABLE = 'calendar_task_audiences';

    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function isReady(): bool
    {
        return Schema::hasTable(self::TASK_TABLE)
            && Schema::hasColumn(self::TASK_TABLE, 'visibility')
            && Schema::hasTable(self::AUDIENCE_TABLE)
            && Schema::hasColumns(self::AUDIENCE_TABLE, [
                'calendar_task_id', 'audience_type', 'organizational_office_id', 'protected_area_id',
                'office_type_snapshot', 'office_code_snapshot', 'office_name_snapshot', 'protected_area_name_snapshot', 'label_snapshot',
            ])
            && Schema::hasTable('organizational_offices')
            && Schema::hasTable('protected_area_office_assignments')
            && Schema::hasTable('protected_areas');
    }

    /** @return array{ready:bool,available:bool,primary:?array,supervising:?array,reason:?string} */
    public function sharingOptions(User $user): array
    {
        if (! $this->isReady()) {
            return ['ready' => false, 'available' => false, 'primary' => null, 'supervising' => null,
                'reason' => 'Office sharing is temporarily unavailable. Private tasks remain available.'];
        }

        $resolved = $this->creatorAudiences($user);
        if (! $resolved['primary']) {
            return ['ready' => true, 'available' => false, 'primary' => null, 'supervising' => null,
                'reason' => $resolved['reason'] ?? 'Your current office assignment could not be matched to one active office.'];
        }

        return [
            'ready' => true,
            'available' => true,
            'primary' => $this->publicAudience($resolved['primary']),
            'supervising' => $resolved['supervising'] ? $this->publicAudience($resolved['supervising']) : null,
            'reason' => null,
        ];
    }

    /** Apply owner OR explicit audience access before any date/range predicate is added. */
    public function applyVisibilityScope(Builder $query, User $viewer, ?bool $sharingReady = null): Builder
    {
        if (! $this->isCalendarUser($viewer)) return $query->whereRaw('1 = 0');
        $sharingReady ??= $this->isReady();

        $query->where(function (Builder $authorized) use ($viewer, $sharingReady): void {
            $authorized->where(self::TASK_TABLE.'.created_by', $viewer->getKey());

            if (! $sharingReady) return;

            $identity = $this->viewerIdentity($viewer);
            if (! $identity) return;

            $authorized->orWhere(function (Builder $shared) use ($identity): void {
                $shared->where(self::TASK_TABLE.'.visibility', 'shared')
                    ->whereExists(function (QueryBuilder $audiences) use ($identity): void {
                        $this->applyAudienceIdentity($audiences, $identity);
                    });
            });
        });

        return $query;
    }

    public function canView(User $viewer, CalendarTask $task): bool
    {
        if (! $this->isCalendarUser($viewer)) return false;
        if ((int) $task->created_by === (int) $viewer->getKey()) return true;
        if (! $this->isReady() || $task->getAttribute('visibility') !== 'shared') return false;

        $query = CalendarTask::query()->whereKey($task->getKey())->where('visibility', 'shared');
        $this->applyVisibilityScope($query, $viewer);

        return $query->exists();
    }

    /** @param list<int|string> $taskIds @return array<int|string,list<array<string,mixed>>> */
    public function audiencesForTasks(array $taskIds, ?bool $sharingReady = null): array
    {
        if ($taskIds === [] || ! ($sharingReady ?? $this->isReady())) return [];

        return DB::table(self::AUDIENCE_TABLE)
            ->whereIn('calendar_task_id', $taskIds)
            ->orderBy('id')
            ->get([
                'calendar_task_id', 'audience_type', 'organizational_office_id', 'protected_area_id',
                'office_type_snapshot', 'office_code_snapshot', 'office_name_snapshot', 'protected_area_name_snapshot', 'label_snapshot',
            ])
            ->groupBy('calendar_task_id')
            ->map(fn ($rows) => $rows->map(fn ($row): array => (array) $row)->values()->all())
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function createAudiences(User $creator, bool $shareWithOffice, bool $includeSupervising): array
    {
        if (! $shareWithOffice) return [];
        if (! $this->isReady()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'share_with_office' => 'Office sharing is temporarily unavailable. Save this task privately or try again later.',
            ]);
        }

        $resolved = $this->creatorAudiences($creator);
        if (! $resolved['primary']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'share_with_office' => $resolved['reason'] ?? 'Your current office assignment is not eligible for sharing.',
            ]);
        }
        if ($includeSupervising && ! $resolved['supervising']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'include_supervising_office' => 'A supervising office is not currently mapped for this Protected Area.',
            ]);
        }

        $audiences = [$resolved['primary']];
        if ($includeSupervising) $audiences[] = $resolved['supervising'];

        return $audiences;
    }

    /** @param list<array<string,mixed>> $audiences */
    public function persistAudiences(CalendarTask $task, array $audiences): void
    {
        if ($audiences === []) return;
        $now = now();
        DB::table(self::AUDIENCE_TABLE)->insert(array_map(fn (array $audience): array => [
            'calendar_task_id' => $task->getKey(),
            'audience_type' => $audience['audience_type'],
            'organizational_office_id' => $audience['organizational_office_id'],
            'protected_area_id' => $audience['protected_area_id'],
            'office_type_snapshot' => $audience['office_type_snapshot'],
            'office_code_snapshot' => $audience['office_code_snapshot'],
            'office_name_snapshot' => $audience['office_name_snapshot'],
            'protected_area_name_snapshot' => $audience['protected_area_name_snapshot'],
            'label_snapshot' => $audience['label_snapshot'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $audiences));
    }

    /** @param array<string,mixed> $audience @return array<string,mixed> */
    public function auditAudience(array $audience): array
    {
        return [
            'type' => $audience['audience_type'],
            'organizational_office_id' => $audience['organizational_office_id'],
            'office_type' => $audience['office_type_snapshot'],
            'office_code' => $audience['office_code_snapshot'],
            'office_name' => $audience['office_name_snapshot'],
            'protected_area_id' => $audience['protected_area_id'],
            'protected_area_name' => $audience['protected_area_name_snapshot'],
            'label' => $audience['label_snapshot'],
        ];
    }

    /** @return array{primary:?array,supervising:?array,reason:?string} */
    private function creatorAudiences(User $creator): array
    {
        if (! $this->isCalendarUser($creator)) {
            return ['primary' => null, 'supervising' => null, 'reason' => 'Your account must be active, approved, and able to open Calendar.'];
        }

        $category = $this->organization->effectiveCategory($creator);
        if ($category === OrganizationalAccessService::PAMO) {
            return $this->pamoCreatorAudiences($creator);
        }

        $officeType = match ($category) {
            OrganizationalAccessService::CENRO_RECORDS,
            OrganizationalAccessService::CENRO_CHIEF,
            OrganizationalAccessService::CENRO_FOCAL => 'cenro',
            OrganizationalAccessService::PENRO_RECORDS,
            OrganizationalAccessService::OFFICE_PENRO,
            OrganizationalAccessService::PENRO_TSD_CHIEF,
            OrganizationalAccessService::PENRO_CHIEF,
            OrganizationalAccessService::PENRO_FOCAL => 'penro',
            default => null,
        };

        $office = $officeType ? $this->resolveOffice($creator->office_designated, $officeType) : null;
        if (! $office) {
            return ['primary' => null, 'supervising' => null,
                'reason' => $category === null
                    ? 'Your account has no assigned office. Private tasks remain available.'
                    : 'Your office assignment is missing, inactive, or ambiguous. Private tasks remain available.'];
        }

        return ['primary' => $this->audience('office', $office, null, null), 'supervising' => null, 'reason' => null];
    }

    /** @return array{primary:?array,supervising:?array,reason:?string} */
    private function pamoCreatorAudiences(User $creator): array
    {
        $area = $creator->protected_area_id
            ? ProtectedArea::query()->whereKey($creator->protected_area_id)->where('status', 'Active')->first(['id', 'name', 'short_name'])
            : null;
        if (! $area) {
            return ['primary' => null, 'supervising' => null, 'reason' => 'Your active Protected Area assignment could not be confirmed. Private tasks remain available.'];
        }

        $assignment = ProtectedAreaOfficeAssignment::query()
            ->where('protected_area_id', $area->getKey())
            ->where('assignment_type', 'pamo')
            ->with('office:id,code,name,office_type,is_active')
            ->first();
        $office = $assignment?->office;
        if (! $office || $office->office_type !== 'pamo' || ! $office->is_active
            || mb_strtolower(trim((string) $creator->office_designated)) !== mb_strtolower(trim((string) $office->name))) {
            return ['primary' => null, 'supervising' => null, 'reason' => 'Your PAMO office does not match the active Protected Area mapping. Private tasks remain available.'];
        }

        $primary = $this->audience('pamo', $office, $area, null);
        $supervising = $this->supervisingAudience($area);

        return ['primary' => $primary, 'supervising' => $supervising, 'reason' => null];
    }

    private function supervisingAudience(ProtectedArea $area): ?array
    {
        $assignment = ProtectedAreaOfficeAssignment::query()
            ->where('protected_area_id', $area->getKey())
            ->where('assignment_type', 'supervising')
            ->with('office:id,code,name,office_type,is_active')
            ->first();
        $office = $assignment?->office;
        if (! $office || ! $office->is_active) return null;

        $expectedType = app(\App\Services\SubmissionTracking\ProtectedAreaRoutingPolicy::class)->isDirectProtectedArea($area) ? 'penro' : 'cenro';
        if ($office->office_type !== $expectedType) return null;

        return $this->audience('supervising', $office, $area, null);
    }

    private function viewerIdentity(User $viewer): ?array
    {
        $resolved = $this->creatorAudiences($viewer);
        return $resolved['primary'];
    }

    private function resolveOffice(?string $designated, string $type): ?OrganizationalOffice
    {
        $normalized = $this->organization->normalizeOffice($designated);
        if (! $normalized || ! in_array($normalized, $this->organization->canonicalOffices(), true)) return null;
        $expectedType = in_array($normalized, $this->organization->cenroOffices(), true) ? 'cenro' : 'penro';
        if ($expectedType !== $type) return null;

        $matches = OrganizationalOffice::query()
            ->where('name', $normalized)
            ->where('office_type', $type)
            ->where('is_active', true)
            ->get(['id', 'code', 'name', 'office_type', 'is_active']);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function audience(string $type, OrganizationalOffice $office, ?ProtectedArea $area, ?string $label): array
    {
        $officeName = (string) $office->name;
        $areaName = $area ? (string) $area->name : null;
        $audienceLabel = $label ?? ($area ? $officeName.' — '.$areaName : $officeName);

        return [
            'audience_type' => $type,
            'organizational_office_id' => (int) $office->getKey(),
            'protected_area_id' => $area ? (int) $area->getKey() : null,
            'office_type_snapshot' => (string) $office->office_type,
            'office_code_snapshot' => (string) $office->code,
            'office_name_snapshot' => $officeName,
            'protected_area_name_snapshot' => $areaName,
            'label_snapshot' => $audienceLabel,
        ];
    }

    private function publicAudience(array $audience): array
    {
        return [
            'type' => $audience['audience_type'],
            'office_name' => $audience['office_name_snapshot'],
            'protected_area_name' => $audience['protected_area_name_snapshot'],
            'label' => $audience['label_snapshot'],
        ];
    }

    private function applyAudienceIdentity(QueryBuilder $audiences, array $identity): void
    {
        $table = self::AUDIENCE_TABLE;
        $officeType = $identity['office_type_snapshot'];
        $audiences->selectRaw('1')
            ->from($table)
            ->whereColumn($table.'.calendar_task_id', self::TASK_TABLE.'.id')
            ->where(function (QueryBuilder $matches) use ($identity, $officeType, $table): void {
                if ($identity['audience_type'] === 'pamo') {
                    $matches->where($table.'.audience_type', 'pamo')
                        ->where($table.'.organizational_office_id', $identity['organizational_office_id'])
                        ->where($table.'.protected_area_id', $identity['protected_area_id'])
                        ->where($table.'.office_type_snapshot', $officeType)
                        ->whereExists(function (QueryBuilder $office): void {
                            $office->selectRaw('1')->from('organizational_offices')
                                ->whereColumn('organizational_offices.id', self::AUDIENCE_TABLE.'.organizational_office_id')
                                ->where('organizational_offices.is_active', true)
                                ->whereColumn('organizational_offices.office_type', self::AUDIENCE_TABLE.'.office_type_snapshot');
                        })
                        ->whereExists(function (QueryBuilder $area): void {
                            $area->selectRaw('1')->from('protected_areas')
                                ->whereColumn('protected_areas.id', self::AUDIENCE_TABLE.'.protected_area_id')
                                ->where('protected_areas.status', 'Active');
                        });

                    return;
                }

                $matches->where(function (QueryBuilder $primary) use ($identity, $officeType, $table): void {
                    $primary->where($table.'.audience_type', 'office')
                        ->whereNull($table.'.protected_area_id')
                        ->where($table.'.organizational_office_id', $identity['organizational_office_id'])
                        ->where($table.'.office_type_snapshot', $officeType)
                        ->whereExists(function (QueryBuilder $office): void {
                            $office->selectRaw('1')->from('organizational_offices')
                                ->whereColumn('organizational_offices.id', self::AUDIENCE_TABLE.'.organizational_office_id')
                                ->where('organizational_offices.is_active', true)
                                ->whereColumn('organizational_offices.office_type', self::AUDIENCE_TABLE.'.office_type_snapshot');
                        });
                })->orWhere(function (QueryBuilder $supervising) use ($identity, $officeType, $table): void {
                    $supervising->where($table.'.audience_type', 'supervising')
                        ->whereNotNull($table.'.protected_area_id')
                        ->where($table.'.organizational_office_id', $identity['organizational_office_id'])
                        ->where($table.'.office_type_snapshot', $officeType)
                        ->whereExists(function (QueryBuilder $area): void {
                            $area->selectRaw('1')->from('protected_areas')
                                ->whereColumn('protected_areas.id', self::AUDIENCE_TABLE.'.protected_area_id')
                                ->where('protected_areas.status', 'Active');
                        })
                        ->whereExists(function (QueryBuilder $assignment): void {
                            $assignment->selectRaw('1')->from('protected_area_office_assignments as task_audience_assignment')
                                ->join('organizational_offices as task_audience_office', 'task_audience_office.id', '=', 'task_audience_assignment.organizational_office_id')
                                ->whereColumn('task_audience_assignment.protected_area_id', self::AUDIENCE_TABLE.'.protected_area_id')
                                ->whereColumn('task_audience_assignment.organizational_office_id', self::AUDIENCE_TABLE.'.organizational_office_id')
                                ->where('task_audience_assignment.assignment_type', 'supervising')
                                ->where('task_audience_office.is_active', true)
                                ->whereColumn('task_audience_office.office_type', self::AUDIENCE_TABLE.'.office_type_snapshot');
                        });
                });
            });
    }

    private function isCalendarUser(User $user): bool
    {
        return (bool) $user->is_active && (bool) $user->is_approved && $user->can('calendar.view');
    }
}
