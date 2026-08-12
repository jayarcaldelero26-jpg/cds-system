import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import Card from '../../Components/Card';
import DataTable from '../../Components/DataTable';
import StatusBadge from '../../Components/StatusBadge';

export default function Aws({ awsRecords = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingRecord, setEditingRecord] = useState(null);

    const { data, setData, post, put, delete: destroy, processing, reset, errors } = useForm({
        station_name: '',
        location: '',
        latitude: '',
        longitude: '',
        status: 'Active',
        temperature: '',
        humidity: '',
        rainfall: ''
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingRecord) {
            put(route('aws.update', editingRecord.id), {
                onSuccess: () => { closeModal(); }
            });
        } else {
            post(route('aws.store'), {
                onSuccess: () => { closeModal(); }
            });
        }
    };

    const openModal = (record = null) => {
        if (record) {
            setEditingRecord(record);
            setData({
                station_name: record.station_name,
                location: record.location,
                latitude: record.latitude || '',
                longitude: record.longitude || '',
                status: record.status,
                temperature: record.temperature || '',
                humidity: record.humidity || '',
                rainfall: record.rainfall || ''
            });
        } else {
            setEditingRecord(null);
            reset();
        }
        setIsModalOpen(true);
    };

    const closeModal = () => {
        setIsModalOpen(false);
        setEditingRecord(null);
        reset();
    };

    const columns = [
        {
            key: 'station_name',
            label: 'Station Name',
            render: (row) => <span className="font-bold text-gray-900 dark:text-white">{row.station_name}</span>
        },
        { key: 'location', label: 'Location' },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge variant={row.status === 'Active' ? 'active' : 'pending'}>{row.status}</StatusBadge>
        },
        { key: 'temperature', label: 'Temp (°C)', render: (row) => `${row.temperature ?? 'N/A'} °C` },
        { key: 'humidity', label: 'Humidity (%)', render: (row) => `${row.humidity ?? 'N/A'}%` },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex items-center gap-2">
                    <button onClick={() => openModal(row)} className="text-xs font-bold text-blue-600 hover:underline">Edit</button>
                    <button onClick={() => { if(confirm('Are you sure?')) destroy(route('aws.destroy', row.id)); }} className="text-xs font-bold text-rose-600 hover:underline">Delete</button>
                </div>
            )
        }
    ];

    return (
        <AuthenticatedLayout title="Automated Weather Stations (AWS)">
            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-extrabold text-gray-900 dark:text-white">Automated Weather Stations (AWS)</h1>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Manage weather monitoring stations and real-time environmental metrics.</p>
                    </div>
                    <button
                        onClick={() => openModal()}
                        className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-sky-700 transition"
                    >
                        + Add Weather Station
                    </button>
                </div>

                <Card className="border border-gray-100 dark:border-gray-800 shadow-sm rounded-2xl" padding="p-0">
                    {awsRecords.length > 0 ? (
                        <DataTable columns={columns} rows={awsRecords} />
                    ) : (
                        <div className="p-8 text-center">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-white">No AWS records found</h3>
                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">Get started by adding your first weather station.</p>
                        </div>
                    )}
                </Card>

                {/* MODAL FORM */}
                {isModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="w-full max-w-lg rounded-2xl bg-white dark:bg-gray-900 p-6 shadow-2xl border border-gray-200 dark:border-gray-800 space-y-4">
                            <h2 className="text-base font-extrabold text-gray-900 dark:text-white">
                                {editingRecord ? 'Edit Weather Station' : 'Add New Weather Station'}
                            </h2>
                            <form onSubmit={handleSubmit} className="space-y-3 text-xs font-semibold">
                                <div>
                                    <label className="text-gray-600 dark:text-gray-400">Station Name</label>
                                    <input
                                        type="text"
                                        value={data.station_name}
                                        onChange={(e) => setData('station_name', e.target.value)}
                                        className="w-full mt-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white p-2.5"
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="text-gray-600 dark:text-gray-400">Location</label>
                                    <input
                                        type="text"
                                        value={data.location}
                                        onChange={(e) => setData('location', e.target.value)}
                                        className="w-full mt-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white p-2.5"
                                        required
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="text-gray-600 dark:text-gray-400">Temperature (°C)</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={data.temperature}
                                            onChange={(e) => setData('temperature', e.target.value)}
                                            className="w-full mt-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white p-2.5"
                                        />
                                    </div>
                                    <div>
                                        <label className="text-gray-600 dark:text-gray-400">Humidity (%)</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={data.humidity}
                                            onChange={(e) => setData('humidity', e.target.value)}
                                            className="w-full mt-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white p-2.5"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label className="text-gray-600 dark:text-gray-400">Status</label>
                                    <select
                                        value={data.status}
                                        onChange={(e) => setData('status', e.target.value)}
                                        className="w-full mt-1 rounded-xl border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white p-2.5"
                                    >
                                        <option value="Active">Active</option>
                                        <option value="Maintenance">Maintenance</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>
                                <div className="flex justify-end gap-2 pt-3">
                                    <button type="button" onClick={closeModal} className="px-4 py-2 rounded-xl bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300">Cancel</button>
                                    <button type="submit" disabled={processing} className="px-4 py-2 rounded-xl bg-sky-600 text-white font-bold hover:bg-sky-700">Save Record</button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
