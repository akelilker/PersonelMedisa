<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1 / S98 / S106: Catalog completeness gate with three-level tamlik evaluation.
 * Never promotes DOGRULANMIS_TAM without full evidence.
 */
final class SgkKatalogTamlikService
{
    /**
     * @param array{
     *   katalog_surumu?: string|null,
     *   manifests?: list<array<string,mixed>>,
     *   operasyonel_kanitlar?: list<array<string,mixed>>,
     *   kod_satirlari?: list<array<string,mixed>>,
     *   birlesik_neden_matrisi?: list<array<string,mixed>>,
     *   belge_matrisi?: list<array<string,mixed>>,
     *   sifir_gun_kurallari?: list<array<string,mixed>>,
     *   kismi_sureli_kurallari?: list<array<string,mixed>>,
     *   ebildirge_guncel_gorunum_dogrulandi_mi?: bool,
     *   ucuncu_taraf_kaynak_kullanildi_mi?: bool,
     *   gunce_tam_kod_listesi_kanitlandi_mi?: bool,
     *   kod_bazli_yururluk_tarihi_tam_mi?: bool,
     *   expert_draft_tek_basina_mi?: bool,
     *   istenen_tamlik_durumu?: string|null
     * } $input
     */
    public static function evaluate(array $input): array
    {
        $manifests = array_values($input['manifests'] ?? []);
        $operasyonel = array_values($input['operasyonel_kanitlar'] ?? []);
        $kodlar = array_values($input['kod_satirlari'] ?? []);
        $birlesik = array_values($input['birlesik_neden_matrisi'] ?? []);
        $belgeMatrisi = array_values($input['belge_matrisi'] ?? []);
        $sifirKurallar = array_values($input['sifir_gun_kurallari'] ?? []);
        $kismiKurallar = array_values($input['kismi_sureli_kurallari'] ?? []);

        $limitedBlockers = [];
        $limitedWarnings = [];
        $fullBlockers = [];
        $erisilemeyen = [];
        $uyarilar = [];

        $gunceTam = !empty($input['gunce_tam_kod_listesi_kanitlandi_mi']);
        $yururlukTam = !empty($input['kod_bazli_yururluk_tarihi_tam_mi']);
        $ebildirgeOk = !empty($input['ebildirge_guncel_gorunum_dogrulandi_mi']);
        $ucuncuTaraf = !empty($input['ucuncu_taraf_kaynak_kullanildi_mi']);

        if (!$gunceTam) {
            $limitedWarnings[] = 'GUNCEL_TAM_KOD_LISTESI';
            $fullBlockers[] = 'GUNCEL_TAM_KOD_LISTESI';
        }
        if (!$yururlukTam) {
            $limitedWarnings[] = 'KOD_BAZLI_YURURLUK_TARIHI';
            $fullBlockers[] = 'KOD_BAZLI_YURURLUK_TARIHI';
        }
        if ($birlesik === []) {
            $limitedWarnings[] = 'BIRLESIK_NEDEN_MATRISI';
            $fullBlockers[] = 'BIRLESIK_NEDEN_MATRISI';
        }
        if ($belgeMatrisi === []) {
            $limitedWarnings[] = 'KOD_BELGE_MATRISI';
            $fullBlockers[] = 'KOD_BELGE_MATRISI';
        }
        if ($sifirKurallar === []) {
            $limitedWarnings[] = 'SIFIR_GUN_SIFIR_KAZANC_KISITLARI';
            $fullBlockers[] = 'SIFIR_GUN_SIFIR_KAZANC_KISITLARI';
        }
        if ($kismiKurallar === []) {
            $limitedWarnings[] = 'KISMI_SURELI_KULLANIM_KURALLARI';
            $fullBlockers[] = 'KISMI_SURELI_KULLANIM_KURALLARI';
        }
        if ($manifests === []) {
            $limitedBlockers[] = 'KAYNAK_MANIFESTI';
            $fullBlockers[] = 'KAYNAK_MANIFESTI';
        }
        if (!$ebildirgeOk) {
            $limitedWarnings[] = 'EBILDIRGE_GUNCEL_GORUNUM';
            $fullBlockers[] = 'EBILDIRGE_GUNCEL_GORUNUM';
            $erisilemeyen[] = 'e-Bildirge/e-Beyanname login-gated dropdown';
        }
        if ($ucuncuTaraf) {
            $limitedBlockers[] = 'UCUNCU_TARAF_KAYNAK';
            $fullBlockers[] = 'UCUNCU_TARAF_KAYNAK';
        }
        if (!empty($input['expert_draft_tek_basina_mi'])) {
            $limitedBlockers[] = 'EXPERT_DRAFT_TEK_BASINA_YETERSIZ';
            $fullBlockers[] = 'EXPERT_DRAFT_TEK_BASINA_YETERSIZ';
        }

        $aktifManifest = 0;
        $primaryManifest = 0;
        $manifestIds = [];
        foreach ($manifests as $m) {
            $id = (string) ($m['kaynak_id'] ?? $m['id'] ?? '');
            $durum = strtoupper((string) ($m['durum'] ?? 'AKTIF'));
            $hash = (string) ($m['icerik_sha256'] ?? $m['indirilen_dosya_sha256'] ?? '');
            $sinif = strtoupper((string) ($m['kanit_sinifi'] ?? SgkKatalogContracts::KANIT_RESMI_PRIMARY));
            if ($id !== '') {
                $manifestIds[] = $id;
            }
            if ($durum !== 'AKTIF') {
                $limitedBlockers[] = 'PASIF_MANIFEST:' . $id;
                $fullBlockers[] = 'PASIF_MANIFEST:' . $id;
            } else {
                $aktifManifest++;
            }
            if ($sinif === SgkKatalogContracts::KANIT_EXPERT_DRAFT) {
                $limitedBlockers[] = 'EXPERT_DRAFT_MANIFEST:' . $id;
                $fullBlockers[] = 'EXPERT_DRAFT_MANIFEST:' . $id;
            } elseif (SgkKatalogContracts::isPrimaryOfficialManifest($m)) {
                $primaryManifest++;
            }
            if (!SgkKatalogContracts::isSha256($hash)) {
                $limitedBlockers[] = 'MANIFEST_HASH_DOGRULANAMADI:' . $id;
                $fullBlockers[] = 'MANIFEST_HASH_DOGRULANAMADI:' . $id;
            }
            if (!empty($m['erisilemez_mi'])) {
                $erisilemeyen[] = $id !== '' ? $id : 'bilinmeyen_kaynak';
            }
            if (!empty($m['volatile_html_mi']) || !empty($m['hash_degisti_mi'])) {
                $limitedBlockers[] = 'VOLATILE_HTML_HASH_DEGISIMI:' . $id;
                $fullBlockers[] = 'VOLATILE_HTML_HASH_DEGISIMI:' . $id;
            }
            if (array_key_exists('arsiv_kopyasi_repoda_mi', $m) && !$m['arsiv_kopyasi_repoda_mi']) {
                $limitedWarnings[] = 'ARSIV_KOPYASI_YOK:' . $id;
                $fullBlockers[] = 'ARSIV_KOPYASI_YOK:' . $id;
            }
        }

        if ($primaryManifest === 0) {
            $limitedBlockers[] = 'PRIMARY_RESMI_MANIFEST_EKSIK';
            $fullBlockers[] = 'PRIMARY_RESMI_MANIFEST_EKSIK';
        }

        if ($kodlar === [] && ($manifests !== [] || $operasyonel !== [])) {
            $limitedBlockers[] = 'KOD_SATIRLARI_EKSIK';
            $fullBlockers[] = 'KOD_SATIRLARI_EKSIK';
        }

        foreach ($kodlar as $kodRow) {
            $kod = strtoupper(trim((string) ($kodRow['eksik_gun_kodu'] ?? '')));
            $aciklama = trim((string) ($kodRow['resmi_aciklama'] ?? ''));
            $sifir = strtoupper((string) ($kodRow['sifir_gun_sifir_kazanc_durumu'] ?? ''));
            $yabanci = strtoupper((string) ($kodRow['yabanci_kullanim_durumu'] ?? ''));
            $aktiflik = strtoupper((string) ($kodRow['aktiflik_durumu'] ?? ''));
            $portal = strtoupper((string) ($kodRow['portal_teyit_durumu'] ?? ''));
            $bas = $kodRow['gecerlilik_baslangic'] ?? null;
            $basStr = is_string($bas) ? trim($bas) : '';
            $tarihDurumu = strtoupper((string) ($kodRow['gecerlilik_tarih_durumu'] ?? 'BELIRLENEMEDI'));
            if ($tarihDurumu === '') {
                $tarihDurumu = 'BELIRLENEMEDI';
            }
            $manifestId = (string) ($kodRow['kaynak_manifest_id'] ?? '');

            if ($kod === '' || $aciklama === '') {
                $limitedBlockers[] = 'KOD_ACIKLAMA_EKSIK:' . ($kod !== '' ? $kod : '?');
                $fullBlockers[] = 'KOD_ACIKLAMA_EKSIK:' . ($kod !== '' ? $kod : '?');
            }

            if ($sifir === 'TEYITSIZ') {
                $limitedWarnings[] = 'TEYITSIZ_SIFIR_GUN:' . $kod;
                $fullBlockers[] = 'TEYITSIZ_SIFIR_GUN:' . $kod;
            }
            if ($yabanci === 'TEYITSIZ' || $yabanci === '') {
                $limitedWarnings[] = 'YABANCI_KULLANIM_BELIRSIZ:' . $kod;
                $fullBlockers[] = 'YABANCI_KULLANIM_BELIRSIZ:' . $kod;
            }
            if ($portal === 'TEYIT_BEKLIYOR' || $aktiflik === 'PORTAL_TEYIT_BEKLIYOR') {
                $limitedWarnings[] = 'PORTAL_TEYIT_BEKLIYOR:' . $kod;
                $fullBlockers[] = 'PORTAL_TEYIT_BEKLIYOR:' . $kod;
            }

            if ($basStr === '' || !SgkKatalogContracts::isDate($basStr)) {
                if ($tarihDurumu === 'BELIRLENEMEDI') {
                    $uyarilar[] = 'BELIRLENEMEDI_YURURLUK:' . $kod;
                } else {
                    $limitedBlockers[] = 'YURURLUK_TARIHI_EKSIK:' . $kod;
                    $fullBlockers[] = 'YURURLUK_TARIHI_EKSIK:' . $kod;
                }
            }

            if ($manifestId === '') {
                $limitedBlockers[] = 'PRIMARY_MANIFEST_EKSIK:' . $kod;
                $fullBlockers[] = 'PRIMARY_MANIFEST_EKSIK:' . $kod;
            }
            if (($kod === '28' || $kod === '29') && $aktiflik === 'AKTIF') {
                $limitedWarnings[] = 'TARIHSEL_KOD_GUNCEL_AKTIF:' . $kod;
                $fullBlockers[] = 'TARIHSEL_KOD_GUNCEL_AKTIF:' . $kod;
            }
            if ($kod === '27') {
                $setHash = (string) ($kodRow['kaynak_kod_set_hash'] ?? '');
                if (!SgkKatalogContracts::isSha256($setHash)) {
                    $limitedWarnings[] = 'OZEL_BIRLESIK_KOD_MATRISI_EKSIK:27';
                    $fullBlockers[] = 'OZEL_BIRLESIK_KOD_MATRISI_EKSIK:27';
                }
            }
        }

        $yalnizOperasyonel = $kodlar === [] && $operasyonel !== [] && $manifests === [];
        if ($yalnizOperasyonel) {
            $limitedBlockers[] = 'YALNIZ_OPERASYONEL_EKRAN_GORUNTUSU';
            $fullBlockers[] = 'YALNIZ_OPERASYONEL_EKRAN_GORUNTUSU';
        }

        foreach ($operasyonel as $op) {
            if (!empty($op['mevzuat_kaynagi_mi'])) {
                $limitedWarnings[] = 'OPERASYONEL_KANIT_MEVZUAT_YERINE_KULLANILDI';
                $fullBlockers[] = 'OPERASYONEL_KANIT_MEVZUAT_YERINE_KULLANILDI';
            }
            if (!empty($op['tek_basina_yeterli_mi'])) {
                $limitedWarnings[] = 'OPERASYONEL_KANIT_TEK_BASINA_YETERLI_IDDIASI';
                $fullBlockers[] = 'OPERASYONEL_KANIT_TEK_BASINA_YETERLI_IDDIASI';
            }
        }

        $limitedBlockers = array_values(array_unique($limitedBlockers));
        $limitedWarnings = array_values(array_unique($limitedWarnings));
        $fullBlockers = array_values(array_unique($fullBlockers));
        $erisilemeyen = array_values(array_unique($erisilemeyen));
        $uyarilar = array_values(array_unique($uyarilar));

        $eksikKanitlar = array_values(array_unique(array_merge($limitedBlockers, $limitedWarnings, $uyarilar)));

        $requested = strtoupper((string) ($input['istenen_tamlik_durumu'] ?? ''));
        if ($requested === 'DOGRULANMIS_TAM') {
            $fullBlockers[] = 'DOGRULANMIS_TAM_REDDI';
            $eksikKanitlar[] = 'DOGRULANMIS_TAM_REDDI';
            $eksikKanitlar = array_values(array_unique($eksikKanitlar));
        }

        $limitedEligible = $limitedBlockers === []
            && $primaryManifest > 0
            && count($kodlar) > 0;

        $dogrulanmisTamEligible = $limitedEligible
            && $fullBlockers === []
            && $gunceTam
            && $yururlukTam
            && $ebildirgeOk;

        $blockers = [];
        $tamlikDurumu = 'TASLAK';
        $onaylanabilir = false;
        $importYazmaAktif = false;
        $approveAktif = false;

        if ($dogrulanmisTamEligible) {
            $tamlikDurumu = 'DOGRULANMIS_TAM';
            $onaylanabilir = true;
            $importYazmaAktif = true;
            $approveAktif = true;
        } elseif ($limitedEligible) {
            $tamlikDurumu = 'RESMI_KAYNAKLI_KISITLI';
            $onaylanabilir = true;
            $importYazmaAktif = true;
            $approveAktif = true;
        } else {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'Resmi kaynak tamlik kaniti eksik; katalog RESMI_KAYNAKLI_KISITLI veya DOGRULANMIS_TAM yapilamaz.',
                'Birincil resmi manifest, kod satirlari ve zorunlu kanit kapilarini tamamlayin; ucuncu taraf listeleri kullanmayin.'
            );
        }

