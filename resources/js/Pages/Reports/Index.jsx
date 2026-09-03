import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Index({ stats = {} }) {
    return (
        <AuthenticatedLayout title="Executive Reports">
            <PageHeader
                title="Executive Summary & Reports"
                description="Current eDATS operational indicators. Historical retired-module records are excluded from active reporting."
                actions={<button type="button" onClick={() => window.print()} className="rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white">Print Summary Report</button>}
            />
            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {[
                    ['Protected Areas', stats.protected_areas_count, 'Authorized active registry records'],
                    ['Management Plans', stats.management_plans_count, 'Authorized active plan records'],
                    ['BMS Records', stats.bms_records_count, 'Current biodiversity monitoring records'],
                    ['BAMS Records', stats.bams_records_count, 'Current biodiversity assessment records'],
                    ['IMEA Assessments', stats.imea_assessments_count, 'Current effectiveness assessments'],
                    ['AWS Records', stats.aws_records_count, 'Current AWS monitoring records'],
                ].map(([label, value, description]) => (
                    <Card key={label} className="border border-gray-100 shadow-xs dark:border-gray-800">
                        <p className="text-xs font-semibold uppercase tracking-wider text-gray-500">{label}</p>
                        <p className="mt-2 text-3xl font-bold text-gray-900 dark:text-white">{value ?? 0}</p>
                        <p className="mt-1 text-xs text-green-600">{description}</p>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
