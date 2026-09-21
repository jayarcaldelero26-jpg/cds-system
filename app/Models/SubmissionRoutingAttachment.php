<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionRoutingAttachment extends Model
{
    protected $fillable = ['source', 'source_id', 'document_routing_event_id', 'pamb_routing_event_id', 'stage_key', 'action_key', 'purpose', 'original_name', 'stored_path', 'mime_type', 'file_size', 'uploaded_by', 'remarks'];

    protected function casts(): array { return ['file_size' => 'integer']; }
    public function documentRoutingEvent(): BelongsTo { return $this->belongsTo(DocumentRoutingEvent::class); }
    public function pambRoutingEvent(): BelongsTo { return $this->belongsTo(PambRoutingEvent::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
