import { usePage } from '@inertiajs/react';
import ReportSubmissionTracker from '@/Pages/Bms/ReportSubmissionTracker';

export default function AwsReportSubmissionTracker({ records = [], pagination, protectedAreas = [], organizationalOffices = [], filters = {} }) {
    const { auth = {} } = usePage().props;

    return <ReportSubmissionTracker
        submissions={{ data: records.map(record => ({ ...record, mov: record.report_file, mov_url: record.report_file?.url })), total: pagination?.total ?? records.length, links: pagination?.links || [] }}
        protectedAreas={protectedAreas}
        targetOffices={organizationalOffices}
        filters={filters}
        moduleLabel="Automated Weather Station"
        submissionRoutes={{
            index: route('aws.index'),
            store: route('aws.store'),
            update: id => route('aws.update', id),
            destroy: id => route('aws.destroy', id),
            mov: report => report.report_file?.url || route('aws.report-file.show', report.id),
        }}
        filterPrefix=""
        permissions={{
            create: Boolean(auth.canCreateAws),
            update: Boolean(auth.canUpdateAws),
            delete: Boolean(auth.canDeleteAws),
        }}
        attachmentField="report_file"
        workflowConfig={{
            key: 'aws',
            description: 'Monitoring and Maintenance of AWS report submissions.',
            activities: ['Monitoring and Maintenance of AWS'],
            default_activity: 'Monitoring and Maintenance of AWS',
            documents: ['Progress Report', 'Final Report'],
            activity_documents: { 'Monitoring and Maintenance of AWS': ['Progress Report', 'Final Report'] },
            period_field: 'quarter',
            period_label: 'Reporting Period',
            periods: ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            period_options: [{ value: 1, label: 'Quarter 1' }, { value: 2, label: 'Quarter 2' }, { value: 3, label: 'Quarter 3' }, { value: 4, label: 'Quarter 4' }],
            coverage_period: true,
            date_conducted_type: 'date',
            date_conducted_required: true,
            target_office_select: true,
            days_complied_field: 'number_days_complied',
            penro_delay_field: 'total_days_delayed_penro',
        }}
    />;
}
