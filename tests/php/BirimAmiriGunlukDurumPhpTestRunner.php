<?php

declare(strict_types=1);

/**
 * SQLite: BIRIM_AMIRI operational roster is OrgScope-fail-closed (own birim only).
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Bildirim\BirimAmiriGunlukDurumService;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;

function bagdFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function bagdOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function bagdAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        bagdFail($msg);
    }
    bagdOk($msg);
}

if (BirimAmiriGunlukDurumService::deriveDurum(null) !== 'HENUZ_DEGERLENDIRILMEDI') {
    bagdFail('null notification is HENUZ_DEGERLENDIRILMEDI');
}
if (BirimAmiriGunlukDurumService::deriveDurum('GEC_GELDI') !== 'GEC_GELDI') {
    bagdFail('GEC_GELDI stays exception');
}
bagdOk('deriveDurum evidence-gated');

$counts = BirimAmiriGunlukDurumService::buildOzetCounts([
    ['durum' => 'GELDI'],
    ['durum' => 'GEC_GELDI'],
    ['durum' => 'GELMEDI'],
    ['durum' => 'IZINLI'],
    ['durum' => 'RAPORLU'],
    ['durum' => 'ERKEN_CIKTI'],
    ['durum' => 'GOREVDE'],
    ['durum' => 'HENUZ_DEGERLENDIRILMEDI'],
]);
bagdAssert($counts['toplam_personel'] === 8, 'count toplam');
bagdAssert($counts['geldi'] === 1, 'count geldi');
bagdAssert($counts['gec_geldi'] === 1, 'count gec');
bagdAssert($counts['gelmedi'] === 1, 'count gelmedi');
bagdAssert($counts['izinli'] === 1, 'count izinli');
bagdAssert($counts['raporlu'] === 1, 'count raporlu');
bagdAssert($counts['izinli_raporlu'] === 2, 'count izinli_raporlu');
bagdAssert($counts['erken_cikti'] === 1, 'count erken');
bagdAssert($counts['gorevde'] === 1, 'count gorevde');
bagdAssert($counts['henuz_degerlendirilmedi'] === 1, 'count henuz');
bagdAssert(BirimAmiriGunlukDurumService::countsSatisfyInvariant($counts), 'count invariant');

$mapped = BirimAmiriGunlukDurumService::mapPersonelRow([
    'personel_id' => 1,
    'ad_soyad' => 'Ahmet Yılmaz',
    'bildirim_turu' => 'GEC_GELDI',
    'dakika' => 18,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'puantaj_giris' => null,
    'puantaj_cikis' => null,
    'puantaj_gec' => null,
    'puantaj_erken' => null,
]);
bagdAssert($mapped['gec_kalma_dakika'] === 18, 'late minutes from notification');
bagdAssert($mapped['durum_label'] === 'Geç Geldi', 'late label');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE personeller (
        id INTEGER PRIMARY KEY,
        ad TEXT,
        soyad TEXT,
        sube_id INTEGER,
        bolum_id INTEGER,
        birim_id INTEGER,
        aktif_durum TEXT,
        ise_giris_tarihi TEXT
    )"
);
$pdo->exec(
    "CREATE TABLE gunluk_bildirimler (
        id INTEGER PRIMARY KEY,
        personel_id INTEGER,
        tarih TEXT,
        state TEXT,
        bildirim_turu TEXT,
        dakika INTEGER,
        baslangic_saati TEXT,
        bitis_saati TEXT
    )"
);
$pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id, bolum_id, birim_id, aktif_durum, ise_giris_tarihi)
    VALUES (1, 'Ayşe', 'Yılmaz', 1, 3, 10, 'AKTIF', '2020-01-01')");
$pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id, bolum_id, birim_id, aktif_durum, ise_giris_tarihi)
    VALUES (2, 'Mehmet', 'Kaya', 2, 6, 20, 'AKTIF', '2020-01-01')");
$pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id, bolum_id, birim_id, aktif_durum, ise_giris_tarihi)
    VALUES (3, 'Pasif', 'Kişi', 1, 3, 10, 'PASIF', '2020-01-01')");
$pdo->exec("INSERT INTO gunluk_bildirimler (id, personel_id, tarih, state, bildirim_turu, dakika)
    VALUES (1, 1, '2026-09-04', 'GONDERILDI', 'GEC_GELDI', 18)");
$pdo->exec("INSERT INTO gunluk_bildirimler (id, personel_id, tarih, state, bildirim_turu, dakika)
    VALUES (2, 2, '2026-09-04', 'GONDERILDI', 'GELMEDI', NULL)");
PersonelOrgStructureSchema::clearReadyCache();

$request = new Request();
$user = [
    'id' => 3,
    'rol' => 'BIRIM_AMIRI',
    'birim_ids' => [10],
    'sube_ids' => [],
    'bolum_ids' => [],
];

$payload = BirimAmiriGunlukDurumService::build($pdo, $user, $request, '2026-09-04');
$ids = array_map(static function ($row) {
    return (int) $row['personel_id'];
}, $payload['personeller']);
bagdAssert($ids === [1], 'only own-unit active personnel');
bagdAssert($payload['ozet']['toplam_personel'] === 1, 'scoped toplam');
bagdAssert($payload['ozet']['gec_geldi'] === 1, 'scoped gec count');
bagdAssert((int) $payload['personeller'][0]['gec_kalma_dakika'] === 18, 'scoped late minutes');

$emptyWhere = [];
$emptyParams = [];
OrgScope::appendPersonelOrgFilter($emptyWhere, $emptyParams, $emptyUser = [
    'id' => 3,
    'rol' => 'BIRIM_AMIRI',
    'birim_ids' => [],
    'sube_ids' => [],
    'bolum_ids' => [],
], null, 'p', 'empty', $pdo);
bagdAssert(in_array('1=0', $emptyWhere, true), 'empty birim_ids fail-closed');

echo "ALL_PASS birim-amiri-gunluk-durum\n";
exit(0);
