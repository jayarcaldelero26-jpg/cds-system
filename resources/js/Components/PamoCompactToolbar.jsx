import FilterToolbar from '@/Components/Form/FilterToolbar';
import { FloatingInput } from '@/Components/Form';

export default function PamoCompactToolbar({
    searchId,
    searchLabel = 'Search reports',
    search = '',
    onSearchChange,
    chips = [],
    onClear,
    children,
    action = null,
}) {
    return <div role="group" aria-label="PAMO search and filters" className="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div className="w-full min-w-0 sm:w-[22rem] sm:max-w-full sm:flex-none">
            <FloatingInput
                id={searchId}
                label={searchLabel}
                labelSrOnly
                placeholder={searchLabel}
                placeholderAlwaysVisible
                size="sm"
                value={search}
                onChange={onSearchChange}
            />
        </div>
        <div className="flex min-w-0 flex-wrap items-center gap-2">
            <FilterToolbar
                label="Filters"
                className="w-full max-w-full sm:w-auto"
                triggerClassName="!h-10 !rounded-lg"
                clearClassName="!h-10 !min-h-10 !rounded-lg !border !border-gray-300 dark:!border-gray-600"
                chips={chips}
                onClear={onClear}
            >
                {children}
            </FilterToolbar>
        </div>
        {action && <div className="w-full min-w-0 sm:ml-auto sm:w-auto">{action}</div>}
    </div>;
}
