<?php

namespace App\Services\Conservation;

final class ConservationReportWorkflowRegistry
{
    /** @var array<string, array<string, mixed>> */
    private const WORKFLOWS = [
        'homestay' => ['label' => 'Homestay', 'description' => 'Homestay report submission and compliance tracking.', 'activities' => ['Training on Homestay Program'], 'documents' => ['Progress Report', 'Final Report']],
        'regular_pamb' => ['label' => 'Regular PAMB Meetings', 'description' => 'Regular PAMB meeting report submission and compliance tracking.', 'activities' => ['Regular PAMB'], 'documents' => ['Minutes', 'Reso', 'Report']],
        'special_pamb' => ['label' => 'Special PAMB Meetings', 'description' => 'Special PAMB meeting report submission and compliance tracking.', 'activities' => ['Special PAMB'], 'documents' => ['Minutes', 'Reso']],
        'maintenance_monuments' => ['label' => 'Maintenance of Monuments', 'description' => 'Maintenance of monuments report submission and compliance tracking.', 'activities' => ['Maintenance of Monuments'], 'documents' => ['Progress Report', 'Final Report']],
        'maintenance_buoy' => ['label' => 'Maintenance of Buoy', 'description' => 'Maintenance of buoy report submission and compliance tracking.', 'activities' => ['Maintenance of Buoys'], 'documents' => ['Progress Report', 'Final Report']],
        'twc_meetings' => ['label' => 'TWC Meetings', 'description' => 'TWC meeting report submission and compliance tracking.', 'activities' => ['TWC Meeting'], 'documents' => ['Report', 'Minutes']],
        'updating_pamp' => ['label' => 'Updating of PAMP', 'description' => 'Updating of PAMP report submission and compliance tracking.', 'activities' => ['Updating of PAMP'], 'documents' => ['Progress Report', 'Final Report']],
        'restoration_plan_5_year' => ['label' => '5 Year Restoration Plan Preparation', 'description' => '5 Year Restoration Plan preparation report submission and compliance tracking.', 'activities' => ['Preparation of 5-Year Restoration Plan'], 'documents' => ['Progress Report', 'Final Report']],
        'additional_bms_site' => ['label' => 'Additional BMS Site', 'description' => 'Additional BMS site report submission and compliance tracking.', 'activities' => ['Establishment of additional BMS site (Davao de Oro)'], 'documents' => ['Progress Report', 'Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 15, 'timeliness_standard' => 'A']]]],
        'cepa_plan' => ['label' => 'CEPA Plan', 'description' => 'CEPA plan report submission and compliance tracking.', 'activities' => ['CEPA Plan preparation (Analysis/Stocktaking)', 'CEPA Plan preparation (Branding)', 'CEPA Plan preparation (Identification of strategies)', 'CEPA Plan preparation (Action Planning)', 'CEPA Plan preparation (Writing the Communication Plan)', 'Submission of Final CEPA Plan'], 'documents' => ['Progress Report', 'Final Report'], 'activity_documents' => ['CEPA Plan preparation (Analysis/Stocktaking)' => ['Progress Report'], 'CEPA Plan preparation (Branding)' => ['Progress Report'], 'CEPA Plan preparation (Identification of strategies)' => ['Progress Report'], 'CEPA Plan preparation (Action Planning)' => ['Progress Report'], 'CEPA Plan preparation (Writing the Communication Plan)' => ['Progress Report'], 'Submission of Final CEPA Plan' => ['Final Report']], 'submission_rules' => ['CEPA Plan preparation (Analysis/Stocktaking)' => ['Progress Report' => ['working_days' => 7, 'timeliness_standard' => 'B']], 'CEPA Plan preparation (Branding)' => ['Progress Report' => ['working_days' => 7, 'timeliness_standard' => 'B']], 'CEPA Plan preparation (Identification of strategies)' => ['Progress Report' => ['working_days' => 7, 'timeliness_standard' => 'B']], 'CEPA Plan preparation (Action Planning)' => ['Progress Report' => ['working_days' => 7, 'timeliness_standard' => 'B']], 'CEPA Plan preparation (Writing the Communication Plan)' => ['Progress Report' => ['working_days' => 7, 'timeliness_standard' => 'B']], 'Submission of Final CEPA Plan' => ['Final Report' => ['working_days' => 15, 'timeliness_standard' => 'A']]]],
        'vtol_operations' => ['label' => 'Vertical Take Off and Landing Operations', 'description' => 'Vertical Take Off and Landing Operations report submission and compliance tracking.', 'activities' => ['Comprehensive Insurance (Medium multi rotor)', 'Comprehensive Insurance (Small multi rotor)', 'Preventive Maintenance (Medium multi rotor)', 'Preventive Maintenance (Small multi rotor)'], 'documents' => ['Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
        'bdfe_terrestrial' => ['label' => 'BDFE for Terrestrial PAs', 'description' => 'BDFE for Terrestrial PAs report submission and compliance tracking.', 'activities' => ['Development of BDFE for Terrestrial PA'], 'documents' => ['Progress Report', 'Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
        'bdfap' => ['label' => 'BDFAPs in PAs', 'description' => 'BDFAP submission and compliance tracking.', 'activities' => ['Identification of Potential BDFAP'], 'documents' => ['Inventory Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
        'maintenance_pamo_ecotourism' => ['label' => 'Maintenance of PAMO or Ecotourism', 'description' => 'PAMO or ecotourism maintenance submission and compliance tracking.', 'activities' => ['Maintenance of PAMO', 'Maintenance of Ecotourism Facilities'], 'documents' => ['Report']],
        'rehabilitation_pa_office' => ['label' => 'Rehabilitation of PA Office', 'description' => 'PA office rehabilitation submission and compliance tracking.', 'activities' => ['ASEAN Flags', 'Concrete Billboards'], 'documents' => ['Progress Report', 'Final Report']],
        'ecotourism_management_plan' => ['label' => 'Ecotourism Management Plan', 'description' => 'Ecotourism management plan submission and compliance tracking.', 'activities' => ['Preliminary Site Evaluation', 'Full Site Assessment', 'Meetings / Writeshops', 'Final Plan'], 'documents' => ['Progress Report', 'Final Report']],
        'updating_pamb_manual' => ['label' => 'Updating of PAMB Manual Operations', 'description' => 'PAMB manual update submission and compliance tracking.', 'activities' => ['Workshop / Writeshop', 'Presentation / Adoption', 'Final Updated Manual'], 'documents' => ['Progress Report', 'Final Report']],
        'management_effectiveness_assessment' => ['label' => 'Management Effectiveness Assessment', 'description' => 'Management effectiveness assessment submission and compliance tracking.', 'activities' => ['Key Informant Interview', 'Data Analysis', 'Final MEA'], 'documents' => ['Progress Report', 'Final Report']],
        'maintenance_pa_information_system' => ['label' => 'Maintenance of PA Information System', 'description' => 'PA information system maintenance submission and compliance tracking.', 'activities' => ['Maintenance of Protected Area Information System'], 'documents' => ['Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
        'monitoring_mangroves_corals_seagrass' => ['label' => 'Monitoring Mangroves, Corals, Seagrass', 'description' => 'Coastal ecosystem monitoring submission and compliance tracking.', 'activities' => ['Monitoring of Habitat condition (Mangroves - 1st Q)', 'Monitoring of Habitat condition (Coral reef - 2nd Q)', 'Monitoring of Habitat condition (Seagrass - 3rd Q)', 'Monitoring of Habitat condition Mangroves, Coral reef, Seagrass - 3rd Q)'], 'documents' => ['Report', 'Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 15, 'timeliness_standard' => 'A']]]],
        'water_quality_monitoring' => ['label' => 'Water Quality Monitoring within PA', 'description' => 'Water quality monitoring submission and compliance tracking.', 'activities' => ['Water Quality Monitoring (1st and 3rd Q)'], 'documents' => ['Progress Report', 'Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
        'mpan' => ['label' => 'MPAN', 'description' => 'MPAN enhancement submission and compliance tracking.', 'activities' => ['MPAN (TAMCMECA/MA-TA-MPAN) Enhancement to different levels of networking (4th Q)'], 'documents' => ['Progress Report', 'Final Report'], 'submission_rules' => ['*' => ['*' => ['working_days' => 7, 'timeliness_standard' => 'B']]]],
    ];

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        $workflow = self::WORKFLOWS[$key] ?? null;
        if (! $workflow) {
            return null;
        }

        $defaults = [
            'homestay' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3'],
                'default_activity' => 'Training on Homestay Program',
            ],
            'regular_pamb' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Regular PAMB',
            ],
            'special_pamb' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Special PAMB',
            ],
            'maintenance_monuments' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Maintenance of Monuments',
            ],
            'maintenance_buoy' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Maintenance of Buoys',
            ],
            'twc_meetings' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'TWC Meeting',
            ],
            'updating_pamp' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Updating of PAMP',
            ],
            'restoration_plan_5_year' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Preparation of 5-Year Restoration Plan',
            ],
            'additional_bms_site' => [
                'periods' => ['1st Semester', '2nd Semester'],
                'period_label' => 'Semester',
                'default_activity' => 'Establishment of additional BMS site (Davao de Oro)',
            ],
            'cepa_plan' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'CEPA Plan preparation (Analysis/Stocktaking)',
            ],
            'vtol_operations' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Comprehensive Insurance (Medium multi rotor)',
            ],
            'bdfe_terrestrial' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Development of BDFE for Terrestrial PA',
            ],
            'bdfap' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Identification of Potential BDFAP',
            ],
            'maintenance_pa_information_system' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Maintenance of Protected Area Information System',
            ],
            'monitoring_mangroves_corals_seagrass' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Monitoring of Habitat condition (Mangroves - 1st Q)',
            ],
            'water_quality_monitoring' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'Water Quality Monitoring (1st and 3rd Q)',
            ],
            'mpan' => [
                'periods' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
                'default_activity' => 'MPAN (TAMCMECA/MA-TA-MPAN) Enhancement to different levels of networking (4th Q)',
            ],
        ][$key] ?? [];

        return [
            'key' => $key,
            'period_field' => 'reporting_period',
            'period_label' => 'Reporting Period',
            'periods' => ['1st Semester', '2nd Semester'],
            'activity_documents' => array_fill_keys($workflow['activities'], $workflow['documents']),
            'days_complied_field' => 'days_complied',
            'penro_delay_field' => 'penro_delay',
            ...$defaults,
            ...$workflow,
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::WORKFLOWS);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values(array_filter(array_map(fn (string $key) => $this->find($key), $this->keys())));
    }

    /** @return array{working_days: int, timeliness_standard: 'A'|'B'} */
    public function submissionRule(string $workflowKey, ?string $activityName, ?string $documentType): array
    {
        $rules = $this->find($workflowKey)['submission_rules'] ?? [];
        $activityRules = $rules[$activityName] ?? [];
        $defaultRules = $rules['*'] ?? [];

        return $activityRules[$documentType]
            ?? $activityRules['*']
            ?? $defaultRules[$documentType]
            ?? $defaultRules['*']
            ?? ($workflowKey === 'cepa_plan' && $documentType === 'Final Report'
                ? ['working_days' => 15, 'timeliness_standard' => 'A']
                : null)
            ?? ($workflowKey === 'cepa_plan' && $documentType === 'Progress Report'
                ? ['working_days' => 7, 'timeliness_standard' => 'B']
                : null)
            ?? ['working_days' => 7, 'timeliness_standard' => 'B'];
    }
}
