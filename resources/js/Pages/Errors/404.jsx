import { Link } from '@inertiajs/react';

export default function Error404() {
    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-100 dark:bg-gray-950 p-4">
            {/* Modal Dialog Container */}
            <div className="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-2xl transition-all dark:bg-gray-900 border border-gray-200 dark:border-gray-800">

                {/* Icon Header */}
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 dark:bg-red-950/50 mb-4">
                    <svg className="h-8 w-8 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" strokeLinejoin="round5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>

                {/* Title & Message */}
                <h3 className="text-center text-lg font-bold leading-6 text-gray-900 dark:text-white mb-2">
                    Access Restricted or Page Not Found
                </h3>
                <p className="text-center text-sm text-gray-600 dark:text-gray-300 mb-6">
                    Wala ka kahatag og permiso sa pag-access niini nga pahina, o kaha wala na kini sa sistema. Palihog pagbalik sa dashboard o pagkontak sa CDS Admin kung kinahanglan nimo kini nga pwesto.
                </p>

                {/* Buttons Action */}
                <div className="flex justify-center gap-3">
                    <Link
                        href="/dashboard"
                        className="inline-flex justify-center rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-800 transition"
                    >
                        Balik sa Dashboard
                    </Link>
                </div>
            </div>
        </div>
    );
}
