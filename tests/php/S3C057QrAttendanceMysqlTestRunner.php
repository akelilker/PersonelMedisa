<?php

declare(strict_types=1);

/**
 * S3C: disposable MariaDB — migration 057 qr_attendance_events + UNIQUE/FK semantics.
 * php tests/php/S3C057QrAttendanceMysqlTestRunner.php
 */

function s3c057Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3c057RootPdo(): PDO
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
function s3c057SplitSql(string $sql): array
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

function s3c057Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3c057SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3c057PdoForDb(string $database): PDO
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

function s3c057Nonce(int $n): string
{
    return sprintf('a0000000-0000-4000-8000-%012x', $n);
}

function s3c057InsertEvent(
    PDO $pdo,
    int $personelId,
    int $userId,
    int $subeId,
    string $eventType,
    string $jti,
    string $nonce
): void {
    $pdo->exec(
        "INSERT INTO qr_attendance_events (
            personel_id, user_id, sube_id, event_type,
            occurred_at_utc, qr_version, qr_jti,
            qr_issued_at_utc, qr_expires_at_utc, request_nonce
         ) VALUES (
            {$personelId}, {$userId}, {$subeId}, " . $pdo->quote($eventType) . ",
            '2026-01-15 08:00:00.000000', 1, " . $pdo->quote($jti) . ",
            '2026-01-15 07:59:00.000000', '2026-01-15 08:01:00.000000', " . $pdo->quote($nonce) . "
         )"
    );
}

$root = s3c057RootPdo();
$database = 'medisa_s3c_057_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = s3c057PdoForDb($database);

    s3c057Apply($pdo, '001_initial_schema.sql');
    s3c057Apply($pdo, '051_users_varsayilan_sube_id.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, gorev_id, aktif_durum
         ) VALUES
         (1, '11111111111', 'Ali', 'Bir', '1990-01-01', '5550000001', 'A', '5550000011', 'S1', '2020-01-01', 1, 1, 1, 'AKTIF'),
         (2, '22222222222', 'Veli', 'Iki', '1991-01-01', '5550000002', 'B', '5550000012', 'S2', '2020-01-01', 1, 1, 1, 'AKTIF')"
    );

    $hash = password_hash('S3cMigPass-24chars!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'u1', " . $pdo->quote($hash) . ", 'User One', 'GENEL_YONETICI', 'AKTIF'),
         (2, 'u2', " . $pdo->quote($hash) . ", 'User Two', 'GENEL_YONETICI', 'AKTIF')"
    );

    s3c057Apply($pdo, '056_users_personel_binding.sql');
    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 1');
    $pdo->exec('UPDATE users SET personel_id = 2 WHERE id = 2');

    s3c057Apply($pdo, '057_qr_attendance_events.sql');
    s3c057Assert(true, '057 ilk apply');
    s3c057Apply($pdo, '057_qr_attendance_events.sql');
    s3c057Assert(true, '057 ikinci apply idempotent');

    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_attendance_events'"
    )->fetchColumn();
    s3c057Assert($tableExists === 1, 'qr_attendance_events table exists');

    $eventTypeCol = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'qr_attendance_events'
           AND COLUMN_NAME = 'event_type'"
    )->fetch(PDO::FETCH_ASSOC);
    s3c057Assert(is_array($eventTypeCol), 'event_type column exists');
    $columnType = (string) ($eventTypeCol['COLUMN_TYPE'] ?? '');
    s3c057Assert(stripos($columnType, 'GIRIS') !== false, 'event_type ENUM GIRIS');
    s3c057Assert(stripos($columnType, 'CIKIS') !== false, 'event_type ENUM CIKIS');

    foreach (['uq_qr_att_user_nonce', 'uq_qr_att_user_jti_type'] as $indexName) {
        $idx = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qr_attendance_events'
               AND INDEX_NAME = " . $pdo->quote($indexName)
        )->fetchColumn();
        s3c057Assert($idx > 0, 'unique ' . $indexName . ' exists');
    }

    foreach (['fk_qr_att_personel', 'fk_qr_att_user', 'fk_qr_att_sube'] as $fkName) {
        $fk = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = " . $pdo->quote($fkName)
        )->fetchColumn();
        s3c057Assert($fk === 1, 'FK ' . $fkName . ' exists');
    }

    $sharedJti = str_repeat('a', 32);

    s3c057InsertEvent($pdo, 1, 1, 1, 'GIRIS', $sharedJti, s3c057Nonce(1));
    s3c057InsertEvent($pdo, 2, 2, 1, 'GIRIS', $sharedJti, s3c057Nonce(2));
    s3c057Assert(true, 'two different users same jti allowed');

    $userJti = str_repeat('b', 32);
    s3c057InsertEvent($pdo, 1, 1, 1, 'GIRIS', $userJti, s3c057Nonce(3));
    s3c057InsertEvent($pdo, 1, 1, 1, 'CIKIS', $userJti, s3c057Nonce(4));
    s3c057Assert(true, 'same user same jti GIRIS+CIKIS allowed');

    $dupTypeFailed = false;
    try {
        s3c057InsertEvent($pdo, 1, 1, 1, 'GIRIS', $userJti, s3c057Nonce(5));
    } catch (Throwable $e) {
        $dupTypeFailed = true;
    }
    s3c057Assert($dupTypeFailed, 'same user+jti+event_type duplicate fails');

    $dupNonceFailed = false;
    try {
        s3c057InsertEvent($pdo, 1, 1, 1, 'CIKIS', str_repeat('c', 32), s3c057Nonce(4));
    } catch (Throwable $e) {
        $dupNonceFailed = true;
    }
    s3c057Assert($dupNonceFailed, 'same user+nonce duplicate fails');

    $migrationSql = (string) file_get_contents(__DIR__ . '/../../api/migrations/057_qr_attendance_events.sql');
    s3c057Assert(!preg_match('/^\s*INSERT\s+/im', $migrationSql), 'no seed INSERT');
    s3c057Assert(!preg_match('/\bDROP\s+TABLE\b/i', $migrationSql), 'no DROP TABLE');
    s3c057Assert(stripos($migrationSql, 'gunluk_puantaj') === false, 'no gunluk_puantaj in migration');
    s3c057Assert(stripos($migrationSql, 'interval') === false, 'no interval table in migration');

    echo "S3C 057 mysql runner OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
