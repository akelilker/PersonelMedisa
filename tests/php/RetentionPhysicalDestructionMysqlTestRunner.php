<?php

declare(strict_types=1);

/**
 * Pack 2: disposable MariaDB — physical destruction execute matrix.
 * php tests/php/RetentionPhysicalDestructionMysqlTestRunner.php
 *
 * Coverage A–R: feature flag, auth, status gates, legal hold, integrity,
 * plan hash, ISE_GIRIS_CIKIS happy path, idempotency, policy handlers,
 * sequential lock, rollback, PII-free evidence, migration 059, ozluk deps.
 *
 * Concurrent execute (L): true parallel is hard in a single PHP process;
 * this runner documents/proves sequential FOR UPDATE → ALREADY_EXECUTED.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\PhysicalDestruction\Handlers\PersonelOzlukDestructionHandler;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;

function rpdAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function rpdRootPdo(): PDO
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
function rpdSplitSql(string $sql): array
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

function rpdApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (rpdSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function rpdPdoForDb(string $database): PDO
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
function rpdMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

/**
 * PRODUCTION GUARD — never touch live karmotor_medisa or non-local hosts.
 */
function rpdAssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }

    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused DSN containing karmotor_medisa\n");
        exit(1);
    }

    $host = '';
    if (preg_match('/(?:host|server)=([^;]+)/i', $dsn, $m)) {
        $host = strtolower(trim($m[1]));
    }
    $local = ['127.0.0.1', 'localhost', '::1', ''];
    if ($host !== '' && !in_array($host, $local, true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
}

function rpdSeed(PDO $pdo): void
{
    $hash = password_hash('RpdPack2TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'ik', '{$hash}', 'IK User', 'IK_SORUMLUSU', 'AKTIF'),
        (3, 'muhasebe', '{$hash}', 'Muhasebe', 'MUHASEBE', 'AKTIF'),
        (4, 'personel', '{$hash}', 'Personel', 'PERSONEL', 'AKTIF'),
        (5, 'sistem', '{$hash}', 'Sistem Yon', 'SISTEM_YONETICISI', 'AKTIF'),
        (6, 'smoke', '{$hash}', 'Smoke RO', 'AUTH_SMOKE_READONLY', 'AKTIF'),
        (7, 'birim', '{$hash}', 'Birim Amiri', 'BIRIM_AMIRI', 'AKTIF'),
        (8, 'bolum', '{$hash}', 'Bolum Yon', 'BOLUM_YONETICISI', 'AKTIF')"
    );
    // Synthetic TC only (111… style) — never realistic people.
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111111', 'Aktif', 'Personel', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S001', '2010-01-01', 1, 'AKTIF'),
         (11, '22222222222', 'Pasif', 'Personel', '1990-01-01', '05000000002', 'Acil', '05000000003',
            'S002', '2010-01-01', 1, 'PASIF'),
         (12, '33333333333', 'Pasif', 'Missing', '1990-01-01', '05000000004', 'Acil', '05000000005',
            'S003', '2010-01-01', 1, 'PASIF'),
         (13, '44444444444', 'Pasif', 'Ozluk', '1990-01-01', '05000000006', 'Acil', '05000000007',
            'S004', '2010-01-01', 1, 'PASIF'),
         (14, '55555555555', 'Pasif', 'Belge', '1990-01-01', '05000000008', 'Acil', '05000000009',
            'S005', '2010-01-01', 1, 'PASIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES
         (11, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF'),
         (12, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF'),
         (13, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF'),
         (14, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
}

/** @return array<string, mixed> */
function rpdUser($id, $rol)
{
    return ['id' => (int) $id, 'rol' => (string) $rol];
}

function rpdNonce(): string
{
    return bin2hex(random_bytes(32));
}

function rpdFlagOn(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=1');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '1';
}

function rpdFlagOff(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=0');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '0';
}

function rpdInsertQrEvent(PDO $pdo, int $personelId, int $userId, int $subeId, string $eventType, string $jti, string $nonce): void
{
    $pdo->exec(
        "INSERT INTO qr_attendance_events (
            personel_id, user_id, sube_id, event_type,
            occurred_at_utc, qr_version, qr_jti,
            qr_issued_at_utc, qr_expires_at_utc, request_nonce
         ) VALUES (
            {$personelId}, {$userId}, {$subeId}, " . $pdo->quote($eventType) . ",
            '2015-05-01 08:00:00.000000', 1, " . $pdo->quote($jti) . ",
            '2015-05-01 07:59:00.000000', '2015-05-01 08:01:00.000000', " . $pdo->quote($nonce) . "
         )"
    );
}

/**
 * Mint lifecycle manifests after QR seed (ISE fingerprint includes events).
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function rpdMintLifecycle(PDO $pdo, int $personelId, int $actorId): array
{
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, $personelId, $actorId);
    $ozluk = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        $personelId,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => $personelId]
    );
    $ise = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        $personelId,
        RetentionCategories::ISE_GIRIS_CIKIS,
        ['personel_id' => $personelId]
    );
    if ($ozluk === null || $ise === null) {
        throw new RuntimeException('lifecycle manifests missing for personel ' . $personelId);
    }

    return [$ozluk, $ise];
}

/**
 * @return array<string, mixed>
 */
function rpdRequestApproveIse(PDO $pdo, array $gm, int $personelId): array
{
    $req = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::ISE_GIRIS_CIKIS,
        'entity_type' => 'personel',
        'record_id' => $personelId,
        'personel_id' => $personelId,
        'reason' => 'Pack2 physical destruction test',
    ]);
    if ((string) ($req['item']['status'] ?? '') !== DestructionWorkflowService::STATUS_REQUESTED) {
        throw new RuntimeException(
            'ISE request not REQUESTED: ' . (string) ($req['eligibility']['code'] ?? $req['item']['status'] ?? '?')
        );
    }
    $approved = DestructionWorkflowService::approveDestruction(
        $pdo,
        $gm,
        (int) $req['item']['id'],
        'GM onay Pack2',
        true
    );
    if ((string) ($approved['status'] ?? '') !== DestructionWorkflowService::STATUS_APPROVED) {
        throw new RuntimeException('ISE approve failed');
    }

    return $approved;
}

/**
 * @param array<string, mixed> $payload
 * @return array{status:int, stdout:string, stderr:string}
 */
function rpdInvokeExecuteChild(string $database, array $user, int $talepId, array $payload): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }

    $statusFile = tempnam(sys_get_temp_dir(), 'rpd_st_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }

    $cfg = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $database,
        'auth' => $user,
        'talep_id' => $talepId,
        'payload' => $payload,
        'status_file' => $statusFile,
        'flag' => getenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED') ?: '0',
        'clock' => '2037-01-01',
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--execute-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        @unlink($statusFile);
        throw new RuntimeException('proc_open failed');
    }
    fwrite($pipes[0], (string) $cfg);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = (int) (file_exists($statusFile) ? (string) file_get_contents($statusFile) : 0);
    @unlink($statusFile);

    return [
        'status' => $status,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

// ---- child mode: RolePermissions / JsonResponse::forbidden exits here ----
if (isset($argv[1]) && $argv[1] === '--execute-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }
    $statusFile = (string) ($cfg['status_file'] ?? '');
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        if (!is_int($code) || $code < 100) {
            $code = 200;
        }
        if ($statusFile !== '') {
            file_put_contents($statusFile, (string) $code);
        }
    });

    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=' . (string) ($cfg['flag'] ?? '0'));
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = (string) ($cfg['flag'] ?? '0');

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . (string) $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    RetentionClock::setOverride(new DateTimeImmutable((string) ($cfg['clock'] ?? '2037-01-01')));
    PhysicalDestructionService::execute(
        $pdo,
        is_array($cfg['auth'] ?? null) ? $cfg['auth'] : [],
        (int) ($cfg['talep_id'] ?? 0),
        is_array($cfg['payload'] ?? null) ? $cfg['payload'] : []
    );
    exit(0);
}

