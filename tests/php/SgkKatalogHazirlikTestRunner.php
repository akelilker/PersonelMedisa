<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogContracts.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogTamlikService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogImportValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkOperasyonelKanitValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkOperasyonelKanitBase64Guard.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogOnayService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogWriteService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSurecKodEslemeValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkCokluNedenValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogPreviewService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkBelgeGereksinimValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKaynakManifestReader.php';

use Medisa\Api\Services\Payroll\SgkBelgeGereksinimValidator;
use Medisa\Api\Services\Payroll\SgkCokluNedenValidator;
use Medisa\Api\Services\Payroll\SgkKatalogContracts;
use Medisa\Api\Services\Payroll\SgkKatalogImportValidator;
use Medisa\Api\Services\Payroll\SgkKatalogOnayService;
use Medisa\Api\Services\Payroll\SgkKatalogPreviewService;
use Medisa\Api\Services\Payroll\SgkKatalogTamlikService;
use Medisa\Api\Services\Payroll\SgkKaynakManifestReader;
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitBase64Guard;
use Medisa\Api\Services\Payroll\SgkOperasyonelKanitValidator;
use Medisa\Api\Services\Payroll\SgkSurecKodEslemeValidator;

function assertTrue(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function validHash(string $text): string
{
    return hash('sha256', $text);
}

$manifest = [
    'kaynak_id' => 'MAN_1',
    'durum' => 'AKTIF',
    'icerik_sha256' => str_repeat('a', 64),
    'yururluk_baslangic' => '2011-01-01',
    'yururluk_bitis' => null,
    'arsiv_kopyasi_repoda_mi' => true,
];

// --- Tamlik ---
$t1 = SgkKatalogTamlikService::evaluate([]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $t1['blocker_kodlari'], true), 'tamlik kaynak eksik blocker');
assertTrue($t1['onaylanabilir_mi'] === false, 'tamlik onaylanamaz');
assertTrue($t1['tamlik_durumu'] !== 'DOGRULANMIS_TAM', 'tamlik asla DOGRULANMIS_TAM degil');
assertTrue(in_array('KAYNAK_MANIFESTI', $t1['eksik_kanitlar'], true), 'tamlik kaynak manifest eksik');

$tVol = SgkKatalogTamlikService::evaluate([
    'manifests' => [array_merge($manifest, ['volatile_html_mi' => true])],
]);
assertTrue((bool) array_filter($tVol['eksik_kanitlar'], static fn ($x) => str_starts_with($x, 'VOLATILE_HTML')), 'tamlik volatile html');

$tArsiv = SgkKatalogTamlikService::evaluate([
    'manifests' => [array_merge($manifest, ['arsiv_kopyasi_repoda_mi' => false])],
]);
assertTrue((bool) array_filter($tArsiv['eksik_kanitlar'], static fn ($x) => str_starts_with($x, 'ARSIV_KOPYASI')), 'tamlik arsiv yok');

$tOp = SgkKatalogTamlikService::evaluate([
    'operasyonel_kanitlar' => [['dosya_adi' => 'x.png', 'sha256' => str_repeat('b', 64)]],
    'manifests' => [],
    'kod_satirlari' => [],
]);
assertTrue(in_array('YALNIZ_OPERASYONEL_EKRAN_GORUNTUSU', $tOp['eksik_kanitlar'], true), 'tamlik yalniz operasyonel');

$t3 = SgkKatalogTamlikService::evaluate(['ucuncu_taraf_kaynak_kullanildi_mi' => true]);
assertTrue(in_array('UCUNCU_TARAF_KAYNAK', $t3['eksik_kanitlar'], true), 'tamlik ucuncu taraf');

$tEb = SgkKatalogTamlikService::evaluate(['ebildirge_guncel_gorunum_dogrulandi_mi' => false]);
assertTrue(in_array('EBILDIRGE_GUNCEL_GORUNUM', $tEb['eksik_kanitlar'], true), 'tamlik ebildirge erisilemez');

$tDog = SgkKatalogTamlikService::evaluate(['istenen_tamlik_durumu' => 'DOGRULANMIS_TAM']);
assertTrue(in_array('DOGRULANMIS_TAM_REDDI', $tDog['eksik_kanitlar'], true), 'tamlik DOGRULANMIS_TAM reddi');
assertTrue($tDog['dogrulanmis_tam_secilebilir_mi'] === false, 'tamlik dogrulanmis secilemez');
assertTrue($tDog['tamlik_durumu'] === 'TASLAK', 'tamlik istenen DOGRULANMIS_TAM yine TASLAK');

$tLimitedOk = SgkKatalogTamlikService::evaluate([
    'manifests' => [$manifest],
    'kod_satirlari' => [[
        'eksik_gun_kodu' => '01',
        'resmi_aciklama' => 'Istirahat',
        'gecerlilik_tarih_durumu' => 'BELIRLENEMEDI',
        'gecerlilik_baslangic' => '',
        'sifir_gun_sifir_kazanc_durumu' => 'TEYITSIZ',
        'yabanci_kullanim_durumu' => 'TEYITSIZ',
        'aktiflik_durumu' => 'PORTAL_TEYIT_BEKLIYOR',
        'portal_teyit_durumu' => 'TEYIT_BEKLIYOR',
        'kaynak_manifest_id' => 'MAN_1',
    ]],
]);
assertTrue($tLimitedOk['tamlik_durumu'] === 'RESMI_KAYNAKLI_KISITLI', 'tamlik RESMI_KAYNAKLI_KISITLI');
assertTrue($tLimitedOk['onaylanabilir_mi'] === true, 'tamlik kisitli onaylanabilir');
assertTrue($tLimitedOk['import_yazma_aktif_mi'] === true, 'tamlik kisitli import yazma aktif');
assertTrue($tLimitedOk['blocker_kodlari'] === [], 'tamlik kisitli blocker bos');
assertTrue(in_array('TEYITSIZ_SIFIR_GUN:01', $tLimitedOk['uyarilar'], true), 'tamlik kisitli TEYITSIZ uyari');

