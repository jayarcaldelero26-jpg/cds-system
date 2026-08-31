<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionRoutingCorrection extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $guarded = [];
    public $timestamps = false;

    protected function casts(): array
    {
        return ['corrected_at' => 'datetime'];
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
