import { Link, useForm } from '@inertiajs/react';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ForgotPassword() {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    return <AuthLayout title="Forgot password"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Forgot your password?</h2><p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Enter your email address and we will send you a password reset link.</p><form onSubmit={(event) => { event.preventDefault(); post('/forgot-password'); }} className="mt-6 space-y-5"><div><Input id="email" label="Email address" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={Boolean(errors.email)} required autoFocus autoComplete="username" />{errors.email && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{errors.email}</p>}</div><PrimaryButton className="w-full" type="submit" disabled={processing}>{processing ? 'Sending...' : 'Email reset link'}</PrimaryButton></form><Link className="mt-7 block text-center text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400" href="/login">Back to sign in</Link></div></AuthLayout>;
}
