import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { buildSidebarNavigation, finalArchivesNavigationItem, matchesNavigationItem, withGenericModuleNavigation } from '../../resources/js/Layouts/navigation.mjs';

const authenticatedLayout = readFileSync(new URL('../../resources/js/Layouts/AuthenticatedLayout.jsx', import.meta.url), 'utf8');

const protectedArea = 'protected_area_management_and_development';
const staticReports = [
    ['Homestay', '/conservation-reports/homestay'],
    ['Regular PAMB Meetings', '/conservation-reports/regular_pamb'],
    ['Special PAMB Meetings', '/conservation-reports/special_pamb'],
    ['Maintenance of Monuments', '/conservation-reports/maintenance_monuments'],
    ['Maintenance of Buoy', '/conservation-reports/maintenance_buoy'],
    ['TWC Meetings', '/conservation-reports/twc_meetings'],
    ['Updating of PAMP', '/conservation-reports/updating_pamp'],
    ['BMS', '/bms?tracker=1'],
    ['BAMS', '/bams/report-submissions'],
    ['5 Year Restoration Plan Preparation', '/conservation-reports/restoration_plan_5_year'],
    ['Additional BMS Site', '/conservation-reports/additional_bms_site'],
    ['CEPA Plan', '/conservation-reports/cepa_plan'],
    ['Vertical Take off and Landing Operations', '/conservation-reports/vtol_operations'],
    ['Automated Weather Station', '/aws'],
    ['BDFE for Terrestrial PAs', '/conservation-reports/bdfe_terrestrial'],
    ['BDFAPs in PAs', '/conservation-reports/bdfap'],
    ['Maintenance of PAMO or Ecotourism', '/conservation-reports/maintenance_pamo_ecotourism'],
    ['IMEA', '/imea/report-submissions'],
    ['Management of IPAF', '/ipaf?ipaf_tab=management'],
    ['Rehabilitation of PA Office', '/conservation-reports/rehabilitation_pa_office'],
    ['Ecotourism Management Plan', '/conservation-reports/ecotourism_management_plan'],
    ['Updating of PAMB Manual Operations', '/conservation-reports/updating_pamb_manual'],
    ['Management Effectiveness Assessment', '/conservation-reports/management_effectiveness_assessment'],
    ['Maintenance of PA Information System', '/conservation-reports/maintenance_pa_information_system'],
    ['Monitoring Mangroves, Corals, Seagrass', '/conservation-reports/monitoring_mangroves_corals_seagrass'],
    ['Revenue Collection', '/ipaf?ipaf_tab=revenue'],
    ['Water Quality Monitoring within PA', '/conservation-reports/water_quality_monitoring'],
    ['MPAN', '/conservation-reports/mpan'],
];

const genericNames = [
    '5 Year Restoration Plan Preparation', 'Additional BMS Site', 'BDFAPs in PAs',
    'BDFE for Terrestrial PAs', 'CEPA Plan', 'Ecotourism Management Plan', 'Homestay',
    'Maintenance of Buoy', 'Maintenance of Monuments', 'Maintenance of PA Information System',
    'Maintenance of PAMO or Ecotourism', 'Management Effectiveness Assessment',
    'Monitoring Mangroves, Corals, Seagrass', 'MPAN', 'Regular PAMB Meetings',
    'Rehabilitation of PA Office', 'Special PAMB Meetings', 'TWC Meetings',
    'Updating of PAMB Manual Operations', 'Updating of PAMP',
    'Vertical Take Off and Landing Operations', 'Water Quality Monitoring within PA',
];

test('absolute generic routes merge into curated report links exactly once', () => {
    const databaseChildren = [
        { label: 'BMS Data', href: '/bms' },
        { label: 'BAMS Data', href: '/bams' },
    ];
    const navigation = [
        {
            label: 'Protected Area Management and Development',
            children: staticReports.map(([label, href]) => ({ label, href })),
        },
        { label: 'Conservation Database', children: databaseChildren },
        { label: 'CDS-SMART MONITORING' },
    ];
    const modules = genericNames.map(label => ({
        label,
        href: `http://cds-smart${staticReports.find(([name]) => name.toLowerCase() === label.toLowerCase())[1]}`,
        program_area: protectedArea,
    }));
    const merged = withGenericModuleNavigation(navigation, modules);
    const reports = merged.find(item => item.label === 'Protected Area Management and Development').children;

    assert.deepEqual(reports.map(item => [item.label, item.href]), staticReports);
    assert.equal(new Set(reports.map(item => item.href)).size, 28);
    assert.deepEqual(merged.find(item => item.label === 'Conservation Database').children, databaseChildren);
});

