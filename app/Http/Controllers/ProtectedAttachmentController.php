<?php

namespace App\Http\Controllers;

use App\Services\Attachments\ProtectedAttachmentService;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\Authorization\OrganizationalAccessService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProtectedAttachmentController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments, private readonly PambSubmissionAccessService $pambAccess, private readonly OrganizationalAccessService $organization) {}

    public function show(string $source, int $record, string $attachment): BinaryFileResponse
    {
        $definition = $this->attachments->definition($source);
        abort_unless($definition, 404);

        $model = $definition['model'];
        $recordModel = $model::query()->when($model === ConservationReportSubmission::class, fn ($query) => $query->with('protectedArea'))->findOrFail($record);
        $ability = $definition['ability'] ?? null;
        $user = request()->user();
        abort_unless(is_string($ability) && $ability !== '' && $user?->can($ability), 403);
        abort_unless($this->organization->canViewSubmissionAttachment(request()->user(), $recordModel), 403);

        return $this->attachments->response($source, $recordModel, $attachment);
    }
}
