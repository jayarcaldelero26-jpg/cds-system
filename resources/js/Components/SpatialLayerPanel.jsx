import { router } from '@inertiajs/react';
import { useMap } from 'react-leaflet';
import L from 'leaflet';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Tooltip from '@/Components/Tooltip';

export default function SpatialLayerPanel({ layers = [], visibleLayers, onToggle, deleteRoute, onAdd, canDelete = false }) {
    const map = useMap();
    const [layerToDelete, setLayerToDelete] = useState(null);
    const zoomTo = layer => {
        const bounds = L.geoJSON(layer.geojson).getBounds();
        if (bounds?.isValid()) map.fitBounds(bounds, { padding: [50, 50] });
    };
    return <>
        <div className="absolute right-3 top-3 z-[500] w-72 max-w-[calc(100%-1.5rem)] rounded-2xl border border-gray-200 bg-white/95 p-3 shadow-xl backdrop-blur dark:border-gray-700 dark:bg-gray-900/95">
            <div className="mb-2 flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800"><h4 className="text-xs font-extrabold tracking-wider text-gray-800 dark:text-gray-100">MAP LAYERS</h4><span className="text-[10px] font-semibold text-gray-400">{layers.length}</span></div>
            <div className="max-h-64 space-y-1 overflow-y-auto">
                {layers.length === 0 && <p className="py-2 text-xs text-gray-500">No spatial layers uploaded yet.</p>}
                {layers.map(layer => <div key={layer.id} className="flex items-center gap-2 rounded-xl px-1 py-2 hover:bg-gray-50 dark:hover:bg-gray-800">
                    <Tooltip content={visibleLayers[layer.id] ? 'Hide layer' : 'Show layer'}><input type="checkbox" checked={visibleLayers[layer.id] !== false} onChange={() => onToggle(layer.id)} aria-label={`${visibleLayers[layer.id] === false ? 'Show' : 'Hide'} ${layer.name}`} className="rounded border-gray-300 text-green-600 focus:ring-green-500" /></Tooltip>
                    <div className="min-w-0 flex-1"><p className="truncate text-xs font-bold text-gray-800 dark:text-gray-100">{layer.name}</p><p className="text-[10px] text-gray-500">{layer.geometry_type || layer.layer_type || 'Spatial'} • {(layer.source_format || 'geojson').toUpperCase()}</p></div>
                    <Tooltip content="Zoom to layer"><button type="button" onClick={() => zoomTo(layer)} className="rounded-lg px-1.5 py-1 text-xs text-green-700 hover:bg-green-50 dark:text-green-400" aria-label={`Zoom to ${layer.name}`}>⌖</button></Tooltip>
                    {canDelete && <Tooltip content="Delete layer"><button type="button" onClick={() => setLayerToDelete(layer)} className="rounded-lg px-1.5 py-1 text-xs text-red-600 hover:bg-red-50" aria-label={`Delete ${layer.name}`}>×</button></Tooltip>}
                </div>)}
            </div>
            {onAdd && <Tooltip content="Add a new spatial layer"><button type="button" onClick={onAdd} className="mt-2 w-full rounded-xl bg-green-700 px-3 py-2 text-xs font-bold text-white hover:bg-green-800">＋ Add Spatial Layer</button></Tooltip>}
        </div>
        <ConfirmDialog open={Boolean(layerToDelete)} title="Delete spatial layer?" message={`This will remove “${layerToDelete?.name || ''}” from the map. Other layers will remain untouched.`} confirmLabel="Delete" variant="danger" onCancel={() => setLayerToDelete(null)} onConfirm={() => { const id = layerToDelete.id; setLayerToDelete(null); router.delete(route(deleteRoute, id), { preserveScroll: true }); }} />
    </>;
}
