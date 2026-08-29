<?php

namespace App\Services\SubmissionTracking;

use App\Models\ProtectedArea;
use Illuminate\Database\Eloquent\Model;

/** Centralized routing-origin rules for protected-area submissions. */
final class ProtectedAreaRoutingPolicy
{
    private const DIRECT_PENRO_NAME = 'Mt. Hamiguitan Range Wildlife Sanctuary';

    private const DIRECT_PENRO_CANONICAL_NAMES = [
        self::DIRECT_PENRO_NAME,
        'Mt. Hamiguitan Range Wildlife Sanctuary (MHRWS)',
    ];

    private const DIRECT_PENRO_ALIASES = [
        'mhrws',
        'mt hamiguitan rws',
        'mt hamiguitan range wildlife sanctuary',
        'hamiguitan',
    ];

    private bool $resolved = false;

    private ?int $directPenroProtectedAreaId = null;

    public function isDirectPenro(Model $record): bool
    {
        $protectedAreaId = $record->getAttribute('protected_area_id');
        if (! $protectedAreaId) {
            return false;
        }

        $this->resolveCanonicalId();

        if ($this->directPenroProtectedAreaId !== null && (int) $protectedAreaId === $this->directPenroProtectedAreaId) {
            return true;
        }

        $area = $record->relationLoaded('protectedArea') ? $record->getRelation('protectedArea') : null;
        $normalizedName = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim((string) $area?->name)));
        $normalizedShortName = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim((string) $area?->short_name)));

        return ($normalizedName !== '' && in_array($normalizedName, self::DIRECT_PENRO_ALIASES, true))
            || ($normalizedShortName !== '' && in_array($normalizedShortName, self::DIRECT_PENRO_ALIASES, true));
    }

    public function label(): string
    {
        return self::DIRECT_PENRO_NAME;
    }

    private function resolveCanonicalId(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->directPenroProtectedAreaId = ProtectedArea::query()
            ->where(function ($query): void {
                $query->whereIn('name', self::DIRECT_PENRO_CANONICAL_NAMES)
                    ->orWhereIn('short_name', ['MHRWS']);
            })
            ->value('id');
        $this->resolved = true;
    }
}
