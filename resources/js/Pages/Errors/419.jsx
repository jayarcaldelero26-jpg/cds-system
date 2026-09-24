import { Link } from '@inertiajs/react';

export default function Error419() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-100 p-4 dark:bg-slate-950">
            <section className="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-xl dark:bg-slate-900">
                <h1 className="text-xl font-bold text-slate-900 dark:text-white">Session Expired</h1>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Your session has expired. Sign in again to continue.</p>
                <Link href={route('login')} className="mt-6 inline-flex rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white">Return to login</Link>
            </section>
        </main>
    );
}
