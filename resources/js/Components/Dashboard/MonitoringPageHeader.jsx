import PageHeader from '@/Components/PageHeader';

export default function MonitoringPageHeader({ title, description, periodTitle, periodSubtitle, icon = 'solar:document-text-linear' }) {
    return <PageHeader title={title} description={description} periodTitle={periodTitle} periodSubtitle={periodSubtitle} icon={icon} variant="monitoring" />;
}
