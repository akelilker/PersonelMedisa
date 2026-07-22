<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/Payroll/SgkPrimGunuEngine.php';

use Medisa\Api\Services\Payroll\SgkPrimGunuEngine;

function sgkAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @return array<string, mixed> */
function sgkCatalog(array $overrides = []): array
{
    $codes = [
        '01' => ['resmi_aciklama' => 'Istirahat', 'aktif_mi' => true, 'belge_zorunlulugu' => 'ZORUNLU', 'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true],
        '07' => ['resmi_aciklama' => 'Puantaj Kayitlari', 'aktif_mi' => true, 'belge_zorunlulugu' => 'ZORUNLU', 'sifir_gun_sifir_kazanc_kullanilabilir_mi' => false],
        '12' => ['resmi_aciklama' => 'Birden Fazla', 'aktif_mi' => true, 'belge_zorunlulugu' => 'ZORUNLU', 'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true],
        '15' => ['resmi_aciklama' => 'Devamsizlik', 'aktif_mi' => true, 'belge_zorunlulugu' => 'KOSULLU', 'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true],
        '21' => ['resmi_aciklama' => 'Diger Ucretsiz Izin', 'aktif_mi' => true, 'belge_zorunlulugu' => 'ZORUNLU', 'sifir_gun_sifir_kazanc_kullanilabilir_mi' => true],
    ];

    return array_merge([
        'surum_id' => 1,
        'surum_kodu' => 'TEST_DOGRULANMIS_KATALOG_V1',
        'state' => 'ONAYLANDI',
        'tamlik_durumu' => 'DOGRULANMIS_TAM',
        'manifest_hash' => str_repeat('a', 64),
        'kodlar' => $codes,
        'cakismalar' => [],
    ], $overrides);
}

/** @return array<int, array<string, mixed>> */
function fullAttendance(string $from, string $to, int $minutes = 450): array
{
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    $rows = [];
    $id = 1;
    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $isSunday = $date->format('N') === '7';
        $rows[] = [
            'muhur_satir_id' => $id++,
            'tarih' => $date->format('Y-m-d'),
            'gun_tipi' => $isSunday ? 'Hafta_Tatili_Pazar' : 'Normal_Is_Gunu',
            'hareket_durumu' => $isSunday ? 'Gelmedi' : 'Geldi',
            'net_calisma_suresi_dakika' => $isSunday ? 0 : $minutes,
            'hafta_tatili_hak_kazandi_mi' => $isSunday ? true : null,
        ];
    }

    return $rows;
}

/** @return array<string, mixed> */
function sgkInput(string $from, string $to, array $overrides = []): array
{
    return array_merge([
        'donem_baslangic' => $from,
        'donem_bitis' => $to,
        'personel' => [
            'personel_id' => 1,
            'istihdam_baslangic' => $from,
            'istihdam_bitis' => $to,
            'ucret_modeli' => 'MAKTU_AYLIK',
            'sozlesme_turu' => 'TAM_SURELI',
            'sigortalilik_statusu' => '4A',
        ],
        'puantajlar' => fullAttendance($from, $to),
        'surecler' => [],
        'katalog' => sgkCatalog(),
        'gunluk_alt_sinir' => '1000.00',
        'gunluk_ust_sinir' => '7500.00',
        'sinir_mevzuat_surumu' => 'TEST_PEK_V1',
        'sifir_kazanc_mi' => false,
        'bildirim_donem_tipi' => 'AY_1_SON_GUN',
    ], $overrides);
}

/** @return array<string, mixed> */
function processFixture(string $canonical, string $from, string $to, string $code, array $overrides = []): array
{
    return array_merge([
        'surec_id' => random_int(100, 9999),
        'surec_turu' => 'TEST',
        'alt_tur' => $canonical,
        'canonical_surec_turu' => $canonical,
        'prim_gunu_etkisi' => $canonical === 'YILLIK_IZIN' ? 'DAHIL' : 'DUSUR',
        'baslangic_tarihi' => $from,
        'bitis_tarihi' => $to,
        'eksik_gun_kodu' => $code,
        'belge_dogrulandi_mi' => true,
        'kaynak_belge_idleri' => [77],
    ], $overrides);
}

foreach ([
    ['2026-02-01', '2026-02-28', 30, 'Subat 28 tam ay'],
    ['2024-02-01', '2024-02-29', 30, 'Subat 29 tam ay'],
    ['2026-04-01', '2026-04-30', 30, '30 gunluk tam ay'],
    ['2026-03-01', '2026-03-31', 30, '31 gunluk tam ay'],
] as [$from, $to, $expected, $label]) {
    $result = SgkPrimGunuEngine::calculate(sgkInput($from, $to));
    sgkAssert(!$result['manuel_inceleme_gerekli_mi'] && $result['hesaplanan_prim_gunu'] === $expected, $label);
}

$midEntry = sgkInput('2026-03-01', '2026-03-31');
$midEntry['personel']['istihdam_baslangic'] = '2026-03-16';
$midEntryResult = SgkPrimGunuEngine::calculate($midEntry);
sgkAssert($midEntryResult['hesaplanan_prim_gunu'] === 16, 'Ay ortasi giris inclusive 16 gun');

$midExit = sgkInput('2026-03-01', '2026-03-31');
$midExit['personel']['istihdam_bitis'] = '2026-03-15';
$midExitResult = SgkPrimGunuEngine::calculate($midExit);
sgkAssert($midExitResult['hesaplanan_prim_gunu'] === 15, 'Ay ortasi cikis inclusive 15 gun');

$sameMonth = sgkInput('2026-03-01', '2026-03-31');
$sameMonth['personel']['istihdam_baslangic'] = '2026-03-10';
$sameMonth['personel']['istihdam_bitis'] = '2026-03-20';
$sameMonthResult = SgkPrimGunuEngine::calculate($sameMonth);
sgkAssert($sameMonthResult['hesaplanan_prim_gunu'] === 11, 'Ayni ay giris cikis inclusive');

$shiftedPeriod = sgkInput('2026-03-15', '2026-04-14', ['bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14']);
$shiftedPeriodResult = SgkPrimGunuEngine::calculate($shiftedPeriod);
sgkAssert($shiftedPeriodResult['hesaplanan_prim_gunu'] === 30, '15-14 bildirim donemi ayni contract icinde');

$oneHour = sgkInput('2026-03-01', '2026-03-31');
$oneHour['puantajlar'] = fullAttendance('2026-03-01', '2026-03-31', 60);
$oneHourResult = SgkPrimGunuEngine::calculate($oneHour);
sgkAssert($oneHourResult['hesaplanan_prim_gunu'] === 30, 'Tam sureli 1 saat calisilan gun 7.5 saate bolunmez');

foreach ([1, 2, 3, 10] as $days) {
    $to = (new DateTimeImmutable('2026-03-03'))->modify('+' . ($days - 1) . ' day')->format('Y-m-d');
    $input = sgkInput('2026-03-01', '2026-03-31', [
        'surecler' => [processFixture('HASTALIK', '2026-03-03', $to, '01', ['ilk_iki_gun_firma_oder_mi' => true])],
    ]);
    $result = SgkPrimGunuEngine::calculate($input);
    sgkAssert($result['eksik_gun_sayisi'] === max(0, $days - 2), 'Hastalik true ' . $days . ' gun');
}

$sickFalse = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('HASTALIK', '2026-03-03', '2026-03-04', '01', ['ilk_iki_gun_firma_oder_mi' => false])],
]));
sgkAssert($sickFalse['eksik_gun_sayisi'] === 2 && $sickFalse['hesaplanan_prim_gunu'] === 29, 'Hastalik false ilk iki gun kesinti adayi');

