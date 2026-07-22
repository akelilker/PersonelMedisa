<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Multi-reason combined-code validation without seeded matrix.
 */
final class SgkCokluNedenValidator
{
    /**
     * @param array{
     *   kodlar?: list<string>,
     *   kurallar?: list<array{kaynak_kodlar?: list<string>, sonuc_eksik_gun_kodu?: string, aktif_mi?: bool}>
     * } $input
     */
    public static function validate(array $input): array
    {
        $normalized = SgkKatalogContracts::normalizeKodSet($input['kodlar'] ?? []);
        $setHash = SgkKatalogContracts::kodSetHash($normalized);
        $kurallar = array_values($input['kurallar'] ?? []);
        $blockers = [];

        $hits = [];
        foreach ($kurallar as $kural) {
            if (isset($kural['aktif_mi']) && !$kural['aktif_mi']) {
                continue;
            }
            $kaynak = SgkKatalogContracts::normalizeKodSet($kural['kaynak_kodlar'] ?? []);
            if ($kaynak === $normalized) {
                $hits[] = (string) ($kural['sonuc_eksik_gun_kodu'] ?? '');
            }
        }
        $hits = array_values(array_filter($hits, static fn ($c) => $c !== ''));

        if ($normalized === []) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_COKLU_BULUNAMADI,
                'Coklu neden kod seti bos.',
                'En az bir eksik gun kodu gonderin.'
            );
        } elseif ($hits === []) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_COKLU_BULUNAMADI,
                'Birlesik kod kurali bulunamadi.',
                'Resmi birlesik neden matrisini onayli katalog ile ekleyin; ilk kodu secmeyin.'
            );
        } elseif (count(array_unique($hits)) > 1) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_COKLU_CAKISTI,
                'Birden fazla birlesik kod sonucu cakisti.',
                'Cakisan kurallari tek resmi sonuca indirin.'
            );
        }

        // Without seeded matrix, even a single hit is informational only in C1 if kurallar empty by default.
        $sonuc = count(array_unique($hits)) === 1 ? $hits[0] : null;

        $out = [
            'kodlar_normalize' => $normalized,
            'kaynak_kod_set_hash' => $setHash,
            'sonuc_eksik_gun_kodu' => $sonuc,
            'kural_sayisi' => count($kurallar),
            'seed_matrisi_var_mi' => $kurallar !== [],
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
            'gecerli_mi' => $blockers === [] && $sonuc !== null,
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }
}
