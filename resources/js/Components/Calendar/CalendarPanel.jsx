import { useState } from 'react';
import { Icon } from '@iconify/react';
import { addMonths, calendarDays, createDateOnly, formatDateKey, isDateDisabled, isSameDate, monthLabel, monthStart, parseDateOnly, todayDate } from './calendarUtils';

const weekdays = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
const monthNames = Array.from({ length: 12 }, (_, index) => new Intl.DateTimeFormat('en-US', { month: 'short' }).format(createDateOnly(2020, index, 1)));

function NavigationButton({ label, onClick }) {
    return <button type="button" onClick={onClick} aria-label={label} className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-500 transition hover:bg-green-50 hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-gray-300 dark:hover:bg-green-950/40 dark:hover:text-green-300"><Icon icon={label === 'Previous month' ? 'solar:alt-arrow-left-linear' : 'solar:alt-arrow-right-linear'} width="18" height="18" aria-hidden="true" /></button>;
}

function TitleButton({ children, onClick, label }) {
    return <button type="button" onClick={onClick} aria-label={label} className="rounded-lg px-2 py-1 text-sm font-extrabold text-gray-900 transition hover:bg-green-50 hover:text-green-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 dark:text-white dark:hover:bg-green-950/40 dark:hover:text-green-300">{children}</button>;
}

