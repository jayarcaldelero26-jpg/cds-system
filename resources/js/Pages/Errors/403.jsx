import { Icon } from '@iconify/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link } from '@inertiajs/react';

export default function Error403() {
    return (
        <AuthenticatedLayout title="Access Restricted">
            <div className="mx-auto flex min-h-[60vh] max-w-xl items-center px-4 py-8">
                <div className="w-full rounded-2xl border border-red-100 bg-white p-8 text-center shadow-xl dark:border-red-950 dark:bg-gray-900">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300">
                        <Icon icon="lucide:shield-alert" width="28" height="28" aria-hidden="true" />
                    </div>
                    <h1 className="mt-4 text-xl font-bold text-gray-900 dark:text-white">Access Restricted</h1>
                    <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Your account does not currently have permission to access this area.
                    </p>
                    <div className="mt-6 flex flex-wrap justify-center gap-3">
                        <button type="button" onClick={() => window.history.back()} className="rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200">
                            Back
                        </button>
                        <Link href="/dashboard" className="inline-flex items-center gap-2 rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800">
                            <Icon icon="lucide:layout-dashboard" width="17" height="17" aria-hidden="true" />
                            Dashboard
                        </Link>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}