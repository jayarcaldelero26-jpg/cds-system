<?php

namespace App\Services\SubmissionTracking;

use RuntimeException;
use App\Services\Authorization\OrganizationalAccessService;

/** Applies an immutable position setting to the canonical route definitions. */
final class EffectiveRoutingGraphResolver
{
    public function __construct(private readonly DocumentRoutingProfileRegistry $profiles) {}

    /** @return array{profile:array,actions:array,route_position:array} */
    public function resolve(string $source, bool $direct, array $position): array
    {
        if (($position['graph_version'] ?? null) !== RoutingPositionSnapshotService::GRAPH_VERSION) throw new RuntimeException('Unsupported route graph version.');
        $actions = $this->profiles->actionProfile($source, $direct)['actions'];
        $office = (bool) $position['office_penro_enabled'];
        $tsd = (bool) $position['penro_tsd_chief_enabled'];
        $remove = [];
        if (! $office) $remove = [...$remove, 'forward_to_office_penro', 'receive_at_office_penro', 'assign_to_tsd_chief', 'recommend_to_office_penro', 'receive_at_office_penro_final', 'return_from_office_for_correction', 'approve_for_regional_release'];
        if (! $tsd) $remove = [...$remove, 'assign_to_tsd_chief', 'receive_at_tsd_chief', 'forward_to_cds_focal'];
        $actions = array_values(array_filter($actions, fn (array $action): bool => ! in_array($action['key'], $remove, true)));

        if (! $office && ! $tsd) {
            $actions[] = $this->customAction('dispatch_penro_records_to_cds_focal', DocumentRoutingProfileRegistry::PENRO_RECORDS, DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL, 'PENRO Records Unit', 'PENRO CDS Focal Person', OrganizationalAccessService::PENRO_RECORDS, 'Dispatch to CDS Focal');
            $actions = array_map(fn (array $action): array => $action['key'] === 'receive_at_cds_focal' ? [...$action, 'from_office' => 'PENRO Records Unit'] : $action, $actions);
        } elseif (! $office && $tsd) {
            $actions[] = $this->customAction('dispatch_penro_records_to_tsd', DocumentRoutingProfileRegistry::PENRO_RECORDS, DocumentRoutingProfileRegistry::TRANSIT_TSD, 'PENRO Records Unit', 'PENRO TSD Chief', OrganizationalAccessService::PENRO_RECORDS, 'Dispatch to TSD Chief');
            $actions = array_map(fn (array $action): array => match ($action['key']) {
                'receive_at_tsd_chief' => [...$action, 'from_office' => 'PENRO Records Unit'],
                default => $action,
            }, $actions);
        } elseif ($office && ! $tsd) {
            $actions[] = $this->customAction('dispatch_office_to_cds_focal', DocumentRoutingProfileRegistry::OFFICE_PENRO, DocumentRoutingProfileRegistry::TRANSIT_CDS_FOCAL, 'Office of the PENRO', 'PENRO CDS Focal Person', OrganizationalAccessService::OFFICE_PENRO, 'Dispatch to CDS Focal');
            $actions = array_map(fn (array $action): array => $action['key'] === 'receive_at_cds_focal' ? [...$action, 'from_office' => 'Office of the PENRO'] : $action, $actions);
        }

        if (! $office) {
            $actions[] = $this->customAction('recommend_to_penro_records_final', DocumentRoutingProfileRegistry::CDS_CHIEF, DocumentRoutingProfileRegistry::TRANSIT_PENRO_RECORDS_FINAL, 'PENRO CDS Chief', 'PENRO Records Unit', OrganizationalAccessService::PENRO_CHIEF, 'Recommend to PENRO Records for Regional Release', 'recommended');
            $actions = array_map(fn (array $action): array => $action['key'] === 'receive_at_penro_records_final'
                ? [...$action, 'from_office' => 'PENRO CDS Chief', 'label' => 'Received by PENRO Records Unit on Recommendation', 'action_label' => 'Receive Recommendation'] : $action, $actions);
        }

        $outgoing = ['dispatch_penro_records_to_tsd', 'dispatch_penro_records_to_cds_focal', 'dispatch_office_to_cds_focal'];
        foreach ($actions as &$action) {
            if (in_array($action['key'], $outgoing, true)) $action['document_operation'] = 'forward';
            if (in_array($action['key'], ['dispatch_penro_records_to_tsd', 'dispatch_penro_records_to_cds_focal'], true)) $action['routing_checkpoint'] = 'penro_records_initial_dispatch';
        }
        unset($action);

        return [
            'profile' => $this->profiles->profile($source, $direct),
            'actions' => array_values($actions),
            'route_position' => [...$position, 'office_penro_enabled' => $office, 'penro_tsd_chief_enabled' => $tsd],
        ];
    }

    private function customAction(string $key, string $from, string $to, string $fromOffice, string $toOffice, string $category, string $label, string $eventKey = 'forwarded'): array
    {
        return [
            'key' => $key, 'from' => $from, 'to' => $to, 'event_key' => $eventKey,
            'from_office' => $fromOffice, 'to_office' => $toOffice, 'categories' => [$category],
            'label' => $label, 'action_label' => $label, 'document_operation' => 'forward',
            ...($from === DocumentRoutingProfileRegistry::PENRO_RECORDS ? ['routing_checkpoint' => 'penro_records_initial_dispatch'] : []),
        ];
    }
}
