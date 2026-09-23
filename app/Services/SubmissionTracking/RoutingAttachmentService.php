<?php

namespace App\Services\SubmissionTracking;

use App\Models\DocumentRoutingEvent;
use App\Models\PambRoutingEvent;
use App\Models\SubmissionRoutingAttachment;
use App\Models\User;
use App\Services\Attachments\ProtectedAttachmentService;
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
        $previousPrimary = $purpose === 'routing_copy' ? $this->primaryAttachment($source, $sourceId) : null;

        try {
            $attachment = DB::transaction(function () use ($source, $sourceId, $documentEvent, $pambEvent, $stageKey, $actionKey, $purpose, $file, $path, $user, $remarks, $previousPrimary): SubmissionRoutingAttachment {
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

                if ($purpose === 'routing_copy' && $previousPrimary !== null) {
                    $this->replacePrimaryAttachment($previousPrimary, $path, $file);
                }

                return $attachment;
            });

            // Keep event/version metadata, but retain only the current binary
            // for this logical slot. The new DB reference is persisted before
            // any previous physical file is removed.
            if ($previous && $previous->stored_path !== $path) {
                $this->discard($previous->stored_path);
            }

            if ($previousPrimary !== null && $previousPrimary['path'] !== $path) {
                $this->discard($previousPrimary['path']);
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

    /** Resolve the effective current copy without changing event/version history. */
    public function currentDescriptor(string $source, int $sourceId, ?array $original = null, ?string $fallbackUrl = null, ?string $fallbackName = null): ?array
    {
        $latest = SubmissionRoutingAttachment::query()->where('source', $source)->where('source_id', $sourceId)->where(function ($query): void { $query->whereNull('purpose')->orWhere('purpose', '!=', 'correction_reference'); })->latest('id')->first();
        if ($latest) {
            return $this->descriptor($latest);
        }

        $url = $original['url'] ?? $original['preview_url'] ?? $fallbackUrl;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $mimeType = $original['mime_type'] ?? $original['type'] ?? null;
        $downloadUrl = $original['download_url'] ?? $url;

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
            'download_url' => $downloadUrl,
            'preview_url' => $original['preview_url'] ?? $url,
        ];
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

    /** @return array{record:Model,path:string,name:?string,mime:?string,size:?int}|null */
    private function primaryAttachment(string $source, int $sourceId): ?array
    {
        $protectedSource = match ($source) {
            'conservation' => 'conservation-report',
            'engp' => 'engp-report',
            'bms' => 'bms-report',
            'bams' => 'bams-report',
            'imea' => 'imea-report',
            'imea-maintenance' => 'imea-maintenance',
            'aws' => 'aws',
            'ipaf-management' => 'ipaf-management',
            'revenue' => 'ipaf-revenue',
            default => null,
        };
        if ($protectedSource === null) return null;

        $definition = app(ProtectedAttachmentService::class)->definition($protectedSource);
        if (! is_array($definition) || ($definition['kind'] ?? null) !== 'scalar') return null;

        /** @var class-string<Model> $modelClass */
        $modelClass = $definition['model'];
        $record = $modelClass::query()->find($sourceId);
        $path = $record?->getAttribute($definition['path']);
        if (! $record || ! is_string($path) || trim($path) === '') return null;

        return [
            'record' => $record,
            'path' => $path,
            'name' => isset($definition['name']) ? $record->getAttribute($definition['name']) : null,
            'mime' => isset($definition['mime']) ? $record->getAttribute($definition['mime']) : null,
            'size' => isset($definition['size']) ? $record->getAttribute($definition['size']) : null,
        ];
    }

    /** @param array{record:Model,path:string,name:?string,mime:?string,size:?int} $previous */
    private function replacePrimaryAttachment(array $previous, string $path, UploadedFile $file): void
    {
        $record = $previous['record'];
        $definition = app(ProtectedAttachmentService::class)->registry();
        $source = collect($definition)->first(fn (array $item): bool => ($item['model'] ?? null) === $record::class && ($item['kind'] ?? null) === 'scalar' && $record->getAttribute($item['path'] ?? '') === $previous['path']);
        if (! is_array($source)) return;

        $changes = [$source['path'] => $path];
        if (isset($source['name'])) $changes[$source['name']] = $this->filename($file->getClientOriginalName());
        if (isset($source['mime'])) $changes[$source['mime']] = $file->getMimeType() ?: $file->getClientMimeType();
        if (isset($source['size'])) $changes[$source['size']] = (int) ($file->getSize() ?: 0);
        $record->update($changes);
    }
}
