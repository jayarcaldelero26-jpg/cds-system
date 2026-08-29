import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '../../Layouts/AuthLayout';

function FieldIcon({ name }) {
    if (name === 'mail') {
        return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m4 7 8 6 8-6" /></svg>;
    }

    if (name === 'lock') {
        return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>;
    }

    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="2.5" />{name === 'eye-off' && <path d="m4 4 16 16" />}</svg>;
}

function SignInIcon() {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3" /></svg>;
}

function AuthInput({ id, label, icon, error, children, ...inputProps }) {
    const errorId = `${id}-error`;

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-semibold text-slate-700 dark:text-slate-200">{label} <span className="text-emerald-700 dark:text-emerald-400">*</span></label>
            <div className="relative mt-1.5">
                <span className="pointer-events-none absolute inset-y-0 left-0 z-10 flex items-center pl-4 text-emerald-700 dark:text-emerald-400"><FieldIcon name={icon} /></span>
                <input id={id} name={id} aria-invalid={Boolean(error)} aria-describedby={error ? errorId : undefined} className={`block h-[52px] w-full rounded-xl border bg-white py-2.5 text-sm text-slate-900 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-emerald-600 focus:ring-4 focus:ring-emerald-600/15 dark:bg-[#10231f] dark:text-white dark:placeholder:text-slate-500 dark:focus:border-emerald-400 dark:focus:ring-emerald-400/15 ${children ? 'pl-12 pr-12' : 'pl-12 pr-4'} ${error ? 'border-red-400 dark:border-red-500' : 'border-slate-300/90 hover:border-emerald-600/50 dark:border-emerald-100/15 dark:hover:border-emerald-400/55'}`} {...inputProps} />
                {children && <span className="absolute inset-y-0 right-0 z-10 flex items-center pr-2">{children}</span>}
            </div>
            {error && <p id={errorId} className="mt-1.5 text-sm font-medium text-red-700 dark:text-red-300" role="alert">{error}</p>}
        </div>
    );
}

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });
    const [showPassword, setShowPassword] = useState(false);
    const submit = (event) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <AuthLayout title="Sign in">
            <div className="mt-5">
                <h2 className="text-lg font-semibold leading-6 tracking-tight text-slate-900 dark:text-white sm:text-xl">Sign in to your account</h2>
                <p className="mt-0.5 text-sm leading-5 text-slate-500 dark:text-slate-400">Use your authorized eDATS account to continue.</p>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <AuthInput id="email" label="Email address" icon="mail" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} autoComplete="email" autoFocus required />

                    <div>
                        <AuthInput id="password" label="Password" icon="lock" type={showPassword ? 'text' : 'password'} value={data.password} onChange={(event) => setData('password', event.target.value)} error={errors.password} autoComplete="current-password" required>
                            <button type="button" onClick={() => setShowPassword((visible) => !visible)} className="inline-flex rounded-lg p-1.5 text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950" aria-label={showPassword ? 'Hide password' : 'Show password'}><FieldIcon name={showPassword ? 'eye-off' : 'eye'} /></button>
                        </AuthInput>
                        <div className="mt-1.5 flex justify-end"><Link href="/forgot-password" className="rounded text-xs font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">Forgot password?</Link></div>
                    </div>

                    <label className="flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600 dark:text-slate-300"><input type="checkbox" checked={data.remember} onChange={(event) => setData('remember', event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-700 dark:border-slate-600 dark:bg-slate-800" />Remember me</label>

                    <button className="inline-flex h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-800 to-green-700 px-4 text-sm font-semibold text-white shadow-sm transition hover:from-emerald-900 hover:to-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-70 dark:from-emerald-700 dark:to-green-700 dark:hover:from-emerald-600 dark:hover:to-green-600" type="submit" disabled={processing}>{processing ? 'Signing in...' : <><span>Sign in</span><SignInIcon /></>}</button>
                </form>

                <p className="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">Need an account? <Link href="/register" className="font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">Register</Link></p>
            </div>
        </AuthLayout>
    );
}
