import { FileInput } from "@/Components/Crud/FileInput";import { FloatingSelect, FloatingInput, FloatingTextarea } from "@/Components/Form";import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import MapView from './Components/MapView';
import { Head, Link, useForm } from '@inertiajs/react';
import { normalizeSpatialFile } from '@/Utils/spatialUpload';

export default function BamsIndex({
  auth,
  protectedAreas = [],
  bamsRecords = [],
  spatialLayers = []
}) {
  const [activeTab, setActiveTab] = useState('list');
  const canCreate = Boolean(auth?.canCreateBams);
  const canDelete = Boolean(auth?.canDeleteBams);
  const canManageSpatial = Boolean(auth?.canManageBamsSpatial);

  // States for delete confirmations and selection.
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
  const [selectedIds, setSelectedIds] = useState([]);
  const [recordToDelete, setRecordToDelete] = useState(null);

  // Form for PMP Single Entry (Annex 6.8 Standards)
  const form = useForm({
    protected_area_id: '',
    plot_no: '',
    quadrat_no: '',
    transect_no: '',
    date: '',
    time: '',
    observer: '',
    vegetation_type: '',
    weather: '',
    elevation: '',
    gps_unit: '',
    lat: '',
    long: '',
    species_code: '',
    dbh: '',
    th: '',
    mh: '',
    bearing: '',
    distance: '',
    remarks: ''
  });

  // Form for Excel / CSV Import
  const excelForm = useForm({
    protected_area_id: '',
    file: null
  });

  // Form for Shapefile / GeoJSON Import
  const spatialForm = useForm({
    protected_area_id: '',
    spatial_file: null,
    layer_name: ''
  });

  const submitRecord = (e) => {
    e.preventDefault();

    form.post(route('bams.flora.store'), {
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        setActiveTab('list');
      }
    });
  };

  const submitExcelImport = (e) => {
    e.preventDefault();

    excelForm.reset();
  };

  // GI-FIX: Actual Inertia post request to the backend controller
  const submitSpatialImport = async (e) => {
    e.preventDefault();
    try {
      const normalized = await normalizeSpatialFile(spatialForm.data.spatial_file);
      spatialForm.transform(() => ({ protected_area_id: spatialForm.data.protected_area_id, layer_name: spatialForm.data.layer_name, spatial_geojson: JSON.stringify(normalized.geojson), source_format: normalized.source_format, original_filename: normalized.original_filename }));
      spatialForm.post(route('bams.store-spatial'), { preserveScroll: true, onSuccess: () => { spatialForm.reset(); setActiveTab('map'); } });
    } catch (error) { spatialForm.setError('spatial_file', error.message); }
  };

  const confirmDelete = () => {
    setShowDeleteConfirm(false);
    setRecordToDelete(null);

  };

  const confirmBulkDelete = () => {
    setShowBulkDeleteConfirm(false);
    setSelectedIds([]);

  };

  return (
    <AuthenticatedLayout user={auth?.user}>
            <Head title="Permanent Monitoring Plot - BAMS" />

            <div className="py-6 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900 min-h-screen">
                <div className="max-w-7xl mx-auto space-y-6">

                    {/* CUSTOM BULK DELETE CONFIRMATION MODAL */}
                    {canDelete && showBulkDeleteConfirm &&
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">

                                <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">
                                    ⚠️
                                </div>

                                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">
                                    Delete Selected Records?
                                </h3>

                                <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">
                                    Are you sure you want to delete {selectedIds.length} selected record(s)? This cannot be undone.
                                </p>

                                <div className="flex gap-3">
                                    <button
                  type="button"
                  onClick={() => setShowBulkDeleteConfirm(false)}
                  className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition">

                                        Cancel
                                    </button>

                                    <button
                  type="button"
                  onClick={confirmBulkDelete}
                  className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition">

                                        Yes, Delete All
                                    </button>
                                </div>
                            </div>
                        </div>
          }

                    {/* CUSTOM SINGLE DELETE CONFIRMATION MODAL */}
                    {canDelete && showDeleteConfirm &&
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
                            <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">

                                <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">
                                    ⚠️
                                </div>

                                <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">
                                    Are you sure?
                                </h3>

                                <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">
                                    Do you really want to delete this record? This process cannot be undone.
                                </p>

                                <div className="flex gap-3">
                                    <button
                  type="button"
                  onClick={() => setShowDeleteConfirm(false)}
                  className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition">

                                        Cancel
                                    </button>

                                    <button
                  type="button"
                  onClick={confirmDelete}
                  className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition">

                                        Yes, Delete
                                    </button>
                                </div>
                            </div>
                        </div>
          }

                    {/* SHARED PAGE HEADER */}
                    <PageHeader
            title="Field Data Sheet for Permanent Monitoring Plot"
            description="Terrestrial Ecosystems Floristic Survey, Spatial Mapping, and Tree Measurements."
            actions={
            <span className="bg-white/10 backdrop-blur-md text-white border border-white/20 px-4 py-2 rounded-xl text-xs font-bold tracking-wider uppercase">
                                BAMS Operations
                            </span>
            } />


                    {/* NAVIGATION TABS */}
                    <div className="flex flex-wrap gap-2 border-b border-gray-200 dark:border-gray-700 pb-3 no-print">

                        <button
              onClick={() => setActiveTab('list')}
              className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${
              activeTab === 'list' ?
              'bg-green-700 text-white shadow-md' :
              'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`
              }>

                            📄 Database Records
                        </button>

                        {canCreate && <button
              onClick={() => setActiveTab('add')}
              className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${
              activeTab === 'add' ?
              'bg-green-700 text-white shadow-md' :
              'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`
              }>

                            ➕ Encode Field Sheet
                        </button>}

                        <button
              onClick={() => setActiveTab('map')}
              className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${
              activeTab === 'map' ?
              'bg-green-700 text-white shadow-md' :
              'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`
              }>

                            🗺️ Map View
                        </button>

                        {canCreate && <button
              onClick={() => setActiveTab('excel-import')}
              className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${
              activeTab === 'excel-import' ?
              'bg-green-700 text-white shadow-md' :
              'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`
              }>

                            📊 Excel / CSV Import
                        </button>}

                        {canManageSpatial && <button
              onClick={() => setActiveTab('spatial-import')}
              className={`px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${
              activeTab === 'spatial-import' ?
              'bg-green-700 text-white shadow-md' :
              'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`
              }>

                            🌐 Spatial File Import
                        </button>}
                    </div>

                    {/* TAB 1: RECORDS VIEW */}
                    {activeTab === 'list' &&
          <div className="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden p-6 border border-gray-100 dark:border-gray-700 space-y-4">

                            <div className="flex justify-between items-center">
                                <h3 className="text-base font-bold text-gray-900 dark:text-white">
                                    Permanent Monitoring Plot Records
                                </h3>

                                {canDelete && bamsRecords.length > 0 &&
              <button
                onClick={() => setShowBulkDeleteConfirm(true)}
                className="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 rounded-xl text-xs font-bold transition">

                                        🗑️ Delete Selected
                                    </button>
              }
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse border border-gray-300 dark:border-gray-700 text-xs">

                                    <thead className="bg-green-800 text-white uppercase font-bold text-center">
                                        <tr>
                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-16">
                                                NO.
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3">
                                                PROTECTED AREA
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3">
                                                SPECIES CODE
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">
                                                DBH (CM)
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">
                                                TH (M)
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3 w-24">
                                                MH (M)
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3">
                                                BEARING
                                            </th>

                                            <th className="border border-gray-300 dark:border-gray-700 p-3">
                                                DISTANCE (M)
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-800 text-gray-800 dark:text-gray-200">

                                        {bamsRecords.length > 0 ?
                  bamsRecords.map((record, index) =>
                  <tr key={record.id || index}>

                                                    <td className="border border-gray-300 p-3 text-center">
                                                        {index + 1}
                                                    </td>

                                                    <td className="border border-gray-300 p-3">
                                                        {record.protected_area?.name || 'N/A'}
                                                    </td>

                                                    <td className="border border-gray-300 p-3 italic">
                                                        {record.species_code}
                                                    </td>

                                                    <td className="border border-gray-300 p-3 text-center">
                                                        {record.dbh}
                                                    </td>

                                                    <td className="border border-gray-300 p-3 text-center">
                                                        {record.th}
                                                    </td>

                                                    <td className="border border-gray-300 p-3 text-center">
                                                        {record.mh}
                                                    </td>

                                                    <td className="border border-gray-300 p-3">
                                                        {record.bearing}
                                                    </td>

                                                    <td className="border border-gray-300 p-3">
                                                        {record.distance}
                                                    </td>

                                                </tr>
                  ) :

                  <tr>
                                                <td
                      colSpan="8"
                      className="text-center py-16 text-gray-500 italic">

                                                    No records found yet. Use "Encode Field Sheet".
                                                </td>
                                            </tr>
                  }

                                    </tbody>
                                </table>
                            </div>
                        </div>
          }

                    {/* TAB 2: ENCODE FORM */}
                    {canCreate && activeTab === 'add' &&
          <div className="max-w-5xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">

                            <div className="border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
                                <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                                    Field Data Entry Sheet
                                </h3>

                                <p className="text-sm text-gray-500">
                                    Enter the metadata headers and tree details according to the official manual sheet.
                                </p>
                            </div>

                            <form onSubmit={submitRecord} className="space-y-6">

                                <div className="border border-gray-300 dark:border-gray-700 rounded-xl overflow-hidden bg-gray-50 dark:bg-gray-900/50 p-4 space-y-4">

                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">

                                        <div>




                                            <FloatingSelect id="index-protected-area" label="Protected Area:"
                    value={form.data.protected_area_id}
                    onChange={(e) =>
                    form.setData(
                      'protected_area_id',
                      e.target.value
                    )
                    }

                    required>

                                                <option value="">
                                                    Select Protected Area
                                                </option>

                                                {protectedAreas.map((pa) =>
                      <option
                        key={pa.id}
                        value={pa.id}>

                                                        {pa.name}
                                                    </option>
                      )}
                                            </FloatingSelect>
                                        </div>

                                        <div>




                                            <FloatingInput id="index-date" label="Date:"
                    type="date"
                    value={form.data.date}
                    onChange={(e) =>
                    form.setData(
                      'date',
                      e.target.value
                    )
                    } />


                                        </div>

                                        <div>




                                            <FloatingInput id="index-time" label="Time:"
                    type="text"
                    placeholder="e.g. 08:30 AM"
                    value={form.data.time}
                    onChange={(e) =>
                    form.setData(
                      'time',
                      e.target.value
                    )
                    } />


                                        </div>

                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">

                                        <div className="space-y-3">

                                            <div>




                                                <FloatingInput id="index-plot-no" label="Plot No."
                      type="text"
                      placeholder="Plot No."
                      value={form.data.plot_no}
                      onChange={(e) =>
                      form.setData(
                        'plot_no',
                        e.target.value
                      )
                      } />


                                            </div>

                                            <div>




                                                <FloatingInput id="index-quadrat-no" label="Quadrat No."
                      type="text"
                      placeholder="Quadrat No."
                      value={form.data.quadrat_no}
                      onChange={(e) =>
                      form.setData(
                        'quadrat_no',
                        e.target.value
                      )
                      } />


                                            </div>

                                            <div>




                                                <FloatingInput id="index-transect-no" label="Transect No."
                      type="text"
                      placeholder="Transect No."
                      value={form.data.transect_no}
                      onChange={(e) =>
                      form.setData(
                        'transect_no',
                        e.target.value
                      )
                      } />


                                            </div>

                                        </div>

                                        <div className="space-y-3">

                                            <div>
                                                <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">
                                                    Coordinates:
                                                </label>

                                                <div className="grid grid-cols-2 gap-2">

                                                    <FloatingInput id="index-n" label="N"
                        type="text"
                        placeholder="N"
                        value={form.data.lat}
                        onChange={(e) =>
                        form.setData(
                          'lat',
                          e.target.value
                        )
                        } />



                                                    <FloatingInput id="index-e" label="E"
                        type="text"
                        placeholder="E"
                        value={form.data.long}
                        onChange={(e) =>
                        form.setData(
                          'long',
                          e.target.value
                        )
                        } />



                                                </div>
                                            </div>

                                            <div>




                                                <FloatingInput id="index-elevation-masl" label="Elevation (masl):"
                      type="text"
                      placeholder="masl"
                      value={form.data.elevation}
                      onChange={(e) =>
                      form.setData(
                        'elevation',
                        e.target.value
                      )
                      } />


                                            </div>

                                            <div>




                                                <FloatingInput id="index-gps-unit" label="GPS Unit:"
                      type="text"
                      placeholder="GPS Unit model"
                      value={form.data.gps_unit}
                      onChange={(e) =>
                      form.setData(
                        'gps_unit',
                        e.target.value
                      )
                      } />


                                            </div>

                                        </div>

                                        <div className="space-y-3">

                                            <div>




                                                <FloatingTextarea id="index-observer-s" label="Observer(s):"
                      rows="2"
                      placeholder="Observer names"
                      value={form.data.observer}
                      onChange={(e) =>
                      form.setData(
                        'observer',
                        e.target.value
                      )
                      } />


                                            </div>

                                            <div>




                                                <FloatingInput id="index-vegetation-type" label="Vegetation Type:"
                      type="text"
                      placeholder="Vegetation Type"
                      value={form.data.vegetation_type}
                      onChange={(e) =>
                      form.setData(
                        'vegetation_type',
                        e.target.value
                      )
                      } />


                                            </div>

                                            <div>




                                                <FloatingInput id="index-weather" label="Weather:"
                      type="text"
                      placeholder="Weather condition"
                      value={form.data.weather}
                      onChange={(e) =>
                      form.setData(
                        'weather',
                        e.target.value
                      )
                      } />


                                            </div>

                                        </div>

                                    </div>
                                </div>

                                <div className="space-y-3 bg-gray-50 dark:bg-gray-900/50 p-4 rounded-xl border border-gray-300 dark:border-gray-700">

                                    <h4 className="font-bold text-green-700 dark:text-green-400 text-sm">
                                        🌲 Tree / Species Record Entry
                                    </h4>

                                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4">

                                        <div>




                                            <FloatingInput id="index-species-code" label="Species Code"
                    type="text"
                    value={form.data.species_code}
                    onChange={(e) =>
                    form.setData(
                      'species_code',
                      e.target.value
                    )
                    }

                    placeholder="e.g. Anonggo" />

                                        </div>

                                        <div>




                                            <FloatingInput id="index-dbh-cm" label="DBH (cm)"
                    type="text"
                    value={form.data.dbh}
                    onChange={(e) =>
                    form.setData(
                      'dbh',
                      e.target.value
                    )
                    }

                    placeholder="18" />

                                        </div>

                                        <div>




                                            <FloatingInput id="index-th-m" label="TH (m)"
                    type="text"
                    value={form.data.th}
                    onChange={(e) =>
                    form.setData(
                      'th',
                      e.target.value
                    )
                    }

                    placeholder="10.3" />

                                        </div>

                                        <div>




                                            <FloatingInput id="index-mh-m" label="MH (m)"
                    type="text"
                    value={form.data.mh}
                    onChange={(e) =>
                    form.setData(
                      'mh',
                      e.target.value
                    )
                    }

                    placeholder="4.2" />

                                        </div>

                                        <div>




                                            <FloatingInput id="index-bearing" label="Bearing"
                    type="text"
                    value={form.data.bearing}
                    onChange={(e) =>
                    form.setData(
                      'bearing',
                      e.target.value
                    )
                    }

                    placeholder="N 67° E" />

                                        </div>

                                        <div>




                                            <FloatingInput id="index-distance-m" label="Distance (m)"
                    type="text"
                    value={form.data.distance}
                    onChange={(e) =>
                    form.setData(
                      'distance',
                      e.target.value
                    )
                    }

                    placeholder="4.2" />

                                        </div>

                                        <div className="md:col-span-2">




                                            <FloatingInput id="index-remarks" label="Remarks"
                    type="text"
                    value={form.data.remarks}
                    onChange={(e) =>
                    form.setData(
                      'remarks',
                      e.target.value
                    )
                    }

                    placeholder="Notes" />

                                        </div>

                                    </div>
                                </div>

                                <button
                type="submit"
                disabled={form.processing}
                className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl shadow-sm transition text-sm">

                                    💾 Save Record
                                </button>

                            </form>
                        </div>
          }

                    {/* TAB 3: MAP VIEW */}
                    {activeTab === 'map' &&
          <MapView
            bamsRecords={bamsRecords}
            spatialLayers={spatialLayers}
            canManageSpatial={canManageSpatial}
            onAddSpatialLayer={() => setActiveTab('spatial-import')} />

          }

                    {/* TAB 4: EXCEL / CSV IMPORT */}
                    {canCreate && activeTab === 'excel-import' &&
          <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">

                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-1">
                                Bulk Import Excel / CSV Data
                            </h3>

                            <p className="text-sm text-gray-500 mb-6">
                                Upload spreadsheet files following the BAMS data sheet template format.
                            </p>

                            <form
              onSubmit={submitExcelImport}
              className="space-y-5">


                                <div>




                                    <FloatingSelect id="index-protected-area" label="Protected Area"
                value={excelForm.data.protected_area_id}
                onChange={(e) =>
                excelForm.setData(
                  'protected_area_id',
                  e.target.value
                )
                }

                required>

                                        <option value="">
                                            Select Protected Area
                                        </option>

                                        {protectedAreas.map((pa) =>
                  <option
                    key={pa.id}
                    value={pa.id}>

                                                {pa.name}
                                            </option>
                  )}
                                    </FloatingSelect>
                                </div>

                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">
                                        Excel / CSV File
                                    </label>

                                    <div className="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-xl p-2 bg-gray-50 dark:bg-gray-900">

                                        <div className="cursor-pointer bg-green-100 hover:bg-green-200 text-green-800 font-bold px-4 py-2 rounded-lg text-xs transition">


                    <FileInput id="index-choose-file"
                    type="file"
                    name="file"
                    accept=".xlsx, .xls, .csv"
                    onChange={(e) =>
                    excelForm.setData(
                      'file',
                      e.target.files[0]
                    )
                    }

                    required />

                                        </div>

                                        <span className="text-xs text-gray-500 truncate">
                                            {excelForm.data.file ?
                    excelForm.data.file.name :
                    'No file chosen'}
                                        </span>

                                    </div>
                                </div>

                                <button
                type="submit"
                disabled={excelForm.processing}
                className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">

                                    🚀 Upload and Process Excel Data
                                </button>

                            </form>
                        </div>
          }

                    {/* TAB 5: SPATIAL FILE IMPORT */}
                    {canManageSpatial && activeTab === 'spatial-import' &&
          <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">

                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-1">
                                Import Spatial Boundary File
                            </h3>

                            <p className="text-sm text-gray-500 mb-6">
                                Upload GeoJSON / JSON files to render your spatial boundaries directly on the map.
                            </p>

                            <form
              onSubmit={submitSpatialImport}
              className="space-y-5">


                                <div>
                                    <FloatingInput id="bams-spatial-layer-name" label="Layer Name (optional)" value={spatialForm.data.layer_name} onChange={(e) => spatialForm.setData('layer_name', e.target.value)} />




                                    <FloatingSelect id="index-protected-area" label="Protected Area"
                value={spatialForm.data.protected_area_id}
                onChange={(e) =>
                spatialForm.setData(
                  'protected_area_id',
                  e.target.value
                )
                }

                required>

                                        <option value="">
                                            Select Protected Area
                                        </option>

                                        {protectedAreas.map((pa) =>
                  <option
                    key={pa.id}
                    value={pa.id}>

                                                {pa.name}
                                            </option>
                  )}
                                    </FloatingSelect>
                                </div>

                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">
                                         Spatial Layer (GeoJSON or Shapefile ZIP)
                                    </label>

                                    <div className="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-xl p-2 bg-gray-50 dark:bg-gray-900">

                                        <div className="cursor-pointer bg-green-100 hover:bg-green-200 text-green-800 font-bold px-4 py-2 rounded-lg text-xs transition">


                    <FileInput id="index-choose-spatial-file"
                    type="file"
                    name="spatial_file"
                    accept=".json, .geojson, .zip"
                    onChange={(e) =>
                    spatialForm.setData(
                      'spatial_file',
                      e.target.files[0]
                    )
                    }

                    required />

                                        </div>

                                        <span className="text-xs text-gray-500 truncate">
                                            {spatialForm.data.spatial_file ?
                    spatialForm.data.spatial_file.name :
                    'No file chosen'}
                                        </span>

                                    </div>

                                    <p className="text-[11px] text-gray-400 mt-1.5">
                                        Supported formats: GeoJSON (.geojson, .json) so they can be read directly by the system.
                                    </p>
                                </div>

                                <button
                type="submit"
                disabled={spatialForm.processing}
                className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">

                                    🌐 Upload and Render Spatial Data
                                </button>

                            </form>
                        </div>
          }

                </div>
            </div>
        </AuthenticatedLayout>);

}
