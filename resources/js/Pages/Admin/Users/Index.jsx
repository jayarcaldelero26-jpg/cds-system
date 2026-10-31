import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import ConfirmDialog from '../../../Components/ConfirmDialog';
import CrudDetailsModal from '../../../Components/Crud/CrudDetailsModal';
import CrudSection from '../../../Components/Crud/CrudSection';
import CrudSummaryGrid from '../../../Components/Crud/CrudSummaryGrid';
import CrudTable from '../../../Components/Crud/CrudTable';
import PageHeader from '../../../Components/PageHeader';
import StatusBadge from '../../../Components/StatusBadge';

const statusMessages = {
    'user-created': 'User account created successfully.',
    'user-updated': 'User account updated successfully.',
    'user-deleted': 'User account deleted successfully.',
    'user-approved': 'User account approved successfully.'
};

const categoryLabels = {
    PAMO: 'PAMO',
    CENRO_RECORDS: 'CENRO Records Unit',
    PENRO_RECORDS: 'PENRO Records',
    OFFICE_OF_THE_PENRO: 'Office of the PENRO',
    PENRO_TSD_CHIEF: 'PENRO TSD Chief',
    CENRO_CDS_CHIEF: 'CENRO CDS Chief',
    CENRO_CDS_FOCAL: 'CENRO CDS Focal Person',
    PENRO_CDS_CHIEF: 'PENRO CDS Chief',
    PENRO_CDS_FOCAL: 'PENRO CDS Focal Person',
};

function accountStatus(user) {
    if (!user?.is_approved) return { label: 'Pending Approval', variant: 'pending' };
    if (user?.is_active) return { label: 'Active', variant: 'active' };
    return { label: 'Inactive', variant: 'inactive' };
}

function display(value) {
    return hasDisplayValue(value) ? value : 'Not provided';
}

function hasDisplayValue(value) {
    return value !== null && value !== undefined && (typeof value !== 'string' || value.trim() !== '');
}

export function userCategoryLabel(value) {
    if (!hasDisplayValue(value)) return value;
    if (categoryLabels[value]) return categoryLabels[value];
    const acronyms = new Set(['PENRO', 'CENRO', 'CDS', 'TSD', 'PAMO', 'PASU', 'PA']);
    return String(value).split('_').map(part => acronyms.has(part) ? part : `${part.charAt(0)}${part.slice(1).toLowerCase()}`).join(' ');
}

function Detail({ label, value }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-1 break-words font-medium text-gray-900 dark:text-white">{display(value)}</dd></div>;
}

