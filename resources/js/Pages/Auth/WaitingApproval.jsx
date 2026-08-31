import { Icon } from '@iconify/react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import Card from '../../Components/Card';

export default function WaitingApproval() {
    const { post, processing } = useForm();

    const handleLogout = (event) => {
        event.preventDefault();
        post('/logout');
    };

    return (
        <AuthLayout title="Account Pending Approval">
            <div className="flex min-h-[62vh] items-center justify-center py-6">
                <Card className="w-full max-w-md p-7 text-center shadow-lg sm:p-8">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                        <Icon icon="lucide:clock-3" width="26" height="26" aria-hidden="true" />
                    </div>
                    <h2 className="mt-4 text-xl font-bold text-gray-900 dark:text-white">Account Pending Approval</h2>
                    <p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Your account has been created successfully and is awaiting administrator approval. You will be able to access eDATS once your account has been approved and assigned the appropriate access level.
                    </p>
                    <button
                        onClick={handleLogout}
                        disabled={processing}
                        className="mt-6 inline-flex items-center justify-center gap-2 rounded-lg bg-red-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <Icon icon="lucide:log-out" width="17" height="17" aria-hidden="true" />
                        {processing ? 'Signing out...' : 'Log out'}
                    </button>
                </Card>
            </div>
        </AuthLayout>
    );
}