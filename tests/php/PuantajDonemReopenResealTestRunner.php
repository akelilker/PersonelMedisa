<?php

declare(strict_types=1);

/**
 * S87 seal revision + dual-control reopen owner (SQLite).
 * php tests/php/PuantajDonemReopenResealTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\PuantajDonemReopenService;

function s87rAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s87rPdo(): PDO
{
    $path = tempnam(sys_get_temp_dir(), 's87-reopen-');
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function s87rSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, kullanici_adi TEXT)');
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT)');
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, ise_giris_tarihi TEXT, cikis_tarihi TEXT,
        aktif_durum TEXT DEFAULT \'AKTIF\', ad TEXT, soyad TEXT
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        revision_no INTEGER NOT NULL DEFAULT 1,
        donem TEXT, durum TEXT NOT NULL DEFAULT \'MUHURLENDI\',
        muhurlenen_kayit_sayisi INTEGER DEFAULT 0,
        created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        parent_muhur_id INTEGER, superseded_by_id INTEGER, source_hash TEXT, reopen_talep_id INTEGER,
        UNIQUE (sube_id, yil, ay, revision_no)
    )');
    // SQLite: emulate single effective via partial unique with NULL trick in tests manually.
    $pdo->exec('CREATE TABLE puantaj_aylik_muhur_satirlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, muhur_id INTEGER NOT NULL,
        personel_id INTEGER NOT NULL, tarih TEXT NOT NULL, gun_tipi TEXT,
        UNIQUE (muhur_id, personel_id, tarih)
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER NOT NULL, tarih TEXT NOT NULL,
        state TEXT DEFAULT \'ACIK\', gun_tipi TEXT, hareket_durumu TEXT, dayanak TEXT,
        net_calisma_suresi_dakika INTEGER DEFAULT 0, hafta_tatili_hak_kazandi_mi INTEGER,
        muhur_id INTEGER, kontrol_durumu TEXT DEFAULT \'BEKLIYOR\',
        UNIQUE (personel_id, tarih)
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_reopen_talepleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        kaynak_muhur_id INTEGER NOT NULL, talep_durumu TEXT NOT NULL, gerekce TEXT NOT NULL,
        requested_by INTEGER NOT NULL, requested_at TEXT NOT NULL,
        approved_by INTEGER, approved_at TEXT, rejected_by INTEGER, rejected_at TEXT,
        rejection_reason TEXT, applied_at TEXT, reseal_muhur_id INTEGER, request_hash TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_reopen_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        aksiyon TEXT NOT NULL, sonuc TEXT NOT NULL, reopen_talep_id INTEGER,
        source_muhur_id INTEGER, source_revision INTEGER, target_muhur_id INTEGER, target_revision INTEGER,
        request_hash TEXT NOT NULL, previous_source_hash TEXT, new_source_hash TEXT,
        failure_code TEXT, payload_json TEXT, actor_id INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (sube_id, yil, ay, aksiyon, request_hash)
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        muhur_id INTEGER NOT NULL, revision_no INTEGER NOT NULL DEFAULT 1,
        state TEXT NOT NULL DEFAULT \'OLUSTURULDU\',
        source_hash TEXT, snapshot_hash TEXT, created_at TEXT, iptal_edildi_at TEXT
    )');

    $pdo->exec("INSERT INTO users (id, kullanici_adi) VALUES (1, 'req'), (2, 'apr')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'T', 'Test')");
    $pdo->exec("INSERT INTO personeller (id, sube_id, ise_giris_tarihi, ad, soyad)
                VALUES (10, 1, '2026-04-01', 'A', 'B')");
}

function s87rSeedSeal(PDO $pdo, int $days = 2): int
{
    $pdo->exec("INSERT INTO puantaj_aylik_muhurleri
        (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, source_hash)
        VALUES (1, 2026, 4, 1, '2026-04', 'MUHURLENDI', {$days}, 1, 'hash-old')");
    $muhurId = (int) $pdo->lastInsertId();
    for ($d = 1; $d <= $days; $d++) {
        $tarih = sprintf('2026-04-%02d', $d);
        $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, state, gun_tipi, muhur_id)
                    VALUES (10, '{$tarih}', 'MUHURLENDI', 'Normal_Is_Gunu', {$muhurId})");
        $pdo->exec("INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, gun_tipi)
                    VALUES ({$muhurId}, 10, '{$tarih}', 'Normal_Is_Gunu')");
    }

    return $muhurId;
}

function s87rFillApril(PDO $pdo): void
{
    for ($d = 1; $d <= 30; $d++) {
        $tarih = sprintf('2026-04-%02d', $d);
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO gunluk_puantaj (personel_id, tarih, state, gun_tipi)
             VALUES (10, :t, \'ACIK\', \'Normal_Is_Gunu\')'
        );
        $stmt->execute(['t' => $tarih]);
        $pdo->prepare(
            'UPDATE gunluk_puantaj SET state = \'ACIK\', gun_tipi = \'Normal_Is_Gunu\', muhur_id = NULL
             WHERE personel_id = 10 AND tarih = :t'
        )->execute(['t' => $tarih]);
    }
}

$pdo = s87rPdo();
s87rSchema($pdo);
$muhurId = s87rSeedSeal($pdo, 2);

// 1) migration semantics: revision 1 preserved
$row = $pdo->query('SELECT revision_no, source_hash FROM puantaj_aylik_muhurleri WHERE id = 1')->fetch();
s87rAssert((int) $row['revision_no'] === 1 && $row['source_hash'] === 'hash-old', 'old seal revision_no=1 + hash intact');

// 2) PERIOD_LOCKED while sealed
$pdo->beginTransaction();
PuantajDonemKilidiService::acquire($pdo, 1, 2026, 4);
s87rAssert(PuantajDonemKilidiService::isSealed($pdo, ['sube_id' => 1, 'yil' => 2026, 'ay' => 4]), 'sealed => isSealed');
$pdo->commit();

// 3) reopen request
$pdo->beginTransaction();
$talep = PuantajDonemReopenService::createReopenRequest($pdo, ['id' => 1], 1, 2026, 4, 'Canonical takvim duzeltmesi gerekli.');
$pdo->commit();
s87rAssert((int) $talep['id'] > 0 && $talep['talep_durumu'] === 'ONAY_BEKLIYOR', 'reopen request created');

// 4) pending still locked
s87rAssert(
    PuantajDonemPeriodService::resolvePeriodState($pdo, 1, 2026, 4) === 'REOPEN_PENDING',
    'state REOPEN_PENDING'
);
s87rAssert(PuantajDonemKilidiService::isSealed($pdo, ['sube_id' => 1, 'yil' => 2026, 'ay' => 4]), 'pending still write-locked');

// 5) self approve forbidden
$pdo->beginTransaction();
try {
    PuantajDonemReopenService::approveReopenRequest($pdo, ['id' => 1], 1, 2026, 4, (int) $talep['id']);
    s87rAssert(false, 'self approve should fail');
} catch (PuantajDonemReopenException $e) {
    $pdo->rollBack();
    s87rAssert($e->getErrorCode() === 'REOPEN_SELF_APPROVAL_FORBIDDEN', 'self approval forbidden');
}

// 6) empty gerekce
$pdo2 = s87rPdo();
s87rSchema($pdo2);
s87rSeedSeal($pdo2, 1);
$pdo2->beginTransaction();
try {
    PuantajDonemReopenService::createReopenRequest($pdo2, ['id' => 1], 1, 2026, 4, '   ');
    s87rAssert(false, 'empty gerekce should fail');
} catch (PuantajDonemReopenException $e) {
    $pdo2->rollBack();
    s87rAssert($e->getErrorCode() === 'VALIDATION_ERROR', 'empty gerekce validation');
}

// 7) approve by other user + active snapshot blocks canonical
$pdo->beginTransaction();
PuantajDonemReopenService::approveReopenRequest($pdo, ['id' => 2], 1, 2026, 4, (int) $talep['id'], 'ok');
$pdo->commit();
s87rAssert(PuantajDonemPeriodService::resolvePeriodState($pdo, 1, 2026, 4) === 'REOPENED', 'state REOPENED');

$pdo->exec("INSERT INTO maas_hesaplama_donem_snapshotlari
    (sube_id, yil, ay, muhur_id, revision_no, state, source_hash, snapshot_hash)
    VALUES (1, 2026, 4, {$muhurId}, 1, 'OLUSTURULDU', 's1', 'h1')");
try {
    PuantajDonemPeriodService::assertCanonicalWriteAllowed($pdo, 1, 2026, 4);
    s87rAssert(false, 'active snapshot should block');
} catch (PuantajDonemReopenException $e) {
    s87rAssert($e->getErrorCode() === 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED', 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED');
}

// 8) cancel snapshot → canonical open
$pdo->exec("UPDATE maas_hesaplama_donem_snapshotlari SET state = 'IPTAL', iptal_edildi_at = CURRENT_TIMESTAMP WHERE id = 1");
PuantajDonemPeriodService::assertCanonicalWriteAllowed($pdo, 1, 2026, 4);
s87rAssert(true, 'canonical write allowed after snapshot cancel');

// 9) payroll blocked while reopened
try {
    PuantajDonemPeriodService::assertPayrollMutationAllowed($pdo, 1, 2026, 4);
    s87rAssert(false, 'payroll should block');
} catch (PuantajDonemReopenException $e) {
    s87rAssert($e->getErrorCode() === 'PERIOD_REOPENED', 'PERIOD_REOPENED blocks payroll');
}

// 10) incomplete calendar reseal blocked
$pdo->beginTransaction();
try {
    PuantajDonemReopenService::reseal(
        $pdo,
        ['id' => 1],
        1,
        2026,
        4,
        'reseal',
        $muhurId,
        static function () {
            return ['rows' => [], 'source_hash' => 'x', 'muhur_id' => 0];
        }
    );
    s87rAssert(false, 'incomplete should fail');
} catch (PuantajDonemReopenException $e) {
    $pdo->rollBack();
    s87rAssert($e->getErrorCode() === 'CANONICAL_CALENDAR_INCOMPLETE', 'incomplete calendar blocked');
}

// 11) empty gun_tipi blocked
s87rFillApril($pdo);
$pdo->exec("UPDATE gunluk_puantaj SET gun_tipi = '' WHERE personel_id = 10 AND tarih = '2026-04-15'");
$pdo->beginTransaction();
try {
    PuantajDonemReopenService::assertCanonicalCalendarComplete($pdo, 1, 2026, 4);
    s87rAssert(false, 'empty gun_tipi should fail');
} catch (PuantajDonemReopenException $e) {
    $pdo->rollBack();
    s87rAssert($e->getErrorCode() === 'CANONICAL_DAY_TYPE_REQUIRED', 'gun_tipi required');
}
$pdo->exec("UPDATE gunluk_puantaj SET gun_tipi = 'Normal_Is_Gunu' WHERE personel_id = 10 AND tarih = '2026-04-15'");

// 12) successful reseal
$oldHash = (string) $pdo->query('SELECT source_hash FROM puantaj_aylik_muhurleri WHERE id = 1')->fetchColumn();
$pdo->beginTransaction();
$result = PuantajDonemReopenService::reseal(
    $pdo,
    ['id' => 1],
    1,
    2026,
    4,
    'Canonical tamamlandi',
    $muhurId,
    static function (PDO $pdo, $subeId, $yil, $ay, $revisionNo, $parentId, $talepId) {
        $pdo->prepare(
            'INSERT INTO puantaj_aylik_muhurleri
                (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, parent_muhur_id, reopen_talep_id, source_hash)
             VALUES (1, 2026, 4, :rev, \'2026-04\', \'MUHURLENDI\', 30, 1, :parent, :talep, \'hash-new\')'
        )->execute(['rev' => $revisionNo, 'parent' => $parentId, 'talep' => $talepId]);
        $newId = (int) $pdo->lastInsertId();
        for ($d = 1; $d <= 30; $d++) {
            $t = sprintf('2026-04-%02d', $d);
            $pdo->exec("INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, gun_tipi)
                        VALUES ({$newId}, 10, '{$t}', 'Normal_Is_Gunu')");
            $pdo->exec("UPDATE gunluk_puantaj SET state='MUHURLENDI', muhur_id={$newId} WHERE personel_id=10 AND tarih='{$t}'");
        }

        return ['rows' => array_fill(0, 30, ['personel_id' => 10]), 'source_hash' => 'hash-new', 'muhur_id' => $newId];
    }
);
$pdo->commit();
s87rAssert((int) $result['revision_no'] === 2, 'new seal revision 2');
s87rAssert(PuantajDonemPeriodService::resolvePeriodState($pdo, 1, 2026, 4) === 'SEALED', 'resealed => SEALED');
$old = $pdo->query('SELECT durum, source_hash, superseded_by_id FROM puantaj_aylik_muhurleri WHERE id = 1')->fetch();
s87rAssert($old['durum'] === 'SUPERSEDED' && $old['source_hash'] === $oldHash, 'old seal immutable hash + SUPERSEDED');
$eff = PuantajDonemPeriodService::findEffectiveSeal($pdo, 1, 2026, 4);
s87rAssert($eff !== null && (int) $eff['revision_no'] === 2, 'effective is revision 2');

// 13) history
$hist = PuantajDonemReopenService::sealHistoryPayload($pdo, 1, 2026, 4);
s87rAssert(count($hist['seals']) === 2 && $hist['effective_revision_no'] === 2, 'seal history ordered');

// 14) reject path does not unlock
$pdo3 = s87rPdo();
s87rSchema($pdo3);
$m3 = s87rSeedSeal($pdo3, 1);
$pdo3->beginTransaction();
$t3 = PuantajDonemReopenService::createReopenRequest($pdo3, ['id' => 1], 1, 2026, 4, 'reject test gerekce');
PuantajDonemReopenService::rejectReopenRequest($pdo3, ['id' => 2], 1, 2026, 4, (int) $t3['id'], 'uygun degil');
$pdo3->commit();
s87rAssert(PuantajDonemPeriodService::resolvePeriodState($pdo3, 1, 2026, 4) === 'SEALED', 'reject keeps SEALED');
s87rAssert(PuantajDonemKilidiService::isSealed($pdo3, ['sube_id' => 1, 'yil' => 2026, 'ay' => 4]), 'reject still locked');

// 15) unsealed request conflict
$pdo4 = s87rPdo();
s87rSchema($pdo4);
$pdo4->beginTransaction();
try {
    PuantajDonemReopenService::createReopenRequest($pdo4, ['id' => 1], 1, 2026, 4, 'no seal');
    s87rAssert(false, 'unsealed should fail');
} catch (PuantajDonemReopenException $e) {
    $pdo4->rollBack();
    s87rAssert($e->getErrorCode() === 'PERIOD_NOT_SEALED', 'PERIOD_NOT_SEALED');
}

// 16) audit server-owned actor
$audit = $pdo->query("SELECT actor_id, aksiyon FROM puantaj_donem_reopen_auditleri WHERE aksiyon = 'PERIOD_RESEALED'")->fetch();
s87rAssert($audit && (int) $audit['actor_id'] === 1, 'audit actor server-owned');

// 17) reject sonrasi ayni gerekce ile yeni talep acilabilir (terminal hash sticky degil)
$pdo3->beginTransaction();
$t3b = PuantajDonemReopenService::createReopenRequest($pdo3, ['id' => 1], 1, 2026, 4, 'reject test gerekce');
$pdo3->commit();
s87rAssert(
    (int) $t3b['id'] > (int) $t3['id'] && $t3b['talep_durumu'] === 'ONAY_BEKLIYOR',
    'post-reject reopen retry creates new pending talep'
);

// 18) month-length calendar completeness (28/29/30/31) — hard-code gun sayisi kullanmadan
function s87rFillMonth(PDO $pdo, int $personelId, int $yil, int $ay): int
{
    $first = sprintf('%04d-%02d-01', $yil, $ay);
    $last = date('Y-m-t', strtotime($first));
    $days = (int) date('j', strtotime($last));
    for ($d = 1; $d <= $days; $d++) {
        $t = sprintf('%04d-%02d-%02d', $yil, $ay, $d);
        $pdo->prepare(
            'INSERT OR REPLACE INTO gunluk_puantaj (personel_id, tarih, state, gun_tipi)
             VALUES (:p, :t, \'ACIK\', \'Normal_Is_Gunu\')'
        )->execute(['p' => $personelId, 't' => $t]);
    }

    return $days;
}

foreach (
    [
        [2027, 2, 28],
        [2028, 2, 29],
        [2026, 4, 30],
        [2026, 1, 31],
    ] as [$y, $m, $expectedDays]
) {
    $pdoM = s87rPdo();
    s87rSchema($pdoM);
    $pdoM->exec("UPDATE personeller SET ise_giris_tarihi = '" . sprintf('%04d-%02d-01', $y, $m) . "' WHERE id = 10");
    $days = s87rFillMonth($pdoM, 10, $y, $m);
    s87rAssert($days === $expectedDays, "month {$y}-{$m} day count {$expectedDays}");
    PuantajDonemReopenService::assertCanonicalCalendarComplete($pdoM, 1, $y, $m);
    s87rAssert(true, "canonical complete {$y}-{$m}");
    // eksik gun
    $mid = sprintf('%04d-%02d-%02d', $y, $m, min(15, $days));
    $pdoM->exec("DELETE FROM gunluk_puantaj WHERE personel_id = 10 AND tarih = '{$mid}'");
    try {
        PuantajDonemReopenService::assertCanonicalCalendarComplete($pdoM, 1, $y, $m);
        s87rAssert(false, "incomplete {$y}-{$m} should fail");
    } catch (PuantajDonemReopenException $e) {
        s87rAssert($e->getErrorCode() === 'CANONICAL_CALENDAR_INCOMPLETE', "incomplete blocked {$y}-{$m}");
    }
}

echo PHP_EOL . 'PuantajDonemReopenResealTestRunner: ALL PASS' . PHP_EOL;
