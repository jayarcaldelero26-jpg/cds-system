<?php

namespace App\Http\Controllers;

use App\Models\SubmissionRoutingAttachment;
use App\Services\SubmissionTracking\RoutingAttachmentService;
use App\Services\SubmissionTracking\SubmissionTrackingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SubmissionRoutingAttachmentController extends Controller
{
    public function __construct(private readonly SubmissionTrackingService $tracking, private readonly RoutingAttachmentService $attachments) {}
    public function show(Request $request, string $source, int $record, SubmissionRoutingAttachment $attachment): BinaryFileResponse
    {
        abort_unless($attachment->source === $source && $attachment->source_id === $record, 404);
        $definition = $this->tracking->source($source); abort_unless($definition, 404); $model = $definition['model'];
        return $this->attachments->response($model::query()->findOrFail($record), $attachment, $request->boolean('preview'));
    }
}
