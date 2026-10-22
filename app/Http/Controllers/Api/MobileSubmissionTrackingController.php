<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileApiToken;
use App\Models\User;
use App\Services\Authorization\OrganizationalAccessService;
use App\Services\SubmissionTracking\DocumentRoutingAccessService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileSubmissionTrackingController extends Controller
{
    private const LIST_FIELDS = [
        'source', 'source_id', 'tracking_number', 'workflow_key', 'module', 'target_office',
        'protected_area', 'protected_area_id', 'activity_name', 'document_type', 'reporting_year',
        'date_conducted', 'date_accomplished', 'reporting_period', 'deadline_submission',
        'submission_status', 'stage', 'routing_complete', 'completed_at',
    ];

    public function __construct(
        private readonly SubmissionTrackingService $tracking,
        private readonly OrganizationalAccessService $organization,
        private readonly DocumentRoutingAccessService $documentAccess,
    ) {}

    public function index(Request $request, string $queue): JsonResponse
    {
        $this->assertTrackingAccess($request);
        if (! in_array($queue, ['incoming', 'outgoing', 'history'], true)) {
            abort(404);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:120'],
            'protected_area_id' => ['nullable', 'integer', 'min:1'],
            'target_office' => ['nullable', 'string', 'max:160'],
            'reporting_period' => ['nullable', 'string', 'max:80'],
            'reporting_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
            'status' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $filters = array_intersect_key($validated, array_flip([
            'search', 'module', 'protected_area_id', 'target_office', 'reporting_period', 'reporting_year', 'status',
        ]));

        $records = $this->tracking->records($filters, null, false);
        $queues = $this->tracking->workspaceQueues($filters, $records);
        $rows = $queues[$queue] ?? collect();
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $total = $rows->count();
        $data = $rows->forPage($page, $perPage)->map(fn (array $row): array => $this->listData($row))->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'queue' => $queue,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $data->isEmpty() ? null : (($page - 1) * $perPage) + 1,
                'to' => $data->isEmpty() ? null : (($page - 1) * $perPage) + $data->count(),
            ],
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $source, int $record): JsonResponse
    {
        $this->assertTrackingAccess($request);
        $sourceConfig = $this->tracking->source($source);
        if (! $sourceConfig) {
            abort(404);
        }

        $model = $sourceConfig['model']::query()->find($record);
        if (! $model || ! $this->documentAccess->canView($request->user(), $model, $source, $sourceConfig['ability'])) {
            abort(404);
        }

        // Resolve details from the same scoped and normalized source projection as the web tracker.
        $row = $this->tracking->records([
            'focus_source' => $source,
            'focus_id' => $record,
        ], null, false)->first(fn (array $item): bool => $item['source'] === $source && (int) $item['source_id'] === $record);

        if (! $row) {
            abort(404);
        }

        return response()->json(['data' => $this->detailData($row)]);
    }

    private function assertTrackingAccess(Request $request): void
    {
        $user = $request->user();
        $token = $request->attributes->get('mobile_api_token');

        abort_unless($user instanceof User
            && $token instanceof MobileApiToken
            && in_array('submission-tracking:read', $token->abilities ?? [], true)
            && $this->organization->canViewSubmissionTracking($user), 403);
    }

    private function listData(array $row): array
    {
        return array_intersect_key($row, array_flip(self::LIST_FIELDS));
    }

    private function detailData(array $row): array
    {
        $data = $this->listData($row);
        $data['routing_summary'] = array_intersect_key((array) ($row['routing_summary'] ?? []), array_flip([
            'current_location', 'current_status', 'responsible_office', 'pending_since',
            'working_days_pending', 'next_expected_action', 'last_updated',
        ]));
        $timeline = data_get($row, 'routing.timeline', $row['routing_timeline'] ?? []);
        $data['routing_timeline'] = collect($timeline)->map(fn (array $event): array => array_intersect_key($event, array_flip([
            'key', 'label', 'status', 'display_status', 'display_status_label', 'event_type',
            'action_label', 'occurred_at', 'from', 'to', 'office', 'recorded_by',
        ])))->values();

        return $data;
    }
}
