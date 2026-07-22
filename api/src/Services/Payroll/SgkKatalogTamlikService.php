<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Catalog completeness gate. Never promotes DOGRULANMIS_TAM without full evidence.
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
     *   kod_bazli_yururluk_tarihi_tam_mi?: bool
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

        $eksikKanitlar = [];
        $erisilemeyen = [];
        $blockers = [];

        $gunceTam = !empty($input['gunce_tam_kod_listesi_kanitlandi_mi']);
        $yururlukTam = !empty($input['kod_bazli_yururluk_tarihi_tam_mi']);
        $ebildirgeOk = !empty($input['ebildirge_guncel_gorunum_dogrulandi_mi']);
        $ucuncuTaraf = !empty($input['ucuncu_taraf_kaynak_kullanildi_mi']);

        if (!$gunceTam) {
            $eksikKanitlar[] = 'GUNCEL_TAM_KOD_LISTESI';
        }
        if (!$yururlukTam) {
            $eksikKanitlar[] = 'KOD_BAZLI_YURURLUK_TARIHI';
        }
        if ($birlesik === []) {
            $eksikKanitlar[] = 'BIRLESIK_NEDEN_MATRISI';
        }
        if ($belgeMatrisi === []) {
            $eksikKanitlar[] = 'KOD_BELGE_MATRISI';
        }
        if ($sifirKurallar === []) {
            $eksikKanitlar[] = 'SIFIR_GUN_SIFIR_KAZANC_KISITLARI';
        }
        if ($kismiKurallar === []) {
            $eksikKanitlar[] = 'KISMI_SURELI_KULLANIM_KURALLARI';
        }
        if ($manifests === []) {
            $eksikKanitlar[] = 'KAYNAK_MANIFESTI';
        }
        if (!$ebildirgeOk) {
            $eksikKanitlar[] = 'EBILDIRGE_GUNCEL_GORUNUM';
            $erisilemeyen[] = 'e-Bildirge/e-Beyanname login-gated dropdown';
        }
        if ($ucuncuTaraf) {
            $eksikKanitlar[] = 'UCUNCU_TARAF_KAYNAK';
        }

        $aktifManifest = 0;
        $manifestIds = [];
        foreach ($manifests as $m) {
            $id = (string) ($m['kaynak_id'] ?? $m['id'] ?? '');
            $durum = strtoupper((string) ($m['durum'] ?? 'AKTIF'));
            $hash = (string) ($m['icerik_sha256'] ?? $m['indirilen_dosya_sha256'] ?? '');
            if ($id !== '') {
                $manifestIds[] = $id;
            }
            if ($durum !== 'AKTIF') {
                $eksikKanitlar[] = 'PASIF_MANIFEST:' . $id;
            } else {
                $aktifManifest++;
            }
            if (!SgkKatalogContracts::isSha256($hash)) {
                $eksikKanitlar[] = 'MANIFEST_HASH_DOGRULANAMADI:' . $id;
            }
            if (!empty($m['erisilemez_mi'])) {
                $erisilemeyen[] = $id !== '' ? $id : 'bilinmeyen_kaynak';
            }
            if (!empty($m['volatile_html_mi']) || !empty($m['hash_degisti_mi'])) {
                $eksikKanitlar[] = 'VOLATILE_HTML_HASH_DEGISIMI:' . $id;
            }
            if (array_key_exists('arsiv_kopyasi_repoda_mi', $m) && !$m['arsiv_kopyasi_repoda_mi']) {
                $eksikKanitlar[] = 'ARSIV_KOPYASI_YOK:' . $id;
            }
        }

        $yalnizOperasyonel = $kodlar === [] && $operasyonel !== [] && $manifests === [];
        if ($yalnizOperasyonel) {
            $eksikKanitlar[] = 'YALNIZ_OPERASYONEL_EKRAN_GORUNTUSU';
        }

        foreach ($operasyonel as $op) {
            if (!empty($op['mevzuat_kaynagi_mi'])) {
                $eksikKanitlar[] = 'OPERASYONEL_KANIT_MEVZUAT_YERINE_KULLANILDI';
            }
            if (!empty($op['tek_basina_yeterli_mi'])) {
                $eksikKanitlar[] = 'OPERASYONEL_KANIT_TEK_BASINA_YETERLI_IDDIASI';
            }
        }

        $eksikKanitlar = array_values(array_unique($eksikKanitlar));
        $erisilemeyen = array_values(array_unique($erisilemeyen));

        $requested = strtoupper((string) ($input['istenen_tamlik_durumu'] ?? ''));
        if ($requested === 'DOGRULANMIS_TAM') {
            $eksikKanitlar[] = 'DOGRULANMIS_TAM_REDDI';
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_TAMLIK,
                'DOGRULANMIS_TAM denemesi reddedildi; kaynak tamlik kapisi acik degil.',
                'Resmi birincil kaynak paketi tamamlanmadan DOGRULANMIS_TAM secilemez.'
            );
            $eksikKanitlar = array_values(array_unique($eksikKanitlar));
        }

        // S85-C1: never emit DOGRULANMIS_TAM production status.
        $tamlikDurumu = 'TASLAK';
        $onaylanabilir = false;

        $blockers[] = SgkKatalogContracts::blocker(
            SgkKatalogContracts::BLOCKER_TAMLIK,
            'Resmi kaynak tamlik kaniti eksik; katalog DOGRULANMIS_TAM yapilamaz ve onaylanamaz.',
            'Mali musavir operasyonel kanit paketi + guncel resmi SGK/mevzuat eklerini tamamlayin; ucuncu taraf listeleri kullanmayin.'
        );

        sort($manifestIds);
        $manifestSetHash = SgkKatalogContracts::sha256Canonical(['manifest_ids' => $manifestIds]);

        $payload = [
            'tamlik_durumu' => $tamlikDurumu,
            'katalog_surumu' => (string) ($input['katalog_surumu'] ?? ''),
            'manifest_set_hash' => $manifestSetHash,
            'kod_sayisi' => count($kodlar),
            'kaynak_sayisi' => count($manifests),
            'aktif_manifest_sayisi' => $aktifManifest,
            'eksik_kanitlar' => $eksikKanitlar,
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
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
            'onaylanabilir_mi' => $onaylanabilir,
            'dogrulanmis_tam_secilebilir_mi' => false,
            'import_yazma_aktif_mi' => false,
            'approve_aktif_mi' => false,
        ];
        $payload['response_hash'] = SgkKatalogContracts::sha256Canonical($payload);

        return $payload;
    }
}
