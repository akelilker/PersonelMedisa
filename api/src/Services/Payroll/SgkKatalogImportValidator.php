<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1 / S98 / S106: Deterministic catalog import dry-run validator (no write).
 */
final class SgkKatalogImportValidator
{
    private const REQUIRED = [
        'katalog_surumu',
        'eksik_gun_kodu',
        'resmi_aciklama',
        'kaynak_manifest_id',
        'belge_zorunlulugu',
        'sifir_gun_sifir_kazanc_kullanilabilir_mi',
        'kismi_sureli_sozlesme_gerekli_mi',
        'tek_basina_kullanilabilir_mi',
        'diger_nedenlerle_birlikte_kullanim',
        'aktif_mi',
        'aciklama_hash',
    ];

    private const OPTIONAL = [
        'gecerlilik_baslangic',
        'gecerlilik_bitis',
        'gecerlilik_tarih_durumu',
        'ilk_resmi_kanit_tarihi',
        'kosullar',
        'kosullar_json',
        'row_no',
        'aktiflik_durumu',
        'sifir_gun_sifir_kazanc_durumu',
        'belge_saklama_ibraz_durumu',
        'yabanci_kullanim_durumu',
        'portal_teyit_durumu',
        'mevzuat_kurallari_json',
        'mevzuat_kurallari',
        'kaynak_kod_set_hash',
        'kaynak_kodlar',
        'kismi_istihdam_izinli_mi',
        'yabanci_kismi_istihdam_baglami_mi',
        'resmi_primary_kod_kaniti_var_mi',
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

        $tamlikFlags = is_array($input['tamlik'] ?? null) ? $input['tamlik'] : [];
        // Always re-evaluate; flags (ucuncu_taraf, ebildirge, …) may be supplied via tamlik.
        $tamlik = SgkKatalogTamlikService::evaluate(array_merge($tamlikFlags, [
            'katalog_surumu' => (string) (($rows[0]['katalog_surumu'] ?? '')),
            'manifests' => array_values($input['manifests'] ?? []),
            'kod_satirlari' => $rows,
        ]));

        $valid = [];
        $invalid = [];
        $warnings = [];
        $blockers = $tamlik['blocker_detaylari'] ?? [];

        $seenKodDonem = [];
        $canonicalRows = [];

        foreach ($rows as $index => $row) {
            $errors = [];
            $unknown = array_diff(array_keys($row), array_merge(self::REQUIRED, self::OPTIONAL));
            foreach ($unknown as $field) {
                $errors[] = 'BILINMEYEN_ALAN:' . $field;
            }
            foreach (self::REQUIRED as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                    $errors[] = 'EKSIK_ZORUNLU_ALAN:' . $field;
                }
            }

            $kod = strtoupper(trim((string) ($row['eksik_gun_kodu'] ?? '')));
            $basRaw = $row['gecerlilik_baslangic'] ?? null;
            $bas = is_string($basRaw) ? trim($basRaw) : '';
            if ($bas === '') {
                $bas = null;
            }
            $bit = $row['gecerlilik_bitis'] ?? null;
            $bit = $bit === '' ? null : (is_string($bit) ? trim($bit) : null);
            if ($bit === '') {
                $bit = null;
            }

            $tarihDurumu = strtoupper(trim((string) ($row['gecerlilik_tarih_durumu'] ?? 'BELIRLENEMEDI')));
            if ($tarihDurumu === '') {
                $tarihDurumu = 'BELIRLENEMEDI';
            }
            if (!in_array($tarihDurumu, SgkKatalogContracts::GECERLILIK_TARIH_DURUMU, true)) {
                $errors[] = 'GECERSIZ_ENUM:gecerlilik_tarih_durumu';
            }

            $ilkKanit = $row['ilk_resmi_kanit_tarihi'] ?? null;
            $ilkKanit = is_string($ilkKanit) && trim($ilkKanit) !== '' ? trim($ilkKanit) : null;
            if ($ilkKanit !== null && !SgkKatalogContracts::isDate($ilkKanit)) {
                $errors[] = 'GECERSIZ_TARIH:ilk_resmi_kanit_tarihi';
            }

            if ($tarihDurumu === 'RESMI_YURURLUK') {
                if ($bas === null || !SgkKatalogContracts::isDate($bas)) {
                    $errors[] = 'GECERSIZ_TARIH:gecerlilik_baslangic';
                }
            } elseif ($tarihDurumu === 'BELIRLENEMEDI') {
                if ($bas !== null) {
                    $errors[] = 'CELISKI_TARIH_DURUMU:gecerlilik_baslangic';
                }
            } elseif ($tarihDurumu === 'ILK_RESMI_KANIT') {
                if ($ilkKanit === null && ($bas === null || !SgkKatalogContracts::isDate($bas))) {
                    if ($bas !== null && !SgkKatalogContracts::isDate($bas)) {
                        $errors[] = 'GECERSIZ_TARIH:gecerlilik_baslangic';
                    } elseif ($ilkKanit === null) {
                        $errors[] = 'ILK_RESMI_KANIT_TARIHI_EKSIK';
                    }
                }
            }

            $aciklama = trim((string) ($row['resmi_aciklama'] ?? ''));
            $manifestId = (string) ($row['kaynak_manifest_id'] ?? '');
            $belge = strtoupper((string) ($row['belge_zorunlulugu'] ?? ''));
            $birlikte = strtoupper((string) ($row['diger_nedenlerle_birlikte_kullanim'] ?? ''));
            $hash = strtolower((string) ($row['aciklama_hash'] ?? ''));

            if ($aciklama === '') {
                $errors[] = 'BOS_ACIKLAMA';
            }
            if ($bit !== null && !SgkKatalogContracts::isDate($bit)) {
                $errors[] = 'GECERSIZ_TARIH:gecerlilik_bitis';
            }
            if ($bas !== null && $bit !== null && SgkKatalogContracts::isDate($bas) && SgkKatalogContracts::isDate($bit) && $bit < $bas) {
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

            $manifest = null;
            if ($manifestId === '' || !isset($manifests[$manifestId])) {
                $errors[] = 'GECERSIZ_KAYNAK';
            } else {
                $manifest = $manifests[$manifestId];
                if (strtoupper((string) ($manifest['durum'] ?? '')) === 'PASIF') {
                    $errors[] = 'PASIF_KAYNAK';
                }
                $mBas = $manifest['yururluk_baslangic'] ?? null;
                $mBit = $manifest['yururluk_bitis'] ?? null;
                if (is_string($mBas) && $mBas !== '' && $bas !== null && SgkKatalogContracts::isDate($bas) && $bas < $mBas) {
                    $errors[] = 'KAYNAK_YURURLUK_CELISKISI';
                }
                if (is_string($mBit) && $mBit !== '' && $bas !== null && SgkKatalogContracts::isDate($bas) && $bas > $mBit) {
                    $errors[] = 'KAYNAK_YURURLUK_CELISKISI';
                }
            }

            foreach (['aktiflik_durumu' => SgkKatalogContracts::AKTIFLIK_DURUMU,
                'sifir_gun_sifir_kazanc_durumu' => SgkKatalogContracts::SIFIR_GUN_DURUMU,
                'belge_saklama_ibraz_durumu' => SgkKatalogContracts::BELGE_SAKLAMA_IBRAZ,
                'yabanci_kullanim_durumu' => SgkKatalogContracts::YABANCI_KULLANIM,
                'portal_teyit_durumu' => SgkKatalogContracts::PORTAL_TEYIT,
            ] as $field => $allowed) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                    continue;
                }
                $val = strtoupper((string) $row[$field]);
                if (!in_array($val, $allowed, true)) {
                    $errors[] = 'GECERSIZ_ENUM:' . $field;
                }
            }

