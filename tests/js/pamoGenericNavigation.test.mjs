import assert from 'node:assert/strict';
import test from 'node:test';
import { buildSidebarNavigation, filterNavigationByUnit, withGenericModuleNavigation } from '../../resources/js/Layouts/navigation.mjs';

const label = 'Protected Area Management and Development';
const href = key => `/conservation-reports/${key}`;
const staticNavigation = () => [
    { label: 'Conservation Unit', heading: true, unit: 'conservation', section: 'CDS' },
    { label, unit: 'conservation', section: 'CDS', children: [
        { label: 'Regular PAMB Meetings', href: href('regular_pamb'), permission: 'canViewPambWorkflow' },
        { label: 'Special PAMB Meetings', href: href('special_pamb'), permission: 'canViewPambWorkflow' },
        { label: 'BMS', href: '/bms?tracker=1', permission: 'canViewBms' },
    ] },
    { label: 'CDS-SMART MONITORING', heading: true, section: 'BOTH' },
];
const catalog = count => Array.from({ length: count }, (_, index) => ({
    key: `workflow_${index}`,
    program_area: 'protected_area_management_and_development',
    label: `Eligible workflow ${index + 1}`,
    href: href(`workflow_${index}`),
    display_order: index + 1,
}));
const pamo = { user: { user_category: 'PAMO' }, canViewPamoReports: true, canViewPambWorkflow: true, canBrowseConservationModules: false };

test('PAMO sidebar is rendered from the complete eligible catalog, replacing stale static routes', () => {
    const navigation = filterNavigationByUnit(withGenericModuleNavigation(staticNavigation(), catalog(22), pamo), 'conservation', null, pamo);
    const group = navigation.find(item => item.label === label);
    assert.ok(group);
    assert.equal(group.children.length, 22);
    assert.deepEqual(group.children.map(item => item.href), catalog(22).map(item => item.href));
    assert.ok(group.children.every(item => item.permissionAny?.includes('canViewPamoReports')));
    assert.ok(!JSON.stringify(navigation).includes('/bms'));
});

test('PAMO sidebar has no generic module group when no workflows are eligible', () => {
    const navigation = filterNavigationByUnit(withGenericModuleNavigation(staticNavigation(), [], pamo), 'conservation', null, pamo);
    assert.ok(!navigation.some(item => item.label === label));
});

test('native source links join PAMO catalog navigation only after server eligibility supplies them', () => {
    const native = [
        { key: 'bms', program_area: 'protected_area_management_and_development', label: 'BMS', href: '/pamo-native-reports/bms', display_order: 23 },
        { key: 'revenue_collection', program_area: 'protected_area_management_and_development', label: 'Revenue Collection', href: '/pamo-native-reports/revenue_collection', display_order: 24 },
    ];
    const navigation = filterNavigationByUnit(withGenericModuleNavigation(staticNavigation(), catalog(22), pamo, native), 'conservation', null, pamo);
    const group = navigation.find(item => item.label === label);
    assert.equal(group.children.length, 24);
    assert.deepEqual(group.children.slice(-2).map(item => item.href), native.map(item => item.href));
    assert.ok(group.children.slice(-2).every(item => item.permissionAny?.includes('canViewPamoReports')));
});

test('authenticated PAMO identity uses the workspace hub when a browse flag is also present', () => {
    const mixedFlags = { ...pamo, canBrowseConservationModules: true, canViewSubmissionTracking: true, canViewFinalArchives: true };
    const native = ['bms', 'bams', 'imea', 'imea_facility_maintenance', 'ipaf_management', 'revenue_collection']
        .map((key, index) => ({ key, program_area: 'protected_area_management_and_development', label: key, href: `/pamo-native-reports/${key}`, display_order: 23 + index }))
        .concat([{ key: 'automated_weather_station', program_area: 'conservation', label: 'Automated Weather Station', href: '/pamo-native-reports/automated_weather_station', display_order: 7 }]);
    const navigation = buildSidebarNavigation(staticNavigation(), catalog(22), mixedFlags, native, 'conservation');
    assert.ok(!navigation.some(item => item.label === label || item.label === 'Conservation'));
    assert.ok(navigation.some(item => item.label === 'Reports' && item.href === '/pamo/reports'));
    assert.ok(navigation.some(item => item.label === 'Archives' && item.href === '/submission-tracking/archives'));
    assert.ok(navigation.some(item => item.label === 'For Action' && item.href === '/submission-tracking?view=incoming'));
});

test('PAMO dashboard, generic pages, and native pages retain the same workspace sidebar', () => {
    const workflows = catalog(22);
    const authorizedPamo = { ...pamo, canViewFinalArchives: true };
    const native = ['bms', 'bams', 'imea', 'imea_facility_maintenance', 'ipaf_management', 'revenue_collection']
        .map((key, index) => ({ key, program_area: 'protected_area_management_and_development', label: key, href: `/pamo-native-reports/${key}`, display_order: 23 + index }))
        .concat([{ key: 'automated_weather_station', program_area: 'conservation', label: 'Automated Weather Station', href: '/pamo-native-reports/automated_weather_station', display_order: 7 }]);
    const urls = ['/dashboard', href('regular_pamb'), '/pamo-native-reports/automated_weather_station'];
    const models = urls.map(() => buildSidebarNavigation(staticNavigation(), workflows, authorizedPamo, native, 'conservation'));
    const hrefs = models.map(model => model.filter(item => item.href).map(item => item.href).sort());
    assert.deepEqual(hrefs[0], hrefs[1]);
    assert.deepEqual(hrefs[1], hrefs[2]);
    assert.ok(hrefs[0].includes('/pamo/reports'));
    assert.ok(hrefs[0].includes('/submission-tracking/archives'));
    assert.ok(!hrefs[0].includes('/pamo/archives'));
    assert.ok(!JSON.stringify(models).includes('/pamo-native-reports/'));
});

test('non-PAMO technical navigation keeps static entries and catalog links require technical-report access', () => {
    const auth = { canViewTechnicalReports: true, canViewBms: true, canBrowseConservationModules: true };
    const navigation = filterNavigationByUnit(withGenericModuleNavigation(staticNavigation(), catalog(1), auth), 'conservation', null, auth);
    const group = navigation.find(item => item.label === label);
    assert.ok(group.children.some(item => item.href === '/bms?tracker=1'));
    assert.ok(group.children.some(item => item.href === href('workflow_0') && item.permissionAny?.includes('canViewTechnicalReports')));
});

test('Super Admin keeps the existing static links and technical catalog permission', () => {
    const auth = { user: { user_category: 'SUPER_ADMIN' }, canViewTechnicalReports: true, canViewBms: true, canBrowseConservationModules: true };
    const navigation = filterNavigationByUnit(withGenericModuleNavigation(staticNavigation(), catalog(1), auth), 'conservation', null, auth);
    const group = navigation.find(item => item.label === label);
    assert.ok(group.children.some(item => item.href === '/bms?tracker=1'));
    assert.ok(group.children.some(item => item.href === href('workflow_0') && item.permissionAny?.includes('canViewTechnicalReports')));
});
