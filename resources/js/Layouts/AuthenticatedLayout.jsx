import { Head, Link, router, usePage } from '@inertiajs/react';
import { Icon as IconifyIcon } from '@iconify/react';
import { useEffect, useRef, useState } from 'react';
import GlobalSearch from '../Components/GlobalSearch';
import FlashSuccessDialog from '../Components/FlashSuccessDialog';
import Tooltip from '../Components/Tooltip';
import NotificationBell from '../Components/Notifications/NotificationBell';

const allNavigation = [
    { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', section: 'BOTH' },

    { label: 'Conservation Unit', heading: true, section: 'CDS' },
    {
        label: 'Manual Operation',
        icon: 'manual',
        section: 'CDS',
        children: [
            { label: 'PA Profiles & Baselines', href: '/protected-areas', permission: 'canViewProtectedAreas' },
        ]
    },
    {
        label: 'Protected Area Management and Development',
        icon: 'protected-area',
        section: 'CDS',
        children: [
            { label: 'Homestay', href: '/conservation-reports/homestay', permission: 'canViewTechnicalReports' },
            { label: 'Regular PAMB Meetings', href: '/conservation-reports/regular_pamb', permission: 'canViewTechnicalReports' },
            { label: 'Special PAMB Meetings', href: '/conservation-reports/special_pamb', permission: 'canViewTechnicalReports' },
            { label: 'Maintenance of Monuments', href: '/conservation-reports/maintenance_monuments', permission: 'canViewTechnicalReports' },
            { label: 'Maintenance of Buoy', href: '/conservation-reports/maintenance_buoy', permission: 'canViewTechnicalReports' },
            { label: 'TWC Meetings', href: '/conservation-reports/twc_meetings', permission: 'canViewTechnicalReports' },
            { label: 'Updating of PAMP', href: '/conservation-reports/updating_pamp', permission: 'canViewTechnicalReports' },
            { label: 'BMS', href: '/bms?tracker=1', permission: 'canViewBms', activeQuery: { tracker: '1' } },
            { label: 'BAMS', href: '/bams/report-submissions', permission: 'canViewBams' },
            { label: '5 Year Restoration Plan Preparation', href: '/conservation-reports/restoration_plan_5_year', permission: 'canViewTechnicalReports' },
            { label: 'Additional BMS Site', href: '/conservation-reports/additional_bms_site', permission: 'canViewTechnicalReports' },
            { label: 'CEPA Plan', href: '/conservation-reports/cepa_plan', permission: 'canViewTechnicalReports' },
            { label: 'Vertical Take off and Landing Operations', href: '/conservation-reports/vtol_operations', permission: 'canViewTechnicalReports' },
            { label: 'Automated Weather Station', href: '/aws', permission: 'canViewAws', activeQuery: { tab: null } },
            { label: 'BDFE for Terrestrial PAs', href: '/conservation-reports/bdfe_terrestrial', permission: 'canViewTechnicalReports' },
            { label: 'BDFAPs in PAs', href: '/conservation-reports/bdfap', permission: 'canViewTechnicalReports' },
            { label: 'Maintenance of PAMO or Ecotourism', href: '/conservation-reports/maintenance_pamo_ecotourism', permission: 'canViewTechnicalReports' },
            { label: 'IMEA', href: '/imea/report-submissions', permission: 'canViewImea' },
            { label: 'Management of IPAF', href: '/ipaf?ipaf_tab=management', permission: 'canViewTechnicalReports', activeQuery: { ipaf_tab: 'management' } },
            { label: 'Rehabilitation of PA Office', href: '/conservation-reports/rehabilitation_pa_office', permission: 'canViewTechnicalReports' },
            { label: 'Ecotourism Management Plan', href: '/conservation-reports/ecotourism_management_plan', permission: 'canViewTechnicalReports' },
            { label: 'Updating of PAMB Manual Operations', href: '/conservation-reports/updating_pamb_manual', permission: 'canViewTechnicalReports' },
            { label: 'Management Effectiveness Assessment', href: '/conservation-reports/management_effectiveness_assessment', permission: 'canViewTechnicalReports' },
            { label: 'Maintenance of PA Information System', href: '/conservation-reports/maintenance_pa_information_system', permission: 'canViewTechnicalReports' },
            { label: 'Monitoring Mangroves, Corals, Seagrass', href: '/conservation-reports/monitoring_mangroves_corals_seagrass', permission: 'canViewTechnicalReports' },
            { label: 'Revenue Collection', href: '/ipaf?ipaf_tab=revenue', permission: 'canViewTechnicalReports', activeQuery: { ipaf_tab: 'revenue' } },
            { label: 'Water Quality Monitoring within PA', href: '/conservation-reports/water_quality_monitoring', permission: 'canViewTechnicalReports' },
            { label: 'MPAN', href: '/conservation-reports/mpan', permission: 'canViewTechnicalReports' },
        ]
    },
    { label: 'Wildlife Conservation and Protection', icon: 'wildlife', groupOnly: true, section: 'CDS' },
    {
        label: 'Conservation Database',
        icon: 'database',
        section: 'CDS',
        children: [
            { label: 'BMS Data', href: '/bms', permission: 'canViewBms', activeQuery: { tracker: null } },
            { label: 'BAMS Data', href: '/bams', permission: 'canViewBams' },
            { label: 'IMEA Data', href: '/imea', permission: 'canViewImea' },
            { label: 'AWS Data', href: '/aws?tab=raw-data', permission: 'canViewAws', activeQueryAny: [{ tab: 'raw-data' }, { tab: 'analytics' }] },
        ]
    },

    { label: 'Development Unit', heading: true, section: 'CDS' },
    {
        label: 'National Greening Program',
        icon: 'sprout',
        section: 'CDS',
        children: [
            { label: 'ENGP IAC Generator', externalConfig: 'engpIacGeneratorUrl' },
            {
                label: 'Report Submission Monitoring',
                heading: true,
                children: [
                    {
                        label: 'Monthly Reports',
                        children: [
                            { label: 'CBEP', href: '/engp-reports/cbep', permission: 'canViewTechnicalReports' },
                            { label: 'ELCAC', href: '/engp-reports/elcac', permission: 'canViewTechnicalReports' },
                            { label: 'NGP Staff Monthly Accomplishment', href: '/engp-reports/ngp_staff_accomplishment', permission: 'canViewTechnicalReports' },
                            { label: 'Forest Disturbance', href: '/engp-reports/forest_disturbance', permission: 'canViewTechnicalReports' },
                            { label: 'Monthly Accomplishment Reports using PMD and FMB Template', href: '/engp-reports/monthly_accomplishment_pmd_fmb', permission: 'canViewTechnicalReports' },
                            { label: 'CENRO Nursery Seedling Production and Disposition', href: '/engp-reports/cenro_nursery_seedling', permission: 'canViewTechnicalReports' },
                            { label: 'Tree Replacement', href: '/engp-reports/tree_replacement', permission: 'canViewTechnicalReports' },
                            { label: 'RIMS', href: '/engp-reports/rims', permission: 'canViewTechnicalReports' },
                        ],
                    },
                    {
                        label: 'Quarterly Reports',
                        children: [
                            { label: 'NGP Produce', href: '/engp-reports/ngp_produce', permission: 'canViewTechnicalReports' },
                            { label: 'P/CENRO Nursery Maintenance', href: '/engp-reports/nursery_maintenance', permission: 'canViewTechnicalReports' },
                            { label: 'Site Visit', href: '/engp-reports/site_visit', permission: 'canViewTechnicalReports' },
                        ],
                    },
                    {
                        label: 'Weekly Reports',
                        children: [
                            { label: 'ENGP Weekly Accomplishment', href: '/engp-reports/weekly_accomplishment', permission: 'canViewTechnicalReports' },
                        ],
                    },
                    { label: 'Summary Monitoring', href: '/engp-reports/summary', permission: 'canViewTechnicalReports' },
                ],
            },
        ]
    },
    { label: 'Community-Based Forest Management', href: '#', icon: 'forest', comingSoon: true, section: 'CDS' },
    { label: 'Integrated Watershed Management', href: '#', icon: 'watershed', comingSoon: true, section: 'CDS' },

    { label: 'eDATS MONITORING', heading: true, section: 'BOTH' },
    { label: 'Submission Tracking', href: '/submission-tracking', icon: 'submission-tracking', permission: 'canViewReports', section: 'CDS' },
    { label: 'Alerts', href: '/compliance-alerts', icon: 'alerts', permission: 'canViewComplianceAlerts', section: 'CDS' },
    { label: 'Calendar', href: '/admin/business-calendar', icon: 'calendar', permission: 'canViewReports', section: 'BOTH' },

    { label: 'Administration', heading: true, permission: 'canManageAdministration', section: 'BOTH' },
    { label: 'User Management', href: '/admin/users', icon: 'users', permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Audit Logs', href: '#', icon: 'audit', comingSoon: true, permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Recipient Mapping', href: '/admin/recipient-mapping', icon: 'recipient-map', permission: 'canManageComplianceAlerts', section: 'BOTH' },
    { label: 'Settings', href: '/settings', icon: 'settings', permission: 'canManageUsers', section: 'BOTH' },
];

const fluentSidebarIcons = {
    dashboard: 'fluent-color:apps-32',
    manual: 'fluent-color:book-32',
    'protected-area': 'fluent-emoji-flat:mountain',
    database: 'fluent-color:database-32',
    wildlife: 'fluent-color:animal-paw-print-32',
    sprout: 'fluent-emoji-flat:seedling',
    forest: 'fluent-emoji-flat:evergreen-tree',
    watershed: 'fluent-emoji-flat:droplet',
    'submission-tracking': 'fluent-color:clipboard-task-24',
    alerts: 'fluent-color:alert-32',
    users: 'fluent-color:people-32',
    audit: 'fluent-color:clipboard-text-edit-32',
    'shield-alert': 'fluent-color:shield-checkmark-24',
    'recipient-map': 'fluent-color:pin-32',
    calendar: 'fluent-emoji-flat:calendar',
    settings: 'fluent-color:settings-32',
    projects: 'fluent-color:briefcase-32',
    inspection: 'fluent-color:clipboard-checkmark-32',
    activities: 'fluent-color:document-bullet-list-32',
    photos: 'fluent-color:image-32',
    reports: 'fluent-color:document-32',
};

function Icon({ name, className = 'h-6 w-6 shrink-0' }) {
    const icons = {
        dashboard: <><rect x="3" y="3" width="8" height="8" rx="2" fill="#38BDF8" /><rect x="13" y="3" width="8" height="8" rx="2" fill="#60A5FA" /><rect x="3" y="13" width="8" height="8" rx="2" fill="#34D399" /><rect x="13" y="13" width="8" height="8" rx="2" fill="#22C55E" /></>,
        manual: <><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H12v16H6.5A2.5 2.5 0 0 0 4 21V5.5Z" fill="#60A5FA" /><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H12v16h5.5A2.5 2.5 0 0 1 20 21V5.5Z" fill="#34D399" /><path d="M7 7h2.5M14.5 7H17M7 10h2.5M14.5 10H17" stroke="#fff" strokeWidth="1.4" strokeLinecap="round" /></>,
        'protected-area': <><path d="M12 2.5 20 5v6.4c0 4.9-3.3 8.2-8 10.1-4.7-1.9-8-5.2-8-10.1V5l8-2.5Z" fill="#34D399" /><path d="m6.5 15 3.2-4 2.2 2.4 2.9-4.4 2.7 6H6.5Z" fill="#2563EB" /><circle cx="16.7" cy="7.7" r="1.5" fill="#FDE047" /></>,
        database: <><ellipse cx="12" cy="5.2" rx="7.5" ry="3.2" fill="#818CF8" /><path d="M4.5 5.2v6.8c0 1.8 3.4 3.2 7.5 3.2s7.5-1.4 7.5-3.2V5.2" fill="#6366F1" /><path d="M4.5 12v6.8C4.5 20.6 7.9 22 12 22s7.5-1.4 7.5-3.2V12" fill="#38BDF8" /><path d="M7.5 5.2c1.2.7 2.8 1 4.5 1s3.3-.3 4.5-1" stroke="#E0E7FF" strokeWidth="1.2" fill="none" /></>,
        wildlife: <><circle cx="7" cy="9" r="2.3" fill="#F59E0B" /><circle cx="12" cy="5.8" r="2.3" fill="#FBBF24" /><circle cx="17" cy="9" r="2.3" fill="#FB923C" /><path d="M12 11.2c-3.7 0-6 3.1-6 5.9 0 2.3 2.1 3.6 4.1 2.5.7-.4 1.2-.4 1.9 0 2 1.1 4.1-.2 4.1-2.5 0-2.8-2.3-5.9-6.1-5.9Z" fill="#F97316" /></>,
        sprout: <><path d="M12 21v-7" stroke="#16A34A" strokeWidth="2" strokeLinecap="round" /><path d="M11.8 14C7.2 14 5 11.2 5 7c4.5 0 6.8 2.7 6.8 7Z" fill="#4ADE80" /><path d="M12.2 14C16.8 14 19 11.2 19 7c-4.5 0-6.8 2.7-6.8 7Z" fill="#22C55E" /><path d="M12 15.5c1.8-1.6 3-3.9 3.3-6.4M12 15.5C10.2 13.9 9 11.6 8.7 9.1" stroke="#15803D" strokeWidth="1" strokeLinecap="round" /></>,
        forest: <><path d="m4 20 5-11 5 11H4Z" fill="#22C55E" /><path d="m10 20 5-15 5 15H10Z" fill="#16A34A" /><path d="M3 20h18" stroke="#86EFAC" strokeWidth="1.5" strokeLinecap="round" /><path d="M9 20v-3M15 20v-4" stroke="#854D0E" strokeWidth="1.5" strokeLinecap="round" /></>,
        watershed: <><path d="M12 2.5S5.5 10.1 5.5 15a6.5 6.5 0 1 0 13 0c0-4.9-6.5-12.5-6.5-12.5Z" fill="#38BDF8" /><path d="M8.5 15.5c1.2-1.5 2.6-2.3 4.3-2.3 1.2 0 2.2.4 3.2 1.2" stroke="#E0F2FE" strokeWidth="1.5" strokeLinecap="round" /><path d="M16.5 5.5c2.2.4 3.7 1.8 4.2 4.2-2.4-.4-3.8-1.8-4.2-4.2Z" fill="#4ADE80" /></>,
        users: <><circle cx="9" cy="8" r="3.3" fill="#38BDF8" /><circle cx="17" cy="9" r="2.5" fill="#34D399" /><path d="M3.5 20c.4-4 2.7-6.2 5.5-6.2s5.1 2.2 5.5 6.2H3.5Z" fill="#2563EB" /><path d="M14 19.5c.3-3 2-4.8 4.2-4.8 1.6 0 2.8.8 3.3 2.1V20H14v-.5Z" fill="#16A34A" /></>,
        audit: <><path d="M6 2.8h8.1L19 7.7V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20V4.3A1.5 1.5 0 0 1 6.5 2.8H6Z" fill="#818CF8" /><path d="M14 2.8v5h5" fill="#C4B5FD" /><path d="M8.3 12h7.4M8.3 15.2h5" stroke="#fff" strokeWidth="1.5" strokeLinecap="round" /><circle cx="17.2" cy="17.2" r="3.3" fill="#2DD4BF" /><path d="M17.2 15.6v1.8l1.2.7" stroke="#fff" strokeWidth="1.2" strokeLinecap="round" /></>,
        'shield-alert': <><path d="M12 2.5 20 5v6.4c0 4.9-3.3 8.2-8 10.1-4.7-1.9-8-5.2-8-10.1V5l8-2.5Z" fill="#22C55E" /><path d="M12 7.2v5.5M12 16.2h.01" stroke="#FEF08A" strokeWidth="2" strokeLinecap="round" /><path d="m15.6 16.4 1.3 1.3 2.5-3" stroke="#fff" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round" /></>,
        'recipient-map': <><path d="M3.5 6.2 9 3.8l6 2.4 5.5-2.4v14L15 20.2l-6-2.4-5.5 2.4v-14Z" fill="#60A5FA" /><path d="M9 3.8v14M15 6.2v14" stroke="#fff" strokeWidth="1.1" opacity=".85" /><path d="M15 7.5a3.2 3.2 0 0 0-3.2 3.2c0 2.4 3.2 5.7 3.2 5.7s3.2-3.3 3.2-5.7A3.2 3.2 0 0 0 15 7.5Z" fill="#F87171" /><circle cx="15" cy="10.7" r="1.1" fill="#fff" /></>,
        calendar: <><rect x="3.5" y="5" width="17" height="15.5" rx="2.5" fill="#60A5FA" /><path d="M3.5 9.5h17" stroke="#fff" strokeWidth="1.5" /><path d="M7.5 3v4M16.5 3v4" stroke="#F87171" strokeWidth="2" strokeLinecap="round" /><rect x="7" y="12.5" width="3" height="3" rx=".7" fill="#FDE047" /><rect x="12" y="12.5" width="3" height="3" rx=".7" fill="#fff" /><rect x="17" y="12.5" width="1.5" height="3" rx=".7" fill="#BFDBFE" /></>,
        settings: <><path d="m12 3 1.3 2.1 2.4.4.8 2.3 2.1 1.3-.7 2.3.7 2.3-2.1 1.3-.8 2.3-2.4.4L12 21l-1.3-2.1-2.4-.4-.8-2.3-2.1-1.3.7-2.3-.7-2.3 2.1-1.3.8-2.3 2.4-.4L12 3Z" fill="#60A5FA" /><circle cx="12" cy="12" r="3.1" fill="#E0F2FE" /><circle cx="12" cy="12" r="1.5" fill="#475569" /></>,
    };

    return <IconifyIcon icon={fluentSidebarIcons[name] || fluentSidebarIcons.dashboard} width="24" height="24" className={className} aria-hidden="true" />;
}

function DisclosureIcon({ expanded, className = 'h-4 w-4' }) {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={`${className} transition-transform duration-200 ${expanded ? 'rotate-90' : ''}`} aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>;
}

function MenuIcon({ name }) {
    return <span className="flex h-7 w-7 shrink-0 items-center justify-center"><Icon name={name} /></span>;
}

function SubmenuIcon({ name }) {
    return name ? <span className="flex h-5 w-5 shrink-0 items-center justify-center"><IconifyIcon icon={fluentSubmenuIcons[name]} width="19" height="19" className="shrink-0" aria-hidden="true" /></span> : null;
}

function matchesNavigationItem(item, url) {
    if (item.children?.length) return item.children.some((child) => matchesNavigationItem(child, url));
    if (!item.href || item.href === '#') return false;
    const [targetPath, targetQuery = ''] = item.href.split('?');
    const currentPath = url.split('?')[0];
    if (!(item.exact ? currentPath === targetPath : (currentPath === targetPath || currentPath.startsWith(targetPath + '/')))) return false;
    const currentParams = new URLSearchParams(url.split('?')[1] || '');
    const targetParams = new URLSearchParams(targetQuery);
    for (const [key, value] of targetParams.entries()) {
        if (currentParams.get(key) !== value && !item.activeQueryAny?.some(query => currentParams.get(key) === query[key])) return false;
    }
    if (item.activeQueryAny?.length) return item.activeQueryAny.some(query => Object.entries(query).every(([key, value]) => currentParams.get(key) === value));
    return Object.entries(item.activeQuery || {}).every(([key, value]) => value === null ? !currentParams.has(key) : currentParams.get(key) === value);
}

function Sidebar({ open, onClose, auth, engpIacGeneratorUrl }) {
    const { url } = usePage();
    const safeAuth = auth || {};
    const userSection = auth?.user?.section || 'CDS';
    const [openDropdowns, setOpenDropdowns] = useState({});
    const navigationRef = useRef(null);
    const navigation = allNavigation;

    const toggleDropdown = (label) => {
        setOpenDropdowns((prev) => ({
            ...prev,
            [label]: !prev[label]
        }));
    };

    useEffect(() => {
        const activeGroups = {};
        const visit = (items) => items.forEach((item) => {
            if (!item.children?.length) return;
            if (matchesNavigationItem(item, url)) activeGroups[item.label] = true;
            visit(item.children);
        });
        visit(navigation);
        setOpenDropdowns((prev) => ({ ...prev, ...activeGroups }));
    }, [url]);

    useEffect(() => {
        const navigationElement = navigationRef.current;
        if (!navigationElement || !window.matchMedia('(min-width: 1024px)').matches) return undefined;

        const storageKey = 'edats-sidebar-scroll-position';
        let restoreFrame = null;
        try {
            const savedPosition = window.sessionStorage.getItem(storageKey);
            if (savedPosition !== null) {
                restoreFrame = window.requestAnimationFrame(() => {
                    navigationElement.scrollTop = Number(savedPosition) || 0;
                });
            }
        } catch {
            // Some privacy modes can deny sessionStorage; the sidebar still works normally.
        }
        const rememberPosition = () => {
            try {
                window.sessionStorage.setItem(storageKey, String(navigationElement.scrollTop));
            } catch {
                // Scroll preservation is optional browser UI state.
            }
        };
        const captureNavigationPosition = () => {
            rememberPosition();
        };

        navigationElement.addEventListener('scroll', rememberPosition, { passive: true });
        const stopNavigationCapture = router.on('start', captureNavigationPosition);
        return () => {
            if (restoreFrame !== null) window.cancelAnimationFrame(restoreFrame);
            navigationElement.removeEventListener('scroll', rememberPosition);
            stopNavigationCapture();
        };
    }, []);

    const logoSrc = "/images/DENR LOGO.png";
    const systemTitle = "eDATS-CDS";
    const systemSubtitle = "Enhanced Digital Alert and Tracking System";

    const filteredNavigation = navigation.filter(item =>
        item.section === 'BOTH' || item.section === userSection
    );

    const renderChildren = (children, depth = 0, engpContext = false) => children.map((child) => {
        if (child.permission && !safeAuth[child.permission]) return null;

        const isChildActive = matchesNavigationItem(child, url);
        const isOpen = openDropdowns[child.label];
        const nested = child.children?.length > 0;

        if (child.heading && child.staticHeading) {
            return (
                <div key={child.label} className="pt-2">
                    <p className="px-3 pb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-green-300/80">{child.label}</p>
                    <div className="space-y-1 border-l border-green-300/20 pl-2">{renderChildren(child.children, depth + 1, engpContext)}</div>
                </div>
            );
        }

        if (nested) {
            return (
                <div key={child.label} className="rounded-lg bg-green-950/15">
                    <button
                        type="button"
                        onClick={() => toggleDropdown(child.label)}
                        aria-expanded={isOpen}
                        className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-[11px] font-bold uppercase tracking-[0.08em] ${isChildActive ? 'text-green-100' : 'text-green-200/80 hover:bg-white/10 hover:text-white'}`}
                    >
                        <span className="min-w-0 flex-1">{child.label}</span>
                        <DisclosureIcon expanded={isOpen} className="h-3.5 w-3.5 shrink-0 text-green-300" />
                    </button>
                    {isOpen && <div className="ml-2 space-y-1 border-l border-green-300/20 pl-2 pb-1">{renderChildren(child.children, depth + 1, engpContext)}</div>}
                </div>
            );
        }

        const childCommon = `${engpContext ? 'flex w-full items-start gap-2.5 rounded-lg px-3 py-2 text-xs font-semibold transition' : 'block w-full rounded-xl px-4 py-2.5 text-xs font-semibold transition'} ${
            isChildActive ? 'bg-green-600 text-white shadow-md' : 'text-green-100 hover:bg-white/10 hover:text-white'
        }`;
        const href = child.externalConfig === 'engpIacGeneratorUrl' ? engpIacGeneratorUrl : child.href;

        if (child.externalConfig && !href) {
            return (
                <Tooltip key={child.label} content="External generator URL is not configured" className="block w-full">
                    <div className={`${childCommon} cursor-not-allowed opacity-60`}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5 shrink-0" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8" /><path d="M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
                        <span className="min-w-0 flex-1">{child.label}</span>
                    </div>
                </Tooltip>
            );
        }

        if (child.externalConfig) {
            return (
                <a key={child.label} href={href} target="_blank" rel="noopener noreferrer" className={childCommon}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5 shrink-0" aria-hidden="true"><path d="M14 5h5v5M19 5l-8 8" /><path d="M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
                    <span className="min-w-0 flex-1">{child.label}</span>
                </a>
            );
        }

        if (child.comingSoon) return (
            <Tooltip key={child.label} content="Coming Soon" className="block w-full"><div className={`${childCommon} cursor-not-allowed opacity-75 ${engpContext ? '' : 'flex items-center justify-between'}`}>
                <span className="min-w-0 flex-1">{child.label}</span>
                <span className="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-[9px] font-bold tracking-wide text-green-100">Coming Soon</span>
            </div></Tooltip>
        );

        return (
            <Link key={child.label} href={child.href} onClick={onClose} className={childCommon}>
                <span className="min-w-0 flex-1 whitespace-normal">{child.label}</span>
            </Link>
        );
    });

    return (
        <>
            <button type="button" className={`fixed inset-0 z-30 bg-gray-950/40 lg:hidden ${open ? '' : 'hidden'}`} onClick={onClose} aria-label="Close navigation" />
            <aside className={`fixed inset-y-0 left-0 z-40 flex w-72 flex-col border-r border-green-950/20 bg-green-900 text-white transition-transform duration-200 lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex h-20 items-center gap-3 border-b border-white/10 px-6">
                    <img src={logoSrc} alt="Department of Environment and Natural Resources logo" className="h-11 w-11 shrink-0 object-contain rounded-full bg-white p-0.5 shadow-sm" />
                    <div className="min-w-0 flex-1 leading-tight">
                        <p className="text-sm font-bold tracking-wide text-white">{systemTitle}</p>
                        <p className="mt-1 text-[10px] font-medium leading-4 text-green-100">{systemSubtitle}</p>
                        <p className="text-[9px] font-medium leading-3 text-green-200">Conservation and Development Section</p>
                    </div>
                    <button type="button" onClick={onClose} className="ml-auto rounded p-1 text-green-100 hover:bg-white/10 lg:hidden">x</button>
                </div>
                <nav ref={navigationRef} className="flex-1 overflow-y-auto px-3 py-5 space-y-1.5" aria-label="Main navigation">
                    {filteredNavigation.map((item) => {
                        if (item.permission && !safeAuth[item.permission]) return null;

                        if (item.heading) {
                            return <p key={item.label} className={`mt-5 px-3 text-[11px] font-bold ${item.label === 'eDATS MONITORING' ? 'normal-case' : 'uppercase'} tracking-[0.12em] text-green-300 first:mt-0`}>{item.label}</p>;
                        }

                        if (item.groupOnly) {
                            return <div key={item.label} className="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-green-100"><MenuIcon name={item.icon} /><span>{item.label}</span></div>;
                        }

                        if (item.children) {
                            const isOpen = openDropdowns[item.label];
                            return (
                                <div key={item.label} className="mt-1">
                                    <button
                                        onClick={() => toggleDropdown(item.label)}
                                        aria-expanded={isOpen}
                                        aria-label={`${item.label}: ${isOpen ? 'collapse' : 'expand'}`}
                                        className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition text-green-50 hover:bg-white/10 focus:outline-none"
                                    >
                                        <MenuIcon name={item.icon} />
                                        <span className="flex-1 text-left">{item.label}</span>
                                        <DisclosureIcon expanded={isOpen} className="h-4 w-4 text-green-300" />
                                    </button>

                                    {isOpen && <div className={`mt-1 space-y-1 ${item.label === 'National Greening Program' ? 'rounded-lg bg-green-950/10 p-2 pl-3 pr-1' : 'pl-3 pr-1'}`}>{renderChildren(item.children, 0, item.label === 'National Greening Program')}</div>}
                                </div>
                            );
                        }

                        const active = matchesNavigationItem(item, url);

                        const common = `flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                            active
                                ? 'bg-green-600 text-white shadow-md font-semibold'
                                : 'text-green-50 hover:bg-white/10'
                        }`;

                        if (item.comingSoon) return (
                            <Tooltip key={item.label} content="Coming Soon" className="block w-full"><div className={`${common} cursor-not-allowed opacity-75`}>
                                <MenuIcon name={item.icon} />
                                <span className="flex-1 text-left">{item.label}</span>
                                <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[10px] font-semibold tracking-wide text-green-100">Coming Soon</span>
                            </div></Tooltip>
                        );
                        return (
                            <Link key={item.label} href={item.href} onClick={onClose} className={common}>
                                <MenuIcon name={item.icon} />
                                <span>{item.label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="border-t border-white/10 p-4 text-xs leading-5 text-green-200">Enhanced Digital Alert and Tracking System<br />Conservation and Development Section</div>
            </aside>
        </>
    );
}

export function AuthenticatedShell({ children }) {
    const { auth, engpIacGeneratorUrl, notificationBell } = usePage().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const [darkMode, setDarkMode] = useState(() => {
        if (typeof window === 'undefined') return false;
        const savedTheme = window.localStorage.getItem('theme');
        return savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
    });
    const [mobileSearchOpen, setMobileSearchOpen] = useState(false);

    const userName = auth?.user?.name || 'User';
    const initials = userName.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();

    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
    }, [darkMode]);

    useEffect(() => {
        const stopNavigationCleanup = router.on('navigate', () => {
            setProfileOpen(false);
            setSidebarOpen(false);
            setMobileSearchOpen(false);
        });

        return () => stopNavigationCleanup();
    }, []);

    const toggleTheme = () => {
        const next = !darkMode;
        setDarkMode(next);
        document.documentElement.classList.toggle('dark', next);
        window.localStorage.setItem('theme', next ? 'dark' : 'light');
    };

    return (
        <>
            <FlashSuccessDialog />
            <div className="min-h-screen bg-gray-100 dark:bg-gray-950">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} auth={auth} engpIacGeneratorUrl={engpIacGeneratorUrl} />
                <div className="lg:pl-72">
                    <header className="sticky top-0 z-30 flex h-20 items-center gap-3 border-b border-gray-200 bg-white px-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:px-6 lg:px-8">
                        <button type="button" onClick={() => setSidebarOpen(true)} className="rounded-lg p-2 text-gray-600 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-green-600/40 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden" aria-label="Open navigation">
                            <IconifyIcon icon="solar:hamburger-menu-linear" width="22" height="22" aria-hidden="true" />
                        </button>

                        <div className="hidden flex-1 md:block"><GlobalSearch /></div>

                        <div className="ml-auto flex items-center gap-1.5 sm:gap-3">
                            <button type="button" onClick={() => setMobileSearchOpen(open => !open)} className="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-600/40 dark:text-gray-300 dark:hover:bg-gray-800 md:hidden" aria-label="Search eDATS" title="Search eDATS">
                                <IconifyIcon icon="solar:magnifer-linear" width="20" height="20" aria-hidden="true" />
                            </button>
                            <button type="button" onClick={toggleTheme} className="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-600/40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" aria-label={darkMode ? 'Switch to light mode' : 'Switch to dark mode'} title={darkMode ? 'Switch to light mode' : 'Switch to dark mode'}>
                                <IconifyIcon icon={darkMode ? 'solar:sun-2-linear' : 'solar:moon-linear'} width="19" height="19" aria-hidden="true" />
                            </button>
                            <NotificationBell initial={notificationBell} />
                            <div className="relative">
                                <button type="button" onClick={() => setProfileOpen((open) => !open)} className="flex items-center gap-3 rounded-lg p-1.5 text-left hover:bg-gray-100 dark:hover:bg-gray-800">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-green-100 text-sm font-bold text-green-800 dark:bg-green-950 dark:text-green-300">{initials}</span>
                                    <span className="hidden sm:block">
                                        <span className="block text-sm font-semibold text-gray-800 dark:text-white">{userName}</span>
                                        <span className="block text-xs text-gray-500 dark:text-gray-400">My account</span>
                                    </span>
                                </button>
                                {profileOpen && (
                                    <div className="absolute right-0 z-50 mt-2 w-52 rounded-lg border border-gray-200 bg-white p-1 shadow-2xl dark:border-gray-700 dark:bg-gray-900" role="menu">
                                        <Link href="/profile" className="block rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800">Profile settings</Link>
                                        <form action="/logout" method="POST" className="w-full">
                                            <button type="submit" className="block w-full rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950">
                                                Log out
                                            </button>
                                        </form>
                                    </div>
                                )}
                            </div>
                        </div>
                    </header>
                    {mobileSearchOpen && <div className="border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900 md:hidden"><GlobalSearch onNavigate={() => setMobileSearchOpen(false)} /></div>}
                    <main className="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{children}</main>
                </div>
            </div>
        </>
    );
}

export default function AuthenticatedLayout({ title, children }) {
    return <><Head title={title} />{children}</>;
}
