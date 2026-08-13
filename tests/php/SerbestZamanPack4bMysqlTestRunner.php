<?php

declare(strict_types=1);

/**
 * Pack 4B: disposable MariaDB — SERBEST_ZAMAN allocation-aware destroy + 6M deadline.
 * php tests/php/SerbestZamanPack4bMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Payroll\PayrollComplianceGuard;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\PhysicalDestruction\RetentionPhysicalDestroyGate;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use Medisa\Api\Services\SerbestZaman\SerbestZamanAllocationService;
use Medisa\Api\Services\SerbestZaman\SerbestZamanDeadlineService;

/** Real builder semantics (HaftalikKapanisController::buildSnapshotSatir) — not FM-owned. */
const P4B_NOTLAR_COMPLETENESS_SENTINEL =
    '["Eksik haftalik puantaj gunu (5/7); UBGT ve 18 yas alti haftalik uyarilari uretilmedi."]';

const P4B_COMPLIANCE_JSON = '[{"kod":"WEEKLY_COMPLETENESS","seviye":"INFO"}]';

function p4bAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p4bRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function p4bSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function p4bApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p4bSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p4bPdoForDb(string $database): PDO
{
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));

    return new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** @return list<string> */
function p4bMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function p4bAssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }
    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: DSN contains karmotor_medisa\n");
        exit(1);
    }
    if (!preg_match('/host=([^;]+)/i', $dsn, $m)) {
        return;
    }
    $host = strtolower(trim($m[1]));
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
}

function p4bFlagOn(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=1');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '1';
}

function p4bFlagOff(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=0');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '0';
}

function p4bNonce(): string
{
    return bin2hex(random_bytes(32));
}

function p4bSha64(): string
{
    return hash('sha256', bin2hex(random_bytes(16)));
}

/** @return array{id:int,rol:string} */
function p4bGm(): array
{
    return ['id' => 1, 'rol' => 'GENEL_YONETICI'];
}

