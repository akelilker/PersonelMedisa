<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Deterministic catalog import dry-run validator (no write).
 */
final class SgkKatalogImportValidator
{
    private const REQUIRED = [
        'katalog_surumu',
        'eksik_gun_kodu',
        'resmi_aciklama',
        'gecerlilik_baslangic',
        'kaynak_manifest_id',
        'belge_zorunlulugu',
        'sifir_gun_sifir_kazanc_kullanilabilir_mi',
        'kismi_sureli_sozlesme_gerekli_mi',
        'tek_basina_kullanilabilir_mi',
        'diger_nedenlerle_birlikte_kullanim',
        'aktif_mi',
        'aciklama_hash',
    ];

    /**
     * @param array{
     *   format?: 'JSON'|'CSV',
     *   rows?: list<array<string,mixed>>,
     *   manifests?: list<array<string,mixed>>,
     *   tamlik?: array<string,mixed>|null
     * } $input
     */
    public static function dryRun(array $input): array
    {
        $rows = array_values($input['rows'] ?? []);
        $manifests = [];
        foreach ($input['manifests'] ?? [] as $m) {
            $id = (string) ($m['kaynak_id'] ?? $m['id'] ?? '');
            if ($id !== '') {
                $manifests[$id] = $m;
            }
        }

        $tamlik = $input['tamlik'] ?? SgkKatalogTamlikService::evaluate([
            'katalog_surumu' => (string) (($rows[0]['katalog_surumu'] ?? '')),
            'manifests' => array_values($input['manifests'] ?? []),
            'kod_satirlari' => $rows,
        ]);

        $valid = [];
        $invalid = [];
        $warnings = [];
        $blockers = $tamlik['blocker_detaylari'] ?? [];

        $seenKodDonem = [];
        $canonicalRows = [];

        foreach ($rows as $index => $row) {
            $errors = [];
            $unknown = array_diff(array_keys($row), array_merge(self::REQUIRED, [
                'gecerlilik_bitis', 'kosullar', 'kosullar_json', 'row_no',
            ]));
            foreach ($unknown as $field) {
                $errors[] = 'BILINMEYEN_ALAN:' . $field;
            }
            foreach (self::REQUIRED as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                    $errors[] = 'EKSIK_ZORUNLU_ALAN:' . $field;
                }
            }

            $kod = strtoupper(trim((string) ($row['eksik_gun_kodu'] ?? '')));
            $bas = (string) ($row['gecerlilik_baslangic'] ?? '');
            $bit = $row['gecerlilik_bitis'] ?? null;
            $bit = $bit === '' ? null : (is_string($bit) ? $bit : null);
            $aciklama = trim((string) ($row['resmi_aciklama'] ?? ''));
            $manifestId = (string) ($row['kaynak_manifest_id'] ?? '');
            $belge = strtoupper((string) ($row['belge_zorunlulugu'] ?? ''));
            $birlikte = strtoupper((string) ($row['diger_nedenlerle_birlikte_kullanim'] ?? ''));
            $hash = strtolower((string) ($row['aciklama_hash'] ?? ''));

            if ($aciklama === '') {
                $errors[] = 'BOS_ACIKLAMA';
            }
            if (!SgkKatalogContracts::isDate($bas)) {
                $errors[] = 'GECERSIZ_TARIH:gecerlilik_baslangic';
            }
            if ($bit !== null && !SgkKatalogContracts::isDate($bit)) {
                $errors[] = 'GECERSIZ_TARIH:gecerlilik_bitis';
            }
            if ($bit !== null && SgkKatalogContracts::isDate($bas) && SgkKatalogContracts::isDate($bit) && $bit < $bas) {
                $errors[] = 'TARIH_CAKISMASI_IC';
            }
            if (!in_array($belge, SgkKatalogContracts::BELGE_ZORUNLULUK, true)) {
                $errors[] = 'GECERSIZ_ENUM:belge_zorunlulugu';
            }
            if (!in_array($birlikte, SgkKatalogContracts::BIRLIKTE_KULLANIM, true)) {
                $errors[] = 'GECERSIZ_ENUM:diger_nedenlerle_birlikte_kullanim';
            }
            if (!SgkKatalogContracts::isSha256($hash)) {
                $errors[] = 'GECERSIZ_HASH:aciklama_hash';
            } elseif ($aciklama !== '' && hash('sha256', $aciklama) !== $hash) {
                $errors[] = 'HASH_UYUSMAZLIGI';
            }
            if ($manifestId === '' || !isset($manifests[$manifestId])) {
                $errors[] = 'GECERSIZ_KAYNAK';
            } else {
                $md = $manifests[$manifestId];
                if (strtoupper((string) ($md['durum'] ?? '')) === 'PASIF') {
                    $errors[] = 'PASIF_KAYNAK';
                }
                $mBas = $md['yururluk_baslangic'] ?? null;
                $mBit = $md['yururluk_bitis'] ?? null;
                if (is_string($mBas) && $mBas !== '' && SgkKatalogContracts::isDate($bas) && $bas < $mBas) {
                    $errors[] = 'KAYNAK_YURURLUK_CELISKISI';
                }
                if (is_string($mBit) && $mBit !== '' && SgkKatalogContracts::isDate($bas) && $bas > $mBit) {
                    $errors[] = 'KAYNAK_YURURLUK_CELISKISI';
                }
            }

            // Codes 22-29 without official primary source evidence are rejected.
            if (preg_match('/^(2[2-9])$/', $kod) === 1) {
                $errors[] = 'KAYNAKSIZ_KOD_ARALIGI_22_29';
            }

            $donemKey = $kod . '|' . $bas . '|' . ($bit ?? 'OPEN');
            if (isset($seenKodDonem[$donemKey])) {
                $errors[] = 'DUPLICATE_KOD_DONEM';
            }
            $seenKodDonem[$donemKey] = $index;

            // Overlap check against prior valid/attempted rows with same code
            foreach ($canonicalRows as $prev) {
                if ($prev['eksik_gun_kodu'] !== $kod) {
                    continue;
                }
                if (self::rangesOverlap($bas, $bit, $prev['gecerlilik_baslangic'], $prev['gecerlilik_bitis'])) {
                    $errors[] = 'TARIH_CAKISMASI';
                }
            }

            $canonical = [
                'katalog_surumu' => (string) ($row['katalog_surumu'] ?? ''),
                'eksik_gun_kodu' => $kod,
                'resmi_aciklama' => $aciklama,
                'gecerlilik_baslangic' => $bas,
                'gecerlilik_bitis' => $bit,
                'kaynak_manifest_id' => $manifestId,
                'belge_zorunlulugu' => $belge,
                'sifir_gun_sifir_kazanc_kullanilabilir_mi' => (bool) ($row['sifir_gun_sifir_kazanc_kullanilabilir_mi'] ?? false),
                'kismi_sureli_sozlesme_gerekli_mi' => (bool) ($row['kismi_sureli_sozlesme_gerekli_mi'] ?? false),
                'tek_basina_kullanilabilir_mi' => (bool) ($row['tek_basina_kullanilabilir_mi'] ?? false),
                'diger_nedenlerle_birlikte_kullanim' => $birlikte,
                'aktif_mi' => (bool) ($row['aktif_mi'] ?? false),
                'kosullar' => $row['kosullar'] ?? ($row['kosullar_json'] ?? null),
                'aciklama_hash' => $hash,
            ];

            if ($errors !== []) {
                $invalid[] = [
                    'row_index' => $index,
                    'eksik_gun_kodu' => $kod,
                    'errors' => array_values(array_unique($errors)),
                ];
                continue;
            }

            $canonicalRows[] = $canonical;
            $valid[] = $canonical;
        }