$sickNull = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('HASTALIK', '2026-03-03', '2026-03-04', '01', ['ilk_iki_gun_firma_oder_mi' => null])],
]));
sgkAssert(in_array('HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK', $sickNull['blocker_kodlari'], true), 'Hastalik null blocker');

foreach ([1, 2, 3, 10] as $days) {
    $to = (new DateTimeImmutable('2026-03-03'))->modify('+' . ($days - 1) . ' day')->format('Y-m-d');
    $result = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
        'surecler' => [processFixture('IS_KAZASI', '2026-03-03', $to, '01')],
    ]));
    sgkAssert($result['eksik_gun_sayisi'] === $days && !in_array('HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK', $result['blocker_kodlari'], true), 'Is kazasi ' . $days . ' gun ve hastalik politikasindan ayri');
}

foreach (['MESLEK_HASTALIGI', 'ANALIK'] as $type) {
    $result = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
        'surecler' => [processFixture($type, '2026-03-03', '2026-03-05', '01')],
    ]));
    sgkAssert($result['eksik_gun_sayisi'] === 3 && !in_array('HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK', $result['blocker_kodlari'], true), $type . ' hastalik politikasindan ayri');
}

$unpaid = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-03-03', '2026-03-05', '21')],
]));
sgkAssert($unpaid['eksik_gun_sayisi'] === 3 && $unpaid['eksik_gun_kodu'] === '21', 'Ucretsiz izin');

