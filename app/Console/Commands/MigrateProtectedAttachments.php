<?php

namespace App\Console\Commands;

use App\Services\Attachments\ProtectedAttachmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateProtectedAttachments extends Command
{
    protected $signature = 'edats:migrate-protected-attachments
                            {--dry-run : Inventory active historical attachments without changing files or database records}
                            {--execute : Copy verified historical files to private storage; never deletes public sources}';

    protected $description = 'Copy referenced active eDATS attachments from public to private storage.';

    public function handle(ProtectedAttachmentService $attachments): int
    {
        if ($this->option('dry-run') && $this->option('execute')) {
            $this->error('Use either --dry-run or --execute, not both.');

            return self::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $this->line($execute
            ? 'Mode: EXECUTE — verified copies only; historical public sources will not be deleted.'
            : 'Mode: DRY RUN — no files or database records will be changed. Use --execute to copy files.');

        $stats = [
            'records' => 0,
            'references' => 0,
            'already_private' => 0,
            'historical_public' => 0,
            'would_migrate' => 0,
            'copied' => 0,
            'missing' => 0,
            'zero_byte' => 0,
            'collision' => 0,
            'external' => 0,
            'invalid' => 0,
            'duplicate' => 0,
            'failures' => 0,
            'orphans' => 0,
        ];
        $seen = [];
        $referenced = [];

        foreach ($attachments->registry() as $source => $definition) {
            $modelClass = $definition['model'];
            foreach ($modelClass::query()->cursor() as $record) {
                $stats['records']++;

                foreach ($attachments->migrationReferences($source, $record) as $reference) {
                    $stats['references']++;
                    $key = (string) $reference['key'];
                    $external = $reference['external'];

                    if (is_string($external) && trim($external) !== '') {
                        $stats['external']++;
                        $this->report($source, $record, $key, $external, 'EXTERNAL — NOT MIGRATED');
                        continue;
                    }

                    $path = $reference['path'];
                    $info = $attachments->migrationPathInfo($path);
                    if ($info['status'] === 'invalid') {
                        $stats['invalid']++;
                        $this->report($source, $record, $key, (string) $info['path'], 'INVALID — SKIPPED');
                        continue;
                    }

                    $canonical = $this->canonicalPath($info['path']);
                    $referenced[$canonical] = true;
                    if (isset($seen[$canonical])) {
                        $stats['duplicate']++;
                        $this->report($source, $record, $key, $info['path'], 'DUPLICATE REFERENCE — COPY ONCE');
                        continue;
                    }
                    $seen[$canonical] = true;

                    $size = $info['private']['size'] ?? $info['public']['size'] ?? null;
                    if (isset($info['public'])) {
                        $stats['historical_public']++;
                    }
                    if ($size === 0) {
                        $stats['zero_byte']++;
                        $this->report($source, $record, $key, $info['path'], 'ZERO-BYTE — REVIEW');
                        continue;
                    }

                    match ($info['status']) {
                        'private', 'identical' => $stats['already_private']++,
                        'missing' => $stats['missing']++,
                        'collision' => $stats['collision']++,
                        'public' => $this->handlePublicFile($attachments, $source, $record, $key, $info['path'], $execute, $stats),
                        default => null,
                    };

                    if ($info['status'] === 'missing') {
                        $this->report($source, $record, $key, $info['path'], 'MISSING — SKIPPED');
                    } elseif ($info['status'] === 'collision') {
                        $this->report($source, $record, $key, $info['path'], 'COLLISION — MANUAL REVIEW');
                    }
                }
            }
        }

        $stats['orphans'] = $this->reportOrphanCandidates($referenced);
        $this->summary($stats, $execute);

        return self::SUCCESS;
    }

    private function handlePublicFile(ProtectedAttachmentService $attachments, string $source, Model $record, string $key, string $path, bool $execute, array &$stats): void
    {
        if (! $execute) {
            $stats['would_migrate']++;
            return;
        }

        try {
            $result = $attachments->copyHistoricalToPrivate($path);
        } catch (Throwable $exception) {
            $result = ['status' => 'failed', 'path' => $path, 'message' => $exception->getMessage()];
        }

        if ($result['status'] === 'copied') {
            $stats['copied']++;
            return;
        }

        $stats['failures']++;
        $this->report($source, $record, $key, $path, 'COPY FAILED — '.($result['message'] ?? $result['status']));
    }

    /** @param array<string, bool> $referenced */
    private function reportOrphanCandidates(array $referenced): int
    {
        $count = 0;
        foreach (Storage::disk('public')->allFiles() as $path) {
            $canonical = $this->canonicalPath($path);
            if ($this->isExcludedPublicPath($canonical) || isset($referenced[$canonical])) {
                continue;
            }

            $count++;
            if ($count <= 50) {
                $this->line("PUBLIC STORAGE | {$path} | UNREFERENCED / ORPHAN CANDIDATE");
            }
        }

        if ($count > 50) {
            $this->line(sprintf('PUBLIC STORAGE | %d additional orphan candidates omitted from detailed output.', $count - 50));
        }

        return $count;
    }

    private function report(string $source, Model $record, string $key, string $path, string $status): void
    {
        $this->line(sprintf(
            '%s | #%s | %s | %s | %s',
            Str::headline($source),
            $record->getKey(),
            $key,
            $path,
            $status,
        ));
    }

    private function canonicalPath(string $path): string
    {
        $normalized = strtolower(ltrim(str_replace('\\', '/', $path), '/'));
        return str_starts_with($normalized, 'public/') ? substr($normalized, 7) : $normalized;
    }

    private function isExcludedPublicPath(string $path): bool
    {
        foreach ([
            'cds-lawin-monitorings/',
            'lawin-monitorings/',
            'issue-monitorings/',
            'ppa-attachments/',
            'ecotourism-monitorings/',
            'build/',
            'assets/',
            'images/',
            'css/',
            'js/',
            'fonts/',
        ] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return $path === '.gitignore';
    }

    /** @param array<string, int> $stats */
    private function summary(array $stats, bool $execute): void
    {
        $this->newLine();
        $this->line('Active records scanned: '.$stats['records']);
        $this->line('Attachment references: '.$stats['references']);
        $this->line('Already private: '.$stats['already_private']);
        $this->line('Historical public files found: '.$stats['historical_public']);
        if ($execute) {
            $this->line('Copied to private: '.$stats['copied']);
        } else {
            $this->line('Would migrate: '.$stats['would_migrate']);
        }
        $this->line('Missing: '.$stats['missing']);
        $this->line('Zero-byte: '.$stats['zero_byte']);
        $this->line('Collisions: '.$stats['collision']);
        $this->line('External URLs: '.$stats['external']);
        $this->line('Invalid references: '.$stats['invalid']);
        $this->line('Duplicate references: '.$stats['duplicate']);
        if ($execute) {
            $this->line('Failures: '.$stats['failures']);
        }
        $this->line('Orphan candidates: '.$stats['orphans']);
        $this->info($execute
            ? 'Execution complete. No historical public source files were deleted.'
            : 'DRY RUN — no files changed.');
    }
}