test('new generic modules are appended once when duplicate definitions use relative and absolute URLs', () => {
    const navigation = [
        { label: 'Protected Area Management and Development', children: [{ label: 'Homestay', href: '/conservation-reports/homestay' }] },
        { label: 'CDS-SMART MONITORING' },
    ];
    const modules = [
        { label: 'Local Module', href: 'http://cds-smart/conservation-reports/local_module', program_area: protectedArea },
        { label: 'Local Module Alias', href: '/conservation-reports/local_module', program_area: protectedArea },
    ];
    const merged = withGenericModuleNavigation(navigation, modules);
    const reports = merged[0].children;

    assert.deepEqual(reports.map(item => item.href), ['/conservation-reports/homestay', 'http://cds-smart/conservation-reports/local_module']);
});

test('Archives is a single permission-filtered global sibling after Submission Tracking and stays active for selected records', () => {
    const navigation = [
        { label: 'CDS-SMART MONITORING', heading: true, section: 'BOTH' },
        { label: 'Reports', href: '/reports', section: 'BOTH' },
        { label: 'Submission Tracking', children: [{ label: 'Incoming', href: '/submission-tracking?view=incoming', exact: true }] },
        finalArchivesNavigationItem,
        { label: 'Alerts', href: '/compliance-alerts', section: 'CDS' },
    ];
    const visible = buildSidebarNavigation(navigation, [], { canViewFinalArchives: true });
    const archiveItems = visible.filter(item => item.href === '/submission-tracking/archives');

    assert.equal(archiveItems.length, 1);
    assert.equal(archiveItems[0].label, 'Archives');
    assert.equal(visible.findIndex(item => item.href === '/submission-tracking/archives'), visible.findIndex(item => item.label === 'Submission Tracking') + 1);
    assert.equal(archiveItems[0].href, '/submission-tracking/archives');
    assert.equal(matchesNavigationItem(archiveItems[0], '/submission-tracking/archives?source=bms&record=42'), true);
    assert.equal(matchesNavigationItem(archiveItems[0], '/submission-tracking?view=incoming'), false);
    assert.equal(buildSidebarNavigation(navigation, [], { canViewFinalArchives: false }).some(item => item.href === '/submission-tracking/archives'), false);

    const tracking = authenticatedLayout.indexOf("label: 'Submission Tracking'");
    const archives = authenticatedLayout.indexOf('finalArchivesNavigationItem,', tracking);
    const alerts = authenticatedLayout.indexOf("label: 'Alerts'", archives);
    assert.ok(tracking >= 0 && archives > tracking && alerts > archives, 'the shared main sidebar places Archives after Submission Tracking and before Alerts');
});

test('PAMO navigation has one global Archives destination and preserves its report links', () => {
    const navigation = buildSidebarNavigation([], [], {
        user: { user_category: 'PAMO' },
        canViewPamoReports: true,
        canViewSubmissionTracking: true,
        canViewFinalArchives: true,
    });
    const archives = navigation.filter(item => item.href === '/submission-tracking/archives' || item.href === '/pamo/archives');

    assert.deepEqual(archives.map(item => [item.label, item.href]), [
        ['Archives', '/submission-tracking/archives'],
    ]);
    assert.deepEqual(navigation
        .filter(item => ['Reports', 'Sent Reports', 'Completed Reports'].includes(item.label))
        .map(item => [item.label, item.href]), [
        ['Reports', '/pamo/reports'],
        ['Sent Reports', '/submission-tracking?view=outgoing'],
        ['Completed Reports', '/submission-tracking?view=history'],
    ]);

    const unauthorized = buildSidebarNavigation([], [], {
        user: { user_category: 'PAMO' },
        canViewPamoReports: true,
        canViewSubmissionTracking: true,
        canViewFinalArchives: false,
    });
    assert.equal(unauthorized.some(item => item.href === '/submission-tracking/archives' || item.href === '/pamo/archives'), false);
});
