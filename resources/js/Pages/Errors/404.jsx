import { Icon } from '@iconify/react';
import { Link } from '@inertiajs/react';

export default function Error404() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4 dark:bg-slate-950">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 text-center shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-8">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">
                    <Icon icon="lucide:shield-alert" width="28" height="28" aria-hidden="true" />
                </div>
                <h1 className="mt-4 text-xl font-bold text-slate-900 dark:text-white">Access Restricted</h1>
                <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                    Your account does not currently have permission to access this area.
                </p>
                <div className="mt-6 flex justify-center">
                    <Link
                        href={route('welcome')}
                        className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700"
                    >
                        <Icon icon="lucide:house" width="17" height="17" aria-hidden="true" />
                        Home
                    </Link>
                </div>
            </div>
        </div>
    );
}