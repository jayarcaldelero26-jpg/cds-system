<?php

namespace App\Services\Pamo;

use App\Http\Controllers\AwsController;
use App\Http\Controllers\BmsReportSubmissionController;
use App\Http\Controllers\BamsReportSubmissionController;
use App\Http\Controllers\ImeaReportSubmissionController;
use App\Http\Controllers\ImeaFacilityMaintenanceReportController;
use App\Http\Controllers\IpafController;
use App\Models\PamoPambDraft;
use App\Models\User;
use App\Services\Attachments\CurrentDocumentReplacementService;
use App\Services\Attachments\ProtectedAttachmentService;
use App\Services\Attachments\ReportDocumentAdapterResolver;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\DateConductedRangeService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\SubmissionTracking\RoutingPositionSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Native report contracts used only by the PAMO draft adapter. */
final class PamoNativeDraftAdapter
{
    private const DISK = 'local';

    public function __construct(private readonly PamoNativeSourceRegistry $sources) {}

    /** @return array<string,mixed> */
    public function partialPayload(Request $request, string $workflow): array
    {
        $source = $this->requiredSource($workflow);
        $protectedFields = [
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'], 'target_office' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'conservation_report_submission_id' => ['prohibited'], 'bms_report_submission_id' => ['prohibited'],
            'bams_report_submission_id' => ['prohibited'], 'imea_report_submission_id' => ['prohibited'],
            'imea_facility_maintenance_report_id' => ['prohibited'], 'aws_report_id' => ['prohibited'],
            'ipaf_management_report_id' => ['prohibited'], 'ipaf_revenue_collection_id' => ['prohibited'],
            'source_family' => ['prohibited'], 'report_file' => ['prohibited'], 'workflow_key' => ['prohibited'],
            'started_at' => ['prohibited'], 'originator_user_id' => ['prohibited'],
            'forwarded_at' => ['prohibited'], 'draft_uuid' => ['prohibited'], 'id' => ['prohibited'],
            'office_designated' => ['prohibited'], 'pamo_office_name' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
        ];
        $protectedFields['status'] = ['prohibited'];
        $request->validate($protectedFields);
        $nativeFile = $source['file_field'];
        $requestFile = $request->file('mov');
        $data = $request->except(['mov', 'protected_area_id', 'pamo_office_id', 'target_office', 'office_designated', 'workflow_key', 'source', 'source_key', 'source_id', 'created_by', 'updated_by', 'started_at', 'originator_user_id', 'draft_uuid', 'conservation_report_submission_id']);
        $this->normalize($workflow, $data, $requestFile);
        $rules = $this->rules($workflow, false);
        if ($workflow === 'automated_weather_station') $rules['remarks'] = ['sometimes', 'nullable', 'string'];
        unset($rules['protected_area_id'], $rules['target_office']);
        foreach ($rules as $field => $fieldRules) {
            if (in_array($workflow, ['bms', 'bams', 'imea'], true) && $field === 'date_conducted_ranges') {
                $rules[$field] = ['sometimes', ...array_values(array_filter($fieldRules, fn ($rule): bool => $rule !== 'required' && $rule !== 'min:1'))];
                continue;
            }
            if (in_array($workflow, ['bms', 'bams', 'imea'], true)
                && $field === 'date_conducted_ranges.*.from') {
                $rules[$field] = ['nullable', ...array_values(array_filter($fieldRules, fn ($rule): bool => $rule !== 'required'))];
                continue;
            }
            if (in_array('required', $fieldRules, true)) {
                $rules[$field] = array_values(array_filter($fieldRules, fn ($rule): bool => $rule !== 'required'));
                array_unshift($rules[$field], 'sometimes');
            }
        }
        $nativeFiles = $requestFile ? [$nativeFile => $requestFile] : [];
        $validated = Validator::make($data, $rules)->validate();
        if ($nativeFiles !== []) {
            Validator::make($nativeFiles, [$nativeFile => $this->fileRules($workflow, false)])->validate();
        }
        unset($validated['protected_area_id'], $validated['target_office'], $validated[$nativeFile]);
        $this->validatePartialDates($workflow, $validated);
        return $validated;
    }

