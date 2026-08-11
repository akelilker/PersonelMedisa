<?php

declare(strict_types=1);

/**
 * S3B: disposable MariaDB — migration 056 binding + audit + UNIQUE NULL semantics.
 * php tests/php/S3B056UsersPersonelBindingMysqlTestRunner.php
 */

function s3b056Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3b056RootPdo(): PDO
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
function s3b056SplitSql(string $sql): array
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

function s3b056Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3b056SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3b056PdoForDb(string $database): PDO
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

$root = s3b056RootPdo();
$database = 'medisa_s3b_056_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = s3b056PdoForDb($database);

    s3b056Apply($pdo, '001_initial_schema.sql');
    s3b056Apply($pdo, '051_users_varsayilan_sube_id.sql');

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
         (2, '22222222222', 'Veli', 'Iki', '1991-01-01', '5550000002', 'B', '5550000012', 'S2', '2020-01-01', 1, 1, 1, 'AKTIF'),
         (3, '33333333333', 'Pasif', 'Uc', '1992-01-01', '5550000003', 'C', '5550000013', 'S3', '2020-01-01', 1, 1, 1, 'PASIF')"
    );

    $hash = password_hash('S3bMigPass-24chars!!!!!', PASSWORD_BCRYPT);
    // Use GENEL_YONETICI only — 001 ENUM may not include PERSONEL without 054.
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'admin', " . $pdo->quote($hash) . ", 'Admin', 'GENEL_YONETICI', 'AKTIF'),
         (2, 'u2', " . $pdo->quote($hash) . ", 'User Two', 'GENEL_YONETICI', 'AKTIF'),
         (3, 'u3', " . $pdo->quote($hash) . ", 'User Three', 'GENEL_YONETICI', 'AKTIF')"
    );

    s3b056Apply($pdo, '056_users_personel_binding.sql');
    s3b056Assert(true, '056 ilk apply');
    s3b056Apply($pdo, '056_users_personel_binding.sql');
    s3b056Assert(true, '056 ikinci apply idempotent');

    $col = $pdo->query(
        "SELECT IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'personel_id'"
    )->fetch(PDO::FETCH_ASSOC);
    s3b056Assert(is_array($col), 'column personel_id exists');
    s3b056Assert(($col['IS_NULLABLE'] ?? '') === 'YES', 'personel_id IS_NULLABLE=YES');
    s3b056Assert(strtolower((string) ($col['DATA_TYPE'] ?? '')) === 'int', 'personel_id DATA_TYPE=int');

    $uq = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'uq_users_personel_id'"
    )->fetchColumn();
    s3b056Assert($uq > 0, 'unique uq_users_personel_id exists');

    $fk = $pdo->query(
        "SELECT rc.DELETE_RULE, rc.UPDATE_RULE, kcu.REFERENCED_TABLE_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.CONSTRAINT_NAME = 'fk_users_personel'
           AND kcu.TABLE_NAME = 'users'
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    s3b056Assert(is_array($fk), 'FK fk_users_personel exists');
    s3b056Assert(($fk['REFERENCED_TABLE_NAME'] ?? '') === 'personeller', 'FK references personeller');
    s3b056Assert(strtoupper((string) ($fk['DELETE_RULE'] ?? '')) === 'RESTRICT', 'FK DELETE RESTRICT');
    s3b056Assert(strtoupper((string) ($fk['UPDATE_RULE'] ?? '')) === 'RESTRICT', 'FK UPDATE RESTRICT');

    $auditExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_personel_binding_audit'"
    )->fetchColumn();
    s3b056Assert($auditExists === 1, 'audit table exists');

    $nulls = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id IS NULL')->fetchColumn();
    s3b056Assert($nulls === 3, 'existing users remain NULL (no backfill)');

    // Multiple NULL allowed
    $pdo->exec('UPDATE users SET personel_id = NULL WHERE id IN (1,2,3)');
    s3b056Assert(true, 'multiple NULL personel_id allowed');

    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 2');
    $dupFailed = false;
    try {
        $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 3');
    } catch (Throwable $e) {
        $dupFailed = true;
    }
    s3b056Assert($dupFailed, 'duplicate non-null personel_id forbidden');

    // Audit insert smoke
    $pdo->exec(
        "INSERT INTO user_personel_binding_audit
         (user_id, old_personel_id, new_personel_id, action, changed_by)
         VALUES (2, NULL, 1, 'SET', 1)"
    );
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM user_personel_binding_audit')->fetchColumn();
    s3b056Assert($auditCount === 1, 'audit row insert works');

    // ON DELETE RESTRICT: cannot delete bound personel
    $restrictOk = false;
    try {
        $pdo->exec('DELETE FROM personeller WHERE id = 1');
    } catch (Throwable $e) {
        $restrictOk = true;
    }
    s3b056Assert($restrictOk, 'FK RESTRICT blocks personel delete while bound');

    $migrationSql = (string) file_get_contents(__DIR__ . '/../../api/migrations/056_users_personel_binding.sql');
    s3b056Assert(!preg_match('/UPDATE\s+users\s+SET\s+personel_id/i', $migrationSql), 'no backfill UPDATE');
    s3b056Assert(!preg_match('/\bINSERT\s+INTO\s+users\b/i', $migrationSql), 'no user seed');
    s3b056Assert(!preg_match('/\bDROP\s+TABLE\b/i', $migrationSql), 'no DROP TABLE');

    echo "verify-s3b-056-users-personel-binding-mysql: OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
