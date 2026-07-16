<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimDonemContextService.php';
require_once __DIR__ . '/../../api/src/Services/DonemKapanisPreflightService.php';

use Medisa\Api\Services\DonemKapanisPreflightService;

function preflightPdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function createPreflightSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT)');
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, departman_id INTEGER,
        aktif_durum TEXT NOT NULL DEFAULT \'AKTIF\', maas_tutari REAL
    )');
    $pdo->exec('CREATE TABLE gunluk_bildirimler (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, tarih TEXT NOT NULL,
        sube_id INTEGER NOT NULL, departman_id INTEGER, state TEXT NOT NULL,
        created_by INTEGER, haftalik_mutabakat_id INTEGER
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
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, ay TEXT NOT NULL, personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL, state TEXT NOT NULL, conflict_code TEXT, uygulama_modu TEXT,
        genel_yonetici_bildirim_onayi_id INTEGER
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, tarih TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT \'ACIK\', kontrol_durumu TEXT NOT NULL DEFAULT \'BEKLIYOR\',
        kaynak TEXT, aciklama TEXT
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE ek_odeme_kesinti (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, donem TEXT NOT NULL, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE aylik_ozet_satirlari (
        id INTEGER PRIMARY KEY, ay TEXT NOT NULL, sube_id INTEGER NOT NULL, kapanis_durumu TEXT NOT NULL
    )');
}

function resetPreflightData(PDO $pdo): void
{
    foreach ([
        'aylik_ozet_satirlari', 'ek_odeme_kesinti', 'puantaj_aylik_muhurleri', 'gunluk_puantaj',
        'onayli_bildirim_puantaj_etki_adaylari', 'genel_yonetici_bildirim_onaylari',
        'aylik_bildirim_onaylari', 'haftalik_bildirim_mutabakatlari', 'gunluk_bildirimler',
        'personeller', 'subeler',
    ] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('INSERT INTO subeler (id, kod, ad) VALUES (1, \'MRK\', \'Merkez\')');
    $pdo->exec('INSERT INTO personeller (id, sube_id, departman_id, aktif_durum, maas_tutari)
        VALUES (10, 1, 3, \'AKTIF\', 25000), (11, 1, 4, \'AKTIF\', 0), (12, 1, 4, \'AKTIF\', 18000)');
}

function seedBildirim(PDO $pdo, int $id, int $personelId, string $tarih, string $state, int $amirId = 5): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO gunluk_bildirimler (id, personel_id, tarih, sube_id, departman_id, state, created_by)
         VALUES (:id, :personel_id, :tarih, 1, 3, :state, :created_by)'
    );
    $stmt->execute([
        'id' => $id,
        'personel_id' => $personelId,
        'tarih' => $tarih,
        'state' => $state,
        'created_by' => $amirId,
    ]);
}

function seedWeeklyMutabakat(PDO $pdo, int $id, string $weekStart, int $amirId = 5): void
{
    $weekEnd = (new DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
    $stmt = $pdo->prepare(
        'INSERT INTO haftalik_bildirim_mutabakatlari
         (id, sube_id, birim_amiri_user_id, hafta_baslangic, hafta_bitis, state)
         VALUES (:id, 1, :amir, :start, :end, \'TAMAMLANDI\')'
    );
    $stmt->execute(['id' => $id, 'amir' => $amirId, 'start' => $weekStart, 'end' => $weekEnd]);
}

function seedMonthlyOnay(PDO $pdo, int $id, string $state, int $amirId = 5): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO aylik_bildirim_onaylari
         (id, sube_id, birim_amiri_user_id, ay, ay_baslangic, ay_bitis, state)
         VALUES (:id, 1, :amir, \'2026-06\', \'2026-06-01\', \'2026-06-30\', :state)'
    );
    $stmt->execute(['id' => $id, 'amir' => $amirId, 'state' => $state]);
}

function seedGyOnay(PDO $pdo, int $id, int $aboId, string $state, int $amirId = 5): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO genel_yonetici_bildirim_onaylari
         (id, sube_id, birim_amiri_user_id, ay, aylik_bildirim_onayi_id, state)
         VALUES (:id, 1, :amir, \'2026-06\', :abo, :state)'
    );
    $stmt->execute(['id' => $id, 'amir' => $amirId, 'abo' => $aboId, 'state' => $state]);
}

function seedCandidate(PDO $pdo, int $id, string $state, ?int $gyId = null, int $personelId = 10): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO onayli_bildirim_puantaj_etki_adaylari
         (id, sube_id, ay, personel_id, tarih, state, conflict_code, uygulama_modu, genel_yonetici_bildirim_onayi_id)
         VALUES (:id, 1, \'2026-06\', :personel_id, \'2026-06-04\', :state, NULL, \'OTOMATIK\', :gy_id)'
    );
    $stmt->execute(['id' => $id, 'personel_id' => $personelId, 'state' => $state, 'gy_id' => $gyId]);
}

