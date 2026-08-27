<?php

namespace App\Http\Controllers;

use App\Models\BamsReportSubmission;

class BamsReportSubmissionController extends StandardAReportSubmissionController
{
    protected string $modelClass = BamsReportSubmission::class;
    protected string $page = 'Bams/ReportSubmissions';
    protected string $routePrefix = 'bams.report-submissions';
    protected string $storageFolder = 'bams-report-movs';
    protected string $label = 'BAMS';
}
