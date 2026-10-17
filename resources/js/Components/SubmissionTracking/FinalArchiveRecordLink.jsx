import { Link } from '@inertiajs/react';
import { finalArchiveRecordHref } from '@/Utils/finalArchiveNavigation.mjs';

export default function FinalArchiveRecordLink({ row, canViewFinalArchives = false }) {
    if (!canViewFinalArchives || row?.has_verified_final_archive !== true) return null;
    const href = finalArchiveRecordHref(row);
    if (!href) return null;

    return (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3 dark:border-emerald-900 dark:bg-emerald-950/20" aria-label="Final returned documents">
            <div className="min-w-0">
                <p className="text-[10px] font-bold uppercase tracking-wide text-emerald-800 dark:text-emerald-300">Final returned documents</p>
                <p className="mt-1 text-[11px] leading-5 text-gray-600 dark:text-gray-300">Open this report in Archives to review its Regional receipt and archive actions.</p>
            </div>
            <Link href={href} className="rounded-lg border border-emerald-700 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 dark:text-emerald-300 dark:hover:bg-emerald-950" data-cds-action="true" data-cds-action-variant="primary">Open Archives</Link>
        </section>
    );
}
