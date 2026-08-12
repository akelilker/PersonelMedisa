<?php

declare(strict_types=1);

/**
 * S3F: MariaDB decision/apply integration (KEEP / APPLY / REOPEN + guards).
 * php tests/php/S3FQrPuantajDecisionMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionException;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionLedgerService;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionPolicy;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionService;
use Medisa\Api\Services\Qr\QrPuantajCandidateProjectionService;
use Medisa\Api\Services\Qr\QrPuantajCandidateReadService;

function s3fDecAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3fDecRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return list<string> */
function s3fDecSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s3fDecApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3fDecSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3fDecPdoForDb(string $database): PDO
{
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));

    $pdo = new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
    } catch (Throwable $e) {
        // ignore
    }

    return $pdo;
}

function s3fDecNonce(int $n): string
{
    return sprintf('a0000000-0000-4000-8000-%012x', $n);
}

function s3fDecSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            ad VARCHAR(64) NOT NULL,
            soyad VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE puantaj_donem_kilitleri (
            sube_id INT UNSIGNED NOT NULL,
            yil SMALLINT UNSIGNED NOT NULL,
            ay TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sube_id, yil, ay)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE puantaj_aylik_muhurleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            revision_no INT NOT NULL DEFAULT 1,
            donem VARCHAR(16) NULL,
            durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
            muhurlenen_kayit_sayisi INT NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            parent_muhur_id INT UNSIGNED NULL,
            superseded_by_id INT UNSIGNED NULL,
            source_hash VARCHAR(128) NULL,
            reopen_talep_id INT UNSIGNED NULL,
            UNIQUE KEY uq_muhur_rev (sube_id, yil, ay, revision_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE puantaj_donem_reopen_talepleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            kaynak_muhur_id INT UNSIGNED NOT NULL,
            talep_durumu VARCHAR(32) NOT NULL,
            gerekce TEXT NOT NULL,
            requested_by INT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            rejection_reason TEXT NULL,
            applied_at DATETIME NULL,
            reseal_muhur_id INT UNSIGNED NULL,
            request_hash VARCHAR(128) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE maas_hesaplama_donem_snapshotlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            muhur_id INT UNSIGNED NOT NULL,
            revision_no INT NOT NULL DEFAULT 1,
            state VARCHAR(32) NOT NULL DEFAULT 'OLUSTURULDU',
            source_hash VARCHAR(128) NULL,
            snapshot_hash VARCHAR(128) NULL,
            created_at DATETIME NULL,
            iptal_edildi_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE gunluk_puantaj (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            personel_id INT UNSIGNED NOT NULL,
            tarih DATE NOT NULL,
            state VARCHAR(32) NULL,
            giris_saati VARCHAR(16) NULL,
            cikis_saati VARCHAR(16) NULL,
            kontrol_durumu VARCHAR(32) NULL,
            muhur_id INT UNSIGNED NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            gec_kalma_dakika INT UNSIGNED NULL,
            erken_cikis_dakika INT UNSIGNED NULL,
            gercek_mola_dakika INT UNSIGNED NULL,
            hesaplanan_mola_dakika INT UNSIGNED NULL,
            net_calisma_suresi_dakika INT UNSIGNED NULL,
            gunluk_brut_sure_dakika INT UNSIGNED NULL,
            tatil_donemi_brut_calisma_dakika INT UNSIGNED NULL,
            tatil_donemi_ara_dinlenme_dakika INT UNSIGNED NULL,
            tatil_donemi_net_calisma_dakika INT UNSIGNED NULL,
            UNIQUE KEY uq_personel_tarih (personel_id, tarih)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez')");
    $pdo->exec("INSERT INTO users (id, username) VALUES (10, 'u10')");
    $pdo->exec("INSERT INTO personeller (id, sube_id, ad, soyad) VALUES (1, 1, 'A', 'B')");
}

function s3fDecSeedSeal(PDO $pdo, int $muhurId = 1): void
{
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (id, sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, source_hash)
         VALUES
            ({$muhurId}, 1, 2026, 8, 1, '2026-08', 'MUHURLENDI', 1, 10, 'hash-seal')"
    );
}

