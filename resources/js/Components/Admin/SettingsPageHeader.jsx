import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';

export default function SettingsPageHeader({ title, description, actions }) {
    return (
        <header className="mb-5 border-b border-gray-200 pb-4 dark:border-gray-700">
            <Link
                href="/settings"
                aria-label={`Back to Settings from ${title}`}
                className="inline-flex w-fit shrink-0 items-center gap-1.5 rounded-md text-xs font-semibold text-gray-600 transition hover:text-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2 dark:text-gray-300 dark:hover:text-green-300 dark:focus-visible:ring-offset-gray-900"
            >
                <Icon icon="lucide:arrow-left" width="15" height="15" aria-hidden="true" />
                <span>Settings</span>
            </Link>
            <div className="mt-3 flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 flex-1">
                    <h1 className="break-words text-lg font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-xl">{title}</h1>
                    <p className="mt-1 break-words text-sm leading-5 text-gray-600 dark:text-gray-300">{description}</p>
                </div>
                {actions && <div className="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:justify-end">{actions}</div>}
            </div>
        </header>
    );
}
