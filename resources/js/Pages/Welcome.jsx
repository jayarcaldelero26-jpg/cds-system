import { Head, Link } from '@inertiajs/react';

const features = [
    { title: 'Integrated Monitoring', description: 'Consolidated monitoring across Conservation and Development activities.', icon: 'layers' },
    { title: 'Submission & Timeliness Tracking', description: 'Track report deadlines, routing, receipt, delays, and timeliness.', icon: 'calendar' },
    { title: 'Program & Activity Monitoring', description: 'Monitor PA and ENGP program implementation from one system.', icon: 'chart' },
    { title: 'MOV & Document Management', description: 'Organize supporting documents and Means of Verification.', icon: 'folder' },
    { title: 'Automated Alerts', description: 'Surface due, overdue, and submission-compliance requirements.', icon: 'bell' },
    { title: 'Performance Dashboard', description: 'Visualize current monitoring status, compliance, and upcoming deadlines.', icon: 'dashboard' },
];

const values = [
    ['Integrated', 'One monitoring environment for Conservation and Development.'],
    ['Timely', 'Submission tracking and alerts support timely compliance.'],
    ['Transparent', 'Clear routing, status, and audit visibility.'],
    ['Data-Driven', 'Monitoring information supports better management decisions.'],
];

function Icon({ name, className = 'h-5 w-5' }) {
    const paths = {
        shield: <path d="M12 3 19 6v5.2c0 4.4-2.8 7.7-7 9.8-4.2-2.1-7-5.4-7-9.8V6l7-3Zm-3.2 9 2.1 2.1 4.4-4.4" />,
        lock: <><rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2" /></>,
        check: <><circle cx="12" cy="12" r="8.5" /><path d="m8.5 12 2.2 2.2 4.8-4.8" /></>,
        layers: <><path d="m12 3 8 4.3-8 4.3-8-4.3L12 3Z" /><path d="m4 12 8 4.3 8-4.3M4 16.7l8 4.3 8-4.3" /></>,
        calendar: <><rect x="4" y="5" width="16" height="15" rx="2" /><path d="M8 3v4M16 3v4M4 10h16M8 14h3M8 17h6" /></>,
        chart: <><path d="M4 19V5M4 19h16" /><path d="m7 15 3-3 3 2 4-5" /></>,
        folder: <><path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h4l2 2h6A2.5 2.5 0 0 1 20.5 9.5v7A2.5 2.5 0 0 1 18 19H6a2.5 2.5 0 0 1-2.5-2.5v-9Z" /><path d="M3.5 10h17" /></>,
        bell: <><path d="M18 10a6 6 0 0 0-12 0c0 7-3 7-3 8.5h18C21 17 18 17 18 10ZM10 21h4" /></>,
        dashboard: <><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" /></>,
        arrow: <path d="M5 12h13m-5-5 5 5-5 5" />,
    };

    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">{paths[name]}</svg>;
}

