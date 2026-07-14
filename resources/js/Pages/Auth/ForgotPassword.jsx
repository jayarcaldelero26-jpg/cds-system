import { Link, useForm } from '@inertiajs/react';
import Alert from '../../Components/Alert';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    return <AuthLayout title="Forgot password"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Forgot your password?</h2><p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">Enter your email address and we will send you a password reset link.</p>{status && <Alert variant="success" className="mt-5">{status}</Alert>}<form onSubmit={(event) => { event.preventDefault(); post('/forgot-password'); }} className="mt-6 space-y-5"><div><label htmlFor="email" className="block text-sm font-semibold text-gray-700 dark:text-gray-200">Email address</label><Input id="email" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={Boolean(errors.email)} className="mt-1.5" required autoFocus autoComplete="username" />{errors.email && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{errors.email}</p>}</div><PrimaryButton className="w-full" type="submit" disabled={processing}>{processing ? 'Sending...' : 'Email reset link'}</PrimaryButton></form><Link className="mt-7 block text-center text-sm font-semibold text-green-800 hover:text-green-950 dark:text-green-400" href="/login">Back to sign in</Link></div></AuthLayout>;
}
