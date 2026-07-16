<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueMonitoring extends Model
{
    use HasFactory;

    protected $table = 'issue_monitorings';

    protected $fillable = [
        'protected_area_id',
        'issue_description',
        'findings',
        'date_observed',
        'recommendations',
        'action_taken',
        'status',
        'attachment',
    ];

    protected $casts = [
        'date_observed' => 'date',
    ];

    // Relasyon padulong sa Protected Area
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
