import { formatReportDate } from "@/Utils/dateFormatters";
import { reportContextFields } from "@/Utils/submissionDetailContext";

const FALLBACK = "Not recorded";

/** Shared report identity/context values for the workspace summary and details view. */
export default function SubmissionReportContext({ row, expanded = false }) {
    if (!row) return null;
    const fields = reportContextFields(row, expanded);

    return (
        <dl className="grid min-w-0 gap-2 sm:grid-cols-2">
            {fields.map(([label, value, date, fallback]) => {
                const missing = value === null || value === undefined || String(value).trim() === "";
                return (
                    <div className="min-w-0" key={label}>
                        <dt className="text-[10px] font-semibold text-gray-500">{label}</dt>
                        <dd className="mt-0.5 break-words font-semibold text-gray-900 dark:text-white">{date ? formatReportDate(value, fallback || FALLBACK) : missing ? fallback || 'Not provided' : value}</dd>
                    </div>
                );
            })}
        </dl>
    );
}
