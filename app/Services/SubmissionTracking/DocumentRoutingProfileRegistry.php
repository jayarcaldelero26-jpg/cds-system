<?php

namespace App\Services\SubmissionTracking;

use App\Services\Authorization\OrganizationalAccessService;

/**
 * Describes the shared custody route and action profile for each submission
 * source. Workflow-specific technical review remains outside this custody
 * profile unless that workflow already defines it.
 */
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
    public const TRANSIT_TSD = 'transit_to_tsd';
    public const TSD = 'tsd';
    public const TRANSIT_CDS = 'transit_to_cds';
    public const CDS = 'cds';
    public const TRANSIT_OFFICE_PENRO_RETURN = 'transit_to_office_of_penro_return';
    public const OFFICE_PENRO_RETURN = 'office_of_penro_return';
    public const TRANSIT_PENRO_RECORDS_FINAL = 'transit_to_penro_records_final';
    public const PENRO_RECORDS_FINAL = 'penro_records_final';
    public const RELEASED_REGIONAL = 'released_to_regional';

    /** @return array<string,mixed> */
    public function profile(string $sourceKey, bool $directPenro = false): array
    {
        if ($sourceKey === 'engp') {
            return [
                'key' => 'engp_release_components',
                'label' => 'ENGP release-component routing',
                'originating_office' => 'CENRO',
                'final_destination' => 'PENRO',
                'route_granularity' => 'release_components',
                'business_route_confirmation' => false,
                'detailed_route_requires_confirmation' => false,
            ];
        }

        return [
            'key' => $directPenro ? 'canonical_direct_penro' : 'canonical_cenro_penro_regional',
            'label' => $directPenro ? 'PENRO-origin canonical routing' : 'CENRO-to-PENRO canonical routing',
            'originating_office' => $directPenro ? 'PENRO' : 'CENRO',
            'final_destination' => 'Regional Office',
            'business_route_confirmation' => false,
            'detailed_route_requires_confirmation' => false,
            'route_granularity' => 'detailed',
        ];
    }

    /**
     * Authoritative action profile for non-PAMB Conservation documents.
     * PAMB and ENGP continue using their existing state machines.
     *
     * @return array{profile:array<string,mixed>,actions:list<array<string,mixed>>}
     */
    public function actionProfile(string $sourceKey, bool $directPenro = false): array
    {
        $profile = $this->profile($sourceKey, $directPenro);
        if ($sourceKey === 'engp') return ['profile' => $profile, 'actions' => []];

        $focal = OrganizationalAccessService::CENRO_FOCAL;
        $chief = OrganizationalAccessService::CENRO_CHIEF;
        $cenroRecords = OrganizationalAccessService::CENRO_RECORDS;
        $penroRecords = OrganizationalAccessService::PENRO_RECORDS;
        $penroFocal = OrganizationalAccessService::PENRO_FOCAL;

        $actions = [
            ['key' => 'forward_from_pamo', 'from' => self::PAMO_ORIGIN, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'PAMO', 'to_office' => 'PENRO Records Unit', 'categories' => [OrganizationalAccessService::PAMO], 'label' => 'Forwarded from PAMO to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'forward_from_penro_origin', 'from' => self::PENRO_ORIGIN, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'PENRO CDS Focal Person', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroFocal, OrganizationalAccessService::PENRO_CHIEF], 'label' => 'Forwarded from PENRO to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'forward_to_cenro_chief', 'from' => self::PREPARATION, 'to' => self::TRANSIT_CENRO_CHIEF, 'event_key' => 'forwarded', 'from_office' => 'CENRO CDS Focal Person', 'to_office' => 'CENRO CDS Chief', 'categories' => [$focal], 'label' => 'Forwarded to CENRO CDS Chief', 'action_label' => 'Forward to CENRO Chief'],
            ['key' => 'receive_at_cenro_chief', 'from' => self::TRANSIT_CENRO_CHIEF, 'to' => self::CENRO_CHIEF, 'event_key' => 'received', 'from_office' => 'CENRO CDS Focal Person', 'to_office' => 'CENRO CDS Chief', 'categories' => [$chief], 'label' => 'Received by CENRO CDS Chief', 'action_label' => 'Receive'],
            ['key' => 'forward_to_cenro_records', 'from' => self::CENRO_CHIEF, 'to' => self::TRANSIT_CENRO_RECORDS, 'event_key' => 'endorsed', 'from_office' => 'CENRO CDS Chief', 'to_office' => 'CENRO Records Unit', 'categories' => [$chief], 'label' => 'Endorsed to CENRO Records Unit', 'action_label' => 'Forward to CENRO Records'],
            ['key' => 'receive_at_cenro_records', 'from' => self::TRANSIT_CENRO_RECORDS, 'to' => self::CENRO_RECORDS, 'event_key' => 'received', 'from_office' => 'CENRO CDS Chief', 'to_office' => 'CENRO Records Unit', 'categories' => [$cenroRecords], 'label' => 'Received by CENRO Records Unit', 'action_label' => 'Receive'],
            ['key' => 'forward_to_penro_records', 'from' => self::CENRO_RECORDS, 'to' => self::TRANSIT_PENRO_RECORDS, 'event_key' => 'forwarded', 'from_office' => 'CENRO Records Unit', 'to_office' => 'PENRO Records Unit', 'categories' => [$cenroRecords], 'label' => 'Forwarded to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'receive_at_penro_records', 'from' => self::TRANSIT_PENRO_RECORDS, 'to' => self::PENRO_RECORDS, 'event_key' => 'received', 'from_office' => 'CENRO Records Unit', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroRecords], 'label' => 'Received by PENRO Records Unit', 'action_label' => 'Receive'],
            ['key' => 'forward_to_office_penro', 'from' => self::PENRO_RECORDS, 'to' => self::TRANSIT_OFFICE_PENRO, 'event_key' => 'forwarded', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Office of the PENRO', 'categories' => [$penroRecords], 'label' => 'Forwarded to Office of the PENRO', 'action_label' => 'Forward to Office of the PENRO'],
            ['key' => 'receive_at_office_penro', 'from' => self::TRANSIT_OFFICE_PENRO, 'to' => self::OFFICE_PENRO, 'event_key' => 'received', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Office of the PENRO', 'categories' => [$penroFocal], 'label' => 'Received by Office of the PENRO', 'action_label' => 'Receive'],
            ['key' => 'forward_to_tsd', 'from' => self::OFFICE_PENRO, 'to' => self::TRANSIT_TSD, 'event_key' => 'forwarded', 'from_office' => 'Office of the PENRO', 'to_office' => 'TSD', 'categories' => [$penroFocal], 'label' => 'Forwarded to TSD', 'action_label' => 'Forward to TSD'],
            ['key' => 'receive_at_tsd', 'from' => self::TRANSIT_TSD, 'to' => self::TSD, 'event_key' => 'received', 'from_office' => 'Office of the PENRO', 'to_office' => 'TSD', 'categories' => [$penroFocal], 'label' => 'Received by TSD', 'action_label' => 'Receive'],
            ['key' => 'forward_to_cds', 'from' => self::TSD, 'to' => self::TRANSIT_CDS, 'event_key' => 'forwarded', 'from_office' => 'TSD', 'to_office' => 'CDS', 'categories' => [$penroFocal], 'label' => 'Forwarded to CDS', 'action_label' => 'Forward to CDS'],
            ['key' => 'receive_at_cds', 'from' => self::TRANSIT_CDS, 'to' => self::CDS, 'event_key' => 'received', 'from_office' => 'TSD', 'to_office' => 'CDS', 'categories' => [$penroFocal], 'label' => 'Received by CDS', 'action_label' => 'Receive'],
            ['key' => 'forward_back_to_office_penro', 'from' => self::CDS, 'to' => self::TRANSIT_OFFICE_PENRO_RETURN, 'event_key' => 'forwarded', 'from_office' => 'CDS', 'to_office' => 'Office of the PENRO', 'categories' => [$penroFocal], 'label' => 'Forwarded back to Office of the PENRO', 'action_label' => 'Forward to Office of the PENRO'],
            ['key' => 'receive_at_office_penro_return', 'from' => self::TRANSIT_OFFICE_PENRO_RETURN, 'to' => self::OFFICE_PENRO_RETURN, 'event_key' => 'received', 'from_office' => 'CDS', 'to_office' => 'Office of the PENRO', 'categories' => [$penroFocal], 'label' => 'Received by Office of the PENRO', 'action_label' => 'Receive'],
            ['key' => 'forward_to_penro_records_final', 'from' => self::OFFICE_PENRO_RETURN, 'to' => self::TRANSIT_PENRO_RECORDS_FINAL, 'event_key' => 'forwarded', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroFocal], 'label' => 'Forwarded to PENRO Records Unit', 'action_label' => 'Forward to PENRO Records'],
            ['key' => 'receive_at_penro_records_final', 'from' => self::TRANSIT_PENRO_RECORDS_FINAL, 'to' => self::PENRO_RECORDS_FINAL, 'event_key' => 'received', 'from_office' => 'Office of the PENRO', 'to_office' => 'PENRO Records Unit', 'categories' => [$penroRecords], 'label' => 'Received by PENRO Records Unit', 'action_label' => 'Receive'],
            ['key' => 'release_to_regional', 'from' => self::PENRO_RECORDS_FINAL, 'to' => self::RELEASED_REGIONAL, 'event_key' => 'released', 'from_office' => 'PENRO Records Unit', 'to_office' => 'Regional Office', 'categories' => [$penroRecords], 'label' => 'Released / Endorsed to Regional Office', 'action_label' => 'Release to Regional Office'],
        ];

        if ($directPenro) {
            $actions = array_values(array_filter($actions, fn (array $action): bool => ! in_array($action['from'], [self::PREPARATION, self::TRANSIT_CENRO_CHIEF, self::CENRO_CHIEF, self::TRANSIT_CENRO_RECORDS, self::CENRO_RECORDS], true)));
        }

        return ['profile' => $profile, 'actions' => $actions];
    }
}
