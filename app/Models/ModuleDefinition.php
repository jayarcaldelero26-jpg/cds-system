<?php

namespace App\Models;

use App\Domain\Modules\ProgramArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModuleDefinition extends Model
{
    use HasFactory;

    public const IMPLEMENTATION_GENERIC = 'generic';
    public const IMPLEMENTATION_SPECIALIZED = 'specialized';
    public const TYPE_REGULAR_TARGET = 'regular_target';
    public const TYPE_PLAN = 'plan';
    public const DEADLINE_STANDARD_WORKING_DAYS = 'standard_working_days';
    public const DEADLINE_CUSTOM = 'custom';
    public const DEADLINE_NONE = 'none';

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'semestral', 'annual', 'custom'];

    protected $fillable = [
        'name', 'code', 'program_area', 'implementation_type', 'module_type', 'reporting_frequency',
        'plan_duration_years', 'deadline_mode', 'default_deadline_days', 'allow_deadline_override',
        'is_active', 'description', 'existing_route_name', 'existing_source_key', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'program_area' => ProgramArea::class,
            'plan_duration_years' => 'integer',
            'default_deadline_days' => 'integer',
            'allow_deadline_override' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGeneric(Builder $query): Builder
    {
        return $query->where('implementation_type', self::IMPLEMENTATION_GENERIC);
    }

    public static function codeFromName(string $name): string
    {
        return trim(str_replace('-', '_', Str::slug($name, '-')), '_');
    }

    /** @return array{value:string,label:string} */
    public function programAreaOption(): array
    {
        $area = $this->program_area instanceof ProgramArea ? $this->program_area : ProgramArea::from((string) $this->program_area);
        return ['value' => $area->value, 'label' => $area->label()];
    }
}
