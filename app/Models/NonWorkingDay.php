<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NonWorkingDay extends Model
{
    public const TYPE_NATIONAL_HOLIDAY = 'NATIONAL_HOLIDAY';
    public const TYPE_LOCAL_HOLIDAY = 'LOCAL_HOLIDAY';
    public const TYPE_SPECIAL_NON_WORKING_DAY = 'SPECIAL_NON_WORKING_DAY';
    public const TYPE_OFFICE_DECLARED_NON_WORKING_DAY = 'OFFICE_DECLARED_NON_WORKING_DAY';
    public const TYPE_OTHER = 'OTHER';

    public const SCOPE_NATIONAL = 'NATIONAL';
    public const SCOPE_DAVAO_ORIENTAL = 'DAVAO_ORIENTAL';
    public const SCOPE_OFFICE = 'OFFICE';

    protected $fillable = [
        'date', 'name', 'type', 'scope', 'location', 'reference', 'remarks', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(function (): void { BusinessCalendarService::forgetCache(); });
        static::deleted(function (): void { BusinessCalendarService::forgetCache(); });
    }

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
