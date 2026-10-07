import { Icon } from '@iconify/react';
import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const items = [
    { title: 'Module Management', subtitle: 'Reporting configuration', href: '/settings/module-management', icon: 'lucide:blocks', capability: 'canViewModuleManagement' },
    { title: 'Compliance Alerts', subtitle: 'Operational notifications', href: '/settings/compliance-alerts', icon: 'lucide:triangle-alert', capability: 'canManageComplianceAlerts' },
    { title: 'Report Routing', subtitle: 'Position controls', href: '/settings/routing-workflow', icon: 'lucide:route', capability: 'canViewRoutingWorkflow' },
    { title: 'Storage', subtitle: 'Capacity and availability', href: '/settings/storage', icon: 'lucide:hard-drive', capability: 'canViewStorage' },
    { title: 'System Diagnostics', subtitle: 'Runtime health checks', href: '/settings/system-diagnostics', icon: 'lucide:activity', capability: 'canViewSystemDiagnostics' },
];

export default function SettingsShell({ children, active }) {
    const { props } = usePage();
    const visible = items.filter(item => props.auth?.[item.capability] === true);
    return <AuthenticatedLayout title="Settings">
        <Head title={active ? `${active} · Settings` : 'Settings'} />
        <header className="flex flex-wrap items-center gap-3 border-b border-gray-200 pb-4 dark:border-gray-800">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl border border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300"><Icon icon="lucide:settings-2" width="17" height="17" aria-hidden="true" /></span>
            <div className="min-w-0"><p className="text-[10px] font-extrabold uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">Administration</p><h1 className="mt-0.5 text-xl font-extrabold tracking-tight text-gray-900 dark:text-white">Settings</h1><p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Manage CDS-SMART configuration and connected services.</p></div>
        </header>
        <div className="mt-5 grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
            <nav aria-label="Settings navigation" className="rounded-2xl border border-gray-200 bg-white p-2 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <p className="px-3 pb-2 pt-2 text-[10px] font-extrabold uppercase tracking-[0.16em] text-gray-500 dark:text-gray-400">Settings</p>
                <div className="flex gap-1 overflow-x-auto lg:block lg:space-y-1 lg:overflow-visible">
                    {visible.map(item => { const selected = active === item.title; return <Link key={item.href} href={item.href} aria-current={selected ? 'page' : undefined} className={`group flex min-w-[190px] items-center gap-3 rounded-xl border-l-2 px-3 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-1 lg:min-w-0 ${selected ? 'border-green-600 bg-green-50 text-green-800 dark:border-green-400 dark:bg-green-950/30 dark:text-green-200' : 'border-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white'}`}><Icon icon={item.icon} width="17" height="17" className={selected ? 'text-green-700 dark:text-green-300' : 'text-gray-400 group-hover:text-green-600'} aria-hidden="true" /><span className="min-w-0"><span className="block text-xs font-bold">{item.title}</span><span className="mt-0.5 block truncate text-[11px] text-gray-500 dark:text-gray-400">{item.subtitle}</span></span></Link>; })}
                </div>
            </nav>
            <main className="min-w-0 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6 dark:border-gray-800 dark:bg-gray-900">{children}</main>
        </div>
    </AuthenticatedLayout>;
}
