import { FloatingSelect } from '@/Components/Form';

export default function FinalArchivesFilterFields({
    module = '',
    protectedArea = '',
    office = '',
    year = '',
    filterOptions = {},
    onModuleChange,
    onProtectedAreaChange,
    onOfficeChange,
    onYearChange,
}) {
    return <div className="grid min-w-0 grid-cols-1 gap-3">
        <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
            <FloatingSelect id="final-archive-module" label="Report module" size="sm" value={module} onChange={onModuleChange}>
                <option value="">All modules</option>
                {(filterOptions.modules || []).map(value => <option key={value} value={value}>{value}</option>)}
            </FloatingSelect>
            <FloatingSelect id="final-archive-pa" label="Protected Area" size="sm" value={protectedArea} onChange={onProtectedAreaChange}>
                <option value="">All Protected Areas</option>
                {(filterOptions.protectedAreas || []).map(value => <option key={value} value={value}>{value}</option>)}
            </FloatingSelect>
            <FloatingSelect id="final-archive-office" label="Originating office" size="sm" value={office} onChange={onOfficeChange}>
                <option value="">All offices</option>
                {(filterOptions.offices || []).map(value => <option key={value} value={value}>{value}</option>)}
            </FloatingSelect>
            <FloatingSelect id="final-archive-year" label="Reporting year" size="sm" value={year} onChange={onYearChange}>
                <option value="">All years</option>
                {(filterOptions.years || []).map(value => <option key={value} value={value}>{value}</option>)}
            </FloatingSelect>
        </div>
    </div>;
}
