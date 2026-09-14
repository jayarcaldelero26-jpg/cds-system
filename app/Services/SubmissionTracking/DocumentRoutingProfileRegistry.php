<?php

namespace App\Services\SubmissionTracking;

use App\Services\Authorization\OrganizationalAccessService;

final class DocumentRoutingProfileRegistry
{
    public const PREPARATION = 'cenro_preparation';
    public const PENRO_ORIGIN = 'penro_origin';
    public const PAMO_ORIGIN = 'pamo_origin';
    public const TRANSIT_CENRO_CHIEF = 'transit_to_cenro_chief';
    public const CENRO_CHIEF = 'cenro_chief';
    public const TRANSIT_CENRO_RECORDS = 'transit_to_cenro_records';
    public const CENRO_RECORDS = 'cenro_records';
    public const TRANSIT_PENRO_RECORDS = 'transit_to_penro_records';
    public const PENRO_RECORDS = 'penro_records';
    public const TRANSIT_OFFICE_PENRO = 'transit_to_office_of_penro';
    public const OFFICE_PENRO = 'office_of_penro';
    public const TRANSIT_TSD = 'transit_to_tsd_chief';
    public const TSD = 'penro_tsd_chief';
    public const TRANSIT_CDS_FOCAL = 'transit_to_cds_focal';
    public const CDS_FOCAL = 'penro_cds_focal';
    public const TRANSIT_CDS_CHIEF = 'transit_to_cds_chief';
    public const CDS_CHIEF = 'penro_cds_chief';
    public const TRANSIT_CDS = self::TRANSIT_CDS_FOCAL;
    public const CDS = self::CDS_FOCAL;
    public const TRANSIT_OFFICE_PENRO_RETURN = 'transit_to_office_of_penro_final';
    public const OFFICE_PENRO_RETURN = 'office_of_penro_final';
    public const TRANSIT_PENRO_RECORDS_FINAL = 'transit_to_penro_records_final';
    public const PENRO_RECORDS_FINAL = 'penro_records_final';
    public const RELEASED_REGIONAL = 'released_to_regional';

    public function profile(string $sourceKey, bool $directPenro = false): array
    {
        return ['key' => $directPenro ? 'canonical_direct_penro' : 'canonical_cenro_penro_regional', 'label' => $directPenro ? 'PENRO-origin canonical routing' : 'CENRO-to-PENRO canonical routing', 'originating_office' => $directPenro ? 'PENRO' : 'CENRO', 'final_destination' => 'Regional Office', 'business_route_confirmation' => false, 'detailed_route_requires_confirmation' => false, 'route_granularity' => 'detailed'];
    }