function seedPuantaj(PDO $pdo, int $id, int $personelId, string $tarih, string $kontrol, string $kaynak = 'SISTEM', ?string $aciklama = 'not'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO gunluk_puantaj (id, personel_id, tarih, state, kontrol_durumu, kaynak, aciklama)
         VALUES (:id, :personel_id, :tarih, \'ACIK\', :kontrol, :kaynak, :aciklama)'
    );
    $stmt->execute([
        'id' => $id,
        'personel_id' => $personelId,
        'tarih' => $tarih,
        'kontrol' => $kontrol,
        'kaynak' => $kaynak,
        'aciklama' => $aciklama,
    ]);
}

function hasBlockerCode(array $payload, string $code): bool
{
    foreach ($payload['blockers'] as $issue) {
        if (($issue['code'] ?? '') === $code) {
            return true;
        }
    }

    return false;
}

function hasWarningCode(array $payload, string $code): bool
{
    foreach ($payload['warnings'] as $issue) {
        if (($issue['code'] ?? '') === $code) {
            return true;
        }
    }

    return false;
}

function hasInfoCode(array $payload, string $code): bool
{
    foreach ($payload['infos'] as $issue) {
        if (($issue['code'] ?? '') === $code) {
            return true;
        }
    }

    return false;
}

function assertPreflight(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$path = tempnam(sys_get_temp_dir(), 'medisa-preflight-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}

try {
    $pdo = preflightPdo($path);
    createPreflightSchema($pdo);

    resetPreflightData($pdo);
    $empty = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 7);
    assertPreflight($empty['kapanabilir_mi'] === true, 'empty month is closable');
    assertPreflight($empty['blocker_count'] === 0, 'empty month has no blockers');
    assertPreflight(hasInfoCode($empty, 'NO_NOTIFICATION_ACTIVITY'), 'empty month reports no notification activity');

    resetPreflightData($pdo);
    seedPuantaj($pdo, 1, 10, '2026-06-04', 'AMIR_KONTROL_ETTI');
    $noNotif = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight($noNotif['kapanabilir_mi'] === true, 'no-notification month is closable');
    assertPreflight(!hasBlockerCode($noNotif, 'NOTIF_MONTHLY_INCOMPLETE'), 'no-notification month skips monthly approval blocker');
    assertPreflight(!hasBlockerCode($noNotif, 'NOTIF_GY_INCOMPLETE'), 'no-notification month skips GY approval blocker');
    assertPreflight(!hasBlockerCode($noNotif, 'CANDIDATE_GENERATION_MISSING'), 'no-notification month skips generation blocker');

    resetPreflightData($pdo);
    seedBildirim($pdo, 1, 10, '2026-06-04', 'TASLAK');
    $draft = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($draft, 'NOTIF_DRAFT_OR_CORRECTION'), 'draft bildirim is blocker');

    resetPreflightData($pdo);
    seedBildirim($pdo, 2, 10, '2026-06-04', 'GONDERILDI');
    $weekly = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($weekly, 'NOTIF_WEEKLY_INCOMPLETE'), 'sent bildirim without mutabakat is weekly blocker');

    resetPreflightData($pdo);
    seedBildirim($pdo, 3, 10, '2026-06-04', 'HAFTALIK_MUTABAKATA_ALINDI');
    seedWeeklyMutabakat($pdo, 1, '2026-06-02');
    $monthly = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($monthly, 'NOTIF_MONTHLY_INCOMPLETE'), 'missing monthly approval is blocker');

    resetPreflightData($pdo);
    seedBildirim($pdo, 4, 10, '2026-06-04', 'HAFTALIK_MUTABAKATA_ALINDI');
    seedWeeklyMutabakat($pdo, 2, '2026-06-02');
    seedMonthlyOnay($pdo, 1, 'TAMAMLANDI');
    $gy = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($gy, 'NOTIF_GY_INCOMPLETE'), 'missing GY approval is blocker');

    resetPreflightData($pdo);
    seedBildirim($pdo, 5, 10, '2026-06-04', 'HAFTALIK_MUTABAKATA_ALINDI');
    seedWeeklyMutabakat($pdo, 3, '2026-06-02');
    seedMonthlyOnay($pdo, 2, 'TAMAMLANDI');
    seedGyOnay($pdo, 1, 2, 'TAMAMLANDI');
    $generation = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($generation, 'CANDIDATE_GENERATION_MISSING'), 'GY complete with sources but no candidates is blocker');

    resetPreflightData($pdo);
    seedCandidate($pdo, 1, 'HAZIR');
    $hazir = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($hazir, 'CANDIDATE_HAZIR_PENDING'), 'HAZIR candidate is blocker');

    resetPreflightData($pdo);
    seedCandidate($pdo, 2, 'INCELEME_GEREKLI');
    $inceleme = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($inceleme, 'CANDIDATE_INCELEME_PENDING'), 'INCELEME candidate is blocker');

    resetPreflightData($pdo);
    seedCandidate($pdo, 3, 'UYGULANDI');
    seedCandidate($pdo, 4, 'YOK_SAYILDI');
    $terminal = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(!hasBlockerCode($terminal, 'CANDIDATE_HAZIR_PENDING'), 'terminal UYGULANDI is not blocker');
    assertPreflight(!hasBlockerCode($terminal, 'CANDIDATE_INCELEME_PENDING'), 'terminal YOK_SAYILDI is not blocker');

    resetPreflightData($pdo);
    seedPuantaj($pdo, 10, 10, '2026-06-04', 'BEKLIYOR');
    $bekleyen = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasBlockerCode($bekleyen, 'PUANTAJ_CONTROL_PENDING'), 'BEKLIYOR puantaj is blocker');

    resetPreflightData($pdo);
    seedPuantaj($pdo, 11, 10, '2026-06-04', 'AMIR_KONTROL_ETTI');
    $kontrolOk = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(!hasBlockerCode($kontrolOk, 'PUANTAJ_CONTROL_PENDING'), 'AMIR_KONTROL_ETTI puantaj passes control gate');

    resetPreflightData($pdo);
    $salary = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(hasWarningCode($salary, 'FINANCE_SALARY_MISSING'), 'missing salary is warning');
    assertPreflight($salary['kapanabilir_mi'] === true, 'salary warning does not block close preflight');

    resetPreflightData($pdo);
    $pdo->exec('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (1, 2026, 6)');
    $sealed = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight($sealed['muhur_state'] === 'MUHURLENDI', 'sealed period reports MUHURLENDI');
    assertPreflight($sealed['donem_state'] === 'MUHURLU', 'sealed period reports MUHURLU');
    assertPreflight($sealed['kapanabilir_mi'] === false, 'sealed period is not closable');
    assertPreflight($sealed['blocker_count'] === 0, 'sealed period does not emit blockers');

    resetPreflightData($pdo);
    seedPuantaj($pdo, 20, 10, '2026-06-04', 'AMIR_KONTROL_ETTI');
    $firstHash = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    $secondHash = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6);
    assertPreflight(
        ($firstHash['preflight_hash'] ?? '') === ($secondHash['preflight_hash'] ?? '')
        && strlen((string) ($firstHash['preflight_hash'] ?? '')) === 64,
        'preflight hash is deterministic lowercase sha256'
    );
    assertPreflight(($firstHash['schema_version'] ?? '') === DonemKapanisPreflightService::SCHEMA_VERSION, 'schema version is canonical');

    resetPreflightData($pdo);
    seedPuantaj($pdo, 21, 10, '2026-06-04', 'BEKLIYOR');
    seedPuantaj($pdo, 22, 12, '2026-06-05', 'BEKLIYOR');
    $scoped = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6, ['departman_id' => 3]);
    assertPreflight(hasBlockerCode($scoped, 'PUANTAJ_CONTROL_PENDING'), 'scope filter keeps matching blocker');
    foreach ($scoped['blockers'] as $issue) {
        if (($issue['code'] ?? '') === 'PUANTAJ_CONTROL_PENDING') {
            assertPreflight((int) ($issue['count'] ?? 0) === 1, 'departman scope filters puantaj blocker count');
            assertPreflight(in_array(21, $issue['record_ids'] ?? [], true), 'departman scope keeps dept puantaj id');
            assertPreflight(!in_array(22, $issue['record_ids'] ?? [], true), 'departman scope excludes other dept puantaj id');
        }
    }

    resetPreflightData($pdo);
    seedPuantaj($pdo, 23, 10, '2026-06-04', 'BEKLIYOR');
    seedPuantaj($pdo, 24, 12, '2026-06-05', 'BEKLIYOR');
    $personScoped = DonemKapanisPreflightService::evaluate($pdo, 1, 2026, 6, ['personel_id' => 12]);
    foreach ($personScoped['blockers'] as $issue) {
        if (($issue['code'] ?? '') === 'PUANTAJ_CONTROL_PENDING') {
            assertPreflight((int) ($issue['count'] ?? 0) === 1, 'personel scope filters puantaj blocker count');
            assertPreflight(in_array(24, $issue['record_ids'] ?? [], true), 'personel scope keeps selected personel puantaj id');
        }
    }

    echo 'verify-donem-kapanis-preflight: OK' . PHP_EOL;
} finally {
    unset($pdo);
    @unlink($path);
}