    /** @param array<string,mixed> $payload @return array{0:\Illuminate\Database\Eloquent\Model,1:string} */
    public function promote(PamoPambDraft $draft, string $workflow, array $facts, User $actor, UploadedFile $file, ?array $currentPayload = null): array
    {
        $source = $this->requiredSource($workflow);
        $sourceColumn = $source['column'];
        if ($draft->conservation_report_submission_id !== null) {
            throw ValidationException::withMessages(['draft' => 'This draft is already linked to a Conservation report.']);
        }
        foreach ($this->sources->all() as $other) {
            if ($draft->getAttribute($other['column']) !== null) {
                if ($other['column'] === $sourceColumn) return [$other['model']::query()->findOrFail($draft->getAttribute($sourceColumn)), ''];
                throw ValidationException::withMessages(['draft' => 'This draft is already linked to a different native report source.']);
            }
        }

        $payload = [...($draft->report_payload ?? []), ...($currentPayload ?? []), 'protected_area_id' => $facts['protected_area_id'], 'target_office' => $facts['target_office']];
        $nativeFile = $source['file_field'];
        $payload[$nativeFile] = $file;
        $this->normalize($workflow, $payload, $file, true);
        $validated = Validator::make($payload, $this->rules($workflow, true))->validate();
        $validated = $this->prepare($workflow, $validated, $payload);
        unset($validated[$nativeFile]);
        if ($workflow === 'automated_weather_station') $validated['timestamps'] = null;
        $validated['created_by'] = $draft->originator_user_id;
        $validated['updated_by'] = $actor->getKey();

        $model = $source['model'];
        $record = null;
        $storedPath = null;
        try {
            $record = $model::query()->create($validated);
            $adapter = app(ReportDocumentAdapterResolver::class)->resolve($source['attachment'], $record, $nativeFile);
            $replacement = app(CurrentDocumentReplacementService::class)->replaceUsingAdapter(
                $record,
                $source['attachment'],
                $nativeFile,
                $file,
                $adapter,
                $nativeFile,
                (int) $actor->getKey(),
                fn ($row) => app(OrganizationalAccessService::class)->assertCanAccessProtectedArea($actor, $row->protected_area_id),
                $payload['remarks'] ?? null,
                $this->extraFileMetadata($workflow, $file),
                'UPLOAD',
            );
            $storedPath = $replacement['path'];
            $sourceKey = $source['source'];
            $positions = app(RoutingPositionSnapshotService::class);
            $position = $positions->resolve($record->fresh(), $sourceKey, false, true, true);
            $origin = [
                'protected_area_id' => $facts['protected_area_id'],
                'pamo_office_id' => $facts['pamo_office_id'],
                'pamo_office_name' => $facts['pamo_office_name'],
                'responsible_cenro_office_id' => $facts['responsible_cenro_office_id'],
                'responsible_cenro_name' => $facts['responsible_cenro_name'],
                'originator_user_id' => $draft->originator_user_id,
                'origin_started_at' => $draft->started_at,
            ];
            $positions->capture($record->fresh(), $sourceKey, $position, $facts['profile'], $actor, $origin);
            // This write stays inside the caller's promotion transaction. It
            // makes the linked native row visible to routing authorization in
            // this transaction; any routing failure rolls the draft and row
            // back together before another request can observe them.
            $draft->update([
                $sourceColumn => $record->getKey(),
                'status' => 'forwarded',
                'forwarded_at' => CarbonImmutable::now(\App\Services\BusinessCalendarService::TIMEZONE),
                'updated_by' => $actor->getKey(),
            ]);
            $action = $facts['profile'] === 'pamo_direct' ? 'forward_from_pamo' : 'forward_from_pamo_to_cenro_focal';
            app(DocumentRoutingTransitionService::class)->transition($record->fresh(), $sourceKey, $action, (int) $actor->getKey(), null, null, null, true);
            return [$record->fresh(), $storedPath];
        } catch (Throwable $exception) {
            // The caller owns the promotion transaction. The draft may already
            // reference this native row inside that transaction, so deleting
            // the row here can violate the restrictive link FK and mask the
            // original routing failure. Let rollback remove the row atomically.
            if ($storedPath) Storage::disk(self::DISK)->delete($storedPath);
            throw $exception;
        }
    }