$tUcuncu = SgkKatalogTamlikService::evaluate([
    'manifests' => [$manifest],
    'kod_satirlari' => [[
        'eksik_gun_kodu' => '01',
        'resmi_aciklama' => 'Istirahat',
        'gecerlilik_tarih_durumu' => 'BELIRLENEMEDI',
        'kaynak_manifest_id' => 'MAN_1',
    ]],
    'ucuncu_taraf_kaynak_kullanildi_mi' => true,
]);
assertTrue($tUcuncu['tamlik_durumu'] === 'TASLAK', 'ucuncu taraf TASLAK kalir');
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $tUcuncu['blocker_kodlari'], true), 'ucuncu taraf blocker');

// --- Import ---
$empty = SgkKatalogImportValidator::dryRun(['format' => 'JSON', 'rows' => [], 'manifests' => [$manifest]]);
assertTrue($empty['import_yapilabilir_mi'] === false, 'import yazma kapali');
assertTrue(in_array('BOS_PAKET', $empty['warnings'], true), 'import bos paket warning');
assertTrue($empty['payload_hash'] === SgkKatalogImportValidator::dryRun(['format' => 'JSON', 'rows' => [], 'manifests' => [$manifest]])['payload_hash'], 'import empty repeatability');

$aciklama = 'Istirahat';
$rowBase = [
    'katalog_surumu' => 'V1',
    'eksik_gun_kodu' => '01',
    'resmi_aciklama' => $aciklama,
    'gecerlilik_baslangic' => '2020-01-01',
    'gecerlilik_tarih_durumu' => 'RESMI_YURURLUK',
    'gecerlilik_bitis' => null,
    'kaynak_manifest_id' => 'MAN_1',
    'belge_zorunlulugu' => 'ZORUNLU',
    'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true,
    'kismi_sureli_sozlesme_gerekli_mi' => false,
    'tek_basina_kullanilabilir_mi' => true,
    'diger_nedenlerle_birlikte_kullanim' => 'KOSULLU',
    'aktif_mi' => true,
    'aciklama_hash' => validHash($aciklama),
];

$dup = SgkKatalogImportValidator::dryRun([
    'rows' => [$rowBase, $rowBase],
    'manifests' => [$manifest],
]);
$dupErrors = [];
foreach ($dup['hatali_satirlar'] as $row) {
    foreach ($row['errors'] as $err) {
        $dupErrors[] = $err;
    }
}
assertTrue(in_array('DUPLICATE_KOD_DONEM', $dupErrors, true), 'import duplicate kod');

$rowBelirsiz = array_merge($rowBase, [
    'gecerlilik_baslangic' => null,
    'gecerlilik_tarih_durumu' => 'BELIRLENEMEDI',
]);
$belirsizOk = SgkKatalogImportValidator::dryRun([
    'rows' => [$rowBelirsiz],
    'manifests' => [$manifest],
    'tamlik' => $tLimitedOk,
]);
assertTrue(($belirsizOk['hatali_satirlar'] ?? []) === [], 'import BELIRLENEMEDI null baslangic kabul');
assertTrue($belirsizOk['gecerli_satirlar'][0]['gecerlilik_baslangic'] === null, 'import canonical null baslangic');

$belirsizCeliski = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, ['gecerlilik_tarih_durumu' => 'BELIRLENEMEDI'])],
    'manifests' => [$manifest],
]);
assertTrue(in_array('CELISKI_TARIH_DURUMU:gecerlilik_baslangic', $belirsizCeliski['hatali_satirlar'][0]['errors'] ?? [], true), 'import BELIRLENEMEDI + tarih celiski');

$dupNull = SgkKatalogImportValidator::dryRun([
    'rows' => [$rowBelirsiz, $rowBelirsiz],
    'manifests' => [$manifest],
    'tamlik' => $tLimitedOk,
]);
$dupNullErrors = [];
foreach ($dupNull['hatali_satirlar'] as $row) {
    foreach ($row['errors'] as $err) {
        $dupNullErrors[] = $err;
    }
}
assertTrue(in_array('DUPLICATE_KOD_DONEM', $dupNullErrors, true), 'import null start duplicate kod');

$overlap = SgkKatalogImportValidator::dryRun([
    'rows' => [
        $rowBase,
        array_merge($rowBase, ['gecerlilik_baslangic' => '2020-06-01', 'gecerlilik_bitis' => '2021-01-01']),
    ],
    'manifests' => [$manifest],
]);
$overlapErrors = [];
foreach ($overlap['hatali_satirlar'] as $row) {
    foreach ($row['errors'] as $err) {
        $overlapErrors[] = $err;
    }
}
assertTrue(in_array('TARIH_CAKISMASI', $overlapErrors, true), 'import tarih cakisma');

$noMan = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, ['kaynak_manifest_id' => 'YOK'])],
    'manifests' => [$manifest],
]);
assertTrue(in_array('GECERSIZ_KAYNAK', $noMan['hatali_satirlar'][0]['errors'] ?? [], true), 'import manifest eksik');

$pasif = SgkKatalogImportValidator::dryRun([
    'rows' => [$rowBase],
    'manifests' => [array_merge($manifest, ['durum' => 'PASIF'])],
]);
assertTrue(in_array('PASIF_KAYNAK', $pasif['hatali_satirlar'][0]['errors'] ?? [], true), 'import pasif kaynak');

$badHash = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, ['aciklama_hash' => str_repeat('c', 64)])],
    'manifests' => [$manifest],
]);
assertTrue(in_array('HASH_UYUSMAZLIGI', $badHash['hatali_satirlar'][0]['errors'] ?? [], true), 'import hash uyusmazligi');

$rowB = array_merge($rowBase, [
    'eksik_gun_kodu' => '15',
    'resmi_aciklama' => 'Devamsizlik',
    'aciklama_hash' => validHash('Devamsizlik'),
    'gecerlilik_baslangic' => '2021-01-01',
]);
$order1 = SgkKatalogImportValidator::dryRun(['rows' => [$rowBase, $rowB], 'manifests' => [$manifest]]);
$order2 = SgkKatalogImportValidator::dryRun(['rows' => [$rowB, $rowBase], 'manifests' => [$manifest]]);
assertTrue($order1['payload_hash'] === $order2['payload_hash'], 'import satir sirasi bagimsiz hash');
assertTrue($order1['payload_hash'] === SgkKatalogImportValidator::dryRun(['rows' => [$rowBase, $rowB], 'manifests' => [$manifest]])['payload_hash'], 'import repeatability');

$unknown = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, ['foo_bar' => 1])],
    'manifests' => [$manifest],
]);
assertTrue(in_array('BILINMEYEN_ALAN:foo_bar', $unknown['hatali_satirlar'][0]['errors'] ?? [], true), 'import unknown field');

