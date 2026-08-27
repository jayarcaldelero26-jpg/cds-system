import { Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import PageHeader from '../../Components/PageHeader';
import { FloatingInput, FloatingTextarea } from '../../Components/Form';

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
                                <FloatingInput id="cds-lawin-area" label="Patrol Area / Protected Area"
                                    required
                                    type="text"
                                    placeholder="E.g., Mt. Hamiguitan Range Wildlife Sanctuary"
                                    value={data.patrol_area}
                                    error={errors.patrol_area}
                                    onChange={(e) => setData('patrol_area', e.target.value)}
                                />
                            </div>

                            {/* Patrol Date */}
                            <div>
                                <FloatingInput id="cds-lawin-date" label="Patrol Date"
                                    required
                                    type="date"
                                    value={data.patrol_date}
                                    error={errors.patrol_date}
                                    onChange={(e) => setData('patrol_date', e.target.value)}
                                />
                            </div>

                            {/* Ecoregion */}
                            <div>
                                <FloatingInput id="cds-lawin-ecoregion" label="Ecoregion"
                                    type="text"
                                    placeholder="E.g., Forest / Protected Zone"
                                    value={data.ecoregion}
                                    error={errors.ecoregion}
                                    onChange={(e) => setData('ecoregion', e.target.value)}
                                />
                            </div>

                            {/* Team Leader */}
                            <div>
                                <FloatingInput id="cds-lawin-leader" label="Team Leader"
                                    type="text"
                                    placeholder="Enter Team Leader Name"
                                    value={data.team_leader}
                                    error={errors.team_leader}
                                    onChange={(e) => setData('team_leader', e.target.value)}
                                />
                            </div>

                            {/* Team Members Count */}
                            <div>
                                <FloatingInput id="cds-lawin-members" label="No. of Patrol Members (Pax)"
                                    required
                                    type="number"
                                    min="1"
                                    value={data.team_members_count}
                                    error={errors.team_members_count}
                                    onChange={(e) => setData('team_members_count', e.target.value)}
                                />
                            </div>

                            {/* Threats Observed */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="cds-lawin-threats" label="Threats Observed / Detected"
                                    rows="3"
                                    placeholder="E.g., 2 instances of illegal hunting, encroachment..."
                                    value={data.threats_observed}
                                    error={errors.threats_observed}
                                    onChange={(e) => setData('threats_observed', e.target.value)}
                                />
                            </div>

                            {/* Remarks */}
                            <div className="md:col-span-2">
                                <FloatingTextarea id="cds-lawin-remarks" label="Remarks / Notes"
                                    rows="3"
                                    placeholder="Additional observations, weather conditions, or local sightings..."
                                    value={data.remarks}
                                    error={errors.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                />
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
