<?php

namespace App\Services\Modules;

use App\Models\ModuleDefinition;
use App\Services\BusinessCalendarService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Authoritative policy adapter for future registry-backed workflows. */
final class ModuleDeadlineService
{
    public function __construct(private readonly BusinessCalendarService $calendar) {}

    /**
     * @return array{deadline_date:?string,processing_days:?int,deadline_mode:string,allow_deadline_override:bool}
     */
    public function resolve(ModuleDefinition $module, CarbonInterface|string|null $referenceDate, CarbonInterface|string|null $customDeadline = null, CarbonInterface|string|null $submittedDate = null, ?string $office = null): array
    {
        $reference = $this->date($referenceDate);
        $submitted = $this->date($submittedDate);
        $deadline = match ($module->deadline_mode) {
            ModuleDefinition::DEADLINE_STANDARD_WORKING_DAYS => $reference && $module->default_deadline_days
                ? $this->calendar->addWorkingDays($reference, $module->default_deadline_days, $office, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS)->toDateString()
                : null,
            ModuleDefinition::DEADLINE_CALENDAR_DAYS => $reference && $module->default_deadline_days
                ? $reference->addDays($module->default_deadline_days)->toDateString()
                : null,
            ModuleDefinition::DEADLINE_CUSTOM => $this->date($customDeadline)?->toDateString(),
            ModuleDefinition::DEADLINE_CUSTOM_STORED => $this->date($customDeadline)?->toDateString(),
            default => null,
        };

        $processingDays = $reference && $submitted
            ? ($module->deadline_mode === ModuleDefinition::DEADLINE_CALENDAR_DAYS
                ? max(0, $reference->diffInDays($submitted))
                : $this->calendar->workingDaysBetween($reference, $submitted, 'after_through', $office, BusinessCalendarService::STANDARD_WORKING_WEEKDAYS))
            : null;

        return [
            'deadline_date' => $deadline,
            'processing_days' => $processingDays,
            'deadline_mode' => $module->deadline_mode,
            'allow_deadline_override' => $module->allow_deadline_override,
        ];
    }

    private function date(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value, BusinessCalendarService::TIMEZONE)->startOfDay();
    }
}
