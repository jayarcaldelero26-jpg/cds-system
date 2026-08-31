import { Icon } from '@iconify/react';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AuthField, AuthSelect } from '../../Components/AuthField';
import PrimaryButton from '../../Components/PrimaryButton';
import AuthLayout from '../../Layouts/AuthLayout';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        office_designated: '',
        section: '',
        password: '',
        password_confirmation: '',
    });
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        post('/register');
    };

    return (
        <AuthLayout title="Create an account" contentClassName="max-w-[34rem]">
            <div className="mt-5">
                <div className="border-b border-slate-200/80 pb-4 dark:border-emerald-100/15">
                    <h2 className="text-[1.45rem] font-semibold leading-[1.3] tracking-tight text-slate-900 dark:text-white">Create an eDATS account</h2>
                    <p className="mt-1.5 text-sm leading-5 text-slate-500 dark:text-slate-400">Submit your details for administrator review.</p>
                </div>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <AuthField
                        id="name"
                        name="name"
                        label="Full name"
                        icon="user-round"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                        autoComplete="name"
                        autoFocus
                        required
                    />

                    <AuthField
                        id="email"
                        name="email"
                        label="Email address"
                        icon="mail"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        error={errors.email}
                        autoComplete="username"
                        required
                    />

                    <AuthField
                        id="office_designated"
                        name="office_designated"
                        label="Office designated"
                        icon="building-2"
                        hint="Example: CENRO Mati or PENRO Davao Oriental"
                        value={data.office_designated}
                        onChange={(event) => setData('office_designated', event.target.value)}
                        error={errors.office_designated}
                        autoComplete="organization"
                        required
                    />

                    <AuthSelect
                        id="section"
                        label="User category"
                        name="section"
                        icon="users-round"
                        value={data.section}
                        onChange={(event) => setData('section', event.target.value)}
                        required
                        error={errors.section}
                        hint="Access is assigned separately after administrator approval."
                    >
                        <option value="">Select user category</option>
                        <option value="CDS">CDS Staff</option>
                        <option value="ENGP">ENGP Coordinator</option>
                        <option value="PAMO">PAMO / Protected Area Staff</option>
                    </AuthSelect>

                    <AuthField
                        id="password"
                        name="password"
                        label="Password"
                        icon="lock-keyhole"
                        type={showPassword ? 'text' : 'password'}
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        error={errors.password}
                        autoComplete="new-password"
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

                    <AuthField
                        id="password-confirmation"
                        name="password_confirmation"
                        label="Confirm password"
                        icon="lock-keyhole"
                        type={showPasswordConfirmation ? 'text' : 'password'}
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        error={errors.password_confirmation}
                        autoComplete="new-password"
                        required
                    >
                        <button
                            type="button"
                            onClick={() => setShowPasswordConfirmation((visible) => !visible)}
                            className="inline-flex rounded-lg p-1.5 text-emerald-800 transition hover:bg-emerald-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-700 dark:text-emerald-300 dark:hover:bg-emerald-950"
                            aria-label={showPasswordConfirmation ? 'Hide password confirmation' : 'Show password confirmation'}
                        >
                            <Icon icon={showPasswordConfirmation ? 'lucide:eye-off' : 'lucide:eye'} width="18" height="18" aria-hidden="true" />
                        </button>
                    </AuthField>

                    <PrimaryButton className="h-11 w-full justify-center gap-2" type="submit" disabled={processing}>
                        <span>{processing ? 'Creating account...' : 'Create account'}</span>
                        {!processing && <Icon icon="lucide:user-plus" width="17" height="17" aria-hidden="true" />}
                    </PrimaryButton>
                </form>

                <div className="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-sm">
                    <span className="text-slate-500 dark:text-slate-400">
                        Already have an account? <Link href="/login" className="font-semibold text-emerald-800 transition hover:text-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300">Sign in</Link>
                    </span>
                    <Link href={route('welcome')} className="inline-flex items-center gap-1.5 rounded font-semibold text-slate-600 transition hover:text-emerald-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 dark:text-slate-300 dark:hover:text-emerald-200">
                        <Icon icon="lucide:house" width="16" height="16" aria-hidden="true" />
                        Home
                    </Link>
                </div>

                <p className="mt-4 text-center text-xs leading-5 text-slate-500 dark:text-slate-400">
                    New accounts remain pending until an administrator reviews and approves them.
                </p>
            </div>
        </AuthLayout>
    );
}