            if (array_key_exists('mevzuat_kurallari_json', $row) || array_key_exists('mevzuat_kurallari', $row)) {
                $rules = $row['mevzuat_kurallari_json'] ?? $row['mevzuat_kurallari'];
                if ($rules !== null && $rules !== '' && !SgkKatalogContracts::isValidMevzuatKurallari($rules)) {
                    $errors[] = 'GECERSIZ_MEVZUAT_KURALLARI_JSON';
                }
            }

            foreach (SgkKatalogContracts::assertLegacyCanonicalConsistency($row) as $conflict) {
                $errors[] = $conflict;
            }

            foreach (SgkKatalogContracts::assertKod22_29EvidenceGate($kod, $row, $manifest) as $gateErr) {
                $errors[] = $gateErr;
            }

            $donemKey = $kod . '|' . ($bas ?? '__NULL__') . '|' . ($bit ?? 'OPEN');
            if (isset($seenKodDonem[$donemKey])) {
                $errors[] = 'DUPLICATE_KOD_DONEM';
            }
            $seenKodDonem[$donemKey] = $index;

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
                'gecerlilik_tarih_durumu' => $tarihDurumu,
                'ilk_resmi_kanit_tarihi' => $ilkKanit,
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

            $hasCanonicalStatus = false;
            foreach ([
                'aktiflik_durumu',
                'sifir_gun_sifir_kazanc_durumu',
                'belge_saklama_ibraz_durumu',
                'yabanci_kullanim_durumu',
                'portal_teyit_durumu',
            ] as $cField) {
                if (array_key_exists($cField, $row) && $row[$cField] !== null && $row[$cField] !== '') {
                    $canonical[$cField] = strtoupper((string) $row[$cField]);
                    $hasCanonicalStatus = true;
                }
            }
            if (array_key_exists('mevzuat_kurallari_json', $row)) {
                $canonical['mevzuat_kurallari_json'] = $row['mevzuat_kurallari_json'];
            } elseif (array_key_exists('mevzuat_kurallari', $row)) {
                $canonical['mevzuat_kurallari_json'] = $row['mevzuat_kurallari'];
            }
            if (array_key_exists('kaynak_kod_set_hash', $row)) {
                $canonical['kaynak_kod_set_hash'] = $row['kaynak_kod_set_hash'];
            }
            if (array_key_exists('kaynak_kodlar', $row)) {
                $canonical['kaynak_kodlar'] = $row['kaynak_kodlar'];
            }

