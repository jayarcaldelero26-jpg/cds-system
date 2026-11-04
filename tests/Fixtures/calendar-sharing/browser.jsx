import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import CalendarIndex from '@/Pages/Calendar/Index';

const syntheticTasks = [
    {
        id: 'fixture-shared-1',
        title: 'Fixture shared task',
        scheduled_date: '2026-11-05',
        scheduled_end_date: null,
        is_all_day: true,
        start_time: null,
        end_time: null,
        notes: 'Synthetic fixture only.',
        status: 'scheduled',
        visibility: 'shared',
        audiences: [{ label: 'Fixture CENRO' }],
        can_edit: false,
        can_complete: false,
        can_cancel: false,
        creator_attribution: {
            actor_name: 'Fixture creator',
            label: 'CENRO CDS Focal Person',
            office_name: 'Fixture Other CENRO',
        },
    },
    {
        id: 'fixture-private-owner-1',
        title: 'Fixture private owner task',
        scheduled_date: '2026-11-06',
        scheduled_end_date: null,
        is_all_day: true,
        start_time: null,
        end_time: null,
        notes: 'Synthetic fixture only.',
        status: 'scheduled',
        visibility: 'private',
        audiences: [],
        can_edit: true,
        can_complete: true,
        can_cancel: true,
        creator_attribution: {
            actor_name: 'Fixture signed-in creator',
            label: 'CENRO CDS Focal Person',
            office_name: 'Fixture CENRO',
        },
    },
];

function CalendarSharingFixture() {
    const [view, setView] = useState('month');

    return <>
        <nav aria-label="Fixture view controls" className="flex gap-2 p-3">
            <button id="fixture-month" type="button" onClick={() => setView('month')}>Fixture Month</button>
            <button id="fixture-year" type="button" onClick={() => setView('year')}>Fixture Year</button>
        </nav>
        <CalendarIndex
            view={view}
            year={2026}
            month="2026-11"
            filters={{}}
            modules={[]}
            protectedAreas={[]}
            movEvents={[]}
            yearSummary={{ months: {}, overview: {} }}
            nonWorkingDays={[]}
            tasks={syntheticTasks}
            taskStorageReady
            taskRangeReady
            taskSharingReady
            taskSharingOptions={{
                available: true,
                primary: { label: 'Fixture CENRO' },
                supervising: { label: 'Fixture PENRO' },
            }}
        />
    </>;
}

window.__calendarFixture = { mutations: [], routerCalls: [] };
globalThis.route = (name, id) => `/mock/${name}${id ? `/${id}` : ''}`;

createRoot(document.getElementById('root')).render(<CalendarSharingFixture />);