$annual = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('YILLIK_IZIN', '2026-03-03', '2026-03-10', '', ['prim_gunu_etkisi' => 'DAHIL'])],
]));
sgkAssert($annual['hesaplanan_prim_gunu'] === 30 && $annual['eksik_gun_sayisi'] === 0, 'Yillik izin prim gunune dahil');

$absence = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('MAZERETSIZ_DEVAMSIZLIK', '2026-03-03', '2026-03-05', '15')],
]));
sgkAssert($absence['eksik_gun_sayisi'] === 3 && $absence['eksik_gun_kodu'] === '15', 'Mazeretsiz devamsizlik ayri canonical surec');

$holidayInput = sgkInput('2026-03-01', '2026-03-31');
foreach ($holidayInput['puantajlar'] as &$holidayDay) {
    if ($holidayDay['tarih'] === '2026-03-23') {
        $holidayDay['gun_tipi'] = 'UBGT_Resmi_Tatil';
        $holidayDay['hareket_durumu'] = 'Gelmedi';
        $holidayDay['net_calisma_suresi_dakika'] = 0;
    }
}
unset($holidayDay);
$holidayResult = SgkPrimGunuEngine::calculate($holidayInput);
sgkAssert($holidayResult['hesaplanan_prim_gunu'] === 30, 'UBGT ve hak edilmis hafta tatili prim gunune dahil');

$missingDoc = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('IS_KAZASI', '2026-03-03', '2026-03-03', '01', ['belge_dogrulandi_mi' => false])],
]));
sgkAssert(in_array('SGK_EKSIK_GUN_BELGESI_EKSIK', $missingDoc['blocker_kodlari'], true), 'Belge eksik blocker');

foreach ([
    ['override' => ['belge_iptal_mi' => true], 'label' => 'Belge iptal blocker'],
    ['override' => ['belge_hash_uyusmazligi_mi' => true], 'label' => 'Belge hash uyusmazligi blocker'],
] as $documentCase) {
    $documentResult = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
        'surecler' => [processFixture('IS_KAZASI', '2026-03-03', '2026-03-03', '01', $documentCase['override'])],
    ]));
    sgkAssert(in_array('SGK_EKSIK_GUN_BELGESI_EKSIK', $documentResult['blocker_kodlari'], true), $documentCase['label']);
}

$zeroSeven = sgkInput('2026-04-01', '2026-04-30', [
    'sifir_kazanc_mi' => true,
    'surecler' => [processFixture('PUANTAJ_EKSIK_GUN', '2026-04-01', '2026-04-30', '07')],
]);
$zeroSevenResult = SgkPrimGunuEngine::calculate($zeroSeven);
sgkAssert(in_array('SGK_EKSIK_GUN_KODU_CAKISTI', $zeroSevenResult['blocker_kodlari'], true), '07 kodu 0 gun 0 kazanc yasagi');

$multi = sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [
        processFixture('HASTALIK', '2026-03-03', '2026-03-03', '01', ['ilk_iki_gun_firma_oder_mi' => false]),
        processFixture('UCRETSIZ_IZIN', '2026-03-04', '2026-03-04', '21'),
    ],
]);
$multiResult = SgkPrimGunuEngine::calculate($multi);
sgkAssert(in_array('SGK_EKSIK_GUN_KODU_CAKISTI', $multiResult['blocker_kodlari'], true), 'Birden fazla neden resmi birleşik kod yoksa blocker');

$setHash = SgkPrimGunuEngine::hashCanonical(['01', '21']);
$multi['katalog'] = sgkCatalog(['cakismalar' => [$setHash => ['sonuc_eksik_gun_kodu' => '12']]]);
$multiResolved = SgkPrimGunuEngine::calculate($multi);
sgkAssert(!$multiResolved['manuel_inceleme_gerekli_mi'] && $multiResolved['eksik_gun_kodu'] === '12', 'Resmi birleşik kod ile coklu neden cozulur');

