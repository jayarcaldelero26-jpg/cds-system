import { Head, useForm, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

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
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Patrol Area / Protected Area</label>
                                <input
                                    type="text"
                                    value={data.patrol_area}
                                    onChange={(e) => setData('patrol_area', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                    required
                                />
                                {errors.patrol_area && <span className="text-red-500 text-xs">{errors.patrol_area}</span>}
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Patrol Date</label>
                                    <input
                                        type="date"
                                        value={data.patrol_date}
                                        onChange={(e) => setData('patrol_date', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                        required
                                    />
                                    {errors.patrol_date && <span className="text-red-500 text-xs">{errors.patrol_date}</span>}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Ecoregion</label>
                                    <input
                                        type="text"
                                        value={data.ecoregion}
                                        onChange={(e) => setData('ecoregion', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Team Leader</label>
                                    <input
                                        type="text"
                                        value={data.team_leader}
                                        onChange={(e) => setData('team_leader', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Team Members Count</label>
                                    <input
                                        type="number"
                                        value={data.team_members_count}
                                        onChange={(e) => setData('team_members_count', e.target.value)}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Threats Observed</label>
                                <textarea
                                    value={data.threats_observed}
                                    onChange={(e) => setData('threats_observed', e.target.value)}
                                    rows="3"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">Remarks</label>
                                <textarea
                                    value={data.remarks}
                                    onChange={(e) => setData('remarks', e.target.value)}
                                    rows="3"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 dark:bg-gray-900 dark:border-gray-700 dark:text-white"
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
