<?php

namespace App\Services\Modules;

use App\Domain\Modules\ProgramArea;
use App\Models\Aws;
use App\Models\BamsReportSubmission;
use App\Models\BmsReportSubmission;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Models\ImeaFacilityMaintenanceReport;
use App\Models\ImeaReportSubmission;
use App\Models\IpafManagementReport;
use App\Models\IpafRevenueCollection;
use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use App\Models\TechnicalReport;
use App\Services\Conservation\ConservationReportWorkflowRegistry;
use App\Services\Engp\EngpReportWorkflowRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ModuleMetadataResolver
{
    /** @var Collection<string,ModuleDefinition>|null */
    private ?Collection $genericDefinitions = null;
    public function __construct(
        private readonly ConservationReportWorkflowRegistry $conservationWorkflows,
        private readonly EngpReportWorkflowRegistry $engpWorkflows,
    ) {}

    /** @param Collection<int,Model> $models */
    public function prime(Collection $models): void
    {
        $keys = $models
            ->filter(fn (Model $model): bool => $model instanceof ConservationReportSubmission)
            ->pluck('workflow_key')
            ->filter()
            ->map(fn ($key): string => (string) $key)
            ->unique()
            ->values();

        $this->genericDefinitions = $keys->isEmpty()
            ? collect()
            : ModuleDefinition::query()->active()->generic()->notRetired()->whereIn('code', $keys->all())
                ->get(['id', 'code', 'name', 'program_area'])
                ->keyBy('code');
    }

    /** @return array{module_name:string,program_area:?string,workflow_key:?string} */
    public function resolve(Model $model, ?string $fallbackName = null, ?string $fallbackProgramArea = null): array
    {
        $workflowKey = $model->getAttribute('workflow_key');

        if ($model instanceof ConservationReportSubmission) {
            $definition = $this->genericDefinitions !== null
                ? $this->genericDefinitions->get((string) $workflowKey)
                : ModuleDefinition::query()->active()->generic()->notRetired()->where('code', (string) $workflowKey)->first();

            if ($definition) {
                return [
                    'module_name' => $definition->name,
                    'program_area' => $this->programAreaLabel($definition->program_area),
                    'workflow_key' => $workflowKey ? (string) $workflowKey : null,
                ];
            }

            $legacy = $this->conservationWorkflows->find((string) $workflowKey);

            return [
                'module_name' => $legacy['label'] ?? $fallbackName ?? 'Conservation Report',
                'program_area' => $fallbackProgramArea ?? 'Protected Area Management and Development',
                'workflow_key' => $workflowKey ? (string) $workflowKey : null,
            ];
        }

        if ($model instanceof EngpReportSubmission) {
            $workflow = $this->engpWorkflows->find((string) $workflowKey);

            return [
                'module_name' => $workflow['label'] ?? $fallbackName ?? 'ENGP Report',
                'program_area' => $fallbackProgramArea ?? 'National Greening Program',
                'workflow_key' => $workflowKey ? (string) $workflowKey : null,
            ];
        }

        return [
            'module_name' => $fallbackName ?? $this->specializedName($model),
            'program_area' => $fallbackProgramArea ?? $this->specializedProgramArea($model),
            'workflow_key' => $workflowKey ? (string) $workflowKey : null,
        ];
    }

    private function specializedName(Model $model): string
    {
        return match (true) {
            $model instanceof BmsReportSubmission => 'BMS Report',
            $model instanceof BamsReportSubmission => 'BAMS Report',
            $model instanceof ImeaReportSubmission => 'IMEA Report',
            $model instanceof ImeaFacilityMaintenanceReport => 'IMEA Facility Maintenance',
            $model instanceof Aws => 'AWS Report',
            $model instanceof IpafManagementReport => 'Management of IPAF',
            $model instanceof IpafRevenueCollection => 'Revenue Collection',
            $model instanceof TechnicalReport => 'Technical Reports',
            $model instanceof ManagementPlan => 'Management Plans',
            default => 'Unknown Report',
        };
    }

    private function specializedProgramArea(Model $model): ?string
    {
        return match (true) {
            $model instanceof EngpReportSubmission => 'National Greening Program',
            $model instanceof BmsReportSubmission,
            $model instanceof BamsReportSubmission,
            $model instanceof ImeaReportSubmission,
            $model instanceof ImeaFacilityMaintenanceReport,
            $model instanceof Aws,
            $model instanceof IpafManagementReport,
            $model instanceof IpafRevenueCollection,
            $model instanceof TechnicalReport,
            $model instanceof ManagementPlan => 'Protected Area Management and Development',
            default => null,
        };
    }

    private function programAreaLabel(mixed $area): ?string
    {
        if ($area instanceof ProgramArea) {
            return $area === ProgramArea::ENGP ? 'National Greening Program' : $area->label();
        }

        return filled($area) ? (string) $area : null;
    }
}
