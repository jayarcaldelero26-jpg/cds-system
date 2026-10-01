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
    return hasDisplayValue(value) ? value : '—';
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
    const items = [
        ['User Category', userCategoryLabel(category)],
        ['Unit', user.unit_assignment],
        ['Office', user.office_designated],
        ['Protected Area / PAMO Assignment', user.protected_area_name],
    ].filter(([, value]) => hasDisplayValue(value));
    if (!items.length) return null;
    return <CrudSection title="Organization"><dl className="grid min-w-0 gap-x-6 gap-y-5 sm:grid-cols-2">{items.map(([label, value]) => <Detail key={label} label={label} value={value} />)}</dl></CrudSection>;
}

export function UserRecordInformation({ user }) {
    const items = [['Registration Date', user.created_at], ['Last Updated', user.updated_at]].filter(([, value]) => hasDisplayValue(value));
    if (!items.length) return null;
    return <CrudSection title="Record Information"><dl className="grid min-w-0 gap-x-6 gap-y-5 sm:grid-cols-2">{items.map(([label, value]) => <Detail key={label} label={label} value={value} />)}</dl></CrudSection>;
}

export default function Index({ users, status }) {
    const { flash = {} } = usePage().props;
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
        { key: 'account_role', label: 'Account Role', render: (user) => display(user.account_role) },
        { key: 'scope', label: 'Office / Protected Area', render: (user) => <div className="min-w-0 whitespace-normal"><div className="font-medium">{display(user.office_designated)}</div>{user.protected_area_name && <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{user.protected_area_name}</div>}</div> },
        { key: 'category', label: 'User Category', render: (user) => display(userCategoryLabel(user.effective_category || user.section)) },
        { key: 'is_active', label: 'Account Status', render: (user) => { const current = accountStatus(user); return <StatusBadge variant={current.variant}>{current.label}</StatusBadge>; } },
    ];

    return (
        <AuthenticatedLayout title="User Management">
            <PageHeader
                title="User Management"
                description="Manage authorized CDS-SMART users, their office, section, and access roles. Click a user row to view details and administrative actions."
            />

            <div className="cds-action-row"><Link href="/admin/users/create" data-cds-action="true" data-cds-action-variant="primary" className="cds-page-action inline-flex items-center justify-center text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2">Add user</Link></div>

            {statusMessages[status] && <div className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/50 dark:text-emerald-300" role="status">{statusMessages[status]}</div>}
            {flash.error && <div className="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-800/50 dark:bg-amber-950/40 dark:text-amber-200" role="alert">{flash.error}</div>}

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
                    <CrudSummaryGrid items={[{ label: 'Approval Status', render: () => <StatusBadge variant={selectedUser.is_approved ? 'active' : 'pending'}>{selectedUser.is_approved ? 'Approved' : 'Pending Approval'}</StatusBadge> }, { label: 'Account Status', render: () => <StatusBadge variant={selectedStatus.variant}>{selectedStatus.label}</StatusBadge> }, { label: 'Account Role', value: display(selectedUser.account_role) }]} />
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
