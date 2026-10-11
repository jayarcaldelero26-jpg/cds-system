import HistoricalRecordBadge from './HistoricalRecordBadge';
import { recordTypeBadgeBaseClass } from './recordTypeBadgeStyles.mjs';

export default function RecordTypeBadge({ isHistorical = false, source, id, passive = false }) {
    if (isHistorical) {
        return <HistoricalRecordBadge source={source} id={id} passive={passive} />;
    }

    return <span
        className={`${recordTypeBadgeBaseClass} border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200`}
        aria-label="Live record"
        title="Live source-module record"
    >
        <span className="record-type-badge__live-indicator" aria-hidden="true" />
        Live
    </span>;
}