$r22 = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, ['eksik_gun_kodu' => '22'])],
    'manifests' => [$manifest],
]);
assertTrue(in_array('KAYNAKSIZ_KOD_ARALIGI_22_29', $r22['hatali_satirlar'][0]['errors'] ?? [], true), 'import 22-29 reddi');

$rule07 = SgkKatalogContracts::assert07ZeroEarningsRule('07', 0, 0.0);
assertTrue($rule07 !== null && $rule07['code'] === 'SGK_EKSIK_GUN_KODU_CAKISTI', '07 0/0 fixture kurali');

// --- S98 mevzuat rules ---
$r07 = SgkKatalogContracts::assert07PuantajRules([
    'eksik_gun_kodu' => '07',
    'prim_gun' => 0,
    'kazanc' => 0,
    'kismi_sureli_sozlesme_var_mi' => false,
    'saat_75_bolme_kullanildi_mi' => true,
    'puantaj_imzali_mi' => false,
    'calisma_gunu_sayisi' => 6,
    'hafta_tatili_degerlendirildi_mi' => false,
]);
assertTrue(count($r07) >= 4, '07 puantaj coklu blocker');
assertTrue(SgkKatalogContracts::assert07ImzaliGunTamGun(true, true) === 'TAM_GUN', '07 imzali gun tam gun');

$r20 = SgkKatalogContracts::assert20UcretsizYolIzniRules([
    'eksik_gun_kodu' => '20',
    'eksik_gun_sayisi' => 5,
    'prim_gun' => 0,
    'kazanc' => 0,
    'isci_talebi_var_mi' => false,
    'yillik_izin_baska_yer_belgesi_var_mi' => false,
]);
assertTrue(count($r20) >= 3, '20 yol izni blockerleri');

$c18 = SgkKatalogContracts::assert1827Combination(['18']);
assertTrue($c18['sonuc_eksik_gun_kodu'] === '18', 'yalniz kisa calisma -> 18');
$c27 = SgkKatalogContracts::assert1827Combination(['18', '15'], '12');
assertTrue($c27['sonuc_eksik_gun_kodu'] === '27', '18+baska -> 27');
assertTrue($c27['blocker_detaylari'] !== [], '18+baska sonuc 12 RED');

$c18v = SgkCokluNedenValidator::validate(['kodlar' => ['18'], 'kurallar' => []]);
assertTrue($c18v['sonuc_eksik_gun_kodu'] === '18' && $c18v['gecerli_mi'] === true, 'coklu 18 sonucu');
$c27v = SgkCokluNedenValidator::validate(['kodlar' => ['18', '01'], 'kurallar' => [], 'sonuc_eksik_gun_kodu' => '12']);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_KOD_KURAL, $c27v['blocker_kodlari'], true), 'coklu 18+x sonuc 12 blocker');

$yOk = SgkKatalogContracts::assertYabanciKodIzni(['eksik_gun_kodu' => '01', 'yabanci_uyruklu_mu' => true]);
assertTrue($yOk === [], 'yabanci temel 01 izinli');
$y26 = SgkKatalogContracts::assertYabanciKodIzni(['eksik_gun_kodu' => '26', 'yabanci_uyruklu_mu' => true]);
assertTrue($y26 !== [], '26 baglamsiz RED');
$y26ok = SgkKatalogContracts::assertYabanciKodIzni([
    'eksik_gun_kodu' => '26',
    'yabanci_uyruklu_mu' => true,
    'kismi_istihdam_izinli_mi' => true,
]);
assertTrue($y26ok === [], '26 izinli kismi baglam OK');
$y07 = SgkKatalogContracts::assertYabanciKodIzni(['eksik_gun_kodu' => '07', 'yabanci_uyruklu_mu' => true]);
assertTrue($y07 !== [], '07 yabanci otomatik izinli degil');
$yAnalik = SgkKatalogContracts::assertYabanciKodIzni([
    'eksik_gun_kodu' => '13',
    'yabanci_uyruklu_mu' => true,
    'analik_4857_74_baglami_mi' => true,
]);
assertTrue($yAnalik !== [], 'analik 4857-74 otomatik izinli degil');

$histAktif = SgkKatalogContracts::assertKod22_29EvidenceGate('28', [
    'aktiflik_durumu' => 'AKTIF',
    'portal_teyit_durumu' => 'TEYIT_EDILDI',
    'gecerlilik_baslangic' => '2020-04-01',
    'gecerlilik_bitis' => '2021-07-01',
], $manifest);
assertTrue(in_array('TARIHSEL_KOD_AKTIF_RED', $histAktif, true), '28 AKTIF RED');

$histOk = SgkKatalogContracts::assertKod22_29EvidenceGate('28', [
    'aktiflik_durumu' => 'TARIHSEL',
    'portal_teyit_durumu' => 'TARIHSEL',
    'gecerlilik_baslangic' => '2020-04-01',
    'gecerlilik_bitis' => '2021-07-01',
], $manifest);
assertTrue($histOk === [], '28 TARIHSEL + aralik + manifest kabul');

$histNoDate = SgkKatalogContracts::assertKod22_29EvidenceGate('29', [
    'aktiflik_durumu' => 'TARIHSEL',
    'portal_teyit_durumu' => 'TARIHSEL',
    'gecerlilik_baslangic' => '2020-04-01',
], $manifest);
assertTrue(in_array('TARIHSEL_YURURLUK_ARALIGI_EKSIK', $histNoDate, true), '29 tarih yok RED');

$legacyProj = SgkKatalogContracts::projectCanonicalToLegacy([
    'sifir_gun_sifir_kazanc_durumu' => 'KOSULLU',
    'aktiflik_durumu' => 'PORTAL_TEYIT_BEKLIYOR',
    'portal_teyit_durumu' => 'TEYIT_BEKLIYOR',
    'belge_saklama_ibraz_durumu' => 'ISVERENCE_SAKLA_TALEPTE_IBRAZ',
]);
assertTrue($legacyProj['sifir_gun_sifir_kazanc_kullanilabilir_mi'] === false, 'KOSULLU legacy true uretemez');
assertTrue($legacyProj['aktif_mi'] === false, 'portal teyit bekleyen aktif_mi false');
assertTrue($legacyProj['blockers'] !== [], 'KOSULLU legacy blocker');