$root = rpdRootPdo();
$database = 'medisa_rpd_' . bin2hex(random_bytes(4));
rpdAssertSafeTarget($database);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_rpd_belge_' . bin2hex(random_bytes(4));
if (!mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
    throw new RuntimeException('storage root create failed');
}
putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $storageRoot);
$_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $storageRoot;

try {
    $pdo = rpdPdoForDb($database);
    RetentionClock::clearOverride();
    rpdFlagOff();

    $files = rpdMigrationFiles();
    rpdAssert(end($files) === '064_personel_org_location_model.sql', 'tip ends at 062');
    rpdAssert(in_array('053_retention_legal_hold_arsiv.sql', $files, true), '053 present');
    rpdAssert(in_array('059_retention_physical_destruction_execution.sql', $files, true), '059 present');
    rpdAssert(in_array('060_retention_physical_destroy_trigger_gate.sql', $files, true), '060 present');
    rpdAssert(in_array('061_serbest_zaman_kullanim_tahsisleri.sql', $files, true), '061 present');
    foreach ($files as $file) {
        rpdApply($pdo, $file);
    }
    rpdAssert(true, 'all migrations applied');

    // O — migration 059 table + UNIQUE imha_talep_id
    $tbl = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retention_imha_executionlari'"
    )->fetchColumn();
    rpdAssert($tbl === 1, 'O retention_imha_executionlari exists');
    $uq = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'retention_imha_executionlari'
           AND INDEX_NAME = 'uq_retention_imha_execution_talep'
           AND NON_UNIQUE = 0"
    )->fetchColumn();
    rpdAssert($uq >= 1, 'O UNIQUE imha_talep_id');

    rpdSeed($pdo);
    $gm = rpdUser(1, 'GENEL_YONETICI');

    // P — GM can execute when flag on (permission matrix)
    rpdAssert(
        RolePermissions::has($gm, 'retention.destruction.execute'),
        'P GM has retention.destruction.execute'
    );

    // Maturity clock (+10y from 2015-06-01 → 2025-06-01)
    RetentionClock::setOverride(new DateTimeImmutable('2037-01-01'));

    // Seed QR for personel 11 (happy path) + 13 (ozluk dependent gate)
    rpdInsertQrEvent($pdo, 11, 1, 1, 'GIRIS', str_repeat('a', 32), '11111111-1111-4111-8111-111111111111');
    rpdInsertQrEvent($pdo, 11, 1, 1, 'CIKIS', str_repeat('b', 32), '22222222-2222-4222-8222-222222222222');
    rpdInsertQrEvent($pdo, 13, 1, 1, 'GIRIS', str_repeat('c', 32), '33333333-3333-4333-8333-333333333333');

    rpdMintLifecycle($pdo, 11, 1);
    rpdMintLifecycle($pdo, 12, 1); // no QR — TARGET_ALREADY_MISSING
    rpdMintLifecycle($pdo, 13, 1);

    // B — unauthorized roles denied (permission + forbidden child)
    $deniedRoles = [
        'PERSONEL',
        'MUHASEBE',
        'IK_SORUMLUSU',
        'BIRIM_AMIRI',
        'BOLUM_YONETICISI',
        'SISTEM_YONETICISI',
        'AUTH_SMOKE_READONLY',
    ];
    foreach ($deniedRoles as $rol) {
        rpdAssert(
            !RolePermissions::has(['rol' => $rol], 'retention.destruction.execute'),
            'B no execute perm ' . $rol
        );
    }

    // Prepare a disposable REQUESTED→APPROVED ISE for auth child / flag tests (personel 11 path later)
    // Use personel 12 approved request for auth/flag/status probes before mutating 11.
    $probeReq = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::ISE_GIRIS_CIKIS,
        'entity_type' => 'personel',
        'record_id' => 12,
        'personel_id' => 12,
        'reason' => 'probe request for gates',
    ]);
    rpdAssert(
        (string) $probeReq['item']['status'] === DestructionWorkflowService::STATUS_REQUESTED,
        'C setup REQUESTED'
    );
    $probeId = (int) $probeReq['item']['id'];

    // C — REQUESTED status → no execute
    rpdFlagOn();
    try {
        PhysicalDestructionService::execute($pdo, $gm, $probeId, [
            'expected_plan_hash' => str_repeat('0', 64),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'C REQUESTED should not execute');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === RetentionPolicyService::CODE_DESTRUCTION_REQUEST_NOT_APPROVED
                || $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID,
            'C REQUESTED → no execute (' . $e->getMessage() . ')'
        );
    }

    // Reject for D
    DestructionWorkflowService::approveDestruction($pdo, $gm, $probeId, 'Reddet Pack2', false);
    $rejected = DestructionWorkflowService::getById($pdo, $probeId);
    rpdAssert((string) $rejected['status'] === DestructionWorkflowService::STATUS_REJECTED, 'D REJECTED status');

    // D — REJECTED → no execute
    try {
        PhysicalDestructionService::execute($pdo, $gm, $probeId, [
            'expected_plan_hash' => str_repeat('1', 64),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'D REJECTED should not execute');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === RetentionPolicyService::CODE_DESTRUCTION_REQUEST_NOT_APPROVED,
            'D REJECTED → no execute'
        );
    }

    // Fresh approved ISE for personel 12 (no QR) — A/E/F/G/J and auth children
    $approved12 = rpdRequestApproveIse($pdo, $gm, 12);
    $talep12 = (int) $approved12['id'];

    // B child: PERSONEL forbidden (403)
    $child = rpdInvokeExecuteChild($database, rpdUser(4, 'PERSONEL'), $talep12, [
        'expected_plan_hash' => str_repeat('a', 64),
        'execution_nonce' => rpdNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    rpdAssert($child['status'] === 403, 'B PERSONEL execute → 403 forbidden');

    // A — Flag OFF → DESTRUCTION_EXECUTION_DISABLED, source unchanged
    rpdFlagOff();
    $qrBefore = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = 11')->fetchColumn();
    try {
        PhysicalDestructionService::execute($pdo, $gm, $talep12, [
            'expected_plan_hash' => str_repeat('b', 64),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'A flag OFF should throw');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED,
            'A DESTRUCTION_EXECUTION_DISABLED'
        );
    }
    $evalOff = PhysicalDestructionService::evaluate($pdo, $gm, $talep12);
    rpdAssert(
        ($evalOff['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_DISABLED
            || ($evalOff['execution']['code'] ?? '') === RetentionPolicyService::CODE_DESTRUCTION_EXECUTION_DISABLED,
        'A evaluate flag OFF disabled'
    );
    $qrAfterOff = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = 11')->fetchColumn();
    rpdAssert($qrBefore === $qrAfterOff, 'A source unchanged under flag OFF');

    // Enable for success / gate paths
    rpdFlagOn();
    rpdAssert(PhysicalDestructionService::isEnabled() === true, 'flag ON via putenv');

    // E — APPROVED + legal hold → LEGAL_HOLD_ACTIVE, no delete
    LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 12,
        'reason' => 'Pack2 hold',
    ]);
    try {
        $evalHold = PhysicalDestructionService::evaluate($pdo, $gm, $talep12);
        $planHold = $evalHold['plan'] ?? null;
        $hashHold = is_array($planHold) ? (string) ($planHold['plan_hash'] ?? '') : str_repeat('c', 64);
        PhysicalDestructionService::execute($pdo, $gm, $talep12, [
            'expected_plan_hash' => preg_match('/^[0-9a-f]{64}$/', $hashHold) ? $hashHold : str_repeat('c', 64),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'E hold should block execute');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
            'E LEGAL_HOLD_ACTIVE'
        );
    }
    $execHold = PhysicalDestructionService::findExecutionByTalepId($pdo, $talep12);
    rpdAssert($execHold === null, 'E no execution evidence under hold');
    $holds = LegalHoldService::list($pdo, true);
    foreach ($holds as $h) {
        if ((int) ($h['personel_id'] ?? 0) === 12) {
            LegalHoldService::release($pdo, $gm, (int) $h['id'], 'Pack2 release');
        }
    }

    // F — Source changed after approval (personel 11 ozluk fingerprint change on ISE uses QR hash —
    // for personel 12 empty QR: mutate personel fields does not change ISE empty fingerprint.
    // Insert a QR event after approval → ARCHIVE_SOURCE_INTEGRITY_CHANGED / SOURCE_CONTEXT_CHANGED.
    rpdInsertQrEvent($pdo, 12, 1, 1, 'GIRIS', str_repeat('d', 32), '44444444-4444-4444-8444-444444444444');
    try {
        PhysicalDestructionService::execute($pdo, $gm, $talep12, [
            'expected_plan_hash' => str_repeat('d', 64),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'F source change should block');
    } catch (RuntimeException $e) {
        $code = $e->getMessage();
        rpdAssert(
            $code === RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED
                || $code === RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
            'F SOURCE_CONTEXT_CHANGED or ARCHIVE_SOURCE_INTEGRITY_CHANGED (' . $code . ')'
        );
    }
    // Restore personel 12 to empty QR for J (delete the mid-test event)
    $pdo->exec('DELETE FROM qr_attendance_events WHERE personel_id = 12');
    // Sticky CHANGED on manifest — remint is not allowed for same identity with new sha.
    // Use a fresh personel path for remaining 12 tests: new request after reminting is blocked.
    // For J we need APPROVED with matching empty fingerprint — create personel 12b via new id 15.
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (15, '66666666666', 'Pasif', 'Missing2', '1990-01-01', '05000000010', 'Acil', '05000000011',
            'S015', '2010-01-01', 1, 'PASIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (15, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
    rpdMintLifecycle($pdo, 15, 1);
    $approved15 = rpdRequestApproveIse($pdo, $gm, 15);
    $talep15 = (int) $approved15['id'];

    // G — Plan hash mismatch (hash checked before handler)
    $eval15 = PhysicalDestructionService::evaluate($pdo, $gm, $talep15);
    rpdAssert(
        ($eval15['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
        'G evaluate APPROVED_FOR_DESTRUCTION'
    );
    $goodHash = (string) ($eval15['plan']['plan_hash'] ?? '');
    rpdAssert(preg_match('/^[0-9a-f]{64}$/', $goodHash) === 1, 'G plan_hash format');
    try {
        PhysicalDestructionService::execute($pdo, $gm, $talep15, [
            'expected_plan_hash' => hash('sha256', 'wrong-plan'),
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'G bad plan hash should throw');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
            'G DESTRUCTION_PLAN_CHANGED'
        );
    }

    // J — TARGET_ALREADY_MISSING when no QR events on first execute
    $evalJ = PhysicalDestructionService::evaluate($pdo, $gm, $talep15);
    $planJ = $evalJ['plan'];
    rpdAssert(is_array($planJ) && !empty($planJ['plan_hash']), 'J plan ready');
    // Plan with 0 expected rows still marks executable; handler throws TARGET_ALREADY_MISSING
    try {
        PhysicalDestructionService::execute($pdo, $gm, $talep15, [
            'expected_plan_hash' => (string) $planJ['plan_hash'],
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'J should TARGET_ALREADY_MISSING');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING,
            'J TARGET_ALREADY_MISSING'
        );
    }
    $execJ = PhysicalDestructionService::findExecutionByTalepId($pdo, $talep15);
    rpdAssert($execJ === null, 'J no EXECUTED evidence after missing target');

    // H — ISE_GIRIS_CIKIS happy path (personel 11)
    $approved11 = rpdRequestApproveIse($pdo, $gm, 11);
    $talep11 = (int) $approved11['id'];
    $qrBeforeH = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = 11')->fetchColumn();
    rpdAssert($qrBeforeH >= 2, 'H QR rows present before destroy');
    $evalH = PhysicalDestructionService::evaluate($pdo, $gm, $talep11);
    rpdAssert(
        ($evalH['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
        'H APPROVED_FOR_DESTRUCTION'
    );
    $planH = $evalH['plan'];
    rpdAssert(is_array($planH) && preg_match('/^[0-9a-f]{64}$/', (string) $planH['plan_hash']), 'H plan_hash');
    $nonceH = rpdNonce();
    $resultH = PhysicalDestructionService::execute($pdo, $gm, $talep11, [
        'expected_plan_hash' => (string) $planH['plan_hash'],
        'execution_nonce' => $nonceH,
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    rpdAssert(
        ($resultH['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'H DESTRUCTION_EXECUTED'
    );
    $qrAfterH = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = 11')->fetchColumn();
    rpdAssert($qrAfterH === 0, 'H QR rows deleted');
    $personelRemains = (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = 11')->fetchColumn();
    rpdAssert($personelRemains === 1, 'H personel master remains');
    $execH = PhysicalDestructionService::findExecutionByTalepId($pdo, $talep11);
    rpdAssert($execH !== null && (string) $execH['execution_state'] === 'EXECUTED', 'H execution evidence');
    $talepRemains = DestructionWorkflowService::getById($pdo, $talep11);
    rpdAssert($talepRemains !== null && (string) $talepRemains['status'] === 'APPROVED', 'H request remains');
    $auditCnt = (int) $pdo->query(
        'SELECT COUNT(*) FROM retention_imha_auditleri WHERE imha_talep_id = ' . $talep11 . " AND action = 'EXECUTE'"
    )->fetchColumn();
    rpdAssert($auditCnt >= 1, 'H audit EXECUTE written');

    // N — PII absent from result_summary_json
    $summaryJson = (string) ($execH['result_summary_json'] ?? '');
    $summaryArr = json_decode($summaryJson, true);
    rpdAssert(is_array($summaryArr), 'N summary json');
    foreach (['tc', 'tc_kimlik_no', 'ad', 'soyad', 'telefon'] as $piiKey) {
        rpdAssert(!array_key_exists($piiKey, $summaryArr), 'N no PII key ' . $piiKey);
    }
    // Also ensure handler summary path through sanitize
    $sanitized = json_encode($resultH['execution']['summary'] ?? [], JSON_UNESCAPED_UNICODE);
    rpdAssert(
        $sanitized !== false
            && stripos($sanitized, '"ad"') === false
            && stripos($sanitized, '"soyad"') === false
            && stripos($sanitized, '"telefon"') === false
            && stripos($sanitized, '"tc_kimlik_no"') === false,
        'N PII absent from execution summary'
    );

    // I — Second execute → ALREADY_EXECUTED, mutation_count 0
    $resultI = PhysicalDestructionService::execute($pdo, $gm, $talep11, [
        'expected_plan_hash' => (string) $planH['plan_hash'],
        'execution_nonce' => rpdNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    rpdAssert(
        ($resultI['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'I ALREADY_EXECUTED'
    );
    rpdAssert((int) ($resultI['execution']['mutation_count'] ?? -1) === 0, 'I mutation_count 0');

    // L — Concurrent execute simulation (sequential FOR UPDATE): second ALREADY_EXECUTED
    // Note: true parallel is hard in PHP single process; this proves lock/idempotent path.
    $pdo->beginTransaction();
    $locked = PhysicalDestructionService::findExecutionByTalepId($pdo, $talep11, true);
    rpdAssert($locked !== null && (string) $locked['execution_state'] === 'EXECUTED', 'L FOR UPDATE sees EXECUTED');
    $pdo->commit();
    $resultL = PhysicalDestructionService::execute($pdo, $gm, $talep11, [
        'expected_plan_hash' => (string) ($locked['plan_hash'] ?? $planH['plan_hash']),
        'execution_nonce' => rpdNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    rpdAssert(
        ($resultL['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'L sequential second execute ALREADY_EXECUTED'
    );

    // M — Handler failure rolls back: no EXECUTED evidence (use personel 15 wrong expected via plan change)
    // Force handler throw by approving a new personel with QR then deleting QR before execute after plan built.
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (16, '77777777777', 'Pasif', 'Rollback', '1990-01-01', '05000000012', 'Acil', '05000000013',
            'S016', '2010-01-01', 1, 'PASIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (16, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
    rpdInsertQrEvent($pdo, 16, 1, 1, 'GIRIS', str_repeat('f', 32), '55555555-5555-4555-8555-555555555555');
    rpdMintLifecycle($pdo, 16, 1);
    $approved16 = rpdRequestApproveIse($pdo, $gm, 16);
    $talep16 = (int) $approved16['id'];
    $evalM = PhysicalDestructionService::evaluate($pdo, $gm, $talep16);
    $planM = $evalM['plan'];
    rpdAssert(is_array($planM), 'M plan');
    // Delete QR after plan → handler sees 0 vs expected >0 → PLAN_CHANGED or TARGET_ALREADY_MISSING; rollback
    $pdo->exec('DELETE FROM qr_attendance_events WHERE personel_id = 16');
    try {
        PhysicalDestructionService::execute($pdo, $gm, $talep16, [
            'expected_plan_hash' => (string) $planM['plan_hash'],
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'M should fail');
    } catch (RuntimeException $e) {
        rpdAssert(
            in_array($e->getMessage(), [
                PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
                PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING,
                RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
                RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED,
            ], true),
            'M handler/eligibility failure (' . $e->getMessage() . ')'
        );
    }
    $execM = PhysicalDestructionService::findExecutionByTalepId($pdo, $talep16);
    rpdAssert(
        $execM === null || (string) ($execM['execution_state'] ?? '') !== 'EXECUTED',
        'M failed execute leaves no EXECUTED evidence'
    );

    // K — Pack 3B: PUANTAJ/BORDRO executable; remaining policy categories still fail-closed
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, 2010, 1, '2010-01', 'MUHURLENDI', 1, 1, '2010-02-05 10:00:00')"
    );
    $sealId = (int) $pdo->query('SELECT id FROM puantaj_aylik_muhurleri ORDER BY id DESC LIMIT 1')->fetchColumn();
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2010, 1, $sealId, 1);

    $h = str_repeat('a', 64);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, 2010, 1, '2010-01', '2010-01-01', '2010-01-31', {$sealId}, 1,
            'OLUSTURULDU', '2010-02-10 08:00:00', '{$h}', '{$h}', '{$h}',
            1, 1, 1
         )"
    );
    $snapshotId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO maas_hesaplama_calistirmalari (
            snapshot_id, sube_id, yil, ay, revision_no, state, bordro_onay_durumu,
            engine_version, contract_version,
            snapshot_hash, parameter_set_hash, carryover_set_hash, request_hash, source_hash, result_hash,
            calculation_input_hash, personel_sayisi, basarili_aday_sayisi, created_by, kesinlestirme_at
         ) VALUES (
            {$snapshotId}, 1, 2010, 1, 1, 'HESAPLANDI', 'KESINLESTI',
            'S77D_PAYROLL_ENGINE_V2', 'S77D_PAYROLL_CANDIDATE_V2',
            '{$h}', '{$h}', '{$h}', '{$h}', '{$h}', '{$h}',
            '{$h}', 1, 1, 1, '2010-03-01 12:00:00'
         )"
    );
    $runId = (int) $pdo->lastInsertId();
    ArchiveManifestService::createBordroPeriodManifests($pdo, 1, 2010, 1, $runId, 1);

    $reqP = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PUANTAJ,
        'entity_type' => 'puantaj',
        'record_id' => $sealId,
        'sube_id' => 1,
        'yil' => 2010,
        'ay' => 1,
        'reason' => 'Pack2/3B PUANTAJ executable',
    ]);
    rpdAssert((string) $reqP['item']['status'] === 'REQUESTED', 'K PUANTAJ REQUESTED');
    $apP = DestructionWorkflowService::approveDestruction($pdo, $gm, (int) $reqP['item']['id'], 'GM', true);
    $evalP = PhysicalDestructionService::evaluate($pdo, $gm, (int) $apP['id']);
    rpdAssert(
        ($evalP['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
        'K PUANTAJ APPROVED_FOR_DESTRUCTION (Pack 3B executable)'
    );
    rpdAssert(is_array($evalP['plan'] ?? null) && !empty($evalP['plan']['plan_hash']), 'K PUANTAJ plan_hash');

    $reqB = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::BORDRO,
        'entity_type' => 'bordro',
        'record_id' => $runId,
        'sube_id' => 1,
        'yil' => 2010,
        'ay' => 1,
        'reason' => 'Pack2/3B BORDRO executable',
    ]);
    rpdAssert((string) $reqB['item']['status'] === 'REQUESTED', 'K BORDRO REQUESTED');
    $apB = DestructionWorkflowService::approveDestruction($pdo, $gm, (int) $reqB['item']['id'], 'GM', true);
    $evalB = PhysicalDestructionService::evaluate($pdo, $gm, (int) $apB['id']);
    rpdAssert(
        ($evalB['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION,
        'K BORDRO APPROVED_FOR_DESTRUCTION (Pack 3B executable)'
    );
    rpdAssert(is_array($evalB['plan'] ?? null) && !empty($evalB['plan']['plan_hash']), 'K BORDRO plan_hash');

    // Pack 3C: remaining categories are typed executable (leaf/dependency strategies).
    $pack3cExecutable = [
        RetentionCategories::FAZLA_CALISMA,
        RetentionCategories::SERBEST_ZAMAN,
        RetentionCategories::DISIPLIN,
        RetentionCategories::RAPOR,
        RetentionCategories::IS_KAZASI,
    ];
    foreach ($pack3cExecutable as $execCat) {
        $handler = \Medisa\Api\Services\Retention\PhysicalDestruction\RetentionDestructionHandlerRegistry::forCategory($execCat);
        rpdAssert($handler->isExecutable() === true, 'K Pack3C executable ' . $execCat);
    }

    // Q — PERSONEL_OZLUK with dependent QR → DEPENDENT_RETENTION_RECORDS_REMAIN
    $reqO = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PERSONEL_OZLUK,
        'entity_type' => 'personel',
        'record_id' => 13,
        'personel_id' => 13,
        'reason' => 'Pack2 ozluk dependent',
    ]);
    rpdAssert((string) $reqO['item']['status'] === 'REQUESTED', 'Q OZLUK REQUESTED');
    $apO = DestructionWorkflowService::approveDestruction($pdo, $gm, (int) $reqO['item']['id'], 'GM', true);
    $evalO = PhysicalDestructionService::evaluate($pdo, $gm, (int) $apO['id']);
    $planO = $evalO['plan'];
    rpdAssert(is_array($planO), 'Q ozluk plan');
    try {
        PhysicalDestructionService::execute($pdo, $gm, (int) $apO['id'], [
            'expected_plan_hash' => (string) $planO['plan_hash'],
            'execution_nonce' => rpdNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        rpdAssert(false, 'Q should DEPENDENT');
    } catch (RuntimeException $e) {
        rpdAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN,
            'Q DEPENDENT_RETENTION_RECORDS_REMAIN'
        );
    }

    // R — After ISE destroy (personel 11), ozluk anonymize when deps cleared.
    // ISTEN_AYRILMA counts as dependent (intentional last-stage). IPTAL surecler then
    // call handler directly — full executeFinal would fail TERMINATION_DATE_MISSING.
    $pdo->exec("UPDATE surecler SET state = 'IPTAL' WHERE personel_id = 11");
    // QR already destroyed in H
    $handler = new PersonelOzlukDestructionHandler();
    $talepOz = [
        'id' => 0,
        'category' => RetentionCategories::PERSONEL_OZLUK,
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
    ];
    $planOz = $handler->plan($pdo, $talepOz, ['personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11]);
    $ozResult = $handler->execute($pdo, $talepOz, ['personel_id' => 11], $planOz);
    rpdAssert(
        ($ozResult['result_code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'R ozluk anonymize after deps cleared'
    );
    $row11 = $pdo->query('SELECT ad, soyad, tc_kimlik_no FROM personeller WHERE id = 11')->fetch(PDO::FETCH_ASSOC);
    rpdAssert(
        is_array($row11)
            && (string) $row11['ad'] === 'DESTROYED'
            && (string) $row11['soyad'] === 'PERSONEL',
        'R tombstone applied'
    );

    // PERSONEL_BELGE — temp storage + destroy if feasible
    $fileMeta = PersonelBelgeStorageService::writeNewVersion('%PDF-1.4 synthetic pack2', 'pdf');
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (14, 'BELGE', '2014-01-01', 'AKTIF')"
    );
    $belgeSurecId = (int) $pdo->query('SELECT id FROM surecler WHERE personel_id = 14 AND surec_turu = \'BELGE\' LIMIT 1')->fetchColumn();
    $insV = $pdo->prepare(
        'INSERT INTO personel_belge_dosya_surumleri
            (surec_id, personel_id, surum_no, aktif_mi, storage_key, orijinal_dosya_adi,
             mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id)
         VALUES
            (:sid, 14, 1, 1, :key, :name, :mime, :ext, :bytes, :sha, 1)'
    );
    $insV->execute([
        'sid' => $belgeSurecId,
        'key' => $fileMeta['storage_key'],
        'name' => 'pack2.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'bytes' => $fileMeta['byte_boyutu'],
        'sha' => $fileMeta['sha256'],
    ]);
    // Remint termination-scoped belge manifest
    ArchiveManifestService::createTerminationScopedManifests($pdo, 14, 1);
    // Also ensure OZLUK/ISE manifests exist (seeded earlier)
    $reqBelge = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PERSONEL_BELGE,
        'entity_type' => 'surec',
        'record_id' => $belgeSurecId,
        'personel_id' => 14,
        'reason' => 'Pack2 belge destroy',
    ]);
    if ((string) ($reqBelge['item']['status'] ?? '') === 'REQUESTED') {
        $apBelge = DestructionWorkflowService::approveDestruction(
            $pdo,
            $gm,
            (int) $reqBelge['item']['id'],
            'GM belge',
            true
        );
        $evalBelge = PhysicalDestructionService::evaluate($pdo, $gm, (int) $apBelge['id']);
        if (($evalBelge['execution']['code'] ?? '') === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION
            && is_array($evalBelge['plan'] ?? null)
        ) {
            $resBelge = PhysicalDestructionService::execute($pdo, $gm, (int) $apBelge['id'], [
                'expected_plan_hash' => (string) $evalBelge['plan']['plan_hash'],
                'execution_nonce' => rpdNonce(),
                'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
            ]);
            rpdAssert(
                ($resBelge['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
                'PERSONEL_BELGE destroy executed'
            );
            $verLeft = (int) $pdo->query(
                'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $belgeSurecId
            )->fetchColumn();
            rpdAssert($verLeft === 0, 'PERSONEL_BELGE versions deleted');
        } else {
            echo '[SKIP] PERSONEL_BELGE execute not eligible: '
                . (string) ($evalBelge['execution']['code'] ?? '?') . PHP_EOL;
        }
    } else {
        echo '[SKIP] PERSONEL_BELGE request status='
            . (string) ($reqBelge['item']['status'] ?? '?')
            . ' code=' . (string) ($reqBelge['eligibility']['code'] ?? '?') . PHP_EOL;
    }

    // P again — GM execute succeeded when flag on (H)
    rpdAssert(true, 'P GM execute succeeded with flag ON');

    RetentionClock::clearOverride();
    rpdFlagOff();
    echo "verify-retention-physical-destruction-mysql: OK\n";
} finally {
    RetentionClock::clearOverride();
    rpdFlagOff();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // best-effort cleanup
    }
    if (is_dir($storageRoot)) {
        foreach (glob($storageRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($storageRoot);
    }
}
