import { useForm } from '@inertiajs/react';
import { Passkeys } from '@laravel/passkeys';
import { useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import ConfirmDialog from '../../Components/ConfirmDialog';
import CurrentPasswordConfirmDialog from '../../Components/CurrentPasswordConfirmDialog';
import DangerButton from '../../Components/DangerButton';
import FormField from '../../Components/FormField';
import FormSection from '../../Components/FormSection';
import PageHeader from '../../Components/PageHeader';
import PrimaryButton from '../../Components/PrimaryButton';

export default function Edit({ user, passkeys = [], canManagePasskeys = false, passwordConfirmationFresh = false }) {
    const profile = useForm({ name: user.name, email: user.email });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const remove = useForm({ password: '' });
    const [confirmingDeletion, setConfirmingDeletion] = useState(false);
    const [passkeyName, setPasskeyName] = useState('');
    const [passkeyError, setPasskeyError] = useState('');
    const [passkeyProcessing, setPasskeyProcessing] = useState(false);
    const [passwordIsFresh, setPasswordIsFresh] = useState(passwordConfirmationFresh);
    const [passwordDialogOpen, setPasswordDialogOpen] = useState(false);
    const [pendingPasskeyAction, setPendingPasskeyAction] = useState(null);
    const [passkeyRevokeConfirmation, setPasskeyRevokeConfirmation] = useState(null);

    const passkeySupportMessage = () => {
        if (typeof window !== 'undefined' && window.isSecureContext === false) return 'Passkeys require a secure HTTPS connection. Open CDS-SMART using HTTPS to register or use a passkey.';
        if (typeof window === 'undefined' || typeof window.PublicKeyCredential === 'undefined' || typeof navigator === 'undefined' || typeof navigator.credentials?.create !== 'function') return 'Passkeys are not supported in this browser or device.';
        return null;
    };

    const performPasskeyAction = async (action) => {
        setPasskeyProcessing(true);
        setPasskeyError('');

        try {
            if (action.type === 'register') {
                await Passkeys.register({ name: action.name });
            } else {
                const response = await fetch(`/user/passkeys/${action.id}`, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => ({}));

                if (response.status === 423) throw new Error(payload.message || 'Password confirmation required.');
                if (!response.ok) throw new Error(payload.message || 'Passkey revocation failed.');
            }

            window.location.reload();
        } catch (error) {
            if (error?.message === 'Password confirmation required.') {
                setPasswordIsFresh(false);
                setPendingPasskeyAction(action);
                setPasswordDialogOpen(true);
                return;
            }

            setPasskeyError(error.message || (action.type === 'register' ? 'Passkey registration failed.' : 'Passkey revocation failed.'));
        } finally {
            setPasskeyProcessing(false);
        }
    };

    const startPasskeyAction = (action) => {
        setPasskeyError('');

        if (!passwordIsFresh) {
            setPendingPasskeyAction(action);
            setPasswordDialogOpen(true);
            return;
        }

        if (action.type === 'remove') {
            setPasskeyRevokeConfirmation(action);
            return;
        }

        void performPasskeyAction(action);
    };

    const addPasskey = () => {
        const supportError = passkeySupportMessage();
        if (supportError) {
            setPasskeyError(supportError);
            return;
        }

        startPasskeyAction({ type: 'register', name: passkeyName.trim() });
    };

    const confirmPasswordAndResume = () => {
        setPasswordIsFresh(true);
        setPasswordDialogOpen(false);
        const action = pendingPasskeyAction;
        setPendingPasskeyAction(null);
        if (action?.type === 'remove') {
            setPasskeyRevokeConfirmation(action);
        } else if (action) {
            void performPasskeyAction(action);
        }
    };

    const cancelPasswordConfirmation = () => {
        setPasswordDialogOpen(false);
        setPendingPasskeyAction(null);
    };

    const revokePasskey = (id, name) => startPasskeyAction({ type: 'remove', id, name });

    const confirmPasskeyRevoke = () => {
        const action = passkeyRevokeConfirmation;
        setPasskeyRevokeConfirmation(null);
        if (action) void performPasskeyAction(action);
    };

    return <AuthenticatedLayout title="Profile">
        <PageHeader title="Profile settings" description="Manage your account information and security settings." />
        <div className="mt-6 max-w-3xl space-y-6">
            <Card>
                <FormSection title="Profile information" description="Update your name and email address.">
                    <form onSubmit={(event) => { event.preventDefault(); profile.patch('/profile'); }} className="mt-5 grid gap-5 sm:grid-cols-2">
                        <FormField id="profile-name" label="Name" value={profile.data.name} onChange={(event) => profile.setData('name', event.target.value)} error={profile.errors.name} required />
                        <FormField id="profile-email" label="Email address" type="email" value={profile.data.email} onChange={(event) => profile.setData('email', event.target.value)} error={profile.errors.email} required />
                        <div className="sm:col-span-2"><PrimaryButton className="mt-4" type="submit" disabled={profile.processing}>{profile.processing ? 'Saving...' : 'Save changes'}</PrimaryButton></div>
                    </form>
                </FormSection>
            </Card>

            {canManagePasskeys && <Card>
                <FormSection title="Passkeys" description="Register a device passkey for sensitive administrative step-up actions. Private keys and biometric data stay with your authenticator.">
                    <div className="space-y-3">
                        {passkeys.length > 0 ? passkeys.map((item) => <div key={item.id} className="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-700">
                            <span>{item.name}</span>
                            <span className="text-xs text-gray-500">Registered {item.created_at ? new Date(item.created_at).toLocaleDateString() : '—'}</span>
                            <button type="button" className="ml-3 rounded text-xs font-bold text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 dark:text-red-300" onClick={() => revokePasskey(item.id, item.name)} disabled={passkeyProcessing}>Revoke</button>
                        </div>) : <p className="text-sm text-gray-600 dark:text-gray-300">No passkeys registered. Super Admin emergency override remains unavailable until one is added.</p>}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="min-w-0 flex-1"><FormField id="passkey-name" label="Passkey name" value={passkeyName} onChange={(event) => setPasskeyName(event.target.value)} required /></div>
                            <PrimaryButton className="h-11 w-full py-0 sm:w-auto sm:shrink-0" type="button" disabled={passkeyProcessing || !passkeyName.trim()} onClick={addPasskey}>{passkeyProcessing ? 'Registering...' : 'Add passkey'}</PrimaryButton>
                        </div>
                        {passkeyError && <p className="text-sm text-red-700 dark:text-red-300" role="alert">{passkeyError}</p>}
                        <p className="text-xs text-gray-500 dark:text-gray-400">For your security, you may be asked to confirm your password before adding or removing a passkey.</p>
                    </div>
                </FormSection>
            </Card>}

            <Card>
                <FormSection title="Update password" description="Use a strong password that you do not use elsewhere.">
                    <form onSubmit={(event) => { event.preventDefault(); password.put('/password'); }} className="mt-5 grid gap-5 sm:grid-cols-2">
                        <FormField id="current-password" label="Current password" type="password" value={password.data.current_password} onChange={(event) => password.setData('current_password', event.target.value)} error={password.errors.current_password} required />
                        <div className="hidden sm:block" />
                        <FormField id="new-password" label="New password" type="password" value={password.data.password} onChange={(event) => password.setData('password', event.target.value)} error={password.errors.password} required />
                        <FormField id="confirm-new-password" label="Confirm new password" type="password" value={password.data.password_confirmation} onChange={(event) => password.setData('password_confirmation', event.target.value)} error={password.errors.password_confirmation} required />
                        <div className="sm:col-span-2"><PrimaryButton className="mt-4" type="submit" disabled={password.processing}>{password.processing ? 'Saving...' : 'Save password'}</PrimaryButton></div>
                    </form>
                </FormSection>
            </Card>

            <Card variant="subtle">
                <FormSection title="Delete account" description="This permanently removes your account and cannot be undone.">
                    <div className="mt-5"><DangerButton onClick={() => setConfirmingDeletion(true)}>Delete account</DangerButton></div>
                </FormSection>
            </Card>
        </div>
        <ConfirmDialog open={confirmingDeletion} title="Delete your account?" message="This action permanently deletes your account. Enter your current password to confirm." confirmLabel="Delete account" onCancel={() => setConfirmingDeletion(false)} onConfirm={() => remove.delete('/profile', { onSuccess: () => setConfirmingDeletion(false) })} processing={remove.processing}>
            <FormField id="delete-password" label="Current password" type="password" value={remove.data.password} onChange={(event) => remove.setData('password', event.target.value)} error={remove.errors.password} required />
        </ConfirmDialog>
        <ConfirmDialog
            open={Boolean(passkeyRevokeConfirmation)}
            title="Revoke passkey"
            message={`Revoke “${passkeyRevokeConfirmation?.name ?? 'this passkey'}”? You will no longer be able to use this passkey for CDS-SMART security verification.`}
            confirmLabel="Revoke passkey"
            cancelLabel="Cancel"
            variant="danger"
            onCancel={() => setPasskeyRevokeConfirmation(null)}
            onConfirm={confirmPasskeyRevoke}
            processing={passkeyProcessing}
        />
        <CurrentPasswordConfirmDialog open={passwordDialogOpen} onCancel={cancelPasswordConfirmation} onConfirmed={confirmPasswordAndResume} message={pendingPasskeyAction?.type === 'remove' ? 'Enter your current password to confirm and continue removing this passkey.' : 'Enter your current password to confirm and continue adding this passkey.'} />
    </AuthenticatedLayout>;
}
