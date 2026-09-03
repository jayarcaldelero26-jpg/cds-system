<?php

namespace App\Http\Controllers;

use App\Models\ProtectedArea;
use App\Models\ManagementPlan;
use App\Models\EcotourismMonitoring;
use App\Models\IssueMonitoring;
use App\Models\LawinMonitoring;
use App\Models\ProgramProjectActivity;
use App\Models\BmsRecord;
use App\Models\BamsFlora;
use App\Models\ImeaAssessment;
use App\Models\Aws;
use App\Services\Authorization\OrganizationalAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function __construct(private readonly OrganizationalAccessService $organization) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $pa = $this->organization->scopeProtectedAreaQuery(ProtectedArea::query(), $user, 'id');
        $management = $this->organization->scopeProtectedAreaQuery(ManagementPlan::query(), $user);

        return Inertia::render('Reports/Index', [
            'stats' => [
                'protected_areas_count' => $pa->count(),
                'management_plans_count' => $management->count(),
                'bms_records_count' => $this->organization->scopeProtectedAreaQuery(BmsRecord::query(), $user)->count(),
                'bams_records_count' => $this->organization->scopeProtectedAreaQuery(BamsFlora::query(), $user)->count(),
                'imea_assessments_count' => $this->organization->scopeProtectedAreaQuery(ImeaAssessment::query(), $user)->count(),
                'aws_records_count' => $this->organization->scopeProtectedAreaQuery(Aws::query(), $user)->count(),
            ],
        ]);
    }
}
