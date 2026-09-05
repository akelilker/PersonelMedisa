<?php

declare(strict_types=1);

/**
 * Focused SQLite checks for gunluk_bildirim correction audit + BIRIM_AMIRI parity.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Services\Bildirim\BirimAmiriGunlukDurumService;
use Medisa\Api\Services\Bildirim\BugunPersonelDurumuService;
use Medisa\Api\Services\Bildirim\GunlukBildirimDuzeltmeAuditService;

function gbdaFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function gbdaOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function gbdaAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        gbdaFail($msg);
    }
    gbdaOk($msg);
}

// --- BIRIM_AMIRI / IK parity semantics ---
gbdaAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) === 'HENUZ_DEGERLENDIRILMEDI',
    'shared: no evidence → HENUZ'
);
gbdaAssert(
    BirimAmiriGunlukDurumService::deriveDurum(null) === 'HENUZ_DEGERLENDIRILMEDI',
    'BIRIM_AMIRI deriveDurum parity with HENUZ'
);
gbdaAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, true) === 'GELDI',
    'shared: completion → GELDI'
);
gbdaAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, '08:47', false) === 'GEC_GELDI',
    'shared: 08:47 → GEC_GELDI'
);
gbdaAssert(
    BugunPersonelDurumuService::resolveLateMinutes('08:47', null) === 17,
    'shared: 08:47 → 17 dk'
);
gbdaAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false)
        === BirimAmiriGunlukDurumService::deriveDurum(null),
    'IK / BIRIM_AMIRI same-state parity for no-row'
);

$mappedLate = BirimAmiriGunlukDurumService::mapPersonelRow([
    'personel_id' => 1,
    'ad_soyad' => 'Test',
    'bildirim_turu' => null,
    'dakika' => null,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'puantaj_giris' => '08:47',
    'puantaj_cikis' => null,
    'puantaj_gec' => null,
    'puantaj_erken' => null,
], false);
gbdaAssert($mappedLate['durum'] === 'GEC_GELDI', 'BIRIM_AMIRI attendance 08:47 → GEC_GELDI');
gbdaAssert($mappedLate['gec_kalma_dakika'] === 17, 'BIRIM_AMIRI 17 dk');

$mappedDone = BirimAmiriGunlukDurumService::mapPersonelRow([
    'personel_id' => 2,
    'ad_soyad' => 'Done',
    'bildirim_turu' => null,
    'dakika' => null,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'puantaj_giris' => null,
    'puantaj_cikis' => null,
    'puantaj_gec' => null,
    'puantaj_erken' => null,
], true);
gbdaAssert($mappedDone['durum'] === 'GELDI', 'BIRIM_AMIRI completion + no exception → GELDI');

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
gbdaAssert($counts['toplam_personel'] === 8, 'ozet toplam');
gbdaAssert($counts['henuz_degerlendirilmedi'] === 1, 'ozet henuz');
gbdaAssert($counts['izinli'] === 1 && $counts['raporlu'] === 1, 'ozet izinli/raporlu split');
gbdaAssert($counts['izinli_raporlu'] === 2, 'ozet izinli_raporlu sum');
gbdaAssert(BirimAmiriGunlukDurumService::countsSatisfyInvariant($counts), 'ozet invariant');

// --- Audit table SQLite ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE gunluk_bildirimler (
    id INTEGER PRIMARY KEY,
    personel_id INTEGER NOT NULL,
    sube_id INTEGER NOT NULL,
    tarih TEXT NOT NULL,
    bildirim_turu TEXT NOT NULL,
    alt_tur TEXT,
    baslangic_saati TEXT,
    bitis_saati TEXT,
    dakika INTEGER,
    aciklama TEXT,
    state TEXT NOT NULL,
    correction_reason TEXT
)');
$pdo->exec('CREATE TABLE gunluk_bildirim_duzeltme_auditleri (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gunluk_bildirim_id INTEGER NOT NULL,
    personel_id INTEGER NOT NULL,
    sube_id INTEGER NOT NULL,
    tarih TEXT NOT NULL,
    olay_tipi TEXT NOT NULL,
    actor_user_id INTEGER NOT NULL,
    correction_reason TEXT,
    eski_bildirim_turu TEXT NOT NULL,
    yeni_bildirim_turu TEXT,
    eski_alanlar TEXT NOT NULL,
    yeni_alanlar TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');

$existing = [
    'id' => 10,
    'personel_id' => 5,
    'sube_id' => 1,
    'tarih' => '2026-09-04',
    'bildirim_turu' => 'GELMEDI',
    'alt_tur' => null,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'dakika' => null,
    'aciklama' => 'yok',
    'state' => 'DUZELTME_ISTENDI',
    'correction_reason' => 'sonradan giriş yaptı',
];
$pdo->prepare('INSERT INTO gunluk_bildirimler
    (id, personel_id, sube_id, tarih, bildirim_turu, aciklama, state, correction_reason)
    VALUES (10, 5, 1, \'2026-09-04\', \'GELMEDI\', \'yok\', \'DUZELTME_ISTENDI\', \'sonradan giriş yaptı\')'
)->execute();

$next1 = [
    'bildirim_turu' => 'GEC_GELDI',
    'alt_tur' => null,
    'baslangic_saati' => '08:47',
    'bitis_saati' => null,
    'dakika' => 17,
    'aciklama' => 'yok',
    'state' => 'DUZELTME_ISTENDI',
];
gbdaAssert(
    GunlukBildirimDuzeltmeAuditService::businessFieldsChanged(
        GunlukBildirimDuzeltmeAuditService::businessSnapshot($existing),
        $next1
    ),
    'GELMEDI → GEC_GELDI is a business change'
);

$pdo->beginTransaction();
$id1 = GunlukBildirimDuzeltmeAuditService::appendInTransaction(
    $pdo,
    $existing,
    $next1,
    GunlukBildirimDuzeltmeAuditService::OLAY_DUZELTME,
    42,
    'sonradan giriş yaptı'
);
$pdo->prepare('UPDATE gunluk_bildirimler SET bildirim_turu = :t, baslangic_saati = :b, dakika = :d WHERE id = 10')
    ->execute(['t' => 'GEC_GELDI', 'b' => '08:47', 'd' => 17]);
$pdo->commit();
gbdaAssert($id1 > 0, 'first audit id');

$hist = GunlukBildirimDuzeltmeAuditService::listByBildirimId($pdo, 10);
gbdaAssert(count($hist) === 1, 'one audit after first correction');
gbdaAssert($hist[0]['eski_bildirim_turu'] === 'GELMEDI', 'old tur GELMEDI');
gbdaAssert($hist[0]['yeni_bildirim_turu'] === 'GEC_GELDI', 'new tur GEC_GELDI');
gbdaAssert($hist[0]['actor_user_id'] === 42, 'actor preserved');
gbdaAssert($hist[0]['correction_reason'] === 'sonradan giriş yaptı', 'reason preserved');
gbdaAssert($hist[0]['created_at'] !== '', 'timestamp exists');
gbdaAssert(($hist[0]['eski_alanlar']['dakika'] ?? null) === null, 'eski dakika null');
gbdaAssert((int) ($hist[0]['yeni_alanlar']['dakika'] ?? 0) === 17, 'yeni dakika 17');

$existing2 = [
    'id' => 10,
    'personel_id' => 5,
    'sube_id' => 1,
    'tarih' => '2026-09-04',
    'bildirim_turu' => 'GEC_GELDI',
    'alt_tur' => null,
    'baslangic_saati' => '08:47',
    'bitis_saati' => null,
    'dakika' => 17,
    'aciklama' => 'yok',
    'state' => 'DUZELTME_ISTENDI',
    'correction_reason' => 'rapor ulaştı',
];
$next2 = [
    'bildirim_turu' => 'RAPORLU',
    'alt_tur' => null,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'dakika' => null,
    'aciklama' => 'rapor',
    'state' => 'DUZELTME_ISTENDI',
];
$pdo->beginTransaction();
GunlukBildirimDuzeltmeAuditService::appendInTransaction(
    $pdo,
    $existing2,
    $next2,
    GunlukBildirimDuzeltmeAuditService::OLAY_DUZELTME,
    99,
    'rapor ulaştı'
);
$pdo->commit();
$hist2 = GunlukBildirimDuzeltmeAuditService::listByBildirimId($pdo, 10);
gbdaAssert(count($hist2) === 2, 'second append keeps chain');
gbdaAssert($hist2[1]['eski_bildirim_turu'] === 'GEC_GELDI', 'second old GEC_GELDI');
gbdaAssert($hist2[1]['yeni_bildirim_turu'] === 'RAPORLU', 'second new RAPORLU');
gbdaAssert($hist2[1]['actor_user_id'] === 99, 'second actor');

$same = GunlukBildirimDuzeltmeAuditService::businessSnapshot($existing2);
gbdaAssert(
    !GunlukBildirimDuzeltmeAuditService::businessFieldsChanged($same, $same),
    'no-op identical snapshot'
);

// Audit insert failure rolls back row update
$pdo->beginTransaction();
try {
    $pdo->exec('DROP TABLE gunluk_bildirim_duzeltme_auditleri');
    try {
        GunlukBildirimDuzeltmeAuditService::appendInTransaction(
            $pdo,
            $existing2,
            $next2,
            GunlukBildirimDuzeltmeAuditService::OLAY_DUZELTME,
            1,
            'x'
        );
        gbdaFail('expected missing-table throw');
    } catch (\Throwable $e) {
        $pdo->rollBack();
        gbdaOk('audit insert failure throws');
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

// After rollback the audit table is restored; exercise update-failure rollback.
$beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM gunluk_bildirim_duzeltme_auditleri')->fetchColumn();
$pdo->beginTransaction();
try {
    GunlukBildirimDuzeltmeAuditService::appendInTransaction(
        $pdo,
        $existing2,
        [
            'bildirim_turu' => 'IZINLI',
            'alt_tur' => null,
            'baslangic_saati' => null,
            'bitis_saati' => null,
            'dakika' => null,
            'aciklama' => 'izin',
            'state' => 'DUZELTME_ISTENDI',
        ],
        GunlukBildirimDuzeltmeAuditService::OLAY_DUZELTME,
        7,
        'izin'
    );
    throw new RuntimeException('simulated row update failure');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM gunluk_bildirim_duzeltme_auditleri')->fetchColumn();
    gbdaAssert($afterCount === $beforeCount, 'row update failure rolls back audit');
    $tur = (string) $pdo->query('SELECT bildirim_turu FROM gunluk_bildirimler WHERE id = 10')->fetchColumn();
    gbdaAssert($tur === 'GEC_GELDI', 'source row unchanged after rollback');
}

// request-correction style: state-only change is not businessFieldsChanged for tur fields
$stateOnlyBefore = GunlukBildirimDuzeltmeAuditService::businessSnapshot([
    'bildirim_turu' => 'GELMEDI',
    'alt_tur' => null,
    'baslangic_saati' => null,
    'bitis_saati' => null,
    'dakika' => null,
    'aciklama' => 'x',
    'state' => 'GONDERILDI',
]);
$stateOnlyAfter = $stateOnlyBefore;
$stateOnlyAfter['state'] = 'DUZELTME_ISTENDI';
gbdaAssert(
    !GunlukBildirimDuzeltmeAuditService::businessFieldsChanged($stateOnlyBefore, $stateOnlyAfter),
    'request-correction state-only → no business transition'
);

gbdaAssert(
    \Medisa\Api\Auth\RolePermissions::has(['rol' => 'IK_SORUMLUSU'], 'gunluk_bildirim.correct_scoped'),
    'IK has correct_scoped'
);
gbdaAssert(
    \Medisa\Api\Auth\RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'gunluk_bildirim.correct_scoped'),
    'GENEL has correct_scoped'
);
gbdaAssert(
    !\Medisa\Api\Auth\RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'gunluk_bildirim.correct_scoped'),
    'BIRIM_AMIRI no correct_scoped'
);
gbdaAssert(
    !\Medisa\Api\Auth\RolePermissions::has(['rol' => 'MUHASEBE'], 'gunluk_bildirim.correct_scoped'),
    'MUHASEBE no correct_scoped'
);
gbdaAssert(
    !\Medisa\Api\Auth\RolePermissions::has(['rol' => 'PERSONEL'], 'gunluk_bildirim.correct_scoped'),
    'PERSONEL no correct_scoped'
);
gbdaAssert(
    !\Medisa\Api\Auth\RolePermissions::has(['rol' => 'SISTEM_YONETICISI'], 'gunluk_bildirim.correct_scoped'),
    'SISTEM no correct_scoped'
);

gbdaOk('ALL_PASS gunluk-bildirim-duzeltme-audit');
echo "ALL_PASS gunluk-bildirim-duzeltme-audit\n";
