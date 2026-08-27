import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';

export default function GeneralSettings() {
    return <AuthenticatedLayout title="General Settings">
        <Head title="General Settings" />
        <PageHeader title="General Settings" description="System-wide application configuration." />
        <div className="mt-6 max-w-3xl"><Card padding="p-5"><h2 className="text-base font-extrabold text-gray-900 dark:text-white">No general settings configured</h2><p className="mt-2 text-sm text-gray-600 dark:text-gray-300">There are currently no additional general application settings to manage. Operational configuration is maintained in its dedicated administration areas.</p></Card></div>
    </AuthenticatedLayout>;
}
