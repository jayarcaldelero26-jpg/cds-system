<?php

namespace App\Models;

use App\Services\BusinessCalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpafRevenueCollection extends Model
{
    protected $guarded = [];
    protected $appends = ['ipaf_ria', 'sagf', 'number_days_complied', 'timeliness', 'submission_status'];
    protected function casts(): array { return ['total_collected' => 'decimal:2', 'date_report_released_cenro' => 'date:Y-m-d', 'date_received_penro' => 'date:Y-m-d', 'deadline_submission' => 'date:Y-m-d', 'date_endorsed_regional' => 'date:Y-m-d', 'mov_size' => 'integer']; }
    public function protectedArea(): BelongsTo { return $this->belongsTo(ProtectedArea::class); }
    public function getIpafRiaAttribute(): string { return self::split($this->total_collected)['ipaf_ria']; }
    public function getSagfAttribute(): string { return self::split($this->total_collected)['sagf']; }
    public function getNumberDaysCompliedAttribute(): ?int { if (! $this->deadline_submission || ! $this->date_received_penro) return null; return app(BusinessCalendarService::class)->signedWorkingDayDifference($this->deadline_submission, $this->date_received_penro, $this->target_office ?? null); }
    public function getTimelinessAttribute(): string
    {
        if (! $this->deadline_submission || ! $this->date_report_released_cenro || ! $this->date_received_penro) return 'No Data';
        $days = $this->number_days_complied;
        return match (true) {
            $days >= 3 => 'Outstanding',
            $days === 2 => 'Very Satisfactory',
            $days === 1 => 'Satisfactory',
            $days === 0 || $days === -1 => 'Unsatisfactory',
            default => 'Poor',
        };
    }
    public function getSubmissionStatusAttribute(): string { return $this->date_received_penro ? 'Report Submitted' : 'Pending Submission by CENRO'; }
    public static function split(string|int|float $total): array { $cents = self::toCents($total); $ria = self::roundedShareCents($cents, 75); return ['ipaf_ria' => self::centsToDecimal($ria), 'sagf' => self::centsToDecimal($cents - $ria)]; }
    public static function normalizeMoney(string|int|float|null $value): string { return self::centsToDecimal(self::toCents($value)); }
    public static function sumMoney(iterable $values): string { $cents = 0; foreach ($values as $value) $cents += self::toCents($value); return self::centsToDecimal($cents); }
    public static function accomplishmentPercentage(string|int|float|null $collected, string|int|float|null $target): ?int
    {
        $targetCents = self::toCents($target);
        if ($targetCents <= 0) return null;
        $collectedCents = self::toCents($collected);
        return intdiv(($collectedCents * 100) + intdiv($targetCents, 2), $targetCents);
    }
    private static function toCents(string|int|float|null $value): int
    {
        $normalized = str_replace(',', '', trim((string) ($value ?? '0')));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $decimal = str_pad(substr(preg_replace('/\D/', '', $decimal), 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $decimal;
        return $negative ? -$cents : $cents;
    }
    private static function roundedShareCents(int $cents, int $percent): int { return intdiv(($cents * $percent) + 50, 100); }
    private static function centsToDecimal(int $cents): string { $negative = $cents < 0 ? '-' : ''; $absolute = abs($cents); return sprintf('%s%d.%02d', $negative, intdiv($absolute, 100), $absolute % 100); }
}
