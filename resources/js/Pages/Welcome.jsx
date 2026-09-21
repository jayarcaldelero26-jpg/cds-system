import { Head, Link } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const number = value => Number(value || 0).toLocaleString();
const percent = value => `${Number(value || 0).toFixed(1).replace(/\.0$/, '')}%`;

function Icon({ name, className = 'h-5 w-5' }) {
    const paths = { arrow: <path d="M5 12h13m-5-5 5 5-5 5" /> };
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" className={className} aria-hidden="true">{paths[name]}</svg>;
}

function StatBox({ label, value, tone = 'green' }) {
    const tones = { green: 'border-emerald-900/10 bg-[#f1f8f3] text-emerald-800', amber: 'border-amber-900/10 bg-[#fbf7ed] text-amber-700', red: 'border-red-900/10 bg-[#fdf3f1] text-[#b94a42]' };
    return <div className={`rounded-lg border px-3 py-2.5 ${tones[tone] || tones.green}`}><p className="text-[10px] font-medium uppercase tracking-[0.07em] text-slate-500">{label}</p><p className="mt-1 text-[1.55rem] font-semibold leading-none tabular-nums">{number(value)}</p></div>;
}

function HeroProgram({ program }) {
    const total = Math.max((program.on_time || 0) + (program.late || 0) + (program.overdue || 0), 1);
    const width = value => `${((Number(value || 0) / total) * 100).toFixed(2)}%`;
    return <div className="mt-4 first:mt-0">
        <div className="flex items-center justify-between gap-4"><h3 className="text-sm font-semibold uppercase tracking-wide text-emerald-950">{program.key === 'engp' ? 'ENGP' : 'PA'} <span className="mx-1 text-emerald-700">|</span> {program.office_label}</h3><span className="text-xs font-medium text-slate-500">{number(program.due)} due</span></div>
        <div className="mt-2 flex h-3 overflow-hidden rounded-sm bg-slate-100"><span className="bg-[#18835b]" style={{ width: width(program.on_time) }} /><span className="bg-[#d89a2f]" style={{ width: width(program.late) }} /><span className="bg-[#bf4c45]" style={{ width: width(program.overdue) }} /></div>
        <div className="mt-1.5 grid grid-cols-3 text-[11px] font-medium"><span className="text-[#18835b]">{number(program.on_time)} on time</span><span className="text-center text-[#a46d16]">{number(program.late)} late</span><span className="text-right text-[#b94a42]">{number(program.overdue)} overdue</span></div>
    </div>;
}

function ProgramCard({ program }) {
    const title = program.key === 'engp' ? 'ENGP reports' : 'Protected Area reports';
    return <article className="rounded-xl border border-[#d8e4dc] bg-white p-5 shadow-[0_4px_13px_rgba(18,73,54,0.035)] sm:p-6">
        <h3 className="text-xl font-semibold tracking-tight text-emerald-950">{title}</h3><p className="mt-0.5 text-sm font-medium text-slate-500">{program.office_count ? `${number(program.office_count)} ${program.office_label}` : program.office_label}</p><div className="my-4 border-t border-[#dce6df]" />
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4"><StatBox label="Due" value={program.due} /><StatBox label="Received" value={program.received} /><StatBox label="Overdue" value={program.overdue} tone="red" /><StatBox label="Upcoming" value={program.upcoming} tone="amber" /></div>
        <div className="mt-5"><p className="text-[11px] font-medium uppercase tracking-[0.08em] text-slate-500">On-time submission rate</p><div className="mt-2 flex flex-wrap items-end gap-x-4 gap-y-1"><p className="text-4xl font-semibold tracking-tight text-[#16825a]">{percent(program.on_time_rate)}</p><p className="pb-1 text-sm text-slate-600">{number(program.on_time)} received on time</p></div></div>
        <p className="mt-4 text-sm text-slate-500">{number(program.upcoming)} upcoming report{Number(program.upcoming || 0) === 1 ? '' : 's'}.</p><p className="mt-2 text-sm font-medium text-[#b94a42]">{number(program.late)} received late <span className="mx-1.5 text-slate-400">•</span> {number(program.overdue)} overdue and still unreceived</p>
    </article>;
}

