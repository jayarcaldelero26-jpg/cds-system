<?php

namespace App\Http\Controllers;

use App\Domain\Modules\ProgramArea;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ModuleDefinition;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use App\Services\Reports\ReportRequirementRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\AuditLogService;
use Illuminate\Validation\ValidationException;

class ModuleDefinitionController extends Controller
{
    private const CONSERVATION_AREA = 'protected_area_management_and_development';
    private const DEVELOPMENT_AREA = 'engp';

    public function index(Request $request): Response
    {
        $definitions = ModuleDefinition::query()
            ->notRetired()
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', '%'.$request->string('search')->toString().'%')->orWhere('code', 'like', '%'.$request->string('search')->toString().'%')))
            ->when($request->filled('program_area'), fn ($query) => $query->where('program_area', $request->string('program_area')->toString()))
            ->when($request->filled('module_type'), fn ($query) => $query->where('module_type', $request->string('module_type')->toString()))
            ->when(in_array($request->string('status')->toString(), ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->string('status')->toString() === 'active'))
            ->orderByRaw('display_order IS NULL')->orderBy('display_order')->orderBy('name')->get()
            ->map(fn (ModuleDefinition $definition) => $this->payload($definition))->values();

        return Inertia::render('Admin/Settings/ModuleManagement', [
            'definitions' => $definitions,
            'filters' => $request->only(['search', 'program_area', 'module_type', 'status']),
            'programAreas' => ProgramArea::options(),
            'frequencies' => ModuleDefinition::FREQUENCIES,
            'developmentOffices' => app(EngpReportWorkflowRegistry::class)->availableOffices(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['implementation_type'] = ModuleDefinition::IMPLEMENTATION_GENERIC;
        $data['code'] = ModuleDefinition::codeFromName($data['name']);
        $domain = $data['unit'] === 'development' ? ReportRequirementRegistry::ENGP : ReportRequirementRegistry::PA;
        $data['requirement_domain'] = $domain;
        $data['requirement_key'] = $data['code'];
        $data['requirement_metadata'] = $this->workflowMetadata($data, $domain);
        if (ModuleDefinition::isRetiredCode((string) $data['code'])) {
            return back()->withErrors(['name' => 'This module is retired and cannot be created.'])->withInput();
        }
        if (ModuleDefinition::query()->where('code', $data['code'])->exists()) {
            return back()->withErrors(['name' => 'A module with this generated code already exists.'])->withInput();
        }
        $data = Arr::except($data, ['unit', 'activity_name', 'document_types', 'period_labels', 'reminder_offices']);
        $definition = ModuleDefinition::query()->create($data);
        app(ReportRequirementRegistry::class)->forgetCache();
        app(AuditLogService::class)->record('module_management', 'Module Created', ModuleDefinition::class, $definition->id, $definition->name, 'Created module definition.', ['name' => $definition->name, 'code' => $definition->code, 'program_area' => $definition->program_area->value]);
        return back()->with('success', 'Module definition created.');
    }

    public function update(Request $request, ModuleDefinition $moduleDefinition): RedirectResponse
    {
        abort_unless(! $moduleDefinition->isRetired(), 404);
        $data = $this->validated($request, $moduleDefinition);
        $currentArea = $moduleDefinition->program_area->value;
        $currentDomain = filled($moduleDefinition->requirement_domain)
            ? (string) $moduleDefinition->requirement_domain
            : ($currentArea === ProgramArea::ENGP->value ? 'engp' : 'pa');
        $targetDomain = $data['unit'] === 'development' ? ReportRequirementRegistry::ENGP : ReportRequirementRegistry::PA;
        if ($targetDomain !== $currentDomain) {
            throw ValidationException::withMessages(['unit' => 'A module cannot be moved across its registered report domain.']);
        }
        if ($data['program_area'] !== $currentArea && ! in_array($data['program_area'], [self::CONSERVATION_AREA, self::DEVELOPMENT_AREA], true)) {
            throw ValidationException::withMessages(['program_area' => 'Only the supported program area for the selected unit can be chosen.']);
        }
        if ($moduleDefinition->implementation_type === ModuleDefinition::IMPLEMENTATION_GENERIC) {
            $metadata = is_array($moduleDefinition->requirement_metadata) ? $moduleDefinition->requirement_metadata : [];
            if ($this->hasSourceRecords($moduleDefinition, $currentDomain)) {
                $changedPolicyFields = $this->changedPolicyFields($moduleDefinition, $data, $metadata);
                if ($changedPolicyFields !== []) {
                    throw ValidationException::withMessages(array_fill_keys(
                        $changedPolicyFields,
                        'Workflow settings are locked after reports exist so their saved deadlines and history remain stable.'
                    ));
                }
            }
            $data['requirement_domain'] = $currentDomain;
            $data['requirement_key'] = filled($moduleDefinition->requirement_key) ? $moduleDefinition->requirement_key : $moduleDefinition->code;
            if (($metadata['configured_generic_flow'] ?? false) === true) {
                unset($metadata['custom_deadline_after_period'], $metadata['custom_deadline_from_actual_date']);
                $metadata = [...$metadata, ...$this->workflowMetadata($data, $currentDomain)];
                if ($data['reporting_frequency'] !== 'custom') unset($metadata['periods'], $metadata['canonical_frequency']);
            }
            $data['requirement_metadata'] = $metadata;
        } else {
            $data = Arr::except($data, ['activity_name', 'document_types', 'period_labels']);
        }
        $before = $moduleDefinition->only(array_keys($data));
        $data = Arr::except($data, ['unit', 'activity_name', 'document_types', 'period_labels', 'reminder_offices']);
        $moduleDefinition->update($data);
        app(ReportRequirementRegistry::class)->forgetCache();
        app(AuditLogService::class)->record('module_management', 'Module Configuration Updated', ModuleDefinition::class, $moduleDefinition->id, $moduleDefinition->name, 'Updated module definition configuration.', ['before' => $before, 'after' => $moduleDefinition->fresh()->only(array_keys($data))]);
        return back()->with('success', 'Module definition updated. The module code remains stable.');
    }

    public function toggle(ModuleDefinition $moduleDefinition): RedirectResponse
    {
        abort_unless(! $moduleDefinition->isRetired(), 404);
        $old = $moduleDefinition->is_active;
        $moduleDefinition->update(['is_active' => ! $old]);
        app(ReportRequirementRegistry::class)->forgetCache();
        app(AuditLogService::class)->record('module_management', $moduleDefinition->is_active ? 'Module Activated' : 'Module Deactivated', ModuleDefinition::class, $moduleDefinition->id, $moduleDefinition->name, 'Changed module active status.', ['old' => $old, 'new' => $moduleDefinition->is_active]);
        return back()->with('success', $moduleDefinition->is_active ? 'Module definition activated.' : 'Module definition deactivated. Historical records remain available.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?ModuleDefinition $editing = null): array
    {
        $currentArea = $editing?->program_area?->value;
        $currentDomain = $editing?->requirement_domain ?: ($currentArea === ProgramArea::ENGP->value ? ReportRequirementRegistry::ENGP : ReportRequirementRegistry::PA);
        $allowedUnits = $editing ? [$currentDomain === ReportRequirementRegistry::ENGP ? 'development' : 'conservation'] : ['conservation', 'development'];
        $unit = (string) $request->input('unit', $allowedUnits[0]);
        $areas = $unit === 'development' ? [self::DEVELOPMENT_AREA] : [self::CONSERVATION_AREA];
        if ($editing && $currentArea && ! in_array($currentArea, $areas, true)) $areas[] = $currentArea;
        $generic = ! $editing || $editing->implementation_type === ModuleDefinition::IMPLEMENTATION_GENERIC;
        $configuredFlow = $generic && (! $editing || (($editing->requirement_metadata['configured_generic_flow'] ?? false) === true));

        $data = $request->validate([
            'unit' => ['required', Rule::in($allowedUnits)],
            'name' => ['required', 'string', 'max:255'],
            'program_area' => ['required', Rule::in($areas)],
            'module_type' => ['required', Rule::in([ModuleDefinition::TYPE_REGULAR_TARGET, ModuleDefinition::TYPE_PLAN])],
            'reporting_frequency' => [$generic ? 'required' : 'nullable', Rule::in(ModuleDefinition::FREQUENCIES)],
            'activity_name' => [$configuredFlow ? 'required' : 'nullable', 'string', 'max:255'],
            'document_types' => [$configuredFlow ? 'required' : 'nullable', 'string', 'max:2000'],
            'period_labels' => ['nullable', 'string', 'max:4000', Rule::requiredIf($configuredFlow && $request->input('reporting_frequency') === 'custom')],
            'reminder_offices' => ['nullable', 'array'],
            'reminder_offices.*' => ['string', Rule::in($unit === 'development' ? app(EngpReportWorkflowRegistry::class)->availableOffices() : [])],
            'deadline_mode' => ['required', Rule::in([ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS, ModuleDefinition::DEADLINE_CUSTOM, ModuleDefinition::DEADLINE_NONE])],
            'default_deadline_days' => ['nullable', 'integer', 'min:1', 'max:366', Rule::requiredIf($generic && in_array($request->input('deadline_mode'), [ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS, ModuleDefinition::DEADLINE_CUSTOM], true))],
            'allow_deadline_override' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (($data['unit'] === 'development') !== ($data['program_area'] === self::DEVELOPMENT_AREA)) {
            throw ValidationException::withMessages(['program_area' => 'Choose the program area that belongs to the selected unit.']);
        }
        $data['activity_name'] = trim((string) ($data['activity_name'] ?? ''));
        $data['document_types'] = collect(preg_split('/[,\r\n]+/', (string) ($data['document_types'] ?? '')) ?: [])
            ->map(fn (string $item): string => trim($item))->filter()->unique()->take(12)->values()->all();
        $data['reminder_offices'] = $unit === 'development' && $generic
            ? collect($data['reminder_offices'] ?? [])->unique()->values()->all()
            : [];
        if ($configuredFlow && $data['document_types'] === []) throw ValidationException::withMessages(['document_types' => 'Enter at least one required document type.']);
        $data['period_labels'] = $this->customPeriods((string) ($data['period_labels'] ?? ''), $configuredFlow && ($data['reporting_frequency'] ?? null) === 'custom');
        if ($data['deadline_mode'] === ModuleDefinition::DEADLINE_NONE) $data['default_deadline_days'] = null;
        $data['allow_deadline_override'] = $data['deadline_mode'] === ModuleDefinition::DEADLINE_CUSTOM && (bool) ($data['allow_deadline_override'] ?? false);
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function workflowMetadata(array $data, string $domain): array
    {
        $activity = trim((string) $data['activity_name']);
        $documents = array_values(array_unique($data['document_types']));
        $metadata = [
            'configured_generic_flow' => true,
            'activities' => [$activity], 'default_activity' => $activity, 'activity' => $activity,
            'documents' => $documents, 'document' => $documents[0],
            'activity_documents' => [$activity => $documents],
            'period_field' => $domain === ReportRequirementRegistry::PA ? 'reporting_period' : 'period_key',
            'period_label' => 'Reporting Period',
            'reminder_offices' => $data['reminder_offices'] ?? [],
        ];
        if ($data['reporting_frequency'] === 'custom') {
            $metadata['periods'] = $data['period_labels'];
            $metadata['canonical_frequency'] = 'custom';
        }
        if ($data['deadline_mode'] === ModuleDefinition::DEADLINE_CUSTOM) {
            $metadata['custom_deadline_after_period'] = true;
            $metadata['custom_deadline_from_actual_date'] = true;
        }

        return $metadata;
    }

    /** @return list<array{key:string,label:string,start_date:string,end_date:string}> */
    private function customPeriods(string $value, bool $required): array
    {
        if (trim($value) === '') {
            if ($required) throw ValidationException::withMessages(['period_labels' => 'Add periods as Label | YYYY-MM-DD start | YYYY-MM-DD end, one per line.']);
            return [];
        }
        $periods = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3 || $parts[0] === '' || ! $this->isIsoDate($parts[1]) || ! $this->isIsoDate($parts[2]) || $parts[1] > $parts[2]) {
                throw ValidationException::withMessages(['period_labels' => 'Use Label | YYYY-MM-DD start | YYYY-MM-DD end for each custom period.']);
            }
            $key = \Illuminate\Support\Str::slug($parts[0], '_');
            if ($key === '' || isset($periods[$key])) throw ValidationException::withMessages(['period_labels' => 'Custom period labels must be unique.']);
            $periods[$key] = ['key' => $key, 'label' => $parts[0], 'start_date' => $parts[1], 'end_date' => $parts[2]];
        }

        return array_values($periods);
    }

    private function isIsoDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /** @return list<string> */
    private function changedPolicyFields(ModuleDefinition $module, array $data, array $metadata): array
    {
        $changed = [];
        foreach (['program_area', 'module_type', 'reporting_frequency', 'deadline_mode', 'default_deadline_days', 'allow_deadline_override'] as $field) {
            $current = $module->getAttribute($field);
            $next = $data[$field] ?? null;
            if ($field === 'default_deadline_days') {
                $current = $current === null ? null : (int) $current;
                $next = $next === null ? null : (int) $next;
            } elseif ($field === 'allow_deadline_override') {
                $current = (bool) $current;
                $next = (bool) $next;
            } elseif ($current instanceof \BackedEnum) {
                $current = $current->value;
            }
            if ($current !== $next) $changed[] = $field;
        }

        if (($metadata['configured_generic_flow'] ?? false) === true) {
            $activity = $metadata['default_activity'] ?? $metadata['activity'] ?? ($metadata['activities'][0] ?? '');
            $documents = array_values($metadata['documents'] ?? []);
            if ($activity !== ($data['activity_name'] ?? '')) $changed[] = 'activity_name';
            if ($documents !== array_values($data['document_types'] ?? [])) $changed[] = 'document_types';
            if (array_values($metadata['periods'] ?? []) !== array_values($data['period_labels'] ?? [])) $changed[] = 'period_labels';
            if ($this->sortedValues($metadata['reminder_offices'] ?? []) !== $this->sortedValues($data['reminder_offices'] ?? [])) $changed[] = 'reminder_offices';
        }

        return array_values(array_unique($changed));
    }

    private function hasSourceRecords(ModuleDefinition $module, string $domain): bool
    {
        $keys = array_values(array_unique(array_filter([
            (string) ($module->requirement_key ?: ''),
            (string) ($module->code ?: ''),
        ])));
        if ($keys === []) return false;

        return $domain === ReportRequirementRegistry::ENGP
            ? EngpReportSubmission::withTrashed()->whereIn('workflow_key', $keys)->exists()
            : ConservationReportSubmission::query()->whereIn('workflow_key', $keys)->exists();
    }

    /** @param array<int|string,mixed> $values @return list<string> */
    private function sortedValues(array $values): array
    {
        $values = array_values(array_map('strval', $values));
        sort($values);

        return $values;
    }

    /** @return array<string,mixed> */
    private function payload(ModuleDefinition $definition): array
    {
        $domain = $definition->requirement_domain ?: ($definition->program_area->value === self::DEVELOPMENT_AREA ? ReportRequirementRegistry::ENGP : ReportRequirementRegistry::PA);
        $key = $definition->requirement_key ?: (string) $definition->code;
        $metadata = is_array($definition->requirement_metadata) ? $definition->requirement_metadata : [];
        $workflow = $domain === ReportRequirementRegistry::ENGP
            ? app(EngpReportWorkflowRegistry::class)->find($key)
            : app(ConservationReportWorkflowRegistry::class)->find($key);
        $activities = $metadata['activities'] ?? ($workflow['activities'] ?? array_values(array_filter([$metadata['activity'] ?? $workflow['activity'] ?? $workflow['default_activity'] ?? null])));
        $documents = $metadata['documents'] ?? ($workflow['documents'] ?? array_values(array_filter([$metadata['document'] ?? $workflow['document'] ?? null])));

        return [
            'id' => $definition->id, 'name' => $definition->name, 'code' => $definition->code,
            'program_area' => $definition->program_area->value, 'program_area_label' => $definition->program_area->label(),
            'implementation_type' => $definition->implementation_type, 'module_type' => $definition->module_type,
            'requirement_domain' => $definition->requirement_domain,
            'requirement_key' => $key,
            'unit' => $domain === ReportRequirementRegistry::ENGP ? 'development' : 'conservation',
            'reporting_frequency' => $definition->reporting_frequency,
            'activity_name' => $activities[0] ?? '', 'document_types' => implode(', ', $documents),
            'reminder_offices' => $metadata['reminder_offices'] ?? [],
            'period_labels' => collect($metadata['periods'] ?? [])->map(fn ($period) => is_array($period) ? implode(' | ', [$period['label'] ?? '', $period['start_date'] ?? '', $period['end_date'] ?? '']) : (string) $period)->implode("\n"),
            'configured_generic_flow' => (bool) ($metadata['configured_generic_flow'] ?? false),
            'deadline_mode' => $definition->deadline_mode, 'default_deadline_days' => $definition->default_deadline_days,
            'allow_deadline_override' => $definition->allow_deadline_override, 'description' => $definition->description,
            'is_active' => $definition->is_active, 'existing_source_key' => $definition->existing_source_key,
        ];
    }
}
