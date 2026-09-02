import { FloatingSelect } from "@/Components/Form";import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import Card from '../../../Components/Card';
import PageHeader from '../../../Components/PageHeader';
import PrimaryButton from '../../../Components/PrimaryButton';
import FormField from '../../../Components/FormField';

export default function Form({ title, user, categories = [], protectedAreas = [], offices = [] }) {
  const isEdit = Boolean(user);
  const sectionOptions = categories.length > 0 ? categories : [
    { value: 'CENRO_RECORDS', label: 'CENRO Records Unit' },
    { value: 'CENRO_CDS_CHIEF', label: 'CENRO CDS Chief' },
    { value: 'CENRO_CDS_FOCAL', label: 'CENRO CDS Focal Person' },
    { value: 'PENRO_CDS_CHIEF', label: 'PENRO CDS Chief' },
    { value: 'PENRO_CDS_FOCAL', label: 'PENRO CDS Focal Person' },
    { value: 'PAMO', label: 'PAMO' },
  ];
  const form = useForm({
    name: user?.name || '',
    email: user?.email || '',
    unit_assignment: user?.unit_assignment || '',
    office_designated: user?.office_designated || '',
    section: user?.effective_category || user?.section || '',
    protected_area_id: (user?.effective_category || user?.section) === 'PAMO' ? (user?.protected_area_id || '') : '',
    password: '',
    password_confirmation: '',
    is_active: user?.is_active ?? true
  });

  const filteredSectionOptions = form.data.unit_assignment === 'development'
    ? sectionOptions.filter((option) => option.value !== 'PAMO')
    : sectionOptions;
  const officeOptions = form.data.section?.startsWith('CENRO_')
    ? offices.filter((office) => office.startsWith('CENRO '))
    : form.data.section?.startsWith('PENRO_')
      ? offices.filter((office) => office.startsWith('PENRO '))
      : offices;

  const setCategory = (section) => form.setData((current) => ({
    ...current,
    section,
    office_designated: section.startsWith('CENRO_') && !current.office_designated.startsWith('CENRO ')
      || section.startsWith('PENRO_') && !current.office_designated.startsWith('PENRO ')
      ? '' : current.office_designated,
    protected_area_id: section === 'PAMO' ? current.protected_area_id : '',
  }));

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
        description={isEdit ? 'Update the account details, organizational assignment, and account status.' : 'Create a system user and configure their organizational assignment.'}
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


                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">
                          <FloatingSelect id="form-unit" label="Operational unit" required value={form.data.unit_assignment} onChange={(event) => form.setData((current) => ({ ...current, unit_assignment: event.target.value, section: '', office_designated: '', protected_area_id: '' }))}>
                            <option value="">Select operational unit</option>
                            <option value="conservation">Conservation Unit</option>
                            <option value="development">Development Unit</option>
                          </FloatingSelect>
                          {form.errors.unit_assignment && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.unit_assignment}</p>}
                        </div>

                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">
                          <FloatingSelect id="form-office" label="Office designated" required value={form.data.office_designated} onChange={(event) => form.setData('office_designated', event.target.value)}>
                            <option value="">Select office</option>
                            {officeOptions.map((office) => <option key={office} value={office}>{office}</option>)}
                          </FloatingSelect>
                          {form.errors.office_designated && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.office_designated}</p>}
                        </div>


                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">

              <FloatingSelect id="form-section" label="User category"
              required

              value={form.data.section}
              onChange={(event) => setCategory(event.target.value)}>

                                <option value="">Select user category</option>
                                {filteredSectionOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                            </FloatingSelect>
                            {form.errors.section && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.section}</p>}
                        </div>

                        {form.data.section === 'PAMO' && <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200 sm:col-span-2">
                            <FloatingSelect id="form-protected-area" label="Assigned Protected Area" required value={form.data.protected_area_id} onChange={(event) => form.setData('protected_area_id', event.target.value)}>
                                <option value="">Select protected area</option>
                                {protectedAreas.map((area) => <option key={area.id} value={area.id}>{area.name}</option>)}
                            </FloatingSelect>
                            {form.errors.protected_area_id && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300">{form.errors.protected_area_id}</p>}
                        </div>}

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


                        <div className="min-w-0 block text-sm font-medium text-gray-700 dark:text-gray-200">

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
