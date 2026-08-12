import React, { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Popup, LayersControl, LayerGroup, GeoJSON, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

function MapBoundsUpdater({ data }) {
    const map = useMap();

    useEffect(() => {
        if (data) {
            try {
                const geoJsonLayer = L.geoJSON(data);
                const bounds = geoJsonLayer.getBounds();
                if (bounds && bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            } catch (error) {
                console.error("Error setting map bounds:", error);
            }
        }
    }, [data, map]);

    return null;
}

export default function MapView({
    bmsRecords = [],
    threatData = [],
    spatialData = null,
    geoJsonData = null,
    mapCategoryFilter = 'All',
    setMapCategoryFilter = () => {},
    floraIcon,
    faunaIcon,
    threatIcon
}) {
    const rawSpatialInput = spatialData || geoJsonData;
    const safeRecords = Array.isArray(bmsRecords) ? bmsRecords : [];

    const validBmsRecords = safeRecords
        .filter(record => record && record.latitude && record.longitude)
        .filter(record => mapCategoryFilter === 'All' || record.category === mapCategoryFilter);

    const validThreats = Array.isArray(threatData) ? threatData.filter(t => t.latitude && t.longitude) : [];

    const getValidGeoJson = () => {
        try {
            if (!rawSpatialInput) return null;
            let obj = typeof rawSpatialInput === 'string' ? JSON.parse(rawSpatialInput) : rawSpatialInput;
            if (!obj || typeof obj !== 'object') return null;

            if (obj.geometryType && obj.features && Array.isArray(obj.features)) {
                const convertedFeatures = obj.features.map(f => {
                    let geomType = 'Polygon';
                    let coordinates = [];
                    if (f.geometry) {
                        if (f.geometry.rings && Array.isArray(f.geometry.rings)) {
                            geomType = 'Polygon';
                            coordinates = f.geometry.rings;
                        } else if (f.geometry.paths && Array.isArray(f.geometry.paths)) {
                            geomType = 'LineString';
                            coordinates = f.geometry.paths.length === 1 ? f.geometry.paths[0] : f.geometry.paths;
                        } else if (typeof f.geometry.x === 'number' && typeof f.geometry.y === 'number') {
                            geomType = 'Point';
                            coordinates = [f.geometry.x, f.geometry.y];
                        }
                    }
                    if (coordinates.length === 0) return null;
                    return {
                        type: "Feature",
                        properties: f.attributes || {},
                        geometry: { type: geomType, coordinates: coordinates }
                    };
                }).filter(Boolean);

                if (convertedFeatures.length === 0) return null;
                return { type: "FeatureCollection", features: convertedFeatures };
            }

            return obj;
        } catch (error) {
            console.error("Error parsing spatial data:", error);
            return null;
        }
    };

    const parsedSpatialData = getValidGeoJson();

    return (
        <div className="max-w-7xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden p-6 border border-gray-100 dark:border-gray-700 w-full transition-all space-y-4">
            <style>{`
                .leaflet-popup-content-wrapper, .leaflet-popup-tip {
                    background: transparent !important;
                    box-shadow: none !important;
                    padding: 0 !important;
                }
                .leaflet-popup-content {
                    margin: 0 !important;
                    line-height: inherit;
                }
                .leaflet-popup-close-button {
                    opacity: 0;
                    pointer-events: none;
                }
            `}</style>

            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-gray-100 dark:border-gray-700 pb-4">
                <div>
                    <h3 className="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">Geographic Distribution & Spatial Map</h3>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Visualizing species coordinates, threats, and spatial boundaries across protected areas.</p>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-xs font-bold text-gray-600 dark:text-gray-400">Filter Map:</span>
                    <select
                        value={mapCategoryFilter}
                        onChange={(e) => setMapCategoryFilter(e.target.value)}
                        className="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-white rounded-xl shadow-sm text-xs font-bold py-1.5 px-3 focus:ring-green-500 focus:border-green-500 cursor-pointer"
                    >
                        <option value="All">All Categories (Flora, Fauna & Threats)</option>
                        <option value="Flora">Flora Only 🌱</option>
                        <option value="Fauna">Fauna Only 🐾</option>
                        <option value="Threats">Threats Only ⚠️</option>
                    </select>
                </div>
            </div>

            <div className="h-[720px] w-full rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700 z-0 relative shadow-inner">
                <MapContainer
                    key={JSON.stringify(parsedSpatialData) || 'default-map-key'}
                    center={[6.9573, 126.1979]}
                    zoom={11}
                    scrollWheelZoom={true}
                    style={{ height: '100%', width: '100%' }}
                >
                    <LayersControl position="topright">
                        <LayersControl.BaseLayer checked name="Standard (OpenStreetMap)">
                            <TileLayer
                                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                            />
                        </LayersControl.BaseLayer>
                        <LayersControl.BaseLayer name="Satellite Imagery">
                            <TileLayer
                                attribution='Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
                                url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                            />
                        </LayersControl.BaseLayer>
                        <LayersControl.BaseLayer name="Hybrid (Satellite + Labels)">
                            <LayerGroup>
                                <TileLayer
                                    attribution='Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
                                    url="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}"
                                />
                                <TileLayer
                                    url="https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}"
                                />
                            </LayerGroup>
                        </LayersControl.BaseLayer>
                        <LayersControl.BaseLayer name="Topographic">
                            <TileLayer
                                attribution='Map data: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, SRTM | Map style: &copy; <a href="https://opentopomap.org">OpenTopoMap</a>'
                                url="https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png"
                            />
                        </LayersControl.BaseLayer>
                    </LayersControl>

                    {parsedSpatialData && (
                        <>
                            <GeoJSON
                                data={parsedSpatialData}
                                style={{ color: '#047857', weight: 3.5, fillColor: '#10b981', fillOpacity: 0.35 }}
                                onEachFeature={(feature, layer) => {
                                    if (feature.properties) {
                                        let popupContent = '<div style="font-family: inherit; min-width: 200px; padding: 4px;">';
                                        popupContent += '<div style="background: #065f46; color: white; padding: 6px 10px; border-radius: 6px 6px 0 0; font-weight: bold; font-size: 11px; margin: -6px -6px 8px -6px;">BOUNDARY INFORMATION</div>';
                                        for (let key in feature.properties) {
                                            popupContent += `<div style="margin-bottom: 4px; font-size: 11px;"><strong style="color: #4b5563; text-transform: uppercase; font-size: 9px; display: block;">${key}</strong> <span style="color: #111827; font-weight: 600;">${feature.properties[key]}</span></div>`;
                                        }
                                        popupContent += '</div>';
                                        layer.bindPopup(popupContent);
                                    }
                                }}
                            />
                            <MapBoundsUpdater data={parsedSpatialData} />
                        </>
                    )}

                    {/* Flora & Fauna Markers */}
                    {(mapCategoryFilter === 'All' || mapCategoryFilter === 'Flora' || mapCategoryFilter === 'Fauna') && validBmsRecords
                        .map((record) => (
                            <Marker
                                key={`bms-${record.id}`}
                                position={[parseFloat(record.latitude), parseFloat(record.longitude)]}
                                icon={record.category === 'Fauna' ? faunaIcon : floraIcon}
                            >
                                <Popup className="custom-id-popup">
                                    <div className="w-64 rounded-2xl overflow-hidden bg-white shadow-2xl border border-gray-100 text-gray-800 relative">
                                        <div className={`px-4 py-2.5 flex items-center justify-between text-white ${record.category === 'Fauna' ? 'bg-gradient-to-r from-amber-600 to-amber-800' : 'bg-gradient-to-r from-emerald-600 to-teal-800'}`}>
                                            <span className="text-[10px] font-extrabold tracking-wider uppercase opacity-95">
                                                {record.category === 'Fauna' ? '🐾 FAUNA ID CARD' : '🌱 FLORA ID CARD'}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <span className="text-[10px] bg-white/20 px-2 py-0.5 rounded-md font-mono font-bold">ID #{record.id}</span>
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        const closeBtn = e.target.closest('.leaflet-popup')?.querySelector('.leaflet-popup-close-button');
                                                        if (closeBtn) closeBtn.click();
                                                    }}
                                                    className="text-white/90 hover:text-white font-bold text-sm bg-black/20 hover:bg-black/40 w-5 h-5 rounded-full flex items-center justify-center transition cursor-pointer"
                                                    title="Close"
                                                >
                                                    ×
                                                </button>
                                            </div>
                                        </div>

                                        <div className="p-4 space-y-3">
                                            <div>
                                                <h4 className="font-extrabold text-sm italic text-gray-900 leading-tight">
                                                    {record.species_scientific_name || record.species_name}
                                                </h4>
                                                <p className="text-xs font-semibold text-emerald-700 mt-0.5">
                                                    Common Name: <span className="font-normal text-gray-600">{record.species_common_name || 'N/A'}</span>
                                                </p>
                                            </div>

                                            <div className="bg-gray-50 rounded-xl p-2.5 space-y-1.5 border border-gray-100 text-xs">
                                                <div className="flex justify-between">
                                                    <span className="text-gray-400 font-medium uppercase text-[10px]">Station:</span>
                                                    <span className="font-bold text-gray-800">{record.station || record.plot_no || 'N/A'}</span>
                                                </div>
                                                <div className="flex justify-between">
                                                    <span className="text-gray-400 font-medium uppercase text-[10px]">Count / Qty:</span>
                                                    <span className="font-bold text-gray-800">{record.count || 'N/A'}</span>
                                                </div>
                                            </div>

                                            <div className="pt-1 flex items-center justify-between text-[10px] text-gray-400 border-t border-gray-100">
                                                <span>PENRO Davao Oriental</span>
                                                <span className="font-mono font-semibold text-emerald-600">Verified Record</span>
                                            </div>
                                        </div>
                                    </div>
                                </Popup>
                            </Marker>
                        ))
                    }

                    {/* Threats Markers */}
                    {(mapCategoryFilter === 'All' || mapCategoryFilter === 'Threats') && validThreats
                        .map((threat) => (
                            <Marker
                                key={`threat-${threat.id}`}
                                position={[parseFloat(threat.latitude), parseFloat(threat.longitude)]}
                                icon={threatIcon}
                            >
                                <Popup className="custom-id-popup">
                                    <div className="w-64 rounded-2xl overflow-hidden bg-white shadow-2xl border border-gray-100 text-gray-800 relative">
                                        <div className="px-4 py-2.5 flex items-center justify-between text-white bg-gradient-to-r from-rose-600 to-red-800">
                                            <span className="text-[10px] font-extrabold tracking-wider uppercase opacity-95">⚠️ THREAT ALERT CARD</span>
                                            <div className="flex items-center gap-2">
                                                <span className="text-[10px] bg-white/20 px-2 py-0.5 rounded-md font-mono font-bold">ID #{threat.id}</span>
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        const closeBtn = e.target.closest('.leaflet-popup')?.querySelector('.leaflet-popup-close-button');
                                                        if (closeBtn) closeBtn.click();
                                                    }}
                                                    className="text-white/90 hover:text-white font-bold text-sm bg-black/20 hover:bg-black/40 w-5 h-5 rounded-full flex items-center justify-center transition cursor-pointer"
                                                    title="Close"
                                                >
                                                    ×
                                                </button>
                                            </div>
                                        </div>

                                        <div className="p-4 space-y-3">
                                            <div>
                                                <span className="inline-block px-2 py-0.5 rounded-md text-[10px] font-extrabold bg-rose-100 text-rose-800 uppercase mb-1">
                                                    {threat.threatType || 'Threat'}
                                                </span>
                                                <h4 className="font-extrabold text-sm text-gray-900 leading-snug">
                                                    {threat.threatDetail || 'System Threat'}
                                                </h4>
                                            </div>

                                            <div className="bg-gray-50 rounded-xl p-2.5 space-y-1.5 border border-gray-100 text-xs">
                                                <div className="flex justify-between">
                                                    <span className="text-gray-400 font-medium uppercase text-[10px]">Location:</span>
                                                    <span className="font-bold text-gray-800">{threat.location || 'N/A'}</span>
                                                </div>
                                            </div>

                                            <div className="pt-1 flex items-center justify-between text-[10px] text-gray-400 border-t border-gray-100">
                                                <span>MES Section</span>
                                                <span className="font-mono text-rose-600 font-bold">Action Required</span>
                                            </div>
                                        </div>
                                    </div>
                                </Popup>
                            </Marker>
                        ))
                    }
                </MapContainer>
            </div>
        </div>
    );
}