        sort($manifestIds);
        $manifestSetHash = SgkKatalogContracts::sha256Canonical(['manifest_ids' => $manifestIds]);

        $payload = [
            'tamlik_durumu' => $tamlikDurumu,
            'katalog_surumu' => (string) ($input['katalog_surumu'] ?? ''),
            'manifest_set_hash' => $manifestSetHash,
            'kod_sayisi' => count($kodlar),
            'kaynak_sayisi' => count($manifests),
            'aktif_manifest_sayisi' => $aktifManifest,
            'primary_resmi_manifest_sayisi' => $primaryManifest,
            'eksik_kanitlar' => $eksikKanitlar,
            'uyarilar' => array_values(array_unique(array_merge($limitedWarnings, $uyarilar))),
            'limited_blocker_kodlari' => $limitedBlockers,
            'erisilemeyen_kaynaklar' => $erisilemeyen,
            'operasyonel_kanitlar' => array_map(static function (array $op): array {
                return [
                    'kanit_turu' => (string) ($op['kanit_turu'] ?? 'OPERASYONEL_DOGRULAMA_KANITI'),
                    'dosya_adi' => (string) ($op['dosya_adi'] ?? ''),
                    'sha256' => (string) ($op['sha256'] ?? ''),
                    'mevzuat_kaynagi_mi' => false,
                    'tek_basina_yeterli_mi' => false,
                    'destekledigi_kodlar' => array_values($op['destekledigi_kodlar'] ?? []),
                ];
            }, $operasyonel),
            'blocker_kodlari' => $tamlikDurumu === 'TASLAK'
                ? array_values(array_map(static fn (array $b) => $b['code'], $blockers))
                : [],
            'blocker_detaylari' => $blockers,
            'onaylanabilir_mi' => $onaylanabilir,
            'dogrulanmis_tam_secilebilir_mi' => $dogrulanmisTamEligible,
            'import_yazma_aktif_mi' => $importYazmaAktif,
            'approve_aktif_mi' => $approveAktif,
        ];
        $payload['response_hash'] = SgkKatalogContracts::sha256Canonical($payload);

        return $payload;
    }
}