function s3fDecSeedReopenApproved(PDO $pdo, int $muhurId = 1, int $talepId = 1): void
{
    s3fDecSeedSeal($pdo, $muhurId);
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri
            (id, sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce, requested_by, requested_at, request_hash)
         VALUES
            ({$talepId}, 1, 2026, 8, {$muhurId}, 'ONAYLANDI', 'test reopen', 10, '2026-08-15 10:00:00', 'req-hash-1')"
    );
}

function s3fDecSeedActiveSnapshot(PDO $pdo, int $muhurId = 1, int $snapshotId = 1): void
{
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari
            (id, sube_id, yil, ay, muhur_id, revision_no, state, source_hash, snapshot_hash, created_at)
         VALUES
            ({$snapshotId}, 1, 2026, 8, {$muhurId}, 1, 'OLUSTURULDU', 'src', 'snap', '2026-08-15 11:00:00')"
    );
}

function s3fDecSeedQrPair(PDO $pdo, string $girisUtc, string $cikisUtc): void
{
    $ins = $pdo->prepare(
        'INSERT INTO qr_attendance_events
            (personel_id, user_id, sube_id, event_type, occurred_at_utc,
             qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES
            (:personel_id, 10, 1, :event_type, :occurred_at_utc,
             1, :jti, :issued_at_utc, :expires_at_utc, :nonce)'
    );
    $nonce = static function (): string {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x1000, 0x1fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        );
    };
    $ins->execute([
        'personel_id' => 1,
        'event_type' => 'GIRIS',
        'occurred_at_utc' => $girisUtc,
        'issued_at_utc' => $girisUtc,
        'expires_at_utc' => $girisUtc,
        'jti' => bin2hex(random_bytes(16)),
        'nonce' => $nonce(),
    ]);
    $ins->execute([
        'personel_id' => 1,
        'event_type' => 'CIKIS',
        'occurred_at_utc' => $cikisUtc,
        'issued_at_utc' => $cikisUtc,
        'expires_at_utc' => $cikisUtc,
        'jti' => bin2hex(random_bytes(16)),
        'nonce' => $nonce(),
    ]);
}

function s3fDecClearPeriod(PDO $pdo): void
{
    $pdo->exec('DELETE FROM maas_hesaplama_donem_snapshotlari');
    $pdo->exec('DELETE FROM puantaj_donem_reopen_talepleri');
    $pdo->exec('DELETE FROM puantaj_aylik_muhurleri');
    $pdo->exec('DELETE FROM puantaj_donem_kilitleri');
}

function s3fDecResetDay(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DELETE FROM qr_puantaj_candidate_decision_ledger');
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    s3fDecClearPeriod($pdo);
}

/**
 * @param array<string,mixed> $fields
 */
function s3fDecInsertPuantaj(PDO $pdo, array $fields = []): int
{
    $defaults = [
        'personel_id' => 1,
        'tarih' => '2026-08-12',
        'state' => 'ACIK',
        'giris_saati' => '09:00',
        'cikis_saati' => '18:00',
        'kontrol_durumu' => 'ONAYLANDI',
        'muhur_id' => null,
        'gec_kalma_dakika' => null,
        'erken_cikis_dakika' => null,
        'gercek_mola_dakika' => null,
        'hesaplanan_mola_dakika' => null,
        'net_calisma_suresi_dakika' => null,
        'gunluk_brut_sure_dakika' => null,
        'tatil_donemi_brut_calisma_dakika' => null,
        'tatil_donemi_ara_dinlenme_dakika' => null,
        'tatil_donemi_net_calisma_dakika' => null,
    ];
    $row = array_merge($defaults, $fields);
    $stmt = $pdo->prepare(
        'INSERT INTO gunluk_puantaj (
            personel_id, tarih, state, giris_saati, cikis_saati, kontrol_durumu, muhur_id,
            gec_kalma_dakika, erken_cikis_dakika, gercek_mola_dakika, hesaplanan_mola_dakika,
            net_calisma_suresi_dakika, gunluk_brut_sure_dakika,
            tatil_donemi_brut_calisma_dakika, tatil_donemi_ara_dinlenme_dakika, tatil_donemi_net_calisma_dakika
         ) VALUES (
            :personel_id, :tarih, :state, :giris_saati, :cikis_saati, :kontrol_durumu, :muhur_id,
            :gec_kalma_dakika, :erken_cikis_dakika, :gercek_mola_dakika, :hesaplanan_mola_dakika,
            :net_calisma_suresi_dakika, :gunluk_brut_sure_dakika,
            :tatil_donemi_brut_calisma_dakika, :tatil_donemi_ara_dinlenme_dakika, :tatil_donemi_net_calisma_dakika
         )'
    );
    $stmt->execute($row);

    return (int) $pdo->lastInsertId();
}

