import { Head, Link } from '@inertiajs/react';
import FlashSuccessDialog from '../Components/FlashSuccessDialog';

export default function GuestLayout({ title, children }) {
    return <>
        <Head title={title} />
        <FlashSuccessDialog />
        <main className="min-h-screen bg-gray-100 px-4 py-12 dark:bg-gray-900">
            <div className="mx-auto w-full max-w-md rounded-lg bg-white p-6 shadow dark:bg-gray-800">{children}</div>
        </main>
    </>;
}