            if ($hasCanonicalStatus) {
                $proj = SgkKatalogContracts::projectCanonicalToLegacy($canonical);
                foreach ($proj['warnings'] as $w) {
                    $warnings[] = $w;
                }
                $canonical['legacy_projection'] = [
                    'sifir_gun_sifir_kazanc_kullanilabilir_mi' => $proj['sifir_gun_sifir_kazanc_kullanilabilir_mi'],
                    'aktif_mi' => $proj['aktif_mi'],
                    'belge_zorunlulugu' => $proj['belge_zorunlulugu'],
                ];
            }

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

        usort($canonicalRows, static function (array $a, array $b): int {
            $aBas = $a['gecerlilik_baslangic'] ?? '';
            $bBas = $b['gecerlilik_baslangic'] ?? '';

            return [$a['eksik_gun_kodu'], (string) $aBas, (string) $a['gecerlilik_bitis']]
                <=> [$b['eksik_gun_kodu'], (string) $bBas, (string) $b['gecerlilik_bitis']];
        });

        if (!empty($tamlik['blocker_kodlari'])) {
            $warnings[] = 'TAMLIK_KAPISI_IMPORT_YAZMAYI_ENGELLER';
        }
        if ($rows === []) {
            $warnings[] = 'BOS_PAKET';
        }
        $warnings = array_values(array_unique($warnings));

        $manifestIds = array_keys($manifests);
        sort($manifestIds);
        $manifestSetHash = SgkKatalogContracts::sha256Canonical(['manifest_ids' => $manifestIds]);
        $payloadHash = SgkKatalogContracts::sha256Canonical(['rows' => $canonicalRows]);

        $structuralOk = $invalid === [];
        $tamlikDurumu = (string) ($tamlik['tamlik_durumu'] ?? 'TASLAK');
        $importYapilabilir = $structuralOk
            && ($tamlik['import_yazma_aktif_mi'] ?? false)
            && in_array($tamlikDurumu, ['RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'], true);

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
            'yazma_endpoint_aktif_mi' => (bool) ($tamlik['import_yazma_aktif_mi'] ?? false),
            'tamlik' => [
                'tamlik_durumu' => $tamlikDurumu,
                'onaylanabilir_mi' => (bool) ($tamlik['onaylanabilir_mi'] ?? false),
                'response_hash' => $tamlik['response_hash'] ?? null,
            ],
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }

    /**
     * @param string|null $a0
     * @param string|null $a1
     * @param string|null $b0
     * @param string|null $b1
     */
    private static function rangesOverlap($a0, $a1, $b0, $b1): bool
    {
        if ($a0 === null && $b0 === null) {
            return true;
        }
        if ($a0 === null || $b0 === null) {
            return true;
        }
        if (!SgkKatalogContracts::isDate($a0) || !SgkKatalogContracts::isDate($b0)) {
            return false;
        }
        $aEnd = $a1 ?? '9999-12-31';
        $bEnd = $b1 ?? '9999-12-31';

        return $a0 <= $bEnd && $b0 <= $aEnd;
    }
}
