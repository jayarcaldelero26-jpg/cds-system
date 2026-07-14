import { router } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';

export default function VerifyEmail({ status }) {
    return <GuestLayout title="Verify Email"><p className="text-sm dark:text-gray-300">Please verify your email address using the link we sent you.</p>{status === 'verification-link-sent' && <p className="mt-3 text-sm text-green-600">A new verification link has been sent.</p>}<div className="mt-4 flex items-center gap-4"><button type="button" onClick={() => router.post('/email/verification-notification')} className="rounded bg-gray-800 px-4 py-2 text-xs font-semibold uppercase text-white">Resend verification email</button><button type="button" onClick={() => router.post('/logout')} className="text-sm underline">Log out</button></div></GuestLayout>;
}
