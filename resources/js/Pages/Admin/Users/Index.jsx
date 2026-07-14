import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout';
import Card from '../../../Components/Card';
import ConfirmDialog from '../../../Components/ConfirmDialog';
import DataTable from '../../../Components/DataTable';
import PageHeader from '../../../Components/PageHeader';
import StatusBadge from '../../../Components/StatusBadge';

const statusMessages = { 'user-created': 'User created successfully.', 'user-updated': 'User updated successfully.', 'user-deleted': 'User deleted successfully.' };

export default function Index({ users, status }) {
    const [userToDelete, setUserToDelete] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const updateStatus = (user) => router.patch(`/admin/users/${user.id}`, { name: user.name, email: user.email, role: user.role, is_active: !user.is_active });
    const deleteUser = () => { setDeleting(true); router.delete(`/admin/users/${userToDelete.id}`, { onFinish: () => { setDeleting(false); setUserToDelete(null); } }); };
    const columns = [{ key: 'name', label: 'Name', render: (user) => <span className="font-medium text-gray-900">{user.name}</span> }, { key: 'email', label: 'Email' }, { key: 'role', label: 'Role', render: (user) => user.role || <span className="text-gray-400">No role</span> }, { key: 'is_active', label: 'Status', render: (user) => <StatusBadge variant={user.is_active ? 'active' : 'inactive'}>{user.is_active ? 'Active' : 'Inactive'}</StatusBadge> }, { key: 'created_at', label: 'Created' }, { key: 'actions', label: <span className="sr-only">Actions</span>, cellClassName: 'text-right', render: (user) => <div className="flex justify-end gap-3"><Link className="font-medium text-green-800 hover:text-green-950" href={`/admin/users/${user.id}/edit`}>Edit</Link>{user.role && <button type="button" className="font-medium text-amber-700 hover:text-amber-900" onClick={() => updateStatus(user)}>{user.is_active ? 'Deactivate' : 'Activate'}</button>}<button type="button" className="font-medium text-red-700 hover:text-red-900" onClick={() => setUserToDelete(user)}>Delete</button></div> }];

    return <AuthenticatedLayout title="User Management"><PageHeader title="User Management" description="Manage authorized CDS system users and their access roles." actions={<Link href="/admin/users/create" className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700 focus:ring-offset-2">Add user</Link>} />{statusMessages[status] && <p className="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="status">{statusMessages[status]}</p>}<Card className="mt-6" padding="p-0"><DataTable columns={columns} rows={users.data} emptyTitle="No users found" emptyDescription="User accounts will appear here when they are added." /></Card><div className="mt-5 flex items-center justify-between text-sm">{users.prev_page_url ? <Link href={users.prev_page_url} className="font-semibold text-green-800 hover:text-green-950">← Previous</Link> : <span />}{users.next_page_url && <Link href={users.next_page_url} className="font-semibold text-green-800 hover:text-green-950">Next →</Link>}</div><ConfirmDialog open={Boolean(userToDelete)} title="Delete user account?" message={`Delete ${userToDelete?.name}'s account? This action cannot be undone.`} confirmLabel="Delete user" onCancel={() => setUserToDelete(null)} onConfirm={deleteUser} processing={deleting} /></AuthenticatedLayout>;
}