$conflict = SgkKatalogContracts::assertLegacyCanonicalConsistency([
    'sifir_gun_sifir_kazanc_durumu' => 'TEYITSIZ',
    'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true,
]);
assertTrue($conflict !== [], 'TEYITSIZ + legacy true celiski');

$r28import = SgkKatalogImportValidator::dryRun([
    'rows' => [array_merge($rowBase, [
        'eksik_gun_kodu' => '28',
        'aktif_mi' => false,
        'aktiflik_durumu' => 'TARIHSEL',
        'portal_teyit_durumu' => 'TARIHSEL',
        'gecerlilik_baslangic' => '2020-04-01',
        'gecerlilik_bitis' => '2021-07-01',
        'sifir_gun_sifir_kazanc_durumu' => 'YASAK',
        'sifir_gun_sifir_kazanc_kullanilabilir_mi' => false,
    ])],
    'manifests' => [$manifest],
]);
assertTrue(($r28import['hatali_satirlar'] ?? []) === [], 'import 28 tarihsel kabul');

$tamlikPortal = SgkKatalogTamlikService::evaluate([
    'kod_satirlari' => [[
        'eksik_gun_kodu' => '01',
        'sifir_gun_sifir_kazanc_durumu' => 'TEYITSIZ',
        'yabanci_kullanim_durumu' => 'TEYITSIZ',
        'aktiflik_durumu' => 'PORTAL_TEYIT_BEKLIYOR',
        'portal_teyit_durumu' => 'TEYIT_BEKLIYOR',
        'gecerlilik_baslangic' => '2020-01-01',
        'kaynak_manifest_id' => 'MAN_1',
    ]],
    'manifests' => [$manifest],
    'expert_draft_tek_basina_mi' => true,
]);
assertTrue(in_array('TEYITSIZ_SIFIR_GUN:01', $tamlikPortal['eksik_kanitlar'], true), 'tamlik TEYITSIZ');
assertTrue(in_array('PORTAL_TEYIT_BEKLIYOR:01', $tamlikPortal['eksik_kanitlar'], true), 'tamlik portal bekliyor');
assertTrue(in_array('EXPERT_DRAFT_TEK_BASINA_YETERSIZ', $tamlikPortal['eksik_kanitlar'], true), 'expert draft yetersiz');
assertTrue($tamlikPortal['tamlik_durumu'] === 'TASLAK', 'portal bekleyen expert draft TASLAK');
assertTrue($tamlikPortal['onaylanabilir_mi'] === false, 'tamlik expert draft onaylanamaz');
assertTrue(in_array('EXPERT_DRAFT_TEK_BASINA_YETERSIZ', $tamlikPortal['limited_blocker_kodlari'] ?? $tamlikPortal['eksik_kanitlar'], true), 'expert draft limited blocker');

// --- Esleme ---
$e1 = SgkSurecKodEslemeValidator::validate(['surec_turu' => 'RAPOR', 'mappings' => []]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_SUREC_BULUNAMADI, $e1['blocker_kodlari'], true), 'esleme yok');

$e2 = SgkSurecKodEslemeValidator::validate([
    'surec_turu' => 'RAPOR',
    'alt_tur' => 'X',
    'mappings' => [
        ['surec_turu' => 'RAPOR', 'alt_tur' => 'X', 'eksik_gun_kodu' => '01', 'kaynak_manifest_id' => 'MAN_1'],
        ['surec_turu' => 'RAPOR', 'alt_tur' => 'X', 'eksik_gun_kodu' => '15', 'kaynak_manifest_id' => 'MAN_1'],
    ],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_SUREC_CAKISTI, $e2['blocker_kodlari'], true), 'esleme cakisma');

$e3 = SgkSurecKodEslemeValidator::validate([
    'surec_turu' => 'RAPOR',
    'alt_tur' => 'Y',
    'mappings' => [
        ['surec_turu' => 'RAPOR', 'alt_tur' => '*', 'eksik_gun_kodu' => '01', 'kaynak_manifest_id' => 'MAN_1'],
    ],
]);
assertTrue($e3['esleme_modu'] === 'WILDCARD' && $e3['gecerli_mi'] === true, 'esleme wildcard');

$e4 = SgkSurecKodEslemeValidator::validate([
    'surec_turu' => 'RAPOR',
    'canonical_surec_turu' => 'BILINMEYEN',
    'mappings' => [],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_SUREC_BULUNAMADI, $e4['blocker_kodlari'], true), 'esleme bilinmeyen surec');

$e5 = SgkSurecKodEslemeValidator::validate([
    'surec_turu' => 'RAPOR',
    'alt_tur' => 'Z',
    'mappings' => [
        ['surec_turu' => 'RAPOR', 'alt_tur' => 'Z', 'eksik_gun_kodu' => '01'],
    ],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_SUREC_KAYNAK, $e5['blocker_kodlari'], true), 'esleme kaynak eksik');

// --- Coklu neden ---
$c1 = SgkCokluNedenValidator::validate(['kodlar' => ['01', '15'], 'kurallar' => []]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_COKLU_BULUNAMADI, $c1['blocker_kodlari'], true), 'coklu sonuc yok');

$c2 = SgkCokluNedenValidator::validate([
    'kodlar' => ['15', '01', '01'],
    'kurallar' => [
        ['kaynak_kodlar' => ['01', '15'], 'sonuc_eksik_gun_kodu' => '12'],
        ['kaynak_kodlar' => ['01', '15'], 'sonuc_eksik_gun_kodu' => '13'],
    ],
]);
assertTrue($c2['kaynak_kod_set_hash'] === SgkCokluNedenValidator::validate(['kodlar' => ['01', '15'], 'kurallar' => []])['kaynak_kod_set_hash'], 'coklu siralama/duplicate normalize');
assertTrue(in_array(SgkKatalogContracts::BLOCKER_COKLU_CAKISTI, $c2['blocker_kodlari'], true), 'coklu cakisma');

// --- Operasyonel ---
$bytes = 'hello-kanit';
$opOk = SgkOperasyonelKanitValidator::validate([
    'dosya_adi' => 'a.png',
    'sha256' => hash('sha256', $bytes),
    'byte_boyutu' => strlen($bytes),
], $bytes);
assertTrue($opOk['gecerli_mi'] === true && $opOk['mevzuat_kaynagi_mi'] === false, 'op hash dogru');

