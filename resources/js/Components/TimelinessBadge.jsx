const TIMELINESS_STYLES = {
    outstanding: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
    'very satisfactory': 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    satisfactory: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
    unsatisfactory: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
    poor: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    default: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
};

export const normalizeTimeliness = value => String(value ?? '').trim().toLowerCase().replace(/\s+/g, ' ');
export const isTimelinessValue = value => ['outstanding', 'very satisfactory', 'satisfactory', 'unsatisfactory', 'poor', 'no rating', 'n/a', 'na', 'not applicable'].includes(normalizeTimeliness(value));
export const timelinessClass = value => TIMELINESS_STYLES[normalizeTimeliness(value)] || TIMELINESS_STYLES.default;

export default function TimelinessBadge({ value, className = '' }) {
    const label = value === null || value === undefined || String(value).trim() === '' ? 'No Rating' : value;
    return <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ring-gray-600/10 dark:ring-white/10 ${timelinessClass(value)} ${className}`}>{label}</span>;
}
