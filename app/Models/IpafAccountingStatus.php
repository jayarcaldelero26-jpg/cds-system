<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IpafAccountingStatus extends Model
{
    protected $fillable = [
        'protected_area_id',
        'reporting_year',
        'total_ipaf_collection',
        'bank_balance',
        'accounting_data_source',
        'total_ipaf_collection_source_reference',
        'bank_balance_source',
        'bank_balance_source_reference',
        'bank_balance_source_as_of',
        'bank_balance_synced_at',
        'bank_balance_sync_status',
        'status_note',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'total_ipaf_collection' => 'decimal:2',
            'bank_balance' => 'decimal:2',
            'bank_balance_synced_at' => 'datetime',
            'bank_balance_source_as_of' => 'date',
        ];
    }

    public function protectedArea(): BelongsTo
    {
        return $this->belongsTo(ProtectedArea::class);
    }
}