export default function Welcome({ overview = {} }) {
    const metrics = [
        ['Tracked Reports', overview.tracked_reports],
        ['Submitted', overview.submitted],
        ['Overdue', overview.overdue],
        ['Due / In Progress', overview.reports_due],
        ['Compliant', overview.compliant],
        ['Monitoring Sources', overview.monitoring_sources],
    ];

    return (
        <>
            <Head title="eDATS | PENRO Mati" />
            <main className="min-h-screen overflow-hidden bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
                <header className="border-b border-emerald-950/10 bg-white/95 shadow-sm backdrop-blur dark:border-white/10 dark:bg-slate-950/95">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-5 px-5 py-4 sm:px-6 lg:px-8">
                        <div className="flex min-w-0 items-center gap-3 sm:gap-4">
                            <div className="flex shrink-0 items-center gap-2" aria-label="Department and section logos">
                                <img src="/images/DENR%20LOGO.png" alt="Department of Environment and Natural Resources logo" className="h-11 w-11 object-contain sm:h-12 sm:w-12" />
                                <img src="/images/CDS%20Logo.png" alt="Conservation and Development Section logo" className="h-10 w-10 object-contain sm:h-11 sm:w-11" />
                            </div>
                            <div className="min-w-0 border-l border-emerald-900/15 pl-3 sm:pl-4">
                                <p className="text-xl font-extrabold tracking-tight text-emerald-900 dark:text-emerald-300">eDATS</p>
                                <p className="text-xs font-semibold text-slate-700 dark:text-slate-200 sm:text-sm">Enhanced Digital Alert and Tracking System</p>
                                <p className="mt-0.5 text-[10px] font-bold uppercase tracking-[0.12em] text-emerald-800 dark:text-emerald-400">PENRO Mati – Conservation and Development Section</p>
                            </div>
                        </div>
                    </div>
                </header>

                <section className="relative isolate overflow-hidden bg-gradient-to-br from-emerald-950 via-emerald-900 to-teal-800">
                    <div className="absolute inset-0 -z-10 opacity-30" aria-hidden="true">
                        <div className="absolute -left-20 bottom-0 h-64 w-[58%] rounded-tr-[100%] bg-emerald-500/45" />
                        <div className="absolute right-0 top-0 h-72 w-2/3 rounded-bl-[100%] bg-cyan-300/20" />
                        <svg className="absolute inset-x-0 bottom-0 h-48 w-full text-emerald-300/30" viewBox="0 0 1440 240" preserveAspectRatio="none"><path d="M0 196 190 104l170 72L558 48l190 142 216-102 197 94 279-128v186H0Z" fill="currentColor" /></svg>
                    </div>
                    <div className="mx-auto grid max-w-7xl gap-10 px-5 py-14 sm:px-6 md:py-20 lg:grid-cols-[1.15fr_.85fr] lg:items-center lg:px-8">
                        <div className="max-w-3xl">
                            <span className="inline-flex rounded-full border border-emerald-100/30 bg-white/10 px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-emerald-50">CDS Integrated Monitoring and Management System</span>
                            <h1 className="mt-6 text-4xl font-extrabold leading-[1.04] tracking-tight text-white sm:text-5xl lg:text-6xl">Enhanced Digital<br />Alert and Tracking System<br /><span className="text-emerald-200">(eDATS)</span></h1>
                            <p className="mt-5 text-lg font-semibold text-emerald-100 sm:text-xl">One System. All CDS Monitoring. Better Decisions.</p>
                             <p className="mt-5 max-w-2xl text-sm leading-7 text-emerald-50/90 sm:text-base">eDATS consolidates monitoring, report submissions, deadlines, supporting documents, alerts, performance, Conservation activities, and Development/ENGP monitoring in one workspace.</p>
                             <Link href="/login" className="mt-8 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-emerald-900 shadow-lg shadow-emerald-950/20 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-white" aria-label="Access eDATS login">Access Login <Icon name="arrow" className="h-4 w-4" /></Link>
                        </div>

                        <section className="rounded-2xl border border-white/20 bg-white/95 p-5 shadow-2xl shadow-emerald-950/25 backdrop-blur dark:bg-slate-900/95 sm:p-6" aria-labelledby="overview-title">
                            <div className="flex items-start justify-between gap-3">
                                <div><h2 id="overview-title" className="text-sm font-extrabold tracking-wide text-emerald-950 dark:text-emerald-300">eDATS OVERVIEW SUMMARY</h2><p className="mt-1 text-xs text-slate-600 dark:text-slate-300">Current safe monitoring aggregates</p></div>
                                <Icon name="dashboard" className="h-6 w-6 text-emerald-700 dark:text-emerald-400" />
                            </div>
                            <dl className="mt-5 grid grid-cols-2 gap-3">
                                {metrics.map(([label, value]) => <div key={label} className="rounded-xl border border-emerald-900/10 bg-emerald-50/70 p-3 dark:border-emerald-300/15 dark:bg-emerald-950/30"><dt className="text-[10px] font-bold uppercase leading-4 tracking-[0.08em] text-slate-600 dark:text-slate-300">{label}</dt><dd className="mt-1 text-2xl font-extrabold text-emerald-900 dark:text-emerald-300">{Number(value || 0).toLocaleString()}</dd></div>)}
                            </dl>
                        </section>
                    </div>
                </section>

                <section className="mx-auto max-w-7xl px-5 py-14 sm:px-6 lg:px-8" aria-labelledby="capabilities-title">
                    <div className="max-w-2xl"><p className="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-400">System capabilities</p><h2 id="capabilities-title" className="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">A connected monitoring environment</h2></div>
                    <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {features.map((feature) => <Link key={feature.title} href="/login" className="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-emerald-700" aria-label={`${feature.title}: access through login`}><span className="inline-flex rounded-xl bg-emerald-100 p-2.5 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><Icon name={feature.icon} /></span><h3 className="mt-4 text-base font-extrabold text-slate-900 dark:text-white">{feature.title}</h3><p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">{feature.description}</p><span className="mt-4 inline-flex items-center gap-1 text-xs font-bold text-emerald-800 dark:text-emerald-400">Authorized access <Icon name="arrow" className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" /></span></Link>)}
                    </div>
                </section>

                <section className="border-y border-emerald-900/10 bg-emerald-50 dark:border-emerald-300/10 dark:bg-emerald-950/25" aria-label="eDATS values"><div className="mx-auto grid max-w-7xl gap-6 px-5 py-8 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">{values.map(([title, description]) => <div key={title}><h2 className="text-sm font-extrabold text-emerald-900 dark:text-emerald-300">{title}</h2><p className="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-300">{description}</p></div>)}</div></section>

                <footer className="bg-slate-950 px-5 py-8 text-center text-xs leading-6 text-slate-300 sm:px-6 lg:px-8"><p className="font-semibold text-white">eDATS is a system of the Conservation and Development Section, PENRO Mati.</p><p className="mt-1">For authorized users only. System activities may be monitored and logged.</p><p className="mt-3 text-slate-400">© {new Date().getFullYear()} Department of Environment and Natural Resources. All rights reserved.</p></footer>
            </main>
        </>
    );
}
