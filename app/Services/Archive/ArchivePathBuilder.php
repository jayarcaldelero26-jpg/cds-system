<?php

namespace App\Services\Archive;

use App\Models\ModuleDefinition;
use RuntimeException;

/** Resolves Drive path segments from canonical Submission Tracking module metadata. */
final class ArchivePathBuilder
{
    /** @return array{unit:string,office:string,group:string,module:string,segments:list<string>,filename:string} */
    public function build(ModuleDefinition $module, string $archiveUnit, string $archiveOffice, string $filenameBase, string $extension = 'pdf', bool $allowPenroOffice = false, ?string $folderGroup = null): array
    {
        if (! $module->is_active || $module->isRetired() || ! filled($module->name)) {
            throw new RuntimeException('The active submission module is unmapped for archive storage.');
        }
        if (! in_array($archiveUnit, ['Conservation Unit', 'Development Unit'], true)) {
            throw new RuntimeException('The active submission source has no defensible archive unit classification.');
        }

        $officeName = $this->safeSegment($archiveOffice);
        if (! str_starts_with($officeName, 'CENRO ') && ! ($allowPenroOffice && str_starts_with($officeName, 'PENRO '))) {
            throw new RuntimeException('The active submission source has no defensible CENRO archive office.');
        }
        $moduleName = $this->safeSegment((string) $module->name);
        $groupName = $this->safeSegment($folderGroup ?? $officeName);
        $filename = strtolower($extension) === 'pdf'
            ? $this->safePdfFilename($filenameBase)
            : $this->safeFilenameWithExtension($filenameBase, $extension);

        return [
            'unit' => $archiveUnit,
            'office' => $officeName,
            'group' => $groupName,
            'module' => $moduleName,
            'segments' => [$archiveUnit, $groupName, $moduleName],
            'filename' => $filename,
        ];
    }

    private function safeSegment(string $value): string
    {
        $segment = preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '-', trim($value));
        $segment = preg_replace('/\s+/u', ' ', (string) $segment);
        $segment = trim((string) $segment, " .\t\n\r\0\x0B");
        if ($segment === '' || in_array($segment, ['.', '..'], true)) {
            throw new RuntimeException('The active module has an unsafe archive folder name.');
        }

        return $segment;
    }

    private function safeFilename(string $value): string
    {
        $filename = preg_replace('/[\\\\\/\x00-\x1F\x7F"<>:|?*]+/u', '-', trim($value));
        $filename = trim((string) $filename, " .\t\n\r\0\x0B");
        if ($filename === '' || in_array($filename, ['.', '..'], true)) {
            throw new RuntimeException('The archive filename is unsafe.');
        }

        return $filename;
    }

    private function safePdfFilename(string $filenameBase): string
    {
        $stem = rtrim(trim($filenameBase), '.');
        $stem = preg_replace('/(?:\.pdf)+$/i', '', $stem) ?? $stem;
        $stem = trim($stem, " .\t\n\r\0\x0B");
        if ($stem === '' || in_array($stem, ['.', '..'], true)) {
            throw new RuntimeException('The archive filename is unsafe.');
        }

        // Reserve four characters for the extension in document_archives.original_filename.
        $stem = rtrim(mb_substr($this->safeFilename($stem), 0, 251), ' .');

        return $stem.'.pdf';
    }

    private function safeFilenameWithExtension(string $filenameBase, string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]+/i', '', $extension) ?? '');
        if ($extension === '') throw new RuntimeException('The archive filename extension is unsafe.');

        $stem = pathinfo($filenameBase, PATHINFO_FILENAME);
        $stem = trim($this->safeFilename($stem), " .\t\n\r\0\x0B");
        if ($stem === '' || in_array($stem, ['.', '..'], true)) {
            throw new RuntimeException('The archive filename is unsafe.');
        }

        $stem = rtrim(mb_substr($stem, 0, max(1, 254 - mb_strlen($extension))), ' .');
        return $stem.'.'.$extension;
    }
}
