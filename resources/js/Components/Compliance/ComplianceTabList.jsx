export default function ComplianceTabList({ tabs, value, onChange, label, className = '' }) {
    return <div role="tablist" aria-label={label} className={`flex flex-wrap gap-2 ${className}`}>
        {tabs.map(([key, title]) => <button key={key} type="button" role="tab" aria-selected={value === key} onClick={() => onChange(key)} className={`cds-compliance-tab recipient-scope-tab rounded-lg px-3 py-2 text-xs font-bold ${value === key ? 'is-selected' : ''}`}>{title}</button>)}
    </div>;
}
