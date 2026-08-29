<?php

namespace App\Http\Controllers;

use App\Services\Attachments\ProtectedAttachmentService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProtectedAttachmentController extends Controller
{
    public function __construct(private readonly ProtectedAttachmentService $attachments) {}

    public function show(string $source, int $record, string $attachment): BinaryFileResponse
    {
        $definition = $this->attachments->definition($source);
        abort_unless($definition, 404);
        abort_unless(request()->user()?->can($definition['ability']), 403);

        $model = $definition['model'];
        $recordModel = $model::query()->findOrFail($record);

        return $this->attachments->response($source, $recordModel, $attachment);
    }
}