export default function CalendarPanel({ month, onMonthChange, months = 1, selectedDate = '', rangeStart = '', rangeEnd = '', onSelectDate, minDate = '', maxDate = '' }) {
    const firstMonth = monthStart(month);
    const [pickerView, setPickerView] = useState('day');
    const [pickerYear, setPickerYear] = useState(firstMonth.getFullYear());
    const [pickerMonth, setPickerMonth] = useState(firstMonth.getMonth());
    const today = formatDateKey(todayDate());

    const openMonthPicker = (value) => { const selectedMonth = monthStart(value); setPickerYear(selectedMonth.getFullYear()); setPickerMonth(selectedMonth.getMonth()); setPickerView('month'); };
    const chooseYear = (year) => { setPickerYear(year); setPickerView('month'); onMonthChange(createDateOnly(year, pickerMonth, 1)); };
    const chooseMonth = (monthIndex) => { setPickerMonth(monthIndex); onMonthChange(createDateOnly(pickerYear, monthIndex, 1)); setPickerView('day'); };
    const selectDate = (key) => { if (!isDateDisabled(key, minDate, maxDate)) onSelectDate?.(key); };

    if (pickerView === 'year') {
        const startYear = Math.floor(pickerYear / 10) * 10;
        return <div className="p-4"><div className="mb-3 flex items-center justify-between"><NavigationButton label="Previous month" onClick={() => setPickerYear(startYear - 10)} /><TitleButton label="Choose month" onClick={() => setPickerView('month')}>{startYear} – {startYear + 9}</TitleButton><NavigationButton label="Next month" onClick={() => setPickerYear(startYear + 10)} /></div><div className="grid grid-cols-3 gap-2 sm:grid-cols-4">{Array.from({ length: 12 }, (_, index) => startYear + index).map((year) => <button key={year} type="button" onClick={() => chooseYear(year)} className={`rounded-lg px-3 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${year === firstMonth.getFullYear() ? 'bg-green-700 text-white' : 'text-gray-700 hover:bg-green-50 hover:text-green-800 dark:text-gray-200 dark:hover:bg-green-950/40'}`}>{year}</button>)}</div></div>;
    }

    if (pickerView === 'month') {
        return <div className="p-4"><div className="mb-3 flex items-center justify-between"><NavigationButton label="Previous month" onClick={() => setPickerYear(pickerYear - 1)} /><TitleButton label="Choose year" onClick={() => setPickerView('year')}>{pickerYear}</TitleButton><NavigationButton label="Next month" onClick={() => setPickerYear(pickerYear + 1)} /></div><div className="grid grid-cols-3 gap-2 sm:grid-cols-4">{monthNames.map((name, index) => <button key={name} type="button" onClick={() => chooseMonth(index)} className={`rounded-lg px-3 py-3 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${pickerYear === firstMonth.getFullYear() && index === firstMonth.getMonth() ? 'bg-green-700 text-white' : 'text-gray-700 hover:bg-green-50 hover:text-green-800 dark:text-gray-200 dark:hover:bg-green-950/40'}`}>{name}</button>)}</div></div>;
    }

    const renderMonth = (displayMonth, index) => <section key={formatDateKey(displayMonth)} className={months === 2 && index === 1 ? 'hidden md:block min-w-0 flex-1 p-3 sm:p-4' : 'min-w-0 flex-1 p-3 sm:p-4'}>
        <div className="mb-2 flex justify-center"><TitleButton label={`Choose month and year for ${monthLabel(displayMonth)}`} onClick={() => openMonthPicker(displayMonth)}>{monthLabel(displayMonth)}</TitleButton></div>
        <div className="grid grid-cols-7 text-center text-[10px] font-bold uppercase tracking-wide text-gray-400">{weekdays.map((day) => <span key={day} className="py-1">{day}</span>)}</div>
        <div className="grid grid-cols-7 gap-y-0.5">{calendarDays(displayMonth).map(({ date, key }) => {
            const inMonth = date.getMonth() === displayMonth.getMonth();
            const selected = selectedDate && isSameDate(key, selectedDate);
            const start = rangeStart && isSameDate(key, rangeStart);
            const end = rangeEnd && isSameDate(key, rangeEnd);
            const inRange = rangeStart && rangeEnd && key >= formatDateKey(rangeStart) && key <= formatDateKey(rangeEnd);
            const disabled = isDateDisabled(key, minDate, maxDate);
            return <button key={key} type="button" disabled={disabled} onClick={() => selectDate(key)} aria-label={new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' }).format(date)} aria-current={key === today ? 'date' : undefined} aria-pressed={Boolean(selected || start || end)} className={`relative h-9 rounded-lg text-xs font-semibold transition focus:z-10 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 ${!inMonth ? 'text-gray-300 dark:text-gray-700' : 'text-gray-700 dark:text-gray-200'} ${disabled ? 'cursor-not-allowed opacity-35' : 'hover:bg-green-50 hover:text-green-800 dark:hover:bg-green-950/40 dark:hover:text-green-200'} ${inRange ? 'bg-green-100 text-green-900 dark:bg-green-900/50 dark:text-green-100' : ''} ${start ? 'rounded-l-lg bg-green-700 text-white hover:bg-green-700 dark:bg-green-600' : ''} ${end ? 'rounded-r-lg bg-green-700 text-white hover:bg-green-700 dark:bg-green-600' : ''} ${selected ? 'bg-green-700 text-white hover:bg-green-700 dark:bg-green-600' : ''} ${key === today && !selected && !start && !end ? 'ring-1 ring-inset ring-green-500' : ''}`}>{date.getDate()}</button>;
        })}</div>
    </section>;

    return <div><div className="flex items-center justify-between border-b border-gray-100 px-3 py-2 dark:border-gray-800"><NavigationButton label="Previous month" onClick={() => onMonthChange(addMonths(firstMonth, -1))} /><span className="text-[11px] font-semibold text-gray-500">{months === 2 ? `${monthLabel(firstMonth)} / ${monthLabel(addMonths(firstMonth, 1))}` : 'Select a date'}</span><NavigationButton label="Next month" onClick={() => onMonthChange(addMonths(firstMonth, 1))} /></div><div className={`flex ${months === 2 ? 'flex-col divide-y divide-gray-100 dark:divide-gray-800 md:flex-row md:divide-x md:divide-y-0' : ''}`}>{Array.from({ length: months }, (_, index) => renderMonth(addMonths(firstMonth, index), index))}</div></div>;
}
