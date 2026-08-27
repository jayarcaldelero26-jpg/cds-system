import { FloatingSelect } from "@/Components/Form";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import Card from '../../../Components/Card';
import PageHeader from '../../../Components/PageHeader';
import PrimaryButton from '../../../Components/PrimaryButton';
import FormField from '../../../Components/FormField';

export default function Form({ title, user, roles }) {
  const isEdit = Boolean(user);
  const form = useForm({
    name: user?.name || '',
    email: user?.email || '',
    office_designated: user?.office_designated || '',
    section: user?.section || '',
    password: '',
    password_confirmation: '',
    role: user?.role || '',
    is_active: user?.is_active ?? true
  });

  const submit = (event) => {
    event.preventDefault();

    if (isEdit) {
      form.patch(`/admin/users/${user.id}`);
    } else {
      form.post('/admin/users');
    }
  };

  return (
    <AuthenticatedLayout title={title}>
            <PageHeader
        title={title}
        description={isEdit ? 'Update the account details, office, section, access role, and account status.' : 'Create a system user, specify their office/section, and assign their access role.'}
        actions={<Link href="/admin/users" className="text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400">← Back to users</Link>} />


            <Card className="mt-6 max-w-3xl">
                <form onSubmit={submit} className="space-y-6">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField
              id="name"
              label="Name"
              value={form.data.name}
              onChange={(event) => form.setData('name', event.target.value)}
              error={form.errors.name}
              required />

                        <FormField
              id="email"
              label="Email address"
              type="email"
              value={form.data.email}
              onChange={(event) => form.setData('email', event.target.value)}
              error={form.errors.email}
              required />


                        <FormField
              id="office_designated"
              label="Office Designated (e.g. CENRO Mati, PENRO Davao Oriental)"
              value={form.data.office_designated}
              onChange={(event) => form.setData('office_designated', event.target.value)}
              error={form.errors.office_designated}
              required />


                        <div className="block text-sm font-medium text-gray-700 dark:text-gray-200">

              <FloatingSelect id="form-section" label="Section"
              required

              value={form.data.section}
              onChange={(event) => form.setData('section', event.target.value)}>

                                <option value="">Select section</option>
                                <option value="CDS">Conservation Development Section (CDS)</option>
                                <option value="MES">Monitoring and Enforcement Section (MES)</option>
                            </FloatingSelect>
                            {form.errors.section && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.section}</p>}
                        </div>

                        <FormField
              id="password"
              label={isEdit ? 'New password' : 'Password'}
              type="password"
              value={form.data.password}
              onChange={(event) => form.setData('password', event.target.value)}
              error={form.errors.password}
              required={!isEdit}
              hint={isEdit ? 'Leave blank to keep the current password.' : undefined} />

                        <FormField
              id="password_confirmation"
              label="Confirm password"
              type="password"
              value={form.data.password_confirmation}
              onChange={(event) => form.setData('password_confirmation', event.target.value)}
              error={form.errors.password_confirmation}
              required={!isEdit} />


                        <div className="block text-sm font-medium text-gray-700 dark:text-gray-200">

              <FloatingSelect id="form-role" label="Role"
              required

              value={form.data.role}
              onChange={(event) => form.setData('role', event.target.value)}>

                                <option value="">Select a role</option>
                                {roles.map((role) => <option key={role} value={role}>{role}</option>)}
                            </FloatingSelect>
                            {form.errors.role && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.role}</p>}
                        </div>

                        <div className="block text-sm font-medium text-gray-700 dark:text-gray-200">

              <FloatingSelect id="form-account-status" label="Account status"

              value={form.data.is_active ? '1' : '0'}
              onChange={(event) => form.setData('is_active', event.target.value === '1')}>

                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </FloatingSelect>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : isEdit ? 'Save changes' : 'Create user'}
                        </PrimaryButton>
                        <Link href="/admin/users" className="rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">
                            Cancel
                        </Link>
                    </div>
                </form>
            </Card>

            {/* 🚀 Success Pop-up Modal / Dialog */}
        </AuthenticatedLayout>);

}
