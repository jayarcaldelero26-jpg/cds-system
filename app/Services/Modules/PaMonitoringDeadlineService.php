<?php

namespace App\Services\Modules;

use App\Models\ManagementPlan;
use App\Models\ModuleDefinition;
use Carbon\CarbonImmutable;

/** Centralizes PA Monitoring deadline modes without owning routing state. */
final class PaMonitoringDeadlineService
{
    /** @return array{deadline_mode:string,deadline_days:int,timeliness_standard:'A'|'B'} */
    public function managementPlanRule(ManagementPlan $plan): array
    {
        $type = $plan->relationLoaded('managementPlanType') ? $plan->managementPlanType?->name : null;
        $context = strtolower(trim(implode(' ', array_filter([
            $type,
            $plan->plan_type,
            $plan->activity_name,
            $plan->document_type,
        ]))));

        if (str_contains($context, 'ecotourism') || preg_match('/\bemp\b/', $context)) {
            return $this->rule(ModuleDefinition::DEADLINE_CALENDAR_DAYS, 7, 'B');
        }

        if (str_contains($context, 'cepa') && (str_contains($context, 'final') || strcasecmp(trim((string) $plan->document_type), 'Final Report') === 0)) {
            return $this->rule(ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 15, 'A');
        }

        if (str_contains($context, 'cepa') || str_contains($context, 'updating') || str_contains($context, 'restoration')) {
            return $this->rule(ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 7, 'B');
        }

        return $this->rule(ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS, 7, 'B');
    }

    public function deadline(CarbonImmutable $reference, array $rule, ?string $office = null): string
    {
        return match ($rule['deadline_mode']) {
            ModuleDefinition::DEADLINE_CALENDAR_DAYS => $reference->addDays($rule['deadline_days'])->toDateString(),
            default => app(\App\Services\BusinessCalendarService::class)->addWorkingDays($reference, $rule['deadline_days'], $office, \App\Services\BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString(),
        };
    }

    private function rule(string $mode, int $days, string $standard): array
    {
        return ['deadline_mode' => $mode, 'deadline_days' => $days, 'timeliness_standard' => $standard];
    }
}
