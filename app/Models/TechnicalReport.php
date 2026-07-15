<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'protected_area_id',
        'report_type',
        'reporting_year',
        'quarter',
        'submission_date',
        'status',
        'attachment',
        'remarks',
    ];
    protected $casts = [
        'submission_date' => 'date',
    ];
    // Relasyon: Kini nga report iya sa usa ka Protected Area
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

}
