import { Skeleton } from '@/Components/Skeleton';

const sectionClass = 'rounded-2xl border border-slate-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900';

function FactRows({ count = 4 }) {
    return <div className="mt-5 grid grid-cols-1 gap-x-5 gap-y-4 sm:grid-cols-2">
        {Array.from({ length: count }, (_, index) => <div key={index} className="space-y-2">
            <Skeleton className="h-3 w-2/5" />
            <Skeleton className="h-4 w-3/4" />
        </div>)}
    </div>;
}

function DetailSection({ titleWidth = 'w-2/5', children }) {
    return <section className={sectionClass}>
        <Skeleton className={`h-4 ${titleWidth}`} />
        {children}
    </section>;
}

export default function HistoricalReportDetailSkeleton() {
    return <div role="status" aria-label="Loading report details" className="space-y-5">
        <span className="sr-only">Loading report details…</span>
        <div aria-hidden="true">
            <div className="mb-5 flex flex-wrap items-center gap-2">
                <Skeleton className="h-7 w-32 rounded-full" />
                <Skeleton className="h-7 w-24 rounded-full" />
                <Skeleton className="h-7 w-36 rounded-full" />
            </div>
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(280px,.7fr)]">
                <div className="space-y-4">
                    <section className={sectionClass}>
                        <div className="flex items-start gap-3">
                            <Skeleton className="h-9 w-9 shrink-0 rounded-xl" />
                            <div className="flex-1 space-y-2">
                                <Skeleton className="h-4 w-2/5" />
                                <Skeleton className="h-3 w-11/12" />
                                <Skeleton className="h-3 w-3/4" />
                            </div>
                        </div>
                        <div className="mt-5 space-y-3 rounded-xl border border-sky-200 p-3 dark:border-sky-900">
                            <Skeleton className="h-3 w-1/3" />
                            {[0, 1, 2].map(row => <div key={row} className="flex justify-between gap-4 border-t border-sky-100 pt-2 dark:border-sky-900">
                                <Skeleton className="h-3 w-1/3" />
                                <Skeleton className="h-3 w-1/4" />
                            </div>)}
                        </div>
                        <FactRows count={4} />
                    </section>
                    <DetailSection titleWidth="w-1/3">
                        <FactRows count={6} />
                    </DetailSection>
                </div>
                <aside className="space-y-4">
                    <DetailSection titleWidth="w-1/2">
                        <div className="mt-4 rounded-xl border border-slate-200 p-4 dark:border-gray-700">
                            <Skeleton className="h-3 w-1/3" />
                            <Skeleton className="mt-3 h-8 w-1/2" />
                            <Skeleton className="mt-2 h-3 w-4/5" />
                        </div>
                    </DetailSection>
                    <DetailSection titleWidth="w-2/3">
                        <Skeleton className="mt-2 h-3 w-full" />
                        {[0, 1, 2].map(row => <div key={row} className="mt-3 rounded-xl border border-slate-200 p-3 dark:border-gray-700">
                            <Skeleton className="h-3 w-2/5" />
                            <Skeleton className="mt-2 h-5 w-1/3" />
                            <Skeleton className="mt-2 h-3 w-3/4" />
                        </div>)}
                    </DetailSection>
                    <DetailSection titleWidth="w-1/2">
                        {[0, 1].map(row => <div key={row} className="mt-3 flex items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-gray-700">
                            <Skeleton className="h-8 w-8 shrink-0 rounded-lg" />
                            <div className="flex-1 space-y-2"><Skeleton className="h-3 w-3/4" /><Skeleton className="h-3 w-1/2" /></div>
                        </div>)}
                    </DetailSection>
                    <DetailSection titleWidth="w-1/3">
                        <div className="mt-4 space-y-4">
                            {[0, 1, 2, 3].map(row => <div key={row} className="flex justify-between gap-3 border-t border-slate-100 pt-2.5 dark:border-gray-800">
                                <Skeleton className="h-3 w-1/3" />
                                <Skeleton className="h-3 w-2/5" />
                            </div>)}
                        </div>
                    </DetailSection>
                </aside>
            </div>
        </div>
    </div>;
}
