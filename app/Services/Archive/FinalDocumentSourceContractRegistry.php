<?php

namespace App\Services\Archive;

use App\Services\Attachments\ReportDocumentAdapterResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit final returned-file contracts for routed report families. */
final class FinalDocumentSourceContractRegistry
{
    /**
     * Each source has one official report slot in its current adapter/schema.
     * The Regional return may contain several physical files for that slot;
     * the focal selects the complete set and confirms that exact set before
     * the server binds it to a manifest.
     *
     * @var array<string,array{attachment_source:string,slot:string,identity:string,label:string,extensions:list<string>,max_kilobytes:int}>
     */
    private const CONTRACTS = [
        'conservation' => ['attachment_source' => 'conservation-report', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 102400],
        'bms' => ['attachment_source' => 'bms-report', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 102400],
        'bams' => ['attachment_source' => 'bams-report', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 102400],
        'imea' => ['attachment_source' => 'imea-report', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 102400],
        'imea-maintenance' => ['attachment_source' => 'imea-maintenance', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 20480],
        'aws' => ['attachment_source' => 'aws', 'slot' => 'report_file', 'identity' => 'regional-return-report-file', 'label' => 'Final signed report file', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'], 'max_kilobytes' => 10240],
        'ipaf-management' => ['attachment_source' => 'ipaf-management', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 10240],
        'revenue' => ['attachment_source' => 'ipaf-revenue', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], 'max_kilobytes' => 10240],
        'engp' => ['attachment_source' => 'engp-report', 'slot' => 'mov', 'identity' => 'regional-return-mov', 'label' => 'Final signed ENGP MOV', 'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'], 'max_kilobytes' => 10240],
    ];

    public function forRegisteredSource(string $source, \Illuminate\Database\Eloquent\Model $record, ReportDocumentAdapterResolver $documents): ?array
    {
        $contract = self::CONTRACTS[$source] ?? null;
        if (! $contract) return null;

        $official = $documents->resolveOfficialDocumentSlot($source, $record);
        if (($official['status'] ?? null) !== ReportDocumentAdapterResolver::SUPPORTED
            || ($official['attachment_source'] ?? null) !== $contract['attachment_source']
            || ($official['slot'] ?? null) !== $contract['slot']
            || ! $official['adapter']) return null;

        return $contract;
    }

    /** @return list<string> */
    public function sourceKeys(): array
    {
        return array_keys(self::CONTRACTS);
    }

    public function logicalIdentity(string $source, int $ordinal): string
    {
        $contract = self::CONTRACTS[$source] ?? null;
        if (! $contract || $ordinal < 1 || $ordinal > 65535) {
            throw ValidationException::withMessages(['final_files' => 'The returned file set is outside this source contract.']);
        }

        return $contract['identity'].':part:'.str_pad((string) $ordinal, 5, '0', STR_PAD_LEFT);
    }

    /** @return array{mime_type:string,size:int,sha256:string,extension:string} */
    public function validateUpload(string $source, UploadedFile $upload): array
    {
        $contract = self::CONTRACTS[$source] ?? null;
        if (! $contract || ! $upload->isValid()) {
            throw ValidationException::withMessages(['final_files' => 'This report has no valid final returned-file contract.']);
        }

        Validator::make(['file' => $upload], [
            'file' => ['required', 'file', 'mimes:'.implode(',', $contract['extensions']), 'max:'.$contract['max_kilobytes']],
        ], ['file.mimes' => 'The selected file type is not allowed for this report source.', 'file.max' => 'The selected file is larger than this report source allows.'])->validate();

        $path = $upload->getRealPath();
        $mime = is_string($path) ? mime_content_type($path) : false;
        $size = (int) $upload->getSize();
        $hash = is_string($path) ? hash_file('sha256', $path) : false;
        $extension = strtolower($upload->getClientOriginalExtension());
        if (! is_string($mime) || ! in_array($extension, $contract['extensions'], true) || $size < 1 || ! is_string($hash)) {
            throw ValidationException::withMessages(['final_files' => 'The selected returned file could not be validated.']);
        }

        return ['mime_type' => $mime, 'size' => $size, 'sha256' => $hash, 'extension' => $extension];
    }

    /** @return list<string> */
    public function allowedMimeTypes(string $source): array
    {
        return match ($source) {
            'aws' => ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-ole-storage', 'application/octet-stream'],
            'engp' => ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-ole-storage', 'application/octet-stream'],
            default => ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-ole-storage', 'application/octet-stream'],
        };
    }
}