$opBad = SgkOperasyonelKanitValidator::validate([
    'dosya_adi' => 'a.png',
    'sha256' => str_repeat('d', 64),
], $bytes);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_OP_KANIT, $opBad['blocker_kodlari'], true), 'op hash yanlis');

$opMiss = SgkOperasyonelKanitValidator::validate([
    'dosya_adi' => 'a.png',
    'sha256' => str_repeat('e', 64),
    'dosya_erisilebilir_mi' => false,
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_OP_KANIT, $opMiss['blocker_kodlari'], true), 'op dosya erisilemez');

$opMev = SgkOperasyonelKanitValidator::validate([
    'dosya_adi' => 'a.png',
    'sha256' => hash('sha256', $bytes),
    'mevzuat_kaynagi_mi' => true,
], $bytes);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $opMev['blocker_kodlari'], true), 'op mevzuat authority reddi');
assertTrue($opMev['mevzuat_kaynagi_mi'] === false, 'op her zaman mevzuat false');

// --- Operasyonel Base64 guard (S85-C1.1) ---
assertTrue(SgkOperasyonelKanitBase64Guard::MAX_DECODED_BYTES === 10 * 1024 * 1024, 'base64 limit 10MiB canonical');

$bMissing = SgkOperasyonelKanitBase64Guard::resolve(null);
assertTrue($bMissing['ok'] === true && $bMissing['bytes'] === null, 'base64 alan yok → null bytes');

$bEmpty = SgkOperasyonelKanitBase64Guard::resolve('');
assertTrue($bEmpty['ok'] === true && $bEmpty['bytes'] === null, 'base64 bos string → null bytes');

$small = 'kanit-ok';
$bSmall = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($small));
assertTrue($bSmall['ok'] === true && $bSmall['bytes'] === $small, 'base64 gecerli kucuk payload');

$utf8 = "üçğışİUTF8✓";
$bUtf = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($utf8));
assertTrue($bUtf['ok'] === true && $bUtf['bytes'] === $utf8, 'base64 utf8 içerik');

$binary = "\x00\x01\x02\xff\xfe\x80binary";
$bBin = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($binary));
assertTrue($bBin['ok'] === true && $bBin['bytes'] === $binary, 'base64 binary içerik');

$bBadChar = SgkOperasyonelKanitBase64Guard::resolve('@@@not-base64@@@');
assertTrue(
    $bBadChar['ok'] === false
    && $bBadChar['http'] === 422
    && $bBadChar['code'] === SgkOperasyonelKanitBase64Guard::ERROR_BASE64_GECERSIZ,
    'base64 gecersiz karakter'
);

$bPad = SgkOperasyonelKanitBase64Guard::resolve('YQ='); // "a" without proper padding length
assertTrue(
    $bPad['ok'] === false
    && $bPad['http'] === 422
    && $bPad['code'] === SgkOperasyonelKanitBase64Guard::ERROR_BASE64_GECERSIZ,
    'base64 hatali padding'
);

$bWs = SgkOperasyonelKanitBase64Guard::resolve(base64_encode('x') . "\n");
assertTrue(
    $bWs['ok'] === false
    && $bWs['code'] === SgkOperasyonelKanitBase64Guard::ERROR_BASE64_GECERSIZ,
    'base64 whitespace/newline reddedilir (strip yok)'
);

$bNonCanon = SgkOperasyonelKanitBase64Guard::resolve('YWJjZA'); // "abcd" without padding (len 6)
assertTrue(
    $bNonCanon['ok'] === false
    && $bNonCanon['code'] === SgkOperasyonelKanitBase64Guard::ERROR_BASE64_GECERSIZ,
    'base64 non-canonical / hatali uzunluk'
);

$max = SgkOperasyonelKanitBase64Guard::MAX_DECODED_BYTES;
$underBytes = str_repeat('U', $max - 1);
$bUnder = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($underBytes));
assertTrue($bUnder['ok'] === true && strlen((string) $bUnder['bytes']) === $max - 1, 'base64 limit-1 kabul');

$exactBytes = str_repeat('E', $max);
$bExact = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($exactBytes));
assertTrue($bExact['ok'] === true && strlen((string) $bExact['bytes']) === $max, 'base64 limit tam kabul');

$overBytes = str_repeat('O', $max + 1);
$bOver = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($overBytes));
assertTrue(
    $bOver['ok'] === false
    && $bOver['http'] === 413
    && $bOver['code'] === SgkOperasyonelKanitBase64Guard::ERROR_DOSYA_BOYUTU_ASILDI
    && ($bOver['meta']['limit_byte'] ?? null) === $max
    && (($bOver['meta']['byte_sayisi'] ?? null) === $max + 1 || ($bOver['meta']['tahmini_byte'] ?? 0) > $max),
    'base64 limit+1 413'
);
assertTrue(
    !isset($bOver['meta']['payload'])
    && strpos(json_encode($bOver), base64_encode($overBytes)) === false,
    'base64 size hata payload sizdirmaz'
);

$hugeEncoded = str_repeat('A', SgkOperasyonelKanitBase64Guard::maxEncodedLength() + 4);
$bHuge = SgkOperasyonelKanitBase64Guard::resolve($hugeEncoded);
assertTrue(
    $bHuge['ok'] === false
    && $bHuge['http'] === 413
    && $bHuge['code'] === SgkOperasyonelKanitBase64Guard::ERROR_DOSYA_BOYUTU_ASILDI
    && isset($bHuge['meta']['tahmini_byte']),
    'base64 cok buyuk encoded decode oncesi reddedilir'
);

$reHashBytes = 'sha-rehash-proof';
$resolvedHash = SgkOperasyonelKanitBase64Guard::resolve(base64_encode($reHashBytes));
$opHash = SgkOperasyonelKanitValidator::validate([
    'dosya_adi' => 'proof.bin',
    'sha256' => str_repeat('0', 64),
    'byte_boyutu' => 1,
], $resolvedHash['bytes']);
assertTrue($opHash['sha256'] === hash('sha256', $reHashBytes), 'base64 sonrasi SHA256 re-hash');
assertTrue($opHash['byte_boyutu'] === strlen($reHashBytes), 'base64 sonrasi gercek byte');

