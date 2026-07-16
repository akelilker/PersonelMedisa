<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimDonemContextService.php';
require_once __DIR__ . '/../../api/src/Services/DonemKapanisAuditService.php';
require_once __DIR__ . '/../../api/src/Services/DonemKapanisPreflightService.php';
require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiPuantajMapper.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiManualApplyService.php';

use Medisa\Api\Services\BildirimPuantajEtkiManualApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use Medisa\Api\Services\DonemKapanisAuditService;
use Medisa\Api\Services\DonemKapanisPreflightService;
use Medisa\Api\Services\PuantajDonemKilidiService;

function closePdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 100');

    return $pdo;
}

function createCloseSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT)');
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, departman_id INTEGER,
        aktif_durum TEXT NOT NULL DEFAULT \'AKTIF\', maas_tutari REAL
    )');
    $pdo->exec('CREATE TABLE gunluk_bildirimler (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, tarih TEXT NOT NULL,
        sube_id INTEGER NOT NULL, departman_id INTEGER, state TEXT NOT NULL, created_by INTEGER,
        haftalik_mutabakat_id INTEGER
    )');
    $pdo->exec('CREATE TABLE haftalik_bildirim_mutabakatlari (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, birim_amiri_user_id INTEGER NOT NULL,
        hafta_baslangic TEXT NOT NULL, hafta_bitis TEXT NOT NULL, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE aylik_bildirim_onaylari (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, birim_amiri_user_id INTEGER NOT NULL,
        ay TEXT NOT NULL, ay_baslangic TEXT NOT NULL, ay_bitis TEXT NOT NULL, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE genel_yonetici_bildirim_onaylari (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, birim_amiri_user_id INTEGER NOT NULL,
        ay TEXT NOT NULL, aylik_bildirim_onayi_id INTEGER NOT NULL, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INTEGER PRIMARY KEY, personel_id INTEGER, sube_id INTEGER, tarih TEXT, state TEXT,
        etki_turu TEXT, etki_miktari INTEGER, etki_birimi TEXT, conflict_code TEXT,
        bildirim_aciklama TEXT, source_snapshot TEXT, source_hash TEXT,
        uygulama_modu TEXT, manuel_karar_turu TEXT, manuel_karar_miktari INTEGER,
        karar_veren_user_id INTEGER, karar_zamani TEXT, karar_gerekcesi TEXT,
        uygulanan_puantaj_id INTEGER, onceki_puantaj_snapshot TEXT,
        sonraki_puantaj_snapshot TEXT, uygulama_hash TEXT, ay TEXT,
        genel_yonetici_bildirim_onayi_id INTEGER
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER, tarih TEXT, state TEXT,
        gun_tipi TEXT, hareket_durumu TEXT, dayanak TEXT, durumu_bildirdi_mi INTEGER,
        durum_bildirim_aciklamasi TEXT, hesap_etkisi TEXT, beklenen_giris_saati TEXT,
        beklenen_cikis_saati TEXT, giris_saati TEXT, cikis_saati TEXT,
        gec_kalma_dakika INTEGER, erken_cikis_dakika INTEGER, gercek_mola_dakika INTEGER,
        hesaplanan_mola_dakika INTEGER, net_calisma_suresi_dakika INTEGER,
        gunluk_brut_sure_dakika INTEGER, hafta_tatili_hak_kazandi_mi INTEGER,
        kontrol_durumu TEXT, kaynak TEXT, aciklama TEXT, muhur_id INTEGER,
        UNIQUE (personel_id, tarih)
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        donem TEXT, durum TEXT, muhurlenen_kayit_sayisi INTEGER DEFAULT 0, created_by INTEGER,
        UNIQUE (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE ek_odeme_kesinti (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, donem TEXT NOT NULL, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE aylik_ozet_satirlari (
        id INTEGER PRIMARY KEY, ay TEXT NOT NULL, sube_id INTEGER NOT NULL, kapanis_durumu TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE donem_kapanis_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        action TEXT NOT NULL, result_state TEXT NOT NULL, muhur_id INTEGER,
        blocker_count INTEGER NOT NULL DEFAULT 0, warning_count INTEGER NOT NULL DEFAULT 0,
        preflight_hash TEXT NOT NULL, request_hash TEXT NOT NULL, result_hash TEXT NOT NULL,
        preflight_snapshot TEXT NOT NULL, actor_user_id INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (sube_id, yil, ay, action, request_hash)
    )');
}

function resetCloseData(PDO $pdo): void
{
    foreach ([
        'donem_kapanis_auditleri', 'puantaj_aylik_muhurleri', 'puantaj_donem_kilitleri',
        'gunluk_puantaj', 'onayli_bildirim_puantaj_etki_adaylari', 'genel_yonetici_bildirim_onaylari',
        'aylik_bildirim_onaylari', 'haftalik_bildirim_mutabakatlari', 'gunluk_bildirimler',
        'ek_odeme_kesinti', 'aylik_ozet_satirlari', 'personeller', 'subeler',
    ] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('INSERT INTO subeler (id, kod, ad) VALUES (1, \'MRK\', \'Merkez\')');
    $pdo->exec('INSERT INTO personeller (id, sube_id, departman_id, aktif_durum, maas_tutari)
        VALUES (7, 1, 3, \'AKTIF\', 25000), (8, 1, 3, \'AKTIF\', NULL)');
}

function findMonthlySeal(PDO $pdo, int $subeId, int $yil, int $ay): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM puantaj_aylik_muhurleri WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay LIMIT 1'
    );
    $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** @param array<string, mixed> $user @param array<string, mixed> $payload */
function attemptPeriodClose(PDO $pdo, array $user, int $subeId, int $yil, int $ay, array $payload = []): array
{
    $pdo->beginTransaction();
    try {
        PuantajDonemKilidiService::acquire($pdo, $subeId, $yil, $ay);
        $existing = findMonthlySeal($pdo, $subeId, $yil, $ay);
        if ($existing) {
            $pdo->commit();

            return [
                'status' => 'idempotent',
                'http' => 200,
                'muhur_id' => (int) $existing['id'],
                'code' => 'PERIOD_ALREADY_SEALED',
            ];
        }

        $preflight = DonemKapanisPreflightService::evaluate($pdo, $subeId, $yil, $ay);
        $requestHash = DonemKapanisAuditService::computeRequestHash(
            $user,
            $subeId,
            $yil,
            $ay,
            $payload,
            (string) ($preflight['preflight_hash'] ?? '')
        );

        if ((int) ($preflight['blocker_count'] ?? 0) > 0) {
            DonemKapanisAuditService::recordBlocked(
                $pdo,
                $preflight,
                $user,
                $subeId,
                $yil,
                $ay,
                $requestHash
            );
            $pdo->commit();

            return [
                'status' => 'blocked',
                'http' => 409,
                'code' => 'PERIOD_CLOSE_BLOCKED',
                'preflight' => $preflight,
                'request_hash' => $requestHash,
            ];
        }

        $donem = sprintf('%04d-%02d', $yil, $ay);
        $insert = $pdo->prepare(
            'INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
             VALUES (:sube_id, :yil, :ay, :donem, :durum, 0, :created_by)'
        );
        $insert->execute([
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'donem' => $donem,
            'durum' => 'MUHURLENDI',
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        $muhurId = (int) $pdo->lastInsertId();

        DonemKapanisAuditService::recordSuccess(
            $pdo,
            $preflight,
            $user,
            $subeId,
            $yil,
            $ay,
            $muhurId,
            $requestHash
        );
        $pdo->commit();

        return [
            'status' => 'sealed',
            'http' => 200,
            'muhur_id' => $muhurId,
            'preflight' => $preflight,
            'request_hash' => $requestHash,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function seedManualCandidate(PDO $pdo): array
{
    $snapshot = ['gunluk_bildirim_id' => 6, 'personel_id' => 7, 'tarih' => '2026-05-15', 'bildirim_turu' => 'DIGER'];
    $aday = [
        'id' => 4, 'personel_id' => 7, 'sube_id' => 1, 'tarih' => '2026-05-15',
        'state' => 'INCELEME_GEREKLI', 'etki_turu' => 'MANUEL_INCELEME', 'etki_miktari' => null,
        'etki_birimi' => null, 'conflict_code' => 'DIGER_MANUEL_INCELEME',
        'bildirim_aciklama' => 'Kontrollu DIGER bildirimi',
        'source_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_hash' => BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot),
        'uygulama_modu' => 'OTOMATIK', 'manuel_karar_turu' => null, 'manuel_karar_miktari' => null,
        'karar_veren_user_id' => null, 'karar_zamani' => null, 'karar_gerekcesi' => null,
        'uygulanan_puantaj_id' => null, 'onceki_puantaj_snapshot' => null,
        'sonraki_puantaj_snapshot' => null, 'uygulama_hash' => null, 'ay' => '2026-05',
        'genel_yonetici_bildirim_onayi_id' => null,
    ];
    $columns = array_keys($aday);
    $stmt = $pdo->prepare('INSERT INTO onayli_bildirim_puantaj_etki_adaylari ('
        . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    $stmt->execute($aday);

    return $aday;
}

function fetchCandidate(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 4')->fetch();
}

function assertClose(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$path = tempnam(sys_get_temp_dir(), 'medisa-close-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}

try {
    $pdo = closePdo($path);
    createCloseSchema($pdo);
    $actor = ['id' => 99];

    resetCloseData($pdo);
    $pdo->exec("INSERT INTO onayli_bildirim_puantaj_etki_adaylari
        (id, sube_id, ay, personel_id, tarih, state, uygulama_modu)
        VALUES (1, 1, '2026-06', 7, '2026-06-04', 'HAZIR', 'OTOMATIK')");
    $blocked = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($blocked['status'] === 'blocked' && $blocked['http'] === 409, 'blocker returns 409 PERIOD_CLOSE_BLOCKED');
    assertClose(($blocked['code'] ?? '') === 'PERIOD_CLOSE_BLOCKED', 'blocked response code is canonical');
    assertClose((int) $pdo->query('SELECT COUNT(*) FROM donem_kapanis_auditleri')->fetchColumn() === 1, 'blocked close creates audit');
    assertClose((int) $pdo->query('SELECT COUNT(*) FROM puantaj_aylik_muhurleri')->fetchColumn() === 0, 'blocked close does not seal');

    $retry = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($retry['status'] === 'blocked', 'blocked retry stays blocked');
    assertClose((int) $pdo->query('SELECT COUNT(*) FROM donem_kapanis_auditleri')->fetchColumn() === 1, 'blocked retry audit is idempotent');

    resetCloseData($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, kaynak)
        VALUES (7, '2026-06-04', 'ACIK', 'AMIR_KONTROL_ETTI', 'SISTEM')");
    $clean = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($clean['status'] === 'sealed' && $clean['http'] === 200, 'clean period seals successfully');
    assertClose((int) $pdo->query('SELECT COUNT(*) FROM puantaj_aylik_muhurleri')->fetchColumn() === 1, 'clean close inserts seal');
    $successAudit = $pdo->query('SELECT * FROM donem_kapanis_auditleri WHERE action = \''
        . DonemKapanisAuditService::ACTION_CLOSE_SUCCESS . '\'')->fetch();
    assertClose(is_array($successAudit) && (int) ($successAudit['muhur_id'] ?? 0) === (int) ($clean['muhur_id'] ?? 0), 'success audit stores muhur_id');

    $sealRetry = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($sealRetry['status'] === 'idempotent', 'seal retry is idempotent');
    assertClose((int) $pdo->query('SELECT COUNT(*) FROM donem_kapanis_auditleri')->fetchColumn() === 1, 'seal retry does not duplicate success audit');

    resetCloseData($pdo);
    $pdo->exec("INSERT INTO aylik_ozet_satirlari (ay, sube_id, kapanis_durumu) VALUES ('2026-06', 1, 'ACIK')");
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, kaynak, aciklama)
        VALUES (7, '2026-06-04', 'ACIK', 'AMIR_KONTROL_ETTI', 'MANUEL', '')");
    $warningClose = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($warningClose['status'] === 'sealed', 'warnings do not block close');
    assertClose((int) ($warningClose['preflight']['warning_count'] ?? 0) > 0, 'warning close retains warning count in preflight');

    resetCloseData($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, kaynak)
        VALUES (7, '2026-06-04', 'ACIK', 'AMIR_KONTROL_ETTI', 'SISTEM')");
    $salaryClose = attemptPeriodClose($pdo, $actor, 1, 2026, 6);
    assertClose($salaryClose['status'] === 'sealed', 'salary missing does not block close');

    resetCloseData($pdo);
    $noNotifPreflight = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 7);
    assertClose(!in_array('NOTIF_MONTHLY_INCOMPLETE', array_column($noNotifPreflight['blockers'], 'code'), true), 'no-notification month skips synthetic monthly blocker');
    assertClose(!in_array('NOTIF_GY_INCOMPLETE', array_column($noNotifPreflight['blockers'], 'code'), true), 'no-notification month skips synthetic GY blocker');
    $noNotifClose = attemptPeriodClose($pdo, $actor, 1, 2026, 7);
    assertClose($noNotifClose['status'] === 'sealed', 'no-notification month closes without synthetic approvals');

    resetCloseData($pdo);
    seedManualCandidate($pdo);
    $first = closePdo($path);
    $second = closePdo($path);

    $first->beginTransaction();
    PuantajDonemKilidiService::acquire($first, 1, 2026, 5);
    $blockedApply = false;
    try {
        $second->beginTransaction();
        PuantajDonemKilidiService::acquire($second, 1, 2026, 5);
    } catch (PDOException $e) {
        $blockedApply = stripos($e->getMessage(), 'locked') !== false;
        if ($second->inTransaction()) {
            $second->rollBack();
        }
    }
    $first->rollBack();
    assertClose($blockedApply, 'S74 period lock serializes concurrent acquire attempts');

    $second->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($second, 1, '2026-05-15');
    $result = BildirimPuantajEtkiManualApplyService::apply(
        $second,
        fetchCandidate($second),
        'INCELEME_GEREKLI',
        'GOREVDE_CALISILMIS_GUN',
        null,
        'Kontrollu operasyon teyidi',
        5
    );
    $second->commit();
    assertClose(($result['status'] ?? '') === 'success', 'S75 manual apply still commits under open period');

    echo 'verify-donem-kapanis-close-integration: OK' . PHP_EOL;
} finally {
    unset($pdo, $first, $second);
    @unlink($path);
}
