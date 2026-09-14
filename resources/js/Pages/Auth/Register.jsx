import { Icon } from '@iconify/react';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AuthField, AuthSelect } from '../../Components/AuthField';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function Register({ registrationOptions = {} }) {
    const operationalGroups = Array.isArray(registrationOptions.operationalGroups) ? registrationOptions.operationalGroups : [];
    const offices = Array.isArray(registrationOptions.offices) ? registrationOptions.offices : [];
    const protectedAreas = Array.isArray(registrationOptions.protectedAreas) ? registrationOptions.protectedAreas : [];
    const accountRole = registrationOptions.accountRole || 'User';
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        operational_group: '',
        office_designated: '',
        section: '',
        protected_area_id: '',
        password: '',
        password_confirmation: '',
    });
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    const selectedGroup = operationalGroups.find((group) => group.value === data.operational_group);
    const categoryOptions = selectedGroup?.categories || [];
    const selectedCategory = categoryOptions.find((category) => category.value === data.section);
    const isCenroGroup = data.operational_group === 'cenro';
    const isPenroGroup = data.operational_group === 'penro';
    const isPamo = data.operational_group === 'pamo';
    const cenroOffices = offices.filter((office) => office?.office_type === 'cenro');

    const changeGroup = (event) => {
        const group = operationalGroups.find((item) => item.value === event.target.value);
        setData((current) => ({
            ...current,
            operational_group: event.target.value,
            section: event.target.value === 'pamo' ? 'PAMO' : '',
            office_designated: '',
            protected_area_id: '',
        }));
    };

    const changeCategory = (event) => {
        setData((current) => ({
            ...current,
            section: event.target.value,
            office_designated: current.operational_group === 'penro' ? 'PENRO Davao Oriental' : '',
            protected_area_id: current.operational_group === 'pamo' ? current.protected_area_id : '',
        }));
    };

    return (
        <AuthLayout title="Create an account" contentClassName="max-w-[34rem]">
            <div className="mt-5">
                <div className="border-b border-slate-200/80 pb-4 dark:border-emerald-100/15">
                    <h2 className="text-[1.45rem] font-semibold leading-[1.3] tracking-tight text-slate-900 dark:text-white">Create an eDATS account</h2>
                    <p className="mt-1.5 text-sm leading-5 text-slate-500 dark:text-slate-400">Submit your details for administrator review.</p>
                </div>

                <form onSubmit={(event) => { event.preventDefault(); post('/register'); }} className="mt-5 space-y-4">
                    <div className="rounded-xl border border-emerald-200/70 bg-emerald-50/70 px-4 py-3 dark:border-emerald-100/15 dark:bg-emerald-950/30">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-800 dark:text-emerald-300">Account role</p>
                        <p className="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{accountRole}</p>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">All public registrations are normal User accounts. Category and organizational scope are reviewed separately.</p>
                    </div>

                    <AuthSelect id="operational-group" label="Operational Group" name="operational_group" icon="building" value={data.operational_group} onChange={changeGroup} required error={errors.operational_group} hint="Select the organizational group for the requested account.">
                        <option value="">Select operational group</option>
                        {operationalGroups.map((group) => <option key={group.value} value={group.value}>{group.label}</option>)}
                    </AuthSelect>

                    {!isPamo && <AuthSelect id="section" label="User Category" name="section" icon="users-round" value={data.section} onChange={changeCategory} required disabled={!selectedGroup} error={errors.section}>
                        <option value="">Select user category</option>
                        {categoryOptions.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
                    </AuthSelect>}

                    {isCenroGroup && <AuthSelect id="office-designated" label="CENRO Office" name="office_designated" icon="building-2" value={data.office_designated} onChange={(event) => setData('office_designated', event.target.value)} required error={errors.office_designated} hint="CENRO office jurisdiction applies to the account's authorized work.">
                        <option value="">Select CENRO office</option>
                        {cenroOffices.map((office) => <option key={office.id} value={office.name}>{office.label || office.name}</option>)}
                    </AuthSelect>}

                    {isPenroGroup && selectedCategory && <p className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-900 dark:border-emerald-100/20 dark:bg-emerald-950/30 dark:text-emerald-200">
                        {selectedCategory.label} is assigned to PENRO Davao Oriental with province-wide Conservation and Development visibility appropriate to its workflow category.
                    </p>}

                    {data.operational_group === 'cenro' && selectedCategory && <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-300">
                        {selectedCategory.label} covers both Conservation and Development within the selected CENRO office.
                    </p>}

                    {isPamo && <AuthSelect id="protected-area-id" label="Protected Area / PAMO assignment" name="protected_area_id" icon="map" value={data.protected_area_id} onChange={(event) => setData('protected_area_id', event.target.value)} required error={errors.protected_area_id} hint="A Protected Area is required for PAMO accounts.">
                        <option value="">Select protected area</option>
                        {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                    </AuthSelect>}

                    <AuthField id="name" name="name" label="Full name" icon="user-round" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} autoComplete="name" autoFocus required />
                    <AuthField id="email" name="email" label="Email address" icon="mail" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} autoComplete="username" required />

                    <AuthField id="password" name="password" label="Password" icon="lock-keyhole" type={showPassword ? 'text' : 'password'} value={data.password} onChange={(event) => setData('password', event.target.value)} error={errors.password} autoComplete="new-password" required>
                        <button type="button" onClick={() => setShowPassword((visible) => !visible)} className="inline-flex rounded-lg p-1.5 text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950" aria-label={showPassword ? 'Hide password' : 'Show password'}>
                            <Icon icon={showPassword ? 'lucide:eye-off' : 'lucide:eye'} width="18" height="18" aria-hidden="true" />
                        </button>
                    </AuthField>

                    <AuthField id="password-confirmation" name="password_confirmation" label="Confirm password" icon="lock-keyhole" type={showPasswordConfirmation ? 'text' : 'password'} value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} error={errors.password_confirmation} autoComplete="new-password" required>
                        <button type="button" onClick={() => setShowPasswordConfirmation((visible) => !visible)} className="inline-flex rounded-lg p-1.5 text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950" aria-label={showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'}>
                            <Icon icon={showPasswordConfirmation ? 'lucide:eye-off' : 'lucide:eye'} width="18" height="18" aria-hidden="true" />
                        </button>
                    </AuthField>

                    <PrimaryButton className="h-11 w-full justify-center gap-2" type="submit" disabled={processing}>
                        <span>{processing ? 'Creating account...' : 'Create account'}</span>
                        {!processing && <Icon icon="lucide:user-plus" width="17" height="17" aria-hidden="true" />}
                    </PrimaryButton>
                </form>

                <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                    <span className="text-slate-500 dark:text-slate-400">Already have an account? <Link href="/login" className="font-semibold text-emerald-800 transition hover:text-emerald-950 dark:text-emerald-400 dark:hover:text-emerald-300">Sign in</Link></span>
                    <Link href={route('welcome')} className="inline-flex items-center gap-1.5 rounded font-semibold text-slate-600 transition hover:text-emerald-900 dark:text-slate-300 dark:hover:text-emerald-200"><Icon icon="lucide:house" width="16" height="16" aria-hidden="true" />Home</Link>
                </div>

                <p className="mt-4 text-center text-xs leading-5 text-slate-500 dark:text-slate-400">New accounts remain pending until an administrator reviews and approves them.</p>
            </div>
        </AuthLayout>
    );
}
