import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@/../css/app.css';
import AuthField, { PasswordVisibilityToggle } from '@/Components/AuthField';
import ComplianceRichTextEditor from '@/Components/Compliance/ComplianceRichTextEditor';
import DatePicker from '@/Components/DatePicker';
import DateRangePicker from '@/Components/DateRangePicker';
import FloatingInput from '@/Components/Form/FloatingInput';
import FloatingSelect from '@/Components/Form/FloatingSelect';
import FloatingTextarea from '@/Components/Form/FloatingTextarea';
import PremiumTimePicker from '@/Components/PremiumTimePicker';

function Fixture() {
    const [contact, setContact] = useState('');
    const [zero, setZero] = useState(0);
    const [office, setOffice] = useState('');
    const [officeChanges, setOfficeChanges] = useState(0);
    const [day, setDay] = useState('2026-11-03');
    const [range, setRange] = useState({ from: '2026-11-01', to: '2026-11-02' });
    const [time, setTime] = useState('09:30');
    const [password, setPassword] = useState('fixture-password-only');
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [submitCount, setSubmitCount] = useState(0);
    const [darkMode, setDarkMode] = useState(false);
    const resetRef = useRef(null);
    useEffect(() => {
        document.documentElement.classList.toggle('dark', darkMode);
        return () => document.documentElement.classList.remove('dark');
    }, [darkMode]);

    return <main className="mx-auto max-w-3xl space-y-5 p-4 text-gray-900 dark:text-gray-100">
        <h1 className="text-xl font-bold">Floating label behavior fixture</h1>
        <p className="text-sm text-gray-600 dark:text-gray-300">Isolated local UI only. All form submissions are prevented.</p>
        <button id="fixture-theme" type="button" aria-pressed={darkMode} onClick={() => setDarkMode(value => !value)} className="rounded border px-3 py-1 text-sm">Switch to {darkMode ? 'light' : 'dark'} theme</button>
        <form id="floating-label-form" onSubmit={event => { event.preventDefault(); setSubmitCount(count => count + 1); }} className="space-y-4">
            <FloatingInput id="fixture-contact" name="contact" label="Contact name" placeholder="Type a contact name" value={contact} onChange={event => setContact(event.target.value)} />
            <button id="clear-contact" type="button" onClick={() => setContact('')} className="rounded border px-3 py-1 text-sm">Clear contact</button>
            <FloatingInput id="fixture-zero" name="quantity" label="Numeric zero" type="number" value={zero} onChange={event => setZero(event.target.value === '' ? '' : Number(event.target.value))} />
            <FloatingSelect id="fixture-office" name="office_code" label="Office" required value={office} onChange={event => { setOffice(event.target.value); setOfficeChanges(count => count + 1); }}>
                <option value="">Select office</option>
                <option value="CENRO-BAGANGA">CENRO Baganga</option>
                <option value="DISABLED-OFFICE" disabled>Unavailable office</option>
                <option value="PENRO">PENRO</option>
            </FloatingSelect>
            <output id="office-change-count" className="block text-xs text-gray-500">Office selection change events: {officeChanges}</output>
            <FloatingTextarea id="fixture-notes" name="notes" label="Long multiline review note" rows={3} value="A prefilled note remains below its label." readOnly />
            <ComplianceRichTextEditor id="fixture-compliance-body" label="Fixture message body" value="<p>Fixture-only body content.</p>" onChange={() => {}} error="Fixture editor error state." placeholder="Write fixture text." rows={3} />
            <FloatingInput id="fixture-error" label="Field with validation error" value="" readOnly required error="Enter a valid fixture value." />
            <FloatingInput id="fixture-disabled" label="Disabled field" value="Unavailable" disabled />
            <DatePicker id="fixture-date" label="Single date" value={day} onChange={setDay} />
            <DateRangePicker id="fixture-range" label="Date range" value={range} onChange={setRange} />
            <PremiumTimePicker id="fixture-time" name="time" label="Time" value={time} onChange={setTime} />
            <AuthField id="fixture-password" name="password" label="Fixture password" icon="lock" type={passwordVisible ? 'text' : 'password'} autoComplete="off" value={password} onChange={event => setPassword(event.target.value)}>
                <PasswordVisibilityToggle visible={passwordVisible} onToggle={() => setPasswordVisible(value => !value)} />
            </AuthField>
            <button id="fixture-submit" type="submit" className="rounded border px-3 py-1 text-sm">Submit fixture</button>
            <output id="submit-count" className="block text-xs text-gray-500">Prevented fixture submits: {submitCount}</output>
        </form>
        <form id="native-reset-form" ref={resetRef} onSubmit={event => event.preventDefault()} className="space-y-3">
            <FloatingInput id="fixture-reset" name="reset_sample" label="Uncontrolled reset sample" defaultValue="" placeholder="Type then reset" />
            <button id="fixture-reset-button" type="reset" className="rounded border px-3 py-1 text-sm">Reset native form</button>
        </form>
    </main>;
}

createRoot(document.getElementById('root')).render(<Fixture />);
