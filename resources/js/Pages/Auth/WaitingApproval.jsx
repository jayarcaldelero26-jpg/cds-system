import { useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';

export default function WaitingApproval() {
    const { post } = useForm();

    const handleLogout = (e) => {
        e.preventDefault();
        post('/logout');
    };

    return (
        <AuthenticatedLayout title="Account Pending">
            <div className="flex min-h-[70vh] items-center justify-center">
                <Card className="max-w-md text-center p-8 shadow-lg">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Account Pending Approval</h2>
                    <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">
                        Your account has been registered successfully, but it is currently waiting for approval and role assignment by the CDS Admin.
                    </p>
                    <button
                        onClick={handleLogout}
                        className="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 transition"
                    >
                        Log out
                    </button>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
