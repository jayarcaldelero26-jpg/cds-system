export default function CrudSection({ title, subtitle, children, className = '' }) {
    return <section className={`space-y-3 ${className}`}>{(title || subtitle) && <div><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{title}</h3>{subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}</div>}<div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">{children}</div></section>;
}
