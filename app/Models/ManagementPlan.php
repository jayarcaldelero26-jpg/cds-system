<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ManagementPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'protected_area_id',
        'plan_type',
        'title',
        'version',
        'prepared_year',
        'approval_date',
        'valid_from',
        'valid_until',
        'status',
        'remarks',
        'attachments', // <--- Gi-update gikan sa attachment ngadto sa attachments
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'prepared_year' => 'integer',
        'approval_date' => 'date',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'attachments' => 'array', // <--- IMPORTANTE: Aronawtomatikong ma-array ang JSON gikan sa DB
    ];

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
