import { Link, useForm } from '@inertiajs/react';
import Input from '../../Components/Input';
import FloatingSelect from '../../Components/Form/FloatingSelect';
import PrimaryButton from '../../Components/PrimaryButton';
import SecondaryButton from '../../Components/SecondaryButton';
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

    const submit = (event) => {
        event.preventDefault();
        post('/register');
    };

    return (
        <AuthLayout title="Register">
            <div className="mt-7">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Create an account</h2>

                <form onSubmit={submit} className="mt-6 space-y-5">

                    <Field
                        id="name"
                        label="Name"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                        autoComplete="name"
                        autoFocus
                    />

                    <Field
                        id="email"
                        label="Email address"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        error={errors.email}
                        autoComplete="username"
                    />

                    {/* Office Designated Text Field */}
                    <Field
                        id="office_designated"
                        label="Office Designated (e.g. CENRO Mati, PENRO Davao Oriental)"
                        value={data.office_designated}
                        onChange={(event) => setData('office_designated', event.target.value)}
                        error={errors.office_designated}
                        autoComplete="organization"
                    />

                    {/* Section Dropdown Field (CDS vs MES) */}
                    <FloatingSelect id="section" label="Section" name="section" value={data.section} onChange={(event) => setData('section', event.target.value)} required error={errors.section}>
                            <option value="">Select Section</option>
                            <option value="CDS">Conservation Development Section (CDS)</option>
                            <option value="MES">Monitoring and Enforcement Section (MES)</option>
                    </FloatingSelect>

                    <Field
                        id="password"
                        label="Password"
                        type="password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        error={errors.password}
                        autoComplete="new-password"
                    />

                    <Field
                        id="password-confirmation"
                        label="Confirm password"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        error={errors.password_confirmation}
                        autoComplete="new-password"
                    />

                    <PrimaryButton className="w-full" type="submit" disabled={processing}>
                        {processing ? 'Creating account...' : 'Register'}
                    </PrimaryButton>
                </form>

                <p className="mt-7 text-center text-sm text-gray-600 dark:text-gray-400">
                    Already registered? <Link href="/login" className="font-semibold text-green-800 hover:text-green-950 dark:text-green-400">Sign in</Link>
                </p>
            </div>

        </AuthLayout>
    );
}

function Field({ id, label, error, ...props }) {
    return (
        <div>
            <Input id={id} label={label} error={Boolean(error)} required {...props} />
        </div>
    );
}
