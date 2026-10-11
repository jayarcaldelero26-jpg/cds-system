<?php

namespace App\Services\HistoricalReports;

use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\HistoricalReportOrigin;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ManagementPlanType;
use App\Models\User;
use App\Services\ActualActivityDateGuard;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\BusinessCalendarService;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Conservation\PambComplianceCalculator;
use App\Services\DateConductedRangeService;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\SubmissionTracking\SubmissionFormScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Persists source-module records with a separate, immutable historical origin. */
final class HistoricalReportEncoder
{
    public function __construct(
        private readonly HistoricalReportCatalog $catalog,
        private readonly ConservationReportWorkflowRegistry $conservation,
        private readonly EngpReportWorkflowRegistry $engp,
        private readonly OrganizationalAccessService $organization,
        private readonly SubmissionFormScopeService $formScope,
        private readonly DateConductedRangeService $dateRanges,
        private readonly ActualActivityDateGuard $actualDates,
        private readonly ProtectedAttachmentService $attachments,
    ) {}

    /** @return array{source:string,id:int,label:string,idempotent:bool} */
    public function encode(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $request->validate([
            'workflow_id' => ['required', 'string', 'max:180'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $workflow = collect($this->catalog->for($user)['workflows'])->firstWhere('id', $request->string('workflow_id')->toString());
        abort_unless($workflow, 404);

        $idempotent = HistoricalReportOrigin::query()->where('idempotency_key', $request->string('idempotency_key')->toString())->first();
        if ($idempotent) {
            abort_unless($idempotent->source === $workflow['source'] && $idempotent->workflow_key === $workflow['workflow_key'], 409);
            abort_unless($this->authorizedForExisting($user, (string) $idempotent->source, (int) $idempotent->source_record_id), 403);
            return ['source' => $idempotent->source, 'id' => (int) $idempotent->source_record_id, 'label' => (string) $workflow['label'], 'idempotent' => true];
        }

        $this->authorizeWorkflow($user, $workflow, $request);
        if ($workflow['source'] !== 'engp') {
            $this->formScope->normalizeRequest($request);
        }

        [$attributes, $modelClass, $attachmentSource, $fileFields, $milestones] = $this->validatedData($request, $workflow, $user);
        $storedPaths = [];

        try {
            foreach ($fileFields as $fileField => $source) {
                $files = is_array($request->file($fileField)) ? $request->file($fileField) : array_filter([$request->file($fileField)]);
                $references = [];
                foreach ($files as $file) {
                    if (! $file) continue;
                    $path = $this->attachments->store($file, $source);
                    $storedPaths[] = $path;
                    $references[] = [
                        'name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                        'size' => $file->getSize(),
                    ];
                }
                if ($fileField === 'attachments') {
                    $attributes['attachments'] = $references;
                } elseif ($references !== []) {
                    $reference = $references[0];
                    $attributes['mov_file_path'] = $reference['path'];
                    // ENGP stores the protected MOV path but has no filename
                    // column; its attachment descriptor derives a display name.
                    if ($modelClass !== EngpReportSubmission::class) {
                        $attributes['mov_file_name'] = $reference['name'];
                    }
                    if ($modelClass === Aws::class) {
                        $attributes['report_file_path'] = $reference['path'];
                        $attributes['report_file_name'] = $reference['name'];
                        unset($attributes['mov_file_path'], $attributes['mov_file_name']);
                    }
                    if (in_array($modelClass, [IpafManagementReport::class, IpafRevenueCollection::class, ImeaFacilityMaintenanceReport::class], true)) {
                        $attributes['mov_mime_type'] = $reference['mime_type'];
                        $attributes['mov_size'] = $reference['size'];
                    }
                }
            }

            $record = DB::transaction(function () use ($attributes, $modelClass, $workflow, $user, $request, $milestones): \Illuminate\Database\Eloquent\Model {
                $record = new $modelClass;
                $record->forceFill([
                    ...$attributes,
                    'is_historical' => true,
                    'created_by' => $user->getKey(),
                    'updated_by' => $user->getKey(),
                ])->saveOrFail();

                HistoricalReportOrigin::query()->create([
                    'source' => $workflow['source'],
                    'source_record_id' => $record->getKey(),
                    'workflow_key' => $workflow['workflow_key'],
                    'encoded_by' => $user->getKey(),
                    'idempotency_key' => $request->string('idempotency_key')->toString(),
                    'original_milestones' => $milestones === [] ? null : [
                        'provenance' => 'manually_recorded_original_dates',
                        'milestones' => $milestones,
                    ],
                    'encoded_at' => CarbonImmutable::now(BusinessCalendarService::TIMEZONE),
                ]);

                return $record;
            });
        } catch (QueryException $exception) {
            foreach ($storedPaths as $path) $this->attachments->delete($path);

            // A concurrent retry can win the unique idempotency key after our
            // initial lookup. Resolve that retry to the one saved record.
            $retry = HistoricalReportOrigin::query()
                ->where('idempotency_key', $request->string('idempotency_key')->toString())
                ->first();
            if ($retry) {
                abort_unless($retry->source === $workflow['source'] && $retry->workflow_key === $workflow['workflow_key'], 409);
                abort_unless($this->authorizedForExisting($user, (string) $retry->source, (int) $retry->source_record_id), 403);
                return ['source' => $retry->source, 'id' => (int) $retry->source_record_id, 'label' => (string) $workflow['label'], 'idempotent' => true];
            }

            // ENGP's source table has a same-office/period uniqueness rule.
            // A historical entry must never turn that into an update/upsert.
            if ($workflow['source'] === 'engp') {
                $duplicatePeriod = EngpReportSubmission::query()
                    ->where('workflow_key', $attributes['workflow_key'] ?? null)
                    ->where('office', $attributes['office'] ?? null)
                    ->where('reporting_year', $attributes['reporting_year'] ?? null)
                    ->where('period_key', $attributes['period_key'] ?? null)
                    ->exists();
                if ($duplicatePeriod) {
                    throw ValidationException::withMessages([
                        'period_key' => 'A report already exists for this CENRO and reporting period. The existing record was left unchanged.',
                    ]);
                }
            }

            throw $exception;
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) $this->attachments->delete($path);
            throw $exception;
        }

        return ['source' => $workflow['source'], 'id' => (int) $record->getKey(), 'label' => (string) $workflow['label'], 'idempotent' => false];
    }

    /** @param array<string,mixed> $workflow
     *  @return array{0:array<string,mixed>,1:class-string,2:?string,3:array<string,string>,4:array<string,string>} */
    private function validatedData(Request $request, array $workflow, User $user): array
    {
        $kind = $workflow['kind'];
        $attachmentRule = ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:102400'];
        $base = [
            'original_date_report_released_cenro' => ['nullable', 'date_format:Y-m-d'],
            'original_date_received_penro' => ['nullable', 'date_format:Y-m-d'],
            'original_date_endorsed_regional' => ['nullable', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'uuid'],
        ];
        $attrs = [];
        $model = '';
        $files = [];
        $activityDates = [];

        switch ($kind) {
            case 'conservation':
                $isMeeting = app(PambComplianceCalculator::class)->isMeeting($workflow['workflow_key']);
                $activityOptions = $workflow['activities'];
                $documentOptions = array_values($workflow['activity_documents'][$request->input('activity_name')] ?? $workflow['documents']);
                $periodRules = $workflow['periods'] === [] ? ['nullable', 'string'] : ['required', Rule::in($workflow['periods'])];
                $rules = [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'activity_name' => ['required', Rule::in($activityOptions)],
                    'document_type' => ['nullable', Rule::in($documentOptions)],
                    'reporting_period' => $periodRules,
                    'date_conducted' => $isMeeting ? ['required', 'date_format:Y-m-d'] : ['nullable', 'date_format:Y-m-d'],
                    'date_accomplished' => $isMeeting ? ['nullable', 'date_format:Y-m-d'] : ['required', 'date_format:Y-m-d'],
                    'mov' => [$attachmentRule],
                    'remarks' => ['nullable', 'string'],
                ];
                if ($workflow['date_ranges']) {
                    $rules['date_conducted_ranges'] = ['required', 'array', 'min:1'];
                    $rules['date_conducted_ranges.*'] = ['required', 'array'];
                    $rules['date_conducted_ranges.*.from'] = ['required', 'date_format:Y-m-d'];
                    $rules['date_conducted_ranges.*.to'] = ['nullable', 'date_format:Y-m-d'];
                }
                $data = Validator::make($request->all(), $rules)->validate();
                $attrs = $data;
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $attrs['workflow_key'] = $workflow['workflow_key'];
                if ($workflow['date_ranges']) $attrs = $this->dateRanges->applyToPayload($attrs, $request->input('date_conducted_ranges'));
                $model = ConservationReportSubmission::class;
                $files = ['mov' => 'conservation-report'];
                $activityDates = $workflow['date_ranges'] ? ['date_conducted_ranges', 'date_accomplished'] : ['date_conducted', 'date_accomplished'];
                break;

            case 'engp':
                $year = (int) $request->input('reporting_year', now(BusinessCalendarService::TIMEZONE)->year);
                $periods = $this->engp->periods($workflow['workflow_key'], $year);
                $data = Validator::make($request->all(), [
                    ...$base,
                    'office' => ['required', Rule::in($workflow['offices'])],
                    'reporting_year' => ['required', 'integer', 'between:2000,2100'],
                    'period_key' => ['required', Rule::in(array_column($periods, 'key'))],
                    'section_name' => ['nullable', 'string', 'max:255'],
                    'mov' => [['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240']],
                    'mov_external_url' => ['prohibited'],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                abort_unless($this->organization->canUseDevelopmentOffice($user, $data['office']), 403);
                $period = $this->engp->period($workflow['workflow_key'], (int) $data['reporting_year'], $data['period_key']);
                abort_unless($period, 422);
                $data['period_label'] = $period['label'];
                $data['deadline_submission'] = $this->engp->deadline($workflow['workflow_key'], (int) $data['reporting_year'], $data['period_key']);
                $data['workflow_key'] = $workflow['workflow_key'];
                $data['activity_name'] = $workflow['activity'];
                $data['document_type'] = $workflow['document_type'];
                $attrs = $data;
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $model = EngpReportSubmission::class;
                $files = ['mov' => 'engp-report'];
                break;

            case 'pa':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'activity_name' => ['required', 'string', 'max:255'],
                    'document_type' => ['nullable', Rule::in(['Final Report', 'Progress Report'])],
                    'semester' => ['required', Rule::in(['1st Semester', '2nd Semester'])],
                    'date_conducted_ranges' => ['required', 'array', 'min:1'],
                    'date_conducted_ranges.*' => ['required', 'array'],
                    'date_conducted_ranges.*.from' => ['required', 'date_format:Y-m-d'],
                    'date_conducted_ranges.*.to' => ['nullable', 'date_format:Y-m-d'],
                    'date_accomplished' => ['required', 'date_format:Y-m-d'],
                    'mov' => [$attachmentRule],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $this->actualDates->assertDateConductedRanges($request->input('date_conducted_ranges'));
                $this->actualDates->assertNotFuture($data['date_accomplished'], 'date_accomplished', 'Date Accomplished');
                $attrs = $this->dateRanges->applyToPayload($data, $request->input('date_conducted_ranges'));
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $model = $workflow['source'] === 'bms' ? BmsReportSubmission::class : ($workflow['source'] === 'bams' ? BamsReportSubmission::class : ImeaReportSubmission::class);
                $files = ['mov' => $workflow['source'].'-report'];
                $activityDates = ['date_conducted_ranges', 'date_accomplished'];
                break;

            case 'maintenance':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'activity_name' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', Rule::in(['Final Report', 'Progress Report'])],
                    'quarter' => ['required', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
                    'date_conducted' => ['required', 'date_format:Y-m-d'],
                    'date_accomplished' => ['required', 'date_format:Y-m-d'],
                    'mov' => [$attachmentRule],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $attrs = $data;
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $model = ImeaFacilityMaintenanceReport::class;
                $files = ['mov' => 'imea-maintenance'];
                $activityDates = ['date_conducted', 'date_accomplished'];
                break;

            case 'aws':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'station_name' => ['nullable', 'string', 'max:255'],
                    'location' => ['nullable', 'string', 'max:255'],
                    'reporting_year' => ['required', 'integer', 'between:2000,2100'],
                    'quarter' => ['required', 'integer', 'between:1,4'],
                    'report_period_type' => ['required', Rule::in(['Monthly', 'Quarterly', 'Semestral'])],
                    'reporting_period' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', Rule::in(['Final Report', 'Progress Report', 'Final Report (1-15)', 'Final Report (16-30)', 'Final Report (16-28)', 'First-half month report', 'Second-half month report'])],
                    'monitoring_period_start' => ['required', 'date_format:Y-m-d'],
                    'monitoring_period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:monitoring_period_start'],
                    'date_conducted' => ['nullable', 'date_format:Y-m-d'],
                    'date_accomplished' => ['nullable', 'date_format:Y-m-d'],
                    'report_file' => [['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240']],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $start = CarbonImmutable::parse($data['monitoring_period_start'], BusinessCalendarService::TIMEZONE);
                $end = CarbonImmutable::parse($data['monitoring_period_end'], BusinessCalendarService::TIMEZONE);
                abort_unless($start->year === (int) $data['reporting_year'] && $end->year === (int) $data['reporting_year'], 422, 'The monitoring period must belong to the reporting year.');
                abort_unless((int) ceil($start->month / 3) === (int) $data['quarter'] && (int) ceil($end->month / 3) === (int) $data['quarter'], 422, 'The monitoring period must fall within the selected quarter.');
                $attrs = $data;
                unset($attrs['report_file'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $attrs['activity_name'] = filled($attrs['activity_name'] ?? null) ? $attrs['activity_name'] : 'Monitoring and Maintenance of AWS';
                $attrs['status'] = 'Approve';
                $attrs['timestamps'] = null;
                $model = Aws::class;
                $files = ['report_file' => 'aws'];
                $activityDates = ['date_conducted', 'date_accomplished'];
                break;

            case 'ipaf-management':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', Rule::in(['Final Report', 'Progress Report'])],
                    'date_conducted' => ['required', 'date_format:Y-m-d'],
                    'date_accomplished' => ['required', 'date_format:Y-m-d'],
                    'mov' => [$attachmentRule],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $attrs = $data;
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $attrs['activity_name'] = 'Management of Integrated Area Protected Area Fund (IPAF)';
                $model = IpafManagementReport::class;
                $files = ['mov' => 'ipaf-management'];
                $activityDates = ['date_conducted', 'date_accomplished'];
                break;

            case 'revenue':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'target_office' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', Rule::in(['Final Report', 'Progress Report'])],
                    'reporting_month' => ['required', 'integer', 'between:1,12'],
                    'reporting_year' => ['required', 'integer', 'between:2000,2100'],
                    'total_collected' => ['required', 'decimal:0,2', 'min:0'],
                    'deadline_submission' => ['required', 'date_format:Y-m-d'],
                    'mov' => [$attachmentRule],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $attrs = $data;
                unset($attrs['mov'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $attrs['activity_name'] = 'Revenue Collection';
                $model = IpafRevenueCollection::class;
                $files = ['mov' => 'ipaf-revenue'];
                break;

            case 'management-plans':
                $data = Validator::make($request->all(), [
                    ...$base,
                    'protected_area_id' => ['required', 'integer', 'exists:protected_areas,id'],
                    'management_plan_type_id' => ['required', 'integer', Rule::exists('management_plan_types', 'id')->where('is_active', true)],
                    'target_office' => ['required', 'string', 'max:255'],
                    'activity_name' => ['required', 'string', 'max:255'],
                    'document_type' => ['required', Rule::in(['Final Report', 'Progress Report'])],
                    'semester' => ['required', Rule::in(['1st Semester', '2nd Semester'])],
                    'date_conducted' => ['required', 'date_format:Y-m-d'],
                    'date_accomplished' => ['required', 'date_format:Y-m-d'],
                    'attachments' => ['required', 'array', 'min:1'],
                    'attachments.*' => ['required', 'file', 'mimes:pdf,docx,zip,jpeg,jpg,png', 'max:20480'],
                    'remarks' => ['nullable', 'string'],
                ])->validate();
                $type = ManagementPlanType::query()->where('is_active', true)->findOrFail($data['management_plan_type_id']);
                $attrs = $data;
                unset($attrs['attachments'], $attrs['idempotency_key'], $attrs['original_date_report_released_cenro'], $attrs['original_date_received_penro'], $attrs['original_date_endorsed_regional']);
                $attrs['plan_type'] = $type->name;
                $attrs['status'] = 'Submitted';
                $model = ManagementPlan::class;
                $files = ['attachments' => 'management-plan'];
                $activityDates = ['date_conducted', 'date_accomplished'];
                break;

            default:
                abort(404);
        }

        foreach ($activityDates as $field) {
            if ($field === 'date_conducted_ranges') {
                $this->actualDates->assertDateConductedRanges($request->input('date_conducted_ranges'));
            } elseif (filled($request->input($field))) {
                $this->actualDates->assertNotFuture($request->input($field), $field, str_replace('_', ' ', ucfirst($field)));
            }
        }
        foreach (['original_date_report_released_cenro', 'original_date_received_penro', 'original_date_endorsed_regional'] as $field) {
            if (filled($request->input($field))) $this->actualDates->assertNotFuture($request->input($field), $field, str_replace('_', ' ', $field));
        }

        $released = $request->input('original_date_report_released_cenro');
        $received = $request->input('original_date_received_penro');
        $endorsed = $request->input('original_date_endorsed_regional');
        if ($released && $received && $released > $received) {
            throw ValidationException::withMessages(['original_date_received_penro' => 'The original PENRO receipt date cannot be earlier than the CENRO release date.']);
        }
        if ($received && $endorsed && $endorsed < $received) {
            throw ValidationException::withMessages(['original_date_endorsed_regional' => 'The regional endorsement date cannot be earlier than the original PENRO receipt date.']);
        }
        if ($released && $endorsed && $endorsed < $released) {
            throw ValidationException::withMessages(['original_date_endorsed_regional' => 'The regional endorsement date cannot be earlier than the CENRO release date.']);
        }

        $milestones = [];
        foreach (['original_date_report_released_cenro', 'original_date_received_penro', 'original_date_endorsed_regional'] as $field) {
            if (filled($request->input($field))) $milestones[substr($field, strlen('original_'))] = $request->input($field);
        }

        return [$attrs, $model, $attachmentSource = null, $files, $milestones];
    }

    /** @param array<string,mixed> $workflow */
    private function authorizeWorkflow(User $user, array $workflow, Request $request): void
    {
        $ability = match ($workflow['source']) {
            'conservation', 'engp', 'ipaf-management', 'revenue' => 'technical-reports.create',
            'bms' => 'bms.create',
            'bams' => 'bams.create',
            'imea', 'imea-maintenance' => 'imea.create',
            'aws' => 'aws.create',
            'management-plans' => 'management-plans.create',
            default => null,
        };
        abort_unless($ability && $user->can($ability), 403);

        if ($workflow['source'] === 'engp') {
            abort_unless($this->organization->canAccessUnit($user, OrganizationalAccessService::DEVELOPMENT), 403);
            return;
        }

        $areaId = $request->integer('protected_area_id');
        $this->organization->assertCanAccessProtectedArea($user, $areaId);

        if ($this->organization->effectiveCategory($user) === OrganizationalAccessService::CENRO_FOCAL) {
            $preparationSource = match ($workflow['source']) {
                'ipaf-management' => 'ipaf-management',
                'revenue' => 'ipaf-revenue',
                default => $workflow['source'],
            };
            abort_unless($this->organization->canPrepareProtectedAreaSource($user, $preparationSource), 403);
        }
    }

    private function authorizedForExisting(User $user, string $source, int $recordId): bool
    {
        $model = match ($source) {
            'conservation' => ConservationReportSubmission::class,
            'engp' => EngpReportSubmission::class,
            'bms' => BmsReportSubmission::class,
            'bams' => BamsReportSubmission::class,
            'imea' => ImeaReportSubmission::class,
            'imea-maintenance' => ImeaFacilityMaintenanceReport::class,
            'aws' => Aws::class,
            'ipaf-management' => IpafManagementReport::class,
            'revenue' => IpafRevenueCollection::class,
            'management-plans' => ManagementPlan::class,
            default => null,
        };
        if (! $model) return false;
        $record = $model::query()->find($recordId);
        if (! $record || ! (bool) $record->getAttribute('is_historical')) return false;
        if ($source === 'engp') return $this->organization->canViewDevelopmentRecord($user, $record);
        return $this->organization->scopeProtectedAreaQuery($model::query(), $user)->whereKey($recordId)->exists();
    }
}
