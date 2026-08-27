import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';

const cards = [
    { title: 'Compliance Alerts', description: 'Email alerts, memorandum, scheduling, and operational alert configuration.', href: '/settings/compliance-alerts', tone: 'border-blue-200 hover:border-blue-400' },
];

export default function SettingsIndex() {
    return <AuthenticatedLayout title="Settings">
        <Head title="Settings" />
        <PageHeader title="Settings" description="Manage system configuration from dedicated settings areas." />
        <div className="mt-6 grid max-w-3xl gap-4">
            {cards.map(card => <Link key={card.href} href={card.href} className="group block rounded-2xl focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-2">
                <Card className={`h-full border-2 transition ${card.tone}`} padding="p-5">
                    <div className="flex items-start justify-between gap-4"><div><h2 className="text-base font-extrabold text-gray-900 dark:text-white">{card.title}</h2><p className="mt-2 text-sm text-gray-600 dark:text-gray-300">{card.description}</p></div><span className="text-xl font-bold text-green-700 transition group-hover:translate-x-1 dark:text-green-300">→</span></div>
                </Card>
            </Link>)}
        </div>
    </AuthenticatedLayout>;
}
