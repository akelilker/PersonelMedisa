<?php

declare(strict_types=1);

/**
 * S3D: MariaDB range/boundary context for interval derivation (reuse 057 schema).
 * php tests/php/S3DQrIntervalRangeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceIntervalReadService;

function s3dRAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3dRRootPdo(): PDO
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
function s3dRSplitSql(string $sql): array
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

function s3dRApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3dRSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3dRPdoForDb(string $database): PDO
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
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

$root = s3dRRootPdo();
$db = 's3d_qr_interval_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = s3dRPdoForDb($db);

try {
    // Minimal parent tables for 057 FKs
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    $pdo->exec("INSERT INTO personeller (id, ad, soyad) VALUES (1, 'A', 'B')");
    $pdo->exec("INSERT INTO users (id, username) VALUES (10, 'u10'), (11, 'u11')");
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez'), (2, 'SubeB')");

    s3dRApply($pdo, '057_qr_attendance_events.sql');
    s3dRAssert(true, 'migration 057 applied for S3D range tests');

    $ins = $pdo->prepare(
        'INSERT INTO qr_attendance_events
            (personel_id, user_id, sube_id, event_type, occurred_at_utc,
             qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES
            (:personel_id, :user_id, :sube_id, :event_type, :occurred_at_utc,
             1, :jti, :issued, :expires, :nonce)'
    );

    $seed = static function (
        PDO $pdo,
        PDOStatement $ins,
        int $userId,
        int $subeId,
        string $type,
        string $utc,
        string $jti
    ): void {
        $ins->execute([
            'personel_id' => 1,
            'user_id' => $userId,
            'sube_id' => $subeId,
            'event_type' => $type,
            'occurred_at_utc' => $utc,
            'jti' => $jti,
            'issued' => $utc,
            'expires' => $utc,
            'nonce' => sprintf(
                '%08x-%04x-%04x-%04x-%012x',
                random_int(0, 0xffffffff),
                random_int(0, 0xffff),
                random_int(0x1000, 0x1fff),
                random_int(0x8000, 0xbfff),
                random_int(0, 0xffffffffffff)
            ),
        ]);
    };

    // Boundary: Jul 31 23:00 TR GIRIS + Aug 1 07:00 TR CIKIS
    $seed($pdo, $ins, 10, 1, 'GIRIS', '2026-07-31 20:00:00.000000', str_repeat('a', 32));
    $seed($pdo, $ins, 10, 1, 'CIKIS', '2026-08-01 04:00:00.000000', str_repeat('b', 32));
    // In-range Aug complete pair
    $seed($pdo, $ins, 10, 1, 'GIRIS', '2026-08-12 05:00:00.000000', str_repeat('c', 32));
    $seed($pdo, $ins, 11, 1, 'CIKIS', '2026-08-12 14:00:00.000000', str_repeat('d', 32));
    // Open Aug 31 GIRIS closed by Sep 1 CIKIS (next context)
    $seed($pdo, $ins, 10, 1, 'GIRIS', '2026-08-31 20:00:00.000000', str_repeat('e', 32));
    $seed($pdo, $ins, 10, 1, 'CIKIS', '2026-08-31 22:00:00.000000', str_repeat('f', 32));

    $aug = QrAttendanceIntervalReadService::listForSelf($pdo, 1, '2026-08-01', '2026-08-31');
    s3dRAssert($aug['algorithm_version'] === 'QR_INTERVAL_V1', 'response algorithm_version');
    s3dRAssert(count($aug['intervals']) === 2, 'Aug has 2 COMPLETE (in-range + edge next)');
    s3dRAssert(count($aug['anomalies']) === 0, 'Aug has no false orphan from Jul GIRIS');
    $durations = array_map(static function ($i) {
        return (int) $i['duration_seconds'];
    }, $aug['intervals']);
    sort($durations);
    s3dRAssert($durations === [2 * 3600, 9 * 3600], 'interval durations are 2h and 9h');
    s3dRAssert(
        (int) $aug['summary']['complete_duration_seconds'] === 11 * 3600,
        'summary duration 11h'
    );

    $entryIds = array_map(static function ($i) {
        return (int) $i['entry_event_id'];
    }, $aug['intervals']);
    sort($entryIds);
    s3dRAssert(count($entryIds) === 2, 'two entry ids');

    // Empty personel window
    $empty = QrAttendanceIntervalReadService::listForSelf($pdo, 1, '2026-01-01', '2026-01-31');
    s3dRAssert($empty['intervals'] === [] && $empty['anomalies'] === [], 'empty month projection');

    // 366-day OK
    $ok = QrAttendanceIntervalReadService::listForSelf($pdo, 1, '2024-01-01', '2024-12-31');
    s3dRAssert(is_array($ok['summary']), '366-day window accepted');

    $denied = false;
    try {
        QrAttendanceIntervalReadService::listForSelf($pdo, 1, '2024-01-01', '2025-01-01');
    } catch (Throwable $e) {
        $denied = true;
    }
    s3dRAssert($denied, '367-day window denied');

    // No interval table / no writes beyond raw seed
    $tables = $pdo->query("SHOW TABLES LIKE 'qr_attendance_intervals'")->fetchAll();
    s3dRAssert(count($tables) === 0, 'no qr_attendance_intervals table');

    echo '[OK] S3DQrIntervalRangeMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
