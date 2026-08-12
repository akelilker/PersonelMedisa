<?php

declare(strict_types=1);

/**
 * S3C-R1: QR history listForSelf Istanbul business-date bounds on disposable MariaDB.
 * php tests/php/S3CQrHistoryTimezoneMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceEventService;

function s3cTzAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3cTzRootPdo(): PDO
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
function s3cTzSplitSql(string $sql): array
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

function s3cTzApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3cTzSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3cTzPdoForDb(string $database): PDO
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

$root = s3cTzRootPdo();
$database = 'medisa_s3c_tz_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = s3cTzPdoForDb($database);
    s3cTzApply($pdo, '001_initial_schema.sql');
    s3cTzApply($pdo, '051_users_varsayilan_sube_id.sql');
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, gorev_id, aktif_durum
         ) VALUES
         (1, '11111111111', 'Ali', 'Bir', '1990-01-01', '5550000001', 'A', '5550000011', 'S1', '2020-01-01', 1, 1, 1, 'AKTIF')"
    );
    $hash = password_hash('S3cTzPass-24chars!!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'u1', " . $pdo->quote($hash) . ", 'User One', 'GENEL_YONETICI', 'AKTIF')"
    );
    s3cTzApply($pdo, '056_users_personel_binding.sql');
    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 1');
    s3cTzApply($pdo, '057_qr_attendance_events.sql');

    // Session timezone must not break UTC datetime storage semantics for bounds.
    $pdo->exec("SET time_zone = '+00:00'");

    $insert = $pdo->prepare(
        "INSERT INTO qr_attendance_events (
            personel_id, user_id, sube_id, event_type, occurred_at_utc,
            qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce
         ) VALUES (1, 1, 1, 'GIRIS', :occ, 1, :jti, :iat, :exp, :nonce)"
    );

    // IN: 2026-08-12 21:30 UTC = 2026-08-13 00:30 Istanbul
    $insert->execute([
        'occ' => '2026-08-12 21:30:00.000000',
        'jti' => str_repeat('1', 32),
        'iat' => '2026-08-12 21:29:00.000000',
        'exp' => '2026-08-12 21:31:00.000000',
        'nonce' => 'a0000000-0000-4000-8000-000000000001',
    ]);
    // OUT: 2026-08-12 20:59:59 UTC = 2026-08-12 23:59:59 Istanbul
    $insert->execute([
        'occ' => '2026-08-12 20:59:59.000000',
        'jti' => str_repeat('2', 32),
        'iat' => '2026-08-12 20:58:00.000000',
        'exp' => '2026-08-12 21:00:00.000000',
        'nonce' => 'a0000000-0000-4000-8000-000000000002',
    ]);
    // Local 00:00 exact (Istanbul) → UTC via TZ
    $tz = new DateTimeZone('Europe/Istanbul');
    $utc = new DateTimeZone('UTC');
    $midnightUtc = (new DateTimeImmutable('2026-08-13 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.000000');
    $insert->execute([
        'occ' => $midnightUtc,
        'jti' => str_repeat('3', 32),
        'iat' => $midnightUtc,
        'exp' => $midnightUtc,
        'nonce' => 'a0000000-0000-4000-8000-000000000003',
    ]);

    $list = QrAttendanceEventService::listForSelf($pdo, 1, '2026-08-13', '2026-08-13');
    s3cTzAssert($list['from'] === '2026-08-13' && $list['to'] === '2026-08-13', 'response business YMD');
    $ids = array_map(static function (array $row): int {
        return (int) $row['id'];
    }, $list['items']);
    s3cTzAssert(count($ids) === 2, 'Aug13 includes midnight+00:30 only (not prev 23:59:59)');
    s3cTzAssert(in_array(1, $ids, true), '21:30 UTC included');
    s3cTzAssert(in_array(3, $ids, true), 'Istanbul midnight included');
    s3cTzAssert(!in_array(2, $ids, true), '20:59:59 UTC excluded');

    echo "S3C QR history timezone mysql runner OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