$partial = sgkInput('2026-03-01', '2026-03-31');
$partial['personel']['sozlesme_turu'] = 'KISMI_SURELI';
$partial['kismi_sureli_prim_gunu'] = 12;
$partial['surecler'] = [processFixture('KISMI_SURELI_CALISMA', '2026-03-01', '2026-03-31', '07', [
    'sozlesme_belgesi_dogrulandi_mi' => false,
])];
$partialBlocked = SgkPrimGunuEngine::calculate($partial);
sgkAssert(in_array('SGK_EKSIK_GUN_BELGESI_EKSIK', $partialBlocked['blocker_kodlari'], true), 'Kismi sureli sozlesme belgesi eksik');
$partial['surecler'][0]['sozlesme_belgesi_dogrulandi_mi'] = true;
$partialResolved = SgkPrimGunuEngine::calculate($partial);
sgkAssert($partialResolved['hesaplanan_prim_gunu'] === 12, 'Kismi sureli ayri owner sonucu kullanilir');

$invalidCatalog = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'katalog' => sgkCatalog(['state' => 'TASLAK']),
]));
sgkAssert(in_array('SGK_KATALOG_SURUMU_GECERSIZ', $invalidCatalog['blocker_kodlari'], true), 'Gecersiz katalog surumu blocker');

$manifestA = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31'));
$manifestB = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'katalog' => sgkCatalog(['surum_kodu' => 'TEST_DOGRULANMIS_KATALOG_V2', 'manifest_hash' => str_repeat('b', 64)]),
]));
sgkAssert($manifestA['source_hash'] !== $manifestB['source_hash'] && $manifestA['sgk_hesap_hash'] !== $manifestB['sgk_hesap_hash'], 'Katalog veya manifest degisimi source changed hash uretir');

$pek = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-03-03', '2026-03-04', '21')],
]));
sgkAssert($pek['hesaplanan_prim_gunu'] === 29 && $pek['donem_alt_sinir'] === '29000.00' && $pek['donem_ust_sinir'] === '217500.00', 'PEK siniri hesaplanan prim gunu ile olceklenir');

foreach (['MAKTU_AYLIK', 'GUNLUK', 'SAATLIK'] as $model) {
    foreach (['BRUT', 'NET'] as $salaryType) {
        $input = sgkInput('2026-04-01', '2026-04-30', ['ucret_turu' => $salaryType]);
        $input['personel']['ucret_modeli'] = $model;
        $result = SgkPrimGunuEngine::calculate($input);
        sgkAssert($result['hesaplanan_prim_gunu'] === 30, $model . '/' . $salaryType . ' sinir matrisi');
    }
}

$deterministicInput = sgkInput('2026-04-01', '2026-04-30');
$deterministicA = SgkPrimGunuEngine::calculate($deterministicInput);
$deterministicB = SgkPrimGunuEngine::calculate($deterministicInput);
sgkAssert($deterministicA['sgk_hesap_hash'] === $deterministicB['sgk_hesap_hash'], 'Ayni girdi ayni SGK hash');

$firstDayHire = sgkInput('2026-03-01', '2026-03-31');
$firstDayHire['personel']['istihdam_baslangic'] = '2026-03-01';
$firstDayHire['personel']['istihdam_bitis'] = '2026-03-01';
sgkAssert(SgkPrimGunuEngine::calculate($firstDayHire)['hesaplanan_prim_gunu'] === 1, 'Ilk gun is girisi inclusive 1 gun');

$lastDayExit = sgkInput('2026-03-01', '2026-03-31');
$lastDayExit['personel']['istihdam_baslangic'] = '2026-03-31';
$lastDayExit['personel']['istihdam_bitis'] = '2026-03-31';
sgkAssert(SgkPrimGunuEngine::calculate($lastDayExit)['hesaplanan_prim_gunu'] === 1, 'Son gun cikis inclusive 1 gun');

$febMissing = sgkInput('2026-02-01', '2026-02-28', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-02-10', '2026-02-10', '21')],
]);
sgkAssert(SgkPrimGunuEngine::calculate($febMissing)['hesaplanan_prim_gunu'] === 27, 'Subat 28 gunde bir eksik gun fiili hesabi 30a yuvarlanmaz');

$marMissing = sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-03-10', '2026-03-10', '21')],
]);
sgkAssert(SgkPrimGunuEngine::calculate($marMissing)['hesaplanan_prim_gunu'] === 30, '31 gunluk ayda bir eksik gun min(30,30)=30');

$aprMissing = sgkInput('2026-04-01', '2026-04-30', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-04-10', '2026-04-10', '21')],
]);
sgkAssert(SgkPrimGunuEngine::calculate($aprMissing)['hesaplanan_prim_gunu'] === 29, '30 gunluk ayda bir eksik gun 29');