    public function updateCorrection(Request $request, string $workflow, int $recordId, array $facts, User $actor): Model
    {
        return DB::transaction(function () use ($request, $workflow, $recordId, $facts, $actor): Model {
        $source = $this->requiredSource($workflow);
        $model = $source['model'];
        $record = $model::query()->findOrFail($recordId);
        abort_unless((int) $record->protected_area_id === (int) $facts['protected_area_id'], 404);
        abort_unless(app(\App\Services\SubmissionTracking\DocumentRoutingAccessService::class)
            ->canView($actor, $record, $source['source']), 403);
        $state = app(DocumentRoutingTransitionService::class)->state($record, $source['source'], actor: $actor);
        abort_unless($state['stage'] === \App\Services\SubmissionTracking\DocumentRoutingProfileRegistry::PAMO_ORIGIN
            && collect($state['actions'])->contains(fn (array $action): bool => in_array($action['key'], ['forward_from_pamo', 'forward_from_pamo_to_cenro_focal'], true)), 403);

        $protectedFields = [
            'protected_area_id' => ['prohibited'], 'pamo_office_id' => ['prohibited'], 'target_office' => ['prohibited'],
            'source' => ['prohibited'], 'source_key' => ['prohibited'], 'source_id' => ['prohibited'],
            'workflow_key' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
            'originator_user_id' => ['prohibited'], 'started_at' => ['prohibited'], 'forwarded_at' => ['prohibited'],
            'id' => ['prohibited'], 'record_id' => ['prohibited'], 'report_file' => ['prohibited'],
            'mov_url' => ['prohibited'], 'protected_area_name' => ['prohibited'],
            'conservation_report_submission_id' => ['prohibited'], 'bms_report_submission_id' => ['prohibited'],
            'bams_report_submission_id' => ['prohibited'], 'imea_report_submission_id' => ['prohibited'],
            'imea_facility_maintenance_report_id' => ['prohibited'], 'aws_report_id' => ['prohibited'],
            'ipaf_management_report_id' => ['prohibited'], 'ipaf_revenue_collection_id' => ['prohibited'],
            'source_updated_at' => ['required', 'date'],
        ];
        if ($workflow !== 'automated_weather_station') $protectedFields['status'] = ['prohibited'];
        $request->validate($protectedFields);
        $expectedUpdatedAt = (string) $request->input('source_updated_at');
        abort_unless($record->updated_at?->toISOString() === CarbonImmutable::parse($expectedUpdatedAt)->toISOString(), 409, 'This report changed in another session. Reload it before editing.');

        $file = $request->file('mov');
        $nativeFile = $source['file_field'];
        $data = $request->except([
            'mov', 'report_file', 'protected_area_id', 'pamo_office_id', 'target_office', 'source', 'source_key', 'source_id',
            'workflow_key', 'created_by', 'updated_by', 'originator_user_id', 'started_at', 'forwarded_at', 'source_updated_at',
            'conservation_report_submission_id', 'bms_report_submission_id', 'bams_report_submission_id', 'imea_report_submission_id',
            'imea_facility_maintenance_report_id', 'aws_report_id', 'ipaf_management_report_id', 'ipaf_revenue_collection_id',
        ]);
        $data['protected_area_id'] = $facts['protected_area_id'];
        $data['target_office'] = $record->target_office;
        if ($file) $data[$nativeFile] = $file;
        $this->normalize($workflow, $data, $file, true);
        $validated = Validator::make($data, $this->rules($workflow, false))->validate();
        $validated = $this->prepare($workflow, $validated, $data);
        unset($validated[$nativeFile]);
        if ($workflow === 'automated_weather_station') $validated['timestamps'] = null;
        if ($workflow !== 'automated_weather_station'
            && ! $file && ! app(\App\Services\Compliance\ComplianceMovService::class)->hasValidSingleFile($record, 'mov_file_path')) {
            throw ValidationException::withMessages(['mov' => \App\Services\Compliance\ComplianceMovService::MESSAGE]);
        }
        if ($workflow === 'automated_weather_station' && ! $file
            && ! app(\App\Services\Compliance\ComplianceMovService::class)->hasValidSingleFile($record, 'report_file_path')) {
            throw ValidationException::withMessages(['report_file' => \App\Services\Compliance\ComplianceMovService::MESSAGE]);
        }
        $validated['updated_by'] = $actor->getKey();
        $locked = $model::query()->lockForUpdate()->findOrFail($record->getKey());
        abort_unless($locked->updated_at?->toISOString() === $expectedUpdatedAt, 409, 'This report changed in another session. Reload it before editing.');
        app(\App\Services\SubmissionTracking\SubmissionTrackingService::class)->assertMutable($locked);
        if ($file) {
            $adapter = app(ReportDocumentAdapterResolver::class)->resolve($source['attachment'], $locked, $nativeFile);
            app(CurrentDocumentReplacementService::class)->replaceUsingAdapter(
                $locked, $source['attachment'], $nativeFile, $file, $adapter, $nativeFile, (int) $actor->getKey(),
                fn ($row) => app(OrganizationalAccessService::class)->assertCanAccessProtectedArea($actor, $row->protected_area_id),
                $data['remarks'] ?? null, [...$validated, ...$this->extraFileMetadata($workflow, $file)], 'REPLACEMENT',
            );
        } else {
            DB::transaction(fn () => $locked->update($validated));
        }

        return $locked->fresh();
        });
    }

    private function requiredSource(string $workflow): array
    {
        $source = $this->sources->find($workflow);
        abort_unless($source && $this->sources->isEligible($workflow), 404);
        $this->sources->assertSchemaReady();
        return $source;
    }

    private function controller(string $workflow): object
    {
        return app(match ($workflow) {
            'bms' => BmsReportSubmissionController::class,
            'bams' => BamsReportSubmissionController::class,
            'imea' => ImeaReportSubmissionController::class,
            'imea_facility_maintenance' => ImeaFacilityMaintenanceReportController::class,
            'automated_weather_station' => AwsController::class,
            'ipaf_management', 'revenue_collection' => IpafController::class,
        });
    }

