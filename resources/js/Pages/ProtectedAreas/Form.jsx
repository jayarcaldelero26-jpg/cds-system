import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import FormField from '../../Components/FormField';
import FormSection from '../../Components/FormSection';
import PageHeader from '../../Components/PageHeader';
import PrimaryButton from '../../Components/PrimaryButton';

const selectClass = 'mt-1.5 block w-full rounded-ui border-gray-300 bg-white shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white';

export default function Form({ title, protectedArea }) {
    const isEdit = Boolean(protectedArea);
    const { auth } = usePage().props;

    // Existing values are stored as comma-separated strings.
    // Convert them to arrays so multiple provinces and municipalities can be selected.
    const toArray = (value, fallback = []) => {
        if (Array.isArray(value)) return value.filter(Boolean);
        if (typeof value === 'string') {
            return value.split(',').map(item => item.trim()).filter(Boolean);
        }
        return fallback;
    };

    const initialProvinces = toArray(protectedArea?.province, ['Davao Oriental']);
    const initialMunicipalities = toArray(protectedArea?.municipality);

    const form = useForm({
        name: protectedArea?.name || '',
        short_name: protectedArea?.short_name || '',
        category: protectedArea?.category || '',
        municipality: initialMunicipalities,
        province: initialProvinces,
        region: protectedArea?.region || 'Region XI',
        area_hectares: protectedArea?.area_hectares || '',
        core_zone_hectares: protectedArea?.core_zone_hectares || '',
        buffer_zone_hectares: protectedArea?.buffer_zone_hectares || '',
        pamo: protectedArea?.pamo || '',
        pasu: protectedArea?.pasu || '',
        year_established: protectedArea?.year_established || '',
        legal_basis: protectedArea?.legal_basis || '',
        status: protectedArea?.status || 'Proposed',
        description: protectedArea?.description || '',
        remarks: protectedArea?.remarks || ''
    });

    const [submitting, setSubmitting] = useState(false);

    const PSGC_REGION_NAME = 'Region XI (Davao Region)';
    const PSGC_BASE_URL = 'https://psgc.cloud/api/v2';

    const [selectedProvince, setSelectedProvince] = useState('');
    const [selectedMunicipality, setSelectedMunicipality] = useState('');
    const [provinceOptions, setProvinceOptions] = useState([]);
    const [municipalityOptions, setMunicipalityOptions] = useState([]);
    const [geoLoading, setGeoLoading] = useState(true);
    const [geoError, setGeoError] = useState('');

    const unwrapCollection = (payload) => {
        if (Array.isArray(payload)) return payload;
        if (Array.isArray(payload?.data)) return payload.data;
        if (Array.isArray(payload?.data?.data)) return payload.data.data;
        return [];
    };

    const fetchJson = async (url) => {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`PSGC request failed (${response.status})`);
        }

        return response.json();
    };

    const addToList = (field, value, reset) => {
        if (!value) return;
        if (!form.data[field].includes(value)) {
            form.setData(field, [...form.data[field], value]);
        }
        reset('');
    };

    const removeFromList = (field, indexToRemove) => {
        form.setData(field, form.data[field].filter((_, index) => index !== indexToRemove));
    };

    const addProvince = () => {
        const province = provinceOptions.find((item) => item.code === selectedProvince);
        if (province) {
            addToList('province', province.name, setSelectedProvince);
        }
    };

    const addMunicipality = () => {
        const municipality = municipalityOptions.find((item) => item.code === selectedMunicipality);
        if (municipality) {
            addToList('municipality', municipality.name, setSelectedMunicipality);
        }
    };

    // Load the official Region XI province and city/municipality hierarchy.
    useEffect(() => {
        let cancelled = false;

        const loadGeography = async () => {
            setGeoLoading(true);
            setGeoError('');

            try {
                const [provincesPayload, municipalitiesPayload] = await Promise.all([
                    fetchJson(`${PSGC_BASE_URL}/regions/${encodeURIComponent(PSGC_REGION_NAME)}/provinces`),
                    fetchJson(`${PSGC_BASE_URL}/regions/${encodeURIComponent(PSGC_REGION_NAME)}/cities-municipalities`),
                ]);

                if (cancelled) return;

                const provinces = unwrapCollection(provincesPayload)
                    .map((item) => ({
                        code: item.code,
                        name: item.name,
                    }))
                    .filter((item) => item.code && item.name)
                    .sort((a, b) => a.name.localeCompare(b.name));

                const municipalities = unwrapCollection(municipalitiesPayload)
                    .map((item) => ({
                        code: item.code,
                        name: item.name,
                        type: item.type,
                        province: item.province,
                        region: item.region,
                    }))
                    .filter((item) => item.code && item.name)
                    .sort((a, b) => {
                        const provinceCompare = (a.province || '').localeCompare(b.province || '');
                        return provinceCompare || a.name.localeCompare(b.name);
                    });

                setProvinceOptions(provinces);
                setMunicipalityOptions(municipalities);
            } catch (error) {
                if (!cancelled) {
                    setGeoError('Unable to load the official Region XI geographic list. Please check your internet connection and try again.');
                }
            } finally {
                if (!cancelled) setGeoLoading(false);
            }
        };

        loadGeography();

        return () => {
            cancelled = true;
        };
    }, []);

    const deleteRecord = () => {
        if (!protectedArea || !auth?.canDeleteProtectedAreas) return;

        const confirmed = window.confirm(
            `Delete ${protectedArea.name || 'this protected area'}? This action will remove the record from the active registry.`
        );

        if (!confirmed) return;

        router.delete(`/protected-areas/${protectedArea.id}`);
    };

    const submit = (event) => {
        event.preventDefault();

        const payload = {
            ...form.data,
            province: form.data.province.join(', '),
            municipality: form.data.municipality.join(', '),
        };

        setSubmitting(true);
        form.clearErrors();

        const options = {
            preserveScroll: true,
            onError: (errors) => {
                form.setError(errors);
            },
            onFinish: () => {
                setSubmitting(false);
            },
        };

        if (isEdit) {
            router.patch(`/protected-areas/${protectedArea.id}`, payload, options);
        } else {
            router.post('/protected-areas', payload, options);
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

    const formFields = (
        <>
                    <FormSection title="Protected Area Details" description="Enter the official identity, category, and geographic coverage of the protected area.">
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">

                            {/* Protected Area Name */}
                            <FormField
                                id="name"
                                label="Protected Area Name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                error={form.errors.name}
                                required
                                className="sm:col-span-2"
                            />

                            <FormField
                                id="short_name"
                                label="Short Name / Acronym"
                                value={form.data.short_name}
                                onChange={(event) => form.setData('short_name', event.target.value)}
                                error={form.errors.short_name}
                                maxLength={100}
                                placeholder="e.g. MHRWS"
                            />

                            {/* Category Dropdown */}
                            {select('category', 'Category',
                                <><option value="">Select a category</option><option>Natural Park</option><option>Protected Landscape</option><option>Wildlife Sanctuary</option><option>Natural Monument</option><option>Other</option></>
                            )}

                            {/* Geographic coverage */}
                            <div className="sm:col-span-2 lg:col-span-3">
                                <div className="rounded-2xl border border-emerald-100 bg-emerald-50/60 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                                    <div className="mb-4">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <h3 className="text-sm font-bold text-gray-900 dark:text-white">Geographic Coverage</h3>
                                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                    Select all provinces and municipalities/cities in Region XI covered by this protected area.
                                                </p>
                                            </div>
                                            <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-green-800 shadow-sm dark:bg-gray-900 dark:text-green-300">
                                                Region XI
                                            </span>
                                        </div>
                                    </div>

                                    {geoError && (
                                        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                            {geoError}
                                        </div>
                                    )}

                                    <div className="grid gap-5 lg:grid-cols-2">
                                        {/* Provinces */}
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                                Provinces
                                            </label>

                                            <div className="mt-1.5 flex gap-2">
                                                <select
                                                    className={selectClass + " mt-0"}
                                                    value={selectedProvince}
                                                    onChange={(e) => setSelectedProvince(e.target.value)}
                                                    disabled={geoLoading}
                                                >
                                                    <option value="">
                                                        {geoLoading ? 'Loading Region XI provinces...' : 'Select province'}
                                                    </option>
                                                    {provinceOptions.map((province) => (
                                                        <option
                                                            key={province.code}
                                                            value={province.code}
                                                            disabled={form.data.province.includes(province.name)}
                                                        >
                                                            {province.name}
                                                        </option>
                                                    ))}
                                                </select>

                                                <button
                                                    type="button"
                                                    onClick={addProvince}
                                                    disabled={!selectedProvince || geoLoading}
                                                    className="shrink-0 rounded-ui bg-green-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Add
                                                </button>
                                            </div>

                                            {form.data.province.length > 0 && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    {form.data.province.map((province, index) => (
                                                        <span
                                                            key={`${province}-${index}`}
                                                            className="inline-flex items-center gap-1.5 rounded-full border border-green-200 bg-green-100 px-3 py-1 text-xs font-semibold text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-300"
                                                        >
                                                            {province}
                                                            <button
                                                                type="button"
                                                                onClick={() => removeFromList('province', index)}
                                                                className="font-bold text-green-600 hover:text-red-600"
                                                                aria-label={`Remove ${province}`}
                                                            >
                                                                ×
                                                            </button>
                                                        </span>
                                                    ))}
                                                </div>
                                            )}

                                            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                {provinceOptions.length > 0
                                                    ? `${provinceOptions.length} Region XI provinces available.`
                                                    : 'Province list is loading.'}
                                            </p>

                                            {form.errors.province && (
                                                <p className="mt-1.5 text-sm font-normal text-red-700 dark:text-red-300">
                                                    {form.errors.province}
                                                </p>
                                            )}
                                        </div>

                                        {/* Municipalities / Cities */}
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                                Municipalities / Cities
                                            </label>

                                            <div className="mt-1.5 flex gap-2">
                                                <select
                                                    className={selectClass + " mt-0"}
                                                    value={selectedMunicipality}
                                                    onChange={(e) => setSelectedMunicipality(e.target.value)}
                                                    disabled={geoLoading || form.data.province.length === 0}
                                                >
                                                    <option value="">
                                                        {form.data.province.length === 0
                                                            ? 'Add a province first'
                                                            : geoLoading
                                                                ? 'Loading municipalities/cities...'
                                                                : 'Select municipality / city'}
                                                    </option>

                                                    {municipalityOptions
                                                        .filter((municipality) =>
                                                            form.data.province.includes(municipality.province)
                                                        )
                                                        .map((municipality) => (
                                                            <option
                                                                key={municipality.code}
                                                                value={municipality.code}
                                                                disabled={form.data.municipality.includes(municipality.name)}
                                                            >
                                                                {municipality.name} — {municipality.province}
                                                            </option>
                                                        ))}
                                                </select>

                                                <button
                                                    type="button"
                                                    onClick={addMunicipality}
                                                    disabled={!selectedMunicipality || geoLoading}
                                                    className="shrink-0 rounded-ui bg-green-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    Add
                                                </button>
                                            </div>

                                            {form.data.municipality.length > 0 && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    {form.data.municipality.map((municipality, index) => {
                                                        const parent = municipalityOptions.find((item) => item.name === municipality);

                                                        return (
                                                            <span
                                                                key={`${municipality}-${index}`}
                                                                className="inline-flex items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-300"
                                                            >
                                                                {municipality}
                                                                {parent?.province && (
                                                                    <span className="font-normal text-blue-600 dark:text-blue-400">
                                                                        · {parent.province}
                                                                    </span>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeFromList('municipality', index)}
                                                                    className="font-bold text-blue-600 hover:text-red-600"
                                                                    aria-label={`Remove ${municipality}`}
                                                                >
                                                                    ×
                                                                </button>
                                                            </span>
                                                        );
                                                    })}
                                                </div>
                                            )}

                                            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                Municipalities/cities are limited to the provinces you selected.
                                            </p>

                                            {form.errors.municipality && (
                                                <p className="mt-1.5 text-sm font-normal text-red-700 dark:text-red-300">
                                                    {form.errors.municipality}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </FormSection>

                    <FormSection title="Management and Legal Information">
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <div className="sm:col-span-2 lg:col-span-3">
                                <div className="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                                    <div className="mb-4">
                                        <h3 className="text-sm font-bold text-gray-900 dark:text-white">Protected Area Zonation</h3>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Record the total protected area together with its Core Zone and Buffer Zone areas in hectares.
                                        </p>
                                    </div>

                                    <div className="grid gap-5 sm:grid-cols-3">
                                        <FormField
                                            id="area_hectares"
                                            label="Total Area (Hectares)"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={form.data.area_hectares}
                                            onChange={(event) => form.setData('area_hectares', event.target.value)}
                                            error={form.errors.area_hectares}
                                        />

                                        <FormField
                                            id="core_zone_hectares"
                                            label="Core Zone (Hectares)"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={form.data.core_zone_hectares}
                                            onChange={(event) => form.setData('core_zone_hectares', event.target.value)}
                                            error={form.errors.core_zone_hectares}
                                        />

                                        <FormField
                                            id="buffer_zone_hectares"
                                            label="Buffer Zone (Hectares)"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={form.data.buffer_zone_hectares}
                                            onChange={(event) => form.setData('buffer_zone_hectares', event.target.value)}
                                            error={form.errors.buffer_zone_hectares}
                                        />
                                    </div>
                                </div>
                            </div>
                            <FormField id="pamo" label="PAMO" value={form.data.pamo} onChange={(event) => form.setData('pamo', event.target.value)} error={form.errors.pamo} />
                            <FormField id="pasu" label="PASu" value={form.data.pasu} onChange={(event) => form.setData('pasu', event.target.value)} error={form.errors.pasu} />
                            <FormField id="year_established" label="Year Established" type="number" min="1800" max={new Date().getFullYear() + 10} value={form.data.year_established} onChange={(event) => form.setData('year_established', event.target.value)} error={form.errors.year_established} />
                            <FormField id="legal_basis" label="Legal Basis" value={form.data.legal_basis} onChange={(event) => form.setData('legal_basis', event.target.value)} error={form.errors.legal_basis} className="sm:col-span-2" />

                            {/* Status Dropdown */}
                            {select('status', 'Status',
                                <><option>Proposed</option><option>Active</option><option>Inactive</option></>
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
        </>
    );

    const editFooter = (
        <div className="flex items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-700 dark:bg-gray-800/50 sm:px-6">
            {auth?.canDeleteProtectedAreas ? (
                <button
                    type="button"
                    onClick={deleteRecord}
                    className="inline-flex items-center rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-100 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300 dark:hover:bg-red-950/50"
                >
                    🗑️ Delete Record
                </button>
            ) : (
                <Link
                    href="/protected-areas"
                    className="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                >
                    ← Back
                </Link>
            )}

            <div className="flex items-center gap-2">
                <Link
                    href="/protected-areas"
                    className="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                >
                    Cancel
                </Link>
                <PrimaryButton type="submit" disabled={submitting}>
                    {form.processing ? 'Saving...' : 'Save Changes'}
                </PrimaryButton>
            </div>
        </div>
    );

    if (isEdit) {
        return (
            <AuthenticatedLayout title={title}>
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-3 backdrop-blur-sm sm:p-4">
                    <div className="relative flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-200 bg-white px-5 py-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6">
                            <div>
                                <h1 className="text-base font-bold text-gray-900 dark:text-white sm:text-lg">
                                    Edit Protected Area Record
                                </h1>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    {protectedArea?.name || 'Protected Area'}
                                </p>
                            </div>
                            <Link
                                href="/protected-areas"
                                className="rounded-lg p-1 text-2xl leading-none text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                aria-label="Close edit form"
                            >
                                ×
                            </Link>
                        </div>

                        {form.hasErrors && (
                            <div className="mx-5 mt-4 shrink-0 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300 sm:mx-6">
                                Please review the highlighted fields before saving.
                            </div>
                        )}

                        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                            <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-5 sm:px-6">
                                {formFields}
                            </div>
                            {editFooter}
                        </form>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout title={title}>
            <PageHeader
                title={title}
                description="Add a protected area to the PENRO Mati master database."
                actions={
                    <Link href="/protected-areas" className="text-sm font-semibold text-white hover:text-green-200 transition">
                        ← Back to protected areas
                    </Link>
                }
            />
            <Card className="mt-6 max-w-6xl">
                <form onSubmit={submit} className="space-y-8">
                    {formFields}
                    <div className="flex flex-wrap gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton type="submit" disabled={submitting}>
                            {form.processing ? 'Saving...' : 'Create protected area'}
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
