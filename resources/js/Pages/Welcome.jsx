import { Head, Link, usePage } from '@inertiajs/react';

export default function Welcome() {
    const { auth } = usePage().props;
    return <><Head title="Welcome" /><main className="min-h-screen bg-gray-100 p-8 dark:bg-gray-900"><div className="mx-auto max-w-3xl rounded-lg bg-white p-8 shadow dark:bg-gray-800"><h1 className="text-2xl font-bold text-gray-900 dark:text-white">CDS Information Management System</h1><p className="mt-2 text-gray-600 dark:text-gray-300">DENR PENRO Davao Oriental</p><div className="mt-6">{auth.user ? <Link className="text-indigo-600" href="/dashboard">Go to Dashboard</Link> : <Link className="text-indigo-600" href="/login">Log in</Link>}</div></div></main></>;
}