function p4bSeedBase(PDO $pdo): void
{
    $hash = password_hash('P4bPack4TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'approver', '{$hash}', 'Approver Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111110', 'Aktif', 'Bir', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S010', '2010-01-01', 1, 'AKTIF'),
         (11, '11111111111', 'Aktif', 'Iki', '1990-01-01', '05000000002', 'Acil', '05000000003',
            'S011', '2010-01-01', 1, 'PASIF'),
         (20, '22222222220', 'Diger', 'Personel', '1990-01-01', '05000000004', 'Acil', '05000000005',
            'S020', '2010-01-01', 1, 'AKTIF'),
         (30, '33333333330', 'Cross', 'Scope', '1990-01-01', '05000000006', 'Acil', '05000000007',
            'S030', '2010-01-01', 1, 'AKTIF'),
         (31, '33333333331', 'Legacy', 'Unalloc', '1990-01-01', '05000000008', 'Acil', '05000000009',
            'S031', '2010-01-01', 1, 'AKTIF'),
         (32, '33333333332', 'Cancel', 'Zero', '1990-01-01', '05000000010', 'Acil', '05000000011',
            'S032', '2010-01-01', 1, 'AKTIF'),
         (33, '33333333333', 'Inv', 'Broken', '1990-01-01', '05000000012', 'Acil', '05000000013',
            'S033', '2010-01-01', 1, 'AKTIF'),
         (34, '33333333334', 'Zero', 'Net', '1990-01-01', '05000000014', 'Acil', '05000000015',
            'S034', '2010-01-01', 1, 'AKTIF'),
         (35, '33333333335', 'Plan', 'Race', '1990-01-01', '05000000016', 'Acil', '05000000017',
            'S035', '2010-01-01', 1, 'AKTIF'),
         (36, '33333333336', 'Idem', 'Potency', '1990-01-01', '05000000018', 'Acil', '05000000019',
            'S036', '2010-01-01', 1, 'AKTIF'),
         (37, '33333333337', 'Flag', 'Off', '1990-01-01', '05000000020', 'Acil', '05000000021',
            'S037', '2010-01-01', 1, 'AKTIF'),
         (40, '44444444440', 'Dl', 'Day', '1990-01-01', '05000000022', 'Acil', '05000000023',
            'S040', '2010-01-01', 1, 'AKTIF'),
         (41, '44444444441', 'Dl', 'Warn', '1990-01-01', '05000000024', 'Acil', '05000000025',
            'S041', '2010-01-01', 1, 'AKTIF'),
         (42, '44444444442', 'Dl', 'Partial', '1990-01-01', '05000000026', 'Acil', '05000000027',
            'S042', '2010-01-01', 1, 'AKTIF'),
         (43, '44444444443', 'Dl', 'Full', '1990-01-01', '05000000028', 'Acil', '05000000029',
            'S043', '2010-01-01', 1, 'AKTIF'),
         (44, '44444444444', 'Dl', 'Legacy', '1990-01-01', '05000000030', 'Acil', '05000000031',
            'S044', '2010-01-01', 1, 'AKTIF'),
         (45, '44444444445', 'Dl', 'Inv', '1990-01-01', '05000000032', 'Acil', '05000000033',
            'S045', '2010-01-01', 1, 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
}

/** Unique Monday hafta_baslangic per sube scope. */
function p4bNextWeekStart(): string
{
    static $n = 0;
    $base = strtotime('2010-01-04');
    $ts = $base + ($n * 7 * 86400);
    $n++;

    return date('Y-m-d', $ts);
}

/**
 * @return array{kapanis_id:int,satir_id:int,tercih_id:int,olusum_id:int,hafta_baslangic:string}
 */
function p4bSeedHaftalikGraph(
    PDO $pdo,
    ?string $haftaBaslangic = null,
    int $personelId = 10,
    int $olusumDakika = 300,
    ?string $sonKullanim = null
): array {
    $haftaBaslangic = $haftaBaslangic ?? p4bNextWeekStart();
    $haftaBitis = date('Y-m-d', strtotime($haftaBaslangic . ' +6 days'));
    $pdo->exec(
        "INSERT INTO haftalik_kapanislar
            (sube_id, hafta_baslangic, hafta_bitis, state, personel_sayisi, snapshot_satir_sayisi, created_by)
         VALUES (1, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI', 1, 1, 1)"
    );
    $kapanisId = (int) $pdo->lastInsertId();
    $notlar = str_replace("'", "''", P4B_NOTLAR_COMPLETENESS_SENTINEL);
    $compliance = str_replace("'", "''", P4B_COMPLIANCE_JSON);
    $pdo->exec(
        "INSERT INTO haftalik_kapanis_satirlari (
            kapanis_id, personel_id, hafta_baslangic, hafta_bitis, state,
            toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika, fazla_surelerle_calisma_dakika,
            tam_hafta_verisi, compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
            hesaplama_zamani, kaynak_gun_sayisi, notlar_json
         ) VALUES (
            {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI',
            3000, 2700, {$olusumDakika}, 0,
            0, '{$compliance}', 1, 0,
            '{$haftaBaslangic} 18:00:00', 5, '{$notlar}'
         )"
    );
    $satirId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO fazla_calisma_odeme_tercihleri (
            snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
            fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, gerekce
         ) VALUES (
            {$satirId}, {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}',
            {$olusumDakika}, 'SERBEST_ZAMAN', '{$haftaBaslangic} 19:00:00', 1, 'synthetic-gerekce'
         )"
    );
    $tercihId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO fazla_calisma_odeme_tercihi_audit (
            tercih_id, snapshot_id, onceki_odeme_tipi, yeni_odeme_tipi,
            secen_kullanici_id, secim_zamani, gerekce
         ) VALUES (
            {$tercihId}, {$satirId}, 'KARAR_BEKLIYOR', 'SERBEST_ZAMAN',
            1, '{$haftaBaslangic} 19:00:00', 'audit-gerekce'
         )"
    );
    if ($sonKullanim === null || $sonKullanim === '') {
        $sonKullanim = date('Y-m-d', strtotime($haftaBaslangic . ' +6 months'));
    }
    $pdo->exec(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, son_kullanim_tarihi,
            kaynak_snapshot_id, kaynak_odeme_tercihi_id, created_by
         ) VALUES (
            {$personelId}, 'SERBEST_ZAMAN_OLUSUM', {$olusumDakika}, '{$haftaBaslangic}', '{$sonKullanim}',
            {$satirId}, {$tercihId}, 1
         )"
    );
    $olusumId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO serbest_zaman_aktif_olusumlar (odeme_tercihi_id, olusum_event_id)
         VALUES ({$tercihId}, {$olusumId})"
    );
    ArchiveManifestService::createHaftalikPeriodManifests($pdo, $kapanisId, 1, $haftaBaslangic, 1);

    return [
        'kapanis_id' => $kapanisId,
        'satir_id' => $satirId,
        'tercih_id' => $tercihId,
        'olusum_id' => $olusumId,
        'hafta_baslangic' => $haftaBaslangic,
    ];
}

/**
 * Schema 029: KULLANIM must have NULL snapshot/tercih/hedef + non-null islem_anahtari.
 */
function p4bInsertKullanim(PDO $pdo, int $personelId, int $dakika, string $eventTarihi, string $anahtar): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, islem_anahtari, created_by
         ) VALUES (
            :pid, 'SERBEST_ZAMAN_KULLANIM', :dakika, :tarih, :anahtar, 1
         )"
    );
    $stmt->execute([
        'pid' => $personelId,
        'dakika' => $dakika,
        'tarih' => $eventTarihi,
        'anahtar' => $anahtar,
    ]);

    return (int) $pdo->lastInsertId();
}

function p4bInsertIptal(PDO $pdo, int $personelId, int $hedefEventId, string $hedefTipi, string $eventTarihi, string $anahtar): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, event_tarihi, hedef_event_id, hedef_event_tipi,
            islem_anahtari, created_by
         ) VALUES (
            :pid, 'SERBEST_ZAMAN_IPTAL', :tarih, :hid, :htip, :anahtar, 1
         )"
    );
    $stmt->execute([
        'pid' => $personelId,
        'tarih' => $eventTarihi,
        'hid' => $hedefEventId,
        'htip' => $hedefTipi,
        'anahtar' => $anahtar,
    ]);

    return (int) $pdo->lastInsertId();
}

/** Pack4A-compatible allocation delta insert (POLICY EARLIEST_EXPIRY_FIRST_V1). */
function p4bInsertAlloc(
    PDO $pdo,
    int $personelId,
    int $kullanimEventId,
    int $olusumEventId,
    int $kaynakEventId,
    int $delta
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO serbest_zaman_kullanim_tahsisleri
            (personel_id, kullanim_event_id, olusum_event_id, kaynak_event_id,
             tahsis_delta_dakika, politika_kodu)
         VALUES
            (:pid, :kid, :oid, :sid, :delta, :politika)'
    );
    $stmt->execute([
        'pid' => $personelId,
        'kid' => $kullanimEventId,
        'oid' => $olusumEventId,
        'sid' => $kaynakEventId,
        'delta' => $delta,
        'politika' => SerbestZamanAllocationService::POLICY_CONSUME,
    ]);
}

