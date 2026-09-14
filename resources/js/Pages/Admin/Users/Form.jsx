import { FloatingSelect } from "@/Components/Form";
import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import Card from '../../../Components/Card';
import PageHeader from '../../../Components/PageHeader';
import PrimaryButton from '../../../Components/PrimaryButton';
import FormField from '../../../Components/FormField';

export default function Form({ title, user, operationalGroups = [], protectedAreas = [], offices = [], accountRoles = [] }) {
    const isEdit = Boolean(user);
    const accountRoleOptions = accountRoles.length ? accountRoles : [{ value: 'User', label: 'User' }, { value: 'Super Admin', label: 'Super Admin' }];
    const form = useForm({
        name: user?.name || '',
        email: user?.email || '',
        account_role: user?.account_role || 'User',
        operational_group: user?.operational_group || '',
        office_designated: user?.office_designated || '',
        section: user?.effective_category || user?.section || '',
        protected_area_id: user?.protected_area_id || '',
        password: '',
        password_confirmation: '',
    });

    const selectedGroup = operationalGroups.find((group) => group.value === form.data.operational_group);
    const categoryOptions = selectedGroup?.categories || [];
    const isCenroGroup = form.data.operational_group === 'cenro';
    const isPenroGroup = form.data.operational_group === 'penro';
    const isPamo = form.data.operational_group === 'pamo';
    const cenroOffices = offices.filter((office) => office?.is_active !== false && office?.office_type === 'cenro');

    const setOperationalGroup = (groupValue) => {
        const group = operationalGroups.find((item) => item.value === groupValue);
        form.setData((current) => ({
            ...current,
            operational_group: groupValue,
            section: groupValue === 'pamo' ? 'PAMO' : '',
            office_designated: '',
            protected_area_id: '',
        }));
    };

    const setCategory = (section) => form.setData((current) => ({
        ...current,
        section,
        office_designated: current.operational_group === 'penro' ? 'PENRO Davao Oriental' : '',
        protected_area_id: current.operational_group === 'pamo' ? current.protected_area_id : '',
    }));

    return (
        <AuthenticatedLayout title={title}>
            <PageHeader title={title} description={isEdit ? 'Update the account role, user category, and organizational scope.' : 'Create a User or Super Admin account with explicit organizational scope.'} actions={<div className="flex flex-wrap items-center gap-3">{isEdit && <span className={user.is_active ? 'inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800' : 'inline-flex items-center rounded-full border border-slate-200 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700'}>{user.is_active ? 'Active' : 'Inactive'}</span>}<Link href="/admin/users" className="text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Back to users</Link></div>} />
            <Card className="mt-6 max-w-3xl">
                <form onSubmit={(event) => { event.preventDefault(); isEdit ? form.patch('/admin/users/' + user.id) : form.post('/admin/users'); }} className="space-y-6">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField id="name" label="Name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required />
                        <FormField id="email" label="Email address" type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} required />
                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            <FloatingSelect id="form-account-role" label="Account Role" required value={form.data.account_role} onChange={(event) => form.setData('account_role', event.target.value)}>
                                <option value="">Select account role</option>
                                {accountRoleOptions.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                            </FloatingSelect>
                            {form.errors.account_role && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.account_role}</p>}
                        </div>
                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            <FloatingSelect id="form-operational-group" label="Operational Group" required value={form.data.operational_group} onChange={(event) => setOperationalGroup(event.target.value)}>
                                <option value="">Select operational group</option>
                                {operationalGroups.map((group) => <option key={group.value} value={group.value}>{group.label}</option>)}
                            </FloatingSelect>
                            {form.errors.operational_group && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.operational_group}</p>}
                            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Select the organizational group for the requested account.</p>
                        </div>
                        {!isPamo && <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200 sm:col-span-2">
                            <FloatingSelect id="form-section" label="User Category" required value={form.data.section} disabled={!selectedGroup} onChange={(event) => setCategory(event.target.value)}>
                                <option value="">Select user category</option>
                                {categoryOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                            </FloatingSelect>
                            {form.errors.section && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.section}</p>}
                        </div>}
                        {isCenroGroup && <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200 sm:col-span-2">
                            <FloatingSelect id="form-office" label="CENRO Office" required value={form.data.office_designated} onChange={(event) => form.setData('office_designated', event.target.value)}>
                                <option value="">Select CENRO office</option>
                                {cenroOffices.map((office) => <option key={office.id} value={office.name}>{office.label || office.name}</option>)}
                            </FloatingSelect>
                            {form.errors.office_designated && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.office_designated}</p>}
                        </div>}
                        {isPamo && <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200 sm:col-span-2">
                            <FloatingSelect id="form-protected-area" label="Protected Area / PAMO assignment" required value={form.data.protected_area_id} onChange={(event) => form.setData('protected_area_id', event.target.value)}>
                                <option value="">Select protected area</option>
                                {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                            </FloatingSelect>
                            {form.errors.protected_area_id && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.protected_area_id}</p>}
                        </div>}
                        {isPenroGroup && <div className="sm:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-900 dark:border-emerald-100/20 dark:bg-emerald-950/30 dark:text-emerald-200">PENRO Davao Oriental · province-wide · Conservation and Development</div>}
                        {form.data.operational_group === 'cenro' && <div className="sm:col-span-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900/60 dark:text-slate-300">CENRO categories cover both Conservation and Development within the selected office; no unit assignment is stored.</div>}
                        <FormField id="password" label={isEdit ? 'New password' : 'Password'} type="password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} required={!isEdit} hint={isEdit ? 'Leave blank to keep the current password.' : undefined} />
                        <FormField id="password_confirmation" label="Confirm password" type="password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} error={form.errors.password_confirmation} required={!isEdit} />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton type="submit" disabled={form.processing}>{form.processing ? 'Saving...' : isEdit ? 'Save changes' : 'Create user'}</PrimaryButton>
                        <Link href="/admin/users" className="rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">Cancel</Link>
                    </div>
                </form>
            </Card>
        </AuthenticatedLayout>
    );
}
