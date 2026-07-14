import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Alert from '../../Components/Alert';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function Login({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '', remember: false });
    const [showPassword, setShowPassword] = useState(false);
    const submit = (event) => { event.preventDefault(); post('/login'); };

    return <AuthLayout title="Sign in"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Sign in to your account</h2>{status && <Alert variant="success" className="mt-4">{status}</Alert>}<form onSubmit={submit} className="mt-6 space-y-5"><Field id="email" label="Email address" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} autoComplete="username" autoFocus /><div><div className="flex items-center justify-between gap-3"><label htmlFor="password" className="text-sm font-semibold text-gray-700 dark:text-gray-200">Password</label><Link href="/forgot-password" className="text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Forgot password?</Link></div><div className="relative mt-1.5"><Input id="password" type={showPassword ? 'text' : 'password'} value={data.password} onChange={(event) => setData('password', event.target.value)} error={Boolean(errors.password)} autoComplete="current-password" className="pr-20" required /><button type="button" onClick={() => setShowPassword((visible) => !visible)} className="absolute inset-y-0 right-0 rounded-r-ui px-3 text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400" aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? 'Hide' : 'Show'}</button></div>{errors.password && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{errors.password}</p>}</div><label className="flex cursor-pointer items-center gap-2.5 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" checked={data.remember} onChange={(event) => setData('remember', event.target.checked)} className="h-4 w-4 rounded border-gray-300 text-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-800" />Remember me</label><PrimaryButton className="w-full" type="submit" disabled={processing}>{processing ? 'Signing in...' : 'Sign in'}</PrimaryButton></form><p className="mt-7 text-center text-sm text-gray-600 dark:text-gray-400">Need an account? <Link href="/register" className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Register</Link></p></div></AuthLayout>;
}

function Field({ id, label, error, ...props }) { return <div><label htmlFor={id} className="block text-sm font-semibold text-gray-700 dark:text-gray-200">{label}</label><Input id={id} error={Boolean(error)} className="mt-1.5" required {...props} />{error && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}</div>; }
