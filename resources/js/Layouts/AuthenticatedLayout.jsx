import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import GlobalSearch from '../Components/GlobalSearch';
import FlashSuccessDialog from '../Components/FlashSuccessDialog';
import Tooltip from '../Components/Tooltip';

const allNavigation = [
    { label: 'Dashboard', href: '/dashboard', icon: 'dashboard', section: 'BOTH' },

    // --- MANUAL OPERATION DROPDOWN ---
    {
        label: 'Manual Operation',
        icon: 'map',
        section: 'CDS',
        children: [
            { label: 'PA Profiles & Baselines', href: '/protected-areas', permission: 'canViewProtectedAreas' },
        ]
    },

    // --- MANAGEMENT PLAN DROPDOWN ---
    {
        label: 'Management Plan',
        icon: 'document',
        section: 'CDS',
        permission: 'canViewManagementPlans',
        dynamicManagementPlans: true,
    },

    // --- TECHNICAL REPORTS DROPDOWN ---
    {
        label: 'Technical Reports',
        icon: 'report',
        section: 'CDS',
        children: [
            { label: 'Biodiversity Monitoring System (BMS)', href: '/bms' },
            { label: 'Biodiversity Assessment (BAMS)', href: '/bams' },
            { label: 'IMEA', href: '/imea' },
            { label: 'Automated Weather Station (AWS)', href: '/aws' },
            { label: 'BDFE', href: '#', comingSoon: true },
            { label: 'IPAF', href: '/ipaf', permission: 'canViewTechnicalReports' },
            { label: 'All Technical Reports', href: '/technical-reports', permission: 'canViewTechnicalReports' },
        ]
    },

    // --- MEETINGS & RESOLUTIONS DROPDOWN ---
    {
        label: 'Meetings & Resolutions',
        icon: 'activity',
        section: 'CDS',
        children: [
            { label: 'PAMB Meeting Minutes', href: '#', comingSoon: true },
            { label: 'PAMB Meeting Resolution', href: '#', comingSoon: true },
            { label: 'TWC Meeting Minutes', href: '#', comingSoon: true },
            { label: 'TWC Resolution', href: '#', comingSoon: true },
            { label: 'Special PAMB Meeting Minutes', href: '#', comingSoon: true },
            { label: 'Special PAMB Meeting Resolution', href: '#', comingSoon: true },
        ]
    },

    { label: 'Programs, Projects & Activities', href: '#', icon: 'folder', comingSoon: true, permission: 'canViewPPA', section: 'CDS' },
    { label: 'CDS LAWIN Monitoring', href: '#', icon: 'shield', comingSoon: true, section: 'CDS' },

    // --- MGA MENU PARA SA MES ---
    { label: 'Issues Monitoring', href: '/issue-monitorings', icon: 'shield-alert', permission: 'canViewIssueMonitoring', section: 'MES' },
    { label: 'LAWIN Monitoring System (MES)', href: '/lawin-monitorings', icon: 'shield', permission: 'canViewLawinMonitoring', section: 'MES' },

    // --- MGA MENU NGA MAKITA SA DUHA (ADMIN / REPORTS) ---
    { label: 'Reports', href: '#', icon: 'report', comingSoon: true, permission: 'canViewReports', section: 'BOTH' },
    { label: 'Administration', heading: true, permission: 'canManageUsers', section: 'BOTH' },
    { label: 'User Management', href: '/admin/users', icon: 'users', permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Audit Logs', icon: 'audit', comingSoon: true, permission: 'canManageUsers', section: 'BOTH' },
    { label: 'Settings', icon: 'settings', comingSoon: true, permission: 'canManageUsers', section: 'BOTH' },
];

function Icon({ name, className = 'h-5 w-5' }) {
    const paths = {
        dashboard: 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z',
        users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m17-9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7-4a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
        document: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm0 0v6h6M8 13h8m-8 4h8',
        activity: 'M3 12h4l3-8 4 16 3-8h4',
        map: 'm9 18-6-3V5l6 3 6-3 6 3v10l-6-3-6 3Zm0-10v10m6-13v10',
        report: 'M4 19.5V4a2 2 0 0 1 2-2h9l5 5v12.5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2ZM14 2v6h6M8 13h8m-8 4h5',
        audit: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        settings: 'M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7.4-3.5a7.6 7.6 0 0 0-.1-1.2l2-1.5-2-3.4-2.4 1a8.7 8.7 0 0 0-1-0.6L15.5 3h-4l-.4 2.3a8.7 8.7 0 0 0-1 .6l-2.4-1-2 3.4 2 1.5a7.6 7.6 0 0 0 0 2.4l-2 1.5 2 3.4 2.4-1c.3.2.6.4 1 .6l.4 2.3h4l.4-2.3c.4-.2.7-.4 1-.6l2.4 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2Z',
        'shield-alert': 'M20 13c0 5-3.5 7.5-7.66 9.7a1 1 0 0 1-.68 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 .76-.97l8-2a1 1 0 0 1 .48 0l8 2A1 1 0 0 1 20 6ZM12 8v4M12 16h.01',
        shield: 'M20 13c0 5-3.5 7.5-7.66 9.7a1 1 0 0 1-.68 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 .76-.97l8-2a1 1 0 0 1 .48 0l8 2A1 1 0 0 1 20 6Z'
    };
    const pathData = paths[name] || 'M12 2L2 22h20L12 2z';
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">
            <path d={pathData} />
        </svg>
    );
}