    private function rules(string $workflow, bool $requireFile): array
    {
        return match ($workflow) {
            'bms' => $this->controller($workflow)->rules(null, $requireFile),
            'bams', 'imea' => $this->controller($workflow)->rules(null, $requireFile),
            'imea_facility_maintenance' => $this->controller($workflow)->rules($requireFile),
            'automated_weather_station' => $this->controller($workflow)->validationRules($requireFile),
            'ipaf_management' => $this->controller($workflow)->managementRules($requireFile),
            'revenue_collection' => $this->controller($workflow)->revenueRules($requireFile),
        };
    }

    private function normalize(string $workflow, array &$data, ?UploadedFile $file, bool $forSubmission = false): void
    {
        if ($workflow !== 'automated_weather_station') return;
        if (filled($data['quarter'] ?? null)) $data['quarter'] = (int) $data['quarter'];
        if (! $forSubmission) return;
        $data['activity_name'] = filled($data['activity_name'] ?? null)
            ? $data['activity_name']
            : 'Monitoring and Maintenance of AWS';
        // Reuse the normalization performed by AwsController::store for its
        // Report Submission form. This runs only server-side at Forward; the
        // draft form contains no station/status controls and cannot override
        // the source action's canonical defaults.
        $request = Request::create('/', 'POST', $data, [], $file ? ['report_file' => $file] : []);
        $this->controller($workflow)->normalizeReportInput($request);
        if (filled($request->input('quarter'))) $request->merge(['quarter' => (int) $request->input('quarter')]);
        $data = $request->all();
    }

    private function prepare(string $workflow, array $validated, array $payload): array
    {
        return match ($workflow) {
            'bms' => $this->prepareBms($validated, $payload),
            'bams', 'imea' => $this->controller($workflow)->prepareDateConductedPayload(Request::create('/', 'POST', $payload), $validated),
            'imea_facility_maintenance' => $this->prepareMaintenance($validated),
            'automated_weather_station' => $this->prepareAws($validated),
            'ipaf_management' => $this->prepareIpafManagement($validated),
            'revenue_collection' => $validated,
        };
    }

    private function prepareBms(array $validated, array $payload): array
    {
        $request = Request::create('/', 'POST', $payload);
        $this->controller('bms')->assertNoFutureActualDates($request, $validated);
        return app(DateConductedRangeService::class)->applyToPayload($validated, $payload['date_conducted_ranges'] ?? null);
    }

    private function prepareMaintenance(array $validated): array
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        $dates->assertNotFuture($validated['date_conducted'] ?? null, 'date_conducted', 'Date Conducted');
        $dates->assertNotFuture($validated['date_accomplished'] ?? null, 'date_accomplished', 'Date Accomplished');
        return $validated;
    }

    private function prepareAws(array $validated): array
    {
        $controller = $this->controller('automated_weather_station');
        $controller->assertNoFutureActualDates($validated);
        $validated = $controller->deriveCanonicalPeriod($validated);
        $controller->validateMonitoringPeriod($validated);
        return $validated;
    }

    private function prepareIpafManagement(array $validated): array
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        $dates->assertNotFuture($validated['date_conducted'] ?? null, 'date_conducted', 'Date Conducted');
        $dates->assertNotFuture($validated['date_accomplished'] ?? null, 'date_accomplished', 'Date Accomplished');
        return $validated;
    }

    private function validatePartialDates(string $workflow, array $validated): void
    {
        $dates = app(\App\Services\ActualActivityDateGuard::class);
        if (in_array($workflow, ['bms', 'bams', 'imea'], true) && isset($validated['date_conducted_ranges'])) {
            $ranges = $validated['date_conducted_ranges'];
            $complete = is_array($ranges) && collect($ranges)->every(fn ($range): bool => is_array($range) && filled($range['from'] ?? null));
            if ($complete) $dates->assertDateConductedRanges($ranges, null, null);
        }
        foreach (['date_conducted', 'date_accomplished'] as $field) {
            if (isset($validated[$field]) && $validated[$field] !== '') $dates->assertNotFuture($validated[$field], $field, $field === 'date_conducted' ? 'Date Conducted' : 'Date Accomplished');
        }
    }

    private function fileRules(string $workflow, bool $required): array
    {
        $rules = $this->rules($workflow, $required);
        $field = $this->sources->find($workflow)['file_field'];
        return $rules[$field] ?? ['nullable', 'file'];
    }

    private function extraFileMetadata(string $workflow, UploadedFile $file): array
    {
        return $workflow === 'imea_facility_maintenance'
            ? ['mov_mime_type' => $file->getMimeType() ?: $file->getClientMimeType(), 'mov_size' => $file->getSize()]
            : [];
    }
}
