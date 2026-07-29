import React, { useEffect, useRef } from 'react';
import { MapContainer, TileLayer, Marker, Popup, LayersControl, LayerGroup, GeoJSON, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

// Component para mo-zoom automatic sa boundary kung naay gi-load
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
    geoJsonData = null, // Giapil ang duha ka klase sa prop aron dili magkaproblema ang BMS ug BAMS
    mapCategoryFilter = 'All',
    setMapCategoryFilter = () => {},
    floraIcon,
    faunaIcon,
    threatIcon
}) {
    // Gamita kung unsa ang naay sulod tali sa spatialData o geoJsonData
    const rawSpatialInput = spatialData || geoJsonData;
    console.log("Spatial/GeoJSON Data Prop received in MapView:", rawSpatialInput);

    const safeRecords = Array.isArray(bmsRecords) ? bmsRecords : [];

    const validBmsRecords = safeRecords
        .filter(record => record && record.latitude && record.longitude)
        .filter(record => mapCategoryFilter === 'All' || record.category === mapCategoryFilter);

    const validThreats = Array.isArray(threatData) ? threatData.filter(t => t.latitude && t.longitude) : [];

    // --- SAFE PARSER & CONVERTER (Mosuporta sa Esri JSON ug Standard GeoJSON nga walay error) ---
    const getValidGeoJson = () => {
        try {
            if (!rawSpatialInput) return null;
            let obj = typeof rawSpatialInput === 'string' ? JSON.parse(rawSpatialInput) : rawSpatialInput;

            if (!obj || typeof obj !== 'object') return null;

            // 1. Kon Esri JSON format ang nadawat (ArcGIS / BAMS / Esri format)
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
                        geometry: {
                            type: geomType,
                            coordinates: coordinates
                        }
                    };
                }).filter(Boolean);

                if (convertedFeatures.length === 0) return null;

                return {
                    type: "FeatureCollection",
                    features: convertedFeatures
                };
            }

            // 2. Standard GeoJSON check
            const validTypes = [
                'FeatureCollection', 'Feature', 'Point',
                'MultiPoint', 'LineString', 'MultiLineString',
                'Polygon', 'MultiPolygon', 'GeometryCollection'
            ];

            if (!obj.type || !validTypes.includes(obj.type)) return null;
            if (obj.type === 'FeatureCollection' && !Array.isArray(obj.features)) return null;

            return obj;
        } catch (error) {
            console.error("Error parsing spatial data:", error);
            return null;
        }
    };

    const parsedSpatialData = getValidGeoJson();

    return (
        <div className="max-w-7xl mx-auto bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden p-6 border border-gray-100 dark:border-gray-700 w-full transition-all space-y-4">
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

                    {/* RENDER IMPORTED SPATIAL BOUNDARIES UG AUTO-ZOOM */}
                    {parsedSpatialData && (
                        <>
                            <GeoJSON
                                data={parsedSpatialData}
                                style={{
                                    color: '#047857',
                                    weight: 3.5,
                                    fillColor: '#10b981',
                                    fillOpacity: 0.35
                                }}
                                onEachFeature={(feature, layer) => {
                                    if (feature.properties) {
                                        let popupContent = '<div style="font-size: 12px;">';
                                        for (let key in feature.properties) {
                                            popupContent += `<b>${key}:</b> ${feature.properties[key]}<br>`;
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
                                <Popup>
                                    <div className="p-1">
                                        <span className={`px-2 py-0.5 rounded-lg text-[10px] font-bold ${record.category === 'Fauna' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800'}`}>
                                            {record.category || 'Flora'}
                                        </span>
                                        <p className="font-bold text-sm mt-1.5 italic text-green-800">{record.species_scientific_name || record.species_name}</p>
                                        <p className="text-xs text-gray-600"><strong>Common Name:</strong> {record.species_common_name || 'N/A'}</p>
                                        <p className="text-xs text-gray-600"><strong>Station:</strong> {record.station || record.plot_no || 'N/A'}</p>
                                        <p className="text-xs text-gray-600"><strong>Count:</strong> {record.count || 'N/A'}</p>
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
                                <Popup>
                                    <div className="p-1">
                                        <span className="px-2 py-0.5 rounded-lg text-[10px] font-bold bg-red-100 text-red-800">
                                            {threat.threatType}
                                        </span>
                                        <p className="font-bold text-sm mt-1.5 text-red-700">{threat.threatDetail}</p>
                                        <p className="text-xs text-gray-600"><strong>Location:</strong> {threat.location}</p>
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