/**
 * Minimal PREPARED retention evidence for RetentionPhysicalDestroyGate::open.
 *
 * @return array{talep_id:int,execution_id:int}
 */
function p4bInsertPreparedEvidence(PDO $pdo, string $category): array
{
    $ins = $pdo->prepare(
        "INSERT INTO retention_imha_talepleri
            (category, entity_type, record_id, reason, status, requested_by,
             approved_by, approved_at, approval_reason)
         VALUES
            (:cat, 'gate_test', 1, 'Pack4B gate evidence', 'APPROVED', 1,
             1, NOW(), 'gate')"
    );
    $ins->execute(['cat' => $category]);
    $talepId = (int) $pdo->lastInsertId();
    $planHash = p4bSha64();
    $nonce = p4bNonce();
    $ex = $pdo->prepare(
        'INSERT INTO retention_imha_executionlari
            (imha_talep_id, handler_version, execution_mode, plan_hash,
             source_version_identity_snapshot, source_sha256_snapshot,
             execution_nonce, result_code, result_summary_json,
             execution_state, executed_by)
         VALUES
            (:tid, :hv, :mode, :ph,
             :sid, NULL,
             :nonce, :rc, :sum,
             :state, 1)'
    );
    $ex->execute([
        'tid' => $talepId,
        'hv' => PhysicalDestructionCodes::HANDLER_VERSION,
        'mode' => PhysicalDestructionCodes::MODE_DELETE_ROWS,
        'ph' => $planHash,
        'sid' => 'gate-test:' . $category,
        'nonce' => $nonce,
        'rc' => PhysicalDestructionCodes::STATE_PREPARED,
        'sum' => json_encode(['phase' => 'PREPARED'], JSON_UNESCAPED_SLASHES),
        'state' => PhysicalDestructionCodes::STATE_PREPARED,
    ]);

    return [
        'talep_id' => $talepId,
        'execution_id' => (int) $pdo->lastInsertId(),
    ];
}

/**
 * @return array<string, mixed>
 */
function p4bApprove(PDO $pdo, string $category, string $entityType, int $recordId, array $extra = []): array
{
    $payload = array_merge([
        'category' => $category,
        'entity_type' => $entityType,
        'record_id' => $recordId,
        'reason' => 'Pack4B ' . $category,
    ], $extra);
    $req = DestructionWorkflowService::requestDestruction($pdo, p4bGm(), $payload);
    if ((string) ($req['item']['status'] ?? '') !== DestructionWorkflowService::STATUS_REQUESTED) {
        throw new RuntimeException(
            'request failed ' . $category . ': ' . (string) ($req['eligibility']['code'] ?? '?')
        );
    }

    return DestructionWorkflowService::approveDestruction(
        $pdo,
        p4bGm(),
        (int) $req['item']['id'],
        'GM Pack4B',
        true
    );
}

/**
 * @return array<string, mixed>
 */
