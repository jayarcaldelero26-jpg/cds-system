import React, { useState } from 'react';

export default function Threats() {
  const [enableDelete, setEnableDelete] = useState(false);
  const [selectedArea, setSelectedArea] = useState('all');
  const [selectedCategory, setSelectedCategory] = useState('all');

  const [threatData, setThreatData] = useState([
    {
      id: 1,
      date: '2025-12-31',
      location: 'San Isidro Site A',
      threatType: 'Wildlife Poaching',
      threatDetail: 'Lit-ag / Bukakang',
      extent: '5 traps found',
      severity: 'High Severity',
      coordFormat: 'DD',
      latitude: '6.7123',
      longitude: '126.1234',
      actionsTaken: 'Traps destroyed on-site',
      remarks: 'Escaped / Unidentified'
    },
    {
      id: 2,
      date: '2025-12-31',
      location: 'Governor Generoso Zone 2',
      threatType: 'Illegal Logging',
      threatDetail: 'Magkono Timber Poaching',
      extent: '2 logs / 150 bd.ft.',
      severity: 'Moderate',
      coordFormat: 'DD',
      latitude: '6.5432',
      longitude: '126.0987',
      actionsTaken: 'Chainsaw turned over',
      remarks: 'Arrested (Local resident)'
    },
  ]);

  const [selectedIds, setSelectedIds] = useState([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);

  // Modal States
  const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
  const [showSingleDeleteConfirm, setShowSingleDeleteConfirm] = useState(false);
  const [showSuccess, setShowSuccess] = useState(false);

  // Form State
  const [formData, setFormData] = useState({
    date: '',
    location: '',
    threatType: '',
    threatDetail: '',
    extent: '',
    severity: '',
    coordFormat: 'DD',
    latitude: '',
    longitude: '',
    latDeg: '', latMin: '', latSec: '',
    longDeg: '', longMin: '', longSec: '',
    utmZone: '', easting: '', northing: '',
    actionsTaken: '',
    remarks: ''
  });

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleOpenAdd = () => {
    setEditingId(null);
    setFormData({
      date: '',
      location: '',
      threatType: '',
      threatDetail: '',
      extent: '',
      severity: '',
      coordFormat: 'DD',
      latitude: '',
      longitude: '',
      latDeg: '', latMin: '', latSec: '',
      longDeg: '', longMin: '', longSec: '',
      utmZone: '', easting: '', northing: '',
      actionsTaken: '',
      remarks: ''
    });
    setIsModalOpen(true);
  };

  const handleOpenEdit = (item) => {
    setEditingId(item.id);
    setFormData({
      ...item,
      coordFormat: item.coordFormat || 'DD'
    });
    setIsModalOpen(true);
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (editingId) {
      setThreatData(threatData.map(item => item.id === editingId ? { ...formData, id: editingId } : item));
    } else {
      const newItem = { ...formData, id: Date.now() };
      setThreatData([newItem, ...threatData]);
    }
    setIsModalOpen(false);
    setShowSuccess(true);
  };

  const confirmSingleDelete = () => {
    setThreatData(threatData.filter(item => item.id !== editingId));
    setShowSingleDeleteConfirm(false);
    setIsModalOpen(false);
    setShowSuccess(true);
  };

  const confirmBulkDelete = () => {
    setThreatData(threatData.filter(item => !selectedIds.includes(item.id)));
    setSelectedIds([]);
    setShowBulkDeleteConfirm(false);
    setShowSuccess(true);
  };

  const handleCheckboxChange = (id) => {
    if (selectedIds.includes(id)) {
      setSelectedIds(selectedIds.filter(itemId => itemId !== id));
    } else {
      setSelectedIds([...selectedIds, id]);
    }
  };

  const renderCoordinatesDisplay = (item) => {
    if (item.coordFormat === 'DMS') {
      return `${item.latDeg || 0}° ${item.latMin || 0}' ${item.latSec || 0}", ${item.longDeg || 0}° ${item.longMin || 0}' ${item.longSec || 0}"`;
    } else if (item.coordFormat === 'UTM') {
      return `${item.utmZone || 'N/A'} E:${item.easting || 0} N:${item.northing || 0}`;
    }
    return `${item.latitude || ''}, ${item.longitude || ''}`;
  };

  return (
    <div className="space-y-6">
      {/* Top Filter & Actions Bar */}
      <div className="bg-white dark:bg-gray-800 p-4 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-4">
        <div className="flex flex-wrap items-center gap-4 flex-1">

          <div className="flex flex-col">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Protected Area</span>
            <select
              value={selectedArea}
              onChange={(e) => setSelectedArea(e.target.value)}
              className="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-white text-sm rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500"
            >
              <option value="all">🌐 All Protected Areas (MHRWS)</option>
              <option value="San Isidro Site A">San Isidro Site A</option>
              <option value="Governor Generoso Zone 2">Governor Generoso Zone 2</option>
            </select>
          </div>

          <div className="flex flex-col">
            <span className="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Threat Category</span>
            <select
              value={selectedCategory}
              onChange={(e) => setSelectedCategory(e.target.value)}
              className="bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-800 dark:text-white text-sm rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-emerald-500"
            >
              <option value="all">⚠️ All Threat Types</option>
              <option value="Illegal Logging">Illegal Logging</option>
              <option value="Wildlife Poaching">Wildlife Poaching</option>
            </select>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-3">
          <button
            onClick={handleOpenAdd}
            className="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm flex items-center gap-1.5"
          >
            <span>+ Add Threat Record</span>
          </button>

          {enableDelete && selectedIds.length > 0 && (
            <button
              onClick={() => setShowBulkDeleteConfirm(true)}
              className="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors shadow-sm"
            >
              Delete ({selectedIds.length})
            </button>
          )}

          <label className="flex items-center gap-2 cursor-pointer bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 border border-gray-300 dark:border-gray-600 px-4 py-2 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-200 transition-colors shadow-sm">
            <input
              type="checkbox"
              checked={enableDelete}
              onChange={(e) => {
                setEnableDelete(e.target.checked);
                if (!e.target.checked) setSelectedIds([]);
              }}
              className="rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer"
            />
            <span>Enable Select to Delete</span>
          </label>
        </div>
      </div>

      {/* Table Section with the exact requested layout */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full text-left border-collapse">
            <thead>
              <tr className="bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-[11px] font-bold uppercase tracking-wider border-b border-gray-200 dark:border-gray-600">
                {enableDelete && <th className="py-3 px-4 text-center w-12">Select</th>}
                <th className="py-3 px-5">Date / Location</th>
                <th className="py-3 px-5">Threat Classification</th>
                <th className="py-3 px-5">Extent / Scale of Impact</th>
                <th className="py-3 px-5">Coordinates</th>
                <th className="py-3 px-5">Actions Taken</th>
                <th className="py-3 px-5">Remarks</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-700 dark:text-gray-300">
              {threatData.map((item) => (
                <tr
                  key={item.id}
                  onClick={() => handleOpenEdit(item)}
                  className="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors cursor-pointer"
                >
                  {enableDelete && (
                    <td className="py-4 px-4 text-center" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        checked={selectedIds.includes(item.id)}
                        onChange={() => handleCheckboxChange(item.id)}
                        className="rounded text-red-600 focus:ring-red-500 w-4 h-4 cursor-pointer"
                      />
                    </td>
                  )}

                  {/* Date / Location */}
                  <td className="py-4 px-5">
                    <div className="font-bold text-gray-900 dark:text-white">{item.date}</div>
                    <div className="text-xs text-gray-400 mt-0.5">{item.location}</div>
                  </td>

                  {/* Threat Classification */}
                  <td className="py-4 px-5">
                    <span className="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">
                      {item.threatType}
                    </span>
                    <div className="text-xs font-semibold text-gray-800 dark:text-gray-200 mt-1">{item.threatDetail}</div>
                  </td>

                  {/* Extent / Scale */}
                  <td className="py-4 px-5">
                    <div className="text-xs font-semibold text-gray-800 dark:text-gray-200">{item.extent}</div>
                    <div className="text-[11px] text-gray-400 mt-0.5">{item.severity}</div>
                  </td>

                  {/* Coordinates */}
                  <td className="py-4 px-5 font-mono text-xs text-gray-500 dark:text-gray-400">
                    {renderCoordinatesDisplay(item)}
                  </td>

                  {/* Actions Taken */}
                  <td className="py-4 px-5 text-xs text-gray-700 dark:text-gray-300">
                    {item.actionsTaken}
                  </td>

                  {/* Remarks */}
                  <td className="py-4 px-5 text-xs text-gray-500 dark:text-gray-400 italic">
                    {item.remarks}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {/* Modal for Add / Edit */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-3xl w-full shadow-2xl border border-gray-100 dark:border-gray-700 max-h-[90vh] overflow-y-auto">

            <div className="flex justify-between items-start mb-4 pb-3 border-b border-gray-100 dark:border-gray-700">
              <div>
                <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                  {editingId ? 'Edit / Delete Threat Record' : 'Add Threat Record'}
                </h3>
                <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Fill out the form based on the Protected Area Threat Monitoring guidelines.
                </p>
              </div>
              <button
                onClick={() => setIsModalOpen(false)}
                className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-sm font-bold p-1"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">

              <div className="text-xs font-bold text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5 pt-1">
                <span>🔍 Threat Observation Details (Table Entry)</span>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Date *</label>
                  <input
                    type="date"
                    name="date"
                    value={formData.date}
                    onChange={handleChange}
                    required
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Location *</label>
                  <input
                    type="text"
                    name="location"
                    value={formData.location}
                    onChange={handleChange}
                    required
                    placeholder="e.g., San Isidro Site A"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Threat Type *</label>
                  <input
                    type="text"
                    name="threatType"
                    value={formData.threatType}
                    onChange={handleChange}
                    required
                    placeholder="e.g., Wildlife Poaching"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Threat Detail / Specifics *</label>
                  <input
                    type="text"
                    name="threatDetail"
                    value={formData.threatDetail}
                    onChange={handleChange}
                    required
                    placeholder="e.g., Lit-ag / Bukakang"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Extent / Scale *</label>
                  <input
                    type="text"
                    name="extent"
                    value={formData.extent}
                    onChange={handleChange}
                    required
                    placeholder="e.g., 5 traps found"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Severity</label>
                  <input
                    type="text"
                    name="severity"
                    value={formData.severity}
                    onChange={handleChange}
                    placeholder="e.g., High Severity"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>
              </div>

              {/* Coordinate Format Input Box with Dropdown */}
              <div className="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-xl border border-gray-200 dark:border-gray-700 space-y-3">
                <div className="flex justify-between items-center">
                  <div className="text-xs font-bold text-gray-700 dark:text-gray-300 flex items-center gap-1.5">
                    <span>🌐 Coordinate Format Input</span>
                  </div>
                  <select
                    name="coordFormat"
                    value={formData.coordFormat}
                    onChange={handleChange}
                    className="bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-200 outline-none focus:ring-2 focus:ring-emerald-500"
                  >
                    <option value="DD">Decimal Degrees (DD)</option>
                    <option value="DMS">Degrees, Minutes, Seconds (DMS)</option>
                    <option value="UTM">UTM Zone</option>
                  </select>
                </div>

                {formData.coordFormat === 'DD' && (
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <label className="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Latitude</label>
                      <input
                        type="text"
                        name="latitude"
                        value={formData.latitude}
                        onChange={handleChange}
                        required
                        placeholder="e.g., 6.7123"
                        className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                      />
                    </div>
                    <div>
                      <label className="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Longitude</label>
                      <input
                        type="text"
                        name="longitude"
                        value={formData.longitude}
                        onChange={handleChange}
                        required
                        placeholder="e.g., 126.1234"
                        className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                      />
                    </div>
                  </div>
                )}

                {formData.coordFormat === 'DMS' && (
                  <div className="space-y-3">
                    <div className="grid grid-cols-3 gap-2">
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Lat Deg</label>
                        <input type="text" name="latDeg" value={formData.latDeg} onChange={handleChange} placeholder="e.g., 6" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Lat Min</label>
                        <input type="text" name="latMin" value={formData.latMin} onChange={handleChange} placeholder="e.g., 42" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Lat Sec</label>
                        <input type="text" name="latSec" value={formData.latSec} onChange={handleChange} placeholder="e.g., 34" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Long Deg</label>
                        <input type="text" name="longDeg" value={formData.longDeg} onChange={handleChange} placeholder="e.g., 126" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Long Min</label>
                        <input type="text" name="longMin" value={formData.longMin} onChange={handleChange} placeholder="e.g., 7" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                      <div>
                        <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Long Sec</label>
                        <input type="text" name="longSec" value={formData.longSec} onChange={handleChange} placeholder="e.g., 24" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                      </div>
                    </div>
                  </div>
                )}

                {formData.coordFormat === 'UTM' && (
                  <div className="grid grid-cols-3 gap-2">
                    <div>
                      <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">UTM Zone</label>
                      <input type="text" name="utmZone" value={formData.utmZone} onChange={handleChange} placeholder="e.g., 51N" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                    </div>
                    <div>
                      <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Easting</label>
                      <input type="text" name="easting" value={formData.easting} onChange={handleChange} placeholder="e.g., 500000" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                    </div>
                    <div>
                      <label className="block text-[10px] font-semibold text-gray-500 dark:text-gray-400 mb-1">Northing</label>
                      <input type="text" name="northing" value={formData.northing} onChange={handleChange} placeholder="e.g., 700000" className="w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-xs rounded-lg px-2 py-1.5 text-gray-800 dark:text-white outline-none" />
                    </div>
                  </div>
                )}
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Actions Taken *</label>
                  <input
                    type="text"
                    name="actionsTaken"
                    value={formData.actionsTaken}
                    onChange={handleChange}
                    required
                    placeholder="e.g., Traps destroyed on-site"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>

                <div>
                  <label className="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Remarks</label>
                  <input
                    type="text"
                    name="remarks"
                    value={formData.remarks}
                    onChange={handleChange}
                    placeholder="e.g., Unidentified suspect"
                    className="w-full bg-gray-50 dark:bg-gray-700/50 border border-gray-300 dark:border-gray-600 text-sm rounded-xl px-3.5 py-2.5 text-gray-800 dark:text-white outline-none focus:ring-2 focus:ring-emerald-500 transition"
                  />
                </div>
              </div>

              {/* Footer Actions inside Modal */}
              <div className="flex items-center justify-between pt-6 border-t border-gray-100 dark:border-gray-700">
                {editingId ? (
                  <button
                    type="button"
                    onClick={() => setShowSingleDeleteConfirm(true)}
                    className="px-4 py-2.5 bg-red-50 hover:bg-red-100 dark:bg-red-950/50 dark:hover:bg-red-900/50 text-red-600 dark:text-red-400 rounded-xl text-sm font-semibold transition flex items-center gap-1.5"
                  >
                    🗑️ Delete Record
                  </button>
                ) : (
                  <div></div>
                )}

                <div className="flex gap-3">
                  <button
                    type="button"
                    onClick={() => setIsModalOpen(false)}
                    className="px-5 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-6 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded-xl text-sm font-semibold shadow-md transition flex items-center gap-1.5"
                  >
                    💾 {editingId ? 'Save Changes' : 'Save Threat Record'}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* SINGLE DELETE CONFIRMATION MODAL */}
      {showSingleDeleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">
            <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">
              ⚠️
            </div>
            <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete Record?</h3>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Do you really want to delete this record? This process cannot be undone.</p>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => setShowSingleDeleteConfirm(false)}
                className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={confirmSingleDelete}
                className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition"
              >
                Yes, Delete
              </button>
            </div>
          </div>
        </div>
      )}

      {/* BULK DELETE CONFIRMATION MODAL */}
      {showBulkDeleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
          <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-red-100 dark:border-red-950 text-center animate-pop-in">
            <div className="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-100 dark:bg-red-950 mb-4 shadow-sm text-red-600 dark:text-red-400 text-2xl">
              ⚠️
            </div>
            <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2">Delete Selected Records?</h3>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Are you sure you want to delete {selectedIds.length} selected record(s)? This cannot be undone.</p>
            <div className="flex gap-3">
              <button
                type="button"
                onClick={() => setShowBulkDeleteConfirm(false)}
                className="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={confirmBulkDelete}
                className="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold shadow-sm transition"
              >
                Yes, Delete All
              </button>
            </div>
          </div>
        </div>
      )}

      {/* SUCCESS MODAL POPUP */}
      {showSuccess && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
          <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-emerald-100 dark:border-emerald-900 text-center animate-pop-in">
            <div className="checkmark-circle mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-emerald-100 dark:bg-emerald-950 mb-4 shadow-sm">
              <svg className="h-8 w-8 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" strokeWidth="3" stroke="currentColor">
                <path className="checkmark-check" strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 font-sans">Success!</h3>
            <p className="text-sm text-gray-600 dark:text-gray-300 mb-6">Action completed successfully.</p>
            <button
              onClick={() => setShowSuccess(false)}
              className="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-xl shadow-sm transition text-sm"
            >
              Continue
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
