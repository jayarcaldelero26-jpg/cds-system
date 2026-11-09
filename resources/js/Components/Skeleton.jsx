import React from 'react';

export function Skeleton({ className = '' }) { return <div className={`cds-skeleton rounded bg-gray-200 dark:bg-gray-700 ${className}`} aria-hidden="true" />; }

export function TableSkeleton({ rows = 5, columns = 4 }) { return <div className="space-y-3 p-5">{Array.from({ length: rows }, (_, row) => <div key={row} className="grid gap-4" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>{Array.from({ length: columns }, (_, column) => <Skeleton key={column} className="h-5" />)}</div>)}</div>; }

const line = (width = 'w-full', height = 'h-4') => <Skeleton className={`${width} ${height}`} />;

export function NavigationSkeleton({ family = 'generic' }) {
    const title = line('w-1/3', 'h-7');
    const cards = <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">{[1, 2, 3, 4].map((item) => <div key={item} className="space-y-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">{line('w-2/5', 'h-3')}{line('w-3/5', 'h-7')}{line('w-4/5', 'h-3')}</div>)}</div>;
    const table = <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"><div className="grid grid-cols-2 gap-4 border-b border-gray-200 p-4 sm:grid-cols-4 dark:border-gray-800">{[1, 2, 3, 4].map((item) => <div key={item}>{line('w-4/5', 'h-4')}</div>)}</div><TableSkeleton rows={6} columns={4} /></div>;
    const content = family === 'reports' ? <div className="space-y-6">{cards}{table}</div>
        : family === 'bms-tracker' ? <div className="space-y-6"><div className="grid grid-cols-2 gap-3 sm:grid-cols-4">{[1, 2, 3, 4].map((item) => <div key={item} className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">{line('w-1/2', 'h-3')}<div className="mt-4">{line('w-2/3', 'h-6')}</div></div>)}</div>{table}</div>
        : family === 'calendar' ? <div className="overflow-hidden rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900"><div className="mb-5 grid grid-cols-7 gap-2">{[1, 2, 3, 4, 5, 6, 7].map((item) => <div key={item}>{line('w-3/4', 'h-3')}</div>)}</div><div className="grid grid-cols-7 gap-2">{Array.from({ length: 35 }, (_, item) => <div key={item} className="min-h-16 rounded-lg border border-gray-100 p-2 dark:border-gray-800 sm:min-h-24">{line('w-5', 'h-3')}</div>)}</div></div>
        : family === 'form' ? <div className="max-w-4xl space-y-6 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900"><div className="grid grid-cols-1 gap-5 sm:grid-cols-2">{Array.from({ length: 6 }, (_, item) => <div key={item} className="space-y-2">{line('w-2/5', 'h-3')}{line('w-full', 'h-11')}</div>)}</div>{line('w-28', 'h-10')}</div>
        : <div className="space-y-6">{cards}{table}</div>;
    return <section className="space-y-6" aria-hidden="true" data-cds-navigation-skeleton={family}>{title}{line('w-2/5', 'h-4')}{content}</section>;
}