export function UserOrganizationDetails({ user }) {
    const category = hasDisplayValue(user.effective_category) ? user.effective_category : user.section;
    const pamo = category === 'PAMO';
    const items = [
        ['User Category', userCategoryLabel(category)],
        ['Unit', user.unit_assignment],
        [pamo ? 'Canonical PAMO Office' : 'Office', pamo ? user.pamo_office_name : user.office_designated],
        ['Protected Area / PAMO Assignment', user.protected_area_name],
        ...(pamo ? [['Supervising Office', user.supervising_office_name]] : []),
    ].filter(([, value]) => hasDisplayValue(value));
    if (!items.length && !user.pamo_assignment_issue) return null;
    return <CrudSection title="Organization">
        <dl className="grid min-w-0 gap-x-6 gap-y-5 sm:grid-cols-2">{items.map(([label, value]) => <Detail key={label} label={label} value={value} />)}</dl>
        {user.pamo_assignment_issue && <p role="alert" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">PAMO assignment needs correction: {user.pamo_assignment_issue}</p>}
    </CrudSection>;
}

export function UserRecordInformation({ user }) {
    const items = [['Registration Date', user.created_at], ['Last Updated', user.updated_at]].filter(([, value]) => hasDisplayValue(value));
    if (!items.length) return null;
    return <CrudSection title="Record Information"><dl className="grid min-w-0 gap-x-6 gap-y-5 sm:grid-cols-2">{items.map(([label, value]) => <Detail key={label} label={label} value={value} />)}</dl></CrudSection>;
}

export default function Index({ users, status, protectedAreaAssignments = [], canViewProtectedAreas = false, canManageProtectedAreas = false }) {
    const { flash = {}, errors = {} } = usePage().props;
    const [selectedUser, setSelectedUser] = useState(null);
    const [userToDelete, setUserToDelete] = useState(null);
    const [userToApprove, setUserToApprove] = useState(null);
    const [userToActivate, setUserToActivate] = useState(null);
    const [userToDeactivate, setUserToDeactivate] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [approving, setApproving] = useState(false);
    const [activating, setActivating] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    const closeDetails = () => setSelectedUser(null);

    const deactivateUser = () => {
        if (!userToDeactivate || deactivating) return;
        setDeactivating(true);
        router.patch(`/admin/users/${userToDeactivate.id}/deactivate`, {}, {
            preserveScroll: true,
            onSuccess: () => setUserToDeactivate(null),
            onFinish: () => setDeactivating(false),
        });
    };

    const deleteUser = () => {
        if (!userToDelete || deleting) return;
        setDeleting(true);
        router.delete(`/admin/users/${userToDelete.id}`, {
            onFinish: () => { setDeleting(false); setUserToDelete(null); }
        });
    };

    const approveUser = () => {
        if (!userToApprove || approving) return;
        setApproving(true);
        router.patch(`/admin/users/${userToApprove.id}/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => setUserToApprove(null),
            onFinish: () => setApproving(false),
        });
    };

    const activateUser = () => {
        if (!userToActivate || activating) return;
        setActivating(true);
        router.patch(`/admin/users/${userToActivate.id}/activate`, {}, {
            preserveScroll: true,
            onSuccess: () => setUserToActivate(null),
            onFinish: () => setActivating(false),
        });
    };

    const selectedStatus = accountStatus(selectedUser);

    const columns = [
        { key: 'name', label: 'User', render: (user) => <div className="min-w-[190px]"><div className="font-semibold text-gray-900 dark:text-white">{user.name}</div><div className="mt-0.5 text-xs font-normal text-gray-500 dark:text-gray-400">{user.email}</div></div> },
        { key: 'account_role', label: 'Account Type / Role', render: (user) => <div><div>{display(user.account_role)}</div><div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Profile: {display(user.role)}</div></div> },
        { key: 'scope', label: 'Office / Protected Area', render: (user) => <div className="min-w-0 whitespace-normal"><div className="font-medium">{user.effective_category === 'PAMO' ? display(user.pamo_office_name) : display(user.office_designated)}</div>{user.protected_area_name && <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{user.protected_area_name}</div>}{user.pamo_assignment_issue && <div className="mt-1 text-xs font-medium text-amber-800 dark:text-amber-300">Mapping needs correction</div>}</div> },
        { key: 'category', label: 'User Category', render: (user) => display(userCategoryLabel(user.effective_category || user.section)) },
        { key: 'approval', label: 'Approval', render: (user) => <StatusBadge variant={user.is_approved ? 'active' : 'pending'}>{user.is_approved ? 'Approved' : 'Pending'}</StatusBadge> },
        { key: 'is_active', label: 'Activity', render: (user) => <StatusBadge variant={user.is_active ? 'active' : 'inactive'}>{user.is_active ? 'Active' : 'Inactive'}</StatusBadge> },
    ];

    return (
        <AuthenticatedLayout title="User Management">
            <PageHeader
                title="User Management"
                description="Manage authorized CDS-SMART users, their office, section, and access roles. Click a user row to view details and administrative actions."
            />

            <div className="cds-action-row"><Link href="/admin/users/create" data-cds-action="true" data-cds-action-variant="primary" className="cds-page-action inline-flex items-center justify-center text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2">Add user</Link>{canViewProtectedAreas && <Link href="/protected-areas" className="cds-page-action inline-flex items-center justify-center border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">Review PA mappings</Link>}</div>

            {statusMessages[status] && <div className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/50 dark:text-emerald-300" role="status">{statusMessages[status]}</div>}
            {flash.error && <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-200" role="alert">{flash.error}</div>}
            {errors.assignment && <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-200" role="alert">Account approval was blocked: {errors.assignment}</div>}

            <section className="mt-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div><h2 className="text-sm font-bold text-gray-950 dark:text-white">Protected Area PAMO eligibility</h2><p className="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">Each reason comes from the current active status, stable PAMO identity, and required supervising office mapping.</p></div>
                    {canManageProtectedAreas && <Link href="/protected-areas" className="text-xs font-semibold text-green-800 underline dark:text-green-300">Open mapping controls</Link>}
                </div>
                <div className="mt-3 overflow-x-auto">
                    <table className="min-w-full text-left text-xs"><thead className="text-[10px] uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr><th className="px-2 py-2">Protected Area</th><th className="px-2 py-2">Status</th><th className="px-2 py-2">Canonical PAMO</th><th className="px-2 py-2">Supervisor</th><th className="px-2 py-2">Eligibility</th><th className="px-2 py-2">Action</th></tr></thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-800">{protectedAreaAssignments.map((area) => <tr key={area.id}>
                            <td className="px-2 py-2 font-semibold text-gray-900 dark:text-white">{area.name}{area.short_name ? ` (${area.short_name})` : ''}</td>
                            <td className="px-2 py-2">{area.status}</td>
                            <td className="px-2 py-2">{display(area.pamo_office_name)}</td>
                            <td className="px-2 py-2">{display(area.supervising_office_name)}</td>
                            <td className="px-2 py-2"><span className={area.eligible ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300'}>{area.eligible ? 'Eligible' : area.issue}</span></td>
                            <td className="px-2 py-2">{canManageProtectedAreas ? <Link href={`/protected-areas/${area.id}/edit`} className="font-semibold text-green-800 underline dark:text-green-300">Edit mapping</Link> : 'View only'}</td>
                        </tr>)}</tbody>
                    </table>
                    {!protectedAreaAssignments.length && <p className="px-2 py-4 text-xs text-gray-600 dark:text-gray-300">No Protected Area records are available.</p>}
                </div>
            </section>

            <div className="mt-6">
                <CrudTable
                    title="User Accounts"
                    subtitle={`${users.total ?? users.data.length} account${(users.total ?? users.data.length) === 1 ? '' : 's'}`}
                    helperText="Click any row to view user details and administrative actions"
                    columns={columns}
                    rows={users.data}
                    rowKey="id"
                    onRowClick={setSelectedUser}
                    emptyTitle="No users found"
                    emptyDescription="User accounts will appear here when they are added."
                    tableClassName="min-w-[980px]"
                    caption="User management accounts"
                />
            </div>

            <div className="cds-pagination mt-5 flex items-center justify-between text-sm">
                {users.prev_page_url ? <Link href={users.prev_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">← Previous</Link> : <span />}
                {users.next_page_url && <Link href={users.next_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Next →</Link>}
            </div>

            <CrudDetailsModal
                open={Boolean(selectedUser)}
                title={selectedUser?.name || 'User Details'}
                subtitle={selectedUser?.email || ''}
                onClose={closeDetails}
                canEdit={Boolean(selectedUser)}
                canDelete={Boolean(selectedUser?.can_delete)}
                onEdit={() => { router.visit(`/admin/users/${selectedUser.id}/edit`); closeDetails(); }}
                onDelete={() => { setUserToDelete(selectedUser); closeDetails(); }}
                editLabel="Edit Access"
                deleteLabel="Delete User"
            >
                {selectedUser && <>
                    <CrudSummaryGrid items={[{ label: 'Approval Status', render: () => <StatusBadge variant={selectedUser.is_approved ? 'active' : 'pending'}>{selectedUser.is_approved ? 'Approved' : 'Pending Approval'}</StatusBadge> }, { label: 'Activity Status', render: () => <StatusBadge variant={selectedStatus.variant}>{selectedStatus.label}</StatusBadge> }, { label: 'Account Type', value: display(selectedUser.account_role) }, { label: 'Permission Profile', value: display(selectedUser.role) }]} />
                    <UserOrganizationDetails user={selectedUser} />
                    <UserRecordInformation user={selectedUser} />
                    <CrudSection title="Administrative Actions">
                        <div className="flex flex-wrap gap-2">
                            {!selectedUser.is_approved ? <button type="button" onClick={() => { setUserToApprove(selectedUser); closeDetails(); }} className="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300" data-cds-action="true" data-cds-action-variant="primary">Approve Account</button> : selectedUser.is_active ? <button type="button" onClick={() => { setUserToDeactivate(selectedUser); closeDetails(); }} className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300" data-cds-action="true" data-cds-action-variant="warning">Deactivate Account</button> : <button type="button" onClick={() => { setUserToActivate(selectedUser); closeDetails(); }} className="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300" data-cds-action="true" data-cds-action-variant="primary">Activate Account</button>}
                        </div>
                    </CrudSection>
                </>}
            </CrudDetailsModal>

            <ConfirmDialog
                open={Boolean(userToApprove)}
                title="Approve this account?"
                message={userToApprove ? `User: ${userToApprove.name}\nEmail: ${userToApprove.email}\nApproval will not change the account status, role, or organizational assignment.` : ''}
                confirmLabel="Approve Account"
                onCancel={() => !approving && setUserToApprove(null)}
                onConfirm={approveUser}
                processing={approving}
            />
            <ConfirmDialog
                open={Boolean(userToDelete)}
                variant="danger"
                title="Delete user account?"
                message={`Delete ${userToDelete?.name}'s account? This action cannot be undone.`}
                confirmLabel="Delete User"
                onCancel={() => !deleting && setUserToDelete(null)}
                onConfirm={deleteUser}
                processing={deleting}
            />
            <ConfirmDialog
                open={Boolean(userToActivate)}
                title="Activate this account?"
                message={userToActivate ? `User: ${userToActivate.name}\nUser Category: ${userCategoryLabel(userToActivate.effective_category || userToActivate.section) || 'Not assigned'}\nUnit: ${userToActivate.unit_assignment || 'Not assigned'}\nOffice: ${userToActivate.office_designated || 'Not assigned'}${userToActivate.protected_area_name ? `\nProtected Area: ${userToActivate.protected_area_name}` : ''}` : ''}
                confirmLabel="Activate Account"
                onCancel={() => !activating && setUserToActivate(null)}
                onConfirm={activateUser}
                processing={activating}
            />
            <ConfirmDialog
                open={Boolean(userToDeactivate)}
                title="Deactivate this account?"
                message="The user will no longer be able to sign in until the account is reactivated. The account role and organizational scope will be preserved."
                confirmLabel="Deactivate Account"
                onCancel={() => !deactivating && setUserToDeactivate(null)}
                onConfirm={deactivateUser}
                processing={deactivating}
            />
        </AuthenticatedLayout>
    );
}