$controllerOpSrc = file_get_contents(__DIR__ . '/../../api/src/Controllers/SgkKatalogHazirlikController.php');
assertTrue(
    strpos($controllerOpSrc, 'SgkOperasyonelKanitBase64Guard::resolve') !== false,
    'controller base64 guard kullanir'
);
assertTrue(strpos($controllerOpSrc, "self::context(\$request, 'mevzuat_parametreleri.view')") !== false, 'op endpoint auth/permission korur');
assertTrue(!preg_match('/file_put_contents|fwrite\s*\(|INSERT\s+INTO|UPDATE\s+/i', $controllerOpSrc), 'op endpoint write yapmaz');
assertTrue(strpos($controllerOpSrc, 'operasyonel_kanit_max_decoded_bytes') !== false, 'op response limit metadata gosterir');
assertTrue(strpos($controllerOpSrc, 'base64_decode($body') === false, 'controller dogrudan base64_decode kullanmaz');

// --- Onay ---
$o1 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAY_BEKLIYOR',
    'action' => 'APPROVE',
    'actor_id' => 5,
    'tamlik' => ['tamlik_durumu' => 'RESMI_KAYNAKLI_KISITLI', 'onaylanabilir_mi' => true, 'blocker_kodlari' => []],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_ATTESTATION, $o1['blocker_kodlari'], true), 'onay attestation eksik');

$o1b = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAY_BEKLIYOR',
    'action' => 'APPROVE',
    'actor_id' => 5,
    'resmi_kaynaklar_incelendi_mi' => true,
    'belirsiz_tarihler_uydurulmadi_mi' => true,
    'kisitli_kullanim_kabul_edildi_mi' => true,
    'tamlik' => ['tamlik_durumu' => 'RESMI_KAYNAKLI_KISITLI', 'onaylanabilir_mi' => true, 'blocker_kodlari' => []],
]);
assertTrue($o1b['allowed_mi'] === true, 'onay kisitli attestation ile allowed');
assertTrue($o1b['yazma_aktif_mi'] === true, 'onay yazma aktif');

$o2 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'TASLAK',
    'action' => 'SUBMIT',
    'tamlik' => $t1,
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $o2['blocker_kodlari'], true), 'onay submit tamlik blocker');

$o3 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAY_BEKLIYOR',
    'action' => 'APPROVE',
    'actor_id' => 2,
    'resmi_kaynaklar_incelendi_mi' => true,
    'belirsiz_tarihler_uydurulmadi_mi' => false,
    'kisitli_kullanim_kabul_edildi_mi' => true,
    'tamlik' => ['tamlik_durumu' => 'RESMI_KAYNAKLI_KISITLI', 'onaylanabilir_mi' => true, 'blocker_kodlari' => []],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_ATTESTATION, $o3['blocker_kodlari'], true), 'onay belirsiz tarih attestation eksik');

$o4 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'TASLAK',
    'action' => 'SUBMIT',
    'tamlik' => ['tamlik_durumu' => 'TASLAK', 'onaylanabilir_mi' => false, 'blocker_kodlari' => [SgkKatalogContracts::BLOCKER_TAMLIK]],
]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $o4['blocker_kodlari'], true), 'onay submit tamlik TASLAK blocker');

$o4b = SgkKatalogOnayService::validateTransition([
    'current_state' => 'TASLAK',
    'action' => 'SUBMIT',
    'tamlik' => $tLimitedOk,
]);
assertTrue($o4b['allowed_mi'] === true, 'onay submit kisitli allowed');

$o5 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAYLANDI',
    'action' => 'UPDATE',
    'tamlik' => ['tamlik_durumu' => 'DOGRULANMIS_TAM', 'onaylanabilir_mi' => true, 'blocker_kodlari' => []],
]);
assertTrue(in_array('SGK_KATALOG_SURUM_IMMUTABLE', $o5['blocker_kodlari'], true), 'onay immutable');

$o6 = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAYLANDI',
    'action' => 'NEW_VERSION',
    'onceki_surum_kodu' => 'V1',
    'tamlik' => ['tamlik_durumu' => 'TASLAK', 'onaylanabilir_mi' => false, 'blocker_kodlari' => [SgkKatalogContracts::BLOCKER_TAMLIK]],
]);
assertTrue($o6['next_state'] === 'TASLAK' && $o6['allowed_mi'] === true, 'onay yeni surum allowed');
assertTrue($o6['yazma_aktif_mi'] === true, 'onay yeni surum yazma aktif');
$o6b = SgkKatalogOnayService::validateTransition([
    'current_state' => 'ONAYLANDI',
    'action' => 'NEW_VERSION',
    'onceki_surum_kodu' => 'V1',
    'tamlik' => ['tamlik_durumu' => 'TASLAK', 'onaylanabilir_mi' => false, 'blocker_kodlari' => [SgkKatalogContracts::BLOCKER_TAMLIK]],
]);
assertTrue($o6['response_hash'] === $o6b['response_hash'], 'onay response_hash deterministic');
assertTrue($o6['muhur']['muhur_zamani'] === null && $o6['muhur']['muhur_uygulandi_mi'] === false, 'onay muhur uygulanmadi');

// --- Preview / belge ---
$k = SgkKatalogPreviewService::kismiSureliPreview([]);
assertTrue($k['hesap_sonucu_uretildi_mi'] === false && $k['saat_bol_7_5_kullanildi_mi'] === false, 'kismi hesap yok');
assertTrue(in_array(SgkKatalogContracts::BLOCKER_KISMI_KURAL, $k['blocker_kodlari'], true), 'kismi kural blocker');

$b = SgkKatalogPreviewService::bildirimDonemiPreview(['bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14']);
assertTrue($b['varsayilan_15_14_uygulandi_mi'] === false, 'bildirim 15-14 varsaymaz');
assertTrue(in_array(SgkKatalogContracts::BLOCKER_BILDIRIM, $b['blocker_kodlari'], true), 'bildirim blocker');

$belge = SgkBelgeGereksinimValidator::validate(['eksik_gun_kodu' => '01', 'belge_matrisi' => []]);
assertTrue(in_array(SgkKatalogContracts::BLOCKER_TAMLIK, $belge['blocker_kodlari'], true), 'belge matrisi eksik');

// --- Manifest storage vs empty catalog ---
$emptyStmt = new class {
    public function fetchAll(int $mode = 0): array
    {
        return [];
    }
};
assertTrue(SgkKaynakManifestReader::hydrate($emptyStmt) === [], 'manifest hydrate basarili bos []');

