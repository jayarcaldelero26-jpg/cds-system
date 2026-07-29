import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';

export default function Create({ cenroList = [], statuses = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        patrol_area: '',
        patrol_date: '',
        ecoregion: '',
        team_leader: '',
        team_members_count: 1,
        threats_observed: '',
        remarks: '',
        status: 'Under Review',
        attachment: null,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('cds-lawin.store'));
    };

    const labelClass = "block text-sm font-medium text-gray-700 dark:text-gray-300";
    const inputClass = "mt-1 block w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-green-700 focus:ring-green-700 dark:border-gray-600 dark:bg-gray-900 dark:text-white dark:[color-scheme:dark]";
    const errorClass = "text-xs text-red-600 dark:text-red-400 mt-1";

    return (
        <AuthenticatedLayout title="Record CDS LAWIN Patrol">
            <PageHeader
                title="Record CDS LAWIN Patrol Activity"
                description="Input data from Protected Area LAWIN patrol forms submitted by CDS field teams."
                actions={
                    <Link href={route('cds-lawin.index')} className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Back
                    </Link>
                }
            />

            <div className="mt-6 max-w-3xl">
                <Card>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-6 md:grid-cols-2">
                            {/* Patrol Area / Protected Area */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Patrol Area / Protected Area</label>
                                <input
                                    required
                                    type="text"
                                    placeholder="E.g., Mt. Hamiguitan Range Wildlife Sanctuary"
                                    className={inputClass}
                                    value={data.patrol_area}
                                    onChange={(e) => setData('patrol_area', e.target.value)}
                                />
                                {errors.patrol_area && <p className={errorClass}>{errors.patrol_area}</p>}
                            </div>

                            {/* Patrol Date */}
                            <div>
                                <label className={labelClass}>Patrol Date</label>
                                <input
                                    required
                                    type="date"
                                    className={inputClass}
                                    value={data.patrol_date}
                                    onChange={(e) => setData('patrol_date', e.target.value)}
                                />
                                {errors.patrol_date && <p className={errorClass}>{errors.patrol_date}</p>}
                            </div>

                            {/* Ecoregion */}
                            <div>
                                <label className={labelClass}>Ecoregion</label>
                                <input
                                    type="text"
                                    placeholder="E.g., Forest / Protected Zone"
                                    className={inputClass}
                                    value={data.ecoregion}
                                    onChange={(e) => setData('ecoregion', e.target.value)}
                                />
                                {errors.ecoregion && <p className={errorClass}>{errors.ecoregion}</p>}
                            </div>

                            {/* Team Leader */}
                            <div>
                                <label className={labelClass}>Team Leader</label>
                                <input
                                    type="text"
                                    placeholder="Enter Team Leader Name"
                                    className={inputClass}
                                    value={data.team_leader}
                                    onChange={(e) => setData('team_leader', e.target.value)}
                                />
                                {errors.team_leader && <p className={errorClass}>{errors.team_leader}</p>}
                            </div>

                            {/* Team Members Count */}
                            <div>
                                <label className={labelClass}>No. of Patrol Members (Pax)</label>
                                <input
                                    required
                                    type="number"
                                    min="1"
                                    className={inputClass}
                                    value={data.team_members_count}
                                    onChange={(e) => setData('team_members_count', e.target.value)}
                                />
                                {errors.team_members_count && <p className={errorClass}>{errors.team_members_count}</p>}
                            </div>

                            {/* Threats Observed */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Threats Observed / Detected</label>
                                <textarea
                                    rows="3"
                                    placeholder="E.g., 2 instances of illegal hunting, encroachment..."
                                    className={inputClass}
                                    value={data.threats_observed}
                                    onChange={(e) => setData('threats_observed', e.target.value)}
                                />
                                {errors.threats_observed && <p className={errorClass}>{errors.threats_observed}</p>}
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <label className={labelClass}>Remarks / Notes</label>
                                <textarea
                                    rows="3"
                                    placeholder="Additional observations, weather conditions, or local sightings..."
                                    className={inputClass}
                                    value={data.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                />
                                {errors.remarks && <p className={errorClass}>{errors.remarks}</p>}
                            </div>
                        </div>

                        <div className="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link href={route('cds-lawin.index')} className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                Cancel
                            </Link>
                            <button type="submit" disabled={processing} className="inline-flex items-center justify-center rounded-lg bg-green-800 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-900 transition disabled:opacity-50">
                                {processing ? 'Saving...' : 'Save Patrol'}
                            </button>
                        </div>
                    </form>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
