import { Link, useForm } from '@inertiajs/react';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({ name: '', email: '', password: '', password_confirmation: '' });
    return <AuthLayout title="Register"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Create an account</h2><form onSubmit={(event) => { event.preventDefault(); post('/register'); }} className="mt-6 space-y-5"><Field id="name" label="Name" value={data.name} onChange={(event) => setData('name', event.target.value)} error={errors.name} autoComplete="name" autoFocus /><Field id="email" label="Email address" type="email" value={data.email} onChange={(event) => setData('email', event.target.value)} error={errors.email} autoComplete="username" /><Field id="password" label="Password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} error={errors.password} autoComplete="new-password" /><Field id="password-confirmation" label="Confirm password" type="password" value={data.password_confirmation} onChange={(event) => setData('password_confirmation', event.target.value)} error={errors.password_confirmation} autoComplete="new-password" /><PrimaryButton className="w-full" type="submit" disabled={processing}>{processing ? 'Creating account...' : 'Register'}</PrimaryButton></form><p className="mt-7 text-center text-sm text-gray-600 dark:text-gray-400">Already registered? <Link href="/login" className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Sign in</Link></p></div></AuthLayout>;
}

function Field({ id, label, error, ...props }) { return <div><label htmlFor={id} className="block text-sm font-semibold text-gray-700 dark:text-gray-200">{label}</label><Input id={id} error={Boolean(error)} className="mt-1.5" required {...props} />{error && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}</div>; }
