import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Input from '../../Components/Input';
import PrimaryButton from '../../Components/PrimaryButton';
import SecondaryButton from '../../Components/SecondaryButton';
import AuthLayout from '../../Layouts/AuthLayout';
import Modal from '../../Components/Modal'; // 🚀 Gi-import ang imong Modal component

export default function Register() {
    const [showSuccessModal, setShowSuccessModal] = useState(false);

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
        post('/register', {
            onSuccess: () => setShowSuccessModal(true),
        });
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
                    <div>
                        <label htmlFor="section" className="block text-sm font-semibold text-gray-700 dark:text-gray-200">
                            Section
                        </label>
                        <select
                            id="section"
                            name="section"
                            value={data.section}
                            onChange={(event) => setData('section', event.target.value)}
                            required
                            className="mt-1.5 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-600 focus:ring-green-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                        >
                            <option value="">Select Section</option>
                            <option value="CDS">Conservation Development Section (CDS)</option>
                            <option value="MES">Monitoring and Enforcement Section (MES)</option>
                        </select>
                        {errors.section && (
                            <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">
                                {errors.section}
                            </p>
                        )}
                    </div>

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

            {/* 🚀 Uniporme nga Success Modal gamit ang imong Modal Component */}
            <Modal
                open={showSuccessModal}
                onClose={() => {}} // Dili nato i-allow nga masirhan pinaagi sa click sa gawas aron mapugos sila sa pag-click sa button
                title="Registration Successful"
                size="md"
                footer={
                    <Link
                        href="/login"
                        className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-700"
                    >
                        Go to Sign in
                    </Link>
                }
            >
                <div className="flex items-center gap-4">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300 text-xl font-bold">
                        ✓
                    </div>
                    <div>
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            Your account has been created successfully and is currently pending administrator approval. Click the button below to proceed to the sign-in page.
                        </p>
                    </div>
                </div>
            </Modal>
        </AuthLayout>
    );
}

function Field({ id, label, error, ...props }) {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-semibold text-gray-700 dark:text-gray-200">{label}</label>
            <Input id={id} error={Boolean(error)} className="mt-1.5" required {...props} />
            {error && <p className="mt-1.5 text-sm text-red-700 dark:text-red-300" role="alert">{error}</p>}
        </div>
    );
}
