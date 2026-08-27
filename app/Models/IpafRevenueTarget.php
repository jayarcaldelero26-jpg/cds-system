<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpafRevenueTarget extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2'];
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
