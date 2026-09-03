import { Head, Link, router, usePage } from '@inertiajs/react';
import { Icon as IconifyIcon } from '@iconify/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import GlobalSearch from '../Components/GlobalSearch';
import FlashSuccessDialog from '../Components/FlashSuccessDialog';
import Tooltip from '../Components/Tooltip';
import NotificationBell from '../Components/Notifications/NotificationBell';

const allNavigation = [
    { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', section: 'BOTH' },

    { label: 'Conservation Unit', heading: true, section: 'CDS', unit: 'conservation' },
    {
        label: 'Manual Operation',
        icon: 'manual',
        section: 'CDS',
        unit: 'conservation',
        children: [
            { label: 'PA Profiles & Baselines', href: '/protected-areas', permission: 'canViewProtectedAreas' },
        ]
    },
    {
        label: 'Protected Area Management and Development',
        icon: 'protected-area',
        section: 'CDS',
        unit: 'conservation',
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
    { label: 'Wildlife Conservation and Protection', icon: 'wildlife', groupOnly: true, section: 'CDS', unit: 'conservation' },
    {
        label: 'Conservation Database',
        icon: 'database',
        section: 'CDS',
        unit: 'conservation',
        children: [
            { label: 'BMS Data', href: '/bms', permission: 'canViewBms', activeQuery: { tracker: null } },
            { label: 'BAMS Data', href: '/bams', permission: 'canViewBams' },
            { label: 'IMEA Data', href: '/imea', permission: 'canViewImea' },
            { label: 'AWS Data', href: '/aws?tab=raw-data', permission: 'canViewAws', activeQueryAny: [{ tab: 'raw-data' }, { tab: 'analytics' }] },
        ]
    },

    { label: 'Development Unit', heading: true, section: 'CDS', unit: 'development' },
    {
        label: 'National Greening Program',
        icon: 'sprout',
        section: 'CDS',
        unit: 'development',
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
    { label: 'Community-Based Forest Management', href: '#', icon: 'forest', comingSoon: true, section: 'CDS', unit: 'development' },
    { label: 'Integrated Watershed Management', href: '#', icon: 'watershed', comingSoon: true, section: 'CDS', unit: 'development' },

    { label: 'eDATS MONITORING', heading: true, section: 'BOTH' },
    { label: 'Submission Tracking', href: '/submission-tracking', icon: 'submission-tracking', permission: 'canViewReports', section: 'CDS' },
    { label: 'Alerts', href: '/compliance-alerts', icon: 'alerts', permission: 'canViewComplianceAlerts', section: 'CDS' },
    { label: 'Calendar', href: '/admin/business-calendar', icon: 'calendar', permission: 'canViewReports', section: 'BOTH' },

    { label: 'Administration', heading: true, permission: 'canManageAdministration', section: 'BOTH' },
    { label: 'User Management', href: '/admin/users', icon: 'users', permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Audit Logs', href: '/admin/audit-logs', icon: 'audit', permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Recipient Mapping', href: '/admin/recipient-mapping', icon: 'recipient-map', permission: 'canManageComplianceAlerts', section: 'BOTH' },
    { label: 'Settings', href: '/settings', icon: 'settings', permission: 'canManageUsers', section: 'BOTH' },
];

const lucideSidebarIcons = {
    dashboard: 'lucide:layout-dashboard',
    manual: 'lucide:clipboard-list',
    'protected-area': 'lucide:map-pinned',
    database: 'lucide:database',
    wildlife: 'lucide:paw-print',
    sprout: 'lucide:sprout',
    forest: 'lucide:trees',
    watershed: 'lucide:waves',
    'submission-tracking': 'lucide:clipboard-check',
    alerts: 'lucide:triangle-alert',
    users: 'lucide:users',
    audit: 'lucide:history',
    'recipient-map': 'lucide:network',
    calendar: 'lucide:calendar-days',
    settings: 'lucide:settings',
    projects: 'lucide:briefcase',
    inspection: 'lucide:clipboard-check',
    activities: 'lucide:clipboard-list',
    photos: 'lucide:image',
    reports: 'lucide:file-text',
};

function Icon({ name, className = 'h-6 w-6 shrink-0' }) {
    return <IconifyIcon icon={lucideSidebarIcons[name] || lucideSidebarIcons.dashboard} width="18" height="18" className={className + ' sidebar-outline-icon'} aria-hidden="true" />;
}


function DisclosureIcon({ expanded, className = 'h-4 w-4' }) {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={`${className} transition-transform duration-200 ${expanded ? 'rotate-90' : ''}`} aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>;
}

function MenuIcon({ name, active = false, muted = false }) {
    return <span className={`flex h-7 w-7 shrink-0 items-center justify-center ${active ? 'opacity-95' : muted ? 'opacity-35' : 'opacity-55'}`}><Icon name={name} /></span>;
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

function withGenericModuleNavigation(navigation, modules) {
    if (!modules?.length) return navigation;

    const areas = {
        protected_area_management_and_development: { label: 'Protected Area Management and Development', icon: 'protected-area' },
        wildlife_conservation_and_protection: { label: 'Wildlife Conservation and Protection', icon: 'wildlife' },
        community_based_forest_management: { label: 'Community-Based Forest Management', icon: 'forest' },
        integrated_watershed_management: { label: 'Integrated Watershed Management', icon: 'watershed' },
        engp: { label: 'National Greening Program', icon: 'sprout' },
        conservation: { label: 'Conservation', icon: 'wildlife' },
        development: { label: 'Development', icon: 'sprout' },
    };
    const grouped = modules.reduce((result, module) => {
        (result[module.program_area] ||= []).push({ label: module.label, href: module.href, permission: 'canViewTechnicalReports' });
        return result;
    }, {});
    const represented = new Set();
    const merged = navigation.map(item => {
        const area = Object.entries(areas).find(([, value]) => value.label === item.label)?.[0];
        if (!area || !grouped[area]) return item;
        represented.add(area);
        return { ...item, groupOnly: false, comingSoon: false, children: [...(item.children || []), ...grouped[area]] };
    });

    Object.entries(grouped).forEach(([area, children]) => {
        if (represented.has(area)) return;
        const config = areas[area];
        if (config) merged.splice(merged.findIndex(item => item.label === 'eDATS MONITORING'), 0, { ...config, section: 'CDS', children });
    });

    return merged;
}

function filterNavigationByUnit(items, unit, inheritedUnit = null) {
    return items.map((item) => {
        const itemUnit = item.unit || inheritedUnit;
        if (unit && itemUnit && itemUnit !== unit) return null;
        const children = item.children ? filterNavigationByUnit(item.children, unit, itemUnit) : null;
        if (item.children && !children?.length) return null;
        return children ? { ...item, children } : item;
    }).filter(Boolean);
}

function Sidebar({ open, onClose, auth, engpIacGeneratorUrl, genericModuleNavigation = [] }) {
    const { url } = usePage();
    const safeAuth = auth || {};
    const userSection = auth?.user?.section || 'CDS';
    const userUnit = auth?.user?.unit_assignment || auth?.organizationalUnit || (userSection === 'ENGP' ? 'development' : null);
    const [openDropdowns, setOpenDropdowns] = useState({});
    const navigationRef = useRef(null);
    const navigation = useMemo(() => withGenericModuleNavigation(allNavigation, genericModuleNavigation), [genericModuleNavigation]);

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

    const filteredNavigation = filterNavigationByUnit(
        navigation.filter(item => !userUnit || item.section === 'BOTH' || item.section === 'CDS'),
        userUnit,
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
                            return <div key={item.label} className="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-green-100/80"><MenuIcon name={item.icon} muted /><span>{item.label}</span></div>;
                        }

                        if (item.children) {
                            const isOpen = openDropdowns[item.label];
                            return (
                                <div key={item.label} className="mt-1">
                                    <button
                                        onClick={() => toggleDropdown(item.label)}
                                        aria-expanded={isOpen}
                                        aria-label={`${item.label}: ${isOpen ? 'collapse' : 'expand'}`}
                                        className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition focus:outline-none ${matchesNavigationItem(item, url) ? 'bg-white/12 text-white' : 'text-green-100/85 hover:bg-white/8 hover:text-white'}`}
                                    >
                                        <MenuIcon name={item.icon} active={matchesNavigationItem(item, url)} />
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
                                ? 'bg-white/14 text-white font-semibold'
                                : 'text-green-100/85 hover:bg-white/8 hover:text-white'
                        }`;

                        if (item.comingSoon) return (
                            <Tooltip key={item.label} content="Coming Soon" className="block w-full"><div className={`${common} cursor-not-allowed opacity-75`}>
                                <MenuIcon name={item.icon} muted />
                                <span className="flex-1 text-left">{item.label}</span>
                                <span className="rounded-full bg-white/8 px-2.5 py-0.5 text-[10px] font-semibold tracking-wide text-green-100/70">Coming Soon</span>
                            </div></Tooltip>
                        );
                        return (
                            <Link key={item.label} href={item.href} onClick={onClose} className={common}>
                                <MenuIcon name={item.icon} active={active} />
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
    const { auth, engpIacGeneratorUrl, notificationBell, genericModuleNavigation } = usePage().props;
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
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} auth={auth} engpIacGeneratorUrl={engpIacGeneratorUrl} genericModuleNavigation={genericModuleNavigation} />
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
                                        <button type="button" onClick={() => router.post('/logout')} className="block w-full rounded-md px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950">
                                                Log out
                                        </button>
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
