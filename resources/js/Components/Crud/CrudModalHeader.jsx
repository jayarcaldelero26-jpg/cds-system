import Tooltip from '@/Components/Tooltip';

export default function CrudModalHeader({ icon, title, subtitle, onClose }) {
    return <header className="flex shrink-0 items-center justify-between gap-4 border-b border-gray-100 bg-gray-50/50 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40">
        <div className="flex min-w-0 items-center gap-2">
            {icon && <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-green-100 text-lg text-green-700 dark:bg-green-950 dark:text-green-400" aria-hidden="true">{icon}</span>}
            <div className="min-w-0"><h2 className="truncate text-sm font-bold text-gray-900 dark:text-white sm:text-base">{title}</h2>{subtitle && <p className="truncate text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}</div>
        </div>
        <Tooltip content="Close modal"><button type="button" onClick={onClose} className="rounded-lg p-1.5 text-lg font-bold text-gray-400 transition hover:bg-gray-200 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-green-700 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="Close modal">×</button></Tooltip>
    </header>;
}
