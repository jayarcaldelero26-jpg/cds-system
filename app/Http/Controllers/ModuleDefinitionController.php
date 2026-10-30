<?php

namespace App\Http\Controllers;

use App\Domain\Modules\ProgramArea;
use App\Models\ModuleDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\AuditLogService;
use App\Services\Modules\CanonicalModuleDefinitionService;

class ModuleDefinitionController extends Controller
{
    public function index(Request $request, CanonicalModuleDefinitionService $canonicalDefinitions): Response
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
            'needsCanonicalConservationSetup' => $canonicalDefinitions->missingCanonicalConservationDefinitionCount() > 0,
            'needsCanonicalEngpSetup' => $canonicalDefinitions->missingCanonicalEngpDefinitionCount() > 0,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['implementation_type'] = ModuleDefinition::IMPLEMENTATION_GENERIC;
        $data['code'] = ModuleDefinition::codeFromName($data['name']);
        if (ModuleDefinition::isRetiredCode((string) $data['code'])) {
            return back()->withErrors(['name' => 'This module is retired and cannot be created.'])->withInput();
        }
        if (ModuleDefinition::query()->where('code', $data['code'])->exists()) {
            return back()->withErrors(['name' => 'A module with this generated code already exists.'])->withInput();
        }
        $definition = ModuleDefinition::query()->create($data);
        app(AuditLogService::class)->record('module_management', 'Module Created', ModuleDefinition::class, $definition->id, $definition->name, 'Created module definition.', ['name' => $definition->name, 'code' => $definition->code, 'program_area' => $definition->program_area->value]);
        return back()->with('success', 'Module definition created.');
    }

    public function ensureDevelopmentCbep(Request $request, CanonicalModuleDefinitionService $definitions): RedirectResponse
    {
        [$definition, $created] = $definitions->ensureDevelopmentCbep();
        if ($created) {
            app(AuditLogService::class)->record(
                'module_management',
                'Canonical Development Module Added',
                ModuleDefinition::class,
                $definition->id,
                $definition->name,
                'Added the missing canonical ENGP CBEP module definition.',
                ['code' => 'engp_cbep', 'program_area' => $definition->program_area->value, 'source_key' => 'engp'],
            );
        }

        return back()->with('success', $created
            ? 'Canonical Development module definition added.'
            : 'Canonical Development module definition is already available.');
    }

    public function ensureCanonicalConservation(Request $request, CanonicalModuleDefinitionService $definitions): RedirectResponse
    {
        $result = $definitions->ensureCanonicalConservationDefinitions();
        foreach ($result['created'] as $definition) {
            app(AuditLogService::class)->record(
                'module_management',
                'Canonical Conservation Module Added',
                ModuleDefinition::class,
                $definition->id,
                $definition->name,
                'Added a missing canonical Conservation report definition.',
                [
                    'code' => $definition->code,
                    'program_area' => $definition->program_area->value,
                    'implementation_type' => $definition->implementation_type,
                    'source_key' => $definition->existing_source_key,
                ],
            );
        }

        $message = sprintf(
            'Canonical Conservation catalog checked: %d added, %d already active, %d existing inactive, %d code conflicts left unchanged.',
            count($result['created']),
            count($result['present']),
            count($result['inactive']),
            count($result['conflicts']),
        );
        if ($result['conflicts'] !== []) {
            $message .= ' Review these codes in Module Management: '.implode(', ', array_keys($result['conflicts'])).'.';
        }

        return back()->with('success', $message);
    }

    public function ensureCanonicalEngp(Request $request, CanonicalModuleDefinitionService $definitions): RedirectResponse
    {
        $result = $definitions->ensureCanonicalEngpDefinitions();
        foreach ($result['created'] as $definition) {
            app(AuditLogService::class)->record(
                'module_management',
                'Canonical ENGP Module Added',
                ModuleDefinition::class,
                $definition->id,
                $definition->name,
                'Added a missing canonical ENGP workflow definition.',
                [
                    'code' => $definition->code,
                    'program_area' => $definition->program_area->value,
                    'workflow_key' => $definition->requirement_key,
                    'source_key' => $definition->existing_source_key,
                ],
            );
        }

        $message = sprintf(
            'Canonical ENGP catalog checked: %d added, %d already active, %d existing inactive, %d code conflicts left unchanged.',
            count($result['created']),
            count($result['present']),
            count($result['inactive']),
            count($result['conflicts']),
        );
        if ($result['conflicts'] !== []) {
            $message .= ' Review these codes in Module Management: '.implode(', ', array_keys($result['conflicts'])).'.';
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, ModuleDefinition $moduleDefinition): RedirectResponse
    {
        abort_unless(! $moduleDefinition->isRetired(), 404);
        $data = $this->validated($request);
        $before = $moduleDefinition->only(array_keys($data));
        $moduleDefinition->update($data);
        app(AuditLogService::class)->record('module_management', 'Module Configuration Updated', ModuleDefinition::class, $moduleDefinition->id, $moduleDefinition->name, 'Updated module definition configuration.', ['before' => $before, 'after' => $moduleDefinition->fresh()->only(array_keys($data))]);
        return back()->with('success', 'Module definition updated. The module code remains stable.');
    }

    public function toggle(ModuleDefinition $moduleDefinition): RedirectResponse
    {
        abort_unless(! $moduleDefinition->isRetired(), 404);
        $old = $moduleDefinition->is_active;
        $moduleDefinition->update(['is_active' => ! $old]);
        app(AuditLogService::class)->record('module_management', $moduleDefinition->is_active ? 'Module Activated' : 'Module Deactivated', ModuleDefinition::class, $moduleDefinition->id, $moduleDefinition->name, 'Changed module active status.', ['old' => $old, 'new' => $moduleDefinition->is_active]);
        return back()->with('success', $moduleDefinition->is_active ? 'Module definition activated.' : 'Module definition deactivated. Historical records remain available.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program_area' => ['required', Rule::enum(ProgramArea::class)],
            'module_type' => ['required', Rule::in([ModuleDefinition::TYPE_REGULAR_TARGET, ModuleDefinition::TYPE_PLAN])],
            'reporting_frequency' => ['nullable', Rule::in(ModuleDefinition::FREQUENCIES), Rule::requiredIf($request->input('module_type') === ModuleDefinition::TYPE_REGULAR_TARGET)],
            'plan_duration_years' => ['nullable', 'integer', 'min:1', 'max:100', Rule::requiredIf($request->input('module_type') === ModuleDefinition::TYPE_PLAN)],
            'deadline_mode' => ['required', Rule::in([ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS, ModuleDefinition::DEADLINE_CUSTOM, ModuleDefinition::DEADLINE_NONE])],
            'default_deadline_days' => ['nullable', 'integer', 'min:1', 'max:366', Rule::requiredIf(in_array($request->input('deadline_mode'), [ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS], true))],
            'allow_deadline_override' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $data['reporting_frequency'] = $data['module_type'] === ModuleDefinition::TYPE_PLAN ? null : $data['reporting_frequency'];
        $data['plan_duration_years'] = $data['module_type'] === ModuleDefinition::TYPE_REGULAR_TARGET ? null : $data['plan_duration_years'];
        $data['default_deadline_days'] = in_array($data['deadline_mode'], [ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, ModuleDefinition::DEADLINE_CALENDAR_DAYS], true) ? $data['default_deadline_days'] : null;
        $data['allow_deadline_override'] = $data['deadline_mode'] === ModuleDefinition::DEADLINE_CUSTOM && (bool) ($data['allow_deadline_override'] ?? false);
        return $data;
    }

    /** @return array<string,mixed> */
    private function payload(ModuleDefinition $definition): array
    {
        return [
            'id' => $definition->id, 'name' => $definition->name, 'code' => $definition->code,
            'program_area' => $definition->program_area->value, 'program_area_label' => $definition->program_area->label(),
            'implementation_type' => $definition->implementation_type, 'module_type' => $definition->module_type,
            'reporting_frequency' => $definition->reporting_frequency, 'plan_duration_years' => $definition->plan_duration_years,
            'deadline_mode' => $definition->deadline_mode, 'default_deadline_days' => $definition->default_deadline_days,
            'allow_deadline_override' => $definition->allow_deadline_override, 'description' => $definition->description,
            'is_active' => $definition->is_active, 'existing_source_key' => $definition->existing_source_key,
        ];
    }
}
