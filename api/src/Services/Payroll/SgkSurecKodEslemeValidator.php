<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Process→SGK code mapping validation without seeded rows.
 */
final class SgkSurecKodEslemeValidator
{
    /**
     * @param array{
     *   surec_turu?: string,
     *   alt_tur?: string|null,
     *   canonical_surec_turu?: string|null,
     *   donem?: string|null,
     *   mappings?: list<array<string,mixed>>,
     *   manifests?: list<array<string,mixed>>
     * } $input
     */
    public static function validate(array $input): array
    {
        $surec = trim((string) ($input['surec_turu'] ?? ''));
        $alt = trim((string) ($input['alt_tur'] ?? '*'));
        if ($alt === '') {
            $alt = '*';
        }
        $canonical = strtoupper(trim((string) ($input['canonical_surec_turu'] ?? '')));
        $mappings = array_values($input['mappings'] ?? []);
        $blockers = [];

        if ($canonical !== '' && !in_array($canonical, SgkKatalogContracts::CANONICAL_SUREC_TURLERI, true)) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_SUREC_BULUNAMADI,
                'Bilinmeyen canonical surec turu.',
                'Canonical tur listesinden secin.'
            );
        }

        $exact = [];
        $wildcard = [];
        foreach ($mappings as $m) {
            $st = (string) ($m['surec_turu'] ?? '');
            $at = (string) ($m['alt_tur'] ?? '*');
            if ($st !== $surec) {
                continue;
            }
            if ($at === $alt) {
                $exact[] = $m;
            } elseif ($at === '*') {
                $wildcard[] = $m;
            }
        }

        $matches = $exact !== [] ? $exact : $wildcard;

        if ($mappings === [] || $matches === []) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_SUREC_BULUNAMADI,
                'Surec→SGK kod eslemesi bulunamadi.',
                'Resmi katalog onayindan sonra canonical esleme satirlarini ekleyin; tahmin etmeyin.'
            );
        } elseif (count($matches) > 1) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_SUREC_CAKISTI,
                'Birden fazla surec→kod eslemesi cakisti.',
                'Esleme satirlarini tekil ve tarih etkili hale getirin.'
            );
        } else {
            $hit = $matches[0];
            if (empty($hit['kaynak_manifest_id']) && empty($hit['kaynak_manifest_kodu'])) {
                $blockers[] = SgkKatalogContracts::blocker(
                    SgkKatalogContracts::BLOCKER_SUREC_KAYNAK,
                    'Esleme kaynak manifesti eksik.',
                    'Her eslemeye resmi kaynak manifest baglayin.'
                );
            }
        }

        $out = [
            'surec_turu' => $surec,
            'alt_tur' => $alt,
            'canonical_surec_turu' => $canonical !== '' ? $canonical : null,
            'esleme_sayisi' => count($matches),
            'esleme_modu' => $exact !== [] ? 'EXACT' : ($wildcard !== [] ? 'WILDCARD' : 'YOK'),
            'seed_var_mi' => false,
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
            'gecerli_mi' => $blockers === [],
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }
}
