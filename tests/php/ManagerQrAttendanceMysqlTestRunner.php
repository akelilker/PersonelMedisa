<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceIntervalReadService;

function managerQrAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function managerQrPdo(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

function managerQrApplyMigration(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/057_qr_attendance_events.sql');
    if ($sql === false) {
        throw new RuntimeException('057 migration okunamadi.');
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        if (trim($statement) !== '' && strpos(trim($statement), '--') !== 0) {
            $pdo->exec($statement);
        }
    }
}

$rootDsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
$user = (string) getenv('MEDISA_TEST_MYSQL_USER');
$password = (string) getenv('MEDISA_TEST_MYSQL_PASSWORD');
if ($rootDsn === '' || $user === '') {
    throw new RuntimeException('Disposable MariaDB credentials are required.');
}

$database = 'manager_qr_' . bin2hex(random_bytes(4));
$root = managerQrPdo($rootDsn, $user, $password);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $rootDsn);
$pdo = managerQrPdo((string) $dsn, $user, $password);

try {
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            ad VARCHAR(64) NOT NULL,
            soyad VARCHAR(64) NOT NULL,
            sicil_no VARCHAR(64) NULL,
            sube_id INT UNSIGNED NOT NULL,
            aktif_durum VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            username VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            ad VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez'), (2, 'Depo')");
    $pdo->exec("INSERT INTO users (id, username) VALUES (10, 'u10'), (11, 'u11')");
    $pdo->exec(
        "INSERT INTO personeller (id, ad, soyad, sicil_no, sube_id, aktif_durum)
         VALUES (1, 'Ahmet', 'Yılmaz', 'MED-001', 1, 'AKTIF'),
                (2, 'Mehmet', 'Kaya', 'MED-002', 2, 'AKTIF')"
    );
    managerQrApplyMigration($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO qr_attendance_events
         (personel_id, user_id, sube_id, event_type, occurred_at_utc, qr_version,
          qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES (:personel_id, :user_id, :sube_id, :event_type, :occurred_at_utc, 1,
                 :qr_jti, :issued_at_utc, :expires_at_utc, :request_nonce)'
    );
    $seed = static function (
        PDOStatement $insert,
        int $personelId,
        int $userId,
        int $subeId,
        string $type,
        string $occurredAt,
        string $suffix
    ): void {
        $insert->execute([
            'personel_id' => $personelId,
            'user_id' => $userId,
            'sube_id' => $subeId,
            'event_type' => $type,
            'occurred_at_utc' => $occurredAt,
            'issued_at_utc' => $occurredAt,
            'expires_at_utc' => $occurredAt,
            'qr_jti' => str_pad($suffix, 32, '0'),
            'request_nonce' => sprintf('%08d-%04d-%04d-%04d-%012d', $personelId, $userId, random_int(1, 9999), random_int(1, 9999), random_int(1, 999999999999)),
        ]);
    };

    // Previous-day entry and requested-day exit must not become a false missing entry.
    $seed($insert, 1, 10, 1, 'GIRIS', '2026-08-12 20:30:00.000000', 'a1');
    $seed($insert, 1, 10, 1, 'CIKIS', '2026-08-13 04:00:00.000000', 'a2');
    // Two complete daily intervals and one open daily entry.
    $seed($insert, 1, 10, 1, 'GIRIS', '2026-08-13 05:01:00.000000', 'a3');
    $seed($insert, 1, 10, 1, 'CIKIS', '2026-08-13 14:04:00.000000', 'a4');
    $seed($insert, 1, 10, 1, 'GIRIS', '2026-08-14 04:58:00.000000', 'a5');
    $seed($insert, 1, 10, 1, 'CIKIS', '2026-08-14 14:10:00.000000', 'a6');
    $seed($insert, 1, 10, 1, 'GIRIS', '2026-08-15 05:03:00.000000', 'a7');
    // Person 2 supplies missing-entry and branch-mismatch coverage.
    $seed($insert, 2, 11, 2, 'CIKIS', '2026-08-14 14:00:00.000000', 'b1');
    $seed($insert, 2, 11, 1, 'GIRIS', '2026-08-15 05:00:00.000000', 'b2');
    $seed($insert, 2, 11, 2, 'CIKIS', '2026-08-15 14:00:00.000000', 'b3');

    $rows = QrAttendanceIntervalReadService::listForManager(
        $pdo,
        null,
        [1, 2],
        1,
        '2026-08-13',
        '2026-08-15',
        100,
        0
    );
    managerQrAssert(count($rows['items']) === 3, 'one person yields one row per active business date');
    managerQrAssert($rows['total'] === 3, 'total counts daily rows, not people');
    managerQrAssert($rows['items'][0]['date_from'] === '2026-08-15', 'deterministic date-desc order');
    managerQrAssert($rows['items'][0]['missing_exit'] === true, 'missing CIKIS is retained on its date');
    managerQrAssert($rows['items'][1]['interval_count'] === 1, 'complete interval remains on its own date');
    managerQrAssert(
        QrAttendanceIntervalReadService::listForManager($pdo, null, [1, 2], 1, '2026-08-01', '2026-08-30', 100, 0)['total'] > 1,
        '30-day request never collapses to one aggregate row'
    );

    $filtered = QrAttendanceIntervalReadService::listForManager($pdo, null, [1, 2], 2, '2026-08-14', '2026-08-15', 100, 0);
    managerQrAssert(count($filtered['items']) === 2, 'personel_id filter is deterministic');
    $filteredAnomalies = array_merge(...array_map(
        static fn (array $item): array => $item['anomalies'],
        $filtered['items']
    ));
    managerQrAssert(in_array('MISSING_GIRIS', $filteredAnomalies, true), 'missing GIRIS is reported');
    managerQrAssert(in_array('BRANCH_MISMATCH', $filteredAnomalies, true), 'branch mismatch is reported');

    $deniedScope = QrAttendanceIntervalReadService::listForManager($pdo, null, [1], 2, '2026-08-14', '2026-08-15', 100, 0);
    managerQrAssert($deniedScope['items'] === [], 'out-of-scope branch fails closed');

    $page = QrAttendanceIntervalReadService::listForManager($pdo, null, [1, 2], 1, '2026-08-13', '2026-08-15', 2, 0);
    managerQrAssert($page['has_next'] === true && $page['total'] === 3, 'pagination total and has_next are row-based');

    echo '[OK] ManagerQrAttendanceMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
