<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramProjectActivity extends Model
{
    use HasFactory;

    protected $table = 'program_project_activities';

    protected $fillable = [
        'protected_area_id',
        'title',
        'category',
        'description',
        'budget',
        'source_of_fund',
        'start_date',
        'end_date',
        'status',
        'remarks',
        'attachment',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'float',
    ];

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
