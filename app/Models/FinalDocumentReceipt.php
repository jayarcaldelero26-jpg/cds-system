<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class FinalDocumentReceipt extends Model
{
    protected $fillable = [
        'source_type', 'source_id', 'workflow_key', 'release_evidence_type', 'release_evidence_key',
        'received_by', 'receiving_category', 'receiving_office', 'physically_received_at', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['physically_received_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Final document receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Final document receipts are retained as audit evidence.'));
    }

    public function manifests()
    {
        return $this->hasMany(FinalDocumentArchiveManifest::class, 'receipt_id');
    }
}