    public function actionProfile(string $sourceKey, bool $directPenro = false): array
    {
        $profile = $this->profile($sourceKey, $directPenro);
        $focal = OrganizationalAccessService::CENRO_FOCAL;
        $chief = OrganizationalAccessService::CENRO_CHIEF;
        $cenroRecords = OrganizationalAccessService::CENRO_RECORDS;
        $penroRecords = OrganizationalAccessService::PENRO_RECORDS;
        $officePenro = OrganizationalAccessService::OFFICE_PENRO;
        $tsdChief = OrganizationalAccessService::PENRO_TSD_CHIEF;
        $penroFocal = OrganizationalAccessService::PENRO_FOCAL;
        $penroChief = OrganizationalAccessService::PENRO_CHIEF;

        $actions = [
            ['key' => 'forward_from_pamo', 'from' => self::PAMO_ORIGIN, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'PAMO', 'to_office' => 'PENRO Records Unit', 'categories' => [OrganizationalAccessService::PAMO], 'label' => 'Forwarded from PAMO to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'forward_from_penro_origin', 'from' => self::PENRO_ORIGIN, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'PENRO origin', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroFocal], 'label' => 'Forwarded from PENRO origin to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'forward_to_cenro_chief', 'from' => self::PREPARATION, 'to' => self::TRANSIT_CENRO_CHIEF, 'event_key' => 'forwarded', 'from_office' => 'CENRO CDS Focal Person', 'to_office' => 'CENRO CDS Chief', 'categories' => [$focal], 'label' => 'Forwarded to CENRO CDS Chief', 'action_label' => 'Forward to CENRO Chief'],
            ['key' => 'receive_at_cenro_chief', 'from' => self::TRANSIT_CENRO_CHIEF, 'to' => self::CENRO_CHIEF, 'event_key' => 'received', 'from_office' => 'CENRO CDS Focal Person', 'to_office' => 'CENRO CDS Chief', 'categories' => [$chief], 'label' => 'Received by CENRO CDS Chief', 'action_label' => 'Receive'],
            ['key' => 'return_to_cenro_focal', 'from' => self::CENRO_CHIEF, 'to' => self::PREPARATION, 'event_key' => 'returned_for_correction', 'from_office' => 'CENRO CDS Chief', 'to_office' => 'CENRO CDS Focal Person', 'categories' => [$chief], 'label' => 'Returned to CENRO CDS Focal Person for Correction', 'action_label' => 'Return for Correction', 'correction' => true],
            ['key' => 'forward_to_cenro_records', 'from' => self::CENRO_CHIEF, 'to' => self::TRANSIT_CENRO_RECORDS, 'event_key' => 'endorsed', 'from_office' => 'CENRO CDS Chief', 'to_office' => 'CENRO Records Unit', 'categories' => [$chief], 'label' => 'Endorsed to CENRO Records Unit', 'action_label' => 'Forward to CENRO Records'],
            ['key' => 'receive_at_cenro_records', 'from' => self::TRANSIT_CENRO_RECORDS, 'to' => self::CENRO_RECORDS, 'event_key' => 'received', 'from_office' => 'CENRO CDS Chief', 'to_office' => 'CENRO Records Unit', 'categories' => [$cenroRecords], 'label' => 'Received by CENRO Records Unit', 'action_label' => 'Receive'],
            ['key' => 'forward_to_penro_records', 'from' => self::CENRO_RECORDS, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'CENRO Records Unit', 'to_office' => 'PENRO Records Unit', 'categories' => [$cenroRecords], 'label' => 'Forwarded to PENRO Records Unit', 'action_label' => 'Release to PENRO Records'],
            ['key' => 'receive_at_penro_records', 'from' => self::TRANSIT_PENRO_RECORDS, 'to' => self::PENRO_RECORDS, 'event_key' => 'received', 'from_office' => 'CENRO Records Unit', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroRecords], 'label' => 'Received by PENRO Records Unit', 'action_label' => 'Receive'],
            ['key' => 'forward_to_office_penro', 'from' => self::PENRO_RECORDS, 'to' => self::TRANSIT_OFFICE_PENRO, 'event_key' => 'forwarded', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Office of the PENRO', 'categories' => [$penroRecords], 'label' => 'Forwarded to Office of the PENRO', 'action_label' => 'Forward to Office of the PENRO'],
            ['key' => 'receive_at_office_penro', 'from' => self::TRANSIT_OFFICE_PENRO, 'to' => self::OFFICE_PENRO, 'event_key' => 'received', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Office of the PENRO', 'categories' => [$officePenro], 'label' => 'Received by Office of the PENRO', 'action_label' => 'Receive'],
            ['key' => 'assign_to_tsd_chief', 'from' => self::OFFICE_PENRO, 'to' => self::TRANSIT_TSD, 'event_key' => 'forwarded', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO TSD Chief', 'categories' => [$officePenro], 'label' => 'Assigned to PENRO TSD Chief', 'action_label' => 'Assign to TSD Chief'],
            ['key' => 'receive_at_tsd_chief', 'from' => self::TRANSIT_TSD, 'to' => self::TSD, 'event_key' => 'received', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO TSD Chief', 'categories' => [$tsdChief], 'label' => 'Received by PENRO TSD Chief', 'action_label' => 'Receive'],
            ['key' => 'forward_to_cds_focal', 'from' => self::TSD, 'to' => self::TRANSIT_CDS_FOCAL, 'event_key' => 'forwarded', 'from_office' => 'PENRO TSD Chief', 'to_office' => 'PENRO CDS Focal Person', 'categories' => [$tsdChief], 'label' => 'Forwarded to PENRO CDS Focal Person', 'action_label' => 'Forward to CDS Focal'],
            ['key' => 'receive_at_cds_focal', 'from' => self::TRANSIT_CDS_FOCAL, 'to' => self::CDS_FOCAL, 'event_key' => 'received', 'from_office' => 'PENRO TSD Chief', 'to_office' => 'PENRO CDS Focal Person', 'categories' => [$penroFocal], 'label' => 'Received by PENRO CDS Focal Person', 'action_label' => 'Receive'],
            ['key' => 'forward_to_cds_chief', 'from' => self::CDS_FOCAL, 'to' => self::TRANSIT_CDS_CHIEF, 'event_key' => 'forwarded', 'from_office' => 'PENRO CDS Focal Person', 'to_office' => 'PENRO CDS Chief', 'categories' => [$penroFocal], 'label' => 'Forwarded to PENRO CDS Chief', 'action_label' => 'Forward to CDS Chief'],
            ['key' => 'receive_at_cds_chief', 'from' => self::TRANSIT_CDS_CHIEF, 'to' => self::CDS_CHIEF, 'event_key' => 'received', 'from_office' => 'PENRO CDS Focal Person', 'to_office' => 'PENRO CDS Chief', 'categories' => [$penroChief], 'label' => 'Received by PENRO CDS Chief', 'action_label' => 'Receive'],
            ['key' => 'return_to_penro_cds_focal', 'from' => self::CDS_CHIEF, 'to' => self::CDS_FOCAL, 'event_key' => 'returned_for_correction', 'from_office' => 'PENRO CDS Chief', 'to_office' => 'PENRO CDS Focal Person', 'categories' => [$penroChief], 'label' => 'Returned to PENRO CDS Focal Person for Correction', 'action_label' => 'Return for Correction', 'correction' => true],
            ['key' => 'recommend_to_office_penro', 'from' => self::CDS_CHIEF, 'to' => self::TRANSIT_OFFICE_PENRO_RETURN, 'event_key' => 'recommended', 'from_office' => 'PENRO CDS Chief', 'to_office' => 'Office of the PENRO', 'categories' => [$penroChief], 'label' => 'Recommended to Office of the PENRO', 'action_label' => 'Recommend to Office of the PENRO'],
            ['key' => 'receive_at_office_penro_final', 'from' => self::TRANSIT_OFFICE_PENRO_RETURN, 'to' => self::OFFICE_PENRO_RETURN, 'event_key' => 'received', 'from_office' => 'PENRO CDS Chief', 'to_office' => 'Office of the PENRO', 'categories' => [$officePenro], 'label' => 'Received by Office of the PENRO for Final Review', 'action_label' => 'Receive'],
            ['key' => 'return_from_office_for_correction', 'from' => self::OFFICE_PENRO_RETURN, 'to' => self::CDS_FOCAL, 'event_key' => 'returned_for_correction', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO CDS Focal Person', 'categories' => [$officePenro], 'label' => 'Returned to PENRO CDS Focal Person for Correction', 'action_label' => 'Return for Correction', 'correction' => true],
            ['key' => 'approve_for_regional_release', 'from' => self::OFFICE_PENRO_RETURN, 'to' => self::TRANSIT_PENRO_RECORDS_FINAL, 'event_key' => 'approved', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO Records Unit', 'categories' => [$officePenro], 'label' => 'Approved for Regional Release', 'action_label' => 'Approve for Regional Release'],
            ['key' => 'receive_at_penro_records_final', 'from' => self::TRANSIT_PENRO_RECORDS_FINAL, 'to' => self::PENRO_RECORDS_FINAL, 'event_key' => 'received', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroRecords], 'label' => 'Received by PENRO Records Unit for Regional Release', 'action_label' => 'Receive'],
            ['key' => 'release_to_regional', 'from' => self::PENRO_RECORDS_FINAL, 'to' => self::RELEASED_REGIONAL, 'event_key' => 'released', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Regional Office', 'categories' => [$penroRecords], 'label' => 'Released / Endorsed to Regional Office', 'action_label' => 'Release to Regional Office'],
        ];

        if ($directPenro) {
            $actions = array_values(array_filter($actions, fn (array $action): bool => ! in_array($action['from'], [self::PREPARATION, self::TRANSIT_CENRO_CHIEF, self::CENRO_CHIEF, self::TRANSIT_CENRO_RECORDS, self::CENRO_RECORDS], true)));
        }

        return ['profile' => $profile, 'actions' => $actions];
    }
}