function Sidebar({ open, onClose, auth, managementPlanTypes = [] }) {
    const { url } = usePage();
    const safeAuth = auth || {};
    const userSection = auth?.user?.section || 'CDS';
    const isMES = userSection === 'MES';

    const [openDropdowns, setOpenDropdowns] = useState({});
    const navigation = allNavigation.map(item => item.dynamicManagementPlans ? {
        ...item,
        children: [
            { label: 'All Plans', href: '/management-plans', exact: true },
            ...managementPlanTypes.map(type => ({ label: type.name, href: `/management-plans/types/${type.slug}` })),
            ...(safeAuth.canCreateManagementPlans ? [{ label: '+ Create Plan', href: '/management-plans?create=1', exact: true }] : []),
        ],
    } : item);

    const toggleDropdown = (label) => {
        setOpenDropdowns((prev) => ({
            ...prev,
            [label]: !prev[label]
        }));
    };

    useEffect(() => {
        navigation.forEach(item => {
            if (item.children) {
                const isActive = item.children.some(child => url.startsWith(child.href) && child.href !== '#');
                if (isActive) {
                    setOpenDropdowns(prev => ({ ...prev, [item.label]: true }));
                }
            }
        });
    }, [url, managementPlanTypes]);

    const logoSrc = isMES ? "/images/DENR LOGO.png" : "/images/CDS Logo.png";
    const systemTitle = isMES ? "MES IMS" : "CDS IMS";
    const systemSubtitle = isMES ? "Monitoring & Enforcement Section" : "Conservation Development Section";

    const filteredNavigation = navigation.filter(item =>
        item.section === 'BOTH' || item.section === userSection
    );

    return (
        <>
            <button type="button" className={`fixed inset-0 z-30 bg-gray-950/40 lg:hidden ${open ? '' : 'hidden'}`} onClick={onClose} aria-label="Close navigation" />
            <aside className={`fixed inset-y-0 left-0 z-40 flex w-72 flex-col border-r border-green-950/20 bg-green-900 text-white transition-transform duration-200 lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex h-20 items-center gap-3 border-b border-white/10 px-6">
                    <img src={logoSrc} alt="System Logo" className="h-11 w-11 object-contain rounded-full bg-white p-0.5 shadow-sm" />
                    <div>
                        <p className="text-sm font-semibold tracking-wide">{systemTitle}</p>
                        <p className="text-[10px] text-green-200 uppercase">{systemSubtitle}</p>
                    </div>
                    <button type="button" onClick={onClose} className="ml-auto rounded p-1 text-green-100 hover:bg-white/10 lg:hidden">×</button>
                </div>
                <nav className="flex-1 overflow-y-auto px-3 py-5 space-y-1.5" aria-label="Main navigation">
                    {filteredNavigation.map((item) => {
                        if (item.permission && !safeAuth[item.permission]) return null;

                        if (item.heading) {
                            return <p key={item.label} className="mt-5 px-3 text-[11px] font-bold uppercase tracking-[0.12em] text-green-300 first:mt-0">{item.label}</p>;
                        }

                        if (item.children) {
                            const isOpen = openDropdowns[item.label];
                            return (
                                <div key={item.label} className="mt-1">
                                    <button
                                        onClick={() => toggleDropdown(item.label)}
                                        className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition text-green-50 hover:bg-white/10 focus:outline-none"
                                    >
                                        <Icon name={item.icon} />
                                        <span className="flex-1 text-left">{item.label}</span>
                                        <span className="text-base font-bold text-green-300">{isOpen ? '−' : '+'}</span>
                                    </button>

                                    {isOpen && (
                                        <div className="mt-1 space-y-1 pl-3 pr-1">
                                            {item.children.map((child) => {
                                                if (child.permission && !safeAuth[child.permission]) return null;

                                                const isChildActive = child.href !== '#' && (child.exact ? url === child.href : (url === child.href || url.startsWith(`${child.href}/`)));

                                                const childCommon = `block w-full rounded-xl px-4 py-2.5 text-xs font-semibold transition ${
                                                    isChildActive
                                                        ? 'bg-green-600 text-white shadow-md'
                                                        : 'text-green-100 hover:bg-white/10 hover:text-white'
                                                }`;

                                                if (child.comingSoon) return (
                                                    <Tooltip key={child.label} content="Coming Soon" className="block w-full"><div className={`${childCommon} cursor-not-allowed opacity-75 flex justify-between items-center`}>
                                                        <span className="truncate pr-2">{child.label}</span>
                                                        <span className="rounded-full bg-white/10 px-2 py-0.5 text-[9px] font-bold tracking-wide text-green-100 flex-shrink-0">Coming Soon</span>
                                                    </div></Tooltip>
                                                );

                                                return (
                                                    <Link key={child.label} href={child.href} onClick={onClose} className={childCommon}>
                                                        {child.label}
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        }

                        const active = item.href && (url === item.href || (item.href !== '/dashboard' && url.startsWith(`${item.href}/`)));

                        const common = `flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
                            active
                                ? 'bg-green-600 text-white shadow-md font-semibold'
                                : 'text-green-50 hover:bg-white/10'
                        }`;

                        if (item.comingSoon) return (
                            <Tooltip key={item.label} content="Coming Soon" className="block w-full"><div className={`${common} cursor-not-allowed opacity-75`}>
                                <Icon name={item.icon} />
                                <span className="flex-1 text-left">{item.label}</span>
                                <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[10px] font-semibold tracking-wide text-green-100">Coming Soon</span>
                            </div></Tooltip>
                        );
                        return (
                            <Link key={item.label} href={item.href} onClick={onClose} className={common}>
                                <Icon name={item.icon} />
                                <span>{item.label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="border-t border-white/10 p-4 text-xs leading-5 text-green-200">{systemSubtitle}<br />Information Management System</div>
            </aside>
        </>
    );
}

export default function AuthenticatedLayout({ title, children }) {
    const { auth, managementPlanTypes = [] } = usePage().props;
    const { url } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const [darkMode, setDarkMode] = useState(false);

    const userName = auth?.user?.name || 'User';
    const initials = userName.split(' ').map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    const isDashboard = url === '/dashboard' || url.startsWith('/dashboard?') || url.startsWith('/dashboard/');

    useEffect(() => {
        const savedTheme = window.localStorage.getItem('theme');
        const shouldUseDark = savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
        setDarkMode(shouldUseDark);
        document.documentElement.classList.toggle('dark', shouldUseDark);
    }, []);

    const toggleTheme = () => {
        const next = !darkMode;
        setDarkMode(next);
        document.documentElement.classList.toggle('dark', next);
        window.localStorage.setItem('theme', next ? 'dark' : 'light');
    };

    return (
        <>
            <Head title={title} />
            <FlashSuccessDialog />
            <div className="min-h-screen bg-gray-100 dark:bg-gray-950">
                <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} auth={auth} managementPlanTypes={managementPlanTypes} />
                <div className="lg:pl-72">
                    <header className="sticky top-0 z-30 flex h-20 items-center gap-4 border-b border-gray-200 bg-white px-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:px-6 lg:px-8">
                        <button type="button" onClick={() => setSidebarOpen(true)} className="rounded-lg p-2 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 lg:hidden">
                            <span className="text-xl leading-none">☰</span>
                        </button>

                        {isDashboard ? (
                            <div className="hidden flex-1 md:block"><GlobalSearch /></div>
                        ) : (<div className="flex-1" />)}

                        <div className="ml-auto flex items-center gap-2 sm:gap-4">
                            <button type="button" onClick={toggleTheme} className="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">
                                <span className="text-lg leading-none">{darkMode ? '☀' : '◐'}</span>
                            </button>
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
                    <main className="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{children}</main>
                </div>
            </div>
        </>
    );
}
