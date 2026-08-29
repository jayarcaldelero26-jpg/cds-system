import { useReportDetails } from './ReportDetailsContext';

export default function CrudSection({ title, subtitle, children, className = '' }) {
    const reportDetails = useReportDetails();
    const reportTitle = {
        'General Information': 'Report Information',
        'General / Report Information': 'Report Information',
        'Revenue': 'Financial Details',
        'Submission Information': 'Submission Timeline',
        'Submission / Compliance Details': 'Submission Timeline',
    }[title] || title;
    const displayTitle = reportDetails ? reportTitle : title;
    const daysComplied = reportDetails?.number_days_complied ?? reportDetails?.days_complied;
    const showDaysComplied = reportDetails && displayTitle === 'Submission Timeline' && daysComplied !== null && daysComplied !== undefined && daysComplied !== '';
    return <section className={`space-y-3 ${className}`}>{(displayTitle || subtitle) && <div><h3 className="text-xs font-bold uppercase tracking-wider text-green-800 dark:text-green-400">{displayTitle}</h3>{subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}</div>}<div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">{children}{showDaysComplied && <div className="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700"><span className="block text-xs text-gray-500">Days Complied</span><span className="font-semibold text-gray-800 dark:text-gray-200">{daysComplied}</span></div>}</div></section>;
}
