import { Link, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { resolveHistoricalRecordHref, stopHistoricalRecordEvent } from './historicalRecordBadgeBehavior.mjs';
import { recordTypeBadgeBaseClass } from './recordTypeBadgeStyles.mjs';

export default function HistoricalRecordBadge({ source, id, onClick = null, passive = false }) {
    const { auth = {} } = usePage().props;
    const href = passive ? null : resolveHistoricalRecordHref(auth.canViewHistoricalReports, route);
    const className = `${recordTypeBadgeBaseClass} border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200`;
    const badge = <><Icon icon="lucide:archive" width="11" height="11" aria-hidden="true" />Historical</>;

    if (!href) return <span className={className} aria-label="Historical record">{badge}</span>;

    return <Link
        href={href}
        onClick={event => stopHistoricalRecordEvent(event, onClick)}
        onKeyDown={event => stopHistoricalRecordEvent(event)}
        className={`${className} hover:border-amber-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500`}
        aria-label="Open Historical Reports table"
    >{badge}</Link>;
}