$attendanceOnly = sgkInput('2026-03-01', '2026-03-31');
foreach ($attendanceOnly['puantajlar'] as &$attendanceDay) {
    if ($attendanceDay['tarih'] === '2026-03-04') {
        $attendanceDay['hareket_durumu'] = 'Gelmedi';
        $attendanceDay['net_calisma_suresi_dakika'] = 0;
        $attendanceDay['dayanak'] = 'Yok_Izinsiz';
    }
}
unset($attendanceDay);
$attendanceOnlyResult = SgkPrimGunuEngine::calculate($attendanceOnly);
sgkAssert(in_array('SGK_EKSIK_GUN_KODU_BULUNAMADI', $attendanceOnlyResult['blocker_kodlari'], true), 'Puantaj yoklugu surecsiz 07 tahmin etmez');

$sundayNull = sgkInput('2026-03-01', '2026-03-31');
foreach ($sundayNull['puantajlar'] as &$sundayDay) {
    if ($sundayDay['tarih'] === '2026-03-01') {
        $sundayDay['gun_tipi'] = 'Hafta_Tatili_Pazar';
        $sundayDay['hareket_durumu'] = 'Gelmedi';
        $sundayDay['net_calisma_suresi_dakika'] = 0;
        $sundayDay['hafta_tatili_hak_kazandi_mi'] = null;
    }
}
unset($sundayDay);
sgkAssert(in_array('CANONICAL_TAKVIM_EKSIK', SgkPrimGunuEngine::calculate($sundayNull)['blocker_kodlari'], true), 'Hafta tatili hak edisi null blocker');

$sameDayClash = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [
        processFixture('YILLIK_IZIN', '2026-03-03', '2026-03-03', '', ['prim_gunu_etkisi' => 'DAHIL']),
        processFixture('UCRETSIZ_IZIN', '2026-03-03', '2026-03-03', '21'),
    ],
]));
sgkAssert(in_array('SGK_KAYNAK_SUREC_CELISKILI', $sameDayClash['blocker_kodlari'], true), 'Ayni gun DAHIL+DUSUR surec cakismasi');

$accidentPolicyLeak = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('IS_KAZASI', '2026-03-03', '2026-03-03', '01', ['ilk_iki_gun_firma_oder_mi' => true])],
]));
sgkAssert(in_array('SGK_KAYNAK_SUREC_CELISKILI', $accidentPolicyLeak['blocker_kodlari'], true), 'Is kazasina hastalik politikasi uygulanamaz');

$zeroUnknown = sgkInput('2026-04-01', '2026-04-30', [
    'sifir_kazanc_mi' => null,
    'surecler' => [processFixture('PUANTAJ_EKSIK_GUN', '2026-04-01', '2026-04-30', '07')],
]);
sgkAssert(in_array('SGK_PRIM_GUNU_HESAPLANAMADI', SgkPrimGunuEngine::calculate($zeroUnknown)['blocker_kodlari'], true), '0 prim gununde sifir kazanc belirsizse fail-closed');

$partialNoExplicit = sgkInput('2026-03-01', '2026-03-31');
$partialNoExplicit['personel']['sozlesme_turu'] = 'KISMI_SURELI';
unset($partialNoExplicit['kismi_sureli_prim_gunu']);
sgkAssert(in_array('SGK_PRIM_GUNU_HESAPLANAMADI', SgkPrimGunuEngine::calculate($partialNoExplicit)['blocker_kodlari'], true), 'Kismi sureli saat/7.5 tahmini yapilmaz');

$noCatalogGuess = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'katalog' => sgkCatalog(['kodlar' => []]),
    'surecler' => [processFixture('PUANTAJ_EKSIK_GUN', '2026-03-03', '2026-03-03', '07')],
]));
sgkAssert(in_array('SGK_EKSIK_GUN_KODU_BULUNAMADI', $noCatalogGuess['blocker_kodlari'], true), '07 kodu katalogsuz tahmin edilmez');

$pekOne = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31', [
    'surecler' => [processFixture('UCRETSIZ_IZIN', '2026-03-02', '2026-03-31', '21')],
]));
sgkAssert($pekOne['hesaplanan_prim_gunu'] === 1 && $pekOne['donem_alt_sinir'] === '1000.00', 'PEK 1 prim gunu olceklenir');

$pekThirty = SgkPrimGunuEngine::calculate(sgkInput('2026-03-01', '2026-03-31'));
sgkAssert($pekThirty['hesaplanan_prim_gunu'] === 30 && $pekThirty['donem_ust_sinir'] === '225000.00', 'PEK 30 prim gunu olceklenir');

echo 'verify-sgk-prim-gunu-engine: OK' . PHP_EOL;