function StatusLegend({ entries = [] }) {
    const tones = ['bg-[#18835b]', 'bg-[#d89a2f]', 'bg-[#bf4c45]', 'bg-[#668895]'];
    return <section className="rounded-xl border border-[#d8e4dc] bg-white p-5 shadow-[0_4px_13px_rgba(18,73,54,0.035)] sm:p-6"><h2 className="text-lg font-semibold uppercase tracking-wide text-emerald-950">How to read the status</h2><div className="mt-4 space-y-3">{entries.map((entry, index) => <div key={entry.label} className="grid grid-cols-[14px_9rem_minmax(0,1fr)] items-start gap-2.5"><span className={`mt-1.5 h-3 w-3 rounded-full ${tones[index] || tones[3]}`} /><p className="font-medium text-slate-800">{entry.label}</p><p className="text-sm leading-5 text-slate-500">{entry.description}</p></div>)}</div><p className="mt-5 border-t border-[#dce6df] pt-4 text-sm font-medium text-emerald-900">View office-level reports, delays, and MOVs after logging in.</p></section>;
}

export default function Welcome({ publicSummary = {} }) {
    const totals = publicSummary.totals || {};
    const programs = publicSummary.programs || [];
    const engp = programs.find(program => program.key === 'engp') || { key: 'engp', office_label: 'CENROs' };
    const pa = programs.find(program => program.key === 'conservation') || { key: 'conservation', office_label: 'PAMOs / PA offices' };
    const comparison = (publicSummary.comparison || programs).map(program => ({ name: program.key === 'engp' ? 'ENGP' : 'PA', rate: Number(program.on_time_rate || 0) }));
    const asOf = publicSummary.as_of ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(publicSummary.as_of)) : null;

    return <><Head title="CDS-SMART | PENRO Davao Oriental" /><main className="min-h-screen bg-[#f7faf8] font-sans text-slate-800">
        <header className="bg-white"><div className="mx-auto flex min-h-[94px] max-w-[1440px] items-center px-5 sm:px-8 lg:px-14"><div className="flex min-w-0 items-center gap-4"><div className="flex shrink-0 items-center gap-2"><img src="/images/DENR%20LOGO.png" alt="Department of Environment and Natural Resources logo" className="h-11 w-11 object-contain" /><img src="/images/CDS%20Logo.png" alt="Conservation and Development Section logo" className="h-11 w-11 object-contain" /></div><div className="hidden h-16 w-px bg-emerald-950/15 sm:block" /><div className="min-w-0"><p className="text-2xl font-semibold tracking-tight text-emerald-950">CDS-SMART</p><p className="mt-0.5 truncate text-sm font-medium text-slate-500">Submission Monitoring and Reminder Tool <span className="mx-1">|</span> PENRO Davao Oriental</p></div></div></div></header>

        <section className="relative isolate overflow-hidden bg-[#084c3c] bg-cover bg-center bg-no-repeat" style={{ backgroundImage: "url('/images/cds-smart-background.png')" }}><div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-emerald-950/55 via-emerald-950/45 to-slate-950/55" aria-hidden="true" /><div className="relative mx-auto grid min-h-[505px] max-w-[1440px] gap-10 px-5 py-12 sm:px-8 lg:grid-cols-[1.08fr_.92fr] lg:items-center lg:px-14 lg:py-11"><div className="max-w-[42rem]"><p className="inline-flex rounded-full border border-emerald-100/35 bg-white/10 px-5 py-2 text-xs font-medium uppercase tracking-[0.09em] text-white">Provincial report monitoring</p><h1 className="mt-8 text-[2.65rem] font-semibold leading-[1.13] tracking-tight text-white sm:text-6xl">Know the status of<br className="hidden lg:block" /> every submission.</h1><p className="mt-6 max-w-xl text-lg leading-7 text-emerald-50/90">ENGP reports from CENROs and Protected Area reports from PAMOs across Davao Oriental.</p><Link href="/login" className="mt-9 inline-flex items-center gap-2 rounded-lg bg-white px-6 py-3.5 text-base font-semibold text-emerald-950 shadow-sm transition hover:bg-emerald-50">Access Login <Icon name="arrow" className="h-4 w-4" /></Link><p className="mt-4 text-sm text-emerald-50/75">Office-level records, MOVs, and follow-up require authorized access.</p></div>
            <section id="report-status" className="rounded-2xl border border-white/50 bg-white p-5 shadow-[0_12px_26px_rgba(3,41,32,0.16)] sm:p-7" aria-labelledby="report-status-title"><h2 id="report-status-title" className="text-xl font-semibold uppercase tracking-wide text-emerald-950">PENRO Report Submission Status</h2><p className="mt-1 text-base text-slate-500">Province-wide summary <span className="mx-2">•</span> ENGP and PA</p><div className="mt-4 border-t border-[#dce6df]" /><div className="mt-4 grid grid-cols-3 gap-3"><StatBox label="Reports due" value={totals.due} /><StatBox label="Received" value={totals.received} /><StatBox label="Overdue" value={totals.overdue} tone="red" /></div><div className="mt-5"><HeroProgram program={engp} /><HeroProgram program={pa} /></div></section>
        </div></section>

        <section className="mx-auto max-w-[1440px] px-5 py-10 sm:px-8 lg:px-14"><p className="text-xs font-medium text-slate-500">{asOf ? `Data as of ${asOf}` : 'Current approved report-deadline and receipt information.'}</p><h2 className="mt-4 text-3xl font-semibold tracking-tight text-emerald-950">Submission status by program</h2><p className="mt-1 text-lg text-slate-500">A quick view of what has been received, what is still upcoming, and what needs immediate action.</p><div className="mt-5 grid gap-6 lg:grid-cols-2"><ProgramCard program={engp} /><ProgramCard program={pa} /></div><div className="mt-5 grid gap-6 lg:grid-cols-2"><section className="rounded-xl border border-[#d8e4dc] bg-white p-5 shadow-[0_4px_13px_rgba(18,73,54,0.035)] sm:p-6"><h2 className="text-lg font-semibold uppercase tracking-wide text-emerald-950">On-time rate <span className="mx-1 text-emerald-700">|</span> Program comparison</h2><p className="mt-1 text-sm text-slate-500">Share of officially received reports that were on time</p><div className="mt-4 h-56"><ResponsiveContainer width="100%" height="100%"><BarChart data={comparison} margin={{ top: 20, right: 16, left: -20, bottom: 0 }}><CartesianGrid vertical={false} stroke="#dce6df" /><XAxis dataKey="name" tick={{ fill: '#36534b', fontSize: 12, fontWeight: 600 }} tickLine={false} axisLine={false} /><YAxis domain={[0, 100]} tick={{ fill: '#718078', fontSize: 11 }} tickLine={false} axisLine={false} /><Tooltip formatter={value => [`${value}%`, 'On-time rate']} contentStyle={{ border: '1px solid #d8e4dc', borderRadius: 8, fontSize: 12 }} /><Bar dataKey="rate" radius={[5, 5, 0, 0]} fill="#18835b" barSize={68} label={{ position: 'top', fill: '#14533f', fontSize: 15, fontWeight: 600, formatter: value => `${value}%` }} /></BarChart></ResponsiveContainer></div></section><StatusLegend entries={publicSummary.legend || []} /></div></section>
        <footer className="bg-[#102630] px-5 py-5 text-center text-sm text-slate-300"><span>CDS-SMART</span><span className="mx-3">•</span><span>Conservation and Development Section</span><span className="mx-3">•</span><span>PENRO Davao Oriental</span></footer>
    </main></>;
}
