import { presentOperationalStatus } from '@/Utils/statusPresentation.mjs';

export default function OperationalStatus({ value, renderStatus }) {
    const presentation = presentOperationalStatus(value);
    if (!presentation.compact) {
        return renderStatus ? renderStatus(value) : <span>{value}</span>;
    }

    return <div role="group" aria-label={`${presentation.context}; status Pending`} className="min-w-0 space-y-1">
        <span className="block break-words text-xs font-semibold leading-4 text-gray-700 dark:text-gray-200">{presentation.context}</span>
        <span aria-label={`${presentation.context}: Pending`} className="inline-flex w-fit items-center rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:ring-amber-900">Pending</span>
    </div>;
}
