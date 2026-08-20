<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BamsFauna extends Model
{
    use HasFactory;

    protected $table = 'bams_fauna';

    protected $fillable = [
        'protected_area_id',
        'fauna_type',
        'quadrat_no',
        'transect_no',
        'species',
        'status_seen_heard',
        'frequency',
        'svl',
        't_l',
        'h_l',
        'f_l',
        'wt',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'quadrat_no' => 'integer',
            'transect_no' => 'integer',
            'frequency' => 'integer',
            'svl' => 'decimal:2',
            't_l' => 'decimal:2',
            'h_l' => 'decimal:2',
            'f_l' => 'decimal:2',
            'wt' => 'decimal:2',
        ];
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
