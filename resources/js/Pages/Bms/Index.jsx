import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, router, useForm } from '@inertiajs/react';
import { useState, useEffect } from 'react';

// Leaflet Map Marker Icons Import
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';

// Recharts Imports for Visual Graphs
import { ResponsiveContainer, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend } from 'recharts';

// Import Threats Component and MapView Component
import Threats from './Threats';
import MapView from './MapView';
import ReportSubmissionTracker from './ReportSubmissionTracker';
import ConfirmDialog from '@/Components/ConfirmDialog';
import CrudTable from '@/Components/Crud/CrudTable';
import CrudDetailsModal from '@/Components/Crud/CrudDetailsModal';
import CrudFormModal from '@/Components/Crud/CrudFormModal';
import CrudSection from '@/Components/Crud/CrudSection';
import CrudSummaryGrid from '@/Components/Crud/CrudSummaryGrid';

const floraIcon = L.divIcon({
    className: 'custom-marker',
    html: `<div style="background-color: #16a34a; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 3px 6px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">🌱</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    popupAnchor: [0, -15]
});

const faunaIcon = L.divIcon({
    className: 'custom-marker',
    html: `<div style="background-color: #d97706; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 3px 6px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">🐾</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    popupAnchor: [0, -15]
});

const threatIcon = L.divIcon({
    className: 'custom-marker',
    html: `<div style="background-color: #dc2626; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 3px 6px rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; color: white; font-size: 14px;">⚠️</div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    popupAnchor: [0, -15]
});

const formatStationDisplay = (stationStr) => {
    if (!stationStr && stationStr !== 0) return { label: '-', meters: '' };
    const cleaned = (stationStr + '').replace(/station/gi, '').trim();
    const match = cleaned.match(/(\d+)\s*(?:-|to)\s*(\d+)/i);
    if (match) {
        const start = parseInt(match[1]);
        const end = parseInt(match[2]);
        return {
            label: `${start} - ${end}`,
            meters: `${start * 250} - ${end * 250} meters`
        };
    } else {
        const num = parseInt(cleaned);
        if (!isNaN(num)) {
            return {
                label: `${num} - ${num + 1}`,
                meters: `${num * 250} - ${(num + 1) * 250} meters`
            };
        }
    }
    return { label: cleaned, meters: '' };
};

const calculateTrendStatus = (points) => {
    const n = points.length;
    if (n < 2) return { slope: 0, status: '➡️ Insufficient Data (Requires 2+ Semesters)' };

    let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
    points.forEach((p, index) => {
        const x = index;
        const y = p.count;
        sumX += x;
        sumY += y;
        sumXY += (x * y);
        sumXX += (x * x);
    });

    const denominator = (n * sumXX - sumX * sumX);
    if (denominator === 0) return { slope: 0, status: '➡️ Stable (No Fluctuation)' };

    const slope = (n * sumXY - sumX * sumY) / denominator;

    let status = '➡️ Stable (No Significant Change)';
    if (slope > 0.05) {
        status = '📈 Increasing (Growing Population)';
    } else if (slope < -0.05) {
        status = '📉 Decreasing (Declining Population)';
    }

    return { slope, status };
};

export default function Index({ auth, bmsRecords, protectedAreas, filters, spatialData, annexHeaderMetadata, reportSubmissions, reportFilters, bmsThreats = [], initialTab }) {
    const canCreateBms = Boolean(auth?.canCreateBms);
    const canUpdateBms = Boolean(auth?.canUpdateBms);
    const canDeleteBms = Boolean(auth?.canDeleteBms);
    const canExportBms = Boolean(auth?.canExportBms);
    const canManageBmsSpatial = Boolean(auth?.canManageBmsSpatial);
    const [activeTab, setActiveTab] = useState(reportFilters?.tracker ? 'report-tracker' : (initialTab || 'list'));
    const [viewMode, setViewMode] = useState('table');
    const [semestralViewMode, setSemestralViewMode] = useState('table');
    const [graphYearFilter, setGraphYearFilter] = useState(filters?.year ? String(filters.year) : 'All');
    const [semestralPaFilter, setSemestralPaFilter] = useState('All');
    const [mapCategoryFilter, setMapCategoryFilter] = useState('All');

    // Threat Data State for Map View Integration
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

    const [acknowledgedSpecies, setAcknowledgedSpecies] = useState(() => {
        try {
            const saved = localStorage.getItem('acknowledged_bms_species');
            return saved ? JSON.parse(saved) : [];
        } catch (e) {
            return [];
        }
    });

    useEffect(() => {
        try {
            localStorage.setItem('acknowledged_bms_species', JSON.stringify(acknowledgedSpecies));
        } catch (e) {}
    }, [acknowledgedSpecies]);

    const handleAcknowledge = (speciesKey) => {
        if (!canUpdateBms) return;

        if (!acknowledgedSpecies.includes(speciesKey)) {
            setAcknowledgedSpecies([...acknowledgedSpecies, speciesKey]);
        }
    };

    const [coordType, setCoordType] = useState('DD');
    const [editCoordType, setEditCoordType] = useState('DD');
    const [isSelectionMode, setIsSelectionMode] = useState(false);
    const [selectedIds, setSelectedIds] = useState([]);
    const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);

    const [editingRecord, setEditingRecord] = useState(null);
    const [isEditingRecord, setIsEditingRecord] = useState(false);
    const [actionError, setActionError] = useState('');
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [bulkDeleteProcessing, setBulkDeleteProcessing] = useState(false);
    const [showEditHeaderModal, setShowEditHeaderModal] = useState(false);

    const [latDeg, setLatDeg] = useState('');
    const [latMin, setLatMin] = useState('');
    const [latSec, setLatSec] = useState('');
    const [latDir, setLatDir] = useState('N');

    const [lonDeg, setLonDeg] = useState('');
    const [lonMin, setLonMin] = useState('');
    const [lonSec, setLonSec] = useState('');
    const [lonDir, setLonDir] = useState('E');

    const [utmZone, setUtmZone] = useState('51N');
    const [easting, setEasting] = useState('');
    const [northing, setNorthing] = useState('');

    const [editLatDeg, setEditLatDeg] = useState('');
    const [editLatMin, setEditLatMin] = useState('');
    const [editLatSec, setEditLatSec] = useState('');
    const [editLatDir, setEditLatDir] = useState('N');

    const [editLonDeg, setEditLonDeg] = useState('');
    const [editLonMin, setEditLonMin] = useState('');
    const [editLonSec, setEditLonSec] = useState('');
    const [editLonDir, setEditLonDir] = useState('E');

    const [editUtmZone, setEditUtmZone] = useState('51N');
    const [editEasting, setEditEasting] = useState('');
    const [editNorthing, setEditNorthing] = useState('');

    const form = useForm({
        protected_area_id: '',
        monitoring_date: '',
        station: '',
        time: '',
        category: 'Flora',
        taxonomic_group: 'trees',
        species_common_name: '',
        species_scientific_name: '',
        count: '',
        observer_name: '',
        latitude: '',
        longitude: '',
        elevation: '',
        remarks: '',
        mode_of_observation: 'Seen',
        location: '',
    });

    const editForm = useForm({
        protected_area_id: '',
        monitoring_date: '',
        station: '',
        time: '',
        category: 'Flora',
        taxonomic_group: '',
        species_common_name: '',
        species_scientific_name: '',
        count: '',
        observer_name: '',
        latitude: '',
        longitude: '',
        elevation: '',
        remarks: '',
        mode_of_observation: 'Seen',
        location: '',
    });

    const annexHeaderForm = useForm({
        location: '',
        date_conducted: '',
        start_end_time: '',
        length_of_transect: '',
        start_gps: '',
        end_gps: '',
        weather_condition: '',
        elevation: '',
        ecosystem_type: '',
        species_observed: '',
        observer: '',
    });

    const importForm = useForm({
        protected_area_id: '',
        file: null,
    });

    const geoJsonForm = useForm({
        protected_area_id: '',
        file: null,
    });

    const convertDmsToDd = (deg, min, sec, dir) => {
        let val = parseFloat(deg || 0) + (parseFloat(min || 0) / 60) + (parseFloat(sec || 0) / 3600);
        if (dir === 'S' || dir === 'W') val = -val;
        return val.toFixed(6);
    };

    const submitRecord = (e) => {
        e.preventDefault();
        let finalLat = form.data.latitude;
        let finalLon = form.data.longitude;
        let finalRemarks = form.data.remarks;

        if (coordType === 'DMS') {
            finalLat = convertDmsToDd(latDeg, latMin, latSec, latDir);
            finalLon = convertDmsToDd(lonDeg, lonMin, lonSec, lonDir);
        } else if (coordType === 'UTM') {
            finalLat = northing || '0';
            finalLon = easting || '0';
            finalRemarks = `[UTM Zone: ${utmZone}, Easting: ${easting}, Northing: ${northing}] ${form.data.remarks || ''}`;
        }

        form.transform((data) => ({
            ...data,
            latitude: finalLat,
            longitude: finalLon,
            remarks: finalRemarks,
        }));

        form.post(route('bms.store'), {
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                setActiveTab('list');
            },
        });
    };

    const openRecordDetails = (record) => {
        if (isSelectionMode) return;
        editForm.clearErrors();
        setActionError('');
        setEditingRecord(record);
        setIsEditingRecord(false);
    };

    const beginEditingRecord = () => {
        if (!editingRecord || !canUpdateBms) return;
        editForm.clearErrors();
        setEditCoordType('DD');
        setEditEasting('');
        setEditNorthing('');
        editForm.setData({
            protected_area_id: editingRecord.protected_area_id || '',
            monitoring_date: editingRecord.monitoring_date ? editingRecord.monitoring_date.split('T')[0] : '',
            station: editingRecord.station || '',
            time: editingRecord.time || '',
            category: editingRecord.category || 'Flora',
            taxonomic_group: editingRecord.taxonomic_group || '',
            species_common_name: editingRecord.species_common_name || '',
            species_scientific_name: editingRecord.species_scientific_name || '',
            count: editingRecord.count ?? '',
            observer_name: editingRecord.observer_name || '',
            latitude: editingRecord.latitude || '',
            longitude: editingRecord.longitude || '',
            elevation: editingRecord.elevation || '',
            remarks: editingRecord.remarks || '',
            mode_of_observation: editingRecord.mode_of_observation || 'Seen',
            location: editingRecord.location || '',
        });
        setIsEditingRecord(true);
    };

    const closeRecordModal = () => {
        setEditingRecord(null);
        setIsEditingRecord(false);
        setActionError('');
        editForm.clearErrors();
    };

    const closeAddForm = () => {
        form.clearErrors();
        setActiveTab('list');
    };

    const submitEdit = (e) => {
        e.preventDefault();
        let finalLat = editForm.data.latitude;
        let finalLon = editForm.data.longitude;
        let finalRemarks = editForm.data.remarks;

        if (editCoordType === 'DMS') {
            finalLat = convertDmsToDd(editLatDeg, editLatMin, editLatSec, editLatDir);
            finalLon = convertDmsToDd(editLonDeg, editLonMin, editLonSec, editLonDir);
        } else if (editCoordType === 'UTM') {
            finalLat = editNorthing || '0';
            finalLon = editEasting || '0';
            finalRemarks = `[UTM Zone: ${editUtmZone}, Easting: ${editEasting}, Northing: ${editNorthing}] ${editForm.data.remarks || ''}`;
        }

        editForm.transform((data) => ({
            ...data,
            _method: 'put',
            latitude: finalLat,
            longitude: finalLon,
            remarks: finalRemarks,
        }));

        editForm.post(route('bms.update', editingRecord.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                closeRecordModal();
            },
            onError: () => setActionError('Please correct the highlighted fields and try again.'),
        });
    };

    const submitHeaderEdit = (e) => {
        e.preventDefault();
        router.post(route('bms.bulk-update-header'), {
            protected_area_id: filters?.protected_area_id || null,
            category: filters?.category || null,
            start_date: filters?.start_date || null,
            end_date: filters?.end_date || null,
            ...annexHeaderForm.data,
            species_observed: annexSpeciesObserved,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowEditHeaderModal(false);
            },
            onError: (err) => {
                console.error("Header update error:", err);
                alert("Failed to update header details.");
            }
        });
    };

    const confirmDelete = () => {
        if (!editingRecord || !canDeleteBms || deleteProcessing) return;
        setDeleteProcessing(true);
        router.delete(route('bms.destroy', editingRecord.id), {
            preserveScroll: true,
            onSuccess: () => {
                setShowDeleteConfirm(false);
                closeRecordModal();
            },
            onError: (errors) => {
                console.error("Delete Error:", errors);
                setShowDeleteConfirm(false);
                setActionError('The record could not be deleted. Please try again.');
            },
            onFinish: () => setDeleteProcessing(false),
        });
    };

    const confirmBulkDelete = () => {
        if (selectedIds.length === 0 || !canDeleteBms || bulkDeleteProcessing) return;
        setBulkDeleteProcessing(true);
        router.post(route('bms.bulk-destroy'), { ids: selectedIds }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedIds([]);
                setIsSelectionMode(false);
                setShowBulkDeleteConfirm(false);
            },
            onError: (errors) => {
                console.error("Bulk Delete Error:", errors);
                setShowBulkDeleteConfirm(false);
            },
            onFinish: () => setBulkDeleteProcessing(false),
        });
    };

    const submitImport = (e) => {
        e.preventDefault();
        importForm.post(route('bms.import'), {
            onSuccess: () => {
                importForm.reset();
                setActiveTab('list');
            },
        });
    };

    const submitGeoJsonImport = (e) => {
        e.preventDefault();
        geoJsonForm.post(route('bms.import-geojson'), {
            onSuccess: () => {
                geoJsonForm.reset();
                setActiveTab('map');
            },
        });
    };

    const getSemestralAggregates = () => {
        const map = {};
        bmsRecords.forEach(r => {
            if (semestralPaFilter !== 'All' && semestralPaFilter !== '' && String(r.protected_area_id) !== String(semestralPaFilter)) {
                return;
            }
            if (!r.monitoring_date) return;
            const dateObj = new Date(r.monitoring_date);
            const year = dateObj.getFullYear();
            const month = dateObj.getMonth() + 1;
            const sem = month <= 6 ? 1 : 2;
            const key = `${r.species_scientific_name || 'Unknown'}___${r.station || '-'}`;

            if (!map[key]) {
                map[key] = {
                    species: r.species_scientific_name || 'Unknown',
                    common: r.species_common_name || '',
                    category: r.category || 'Flora',
                    station: r.station || '-',
                    semesters: {}
                };
            }
            const semKey = `${year}-Sem ${sem}`;
            const parsedCount = Number.parseFloat(r.count);
            const countNum = Number.isFinite(parsedCount) && parsedCount >= 0
                ? Math.trunc(parsedCount)
                : 0;
            if (!map[key].semesters[semKey]) {
                map[key].semesters[semKey] = 0;
            }
            map[key].semesters[semKey] += countNum;
        });

        let allSemKeysFound = [];
        Object.values(map).forEach(item => {
            Object.keys(item.semesters).forEach(k => allSemKeysFound.push(k));
        });
        allSemKeysFound.sort();
        const latestOverallSemester = allSemKeysFound.length > 0 ? allSemKeysFound[allSemKeysFound.length - 1] : '';

        const aggregates = Object.values(map).map(item => {
            const sortedSemKeys = Object.keys(item.semesters).sort((a, b) => {
                const [yearA, semStrA] = a.split('-Sem ');
                const [yearB, semStrB] = b.split('-Sem ');
                if (yearA !== yearB) return parseInt(yearA) - parseInt(yearB);
                return parseInt(semStrA) - parseInt(semStrB);
            });

            const points = sortedSemKeys.map(k => ({
                period: k,
                year: k.split('-')[0],
                count: item.semesters[k]
            }));

            const trend = calculateTrendStatus(points);
            const earliestPeriod = points.length > 0 ? points[0].period : '';
            const isNewSpecies = earliestPeriod === latestOverallSemester && points.length === 1;

            return {
                ...item,
                points,
                latestSemCount: points.length > 0 ? points[points.length - 1].count : 0,
                previousSemCount: points.length > 1 ? points[points.length - 2].count : 0,
                trend,
                isNewSpecies
            };
        });

        if (graphYearFilter === 'All') {
            return aggregates;
        } else {
            return aggregates.filter(item => item.points.some(pt => pt.year === graphYearFilter));
        }
    };

    const checkIsNewSpeciesRecord = (record) => {
        if (!record.monitoring_date || !record.species_scientific_name) return false;
        const dateObj = new Date(record.monitoring_date);
        const year = dateObj.getFullYear();
        const month = dateObj.getMonth() + 1;
        const sem = month <= 6 ? 1 : 2;

        const aggregates = getSemestralAggregates();
        const match = aggregates.find(agg => agg.species === record.species_scientific_name && agg.station === (record.station || '-'));
        if (!match) return false;

        const speciesKey = `${match.species}___${match.station}`;
        const isAcknowledged = acknowledgedSpecies.includes(speciesKey);

        return match.isNewSpecies && !isAcknowledged;
    };

    const getAvailableYears = () => {
        const yearsSet = new Set();
        bmsRecords.forEach(r => {
            if (semestralPaFilter !== 'All' && semestralPaFilter !== '' && String(r.protected_area_id) !== String(semestralPaFilter)) {
                return;
            }
            if (r.monitoring_date) {
                yearsSet.add(new Date(r.monitoring_date).getFullYear().toString());
            }
        });
        return Array.from(yearsSet).sort((a, b) => b.localeCompare(a));
    };

    const formatTimeOfArrival = (time) => {
        if (!time) return '-';

        const match = String(time).trim().match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
        if (!match) return time;

        const hour = Number(match[1]);
        const minutes = match[2];
        const meridiem = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 || 12;

        return `${String(displayHour).padStart(2, '0')}:${minutes} ${meridiem}`;
    };

    const headerValue = (field) => annexHeaderMetadata?.[field] || 'N/A';

    // Species Observed in the Annex header follows the active Category filter.
    // All Categories means the Annex covers both Flora and Fauna.
    const annexSpeciesObserved =
        filters?.category === 'Flora'
            ? 'Flora'
            : filters?.category === 'Fauna'
                ? 'Fauna'
                : 'Flora / Fauna';

    const getGraphChartData = () => {
        const aggregates = getSemestralAggregates();
        const periodMap = {};

        aggregates.forEach(item => {
            item.points.forEach(pt => {
                if (graphYearFilter === 'All' || pt.year === graphYearFilter) {
                    if (!periodMap[pt.period]) {
                        periodMap[pt.period] = { period: pt.period };
                    }
                    periodMap[pt.period][item.species] = pt.count;
                }
            });
        });

        return Object.values(periodMap).sort((a, b) => a.period.localeCompare(b.period));
    };

    const exportAnnexToCSV = () => {
        const first = bmsRecords[0] || {};
        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "REPUBLIC OF THE PHILIPPINES\n";
        csvContent += "Department of Environment and Natural Resources\n";
        csvContent += `Location:,${first.location || 'N/A'},Date Conducted:,${first.monitoring_date || 'N/A'}\n`;
        csvContent += `Start/End Time:,${first.time || 'N/A'},Length of Transect:,${first.length_of_transect || 'N/A'}\n`;
        csvContent += `Start GPS:,${first.start_gps || 'N/A'},End GPS:,${first.end_gps || 'N/A'}\n`;
        csvContent += `Weather:,${first.weather_condition || 'N/A'},Elevation:,${first.elevation || 'N/A'}\n`;
        csvContent += `Ecosystem:,${first.ecosystem_type || 'N/A'},Observer:,${first.observer_name || 'N/A'}\n\n`;

        csvContent += "Station / Meters,Time of Arrival,Species Observed (Local Name / Scientific Name),Count,Mode\n";

        bmsRecords.forEach(record => {
            const stationInfo = formatStationDisplay(record.station);
            const speciesName = `${record.species_common_name || ''} (${record.species_scientific_name || ''})`.replace(/,/g, '');
            const row = [
                `"${stationInfo.label}"`,
                `"${formatTimeOfArrival(record.time)}"`,
                `"${speciesName}"`,
                `"${record.count || '-'}"`,
                `"${record.mode_of_observation || 'Seen'}"`
            ];
            csvContent += row.join(",") + "\n";
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Annex_Summary_${filters?.category || 'BMS'}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const exportSemestralToCSV = () => {
        let csvContent = "data:text/csv;charset=utf-8,";
        csvContent += "Semestral Population Monitoring & Trend Report\n";
        csvContent += "Species Name,Station,Latest Count,Previous Count,Population Trend Status,New Species Flag\n";

        getSemestralAggregates().forEach(item => {
            const speciesKey = `${item.species}___${item.station}`;
            const isAcknowledged = acknowledgedSpecies.includes(speciesKey);
            const row = [
                `"${item.species} - ${item.common}"`,
                `"${item.station}"`,
                item.latestSemCount,
                item.previousSemCount,
                `"${item.trend.status}"`,
                item.isNewSpecies && !isAcknowledged ? `"Yes"` : `"No"`
            ];
            csvContent += row.join(",") + "\n";
        });

        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", `Semestral_Trend_Monitoring.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const fieldError = (errors, field) => errors?.[field]
        ? <p className="mt-1 text-xs font-medium text-red-600 dark:text-red-400" role="alert">{errors[field]}</p>
        : null;
    const speciesColumns = [
        { key: 'date_location', label: 'Date / Location', render: record => <><div className="font-semibold">{record.monitoring_date || 'N/A'}</div><div className="max-w-[150px] truncate text-[11px] text-gray-500">{record.location || 'No location'}</div></> },
        { key: 'station_time', label: 'Station / Time', render: record => <><div className="font-bold text-green-700 dark:text-green-400">{record.station || '-'}</div><div className="text-[11px] text-gray-500">{record.time || '-'}</div></> },
        { key: 'category_group', label: 'Category / Group', render: record => <><span className={`rounded-lg px-2 py-0.5 text-[10px] font-bold ${record.category === 'Fauna' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' : 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300'}`}>{record.category || 'Flora'}</span><div className="mt-1 text-[11px] capitalize text-gray-500">{record.taxonomic_group || '-'}</div></> },
        { key: 'species', label: 'Species (Scientific / Common Name)', render: record => { const isNew = checkIsNewSpeciesRecord(record); const speciesKey = `${record.species_scientific_name || 'Unknown'}___${record.station || '-'}`; return <><div className="flex flex-wrap items-center gap-2"><span className="font-bold italic text-gray-900 dark:text-white">{record.species_scientific_name || 'Unnamed Species'}</span>{isNew && <div className="flex items-center gap-1.5" onClick={event => event.stopPropagation()}><span className="animate-pulse rounded-lg bg-emerald-100 px-2 py-0.5 text-[10px] font-extrabold uppercase text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">✨ New Species</span>{canUpdateBms && <button type="button" onClick={() => handleAcknowledge(speciesKey)} className="rounded-lg bg-gray-200 px-2 py-0.5 text-[10px] font-semibold transition hover:bg-green-600 hover:text-white dark:bg-gray-700">✓ Acknowledge</button>}</div>}</div><div className="text-[11px] text-gray-600 dark:text-gray-400">{record.species_common_name || ''}</div></>; } },
        { key: 'count', label: 'Count', cellClassName: 'text-center font-bold', headerClassName: 'text-center', render: record => record.count ?? '—' },
        { key: 'mode_of_observation', label: 'Mode', cellClassName: 'text-center', headerClassName: 'text-center', render: record => <span className="rounded-lg bg-gray-100 px-2 py-0.5 text-[11px] font-medium dark:bg-gray-700">{record.mode_of_observation || 'Seen'}</span> },
        { key: 'coordinates', label: 'GPS Coordinates', cellClassName: 'font-mono text-[11px]', render: record => record.latitude && record.longitude && Number.isFinite(Number.parseFloat(record.latitude)) && Number.isFinite(Number.parseFloat(record.longitude)) ? `${Number.parseFloat(record.latitude).toFixed(4)}, ${Number.parseFloat(record.longitude).toFixed(4)}` : <span className="italic text-gray-400">No GPS</span> },
    ];

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="BMS Monitoring" />

            <style>{`
                @keyframes stroke { 100% { stroke-dashoffset: 0; } }
                @keyframes scale { 0%, 100% { transform: none; } 50% { transform: scale3d(1.15, 1.15, 1); } }
                @keyframes popIn { 0% { transform: scale(0.9); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
                .animate-pop-in { animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1) forwards; }
                .checkmark-circle { animation: scale 0.3s ease-in-out 0.3s both; }
                .checkmark-check { stroke-dasharray: 50; stroke-dashoffset: 50; animation: stroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.15s forwards; }

                .custom-table-scrollbar {
                    scrollbar-width: thin;
                    scrollbar-color: rgba(156, 163, 175, 0.5) transparent;
                }
                .custom-table-scrollbar::-webkit-scrollbar {
                    width: 6px;
                    height: 6px;
                }
                .custom-table-scrollbar::-webkit-scrollbar-thumb {
                    background: rgba(156, 163, 175, 0.5);
                    border-radius: 9999px;
                }

                @media print {
                    @page { size: A4 portrait; margin: 5mm; }
                    body { background: white !important; color: black !important; font-family: "Times New Roman", Times, serif !important; font-size: 11pt !important; -webkit-print-color-adjust: exact; }
                    aside, nav, header, footer, .no-print { display: none !important; }
                    main, .py-6, .px-4, .max-w-7xl { padding: 0 !important; margin: 0 !important; max-width: none !important; width: 100% !important; box-shadow: none !important; border: none !important; background: white !important; }
                    .pdf-viewer-container { background: white !important; padding: 0 !important; box-shadow: none !important; }
                    .pdf-page {
                        box-shadow: none !important;
                        margin: 0 !important;
                        width: 100% !important;
                        padding: 4mm !important;
                        max-height: 285mm !important;
                        overflow: hidden !important;
                    }
                }
            `}</style>

            <div className="py-6 px-4 sm:px-6 lg:px-8 bg-gray-50 dark:bg-gray-900 min-h-screen">
                <div className="max-w-7xl mx-auto space-y-3">

                    <div className="no-print">
                    <PageHeader
                        title="Biodiversity Monitoring System (BMS)"
                        description="Comprehensive Species Database, Transect Observation Records, and Semestral Population Trends."
                        actions={
                            <span className="bg-white/10 backdrop-blur-md text-white border border-white/20 px-4 py-2 rounded-xl text-xs font-bold tracking-wider uppercase">
                                BMS Operations
                            </span>
                        }
                    />
                    </div>

                    {/* Navigation Tabs */}
                    <div className="no-print flex flex-col gap-2 border-b border-gray-200 pb-3 dark:border-gray-700 xl:flex-row xl:items-center xl:justify-between">
                        <div className="flex min-w-0 items-center gap-2 overflow-x-auto pb-1 xl:pb-0">
                        <button onClick={() => setActiveTab('list')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'list' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📄 Species Records
                        </button>
                        <button onClick={() => setActiveTab('semestral')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'semestral' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📊 Semestral Population Trends
                        </button>
                        <button onClick={() => setActiveTab('threats')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'threats' ? 'bg-red-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            ⚠️ Threats
                        </button>
                        <button onClick={() => setActiveTab('map')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'map' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            🗺️ Map View
                        </button>
                        <button onClick={() => setActiveTab('report-tracker')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'report-tracker' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📑 Report Submission Tracker
                        </button>
                        </div>
                        {(canCreateBms || canManageBmsSpatial) && <div className="flex shrink-0 items-center gap-2 overflow-x-auto xl:justify-end">
                        {canCreateBms && <button onClick={() => setActiveTab('import')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'import' ? 'bg-green-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            📁 Excel / CSV Bulk Import
                        </button>}
                        {canManageBmsSpatial && <button onClick={() => setActiveTab('geojson-import')} className={`shrink-0 whitespace-nowrap px-4 py-2.5 rounded-xl font-bold text-xs transition-all shadow-xs ${activeTab === 'geojson-import' ? 'bg-emerald-700 text-white shadow-md' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-200 dark:border-gray-700'}`}>
                            🗺️📁 Import GeoJSON Spatial File
                        </button>}
                        </div>}
                    </div>

                    {activeTab === 'report-tracker' && (
                        <ReportSubmissionTracker submissions={reportSubmissions} protectedAreas={protectedAreas} filters={reportFilters} />
                    )}

                    {/* TAB 1: SPECIES RECORDS VIEW */}
                    {activeTab === 'list' && (
                        <div className={viewMode === 'pdf' ? "w-full space-y-4 bg-white border-0 p-0 shadow-none" : "w-full space-y-4"}>
                            <div className="flex justify-between items-center bg-white dark:bg-gray-800 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm no-print">
                                <div className="flex items-center gap-2">
                                    <span className="text-xs font-bold text-gray-600 dark:text-gray-400 pl-1">📋 View Layout:</span>
                                    <div className="flex items-center gap-1 bg-gray-50 dark:bg-gray-900 p-1 rounded-xl border border-gray-200 dark:border-gray-700">
                                        <button onClick={() => setViewMode('table')} className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${viewMode === 'table' ? 'bg-green-700 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800'}`}>Species Database</button>
                                        <button onClick={() => setViewMode('pdf')} className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${viewMode === 'pdf' ? 'bg-green-700 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800'}`}>PDF Annex Simulator</button>
                                    </div>
                                </div>
                                {viewMode === 'pdf' && (
                                    <div className="flex items-center gap-2">
                                        {canUpdateBms && <button onClick={() => {
                                            const first = bmsRecords[0] || {};
                                            annexHeaderForm.setData({
                                                location: annexHeaderMetadata?.location || '',
                                                date_conducted: annexHeaderMetadata?.date_conducted || (first.monitoring_date ? first.monitoring_date.split('T')[0] : ''),
                                                start_end_time: annexHeaderMetadata?.start_end_time || '',
                                                length_of_transect: annexHeaderMetadata?.length_of_transect || '',
                                                start_gps: annexHeaderMetadata?.start_gps || '',
                                                end_gps: annexHeaderMetadata?.end_gps || '',
                                                weather_condition: annexHeaderMetadata?.weather_condition || '',
                                                elevation: annexHeaderMetadata?.elevation || '',
                                                ecosystem_type: annexHeaderMetadata?.ecosystem_type || '',
                                                species_observed: annexSpeciesObserved,
                                                observer: annexHeaderMetadata?.observer || '',
                                            });
                                            setShowEditHeaderModal(true);
                                        }} className="bg-amber-600 hover:bg-amber-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">✏️ Edit Header Details</button>}
                                        {canExportBms && <button onClick={exportAnnexToCSV} className="bg-blue-600 hover:bg-blue-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">📥 Export CSV</button>}
                                        {canExportBms && <button onClick={() => window.print()} className="bg-green-700 hover:bg-green-800 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">🖨️ Save as PDF / Print</button>}
                                    </div>
                                )}
                            </div>

                            <div className="bg-white dark:bg-gray-800 p-4 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col md:flex-row justify-between items-center gap-4 no-print">
                                <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
                                    <div className="w-52">
                                        <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Protected Area</label>
                                        <select value={filters?.protected_area_id || ''} onChange={(e) => { router.get(route('bms.index'), { ...filters, protected_area_id: e.target.value }, { preserveState: true, replace: true }); }} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-medium h-[38px]">
                                            <option value="">🌐 All Protected Areas</option>
                                            {protectedAreas.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                        </select>
                                    </div>
                                    <div className="w-36">
                                        <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Category</label>
                                        <select value={filters?.category || ''} onChange={(e) => { router.get(route('bms.index'), { ...filters, category: e.target.value }, { preserveState: true, replace: true }); }} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-medium h-[38px]">
                                            <option value="">🌿 All Categories</option>
                                            <option value="Flora">Flora</option>
                                            <option value="Fauna">Fauna</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Date Range</label>
                                        <div className="flex items-center gap-1">
                                            <input type="date" value={filters?.start_date || ''} onChange={(e) => { router.get(route('bms.index'), { ...filters, start_date: e.target.value }, { preserveState: true, replace: true }); }} className="w-32 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-medium h-[38px]" />
                                            <span className="text-gray-400 text-xs font-bold">to</span>
                                            <input type="date" value={filters?.end_date || ''} onChange={(e) => { router.get(route('bms.index'), { ...filters, end_date: e.target.value }, { preserveState: true, replace: true }); }} className="w-32 border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-medium h-[38px]" />
                                        </div>
                                    </div>
                                </div>
                                {viewMode === 'table' && canDeleteBms && (
                                    <div className="flex items-center gap-2 self-end md:self-auto">
                                        {!isSelectionMode ? (
                                            <button onClick={() => setIsSelectionMode(true)} className="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-600 px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-xs h-[38px] flex items-center">☑️ Enable Select to Delete</button>
                                        ) : (
                                            <div className="flex items-center gap-2">
                                                {selectedIds.length > 0 && (<button onClick={() => setShowBulkDeleteConfirm(true)} className="bg-red-600 hover:bg-red-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm animate-pop-in h-[38px] flex items-center">🗑️ Delete ({selectedIds.length})</button>)}
                                                <button onClick={() => { setIsSelectionMode(false); setSelectedIds([]); }} className="bg-gray-500 hover:bg-gray-600 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm h-[38px] flex items-center">✕ Cancel</button>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>

                            <div>
                                {viewMode === 'table' && (
                                    <CrudTable title="BMS Species Observations" subtitle={`${bmsRecords.length} record${bmsRecords.length === 1 ? '' : 's'} in the current filter`} helperText="Click any row to view complete details" headerActions={canCreateBms ? <button type="button" onClick={() => setActiveTab('add')} className="rounded-xl bg-green-700 px-5 py-2.5 text-xs font-bold text-white shadow-md transition hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-600 focus:ring-offset-2 dark:focus:ring-offset-gray-900">+ Add Field Observation</button> : null} columns={speciesColumns} rows={bmsRecords} onRowClick={openRecordDetails} isRowDisabled={() => isSelectionMode} selectable={isSelectionMode} selectedKeys={selectedIds} onSelectRow={(id, checked) => setSelectedIds(current => checked ? [...new Set([...current, id])] : current.filter(item => item !== id))} onSelectAll={(checked, rows) => setSelectedIds(checked ? rows.map(record => record.id) : [])} emptyTitle="No BMS species records found" emptyDescription="Add or import an observation to populate the species table." caption="BMS species observations" />
                                )}

                                {viewMode === 'pdf' && (
                                    <div className="pdf-viewer-container w-full bg-slate-900/90 py-8 px-4 flex flex-col items-center min-h-[85vh]">
                                        <div className="pdf-page w-full max-w-[210mm] bg-white text-black p-8 sm:p-12 space-y-4" style={{ fontFamily: '"Times New Roman", Times, serif', fontSize: '12pt' }}>
                                            <div className="flex items-center justify-between border-b-2 border-black pb-3">
                                                <div className="w-20 h-20 flex items-center justify-center shrink-0"><img src="/images/DENR LOGO.png" alt="DENR Logo" className="w-20 h-20 object-contain" /></div>
                                                <div className="text-center space-y-0.5 flex-1 px-2">
                                                    <p style={{ fontSize: '10pt' }} className="font-bold tracking-widest text-black">REPUBLIC OF THE PHILIPPINES</p>
                                                    <p style={{ fontSize: '11pt' }} className="font-extrabold text-blue-900 tracking-wide">Department of Environment and Natural Resources</p>
                                                    <p style={{ fontSize: '11pt' }} className="font-extrabold text-green-800 tracking-wide">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</p>
                                                    <p style={{ fontSize: '10pt' }} className="font-semibold text-gray-800">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
                                                    <p style={{ fontSize: '9pt' }} className="text-gray-700">TEL #: 3883-275 | EMAIL ADD: PENRODAVAOORIENTAL@DENR.GOV.PH</p>
                                                </div>
                                                <div className="w-20 h-20 flex items-center justify-center shrink-0"><img src="/images/Bagong Pilipinas logo.png" alt="Bagong Pilipinas Logo" className="w-20 h-20 object-contain" /></div>
                                            </div>
                                            <div className="text-center space-y-0.5 pt-1 pb-1">
                                                <h4 style={{ fontSize: '11pt' }} className="font-bold tracking-wider text-black uppercase">CONSERVATION AND DEVELOPMENT DIVISION</h4>
                                                <h2 style={{ fontSize: '12pt' }} className="font-extrabold uppercase tracking-wide text-black">{filters?.category === 'Fauna' ? 'ANNEX 1-A.2 – SUMMARY OF TRANSECT DATA' : (filters?.category === 'Flora' ? 'ANNEX 1-A.1 – SUMMARY OF TRANSECT DATA' : 'ANNEX 1-A.1 & 1-A.2 – SUMMARY OF TRANSECT DATA')}</h2>
                                            </div>
                                            <div style={{ fontSize: '12pt' }} className="leading-snug pt-1">
                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1">
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Location</span><span>:</span><span>{headerValue('location')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Date Conducted</span><span>:</span><span>{annexHeaderMetadata?.date_conducted || bmsRecords[0]?.monitoring_date || 'N/A'}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Start/End time</span><span>:</span><span>{headerValue('start_end_time')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Length of Transect</span><span>:</span><span>{headerValue('length_of_transect')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Start GPS Reading</span><span>:</span><span>{headerValue('start_gps')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">End GPS Reading</span><span>:</span><span>{headerValue('end_gps')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Weather Condition</span><span>:</span><span>{headerValue('weather_condition')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Elevation</span><span>:</span><span>{headerValue('elevation') !== 'N/A' ? `${headerValue('elevation')} MASL` : 'N/A'}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Ecosystem Type</span><span>:</span><span>{headerValue('ecosystem_type')}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1"><span className="font-bold text-black">Species Observed</span><span>:</span><span>{annexSpeciesObserved}</span></div>
                                                    <div className="grid grid-cols-[160px_10px_1fr] gap-1 md:col-span-2"><span className="font-bold text-black">Observer</span><span>:</span><span>{headerValue('observer')}</span></div>
                                                </div>
                                            </div>
                                            <div className="pt-2">
                                                <div className="w-full overflow-x-auto">
                                                    <table style={{ fontSize: '11pt' }} className="w-full text-center border-collapse border border-black table-fixed">
                                                        <colgroup><col style={{ width: '18%' }} /><col style={{ width: '17%' }} /><col style={{ width: '41%' }} /><col style={{ width: '12%' }} /><col style={{ width: '12%' }} /></colgroup>
                                                        <thead>
                                                            <tr className="bg-gray-100 font-bold" style={{ backgroundColor: '#f3f4f6' }}>
                                                                <th className="border border-black p-1.5 align-middle">Station / Meters</th>
                                                                <th className="border border-black p-1.5 align-middle">Time of Arrival</th>
                                                                <th className="border border-black p-1.5 align-middle">Species Observed<br/>(Local Name / Scientific Name)</th>
                                                                <th className="border border-black p-1.5 align-middle">Count</th>
                                                                <th className="border border-black p-1.5 align-middle">Mode</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {(() => {
                                                                const sorted = [...bmsRecords].sort((a, b) => {
                                                                    const getNum = (s) => { const m = (s || '').match(/\d+/); return m ? parseInt(m[0]) : 0; };
                                                                    return getNum(a.station) - getNum(b.station);
                                                                });
                                                                const counts = {};
                                                                sorted.forEach(r => { const st = r.station || '-'; counts[st] = (counts[st] || 0) + 1; });
                                                                let lastStation = null;
                                                                return sorted.map((record) => {
                                                                    const st = record.station || '-';
                                                                    const isFirst = st !== lastStation;
                                                                    if (isFirst) { lastStation = st; }
                                                                    const stationInfo = formatStationDisplay(st);
                                                                    return (
                                                                        <tr key={record.id}>
                                                                            {isFirst && (<td className="border border-black p-1.5 align-middle bg-white font-bold" rowSpan={counts[st]}><div style={{ fontSize: '11pt' }}>{stationInfo.label}</div>{stationInfo.meters && (<div style={{ fontSize: '9pt' }} className="font-normal text-gray-700 mt-0.5">({stationInfo.meters})</div>)}</td>)}
                                                                            <td className="border border-black p-1 align-middle">{formatTimeOfArrival(record.time)}</td>
                                                                            <td className="border border-black p-1 text-left px-2 break-words align-middle">{record.species_common_name ? record.species_common_name + ' ' : ''}{record.species_scientific_name && <span className="italic font-semibold">({record.species_scientific_name})</span>}</td>
                                                                            <td className="border border-black p-1 align-middle">{record.count || '-'}</td>
                                                                            <td className="border border-black p-1 align-middle">{record.mode_of_observation || 'Seen'}</td>
                                                                        </tr>
                                                                    );
                                                                });
                                                            })()}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* TAB 2: SEMESTRAL POPULATION MONITORING & TRENDS VIEW */}
                    {activeTab === 'semestral' && (
                        <div className={semestralViewMode === 'pdf' ? "w-full space-y-4 bg-white border-0 p-0 shadow-none" : "w-full space-y-4"}>
                            <div className="flex justify-between items-center bg-white dark:bg-gray-800 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm no-print">
                                <div className="flex items-center gap-2">
                                    <span className="text-xs font-bold text-gray-600 dark:text-gray-400 pl-1">📊 Trend Layout:</span>
                                    <div className="flex items-center gap-1 bg-gray-50 dark:bg-gray-900 p-1 rounded-xl border border-gray-200 dark:border-gray-700">
                                        <button onClick={() => setSemestralViewMode('table')} className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${semestralViewMode === 'table' ? 'bg-green-700 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800'}`}>Trend Table</button>
                                        <button onClick={() => setSemestralViewMode('graph')} className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${semestralViewMode === 'graph' ? 'bg-green-700 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800'}`}>Visual Graph</button>
                                        {canExportBms && <button onClick={() => setSemestralViewMode('pdf')} className={`px-4 py-1.5 rounded-lg text-xs font-bold transition ${semestralViewMode === 'pdf' ? 'bg-green-700 text-white shadow-xs' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-800'}`}>PDF Report Simulator</button>}
                                    </div>
                                </div>
                                {canExportBms && <div className="flex items-center gap-2">
                                    {semestralViewMode === 'pdf' ? (
                                        <>
                                            <button onClick={exportSemestralToCSV} className="bg-blue-600 hover:bg-blue-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">📥 Export CSV</button>
                                            <button onClick={() => window.print()} className="bg-green-700 hover:bg-green-800 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">🖨️ Save as PDF / Print</button>
                                        </>
                                    ) : (
                                        <button onClick={exportSemestralToCSV} className="bg-blue-600 hover:bg-blue-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center gap-1.5">📥 Export to Excel / CSV</button>
                                    )}
                                </div>}
                            </div>

                            <div className="bg-white dark:bg-gray-800 p-4 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-wrap items-center gap-4 no-print">
                                <div className="w-64">
                                    <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Protected Area Filter</label>
                                    <select value={semestralPaFilter} onChange={(e) => setSemestralPaFilter(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-bold h-[38px]">
                                        <option value="All">🌐 All Protected Areas</option>
                                        {protectedAreas.map((pa) => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                    </select>
                                </div>
                                <div className="w-48">
                                    <label className="block text-[10px] font-bold text-gray-500 uppercase mb-1">Year Filter</label>
                                    <select value={graphYearFilter} onChange={(e) => setGraphYearFilter(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-bold h-[38px]">
                                        <option value="All">📅 All Years</option>
                                        {getAvailableYears().map((yr) => (<option key={yr} value={yr}>{yr}</option>))}
                                    </select>
                                </div>
                            </div>

                            <div className={semestralViewMode !== 'pdf' ? "bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-6 border border-gray-100 dark:border-gray-700" : ""}>
                                {semestralViewMode === 'table' && (
                                    <div>
                                        <div className="mb-4">
                                            <h3 className="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">📊 Semestral Population Trend Analysis</h3>
                                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Evaluating population patterns across historical semesters to identify growth, decline, or stability.</p>
                                        </div>
                                        <div className="overflow-x-auto custom-table-scrollbar">
                                            <table className="w-full text-left border-collapse border border-gray-200 dark:border-gray-700 text-xs">
                                                <thead className="bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 uppercase font-bold">
                                                    <tr>
                                                        <th className="border border-gray-200 dark:border-gray-700 p-3">Species Name</th>
                                                        <th className="border border-gray-200 dark:border-gray-700 p-3">Station</th>
                                                        <th className="border border-gray-200 dark:border-gray-700 p-3 text-center">Historical Points</th>
                                                        <th className="border border-gray-200 dark:border-gray-700 p-3 text-center">Population Trend Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-800 text-gray-800 dark:text-gray-200">
                                                    {getSemestralAggregates().length > 0 ? (
                                                        getSemestralAggregates().map((item, idx) => {
                                                            const speciesKey = `${item.species}___${item.station}`;
                                                            const isAcknowledged = acknowledgedSpecies.includes(speciesKey);
                                                            return (
                                                                <tr key={idx} className="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                                    <td className="border border-gray-200 dark:border-gray-700 p-3">
                                                                        <div className="flex items-center gap-2 flex-wrap">
                                                                            <span className="italic font-bold text-gray-900 dark:text-white">{item.species}</span>
                                                                            {item.isNewSpecies && !isAcknowledged && canUpdateBms && (
                                                                                <div className="flex items-center gap-1.5">
                                                                                    <span className="bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300 px-2 py-0.5 rounded-lg text-[10px] font-extrabold uppercase animate-pulse">✨ New Species</span>
                                                                                    <button onClick={() => handleAcknowledge(speciesKey)} className="text-[10px] bg-gray-200 dark:bg-gray-700 hover:bg-green-600 hover:text-white px-2 py-0.5 rounded-lg font-semibold transition" title="Click to acknowledge and remove highlight">✓ Acknowledge</button>
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                        <div className="text-gray-500 text-[11px]">{item.common}</div>
                                                                    </td>
                                                                    <td className="border border-gray-200 dark:border-gray-700 p-3 font-bold text-green-700 dark:text-green-400">{item.station}</td>
                                                                    <td className="border border-gray-200 dark:border-gray-700 p-3 text-center">
                                                                        <div className="flex flex-wrap justify-center gap-1">
                                                                            {item.points.map((pt, pIdx) => (<span key={pIdx} className="bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded-lg text-[10px]"><strong>{pt.period}:</strong> {pt.count}</span>))}
                                                                        </div>
                                                                    </td>
                                                                    <td className="border border-gray-200 dark:border-gray-700 p-3 text-center font-bold">{item.trend.status}</td>
                                                                </tr>
                                                            );
                                                        })
                                                    ) : (<tr><td colSpan="4" className="text-center py-12 text-gray-500">No semestral aggregation data available for the selected filters.</td></tr>)}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}

                                {semestralViewMode === 'graph' && (
                                    <div>
                                        <div className="mb-4">
                                            <h3 className="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">📈 Species Population Trend Visual Graph</h3>
                                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Line chart showing population counts across monitored semesters ({graphYearFilter === 'All' ? 'All Years' : `Year ${graphYearFilter}`}).</p>
                                        </div>
                                        <div className="bg-gray-50 dark:bg-gray-900/50 p-4 rounded-2xl border border-gray-200 dark:border-gray-700">
                                            {getGraphChartData().length > 0 ? (
                                                <div className="h-[450px] w-full">
                                                    <ResponsiveContainer width="100%" height="100%">
                                                        <LineChart data={getGraphChartData()} margin={{ top: 20, right: 30, left: 10, bottom: 20 }}>
                                                            <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                                                            <XAxis dataKey="period" stroke="#9ca3af" tick={{ fontSize: 12 }} />
                                                            <YAxis stroke="#9ca3af" tick={{ fontSize: 12 }} />
                                                            <Tooltip contentStyle={{ backgroundColor: '#1f2937', color: '#fff', borderRadius: '12px', border: 'none', fontSize: '12px' }} />
                                                            <Legend wrapperStyle={{ fontSize: '12px', paddingTop: '10px' }} />
                                                            {getSemestralAggregates().map((item, index) => {
                                                                const colors = ['#16a34a', '#2563eb', '#d97706', '#dc2626', '#9333ea', '#0d9488', '#db2777'];
                                                                const color = colors[index % colors.length];
                                                                return (<Line key={index} type="monotone" dataKey={item.species} stroke={color} strokeWidth={2.5} dot={{ r: 4 }} activeDot={{ r: 6 }} />);
                                                            })}
                                                        </LineChart>
                                                    </ResponsiveContainer>
                                                </div>
                                            ) : (<div className="text-center py-20 text-gray-500">No graph data available for the selected filter.</div>)}
                                        </div>
                                    </div>
                                )}

                                {semestralViewMode === 'pdf' && (
                                    <div className="pdf-viewer-container w-full bg-slate-900/90 py-8 px-4 flex flex-col items-center min-h-[85vh]">
                                        <div className="pdf-page w-full max-w-[210mm] bg-white text-black p-8 sm:p-12 space-y-4" style={{ fontFamily: '"Times New Roman", Times, serif', fontSize: '12pt' }}>
                                            <div className="flex items-center justify-between border-b-2 border-black pb-3">
                                                <div className="w-20 h-20 flex items-center justify-center shrink-0"><img src="/images/DENR LOGO.png" alt="DENR Logo" className="w-20 h-20 object-contain" /></div>
                                                <div className="text-center space-y-0.5 flex-1 px-2">
                                                    <p style={{ fontSize: '10pt' }} className="font-bold tracking-widest text-black">REPUBLIC OF THE PHILIPPINES</p>
                                                    <p style={{ fontSize: '11pt' }} className="font-extrabold text-blue-900 tracking-wide">Department of Environment and Natural Resources</p>
                                                    <p style={{ fontSize: '11pt' }} className="font-extrabold text-green-800 tracking-wide">PROVINCIAL ENVIRONMENT AND NATURAL RESOURCES OFFICE</p>
                                                    <p style={{ fontSize: '10pt' }} className="font-semibold text-gray-800">GOVERNMENT CENTER, DAHICAN, CITY OF MATI</p>
                                                    <p style={{ fontSize: '9pt' }} className="text-gray-700">TEL #: 3883-275 | EMAIL ADD: PENRODAVAOORIENTAL@DENR.GOV.PH</p>
                                                </div>
                                                <div className="w-20 h-20 flex items-center justify-center shrink-0"><img src="/images/Bagong Pilipinas logo.png" alt="Bagong Pilipinas Logo" className="w-20 h-20 object-contain" /></div>
                                            </div>
                                            <div className="text-center space-y-0.5 pt-1 pb-1">
                                                <h4 style={{ fontSize: '11pt' }} className="font-bold tracking-wider text-black uppercase">CONSERVATION AND DEVELOPMENT DIVISION</h4>
                                                <h2 style={{ fontSize: '12pt' }} className="font-extrabold uppercase tracking-wide text-black">SEMESTRAL POPULATION MONITORING & TREND REPORT</h2>
                                            </div>
                                            <div className="pt-2">
                                                <div className="w-full overflow-x-auto">
                                                    <table style={{ fontSize: '11pt' }} className="w-full text-center border-collapse border border-black">
                                                        <thead>
                                                            <tr className="bg-gray-100 font-bold" style={{ backgroundColor: '#f3f4f6' }}>
                                                                <th className="border border-black p-2 text-left">Species Name (Scientific / Local)</th>
                                                                <th className="border border-black p-2">Station</th>
                                                                <th className="border border-black p-2">Historical Semesters Data</th>
                                                                <th className="border border-black p-2">Population Trend Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {getSemestralAggregates().length > 0 ? (
                                                                getSemestralAggregates().map((item, idx) => {
                                                                    const speciesKey = `${item.species}___${item.station}`;
                                                                    const isAcknowledged = acknowledgedSpecies.includes(speciesKey);
                                                                    return (
                                                                        <tr key={idx}>
                                                                            <td className="border border-black p-2 text-left"><span className="italic font-bold">{item.species}</span>{item.isNewSpecies && !isAcknowledged && (<span className="ml-2 text-[9pt] font-bold text-green-800">[New Species]</span>)}<div className="text-[10pt] text-gray-700">{item.common}</div></td>
                                                                            <td className="border border-black p-2 font-bold">{item.station}</td>
                                                                            <td className="border border-black p-2 text-[10pt]">{item.points.map(pt => `${pt.period}: ${pt.count}`).join(' | ')}</td>
                                                                            <td className="border border-black p-2 font-semibold">{item.trend.status}</td>
                                                                        </tr>
                                                                    );
                                                                })
                                                            ) : (<tr><td colSpan="4" className="border border-black p-4 text-center">No semestral data available for report generation.</td></tr>)}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* TAB 3: THREATS VIEW */}
                    {activeTab === 'threats' && <Threats threats={bmsThreats} protectedAreas={protectedAreas} />}

                    {/* TAB 4: MAP VIEW */}
                    {activeTab === 'map' && (
                        <MapView
                            bmsRecords={bmsRecords}
                            threatData={threatData}
                            spatialData={spatialData}
                            mapCategoryFilter={mapCategoryFilter}
                            setMapCategoryFilter={setMapCategoryFilter}
                            floraIcon={floraIcon}
                            faunaIcon={faunaIcon}
                            threatIcon={threatIcon}
                        />
                    )}

                    {/* TAB 5: ADD SINGLE FORM */}
                    {activeTab === 'add' && (
                        <CrudFormModal open mode="create" icon="🌿" title="Add Field Observation Data" subtitle="Create a BMS species observation from the Transect Data Summary." onClose={closeAddForm} onSubmit={submitRecord} processing={form.processing} errors={form.errors} saveLabel="Save Field Record" maxWidth="max-w-4xl">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 rounded-2xl border border-green-100 bg-green-50/50 p-4 dark:border-green-900 dark:bg-green-950/20">
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area *</label>
                                        <select value={form.data.protected_area_id} onChange={e => form.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required>
                                            <option value="">Select Protected Area</option>
                                            {protectedAreas.map(pa => <option key={pa.id} value={pa.id}>{pa.name}</option>)}
                                        </select>
                                        {fieldError(form.errors, 'protected_area_id')}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Monitoring Date *</label>
                                        <input type="date" value={form.data.monitoring_date} onChange={e => form.setData('monitoring_date', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required />
                                        {fieldError(form.errors, 'monitoring_date')}
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    <div className="md:col-span-4"><h4 className="font-bold text-green-700 dark:text-green-400 border-b border-gray-200 dark:border-gray-700 pb-2">🔍 Observation Details (Table Entry)</h4></div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Station</label>
                                        <input type="text" placeholder="e.g. 0, 1, 2 or 0-1" value={form.data.station} onChange={e => form.setData('station', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Time of Arrival</label>
                                        <input type="text" placeholder="e.g. 07:15 AM" value={form.data.time} onChange={e => form.setData('time', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Category</label>
                                        <select value={form.data.category} onChange={e => form.setData('category', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm">
                                            <option value="Flora">Flora</option>
                                            <option value="Fauna">Fauna</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Taxonomic Group</label>
                                        <input type="text" placeholder="e.g. Birds, Trees" value={form.data.taxonomic_group} onChange={e => form.setData('taxonomic_group', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required />
                                        {fieldError(form.errors, 'taxonomic_group')}
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                                    <div className="md:col-span-2">
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Scientific Name *</label>
                                        <input type="text" placeholder="e.g. Agathis philippinensis" value={form.data.species_scientific_name} onChange={e => form.setData('species_scientific_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm italic" required />
                                        {fieldError(form.errors, 'species_scientific_name')}
                                    </div>
                                    <div className="md:col-span-2">
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Local / Common Name</label>
                                        <input type="text" placeholder="e.g. Almaciga" value={form.data.species_common_name} onChange={e => form.setData('species_common_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" />
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Count / No. of Individuals *</label>
                                        <input type="text" placeholder="e.g. 1, 2, Dominant, Flock" value={form.data.count} onChange={e => form.setData('count', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required />
                                        {fieldError(form.errors, 'count')}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700 dark:text-gray-300 mb-1">Mode of Observation</label>
                                        <select value={form.data.mode_of_observation} onChange={e => form.setData('mode_of_observation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm">
                                            <option value="Seen">Seen</option>
                                            <option value="Heard">Heard</option>
                                            <option value="Seen/Heard">Seen/Heard</option>
                                        </select>
                                    </div>
                                </div>
                                <div className="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 space-y-4">
                                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                                        <label className="block text-sm font-bold text-gray-800 dark:text-gray-200">🌍 Coordinate Format Input</label>
                                        <select value={coordType} onChange={e => setCoordType(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-bold py-1.5 px-3">
                                            <option value="DD">Decimal Degrees (DD)</option>
                                            <option value="DMS">Degrees, Minutes, Seconds (DMS)</option>
                                            <option value="UTM">UTM Zone</option>
                                        </select>
                                    </div>
                                    {coordType === 'DD' && (
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div><label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Latitude</label><input type="text" value={form.data.latitude} onChange={e => form.setData('latitude', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                            <div><label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Longitude</label><input type="text" value={form.data.longitude} onChange={e => form.setData('longitude', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                        </div>
                                    )}
                                    {coordType === 'DMS' && (
                                        <div className="space-y-3 text-xs">
                                            <div>
                                                <span className="font-bold text-gray-700 dark:text-gray-300">Latitude (DMS):</span>
                                                <div className="grid grid-cols-4 gap-2 mt-1">
                                                    <input type="number" placeholder="Deg" value={latDeg} onChange={e => setLatDeg(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <input type="number" placeholder="Min" value={latMin} onChange={e => setLatMin(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <input type="number" placeholder="Sec" value={latSec} onChange={e => setLatSec(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <select value={latDir} onChange={e => setLatDir(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2 font-bold"><option value="N">N</option><option value="S">S</option></select>
                                                </div>
                                            </div>
                                            <div>
                                                <span className="font-bold text-gray-700 dark:text-gray-300">Longitude (DMS):</span>
                                                <div className="grid grid-cols-4 gap-2 mt-1">
                                                    <input type="number" placeholder="Deg" value={lonDeg} onChange={e => setLonDeg(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <input type="number" placeholder="Min" value={lonMin} onChange={e => setLonMin(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <input type="number" placeholder="Sec" value={lonSec} onChange={e => setLonSec(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                    <select value={lonDir} onChange={e => setLonDir(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2 font-bold"><option value="E">E</option><option value="W">W</option></select>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    {coordType === 'UTM' && (
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                            <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">UTM Zone</label><input type="text" placeholder="e.g. 51N" value={utmZone} onChange={e => setUtmZone(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2" /></div>
                                            <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">Easting (X)</label><input type="text" placeholder="e.g. 562300" value={easting} onChange={e => setEasting(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2 font-mono" /></div>
                                            <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">Northing (Y)</label><input type="text" placeholder="e.g. 769200" value={northing} onChange={e => setNorthing(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2 font-mono" /></div>
                                        </div>
                                    )}
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Elevation (masl)</label><input type="text" placeholder="e.g. 453 - 683" value={form.data.elevation} onChange={e => form.setData('elevation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                    <div><label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Remarks</label><input type="text" placeholder="Additional notes" value={form.data.remarks} onChange={e => form.setData('remarks', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Location</label><input type="text" value={form.data.location} onChange={e => form.setData('location', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                    <div><label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Observer</label><input type="text" value={form.data.observer_name} onChange={e => form.setData('observer_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                </div>
                        </CrudFormModal>
                    )}

                    {/* TAB 6: EXCEL IMPORT */}
                    {activeTab === 'import' && (
                        <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Bulk Import Transect Data</h3>
                            <p className="text-sm text-gray-500 mb-6">Upload a CSV or TXT file following the Annex summary template format (maximum 50 MB).</p>
                            <form onSubmit={submitImport} className="space-y-5">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area</label>
                                    <select value={importForm.data.protected_area_id} onChange={e => importForm.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required>
                                        <option value="">Select Protected Area</option>
                                        {protectedAreas.map(pa => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                    </select>
                                    {importForm.errors.protected_area_id && <p className="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{importForm.errors.protected_area_id}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">CSV / Excel File</label>
                                    <input type="file" accept=".csv,.txt,text/csv,text/plain" onChange={e => importForm.setData('file', e.target.files[0])} className="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 cursor-pointer border border-gray-200 dark:border-gray-700 rounded-xl" required />
                                    {importForm.errors.file && <p className="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{importForm.errors.file}</p>}
                                </div>
                                <button type="submit" disabled={importForm.processing} className="w-full bg-green-700 hover:bg-green-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">🚀 Upload and Process Data</button>
                            </form>
                        </div>
                    )}

                    {/* TAB 7: GEOJSON SPATIAL FILE IMPORT */}
                    {canManageBmsSpatial && activeTab === 'geojson-import' && (
                        <div className="max-w-2xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl p-8 border border-gray-100 dark:border-gray-700">
                            <h3 className="text-xl font-bold text-gray-900 dark:text-white mb-2">🗺️📁 Import Spatial Boundaries / Transects (GeoJSON)</h3>
                            <p className="text-sm text-gray-500 mb-6">Upload a `.geojson` or `.json` spatial file to render park boundaries, zones, or transect lines directly on the map.</p>
                            <form onSubmit={submitGeoJsonImport} className="space-y-5">
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area</label>
                                    <select value={geoJsonForm.data.protected_area_id} onChange={e => geoJsonForm.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required>
                                        <option value="">Select Protected Area</option>
                                        {protectedAreas.map(pa => (<option key={pa.id} value={pa.id}>{pa.name}</option>))}
                                    </select>
                                    {geoJsonForm.errors.protected_area_id && <p className="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{geoJsonForm.errors.protected_area_id}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">GeoJSON / JSON Spatial File</label>
                                    <input type="file" accept=".geojson, .json" onChange={e => geoJsonForm.setData('file', e.target.files[0])} className="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 cursor-pointer border border-gray-200 dark:border-gray-700 rounded-xl" required />
                                    {geoJsonForm.errors.file && <p className="mt-1 text-sm text-red-600 dark:text-red-400" role="alert">{geoJsonForm.errors.file}</p>}
                                </div>
                                <button type="submit" disabled={geoJsonForm.processing} className="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold py-3 px-4 rounded-xl transition shadow-sm">🚀 Upload and Map Spatial Boundaries</button>
                            </form>
                        </div>
                    )}
                </div>
            </div>

            {/* EDIT ANNEX HEADER MODAL */}
            {showEditHeaderModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/55 backdrop-blur-xs">
                    <div className="bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-2xl w-full shadow-2xl border border-gray-200 dark:border-gray-700 animate-pop-in max-h-[90vh] overflow-y-auto">
                        <div className="flex justify-between items-center mb-4 border-b border-gray-100 dark:border-gray-700 pb-3">
                            <div><h3 className="text-lg font-bold text-gray-900 dark:text-white">✏️ Edit Annex Header Details</h3><p className="text-xs text-gray-500">Update the summary metadata at the top of the Annex report.</p></div>
                            <button onClick={() => setShowEditHeaderModal(false)} className="text-gray-400 hover:text-gray-600 font-bold text-lg">✕</button>
                        </div>
                        <form onSubmit={submitHeaderEdit} className="space-y-4 text-sm">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Location</label><input type="text" value={annexHeaderForm.data.location} onChange={e => annexHeaderForm.setData('location', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Date Conducted</label><input type="date" value={annexHeaderForm.data.date_conducted} onChange={e => annexHeaderForm.setData('date_conducted', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Start / End Time</label><input type="text" value={annexHeaderForm.data.start_end_time} onChange={e => annexHeaderForm.setData('start_end_time', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" placeholder="e.g. 07:00 AM - 11:00 AM" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Length of Transect</label><input type="text" value={annexHeaderForm.data.length_of_transect} onChange={e => annexHeaderForm.setData('length_of_transect', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Start GPS Reading</label><input type="text" value={annexHeaderForm.data.start_gps} onChange={e => annexHeaderForm.setData('start_gps', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">End GPS Reading</label><input type="text" value={annexHeaderForm.data.end_gps} onChange={e => annexHeaderForm.setData('end_gps', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Weather Condition</label><input type="text" value={annexHeaderForm.data.weather_condition} onChange={e => annexHeaderForm.setData('weather_condition', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Elevation (MASL)</label><input type="text" value={annexHeaderForm.data.elevation} onChange={e => annexHeaderForm.setData('elevation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Ecosystem Type</label><input type="text" value={annexHeaderForm.data.ecosystem_type} onChange={e => annexHeaderForm.setData('ecosystem_type', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div>
                                    <label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Species Observed</label>
                                    <input
                                        type="text"
                                        value={annexSpeciesObserved}
                                        readOnly
                                        className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm bg-gray-100 dark:bg-gray-700 cursor-not-allowed"
                                    />
                                </div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Observer</label><input type="text" value={annexHeaderForm.data.observer} onChange={e => annexHeaderForm.setData('observer', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                            </div>
                            <div className="flex justify-end gap-2 pt-3 border-t border-gray-100 dark:border-gray-700">
                                <button type="button" onClick={() => setShowEditHeaderModal(false)} className="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-sm font-semibold hover:bg-gray-200 transition">Cancel</button>
                                <button type="submit" disabled={annexHeaderForm.processing} className="px-5 py-2 bg-green-700 hover:bg-green-800 text-white rounded-xl text-sm font-semibold shadow-sm transition">💾 Save Header Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            <CrudDetailsModal open={Boolean(editingRecord) && !isEditingRecord} icon="🌿" title="Species Observation Details" subtitle={editingRecord ? `${editingRecord.species_scientific_name} · ${editingRecord.protected_area?.name || 'Protected area unavailable'}` : ''} onClose={closeRecordModal} canEdit={canUpdateBms} canDelete={canDeleteBms} onEdit={beginEditingRecord} onDelete={() => setShowDeleteConfirm(true)} summary={editingRecord && <CrudSummaryGrid items={[{ label: 'Monitoring Date', value: editingRecord.monitoring_date || '—' }, { label: 'Category', value: editingRecord.category || '—' }, { label: 'Station', value: editingRecord.station || '—' }, { label: 'Count', value: editingRecord.count ?? '—' }]} />}>
                {editingRecord && <div className="space-y-4">
                    <CrudSection title="Species & Observation"><dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['Protected Area', editingRecord.protected_area?.name], ['Scientific Name', editingRecord.species_scientific_name], ['Common Name', editingRecord.species_common_name], ['Taxonomic Group', editingRecord.taxonomic_group], ['Time', editingRecord.time], ['Mode of Observation', editingRecord.mode_of_observation], ['Location', editingRecord.location]].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold text-gray-500">{label}</dt><dd className={`mt-1 font-semibold text-gray-900 dark:text-white ${label === 'Scientific Name' ? 'italic' : ''}`}>{value || '—'}</dd></div>)}</dl></CrudSection>
                    <CrudSection title="GPS Coordinates"><dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['Latitude', editingRecord.latitude], ['Longitude', editingRecord.longitude]].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold text-gray-500">{label}</dt><dd className="mt-1 font-semibold text-gray-900 dark:text-white">{value || '—'}</dd></div>)}</dl></CrudSection>
                    <CrudSection title="Additional Observation Notes"><dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">{[['Observer', editingRecord.observer_name], ['Elevation', editingRecord.elevation]].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold text-gray-500">{label}</dt><dd className="mt-1 font-semibold text-gray-900 dark:text-white">{value || '—'}</dd></div>)}</dl><p className="mt-4 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{editingRecord.remarks || 'No remarks.'}</p></CrudSection>
                </div>}
            </CrudDetailsModal>

            {/* SPECIES EDIT MODAL */}
            {editingRecord && isEditingRecord && (
                <CrudFormModal open mode="edit" icon="✏️" title="Edit Species Record" subtitle={editingRecord.species_scientific_name} onClose={() => { editForm.clearErrors(); setActionError(''); setIsEditingRecord(false); }} onSubmit={submitEdit} processing={editForm.processing} errors={editForm.errors} canDelete={canDeleteBms} onDelete={() => setShowDeleteConfirm(true)} maxWidth="max-w-4xl">
                            {actionError && <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300" role="alert">{actionError}</div>}
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 rounded-2xl border border-green-100 bg-green-50/50 p-4 dark:border-green-900 dark:bg-green-950/20">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Protected Area *</label><select value={editForm.data.protected_area_id} onChange={e => editForm.setData('protected_area_id', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required><option value="">Select Protected Area</option>{protectedAreas.map(pa => <option key={pa.id} value={pa.id}>{pa.name}</option>)}</select>{fieldError(editForm.errors, 'protected_area_id')}</div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Monitoring Date *</label><input type="date" value={editForm.data.monitoring_date} onChange={e => editForm.setData('monitoring_date', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required />{fieldError(editForm.errors, 'monitoring_date')}</div>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Station</label><input type="text" value={editForm.data.station} onChange={e => editForm.setData('station', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Time of Arrival</label><input type="text" value={editForm.data.time} onChange={e => editForm.setData('time', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div>
                                    <label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Category</label>
                                    <select value={editForm.data.category} onChange={e => editForm.setData('category', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm"><option value="Flora">Flora</option><option value="Fauna">Fauna</option></select>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Scientific Name *</label><input type="text" value={editForm.data.species_scientific_name} onChange={e => editForm.setData('species_scientific_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm italic" required />{fieldError(editForm.errors, 'species_scientific_name')}</div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Common Name</label><input type="text" value={editForm.data.species_common_name} onChange={e => editForm.setData('species_common_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Taxonomic Group</label><input type="text" value={editForm.data.taxonomic_group} onChange={e => editForm.setData('taxonomic_group', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required /></div>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Count / Abundance *</label><input type="text" value={editForm.data.count} onChange={e => editForm.setData('count', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" required />{fieldError(editForm.errors, 'count')}</div>
                                <div>
                                    <label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Mode of Observation</label>
                                    <select value={editForm.data.mode_of_observation} onChange={e => editForm.setData('mode_of_observation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm"><option value="Seen">Seen</option><option value="Heard">Heard</option><option value="Seen/Heard">Seen/Heard</option></select>
                                </div>
                            </div>
                            <div className="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-2xl border border-gray-200 dark:border-gray-700 space-y-4">
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
                                    <label className="block text-sm font-bold text-gray-800 dark:text-gray-200">🌍 Coordinate Format Input</label>
                                    <select value={editCoordType} onChange={e => setEditCoordType(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-bold py-1.5 px-3">
                                        <option value="DD">Decimal Degrees (DD)</option>
                                        <option value="DMS">Degrees, Minutes, Seconds (DMS)</option>
                                        <option value="UTM">UTM Zone</option>
                                    </select>
                                </div>
                                {editCoordType === 'DD' && (
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div><label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Latitude</label><input type="text" value={editForm.data.latitude} onChange={e => editForm.setData('latitude', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                        <div><label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Longitude</label><input type="text" value={editForm.data.longitude} onChange={e => editForm.setData('longitude', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm font-mono" /></div>
                                    </div>
                                )}
                                {editCoordType === 'DMS' && (
                                    <div className="space-y-3 text-xs">
                                        <div>
                                            <span className="font-bold text-gray-700 dark:text-gray-300">Latitude (DMS):</span>
                                            <div className="grid grid-cols-4 gap-2 mt-1">
                                                <input type="number" placeholder="Deg" value={editLatDeg} onChange={e => setEditLatDeg(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <input type="number" placeholder="Min" value={editLatMin} onChange={e => setEditLatMin(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <input type="number" placeholder="Sec" value={editLatSec} onChange={e => setEditLatSec(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <select value={editLatDir} onChange={e => setEditLatDir(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2 font-bold"><option value="N">N</option><option value="S">S</option></select>
                                            </div>
                                        </div>
                                        <div>
                                            <span className="font-bold text-gray-700 dark:text-gray-300">Longitude (DMS):</span>
                                            <div className="grid grid-cols-4 gap-2 mt-1">
                                                <input type="number" placeholder="Deg" value={editLonDeg} onChange={e => setEditLonDeg(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <input type="number" placeholder="Min" value={editLonMin} onChange={e => setEditLonMin(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <input type="number" placeholder="Sec" value={editLonSec} onChange={e => setEditLonSec(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2" />
                                                <select value={editLonDir} onChange={e => setEditLonDir(e.target.value)} className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl text-xs p-2 font-bold"><option value="E">E</option><option value="W">W</option></select>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                {editCoordType === 'UTM' && (
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                                        <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">UTM Zone</label><input type="text" placeholder="e.g. 51N" value={editUtmZone} onChange={e => setEditUtmZone(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2" /></div>
                                        <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">Easting (X)</label><input type="text" placeholder="e.g. 562300" value={editEasting} onChange={e => setEditEasting(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2 font-mono" /></div>
                                        <div><label className="block font-semibold text-gray-600 dark:text-gray-400 mb-1">Northing (Y)</label><input type="text" placeholder="e.g. 769200" value={editNorthing} onChange={e => setEditNorthing(e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl p-2 font-mono" /></div>
                                    </div>
                                )}
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Elevation (masl)</label><input type="text" value={editForm.data.elevation} onChange={e => editForm.setData('elevation', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Observer</label><input type="text" value={editForm.data.observer_name} onChange={e => editForm.setData('observer_name', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                                <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Location</label><input type="text" value={editForm.data.location} onChange={e => editForm.setData('location', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" /></div>
                            </div>
                            <div><label className="block font-bold text-gray-700 dark:text-gray-300 mb-1">Remarks</label><textarea rows="3" value={editForm.data.remarks} onChange={e => editForm.setData('remarks', e.target.value)} className="w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-sm" />{fieldError(editForm.errors, 'remarks')}</div>
                </CrudFormModal>
            )}

            <ConfirmDialog open={canDeleteBms && showBulkDeleteConfirm} variant="danger" title="Delete Selected Records?" message={`Delete ${selectedIds.length} selected record(s)? This cannot be undone.`} confirmLabel="Yes, Delete All" onConfirm={confirmBulkDelete} onCancel={() => setShowBulkDeleteConfirm(false)} processing={bulkDeleteProcessing} />
            <ConfirmDialog open={canDeleteBms && showDeleteConfirm} variant="danger" title="Delete Species Record?" message="This observation record will be deleted. This cannot be undone." confirmLabel="Yes, Delete" onConfirm={confirmDelete} onCancel={() => setShowDeleteConfirm(false)} processing={deleteProcessing} />

        </AuthenticatedLayout>
    );
}
