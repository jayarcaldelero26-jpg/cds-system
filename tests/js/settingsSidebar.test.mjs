import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { resolveSettingsHref, settingsNavigationItem, settingsNavigationPermissions } from '../../resources/js/Layouts/settingsNavigation.mjs';

const layout = readFileSync(new URL('../../resources/js/Layouts/AuthenticatedLayout.jsx', import.meta.url), 'utf8');

test('main sidebar has one leaf Settings entry with the existing capability union', () => {
    assert.equal(settingsNavigationItem.label, 'Settings');
    assert.equal(settingsNavigationItem.activePrefix, '/settings');
    assert.deepEqual(settingsNavigationItem.permissionAny, settingsNavigationPermissions);
    assert.match(layout, /settingsNavigationItem,/);
    assert.doesNotMatch(layout, /Settings Overview/);
    assert.doesNotMatch(layout, /label: 'Historical Reports', href: '\/settings\/historical-reports'/);
});

test('Settings link lands on an authorized internal page, including historical-only access', () => {
    assert.equal(resolveSettingsHref({ canViewHistoricalReports: true }), '/settings/historical-reports');
    assert.equal(resolveSettingsHref({ canViewStorage: true }), '/settings/storage');
    assert.equal(resolveSettingsHref({ canViewModuleManagement: true }), '/settings/module-management');
    assert.equal(resolveSettingsHref({ canManageUsers: true, canViewHistoricalReports: true }), '/settings');
    assert.equal(resolveSettingsHref({}), '/settings');
});

test('historical reports remain inside the Settings shell with their own capability', () => {
    const shell = readFileSync(new URL('../../resources/js/Components/Admin/SettingsShell.jsx', import.meta.url), 'utf8');
    assert.match(shell, /title: 'Historical Reports'.*capability: 'canViewHistoricalReports'/);
});
