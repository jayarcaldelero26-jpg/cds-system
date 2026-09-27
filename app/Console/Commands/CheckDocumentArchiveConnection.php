<?php

namespace App\Console\Commands;

use App\Services\Archive\GoogleDriveDocumentArchiveGateway;
use Illuminate\Console\Command;
use Throwable;

final class CheckDocumentArchiveConnection extends Command
{
    protected $signature = 'document-archive:connectivity-check';

    protected $description = 'Validate the configured Google Drive archive using one harmless, self-cleaning test file';

    public function handle(): int
    {
        $gateway = app(\App\Services\Archive\GoogleDriveArchiveGateway::class);
        if (! $gateway instanceof GoogleDriveDocumentArchiveGateway) {
            $this->error('Google Drive archive driver is not selected; no external request was made.');
            return self::FAILURE;
        }

        foreach ([
            'client_id' => 'CLIENT_ID',
            'client_secret' => 'CLIENT_SECRET',
            'refresh_token' => 'REFRESH_TOKEN',
            'folder_id' => 'FOLDER_ID',
        ] as $key => $label) {
            $this->line($label.': '.($gateway->configurationStatus()[$key] ? 'configured' : 'missing'));
        }

        try {
            $result = $gateway->connectivityCheck();
        } catch (Throwable) {
            $this->error('Connectivity check failed safely; no credential or raw Google response was displayed.');
            return self::FAILURE;
        }

        $this->line('OAUTH: '.($result['oauth'] ? 'PASS' : 'FAIL').$this->statusSuffix($result['oauth_status']));
        $this->line('ROOT_FOLDER: '.($result['folder'] ? 'PASS' : 'FAIL').$this->statusSuffix($result['folder_status']));
        if (! $result['folder']) return self::FAILURE;
        $this->line('PHASES: '.implode(' → ', $result['phase_history'] ?? []));
        $this->line('UPLOAD_MODE: '.($result['upload_mode'] ?? 'unknown'));
        $this->line('UPLOAD: '.($result['upload'] ? 'PASS' : 'FAIL'));
        if (is_string($result['file_id'])) $this->line('TEST_FILE_ID: '.$result['file_id']);
        if (is_int($result['reconciliation_matches'] ?? null)) {
            $this->line('RECONCILIATION: '.match ($result['reconciliation_matches']) {
                0 => 'zero matches; failed closed',
                1 => 'unique match; continued verification',
                default => $result['reconciliation_matches'].' matches; failed closed',
            });
        }
        foreach ($result['upload_responses'] ?? [] as $diagnostic) {
            $this->line(sprintf(
                'UPLOAD_RESPONSE: phase=%s mode=%s HTTP=%s content_type=%s body_length=%s json_keys=%s id_present=%s location_present=%s request_error_id=%s',
                $diagnostic['phase'] ?? 'unknown',
                $diagnostic['mode'] ?? 'unknown',
                $diagnostic['http_status'] ?? 'unknown',
                $diagnostic['content_type'] ?? 'none',
                $diagnostic['response_body_length'] ?? 'unknown',
                ($diagnostic['json_top_level_keys'] ?? []) === [] ? 'none' : implode(',', $diagnostic['json_top_level_keys']),
                ($diagnostic['id_present'] ?? false) ? 'yes' : 'no',
                ($diagnostic['location_present'] ?? false) ? 'yes' : 'no',
                $diagnostic['request_error_id'] ?? 'none',
            ));
        }
        $this->line('VERIFY: '.($result['verify'] ? 'PASS' : 'FAIL'));
        $this->line('DOWNLOAD: '.($result['download'] ? 'PASS' : 'FAIL'));
        $this->line('SHA256: '.($result['sha256'] && ($result['content_match'] ?? false) ? 'PASS' : 'FAIL'));
        $this->line('TEST_CLEANUP: '.($result['cleanup'] ? 'PASS' : 'FAIL'));
        if (is_string($result['operation_phase'] ?? null)) {
            $this->line('OPERATION_FAILURE: '.$result['operation_phase'].$this->statusSuffix($result['operation_status'] ?? null));
        }

        return $result['oauth'] && $result['folder'] && $result['upload'] && $result['verify']
            && $result['download'] && $result['sha256'] && ($result['content_match'] ?? false) && $result['cleanup']
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function statusSuffix(mixed $status): string
    {
        return is_int($status) && $status > 0 ? ' (HTTP '.$status.')' : '';
    }
}