$storageFromFalse = false;
try {
    SgkKaynakManifestReader::hydrate(false);
} catch (RuntimeException $e) {
    $storageFromFalse = $e->getMessage() === SgkKaynakManifestReader::STORAGE_ERROR_CODE;
}
assertTrue($storageFromFalse, 'hydrate(false) STORAGE_HATASI');

$wrapped = SgkKaynakManifestReader::storageError(new PDOException('SQLSTATE[42S02]: no such table: secret'));
assertTrue($wrapped->getMessage() === SgkKaynakManifestReader::STORAGE_ERROR_CODE, 'storageError kodu sabit');
assertTrue(strpos($wrapped->getMessage(), 'secret') === false, 'storageError SQL sizdirmaz');
assertTrue($wrapped->getPrevious() instanceof PDOException, 'storageError previous korur');

$pdoLeak = new PDOException(
    'SQLSTATE[HY000] [1045] Access denied for user \'db_user\'@\'db_host\' (using password: YES) DSN=mysql:host=db_host;dbname=db_name SELECT * FROM sgk_kaynak_manifestleri',
    1045
);
$pdoLeak->errorInfo = ['28000', 1045, 'Access denied for user \'db_user\'@\'db_host\' (using password: YES)'];
$wrappedLeak = SgkKaynakManifestReader::storageError($pdoLeak);
$logLine = SgkKaynakManifestReader::formatSanitizedRuntimeLog('kaynaklar', $wrappedLeak, 'Medisa\\Api\\Controllers\\SgkKatalogHazirlikController');
assertTrue(strpos($logLine, 'SGK_KATALOG_RUNTIME_EXCEPTION') === 0, 'log event code ile baslar');
assertTrue(strpos($logLine, 'action=kaynaklar') !== false, 'log action');
assertTrue(strpos($logLine, 'exception_class=PDOException') !== false, 'log exception class');
assertTrue(strpos($logLine, 'exception_code=1045') !== false, 'log exception code');
assertTrue(strpos($logLine, 'sqlstate=28000') !== false, 'log sqlstate');
assertTrue(strpos($logLine, 'driver_code=1045') !== false, 'log driver code');
assertTrue(strpos($logLine, 'owner_class=SgkKatalogHazirlikController') !== false, 'log owner class');
assertTrue(strpos($logLine, 'file=') !== false, 'log file basename key');
assertTrue(preg_match('/\bline=\d+/', $logLine) === 1, 'log line number');
foreach ([
    'db_host',
    'db_name',
    'db_user',
    'db_password',
    'mysql:host=',
    'SELECT * FROM',
    'Access denied',
    'using password',
    'password: YES',
    'sgk_kaynak_manifestleri',
    'stack',
    'trace',
] as $forbidden) {
    assertTrue(stripos($logLine, $forbidden) === false, 'log secret/sizinti yok: ' . $forbidden);
}
assertTrue(strpos($logLine, $pdoLeak->getMessage()) === false, 'log ham exception message yok');

$hydrateFail = null;
try {
    SgkKaynakManifestReader::hydrate(false);
} catch (RuntimeException $e) {
    $hydrateFail = $e;
}
assertTrue($hydrateFail instanceof RuntimeException, 'hydrate fail exception');
$hydrateLog = SgkKaynakManifestReader::formatSanitizedRuntimeLog('tamlik', $hydrateFail, SgkKaynakManifestReader::class);
assertTrue(strpos($hydrateLog, 'SGK_KATALOG_RUNTIME_EXCEPTION') === 0, 'hydrate log event');
assertTrue(strpos($hydrateLog, 'exception_class=RuntimeException') !== false, 'hydrate log class');
assertTrue(strpos($hydrateLog, SgkKaynakManifestReader::STORAGE_ERROR_CODE) === false, 'hydrate log message kodu sizdirmaz');

$contractsSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/SgkKatalogContracts.php');
assertTrue(strpos($contractsSrc, 'RESMI_KAYNAKLI_KISITLI') !== false, 'contracts TAMLIK_DURUMU');
assertTrue(strpos($contractsSrc, 'GECERLILIK_TARIH_DURUMU') !== false, 'contracts GECERLILIK_TARIH_DURUMU');

$writeSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/SgkKatalogWriteService.php');
assertTrue(strpos($writeSrc, 'beginTransaction') !== false, 'write service transaction');
assertTrue(strpos($writeSrc, 'SgkKararPaketiAuthz::assertPrepare') !== false, 'write service prepare authz');
assertTrue(strpos($writeSrc, 'SgkKararPaketiAuthz::assertApprove') !== false, 'write service approve authz');

$routerSrc = file_get_contents(__DIR__ . '/../../api/src/Router.php');
assertTrue(strpos($routerSrc, '/sgk-katalog-hazirlik/import') !== false, 'router import route');
assertTrue(strpos($routerSrc, '/sgk-katalog-hazirlik/submit') !== false, 'router submit route');
assertTrue(strpos($routerSrc, '/sgk-katalog-hazirlik/approve') !== false, 'router approve route');

$controllerSrc = file_get_contents(__DIR__ . '/../../api/src/Controllers/SgkKatalogHazirlikController.php');
assertTrue(strpos($controllerSrc, 'SgkKatalogWriteService::import') !== false, 'controller import wired');
assertTrue(!preg_match('/catch\s*\([^)]+\)\s*\{\s*return\s*\[\];/s', $controllerSrc), 'controller catch-return-empty yok');
assertTrue(strpos($controllerSrc, 'SgkKaynakManifestReader::fetchAll') !== false, 'controller reader kullanir');
assertTrue(strpos($controllerSrc, 'SgkKaynakManifestReader::STORAGE_ERROR_CODE') !== false, 'controller 503 storage kodu');
assertTrue(strpos($controllerSrc, 'SgkKaynakManifestReader::formatSanitizedRuntimeLog') !== false, 'controller sanitized log');
assertTrue(strpos($controllerSrc, 'error_log(') !== false, 'controller error_log cagirir');

$readerSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/SgkKaynakManifestReader.php');
assertTrue(strpos($readerSrc, 'str_contains(') === false, 'reader str_contains kullanmaz');
assertTrue(strpos($readerSrc, 'str_starts_with(') === false, 'reader str_starts_with kullanmaz');
assertTrue(strpos($readerSrc, 'str_ends_with(') === false, 'reader str_ends_with kullanmaz');
assertTrue(strpos($readerSrc, '$target::class') === false, 'reader target::class kullanmaz');
assertTrue(strpos($readerSrc, 'get_class($target)') !== false, 'reader get_class kullanir');
assertTrue(strpos($readerSrc, 'hydrate(mixed $stmt)') === false, 'reader hydrate mixed type yok');
assertTrue(strpos($readerSrc, 'function hydrate($stmt): array') !== false, 'reader hydrate PHP7 imza');
assertTrue(strpos($readerSrc, '@param mixed $stmt') !== false, 'reader hydrate mixed PHPDoc');

$ownerFiles = [
    __DIR__ . '/../../api/src/Services/Payroll/SgkKaynakManifestReader.php',
    __DIR__ . '/../../api/src/Controllers/SgkKatalogHazirlikController.php',
    __DIR__ . '/../../api/src/Database/Connection.php',
    __DIR__ . '/../../api/src/Router.php',
];
foreach ($ownerFiles as $ownerPath) {
    $ownerSrc = file_get_contents($ownerPath);
    $base = basename($ownerPath);
    assertTrue(strpos($ownerSrc, 'str_contains(') === false, $base . ' str_contains yok');
    assertTrue(strpos($ownerSrc, 'str_starts_with(') === false, $base . ' str_starts_with yok');
    assertTrue(strpos($ownerSrc, 'str_ends_with(') === false, $base . ' str_ends_with yok');
    assertTrue(!preg_match('/function\s+\w+\s*\([^)]*\bmixed\s+\$/', $ownerSrc), $base . ' mixed param type yok');
}
assertTrue(strpos($controllerSrc, "'manifests' => self::loadManifests(\$pdo, 'onay_validate')") !== false, 'onayValidate DB manifest okur');
assertTrue(!preg_match('/error_log\([^;]*getMessage|error_log\([^;]*getTraceAsString|error_log\(\s*\$e\b/', $controllerSrc), 'controller raw exception loglamaz');

// surumler reads DB surum list; must not load manifests
$surumPos = strpos($controllerSrc, 'function surumler(Request $request)');
assertTrue($surumPos !== false, 'surumler method mevcut');
$surumChunk = substr($controllerSrc, $surumPos, 2200);
assertTrue(strpos($surumChunk, 'loadManifests') === false, 'surumler loadManifests cagirmaz');
assertTrue(strpos($surumChunk, 'self::context(') !== false, 'surumler context kullanir');
assertTrue(strpos($surumChunk, 'sgk_eksik_gun_katalog_surumleri') !== false, 'surumler DB surum tablosunu okur');
assertTrue(strpos($controllerSrc, 'storedApprovedTamlik') !== false, 'tamlik kayitli ONAYLANDI snapshot okur');
assertTrue(strpos($writeSrc, 'function storedApprovedTamlik') !== false, 'WriteService storedApprovedTamlik');

// --- S106 canonical 19-pack dry-run ---
$canonicalPath = __DIR__ . '/../../ops/sgk/S106-SGK-EKSIK-GUN-19-CANONICAL.json';
assertTrue(is_file($canonicalPath), 'S106 canonical paket dosyasi');
$pkg = json_decode((string) file_get_contents($canonicalPath), true);
assertTrue(is_array($pkg) && isset($pkg['rows'], $pkg['manifests']), 'S106 canonical parse');
assertTrue(count($pkg['rows']) === 19, 'S106 19 satir');
$exact = ['01','03','04','05','06','07','08','09','10','11','12','13','15','16','17','18','19','20','21'];
$pkgCodes = array_map(static fn ($r) => (string) $r['eksik_gun_kodu'], $pkg['rows']);
sort($pkgCodes);
$exactSorted = $exact;
sort($exactSorted);
assertTrue($pkgCodes === $exactSorted, 'S106 exact kod seti');
assertTrue(count(array_unique($pkgCodes)) === 19, 'S106 unique 19');
foreach ($pkg['rows'] as $r) {
    assertTrue(array_key_exists('gecerlilik_baslangic', $r) && $r['gecerlilik_baslangic'] === null, 'S106 baslangic null');
    assertTrue(($r['gecerlilik_tarih_durumu'] ?? '') === 'BELIRLENEMEDI', 'S106 BELIRLENEMEDI');
}
$kod07 = null;
foreach ($pkg['rows'] as $r) {
    if (($r['eksik_gun_kodu'] ?? '') === '07') {
        $kod07 = $r;
        break;
    }
}
assertTrue($kod07 !== null && ($kod07['sifir_gun_sifir_kazanc_durumu'] ?? '') === 'YASAK', 'S106 kod 07 YASAK');

$s106Dry = SgkKatalogImportValidator::dryRun([
    'format' => 'JSON',
    'rows' => $pkg['rows'],
    'manifests' => $pkg['manifests'],
    'tamlik' => $pkg['tamlik_flags'] ?? [],
]);
assertTrue(count($s106Dry['gecerli_satirlar']) === 19, 'S106 dry-run valid=19');
assertTrue(count($s106Dry['hatali_satirlar']) === 0, 'S106 dry-run invalid=0');
assertTrue(($s106Dry['tamlik']['tamlik_durumu'] ?? '') === 'RESMI_KAYNAKLI_KISITLI', 'S106 dry-run kisitli');
assertTrue(($s106Dry['import_yapilabilir_mi'] ?? false) === true, 'S106 dry-run import aktif');
assertTrue(($s106Dry['tamlik']['onaylanabilir_mi'] ?? false) === true, 'S106 dry-run onaylanabilir');

$s106FullReject = SgkKatalogTamlikService::evaluate(array_merge($pkg['tamlik_flags'] ?? [], [
    'katalog_surumu' => $pkg['katalog_surumu'],
    'manifests' => $pkg['manifests'],
    'kod_satirlari' => $pkg['rows'],
    'istenen_tamlik_durumu' => 'DOGRULANMIS_TAM',
]));
assertTrue(($s106FullReject['dogrulanmis_tam_secilebilir_mi'] ?? true) === false, 'S106 DOGRULANMIS_TAM secilemez');
assertTrue(($s106FullReject['tamlik_durumu'] ?? '') === 'RESMI_KAYNAKLI_KISITLI', 'S106 istenen full yine kisitli');

echo 'verify-sgk-katalog-hazirlik: OK' . PHP_EOL;
