<?php

namespace App\Services\Compliance;

final class TargetOfficeNormalizer
{
    /** @return array{key:?string,label:?string} */
    public function normalize(?string $office): array
    {
        $label = trim((string) $office);
        if ($label === '') return ['key' => null, 'label' => null];

        $token = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
        $token = trim($token, '_');
        $aliases = [
            'cenro_baganga' => ['cenro_baganga', 'CENRO Baganga'], 'baganga_cenro' => ['cenro_baganga', 'CENRO Baganga'],
            'cenro_manay' => ['cenro_manay', 'CENRO Manay'], 'manay_cenro' => ['cenro_manay', 'CENRO Manay'],
            'cenro_mati' => ['cenro_mati', 'CENRO Mati'], 'mati_cenro' => ['cenro_mati', 'CENRO Mati'],
            'penro_davao_oriental' => ['penro_davao_oriental', 'PENRO Davao Oriental'], 'penro_mati' => ['penro_davao_oriental', 'PENRO Davao Oriental'],
        ];
        [$key, $canonical] = $aliases[$token] ?? [$token ?: null, $label];

        return ['key' => $key, 'label' => $canonical];
    }
}
