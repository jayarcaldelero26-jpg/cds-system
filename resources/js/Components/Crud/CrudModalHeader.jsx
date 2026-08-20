export default function CrudModalHeader({ icon, title, subtitle, onClose }) {
    return <header className="flex shrink-0 items-center justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
        <div className="flex min-w-0 items-center gap-3">
            {icon && <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-green-100 text-lg text-green-800 dark:bg-green-950 dark:text-green-300" aria-hidden="true">{icon}</span>}
            <div className="min-w-0"><h2 className="truncate text-base font-bold text-gray-900 dark:text-white">{title}</h2>{subtitle && <p className="truncate text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}</div>
        </div>
        <button type="button" onClick={onClose} className="rounded-lg p-2 text-lg font-bold text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-700 dark:hover:bg-gray-800 dark:hover:text-gray-200" aria-label="Close modal">×</button>
    </header>;
}
