import Button from '@/Components/Button';

export default function CrudModalFooter({ left, children, className = '' }) {
    return <footer className={`sticky bottom-0 z-10 flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-gray-800 dark:bg-gray-800/40 ${className}`}>
        <div className="flex flex-wrap items-center gap-2">{left}</div><div className="ml-auto flex flex-wrap items-center justify-end gap-2">{children}</div>
    </footer>;
}

export { Button };