/** @return array<string,mixed>|null */
function s3fDecCandidateItem(PDO $pdo, string $date)
{
    $payload = QrPuantajCandidateReadService::listForPersonel($pdo, 1, 1, $date, $date);
    foreach ($payload['items'] as $item) {
        if (($item['candidate_date'] ?? '') === $date) {
            return $item;
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function s3fDecDecide(PDO $pdo, array $body)
{
    return QrPuantajCandidateDecisionService::decide($pdo, 1, 1, '2026-08-12', 10, $body);
}

function s3fDecCatchCode(callable $fn): string
{
    try {
        $fn();

        return 'OK';
    } catch (QrPuantajCandidateDecisionException $e) {
        return $e->getErrorCode();
    }
}

// --- race child mode ---
if (($argv[1] ?? '') === '--race-apply') {
    $database = (string) ($argv[2] ?? '');
    $hash = strtolower(trim((string) (getenv('S3F_RACE_HASH') ?: ($argv[3] ?? ''))));
    $nonce = trim((string) (getenv('S3F_RACE_NONCE') ?: ($argv[4] ?? '')));
    $reason = trim((string) (getenv('S3F_RACE_REASON') ?: 'Race apply denemesi.'));
    $pdo = s3fDecPdoForDb($database);
    try {
        $result = s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => $hash,
            'request_nonce' => $nonce,
            'gerekce' => $reason,
        ]);
        $idem = !empty($result['idempotent']) ? '1' : '0';
        echo 'OK:' . (string) ($result['decision_id'] ?? 0) . ':' . $idem . PHP_EOL;
        exit(0);
    } catch (QrPuantajCandidateDecisionException $e) {
        echo $e->getErrorCode() . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

/**
 * @return array{process:resource,pipes:array{0:resource,1:resource,2:resource}}
 */
function s3fDecSpawnRace(string $database, string $hash, string $nonce, string $reason): array
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
    $command = array_merge(
        [PHP_BINARY],
        $phpArgs,
        [__FILE__, '--race-apply', $database, $hash, $nonce]
    );

    putenv('S3F_RACE_HASH=' . $hash);
    putenv('S3F_RACE_NONCE=' . $nonce);
    putenv('S3F_RACE_REASON=' . $reason);

    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Race child could not be started.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array{process:resource,pipes:array} $child */
function s3fDecFinishRace(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    if ($code !== 0) {
        throw new RuntimeException('Race child failed: ' . trim((string) $stderr) . ' / ' . trim((string) $stdout));
    }
    $raw = trim((string) $stdout);
    // Ignore PHP startup warnings (e.g. duplicate pdo_mysql load on Windows).
    if (preg_match('/^OK:\d+:[01]$/m', $raw, $m)) {
        return $m[0];
    }
    if (preg_match('/^(QR_[A-Z0-9_]+|IDEMPOTENCY_CONFLICT|VALIDATION_ERROR|ACTIVE_SNAPSHOT_MUST_BE_CANCELLED)$/m', $raw, $m)) {
        return $m[1];
    }

    return $raw;
}

$root = s3fDecRootPdo();
$db = 's3f_decision_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = s3fDecPdoForDb($db);
$date = '2026-08-12';
$reason = 'QR aday karar testi gerekcesi.';

try {
    s3fDecSchema($pdo);
    s3fDecApply($pdo, '057_qr_attendance_events.sql');
    s3fDecApply($pdo, '058_qr_puantaj_candidate_decision_ledger.sql');

    // (a) OPEN + existing + safe diff + no dependent â†’ APPLY success
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    $puantajId = s3fDecInsertPuantaj($pdo, [
        'giris_saati' => '09:00',
        'cikis_saati' => '18:00',
        'kontrol_durumu' => 'ONAYLANDI',
        'state' => 'ACIK',
        'muhur_id' => 99,
    ]);
    $before = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = ' . $puantajId)->fetch(PDO::FETCH_ASSOC);
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn();
    $itemA = s3fDecCandidateItem($pdo, $date);
    s3fDecAssert($itemA !== null, 'a) candidate exists');
    s3fDecAssert(
        ($itemA['comparison_status'] ?? '') === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
        'a) DIFFERS_CANONICAL_TIME'
    );
    $hashA = (string) ($itemA['candidate_hash'] ?? '');
    $resA = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
        'candidate_hash' => $hashA,
        'request_nonce' => s3fDecNonce(1),
        'gerekce' => $reason,
    ]);
    s3fDecAssert(($resA['decision_type'] ?? '') === 'APPLY_EXISTING', 'a) APPLY success type');
    $after = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = ' . $puantajId)->fetch(PDO::FETCH_ASSOC);
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn();
    s3fDecAssert($afterCount === $beforeCount, 'a) row count unchanged');
    s3fDecAssert(strpos((string) ($after['giris_saati'] ?? ''), '08:00') === 0, 'a) giris applied');
    s3fDecAssert(strpos((string) ($after['cikis_saati'] ?? ''), '17:00') === 0, 'a) cikis applied');
    s3fDecAssert(($after['kontrol_durumu'] ?? '') === 'BEKLIYOR', 'a) kontrol BEKLIYOR');
    s3fDecAssert(($after['state'] ?? '') === 'ACIK', 'a) state ACIK');
    s3fDecAssert($after['muhur_id'] === null || $after['muhur_id'] === '', 'a) muhur cleared');
    foreach (QrPuantajCandidateDecisionPolicy::$dependentGuardFields as $field) {
        s3fDecAssert(
            ($before[$field] ?? null) == ($after[$field] ?? null),
            'a) dependent field untouched: ' . $field
        );
    }
    $afterItem = s3fDecCandidateItem($pdo, $date);
    s3fDecAssert(
        ($afterItem['comparison_status'] ?? '') === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME,
        'a) GET candidate MATCHES'
    );

    // (b) no canonical row â†’ APPLY throws; no insert
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    $itemB = s3fDecCandidateItem($pdo, $date);
    s3fDecAssert($itemB !== null, 'b) candidate exists');
    $codeB = s3fDecCatchCode(static function () use ($pdo, $itemB, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => (string) $itemB['candidate_hash'],
            'request_nonce' => s3fDecNonce(2),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert($codeB === QrPuantajCandidateDecisionPolicy::BLOCK_NO_ROW, 'b) QR_APPLY_REQUIRES_EXISTING_PUANTAJ_ROW');
    s3fDecAssert((int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 0, 'b) no insert');
    s3fDecAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'")->fetchColumn() === 0,
        'b) no APPLY ledger'
    );

    // (c) SEALED â†’ APPLY blocked; KEEP allowed; no write
    s3fDecResetDay($pdo);
    s3fDecSeedSeal($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:07:00.000000', '2026-08-12 14:02:00.000000');
    s3fDecInsertPuantaj($pdo, [
        'giris_saati' => '08:00',
        'cikis_saati' => '17:00',
        'state' => 'MUHURLENDI',
        'muhur_id' => 1,
    ]);
    $itemC = s3fDecCandidateItem($pdo, $date);
    $beforeC = $pdo->query('SELECT giris_saati, cikis_saati, muhur_id FROM gunluk_puantaj LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $codeCApply = s3fDecCatchCode(static function () use ($pdo, $itemC, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => (string) $itemC['candidate_hash'],
            'request_nonce' => s3fDecNonce(3),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert(
        in_array($codeCApply, [
            QrPuantajCandidateDecisionPolicy::BLOCK_PERIOD_LOCKED,
            'PERIOD_LOCKED',
            'QR_APPLY_NOT_ALLOWED',
        ], true),
        'c) APPLY blocked on SEALED [' . $codeCApply . ']'
    );
    $keepC = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
        'candidate_hash' => (string) $itemC['candidate_hash'],
        'request_nonce' => s3fDecNonce(4),
        'gerekce' => $reason,
    ]);
    s3fDecAssert(($keepC['decision_type'] ?? '') === 'KEEP_CANONICAL', 'c) KEEP allowed on SEALED');
    $afterC = $pdo->query('SELECT giris_saati, cikis_saati, muhur_id FROM gunluk_puantaj LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    s3fDecAssert($beforeC == $afterC, 'c) no puantaj write on SEALED KEEP');

    // (d) KEEP then same-hash APPLY blocked until REOPEN; then apply ok
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemD = s3fDecCandidateItem($pdo, $date);
    $hashD = (string) ($itemD['candidate_hash'] ?? '');
    s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
        'candidate_hash' => $hashD,
        'request_nonce' => s3fDecNonce(5),
        'gerekce' => $reason,
    ]);
    $codeDApply = s3fDecCatchCode(static function () use ($pdo, $hashD, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => $hashD,
            'request_nonce' => s3fDecNonce(6),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert(
        $codeDApply === QrPuantajCandidateDecisionPolicy::BLOCK_DECISION_CONFLICT,
        'd) APPLY blocked while KEEP active'
    );
    s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_REOPEN_REVIEW,
        'candidate_hash' => $hashD,
        'request_nonce' => s3fDecNonce(7),
        'gerekce' => $reason,
    ]);
    $resDApply = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
        'candidate_hash' => $hashD,
        'request_nonce' => s3fDecNonce(8),
        'gerekce' => $reason,
    ]);
    s3fDecAssert(($resDApply['decision_type'] ?? '') === 'APPLY_EXISTING', 'd) APPLY ok after REOPEN');

    // (e) KEEP idempotent nonce retry
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemE = s3fDecCandidateItem($pdo, $date);
    $hashE = (string) ($itemE['candidate_hash'] ?? '');
    $nonceE = s3fDecNonce(9);
    $firstE = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
        'candidate_hash' => $hashE,
        'request_nonce' => $nonceE,
        'gerekce' => $reason,
    ]);
    $retryE = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
        'candidate_hash' => $hashE,
        'request_nonce' => $nonceE,
        'gerekce' => $reason,
    ]);
    s3fDecAssert(!empty($retryE['idempotent']), 'e) KEEP idempotent retry');
    s3fDecAssert((int) ($retryE['decision_id'] ?? 0) === (int) ($firstE['decision_id'] ?? -1), 'e) same decision_id');
    s3fDecAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger')->fetchColumn() === 1,
        'e) single ledger row'
    );

    // (f) dependent field populated â†’ blocked; no APPLY ledger; row unchanged
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    $pidF = s3fDecInsertPuantaj($pdo, [
        'giris_saati' => '09:00',
        'cikis_saati' => '18:00',
        'gec_kalma_dakika' => 12,
    ]);
    $beforeF = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = ' . $pidF)->fetch(PDO::FETCH_ASSOC);
    $itemF = s3fDecCandidateItem($pdo, $date);
    s3fDecAssert(empty($itemF['review']['can_apply']), 'f) read-time can_apply false');
    s3fDecAssert(
        ($itemF['review']['blocking_code'] ?? '') === QrPuantajCandidateDecisionPolicy::BLOCK_DEPENDENT_FIELDS,
        'f) read-time blocking DEPENDENT_FIELDS'
    );
    s3fDecAssert(!empty($itemF['review']['can_keep_canonical']), 'f) KEEP still allowed at read-time');
    $codeF = s3fDecCatchCode(static function () use ($pdo, $itemF, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => (string) $itemF['candidate_hash'],
            'request_nonce' => s3fDecNonce(10),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert($codeF === QrPuantajCandidateDecisionPolicy::BLOCK_DEPENDENT_FIELDS, 'f) dependent fields block');
    s3fDecAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'")->fetchColumn() === 0,
        'f) no APPLY ledger'
    );
    $afterF = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = ' . $pidF)->fetch(PDO::FETCH_ASSOC);
    s3fDecAssert(
        (string) ($beforeF['giris_saati'] ?? '') === (string) ($afterF['giris_saati'] ?? '')
            && (string) ($beforeF['cikis_saati'] ?? '') === (string) ($afterF['cikis_saati'] ?? ''),
        'f) row unchanged'
    );

    // (g) stale hash â†’ QR_CANDIDATE_STALE; no ledger insert
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemG = s3fDecCandidateItem($pdo, $date);
    $staleHash = str_repeat('ab', 32);
    $codeG = s3fDecCatchCode(static function () use ($pdo, $staleHash, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => $staleHash,
            'request_nonce' => s3fDecNonce(11),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert($codeG === QrPuantajCandidateDecisionPolicy::BLOCK_STALE, 'g) QR_CANDIDATE_STALE');
    s3fDecAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger')->fetchColumn() === 0,
        'g) no ledger insert'
    );
    unset($itemG);

    // (h) REOPENED + active snapshot â†’ apply blocked
    s3fDecResetDay($pdo);
    s3fDecSeedReopenApproved($pdo);
    s3fDecSeedActiveSnapshot($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemH = s3fDecCandidateItem($pdo, $date);
    $codeH = s3fDecCatchCode(static function () use ($pdo, $itemH, $reason) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
            'candidate_hash' => (string) $itemH['candidate_hash'],
            'request_nonce' => s3fDecNonce(12),
            'gerekce' => $reason,
        ]);
    });
    s3fDecAssert(
        $codeH === QrPuantajCandidateDecisionPolicy::BLOCK_ACTIVE_SNAPSHOT
            || $codeH === 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
        'h) ACTIVE_SNAPSHOT_MUST_BE_CANCELLED [' . $codeH . ']'
    );

    // (i) REOPENED no snapshot â†’ apply ok
    s3fDecResetDay($pdo);
    s3fDecSeedReopenApproved($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemI = s3fDecCandidateItem($pdo, $date);
    $resI = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
        'candidate_hash' => (string) $itemI['candidate_hash'],
        'request_nonce' => s3fDecNonce(13),
        'gerekce' => $reason,
    ]);
    s3fDecAssert(($resI['decision_type'] ?? '') === 'APPLY_EXISTING', 'i) REOPENED no snapshot APPLY ok');

    // (j) concurrent SAME nonce exact retry → one mutation, one ledger, both success, same decision_id
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemJ = s3fDecCandidateItem($pdo, $date);
    $hashJ = (string) ($itemJ['candidate_hash'] ?? '');
    $nonceJ = s3fDecNonce(20);
    $reasonJ = 'Concurrent exact nonce apply retry.';
    $ledgerBeforeJ = (int) $pdo->query(
        "SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'"
    )->fetchColumn();
    $childJ1 = s3fDecSpawnRace($db, $hashJ, $nonceJ, $reasonJ);
    $childJ2 = s3fDecSpawnRace($db, $hashJ, $nonceJ, $reasonJ);
    $outJ1 = s3fDecFinishRace($childJ1);
    $outJ2 = s3fDecFinishRace($childJ2);
    s3fDecAssert(strpos($outJ1, 'OK:') === 0, 'j) child1 success [' . $outJ1 . ']');
    s3fDecAssert(strpos($outJ2, 'OK:') === 0, 'j) child2 success [' . $outJ2 . ']');
    s3fDecAssert(
        strpos($outJ1, QrPuantajCandidateDecisionPolicy::BLOCK_STALE) === false
            && strpos($outJ2, QrPuantajCandidateDecisionPolicy::BLOCK_STALE) === false,
        'j) exact retry never QR_CANDIDATE_STALE'
    );
    s3fDecAssert(
        strpos($outJ1, QrPuantajCandidateDecisionPolicy::BLOCK_IDEMPOTENCY) === false
            && strpos($outJ2, QrPuantajCandidateDecisionPolicy::BLOCK_IDEMPOTENCY) === false,
        'j) exact retry never IDEMPOTENCY_CONFLICT'
    );
    $partsJ1 = explode(':', $outJ1);
    $partsJ2 = explode(':', $outJ2);
    $idJ1 = (int) ($partsJ1[1] ?? 0);
    $idJ2 = (int) ($partsJ2[1] ?? 0);
    $idemJ1 = ($partsJ1[2] ?? '') === '1';
    $idemJ2 = ($partsJ2[2] ?? '') === '1';
    s3fDecAssert($idJ1 > 0 && $idJ1 === $idJ2, 'j) same decision_id');
    s3fDecAssert(!($idemJ1 === false && $idemJ2 === false), 'j) not two fresh non-idempotent applies');
    // Ideal: one fresh + one idempotent (xor). Both idempotent is acceptable if both resolve after commit.
    s3fDecAssert($idemJ1 || $idemJ2, 'j) at least one idempotent=true response');
    $applyLedgerJ = (int) $pdo->query(
        "SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'"
    )->fetchColumn();
    s3fDecAssert($applyLedgerJ === $ledgerBeforeJ + 1, 'j) exactly one APPLY ledger row');
    $finalJ = $pdo->query('SELECT giris_saati, cikis_saati, updated_at FROM gunluk_puantaj LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    s3fDecAssert(strpos((string) ($finalJ['giris_saati'] ?? ''), '08:00') === 0, 'j) single mutation giris');
    s3fDecAssert(strpos((string) ($finalJ['cikis_saati'] ?? ''), '17:00') === 0, 'j) single mutation cikis');
    s3fDecAssert((string) ($finalJ['updated_at'] ?? '') !== '', 'j) updated_at present');

    // (k) concurrent DIFFERENT nonce competing apply → one APPLY wins; loser stale/conflict; one mutation
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemK = s3fDecCandidateItem($pdo, $date);
    $hashK = (string) ($itemK['candidate_hash'] ?? '');
    $reasonK = 'Concurrent different nonce competing apply.';
    $childK1 = s3fDecSpawnRace($db, $hashK, s3fDecNonce(21), $reasonK);
    $childK2 = s3fDecSpawnRace($db, $hashK, s3fDecNonce(22), $reasonK);
    $outK1 = s3fDecFinishRace($childK1);
    $outK2 = s3fDecFinishRace($childK2);
    $okK = 0;
    $loseK = 0;
    foreach ([$outK1, $outK2] as $outK) {
        if (strpos($outK, 'OK:') === 0) {
            $okK++;
        } elseif (
            $outK === QrPuantajCandidateDecisionPolicy::BLOCK_STALE
            || $outK === QrPuantajCandidateDecisionPolicy::BLOCK_DECISION_CONFLICT
            || $outK === 'QR_APPLY_NOT_ALLOWED'
        ) {
            $loseK++;
        }
    }
    s3fDecAssert($okK === 1, 'k) exactly one APPLY wins [' . $outK1 . ' / ' . $outK2 . ']');
    s3fDecAssert($loseK === 1, 'k) loser stale/decision conflict [' . $outK1 . ' / ' . $outK2 . ']');
    $applyLedgerK = (int) $pdo->query(
        "SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'"
    )->fetchColumn();
    s3fDecAssert($applyLedgerK === 1, 'k) exactly one APPLY ledger');
    $finalK = $pdo->query('SELECT giris_saati, cikis_saati FROM gunluk_puantaj LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    s3fDecAssert(strpos((string) ($finalK['giris_saati'] ?? ''), '08:00') === 0, 'k) final giris from winner');
    s3fDecAssert(strpos((string) ($finalK['cikis_saati'] ?? ''), '17:00') === 0, 'k) final cikis from winner');

    // (l) same nonce different payload → IDEMPOTENCY_CONFLICT; no second mutation
    s3fDecResetDay($pdo);
    s3fDecSeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    s3fDecInsertPuantaj($pdo, ['giris_saati' => '09:00', 'cikis_saati' => '18:00']);
    $itemL = s3fDecCandidateItem($pdo, $date);
    $hashL = (string) ($itemL['candidate_hash'] ?? '');
    $nonceL = s3fDecNonce(23);
    $firstL = s3fDecDecide($pdo, [
        'action' => QrPuantajCandidateDecisionPolicy::ACTION_APPLY_EXISTING,
        'candidate_hash' => $hashL,
        'request_nonce' => $nonceL,
        'gerekce' => $reason,
    ]);
    s3fDecAssert(($firstL['decision_type'] ?? '') === 'APPLY_EXISTING', 'l) first apply ok');
    $girisAfterL = (string) $pdo->query('SELECT giris_saati FROM gunluk_puantaj LIMIT 1')->fetchColumn();
    $codeL = s3fDecCatchCode(static function () use ($pdo, $hashL, $nonceL) {
        s3fDecDecide($pdo, [
            'action' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
            'candidate_hash' => $hashL,
            'request_nonce' => $nonceL,
            'gerekce' => 'Farkli payload ayni nonce ile conflict.',
        ]);
    });
    s3fDecAssert($codeL === QrPuantajCandidateDecisionPolicy::BLOCK_IDEMPOTENCY, 'l) IDEMPOTENCY_CONFLICT');
    $applyLedgerL = (int) $pdo->query(
        "SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger WHERE decision_type='APPLY_EXISTING'"
    )->fetchColumn();
    s3fDecAssert($applyLedgerL === 1, 'l) still one APPLY ledger');
    $girisFinalL = (string) $pdo->query('SELECT giris_saati FROM gunluk_puantaj LIMIT 1')->fetchColumn();
    s3fDecAssert($girisAfterL === $girisFinalL, 'l) no second canonical mutation');

    echo 'S3F decision mysql tests OK' . PHP_EOL;
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
