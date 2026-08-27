<?php

namespace App\Http\Controllers;

use App\Models\ImeaReportSubmission;

class ImeaReportSubmissionController extends StandardAReportSubmissionController
{
    protected string $modelClass = ImeaReportSubmission::class;
    protected string $page = 'Imea/ReportSubmissions';
    protected string $routePrefix = 'imea.report-submissions';
    protected string $storageFolder = 'imea-report-movs';
    protected string $label = 'IMEA';
}
