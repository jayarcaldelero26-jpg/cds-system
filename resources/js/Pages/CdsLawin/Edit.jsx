import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { FloatingInput, FloatingTextarea } from '@/Components/Form';

export default function Edit({ auth, lawin }) {
    const { data, setData, patch, processing, errors } = useForm({
        patrol_area: lawin.patrol_area || '',
        patrol_date: lawin.patrol_date || '',
        team_leader: lawin.team_leader || '',
        team_members_count: lawin.team_members_count || '',
        ecoregion: lawin.ecoregion || '',
        threats_observed: lawin.threats_observed || '',
        remarks: lawin.remarks || '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('cds-lawin.update', lawin.id));
    };

    return (
        <AuthenticatedLayout
            auth={auth}
            header={<h2 className="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Edit CDS LAWIN Record</h2>}
        >
            <Head title="Edit CDS LAWIN" />

            <div className="py-12">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <FloatingInput id="cds-lawin-area" label="Patrol Area / Protected Area"
                                    type="text"
                                    value={data.patrol_area}
                                    error={errors.patrol_area}
                                    onChange={(e) => setData('patrol_area', e.target.value)}
                                    required
                                />
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <FloatingInput id="cds-lawin-date" label="Patrol Date"
                                        type="date"
                                    value={data.patrol_date}
                                    error={errors.patrol_date}
                                        onChange={(e) => setData('patrol_date', e.target.value)}
                                        required
                                    />
                                </div>
                                <div>
                                    <FloatingInput id="cds-lawin-ecoregion" label="Ecoregion"
                                        type="text"
                                    value={data.ecoregion}
                                    error={errors.ecoregion}
                                        onChange={(e) => setData('ecoregion', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <FloatingInput id="cds-lawin-leader" label="Team Leader"
                                        type="text"
                                    value={data.team_leader}
                                    error={errors.team_leader}
                                        onChange={(e) => setData('team_leader', e.target.value)}
                                    />
                                </div>
                                <div>
                                    <FloatingInput id="cds-lawin-members" label="Team Members Count"
                                        type="number"
                                    value={data.team_members_count}
                                    error={errors.team_members_count}
                                        onChange={(e) => setData('team_members_count', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div>
                                <FloatingTextarea id="cds-lawin-threats" label="Threats Observed"
                                    value={data.threats_observed}
                                    error={errors.threats_observed}
                                    onChange={(e) => setData('threats_observed', e.target.value)}
                                    rows="3"
                                />
                            </div>

                            <div>
                                <FloatingTextarea id="cds-lawin-remarks" label="Remarks"
                                    value={data.remarks}
                                    error={errors.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                    rows="3"
                                />
                            </div>

                            <div className="flex justify-end gap-4">
                                <Link
                                    href={route('cds-lawin.index')}
                                    className="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md text-sm font-medium"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="bg-green-700 hover:bg-green-800 text-white px-4 py-2 rounded-md text-sm font-medium shadow"
                                >
                                    Update Record
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
