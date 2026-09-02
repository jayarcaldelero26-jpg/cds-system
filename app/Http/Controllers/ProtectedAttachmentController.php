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
        abort_unless(request()->user()?->can($definition['ability']), 403);

        $model = $definition['model'];
        $recordModel = $model::query()->when($model === ConservationReportSubmission::class, fn ($query) => $query->with('protectedArea'))->findOrFail($record);
        if ($recordModel instanceof ConservationReportSubmission) {
            abort_unless($this->pambAccess->canView(request()->user(), $recordModel), 403);
        }
        if ($recordModel instanceof EngpReportSubmission) {
            abort_unless($this->organization->canViewDevelopmentRecord(request()->user(), $recordModel), 403);
        }

        return $this->attachments->response($source, $recordModel, $attachment);
    }
}
