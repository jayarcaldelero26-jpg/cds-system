import JSZip from 'jszip';
import { parseZip } from 'shpjs';
import proj4 from 'proj4';

const geometryTypes = new Set(['Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon', 'GeometryCollection']);

// ArcGIS commonly writes WGS84 as ESRI WKT (for example,
// GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",...]) rather than EPSG:4326.
// Only classify it as WGS84 when the WKT root is geographic; this avoids
// treating projected WGS84 systems such as WGS84 / UTM as unprojected data.
export function isWgs84GeographicPrj(prjText) {
    const normalized = String(prjText || '').replace(/\s+/g, ' ').trim();
    const isGeographicWkt = /^(?:GEOGCS|GEOGCRS)\s*\[/i.test(normalized);
    const hasWgs84Identifier = /(?:GCS[_ ]WGS[_ ]1984|D[_ ]WGS[_ ]1984|WGS[_ ]1984|WGS[ _-]?84|EPSG\s*:\s*4326)/i.test(normalized);

    return /EPSG\s*:\s*4326/i.test(normalized) || (isGeographicWkt && hasWgs84Identifier);
}

function asFeatureCollection(value) {
    if (Array.isArray(value)) {
        const features = value.flatMap(item => item?.features || []);
        return { type: 'FeatureCollection', features };
    }
    if (value?.type === 'FeatureCollection') return value;
    if (value?.type === 'Feature') return { type: 'FeatureCollection', features: [value] };
    if (geometryTypes.has(value?.type)) return { type: 'FeatureCollection', features: [{ type: 'Feature', properties: {}, geometry: value }] };
    // Keep compatibility with the Esri JSON files accepted by the old map.
    if (Array.isArray(value?.features)) {
        const features = value.features.map(feature => {
            const geometry = feature.geometry || {};
            if (Array.isArray(geometry.rings)) return { type: 'Feature', properties: feature.attributes || {}, geometry: { type: 'Polygon', coordinates: geometry.rings } };
            if (Array.isArray(geometry.paths)) return { type: 'Feature', properties: feature.attributes || {}, geometry: { type: geometry.paths.length === 1 ? 'LineString' : 'MultiLineString', coordinates: geometry.paths.length === 1 ? geometry.paths[0] : geometry.paths } };
            if (typeof geometry.x === 'number' && typeof geometry.y === 'number') return { type: 'Feature', properties: feature.attributes || {}, geometry: { type: 'Point', coordinates: [geometry.x, geometry.y] } };
            return null;
        }).filter(Boolean);
        return { type: 'FeatureCollection', features };
    }
    throw new Error('The file does not contain supported GeoJSON geometry.');
}

export async function normalizeSpatialFile(file) {
    const originalFilename = file?.name || 'spatial-layer';
    if (!file) throw new Error('Choose a GeoJSON file or a Shapefile ZIP archive.');

    if (file.name.toLowerCase().endsWith('.zip')) {
        const zip = await JSZip.loadAsync(await file.arrayBuffer());
        const requiredExtensions = new Set(['shp', 'shx', 'dbf', 'prj']);
        const datasets = new Map();
        Object.values(zip.files).filter(entry => !entry.dir).forEach(entry => {
            const filename = entry.name.replace(/\\/g, '/').split('/').pop();
            const extensionMatch = filename.match(/\.([^.]+)$/i);
            if (!extensionMatch) return;

            const extension = extensionMatch[1].toLowerCase();
            // Anchored final-extension matching deliberately ignores .shp.xml.
            if (!requiredExtensions.has(extension)) return;

            const basename = filename.slice(0, -(extension.length + 1)).trim().toLowerCase();
            const dataset = datasets.get(basename) || {};
            if (dataset[extension]) throw new Error(`The Shapefile ZIP contains duplicate .${extension} files for "${basename}".`);
            dataset[extension] = entry;
            datasets.set(basename, dataset);
        });

        if (!datasets.size) throw new Error('The Shapefile ZIP does not contain a valid dataset.');
        for (const [basename, dataset] of datasets) {
            for (const required of requiredExtensions) {
                if (!dataset[required]) throw new Error(`The Shapefile dataset "${basename}" is missing the required .${required} component.`);
            }
            // Use the resolved JSZip entry. Do not reconstruct its original path
            // or case, which can make zip.file(...) return null.
            const prjText = await dataset.prj.async('text');
            if (!isWgs84GeographicPrj(prjText)) {
                try { proj4(prjText); } catch { throw new Error('The Shapefile .prj projection is unsupported.'); }
            }
        }
        let parsed;
        try { parsed = await parseZip(await file.arrayBuffer()); } catch (error) { throw new Error(`The Shapefile could not be parsed or its projection is unsupported: ${error.message}`); }
        const geojson = asFeatureCollection(parsed);
        if (!geojson.features.length) throw new Error('The Shapefile contains no features.');
        return { geojson, source_format: 'shapefile', original_filename: originalFilename };
    }

    let parsed;
    try { parsed = JSON.parse(await file.text()); } catch { throw new Error('The uploaded file is not valid JSON.'); }
    const geojson = asFeatureCollection(parsed);
    if (!geojson.features.length) throw new Error('The GeoJSON contains no features.');
    return { geojson, source_format: 'geojson', original_filename: originalFilename };
}
