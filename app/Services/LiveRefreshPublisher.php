<?php

namespace App\Services;

use App\Events\LiveRefreshRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LiveRefreshPublisher
{
    private const SCOPES = ['notifications', 'tracking', 'archives'];

    /** @param iterable<int|string|null> $recipientIds @param list<string> $scopes */
    public function afterCommit(iterable $recipientIds, array $scopes): void
    {
        $recipients = collect($recipientIds)
            ->map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT) ?: 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $scopes = array_values(array_unique(array_filter(
            $scopes,
            static fn (mixed $scope): bool => is_string($scope) && in_array($scope, self::SCOPES, true),
        )));

        if ($recipients === [] || $scopes === []) return;

        $publish = static function () use ($recipients, $scopes): void {
            foreach ($recipients as $recipientId) {
                foreach ($scopes as $scope) {
                    try {
                        event(new LiveRefreshRequested($recipientId, $scope));
                    } catch (Throwable $exception) {
                        Log::warning('CDS-SMART live refresh delivery failed.', [
                            'scope' => $scope,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($publish);
            return;
        }

        $publish();
    }

    /** Resolve report-specific viewers after the write transaction commits. */
    public function afterCommitUsing(callable $recipientIds, array $scopes): void
    {
        $resolveAndPublish = function () use ($recipientIds, $scopes): void {
            try {
                $this->afterCommit($recipientIds(), $scopes);
            } catch (Throwable $exception) {
                Log::warning('CDS-SMART live refresh audience resolution failed.', [
                    'scopes' => array_values(array_filter($scopes, static fn (mixed $scope): bool => is_string($scope))),
                    'exception' => $exception::class,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($resolveAndPublish);
            return;
        }

        $resolveAndPublish();
    }
}