        // Deterministic sort independent of input row order
        usort($canonicalRows, static function (array $a, array $b): int {
            return [$a['eksik_gun_kodu'], $a['gecerlilik_baslangic'], (string) $a['gecerlilik_bitis']]
                <=> [$b['eksik_gun_kodu'], $b['gecerlilik_baslangic'], (string) $b['gecerlilik_bitis']];
        });

        if (!empty($tamlik['blocker_kodlari'])) {
            $warnings[] = 'TAMLIK_KAPISI_IMPORT_YAZMAYI_ENGELLER';
        }
        if ($rows === []) {
            $warnings[] = 'BOS_PAKET';
        }

        $manifestIds = array_keys($manifests);
        sort($manifestIds);
        $manifestSetHash = SgkKatalogContracts::sha256Canonical(['manifest_ids' => $manifestIds]);
        $payloadHash = SgkKatalogContracts::sha256Canonical(['rows' => $canonicalRows]);

        $importYapilabilir = false; // S85-C1: write endpoint not activated

        $out = [
            'mode' => 'DRY_RUN',
            'format' => strtoupper((string) ($input['format'] ?? 'JSON')),
            'gecerli_satirlar' => $valid,
            'hatali_satirlar' => $invalid,
            'warnings' => $warnings,
            'blocker_kodlari' => $tamlik['blocker_kodlari'] ?? [SgkKatalogContracts::BLOCKER_TAMLIK],
            'blocker_detaylari' => $blockers,
            'canonical_payload' => ['rows' => $canonicalRows],
            'payload_hash' => $payloadHash,
            'manifest_set_hash' => $manifestSetHash,
            'import_yapilabilir_mi' => $importYapilabilir,
            'yazma_endpoint_aktif_mi' => false,
            'tamlik' => [
                'tamlik_durumu' => $tamlik['tamlik_durumu'] ?? 'TASLAK',
                'onaylanabilir_mi' => false,
                'response_hash' => $tamlik['response_hash'] ?? null,
            ],
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }

    private static function rangesOverlap(string $a0, ?string $a1, string $b0, ?string $b1): bool
    {
        if (!SgkKatalogContracts::isDate($a0) || !SgkKatalogContracts::isDate($b0)) {
            return false;
        }
        $aEnd = $a1 ?? '9999-12-31';
        $bEnd = $b1 ?? '9999-12-31';

        return $a0 <= $bEnd && $b0 <= $aEnd;
    }
}
