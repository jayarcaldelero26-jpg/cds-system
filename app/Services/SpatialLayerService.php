<?php

namespace App\Services;

use App\Models\SpatialLayer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SpatialLayerService
{
    private const GEOMETRY_TYPES = [
        'Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon',
    ];

    public function create(array $attributes, int $userId): SpatialLayer
    {
        $geoJson = $this->decodeAndNormalize($attributes['geojson']);
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            $name = pathinfo((string) ($attributes['original_filename'] ?? ''), PATHINFO_FILENAME) ?: 'Untitled spatial layer';
        }

        return DB::transaction(fn (): SpatialLayer => SpatialLayer::create([
            'protected_area_id' => $attributes['protected_area_id'],
            'name' => mb_substr($name, 0, 255),
            'layer_type' => $attributes['layer_type'] ?? $this->geometryType($geoJson),
            'source_format' => $attributes['source_format'] ?? 'geojson',
            'geojson' => $geoJson,
            'original_filename' => $attributes['original_filename'] ?? null,
            'geometry_type' => $this->geometryType($geoJson),
            'created_by' => $userId,
        ]));
    }

    public function decodeAndNormalize(string|array $value): array
    {
        $decoded = is_array($value) ? $value : json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw ValidationException::withMessages(['spatial_file' => 'The normalized spatial data is not valid JSON.']);
        }

        if (($decoded['type'] ?? null) === 'FeatureCollection') {
            $features = $decoded['features'] ?? null;
            if (! is_array($features) || $features === []) {
                throw ValidationException::withMessages(['spatial_file' => 'The spatial layer must contain at least one feature.']);
            }
            foreach ($features as $feature) {
                $this->validateFeature($feature);
            }
            return ['type' => 'FeatureCollection', 'features' => array_values($features)];
        }

        if (($decoded['type'] ?? null) === 'Feature') {
            $this->validateFeature($decoded);
            return ['type' => 'FeatureCollection', 'features' => [$decoded]];
        }

        $this->validateGeometry($decoded);
        return ['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature', 'properties' => [], 'geometry' => $decoded,
        ]]];
    }

    public function validateShapefileZip(UploadedFile $file): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'zip' || ! class_exists('ZipArchive')) {
            throw ValidationException::withMessages(['spatial_file' => 'Shapefiles must be uploaded as a ZIP archive.']);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['spatial_file' => 'The uploaded Shapefile ZIP is malformed or cannot be opened.']);
        }

        $extensions = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strtolower($zip->getNameIndex($i) ?: '');
            $extensions[pathinfo($name, PATHINFO_EXTENSION)] = true;
        }
        $zip->close();

        foreach (['shp', 'shx', 'dbf'] as $required) {
            if (! isset($extensions[$required])) {
                throw ValidationException::withMessages(['spatial_file' => "The Shapefile ZIP is missing the required .{$required} component."]);
            }
        }
        if (! isset($extensions['prj'])) {
            throw ValidationException::withMessages(['spatial_file' => 'The Shapefile ZIP must include a .prj projection file so coordinates can be safely transformed.']);
        }
    }

    private function validateFeature(mixed $feature): void
    {
        if (! is_array($feature) || ($feature['type'] ?? null) !== 'Feature') {
            throw ValidationException::withMessages(['spatial_file' => 'Every spatial feature must be a valid GeoJSON Feature.']);
        }
        $this->validateGeometry($feature['geometry'] ?? null);
    }

    private function validateGeometry(mixed $geometry): void
    {
        if (is_array($geometry) && ($geometry['type'] ?? null) === 'GeometryCollection' && is_array($geometry['geometries'] ?? null)) {
            foreach ($geometry['geometries'] as $child) {
                $this->validateGeometry($child);
            }
            return;
        }
        if (! is_array($geometry) || ! in_array($geometry['type'] ?? null, self::GEOMETRY_TYPES, true) || ! is_array($geometry['coordinates'] ?? null) || $geometry['coordinates'] === []) {
            throw ValidationException::withMessages(['spatial_file' => 'Every spatial feature must have a supported, non-empty WGS84 geometry.']);
        }
        if (! $this->coordinatesAreWgs84($geometry['coordinates'])) {
            throw ValidationException::withMessages(['spatial_file' => 'Spatial coordinates must be longitude/latitude in WGS84 (EPSG:4326).']);
        }
    }

    private function coordinatesAreWgs84(array $coordinates): bool
    {
        if (isset($coordinates[0], $coordinates[1]) && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
            return (float) $coordinates[0] >= -180 && (float) $coordinates[0] <= 180
                && (float) $coordinates[1] >= -90 && (float) $coordinates[1] <= 90;
        }
        return $coordinates !== [] && collect($coordinates)->every(fn ($nested) => is_array($nested) && $this->coordinatesAreWgs84($nested));
    }

    private function geometryType(array $geoJson): ?string
    {
        $types = collect($geoJson['features'] ?? [])->map(fn (array $feature) => $feature['geometry']['type'] ?? null)->filter()->unique()->values();
        return $types->count() === 1 ? $types->first() : ($types->isEmpty() ? null : 'Mixed');
    }
}
