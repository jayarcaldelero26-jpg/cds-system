<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class RoutingAttachmentService
{
    private const DISK = 'local';
    private const FOLDER = 'submission-routing-attachments';
    public function __construct(private readonly OrganizationalAccessService $organization) {}
    public function store(UploadedFile $file): string { $path = $file->store(self::FOLDER, self::DISK); abort_unless(is_string($path) && $path !== '', 500); return $path; }
    public function discard(?string $path): void { if (is_string($path) && $path !== '') Storage::disk(self::DISK)->delete($path); }
    public function create(string $source, int $sourceId, UploadedFile $file, string $path, ?User $user, ?string $stageKey, ?string $actionKey, ?string $remarks = null, ?DocumentRoutingEvent $documentEvent = null, ?PambRoutingEvent $pambEvent = null, string $purpose = 'routing_copy'): SubmissionRoutingAttachment
    {
        $previous = $this->currentSlotAttachment($source, $sourceId, $purpose);
        try {
            $attachment = DB::transaction(function () use ($source, $sourceId, $documentEvent, $pambEvent, $stageKey, $actionKey, $purpose, $file, $path, $user, $remarks): SubmissionRoutingAttachment {
                $attachment = SubmissionRoutingAttachment::query()->create([
                    'source' => $source,
                    'source_id' => $sourceId,
                    'document_routing_event_id' => $documentEvent?->getKey(),
                    'pamb_routing_event_id' => $pambEvent?->getKey(),
                    'stage_key' => $stageKey,
                    'action_key' => $actionKey,
                    'purpose' => $purpose,
                    'original_name' => $this->filename($file->getClientOriginalName()),
                    'stored_path' => $path,
                    'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream',
                    'file_size' => (int) ($file->getSize() ?: 0),
                    'uploaded_by' => $user?->getKey(),
                    'remarks' => filled($remarks) ? trim($remarks) : null,
                ]);

                return $attachment;
            });

            // Keep event/version metadata, but retain only the current binary
            // for this logical slot. Defer removal until the surrounding
            // lifecycle transaction commits; this service may be called from
            // an outer transaction that can still roll back after this insert.
            if ($previous && $previous->stored_path !== $path) {
                $previousPath = $previous->stored_path;
                DB::afterCommit(fn (): bool => Storage::disk(self::DISK)->delete($previousPath));
            }

            return $attachment;
        } catch (\Throwable $exception) {
            // The caller also cleans up failed routing transactions; this
            // guard covers direct service use and failed attachment inserts.
            if ($path) $this->discard($path);
            throw $exception;
        }
    }
    public function latest(string $source, int $sourceId): ?SubmissionRoutingAttachment { return SubmissionRoutingAttachment::query()->where('source', $source)->where('source_id', $sourceId)->latest('id')->first(); }
    public function forDocumentEvents(iterable $ids): array { return SubmissionRoutingAttachment::query()->whereIn('document_routing_event_id', collect($ids)->filter()->all())->get()->keyBy('document_routing_event_id')->all(); }
    public function forPambEvents(iterable $ids): array { return SubmissionRoutingAttachment::query()->whereIn('pamb_routing_event_id', collect($ids)->filter()->all())->get()->keyBy('pamb_routing_event_id')->all(); }
    public function forStages(string $source, int $sourceId): array { return SubmissionRoutingAttachment::query()->where('source', $source)->where('source_id', $sourceId)->latest('id')->get()->unique('stage_key')->keyBy('stage_key')->all(); }
    public function descriptor(SubmissionRoutingAttachment $attachment): array
    {
        $base = route('submission-tracking.routing-attachments.show', [$attachment->source, $attachment->source_id, $attachment]);

        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'type' => $attachment->mime_type,
            'size' => $attachment->file_size,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'source' => 'Routing attachment',
            'is_routing_copy' => true,
            'version_source' => 'routing',
            'url' => $base.'?preview=1',
            'download_url' => $base,
            'preview_url' => $base.'?preview=1',
        ];
    }

    /** Resolve only the official source document; event-linked copies are presented with their routing events. */
    public function currentDescriptor(string $source, int $sourceId, ?array $original = null, ?string $fallbackUrl = null, ?string $fallbackName = null): ?array
    {
        $url = $original['url'] ?? $original['preview_url'] ?? $fallbackUrl;
        if (is_string($url) && trim($url) !== '') {
            $mimeType = $original['mime_type'] ?? $original['type'] ?? null;
            return [
                'id' => $original['id'] ?? null,
                'name' => $original['name'] ?? $fallbackName ?? 'Original MOV / report',
                'mime_type' => $mimeType,
                'type' => $mimeType,
                'size' => $original['size'] ?? null,
                'uploaded_at' => $original['uploaded_at'] ?? null,
                'source' => 'Original MOV / report',
                'is_routing_copy' => false,
                'version_source' => 'original',
                'url' => $url,
                'download_url' => $original['download_url'] ?? $url,
                'preview_url' => $original['preview_url'] ?? $url,
            ];
        }

        return null;
    }
    public function response(Model $record, SubmissionRoutingAttachment $attachment, bool $preview = false): BinaryFileResponse
    {
        abort_unless($this->organization->canViewSubmissionAttachment(request()->user(), $record), 403);
        abort_unless($attachment->source_id === (int) $record->getKey() && Storage::disk(self::DISK)->exists($attachment->stored_path), 404);
        $path = Storage::disk(self::DISK)->path($attachment->stored_path); $headers = ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'];
        return $preview ? response()->file($path, $headers) : response()->download($path, $attachment->original_name, $headers);
    }
    private function filename(string $name): string { $name = basename(str_replace('\\', '/', $name)); $name = preg_replace('/[\x00-\x1F\x7F"]+/', '_', $name) ?: 'routing-attachment'; return trim($name, '. ') ?: 'routing-attachment'; }

    private function currentSlotAttachment(string $source, int $sourceId, string $purpose): ?SubmissionRoutingAttachment
    {
        return SubmissionRoutingAttachment::query()
            ->where('source', $source)
            ->where('source_id', $sourceId)
            ->where(function ($query) use ($purpose): void {
                if ($purpose === 'routing_copy') {
                    $query->whereNull('purpose')->orWhere('purpose', 'routing_copy');
                    return;
                }

                $query->where('purpose', $purpose);
            })
            ->latest('id')
            ->first();
    }

}
