<?php

declare(strict_types=1);

/**
 * I13-B: disposable MariaDB — apply tip 051 additively + idempotency + FK/column asserts.
 * php tests/php/I13B051VarsayilanSubeMigrationMysqlTestRunner.php
 */

function i13b051Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function i13b051RootPdo(): PDO
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
function i13b051SplitSql(string $sql): array
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

function i13b051Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (i13b051SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function i13b051PdoForDb(string $database): PDO
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

$root = i13b051RootPdo();
$database = 'medisa_i13b_051_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = i13b051PdoForDb($database);

    i13b051Apply($pdo, '001_initial_schema.sql');
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF'), (2, 'B', 'Sube B', 'AKTIF')");
    $hash = password_hash('I13bMigPass-24chars!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'legacy_user', " . $pdo->quote($hash) . ", 'Legacy User', 'GENEL_YONETICI', 'AKTIF')"
    );

    i13b051Apply($pdo, '051_users_varsayilan_sube_id.sql');
    i13b051Assert(true, '051 ilk apply');
    i13b051Apply($pdo, '051_users_varsayilan_sube_id.sql');
    i13b051Assert(true, '051 ikinci apply idempotent');

    $col = $pdo->query(
        "SELECT IS_NULLABLE, DATA_TYPE, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'varsayilan_sube_id'"
    )->fetch(PDO::FETCH_ASSOC);
    i13b051Assert(is_array($col), 'column varsayilan_sube_id exists');
    i13b051Assert(($col['IS_NULLABLE'] ?? '') === 'YES', 'column IS_NULLABLE=YES');
    i13b051Assert(strtolower((string) ($col['DATA_TYPE'] ?? '')) === 'int', 'column DATA_TYPE=int');
    i13b051Assert(stripos((string) ($col['COLUMN_TYPE'] ?? ''), 'unsigned') !== false, 'column COLUMN_TYPE unsigned');

    $idx = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'idx_users_varsayilan_sube_id'"
    )->fetchColumn();
    i13b051Assert($idx > 0, 'index idx_users_varsayilan_sube_id exists');

    $fk = $pdo->query(
        "SELECT rc.DELETE_RULE, kcu.REFERENCED_TABLE_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.CONSTRAINT_NAME = 'fk_users_varsayilan_sube'
           AND kcu.TABLE_NAME = 'users'
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    i13b051Assert(is_array($fk), 'FK fk_users_varsayilan_sube exists');
    i13b051Assert(($fk['REFERENCED_TABLE_NAME'] ?? '') === 'subeler', 'FK references subeler');
    i13b051Assert(strtoupper((string) ($fk['DELETE_RULE'] ?? '')) === 'SET NULL', 'FK DELETE_RULE=SET NULL');

    $existingDefault = $pdo->query(
        'SELECT varsayilan_sube_id FROM users WHERE id = 1'
    )->fetchColumn();
    i13b051Assert($existingDefault === null, 'existing user varsayilan_sube_id IS NULL');

    $migrationPath = __DIR__ . '/../../api/migrations/051_users_varsayilan_sube_id.sql';
    $migrationSql = file_get_contents($migrationPath);
    if ($migrationSql === false) {
        throw new RuntimeException('051 source okunamadi');
    }
    i13b051Assert(
        !preg_match('/UPDATE\s+users\s+SET\s+varsayilan/i', $migrationSql),
        'migration SQL has no UPDATE users SET varsayilan'
    );

    $pdo->exec('UPDATE users SET varsayilan_sube_id = 2 WHERE id = 1');
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (1, 1), (1, 2)');
    $pdo->exec('DELETE FROM subeler WHERE id = 2');

    $afterDelete = $pdo->query('SELECT id, varsayilan_sube_id FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    i13b051Assert(is_array($afterDelete) && (int) $afterDelete['id'] === 1, 'user still exists after sube delete');
    i13b051Assert($afterDelete['varsayilan_sube_id'] === null, 'ON DELETE SET NULL clears varsayilan_sube_id');

    $remainingScopes = $pdo->query(
        'SELECT sube_id FROM user_subeler WHERE user_id = 1 ORDER BY sube_id ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
    i13b051Assert(
        count($remainingScopes) === 1 && (int) $remainingScopes[0] === 1,
        'user_subeler only keeps sube 1 after CASCADE'
    );

    echo "verify-i13b-051-varsayilan-sube-migration-mysql: OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore cleanup failures
    }
}
