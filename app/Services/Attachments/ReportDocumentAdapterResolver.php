<?php

namespace App\Services\Attachments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Resolves active endpoint attachment definitions into a neutral slot adapter. */
final class ReportDocumentAdapterResolver
{
    public const SUPPORTED = 'SUPPORTED';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const UNRESOLVED = 'UNRESOLVED';

    public function __construct(private readonly ProtectedAttachmentService $attachments) {}

    /**
     * Resolve the official slot from the server's report registry.
     *
     * @return array{status:string,attachment_source:?string,slot:?string,adapter:?DocumentReferenceAdapter}
     */
    public function resolveOfficialDocumentSlot(string $routingSource, Model $record): array
    {
        $definition = $this->attachments->officialDefinitionForRoutingSource($routingSource);
        if ($definition === null) {
            return ['status' => self::NOT_APPLICABLE, 'attachment_source' => null, 'slot' => null, 'adapter' => null];
        }

        return $this->resolveRegisteredOfficialDocumentSlot($definition['source'], $record);
    }

    public function routingSourceForAttachmentSource(string $attachmentSource): ?string
    {
        $definition = $this->attachments->definition($attachmentSource);
        return is_string($definition['routing_source'] ?? null) ? $definition['routing_source'] : null;
    }

    /**
     * Resolve a protected-attachment registry entry when it is used as an
     * official report document. Structured entries without an explicit
     * canonical slot deliberately remain unresolved.
     *
     * @return array{status:string,attachment_source:string,slot:?string,adapter:?DocumentReferenceAdapter}
     */
    public function resolveRegisteredOfficialDocumentSlot(string $attachmentSource, Model $record): array
    {

        $definition = $this->attachments->definition($attachmentSource);
        if (! $definition || ! $record instanceof $definition['model']) {
            return ['status' => self::UNRESOLVED, 'attachment_source' => $attachmentSource, 'slot' => null, 'adapter' => null];
        }

        if (($definition['kind'] ?? null) === 'scalar' && is_string($definition['key'] ?? null)) {
            return ['status' => self::SUPPORTED, 'attachment_source' => $attachmentSource, 'slot' => $definition['key'], 'adapter' => $this->resolve($attachmentSource, $record, $definition['key'])];
        }

        $slot = $definition['official_key'] ?? null;
        if (($definition['kind'] ?? null) !== 'json' || ! is_string($slot)) {
            return ['status' => self::UNRESOLVED, 'attachment_source' => $attachmentSource, 'slot' => null, 'adapter' => null];
        }

        return ['status' => self::SUPPORTED, 'attachment_source' => $attachmentSource, 'slot' => $slot, 'adapter' => $this->resolve($attachmentSource, $record, $slot)];
    }

    public function resolve(string $source, Model $record, string $logicalSlot): DocumentReferenceAdapter
    {
        $definition = $this->attachments->definition($source);
        if (! $definition || ! $record instanceof $definition['model']) {
            throw ValidationException::withMessages(['attachment' => 'The report document source is not supported.']);
        }

        if (($definition['kind'] ?? null) === 'scalar' && ($definition['key'] ?? null) === $logicalSlot) {
            return new ScalarDocumentReferenceAdapter($definition['path'], $definition['name'] ?? null);
        }

        if (($definition['kind'] ?? null) === 'json' && is_string($definition['field'] ?? null)) {
            return new StructuredDocumentReferenceAdapter($definition['field']);
        }

        throw ValidationException::withMessages(['attachment' => 'The logical report document slot is not supported.']);
    }
}
