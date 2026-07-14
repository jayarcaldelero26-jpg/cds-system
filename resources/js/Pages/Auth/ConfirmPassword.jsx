import { useForm } from '@inertiajs/react';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({ password: '' });
    return <AuthLayout title="Confirm password"><div className="mt-7"><h2 className="text-lg font-semibold text-gray-900 dark:text-white">Confirm your password</h2><p className="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">For your security, please confirm your password before continuing.</p><form onSubmit={(event) => { event.preventDefault(); post('/confirm-password'); }} className="mt-6 space-y-5"><div><label htmlFor="password" className="block text-sm font-semibold text-gray-700 dark:text-gray-200">Password</label><Input id="password" type="password" value={data.password} onChange={(event) => setData('password', event.target.value)} error={Boolean(errors.password)} className="mt-1.5" required autoFocus autoComplete="current-password" />{errors.password && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{errors.password}</p>}</div><PrimaryButton className="w-full" type="submit" disabled={processing}>{processing ? 'Confirming...' : 'Confirm password'}</PrimaryButton></form></div></AuthLayout>;
}
