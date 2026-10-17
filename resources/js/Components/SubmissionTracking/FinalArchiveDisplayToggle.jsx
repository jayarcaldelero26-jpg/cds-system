import { Icon } from '@iconify/react';

export default function FinalArchiveDisplayToggle({ value = 'grid', onChange }) {
    const buttonClass = mode => `inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 ${value === mode
        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
        : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'}`;
    return <div role="group" aria-label="Archive display" className="inline-flex h-10 shrink-0 items-center rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-900">
        <button type="button" aria-pressed={value === 'grid'} onClick={() => onChange?.('grid')} className={buttonClass('grid')}><Icon icon="lucide:layout-grid" width="15" height="15" aria-hidden="true" /><span>Grid</span></button>
        <button type="button" aria-pressed={value === 'list'} onClick={() => onChange?.('list')} className={buttonClass('list')}><Icon icon="lucide:list" width="15" height="15" aria-hidden="true" /><span>List</span></button>
    </div>;
}
