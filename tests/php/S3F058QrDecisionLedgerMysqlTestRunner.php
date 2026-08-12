<?php

declare(strict_types=1);

/**
 * S3F: disposable MariaDB — migration 058 decision ledger + UNIQUE/FK RESTRICT.
 * php tests/php/S3F058QrDecisionLedgerMysqlTestRunner.php
 */

function s3f058Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3f058RootPdo(): PDO
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
function s3f058SplitSql(string $sql): array
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

function s3f058Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3f058SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3f058PdoForDb(string $database): PDO
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

$root = s3f058RootPdo();
$database = 'medisa_s3f_058_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = s3f058PdoForDb($database);

    s3f058Apply($pdo, '001_initial_schema.sql');
    s3f058Apply($pdo, '051_users_varsayilan_sube_id.sql');

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

    $hash = password_hash('S3fMigPass-24chars!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'u1', " . $pdo->quote($hash) . ", 'User One', 'GENEL_YONETICI', 'AKTIF')"
    );

    s3f058Apply($pdo, '056_users_personel_binding.sql');
    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 1');

    s3f058Apply($pdo, '057_qr_attendance_events.sql');
    s3f058Assert(true, '057 applied before 058');

    s3f058Apply($pdo, '058_qr_puantaj_candidate_decision_ledger.sql');
    s3f058Assert(true, '058 ilk apply');
    s3f058Apply($pdo, '058_qr_puantaj_candidate_decision_ledger.sql');
    s3f058Assert(true, '058 ikinci apply idempotent');

    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'qr_puantaj_candidate_decision_ledger'"
    )->fetchColumn();
    s3f058Assert($tableExists === 1, 'qr_puantaj_candidate_decision_ledger table exists');

    $uq = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'qr_puantaj_candidate_decision_ledger'
           AND INDEX_NAME = 'uq_qr_pc_decision_user_nonce'
           AND NON_UNIQUE = 0"
    )->fetchColumn();
    s3f058Assert($uq > 0, 'unique uq_qr_pc_decision_user_nonce exists');

    foreach (['idx_qr_pc_decision_personel_date', 'idx_qr_pc_decision_hash', 'idx_qr_pc_decision_sube_date'] as $indexName) {
        $idx = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'qr_puantaj_candidate_decision_ledger'
               AND INDEX_NAME = " . $pdo->quote($indexName)
        )->fetchColumn();
        s3f058Assert($idx > 0, 'index ' . $indexName . ' exists');
    }

    $fkNames = [
        'fk_qr_pc_decision_personel',
        'fk_qr_pc_decision_sube',
        'fk_qr_pc_decision_puantaj',
        'fk_qr_pc_decision_user',
        'fk_qr_pc_decision_supersedes',
    ];
    foreach ($fkNames as $fkName) {
        $fk = $pdo->query(
            "SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = " . $pdo->quote($fkName)
        )->fetch(PDO::FETCH_ASSOC);
        s3f058Assert(is_array($fk), 'FK ' . $fkName . ' exists');
        $del = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        $upd = strtoupper((string) ($fk['UPDATE_RULE'] ?? ''));
        s3f058Assert(
            in_array($del, ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fkName . ' DELETE RESTRICT'
        );
        s3f058Assert(
            in_array($upd, ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fkName . ' UPDATE RESTRICT'
        );
    }

    $migDir = __DIR__ . '/../../api/migrations';
    foreach (['052', '053', '054', '055', '056', '057'] as $prefix) {
        $found = glob($migDir . '/' . $prefix . '_*.sql');
        s3f058Assert(is_array($found) && count($found) >= 1, 'prior migration ' . $prefix . ' still present');
    }

    $migrationSql = (string) file_get_contents(__DIR__ . '/../../api/migrations/058_qr_puantaj_candidate_decision_ledger.sql');
    s3f058Assert(!preg_match('/^\s*INSERT\s+/im', $migrationSql), '058 no seed INSERT');
    s3f058Assert(!preg_match('/\bDROP\s+TABLE\b/i', $migrationSql), '058 no DROP TABLE');
    s3f058Assert(stripos($migrationSql, 'CREATE TABLE IF NOT EXISTS qr_puantaj_candidate_decision_ledger') !== false, '058 append-only ledger CREATE');
    s3f058Assert(stripos($migrationSql, 'ON DELETE RESTRICT') !== false, '058 ON DELETE RESTRICT in SQL');

    echo "S3F 058 mysql runner OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
