import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import FormField from '../../Components/FormField';
import FormSection from '../../Components/FormSection';
import PageHeader from '../../Components/PageHeader';
import PrimaryButton from '../../Components/PrimaryButton';

const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

export default function Form({ title, protectedArea }) {
    const isEdit = Boolean(protectedArea);

    // Convert existing barangays string to array if editing
    const initialBarangays = protectedArea?.barangays
        ? (typeof protectedArea.barangays === 'string' ? protectedArea.barangays.split(',').map(b => b.trim()).filter(Boolean) : protectedArea.barangays)
        : [];

    const form = useForm({
        name: protectedArea?.name || '',
        category: protectedArea?.category || '',
        municipality: protectedArea?.municipality || '',
        province: protectedArea?.province || 'Davao Oriental',
        region: protectedArea?.region || 'Region XI',
        barangays: initialBarangays,
        classification: protectedArea?.classification || '',
        area_hectares: protectedArea?.area_hectares || '',
        pamo: protectedArea?.pamo || '',
        pasu: protectedArea?.pasu || '',
        year_established: protectedArea?.year_established || '',
        legal_basis: protectedArea?.legal_basis || '',
        status: protectedArea?.status || 'Proposed',
        description: protectedArea?.description || '',
        remarks: protectedArea?.remarks || ''
    });

    const [selectedBarangay, setSelectedBarangay] = useState('');

    const addBarangay = () => {
        if (selectedBarangay.trim() !== '') {
            if (!form.data.barangays.includes(selectedBarangay)) {
                form.setData('barangays', [...form.data.barangays, selectedBarangay]);
            }
            setSelectedBarangay('');
        }
    };

    const removeBarangay = (indexToRemove) => {
        form.setData('barangays', form.data.barangays.filter((_, index) => index !== indexToRemove));
    };

    const submit = (event) => {
        event.preventDefault();
        const payload = {
            ...form.data,
            barangays: form.data.barangays.join(', ')
        };

        if (isEdit) {
            form.transform(() => payload).patch(`/protected-areas/${protectedArea.id}`);
        } else {
            form.transform(() => payload).post('/protected-areas');
        }
    };

    const select = (id, label, options) => (
        <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor={id}>
            {label}
            <select id={id} className={selectClass} value={form.data[id]} onChange={(event) => form.setData(id, event.target.value)}>
                {options}
            </select>
            {form.errors[id] && <p className="mt-1.5 text-sm font-normal text-red-700 dark:text-red-300">{form.errors[id]}</p>}
        </label>
    );

    return (
        <AuthenticatedLayout title={title}>
            <PageHeader
                title={title}
                description={isEdit ? 'Update the official protected area record.' : 'Add a protected area to the PENRO Mati master database.'}
                actions={
                    <Link href="/protected-areas" className="text-sm font-semibold text-white hover:text-green-200 transition">
                        ← Back to protected areas
                    </Link>
                }
            />
            <Card className="mt-6 max-w-5xl">
                <form onSubmit={submit} className="space-y-8">
                    <FormSection title="Protected Area Details" description="Enter the core location and classification information.">
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">

                            {/* Protected Area Name */}
                            <FormField
                                id="name"
                                label="Protected Area Name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                error={form.errors.name}
                                required
                                className="sm:col-span-2 lg:col-span-3"
                            />

                            {/* Category Dropdown */}
                            {select('category', 'Category',
                                <><option value="">Select a category</option><option>Natural Park</option><option>Protected Landscape</option><option>Wildlife Sanctuary</option><option>Natural Monument</option><option>Other</option></>
                            )}

                            {/* Municipality Dropdown */}
                            {select('municipality', 'Municipality',
                                <><option value="">Select municipality</option>
                                <option>Mati City</option>
                                <option>Banaybanay</option>
                                <option>Boston</option>
                                <option>Caraga</option>
                                <option>Cateel</option>
                                <option>Governor Generoso</option>
                                <option>Lupon</option>
                                <option>Manay</option>
                                <option>San Isidro</option>
                                <option>Tarragona</option></>
                            )}

                            {/* Classification Dropdown */}
                            {select('classification', 'Classification',
                                <><option value="">Select classification</option>
                                <option>Legislative</option>
                                <option>Initial Component</option>
                                <option>Proclaimed</option></>
                            )}

                            {/* Province Dropdown */}
                            {select('province', 'Province',
                                <><option value="Davao Oriental">Davao Oriental</option></>
                            )}

                            {/* Region Dropdown */}
                            {select('region', 'Region',
                                <><option value="Region XI">Region XI (Davao Region)</option></>
                            )}

                            {/* Barangay Dropdown with Add Button */}
                            <div className="sm:col-span-2 lg:col-span-3">
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                    Barangays (Select and add multiple barangays)
                                </label>
                                <div className="mt-1.5 flex gap-2">
                                    <select
                                        className={selectClass + " mt-0"}
                                        value={selectedBarangay}
                                        onChange={(e) => setSelectedBarangay(e.target.value)}
                                    >
                                        <option value="">Select barangay</option>
                                        <option value="Badas">Badas</option>
                                        <option value="Bobon">Bobon</option>
                                        <option value="Cabuaya">Cabuaya</option>
                                        <option value="Central">Central (Pob.)</option>
                                        <option value="Culasi">Culasi</option>
                                        <option value="Dahican">Dahican</option>
                                        <option value="Don Enrique Lopez">Don Enrique Lopez</option>
                                        <option value="Lawigan">Lawigan</option>
                                        <option value="Libudon">Libudon</option>
                                        <option value="Macambol">Macambol</option>
                                        <option value="Matiao">Matiao</option>
                                        <option value="Mayo">Mayo</option>
                                        <option value="Sainz">Sainz</option>
                                        <option value="Sanghay">Sanghay</option>
                                        <option value="Tagabakid">Tagabakid</option>
                                        <option value="Tamisan">Tamisan</option>
                                    </select>
                                    <button
                                        type="button"
                                        onClick={addBarangay}
                                        className="inline-flex items-center rounded-ui bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800 transition"
                                    >
                                        Add Barangay
                                    </button>
                                </div>

                                {/* List of Added Barangays */}
                                {form.data.barangays.length > 0 && (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {form.data.barangays.map((b, index) => (
                                            <span key={index} className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-medium text-green-800 dark:bg-green-950 dark:text-green-300">
                                                {b}
                                                <button
                                                    type="button"
                                                    onClick={() => removeBarangay(index)}
                                                    className="text-green-600 hover:text-red-600 font-bold ml-1"
                                                >
                                                    ×
                                                </button>
                                            </span>
                                        ))}
                                    </div>
                                )}
                                {form.errors.barangays && <p className="mt-1.5 text-sm font-normal text-red-700">{form.errors.barangays}</p>}
                            </div>

                        </div>
                    </FormSection>

                    <FormSection title="Management and Legal Information">
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField id="area_hectares" label="Area (Hectares)" type="number" min="0" step="0.01" value={form.data.area_hectares} onChange={(event) => form.setData('area_hectares', event.target.value)} error={form.errors.area_hectares} />
                            <FormField id="pamo" label="PAMO" value={form.data.pamo} onChange={(event) => form.setData('pamo', event.target.value)} error={form.errors.pamo} />
                            <FormField id="pasu" label="PASu" value={form.data.pasu} onChange={(event) => form.setData('pasu', event.target.value)} error={form.errors.pasu} />
                            <FormField id="year_established" label="Year Established" type="number" min="1800" max={new Date().getFullYear() + 10} value={form.data.year_established} onChange={(event) => form.setData('year_established', event.target.value)} error={form.errors.year_established} />
                            <FormField id="legal_basis" label="Legal Basis" value={form.data.legal_basis} onChange={(event) => form.setData('legal_basis', event.target.value)} error={form.errors.legal_basis} className="sm:col-span-2" />

                            {/* Status Dropdown */}
                            {select('status', 'Status',
                                <><option>Proposed</option><option>Ongoing</option></>
                            )}
                        </div>
                    </FormSection>

                    <FormSection title="Additional Notes">
                        <div className="grid gap-5">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="description">
                                Description
                                <textarea id="description" rows="4" className={selectClass} value={form.data.description} onChange={(event) => form.setData('description', event.target.value)} />
                                {form.errors.description && <p className="mt-1.5 text-sm text-red-700">{form.errors.description}</p>}
                            </label>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200" htmlFor="remarks">
                                Remarks
                                <textarea id="remarks" rows="3" className={selectClass} value={form.data.remarks} onChange={(event) => form.setData('remarks', event.target.value)} />
                                {form.errors.remarks && <p className="mt-1.5 text-sm text-red-700">{form.errors.remarks}</p>}
                            </label>
                        </div>
                    </FormSection>

                    <div className="flex flex-wrap gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : isEdit ? 'Save changes' : 'Create protected area'}
                        </PrimaryButton>
                        <Link href="/protected-areas" className="inline-flex items-center rounded-ui px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800">
                            Cancel
                        </Link>
                    </div>
                </form>
            </Card>
        </AuthenticatedLayout>
    );
}
