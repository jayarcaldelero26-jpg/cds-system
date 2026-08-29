import Tooltip from '@/Components/Tooltip';

function ReportDocumentIcon() {
    return <svg viewBox="0 0 24 24" className="h-5 w-5" aria-hidden="true"><path d="M6 2.75h8.2L19 7.55V20a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 20V4.25A1.5 1.5 0 0 1 6.5 2.75H6Z" fill="#60A5FA" /><path d="M14 2.75v4.8h5" fill="#A7F3D0" /><path d="M8.25 12h7.5M8.25 15.25h5.2" stroke="#fff" strokeWidth="1.5" strokeLinecap="round" /><circle cx="17.1" cy="17.1" r="3.15" fill="#34D399" /><path d="m15.7 17.1 1 1 1.9-2.1" fill="none" stroke="#fff" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round" /></svg>;
}

export default function CrudModalHeader({ icon, report = false, title, subtitle, onClose }) {
    if (report && subtitle && !/[·-]/.test(subtitle)) subtitle = `${subtitle} · Report submission`;
    return <header className="flex shrink-0 items-center justify-between gap-4 border-b border-gray-100 bg-gray-50/50 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40">
        <div className="flex min-w-0 items-center gap-2">
            {(report || icon) && <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-green-100 text-lg text-green-700 dark:bg-green-950 dark:text-green-400" aria-hidden="true">{report ? <ReportDocumentIcon /> : icon}</span>}
            <div className="min-w-0"><h2 className="truncate text-sm font-bold text-gray-900 dark:text-white sm:text-base">{title}</h2>{subtitle && <p className="truncate text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}</div>
        </div>
        <Tooltip content="Close modal"><button type="button" onClick={onClose} className="rounded-lg p-1.5 text-lg font-bold text-gray-400 transition hover:bg-gray-200 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-green-700 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="Close modal">×</button></Tooltip>
    </header>;
}
