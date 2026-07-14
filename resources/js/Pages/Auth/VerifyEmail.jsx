import { router } from '@inertiajs/react';
import Alert from '../../Components/Alert';
import PrimaryButton from '../../Components/PrimaryButton';
import SecondaryButton from '../../Components/SecondaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function VerifyEmail({ status }) {
    return <AuthLayout title="Verify email"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Verify your email address</h2><p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Please verify your email address using the link we sent you. If you did not receive it, we can send another link.</p>{status === 'verification-link-sent' && <Alert variant="success" className="mt-5">A new verification link has been sent to your email address.</Alert>}<div className="mt-6 grid gap-3"><PrimaryButton onClick={() => router.post('/email/verification-notification')}>Resend verification email</PrimaryButton><SecondaryButton onClick={() => router.post('/logout')}>Log out</SecondaryButton></div></div></AuthLayout>;
}
