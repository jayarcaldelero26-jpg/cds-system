<?php

namespace App\Services\Archive;

use App\Models\ModuleDefinition;
use RuntimeException;

/** Resolves Drive path segments from canonical Submission Tracking module metadata. */
final class ArchivePathBuilder
{
    /** @return array{unit:string,office:string,module:string,segments:list<string>,filename:string} */
    public function build(ModuleDefinition $module, string $archiveUnit, string $archiveOffice, string $trackingNumber): array
    {
        if (! $module->is_active || $module->isRetired() || ! filled($module->name)) {
            throw new RuntimeException('The active submission module is unmapped for archive storage.');
        }
        if (! in_array($archiveUnit, ['Conservation Unit', 'Development Unit'], true)) {
            throw new RuntimeException('The active submission source has no defensible archive unit classification.');
        }

        $officeName = $this->safeSegment($archiveOffice);
        if (! str_starts_with($officeName, 'CENRO ')) {
            throw new RuntimeException('The active submission source has no defensible CENRO archive office.');
        }
        $moduleName = $this->safeSegment((string) $module->name);
        $filename = $this->safeFilename($trackingNumber.'.pdf');

        return [
            'unit' => $archiveUnit,
            'office' => $officeName,
            'module' => $moduleName,
            'segments' => [$archiveUnit, $officeName, $moduleName],
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
            throw new RuntimeException('The tracking number is unsafe for an archive filename.');
        }

        return $filename;
    }
}
