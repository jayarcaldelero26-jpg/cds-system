<?php

namespace App\Http\Controllers;

use App\Services\Attachments\ProtectedAttachmentService;
use App\Models\ConservationReportSubmission;
use App\Models\EngpReportSubmission;
use App\Services\SubmissionTracking\PambSubmissionAccessService;
use App\Services\SubmissionTracking\DocumentRoutingTransitionService;
use App\Services\Authorization\OrganizationalAccessService;
use Symfony\Component\HttpFoundation\Response;

final class ProtectedAttachmentController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments, private readonly PambSubmissionAccessService $pambAccess, private readonly OrganizationalAccessService $organization, private readonly DocumentRoutingTransitionService $routing) {}

    public function show(string $source, int $record, string $attachment): Response
    {
        $definition = $this->attachments->definition($source);
        abort_unless($definition, 404);

        $model = $definition['model'];
        $recordModel = $model::query()->when($model === ConservationReportSubmission::class, fn ($query) => $query->with('protectedArea'))->findOrFail($record);
        $ability = $definition['ability'] ?? null;
        $user = request()->user();
        $sourceAuthorized = is_string($ability) && $ability !== ''
            && (bool) $user?->can($ability)
            && $this->organization->canViewSubmissionAttachment($user, $recordModel);
        $routingSource = $definition['routing_source'] ?? null;
        $isCurrentOfficialDocument = is_string($routingSource)
            && ($definition['official_key'] ?? null) === $attachment;
        if ($isCurrentOfficialDocument) {
            // Preview projection and protected delivery share the same
            // server policy so an Outgoing button cannot promise bytes that
            // this endpoint later rejects (or embed an error page as a PDF).
            abort_unless($this->routing->canAccessCurrentDocument($recordModel, $routingSource, $user), 403);
        } else {
            abort_unless($sourceAuthorized, 403);
        }

        return $this->attachments->response($source, $recordModel, $attachment);
    }
}
