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
    'user-deleted': 'User account deleted successfully.'
};

const categoryLabels = {
    PAMO: 'PAMO',
    CENRO_RECORDS: 'CENRO Records Unit',
    CENRO_CDS_CHIEF: 'CENRO CDS Chief',
    CENRO_CDS_FOCAL: 'CENRO CDS Focal Person',
    PENRO_CDS_CHIEF: 'PENRO CDS Chief',
    PENRO_CDS_FOCAL: 'PENRO CDS Focal Person',
};

function accountStatus(user) {
    if (user?.is_active) return { label: 'Active', variant: 'active' };
    if (!user?.access_configured) return { label: 'Pending Approval', variant: 'pending' };
    return { label: 'Inactive', variant: 'inactive' };
}

function display(value) {
    return value || '—';
}

function Detail({ label, value }) {
    return <div className="min-w-0"><dt className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</dt><dd className="mt-1 break-words font-medium text-gray-900 dark:text-white">{display(value)}</dd></div>;
}

export default function Index({ users, status }) {
    const { flash = {} } = usePage().props;
    const [selectedUser, setSelectedUser] = useState(null);
    const [userToDelete, setUserToDelete] = useState(null);
    const [userToActivate, setUserToActivate] = useState(null);
    const [userToDeactivate, setUserToDeactivate] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [activating, setActivating] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    const closeDetails = () => setSelectedUser(null);

    const deactivateUser = () => {
        if (!userToDeactivate || deactivating) return;
        setDeactivating(true);
        router.patch(`/admin/users/${userToDeactivate.id}`, {
            name: userToDeactivate.name,
            email: userToDeactivate.email,
            office_designated: userToDeactivate.office_designated || '',
            section: userToDeactivate.section || '',
            unit_assignment: userToDeactivate.unit_assignment || '',
            protected_area_id: userToDeactivate.protected_area_id || '',
            is_active: false,
        }, {
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
        { key: 'name', label: 'Name', render: (user) => <span className="font-semibold text-gray-900 dark:text-white">{user.name}</span> },
        { key: 'email', label: 'Email' },
        { key: 'unit_assignment', label: 'Unit', render: (user) => display(user.unit_assignment) },
        { key: 'scope', label: 'Office / Protected Area', render: (user) => <div className="min-w-0 whitespace-normal"><div className="font-medium">{display(user.office_designated)}</div>{user.effective_category === 'PAMO' && user.protected_area_name && <div className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{user.protected_area_name}</div>}</div> },
        { key: 'category', label: 'User Category', render: (user) => display(categoryLabels[user.effective_category || user.section] || user.effective_category || user.section) },
        { key: 'is_active', label: 'Account Status', render: (user) => { const current = accountStatus(user); return <StatusBadge variant={current.variant}>{current.label}</StatusBadge>; } },
    ];

    return (
        <AuthenticatedLayout title="User Management">
            <PageHeader
                title="User Management"
                description="Manage authorized eDATS users, their office, section, and access roles. Click a user row to view details and administrative actions."
                actions={<Link href="/admin/users/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Add user</Link>}
            />

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

            <div className="mt-5 flex items-center justify-between text-sm">
                {users.prev_page_url ? <Link href={users.prev_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">← Previous</Link> : <span />}
                {users.next_page_url && <Link href={users.next_page_url} className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Next →</Link>}
            </div>

            <CrudDetailsModal
                open={Boolean(selectedUser)}
                title="User Details"
                subtitle={selectedUser ? `${selectedUser.name} · ${selectedStatus.label}` : ''}
                onClose={closeDetails}
                canEdit={Boolean(selectedUser)}
                canDelete={Boolean(selectedUser?.can_delete)}
                onEdit={() => { router.visit(`/admin/users/${selectedUser.id}/edit`); closeDetails(); }}
                onDelete={() => { setUserToDelete(selectedUser); closeDetails(); }}
                editLabel="Edit Access"
                deleteLabel="Delete User"
            >
                {selectedUser && <>
                    <CrudSummaryGrid items={[{ label: 'Account Status', render: () => <StatusBadge variant={selectedStatus.variant}>{selectedStatus.label}</StatusBadge> }, { label: 'User Category', value: display(categoryLabels[selectedUser.effective_category || selectedUser.section] || selectedUser.effective_category || selectedUser.section) }, { label: 'Unit', value: display(selectedUser.unit_assignment) }, { label: 'Office', value: display(selectedUser.office_designated) }]} />
                    <CrudSection title="Account Information">
                        <dl className="grid min-w-0 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <Detail label="Name" value={selectedUser.name} />
                            <Detail label="Email Address" value={selectedUser.email} />
                            <Detail label="User Category" value={categoryLabels[selectedUser.effective_category || selectedUser.section] || selectedUser.effective_category || selectedUser.section} />
                            <Detail label="Office Designated" value={selectedUser.office_designated} />
                            <Detail label="Protected Area / PAMO Assignment" value={selectedUser.effective_category === 'PAMO' ? selectedUser.protected_area_name : null} />
                            <Detail label="Registration Date" value={selectedUser.created_at} />
                            <Detail label="Last Updated" value={selectedUser.updated_at} />
                        </dl>
                    </CrudSection>
                    <CrudSection title="Administrative Actions">
                        <div className="flex flex-wrap gap-2">
                            {selectedUser.is_active ? <button type="button" onClick={() => { setUserToDeactivate(selectedUser); closeDetails(); }} className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300">Deactivate Account</button> : <button type="button" onClick={() => { setUserToActivate(selectedUser); closeDetails(); }} className="rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-xs font-semibold text-green-700 dark:border-green-900 dark:bg-green-950/50 dark:text-green-300">Activate Account</button>}
                        </div>
                    </CrudSection>
                </>}
            </CrudDetailsModal>

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
                message={userToActivate ? `User: ${userToActivate.name}\nUser Category: ${categoryLabels[userToActivate.effective_category || userToActivate.section] || userToActivate.effective_category || userToActivate.section || 'Not assigned'}\nUnit: ${userToActivate.unit_assignment || 'Not assigned'}\nOffice: ${userToActivate.office_designated || 'Not assigned'}${userToActivate.protected_area_name ? `\nProtected Area: ${userToActivate.protected_area_name}` : ''}` : ''}
                confirmLabel="Activate Account"
                onCancel={() => !activating && setUserToActivate(null)}
                onConfirm={activateUser}
                processing={activating}
            />
            <ConfirmDialog
                open={Boolean(userToDeactivate)}
                title="Deactivate this account?"
                message="The user will no longer be able to sign in until the account is reactivated. The assigned role and organizational scope will be preserved."
                confirmLabel="Deactivate Account"
                onCancel={() => !deactivating && setUserToDeactivate(null)}
                onConfirm={deactivateUser}
                processing={deactivating}
            />
        </AuthenticatedLayout>
    );
}
