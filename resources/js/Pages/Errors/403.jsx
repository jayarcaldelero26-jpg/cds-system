import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link } from '@inertiajs/react';

export default function Error403({ message = 'You do not have permission to perform this action.' }) {
    return (
        <AuthenticatedLayout title="Permission Denied">
            <div className="mx-auto flex min-h-[60vh] max-w-xl items-center px-4 py-8">
                <div className="w-full rounded-2xl border border-red-100 bg-white p-8 text-center shadow-xl dark:border-red-950 dark:bg-gray-900">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-2xl text-red-700 dark:bg-red-950 dark:text-red-300">
                        !
                    </div>
                    <h1 className="text-xl font-bold text-gray-900 dark:text-white">Permission denied</h1>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">{message}</p>
                    <div className="mt-6 flex justify-center gap-3">
                        <button type="button" onClick={() => window.history.back()} className="rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200">
                            Back
                        </button>
                        <Link href="/dashboard" className="rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800">
                            Dashboard
                        </Link>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
