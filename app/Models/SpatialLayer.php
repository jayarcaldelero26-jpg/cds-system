<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'protected_area_id', 'name', 'layer_type', 'source_format', 'geojson',
    'original_filename', 'geometry_type', 'created_by',
])]
class SpatialLayer extends Model
{
    protected function casts(): array
    {
        return ['geojson' => 'array'];
    }
    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