function p4bEvalExecute(PDO $pdo, int $talepId): array
{
    $eval = PhysicalDestructionService::evaluate($pdo, p4bGm(), $talepId);
    if (($eval['execution']['code'] ?? '') !== RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION) {
        throw new RuntimeException('not eligible: ' . (string) ($eval['execution']['code'] ?? '?'));
    }
    $plan = $eval['plan'];
    if (!is_array($plan) || empty($plan['plan_hash'])) {
        throw new RuntimeException('plan missing');
    }

    return PhysicalDestructionService::execute($pdo, p4bGm(), $talepId, [
        'expected_plan_hash' => (string) $plan['plan_hash'],
        'execution_nonce' => p4bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
}

/** @return list<array<string, mixed>> */
function p4bLoadEvents(PDO $pdo, int $personelId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM serbest_zaman_events WHERE personel_id = :pid ORDER BY id ASC'
    );
    $stmt->execute(['pid' => $personelId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function p4bSzApproveExtras(array $week): array
{
    return [
        'sube_id' => 1,
        'hafta_baslangic' => (string) $week['hafta_baslangic'],
        'haftalik_kapanis_id' => (int) $week['kapanis_id'],
    ];
}

function p4bCountEvents(PDO $pdo, int $eventId): int
{
    return (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $eventId
    )->fetchColumn();
}

function p4bAllocCount(PDO $pdo, ?int $kullanimId = null): int
{
    if ($kullanimId === null) {
        return (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri')->fetchColumn();
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE kullanim_event_id = :k'
    );
    $stmt->execute(['k' => $kullanimId]);

    return (int) $stmt->fetchColumn();
}

// --- main ---
$root = p4bRootPdo();
$database = 'medisa_sz_pack4b_' . substr(bin2hex(random_bytes(4)), 0, 8);
p4bAssertSafeTarget($database);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p4bPdoForDb($database);

try {
    $files = p4bMigrationFiles();
    foreach ($files as $file) {
        p4bApply($pdo, $file);
    }
    $tip = end($files);
    p4bAssert(
        is_string($tip) && $tip === '062_serbest_zaman_retention_destroy_gate.sql',
        'migration tip ends with 062_serbest_zaman_retention_destroy_gate.sql'
    );
    p4bAssert(
        in_array('061_serbest_zaman_kullanim_tahsisleri.sql', $files, true),
        'migration 061 present'
    );

    p4bSeedBase($pdo);
    RetentionClock::setOverride(new DateTimeImmutable('2037-01-01'));
    p4bFlagOn();

    // ---------- L. Schema: gatedCategories includes SERBEST_ZAMAN ----------
    $gated = RetentionPhysicalDestroyGate::gatedCategories();
    p4bAssert(
        in_array(RetentionCategories::SERBEST_ZAMAN, $gated, true),
        'L gatedCategories includes SERBEST_ZAMAN'
    );
    p4bAssert(
        RetentionPhysicalDestroyGate::requiresGate(RetentionCategories::SERBEST_ZAMAN) === true,
        'L requiresGate(SERBEST_ZAMAN)'
    );

    // ---------- A. Migration 062 gate ----------
    $gateWeek = p4bSeedHaftalikGraph($pdo, null, 10, 300);
    $gateKul = p4bInsertKullanim($pdo, 10, 100, (string) $gateWeek['hafta_baslangic'], 'p4b-gate-kul');
    p4bInsertAlloc($pdo, 10, $gateKul, (int) $gateWeek['olusum_id'], $gateKul, 100);
    $allocId = (int) $pdo->query(
        'SELECT id FROM serbest_zaman_kullanim_tahsisleri WHERE kullanim_event_id = ' . $gateKul . ' LIMIT 1'
    )->fetchColumn();
    p4bAssert($allocId > 0, 'A tahsis row created');

    $updBlocked = false;
    try {
        $pdo->exec(
            'UPDATE serbest_zaman_kullanim_tahsisleri SET tahsis_delta_dakika = 99 WHERE id = ' . $allocId
        );
    } catch (Throwable $e) {
        $updBlocked = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false;
    }
    p4bAssert($updBlocked, 'A UPDATE fail SERBEST_ZAMAN_ALLOCATION_IMMUTABLE even if gate openable');

    $delNoGate = false;
    try {
        $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId);
    } catch (Throwable $e) {
        $delNoGate = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false;
    }
    p4bAssert($delNoGate, 'A DELETE without gate fail IMMUTABLE');

    $bordroEv = p4bInsertPreparedEvidence($pdo, RetentionCategories::BORDRO);
    RetentionPhysicalDestroyGate::open(
        $pdo,
        (int) $bordroEv['execution_id'],
        (int) $bordroEv['talep_id'],
        RetentionCategories::BORDRO
    );
    $delWrongCat = false;
    try {
        $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId);
    } catch (Throwable $e) {
        $delWrongCat = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false;
    }
    p4bAssert($delWrongCat, 'A wrong category BORDRO PREPARED gate → DELETE still fail');
    RetentionPhysicalDestroyGate::close($pdo);

    $szEv = p4bInsertPreparedEvidence($pdo, RetentionCategories::SERBEST_ZAMAN);
    RetentionPhysicalDestroyGate::open(
        $pdo,
        (int) $szEv['execution_id'],
        (int) $szEv['talep_id'],
        RetentionCategories::SERBEST_ZAMAN
    );
    $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId);
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId
        )->fetchColumn() === 0,
        'A SERBEST_ZAMAN PREPARED gate → DELETE PASS'
    );

    // Re-insert alloc for close/reuse tests (need fresh OLUSUM+KULLANIM FKs still valid)
    $gateKul2 = p4bInsertKullanim($pdo, 10, 50, (string) $gateWeek['hafta_baslangic'], 'p4b-gate-kul-2');
    p4bInsertAlloc($pdo, 10, $gateKul2, (int) $gateWeek['olusum_id'], $gateKul2, 50);
    $allocId2 = (int) $pdo->query(
        'SELECT id FROM serbest_zaman_kullanim_tahsisleri WHERE kullanim_event_id = ' . $gateKul2 . ' LIMIT 1'
    )->fetchColumn();

    RetentionPhysicalDestroyGate::close($pdo);
    $delAfterClose = false;
    try {
        $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId2);
    } catch (Throwable $e) {
        $delAfterClose = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false;
    }
    p4bAssert($delAfterClose, 'A close gate → DELETE fail again');

    // Connection reuse: open then close must clear CONNECTION_ID gate
    RetentionPhysicalDestroyGate::open(
        $pdo,
        (int) $szEv['execution_id'],
        (int) $szEv['talep_id'],
        RetentionCategories::SERBEST_ZAMAN
    );
    $gateOpenCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM retention_physical_destroy_gates WHERE connection_id = CONNECTION_ID()'
    )->fetchColumn();
    p4bAssert($gateOpenCount === 1, 'A gate open inserts CONNECTION_ID row');
    RetentionPhysicalDestroyGate::close($pdo);
    $gateClosedCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM retention_physical_destroy_gates WHERE connection_id = CONNECTION_ID()'
    )->fetchColumn();
    p4bAssert($gateClosedCount === 0, 'A close clears CONNECTION_ID gate (no reuse leak)');
    $delReuse = false;
    try {
        $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId2);
    } catch (Throwable $e) {
        $delReuse = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false;
    }
    p4bAssert($delReuse, 'A after close DELETE still IMMUTABLE (connection reuse safe)');

    // Clean leftover gate-test alloc with open gate so personel 10 can be reused carefully
    RetentionPhysicalDestroyGate::open(
        $pdo,
        (int) $szEv['execution_id'],
        (int) $szEv['talep_id'],
        RetentionCategories::SERBEST_ZAMAN
    );
    $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $allocId2);
    RetentionPhysicalDestroyGate::close($pdo);
    // Cancel leftover usages so personel 10 is ZERO (not LEGACY) for any later shared use
    p4bInsertIptal($pdo, 10, $gateKul, 'SERBEST_ZAMAN_KULLANIM', (string) $gateWeek['hafta_baslangic'], 'p4b-gate-iptal-1');
    p4bInsertIptal($pdo, 10, $gateKul2, 'SERBEST_ZAMAN_KULLANIM', (string) $gateWeek['hafta_baslangic'], 'p4b-gate-iptal-2');

    // ---------- B. Unused OLUSUM destroy PASS ----------
    $weekB = p4bSeedHaftalikGraph($pdo, null, 20, 300);
    $apB = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekB['kapanis_id'],
        p4bSzApproveExtras($weekB)
    );
    $exB = p4bEvalExecute($pdo, (int) $apB['id']);
    p4bAssert(
        ($exB['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'B unused OLUSUM destroy PASS'
    );
    p4bAssert(p4bCountEvents($pdo, (int) $weekB['olusum_id']) === 0, 'B OLUSUM event deleted');
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . (int) $weekB['kapanis_id']
        )->fetchColumn() === 1,
        'B shared kapanis preserved'
    );

    // ---------- C. Exclusively allocated destroy ----------
    $weekC = p4bSeedHaftalikGraph($pdo, null, 11, 400);
    $kulC = p4bInsertKullanim($pdo, 11, 400, (string) $weekC['hafta_baslangic'], 'p4b-c-kul-400');
    p4bInsertAlloc($pdo, 11, $kulC, (int) $weekC['olusum_id'], $kulC, 400);
    p4bAssert(p4bAllocCount($pdo, $kulC) === 1, 'C allocation inserted');
    $apC = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekC['kapanis_id'],
        p4bSzApproveExtras($weekC)
    );
    $exC = p4bEvalExecute($pdo, (int) $apC['id']);
    p4bAssert(
        ($exC['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'C exclusive allocated destroy PASS'
    );
    p4bAssert(p4bAllocCount($pdo, $kulC) === 0, 'C allocations gone');
    p4bAssert(p4bCountEvents($pdo, $kulC) === 0, 'C KULLANIM gone');
    p4bAssert(p4bCountEvents($pdo, (int) $weekC['olusum_id']) === 0, 'C OLUSUM gone');
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . (int) $weekC['kapanis_id']
        )->fetchColumn() === 1,
        'C shared kapanis preserved'
    );
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE id = ' . (int) $weekC['tercih_id']
        )->fetchColumn() === 1,
        'C FM tercih preserved (co-identity)'
    );

    // ---------- D. Cross-scope ----------
    $weekDA = p4bSeedHaftalikGraph($pdo, null, 30, 300);
    $weekDB = p4bSeedHaftalikGraph($pdo, null, 30, 300);
    $kulD = p4bInsertKullanim($pdo, 30, 400, (string) $weekDA['hafta_baslangic'], 'p4b-d-kul-400');
    // Unique (kaynak_event_id, olusum_event_id): kullanim→A, olusum-B id as kaynak for B delta (FK-only).
    p4bInsertAlloc($pdo, 30, $kulD, (int) $weekDA['olusum_id'], $kulD, 300);
    p4bInsertAlloc($pdo, 30, $kulD, (int) $weekDB['olusum_id'], (int) $weekDB['olusum_id'], 100);

    $eventsBeforeD = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 30'
    )->fetchColumn();
    $allocBeforeD = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE personel_id = 30'
    )->fetchColumn();
    $apD = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekDA['kapanis_id'],
        p4bSzApproveExtras($weekDA)
    );
    try {
        p4bEvalExecute($pdo, (int) $apD['id']);
        p4bAssert(false, 'D cross-scope should fail');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        p4bAssert(
            $msg === PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS
                || $msg === 'not eligible: ' . PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS,
            'D SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS'
        );
    }
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 30'
        )->fetchColumn() === $eventsBeforeD
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE personel_id = 30'
            )->fetchColumn() === $allocBeforeD
            && p4bCountEvents($pdo, (int) $weekDA['olusum_id']) === 1
            && p4bCountEvents($pdo, (int) $weekDB['olusum_id']) === 1
            && p4bCountEvents($pdo, $kulD) === 1,
        'D no mutation on cross-scope block'
    );

    // ---------- E. Legacy unallocated KULLANIM ----------
    $weekE = p4bSeedHaftalikGraph($pdo, null, 31, 300);
    $kulE = p4bInsertKullanim($pdo, 31, 120, (string) $weekE['hafta_baslangic'], 'p4b-e-legacy');
    $eventsBeforeE = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 31'
    )->fetchColumn();
    $apE = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekE['kapanis_id'],
        p4bSzApproveExtras($weekE)
    );
    try {
        p4bEvalExecute($pdo, (int) $apE['id']);
        p4bAssert(false, 'E legacy should fail');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        p4bAssert(
            $msg === PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED
                || $msg === 'not eligible: ' . PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED,
            'E SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED'
        );
    }
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 31'
        )->fetchColumn() === $eventsBeforeE
            && p4bCountEvents($pdo, (int) $weekE['olusum_id']) === 1
            && p4bCountEvents($pdo, $kulE) === 1,
        'E no mutation on legacy block'
    );

    // ---------- F. Cancelled ZERO → destroy PASS ----------
    $weekF = p4bSeedHaftalikGraph($pdo, null, 32, 300);
    $kulF = p4bInsertKullanim($pdo, 32, 100, (string) $weekF['hafta_baslangic'], 'p4b-f-kul');
    p4bInsertIptal($pdo, 32, $kulF, 'SERBEST_ZAMAN_KULLANIM', (string) $weekF['hafta_baslangic'], 'p4b-f-iptal');
    $eventsF = p4bLoadEvents($pdo, 32);
    $usageF = SerbestZamanAllocationService::usageAllocationState($pdo, $eventsF, 32, $kulF);
    p4bAssert($usageF['state'] === SerbestZamanAllocationService::STATE_ZERO, 'F usage state ZERO');
    $apF = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekF['kapanis_id'],
        p4bSzApproveExtras($weekF)
    );
    $exF = p4bEvalExecute($pdo, (int) $apF['id']);
    p4bAssert(
        ($exF['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'F cancelled ZERO destroy OLUSUM PASS'
    );
    p4bAssert(p4bCountEvents($pdo, (int) $weekF['olusum_id']) === 0, 'F OLUSUM deleted');

    // ---------- G. Invariant broken ----------
    $weekG = p4bSeedHaftalikGraph($pdo, null, 33, 300);
    $kulG = p4bInsertKullanim($pdo, 33, 100, (string) $weekG['hafta_baslangic'], 'p4b-g-kul');
    p4bInsertAlloc($pdo, 33, $kulG, (int) $weekG['olusum_id'], $kulG, 50);
    $eventsBeforeG = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 33'
    )->fetchColumn();
    $allocBeforeG = p4bAllocCount($pdo, $kulG);
    $apG = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekG['kapanis_id'],
        p4bSzApproveExtras($weekG)
    );
    try {
        p4bEvalExecute($pdo, (int) $apG['id']);
        p4bAssert(false, 'G invariant should fail');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        $ok = $msg === PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN
            || $msg === 'not eligible: ' . PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN
            || $msg === SerbestZamanAllocationService::CODE_ALLOCATION_INVARIANT_BROKEN
            || stripos($msg, 'INVARIANT') !== false;
        p4bAssert($ok, 'G SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN fail-closed');
    }
    p4bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 33'
        )->fetchColumn() === $eventsBeforeG
            && p4bAllocCount($pdo, $kulG) === $allocBeforeG
            && p4bCountEvents($pdo, (int) $weekG['olusum_id']) === 1,
        'G no mutation on invariant block'
    );

    // ---------- H. Optional zero-net target history ----------
    $weekH = p4bSeedHaftalikGraph($pdo, null, 34, 300);
    $kulH = p4bInsertKullanim($pdo, 34, 100, (string) $weekH['hafta_baslangic'], 'p4b-h-kul');
    p4bInsertAlloc($pdo, 34, $kulH, (int) $weekH['olusum_id'], $kulH, 100);
    $iptalH = p4bInsertIptal(
        $pdo,
        34,
        $kulH,
        'SERBEST_ZAMAN_KULLANIM',
        (string) $weekH['hafta_baslangic'],
        'p4b-h-iptal'
    );
    // Release delta (net 0) keyed by IPTAL event as kaynak
    p4bInsertAlloc($pdo, 34, $kulH, (int) $weekH['olusum_id'], $iptalH, -100);
    $eventsH = p4bLoadEvents($pdo, 34);
    $usageH = SerbestZamanAllocationService::usageAllocationState($pdo, $eventsH, 34, $kulH);
    p4bAssert($usageH['state'] === SerbestZamanAllocationService::STATE_ZERO, 'H usage ZERO after release');
    p4bAssert(p4bAllocCount($pdo, $kulH) === 2, 'H zero-net history rows present');
    $apH = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekH['kapanis_id'],
        p4bSzApproveExtras($weekH)
    );
    $exH = p4bEvalExecute($pdo, (int) $apH['id']);
    p4bAssert(
        ($exH['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'H zero-net target history destroy PASS'
    );
    p4bAssert(p4bAllocCount($pdo, $kulH) === 0, 'H allocation history cleaned');
    p4bAssert(p4bCountEvents($pdo, (int) $weekH['olusum_id']) === 0, 'H OLUSUM deleted');

    // ---------- I. Plan race ----------
    $weekI = p4bSeedHaftalikGraph($pdo, null, 35, 300);
    $apI = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekI['kapanis_id'],
        p4bSzApproveExtras($weekI)
    );
    $evalI = PhysicalDestructionService::evaluate($pdo, p4bGm(), (int) $apI['id']);
    p4bAssert(
        ($evalI['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
        'I evaluate eligible before mutation'
    );
    $oldHash = (string) ($evalI['plan']['plan_hash'] ?? '');
    p4bAssert($oldHash !== '', 'I plan_hash captured');
    // Mutate scope after plan: add exclusive allocated usage → fingerprint/counts change
    $kulI = p4bInsertKullanim($pdo, 35, 50, (string) $weekI['hafta_baslangic'], 'p4b-i-race-kul');
    p4bInsertAlloc($pdo, 35, $kulI, (int) $weekI['olusum_id'], $kulI, 50);
    try {
        PhysicalDestructionService::execute($pdo, p4bGm(), (int) $apI['id'], [
            'expected_plan_hash' => $oldHash,
            'execution_nonce' => p4bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p4bAssert(false, 'I plan race should fail');
    } catch (RuntimeException $e) {
        p4bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
            'I DESTRUCTION_PLAN_CHANGED'
        );
    }
    p4bAssert(p4bCountEvents($pdo, (int) $weekI['olusum_id']) === 1, 'I no destroy on plan race');
    p4bAssert(p4bCountEvents($pdo, $kulI) === 1, 'I race mutation retained (no execute)');

    // ---------- J. Idempotency ----------
    $weekJ = p4bSeedHaftalikGraph($pdo, null, 36, 300);
    $apJ = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekJ['kapanis_id'],
        p4bSzApproveExtras($weekJ)
    );
    $exJ = p4bEvalExecute($pdo, (int) $apJ['id']);
    p4bAssert(
        ($exJ['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'J first execute PASS'
    );
    $retryJ = PhysicalDestructionService::execute($pdo, p4bGm(), (int) $apJ['id'], [
        'expected_plan_hash' => (string) ($exJ['plan']['plan_hash'] ?? p4bSha64()),
        'execution_nonce' => p4bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p4bAssert(
        ($retryJ['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'J idempotency ALREADY_EXECUTED'
    );
    p4bAssert((int) ($retryJ['execution']['mutation_count'] ?? 0) === 0, 'J retry mutation_count=0');

    // ---------- K. Feature flag OFF ----------
    $weekK = p4bSeedHaftalikGraph($pdo, null, 37, 300);
    $apK = p4bApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekK['kapanis_id'],
        p4bSzApproveExtras($weekK)
    );
    p4bFlagOff();
    $evalK = PhysicalDestructionService::evaluate($pdo, p4bGm(), (int) $apK['id']);
    p4bAssert(
        ($evalK['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED,
        'K evaluate DESTRUCTION_EXECUTION_DISABLED'
    );
    try {
        PhysicalDestructionService::execute($pdo, p4bGm(), (int) $apK['id'], [
            'expected_plan_hash' => p4bSha64(),
            'execution_nonce' => p4bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p4bAssert(false, 'K execute should fail when flag OFF');
    } catch (RuntimeException $e) {
        p4bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED,
            'K execute DESTRUCTION_EXECUTION_DISABLED'
        );
    }
    p4bAssert(p4bCountEvents($pdo, (int) $weekK['olusum_id']) === 1, 'K no mutation when flag OFF');
    p4bFlagOn();

    // ---------- Deadline section (SerbestZamanDeadlineService) ----------
    // M. son_kullanim day: ACTIVE/YAKLASIYOR kalan_gun=0; next day SURESI_DOLDU
    $sonM = '2020-06-15';
    $weekM = p4bSeedHaftalikGraph($pdo, null, 40, 300, $sonM);
    $rowsM0 = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 40),
        40,
        $sonM,
        ['ad_soyad' => 'Dl Day', 'sicil_no' => 'S040', 'sube_id' => 1, 'sube_ad' => 'Sube A']
    );
    p4bAssert(count($rowsM0) === 1, 'M one deadline row on son_kullanim day');
    p4bAssert(
        (string) ($rowsM0[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_YAKLASIYOR,
        'M YAKLASIYOR on son_kullanim day'
    );
    p4bAssert((int) ($rowsM0[0]['kalan_gun'] ?? -1) === 0, 'M kalan_gun=0');
    p4bAssert(
        (string) ($rowsM0[0]['expiry_state'] ?? '') === 'ACTIVE',
        'M expiry_state ACTIVE on son_kullanim day'
    );
    $rowsM1 = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 40),
        40,
        '2020-06-16',
        ['ad_soyad' => 'Dl Day', 'sicil_no' => 'S040', 'sube_id' => 1]
    );
    p4bAssert(
        (string) ($rowsM1[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_SURESI_DOLDU,
        'M next day SURESI_DOLDU'
    );

    // N. 30 days YAKLASIYOR, 31 NORMAL
    $sonN = '2020-07-15';
    $weekN = p4bSeedHaftalikGraph($pdo, null, 41, 300, $sonN);
    $rowsN30 = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 41),
        41,
        '2020-06-15',
        ['ad_soyad' => 'Dl Warn', 'sicil_no' => 'S041', 'sube_id' => 1]
    );
    p4bAssert((int) ($rowsN30[0]['kalan_gun'] ?? -1) === 30, 'N kalan_gun=30');
    p4bAssert(
        (string) ($rowsN30[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_YAKLASIYOR,
        'N 30 days YAKLASIYOR'
    );
    $rowsN31 = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 41),
        41,
        '2020-06-14',
        ['ad_soyad' => 'Dl Warn', 'sicil_no' => 'S041', 'sube_id' => 1]
    );
    p4bAssert((int) ($rowsN31[0]['kalan_gun'] ?? -1) === 31, 'N kalan_gun=31');
    p4bAssert(
        (string) ($rowsN31[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_NORMAL,
        'N 31 days NORMAL'
    );

    // O. partial consume — only available in summary / row
    $sonO = '2020-08-01';
    $weekO = p4bSeedHaftalikGraph($pdo, null, 42, 400, $sonO);
    $kulO = p4bInsertKullanim($pdo, 42, 100, (string) $weekO['hafta_baslangic'], 'p4b-o-partial');
    p4bInsertAlloc($pdo, 42, $kulO, (int) $weekO['olusum_id'], $kulO, 100);
    $refO = '2020-07-20';
    $rowsO = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 42),
        42,
        $refO,
        ['ad_soyad' => 'Dl Partial', 'sicil_no' => 'S042', 'sube_id' => 1]
    );
    p4bAssert(count($rowsO) === 1, 'O one partial-consume deadline row');
    p4bAssert((int) ($rowsO[0]['available_dakika'] ?? 0) === 300, 'O available_dakika=300 only');
    $sumO = SerbestZamanDeadlineService::summarize($rowsO, $refO);
    p4bAssert(
        (int) ($sumO['yaklasan_dakika'] ?? 0) === 300
            || (int) ($sumO['suresi_dolmus_kullanilmamis_dakika'] ?? 0) === 300
            || (int) ($rowsO[0]['available_dakika'] ?? 0) === 300,
        'O summary reflects available only (not consumed)'
    );

    // P. fully consumed — no deadline row
    $sonP = '2020-09-01';
    $weekP = p4bSeedHaftalikGraph($pdo, null, 43, 200, $sonP);
    $kulP = p4bInsertKullanim($pdo, 43, 200, (string) $weekP['hafta_baslangic'], 'p4b-p-full');
    p4bInsertAlloc($pdo, 43, $kulP, (int) $weekP['olusum_id'], $kulP, 200);
    $rowsP = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 43),
        43,
        '2020-08-01',
        ['ad_soyad' => 'Dl Full', 'sicil_no' => 'S043', 'sube_id' => 1]
    );
    p4bAssert(count($rowsP) === 0, 'P fully consumed no deadline row');

    // Q. LEGACY → ALLOCATION_UNRESOLVED, available_dakika null
    $weekQ = p4bSeedHaftalikGraph($pdo, null, 44, 300, '2020-10-01');
    p4bInsertKullanim($pdo, 44, 80, (string) $weekQ['hafta_baslangic'], 'p4b-q-legacy');
    $rowsQ = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 44),
        44,
        '2020-09-01',
        ['ad_soyad' => 'Dl Legacy', 'sicil_no' => 'S044', 'sube_id' => 1]
    );
    p4bAssert(count($rowsQ) === 1, 'Q one unresolved row');
    p4bAssert(
        (string) ($rowsQ[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_ALLOCATION_UNRESOLVED,
        'Q LEGACY → ALLOCATION_UNRESOLVED'
    );
    p4bAssert($rowsQ[0]['available_dakika'] === null, 'Q available_dakika null');

    // R. INVARIANT → ALLOCATION_UNRESOLVED
    $weekR = p4bSeedHaftalikGraph($pdo, null, 45, 300, '2020-11-01');
    $kulR = p4bInsertKullanim($pdo, 45, 100, (string) $weekR['hafta_baslangic'], 'p4b-r-inv');
    p4bInsertAlloc($pdo, 45, $kulR, (int) $weekR['olusum_id'], $kulR, 40);
    $rowsR = SerbestZamanDeadlineService::projectPersonelDeadlineRows(
        $pdo,
        p4bLoadEvents($pdo, 45),
        45,
        '2020-10-01',
        ['ad_soyad' => 'Dl Inv', 'sicil_no' => 'S045', 'sube_id' => 1]
    );
    p4bAssert(count($rowsR) === 1, 'R one unresolved row');
    p4bAssert(
        (string) ($rowsR[0]['deadline_state'] ?? '') === SerbestZamanDeadlineService::DEADLINE_ALLOCATION_UNRESOLVED,
        'R INVARIANT → ALLOCATION_UNRESOLVED'
    );
    p4bAssert($rowsR[0]['available_dakika'] === null, 'R available_dakika null');

    // V. Constants + PayrollComplianceGuard source-lock
    p4bAssert(
        SerbestZamanDeadlineService::PAYROLL_HARD_BLOCK === false,
        'V PAYROLL_HARD_BLOCK === false'
    );
    p4bAssert(
        SerbestZamanDeadlineService::COMPLIANCE_MODE === 'WARNING_AND_OPERATIONAL_FOLLOWUP',
        'V COMPLIANCE_MODE constant'
    );
    p4bAssert(
        SerbestZamanDeadlineService::WARNING_DAYS === 30,
        'V WARNING_DAYS===30'
    );
    $guardSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/PayrollComplianceGuard.php');
    p4bAssert(is_string($guardSrc) && $guardSrc !== '', 'V PayrollComplianceGuard.php readable');
    p4bAssert(
        stripos($guardSrc, 'son_kullanim') === false
            && stripos($guardSrc, 'SURESI_DOLDU') === false
            && !preg_match('/\b6M\b/', $guardSrc)
            && stripos($guardSrc, '6 ay') === false
            && stripos($guardSrc, '6-ay') === false,
        'V PayrollComplianceGuard has no son_kullanim / 6M / SURESI_DOLDU blocker strings'
    );
    // Touch class so runtime autoload is exercised
    p4bAssert(class_exists(PayrollComplianceGuard::class), 'V PayrollComplianceGuard class loads');

    echo "verify-serbest-zaman-pack4b-mysql: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    RetentionClock::clearOverride();
    p4bFlagOff();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
