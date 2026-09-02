import { Icon } from '@iconify/react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthField from '../../Components/AuthField';
import AuthLayout from '../../Layouts/AuthLayout';
import SuccessDialog from '../../Components/SuccessDialog';

export default function Login() {
    const { flash = {} } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });
    const [showPassword, setShowPassword] = useState(false);
    const [pendingApproval, setPendingApproval] = useState(Boolean(flash.pending_approval));

    useEffect(() => {
        if (flash.pending_approval) setPendingApproval(true);
    }, [flash.pending_approval]);

    const submit = (event) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <AuthLayout title="Sign in" contentClassName="max-w-[30rem]">
            <SuccessDialog
                open={pendingApproval}
                title="Account Pending Approval"
                message="Your registration request is still awaiting administrator approval. Please contact the system administrator if you need assistance with your account activation."
                onClose={() => setPendingApproval(false)}
            />
            <div className="mt-5">
                <div className="border-b border-slate-200/80 pb-4 dark:border-emerald-100/15">
                    <h2 className="text-[1.45rem] font-semibold leading-[1.3] tracking-tight text-slate-900 dark:text-white">Sign in to continue</h2>
                    <p className="mt-1.5 text-sm leading-5 text-slate-500 dark:text-slate-400">Use your authorized eDATS account to continue.</p>
                </div>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <AuthField
                        id="email"
                        name="email"
                        label="Email address"
                        icon="mail"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        error={errors.email}
                        autoComplete="email"
                        autoFocus
                        required
                    />

                    <div>
                        <AuthField
                            id="password"
                            name="password"
                            label="Password"
                            icon="lock"
                            type={showPassword ? 'text' : 'password'}
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            error={errors.password}
                            autoComplete="current-password"
                            required
                        >
                            <button
                                type="button"
                                onClick={() => setShowPassword((visible) => !visible)}
                                className="inline-flex rounded-lg p-1.5 text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950"
                                aria-label={showPassword ? 'Hide password' : 'Show password'}
                            >
                                <Icon icon={showPassword ? 'lucide:eye-off' : 'lucide:eye'} width="18" height="18" aria-hidden="true" />
                            </button>
                        </AuthField>
                        <div className="mt-1.5 flex justify-end">
                            <Link href="/forgot-password" className="rounded text-xs font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">
                                Forgot password?
                            </Link>
                        </div>
                    </div>

                    <label className="flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600 dark:text-slate-300">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-700 dark:border-slate-600 dark:bg-slate-800"
                        />
                        Remember me
                    </label>

                    <button
                        className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-emerald-800 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-emerald-700 dark:hover:bg-emerald-600"
                        type="submit"
                        disabled={processing}
                    >
                        <span>{processing ? 'Signing in...' : 'Sign in'}</span>
                        {!processing && <Icon icon="lucide:log-in" width="17" height="17" aria-hidden="true" />}
                    </button>
                </form>

                <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                    <span className="text-slate-500 dark:text-slate-400">
                        Need an account? <Link href="/register" className="font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">Register</Link>
                    </span>
                    <Link href={route('welcome')} className="inline-flex items-center gap-1.5 rounded font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:text-emerald-200">
                        <Icon icon="lucide:house" width="16" height="16" aria-hidden="true" />
                        Home
                    </Link>
                </div>
            </div>
        </AuthLayout>
    );
}
