<?php

declare(strict_types=1);

/**
 * 067 canonical reference migration focused MariaDB acceptance.
 * Production is never a valid target for this runner.
 */

function p067Pdo(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function p067Statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inTrigger = false;

    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if (!$inSingle && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
            continue;
        }
        if (!$inTrigger && !$inSingle && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] !== "'") {
                continue;
            }
            if ($inSingle && $i + 1 < $length && $line[$i + 1] === "'") {
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        }
        if ($inSingle) {
            continue;
        }
        if ($inTrigger) {
            $complete = preg_match('/\bTHEN\b/i', $buffer)
                ? preg_match('/^END\s+IF;$/i', $trimmed)
                : substr($trimmed, -1) === ';';
            if (!$complete) {
                continue;
            }
            $inTrigger = false;
        }
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

function p067Apply(PDO $pdo, string $filename): void
{
    $path = __DIR__ . '/../../api/migrations/' . $filename;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration not readable: ' . $filename);
    }
    foreach (p067Statements($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p067Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function p067SafeDatabase(string $database, string $dsn): void
{
    if (stripos($database, 'karmotor_medisa') !== false
        || stripos($dsn, 'karmotor_medisa') !== false
        || preg_match('/host=([^;]+)/i', $dsn, $match)
            && !in_array(strtolower(trim($match[1])), ['127.0.0.1', 'localhost', '::1'], true)
    ) {
        throw new RuntimeException('Refusing non-disposable database target.');
    }
}

function p067RunMigration(PDO $pdo, string $filename): ?Throwable
{
    try {
        p067Apply($pdo, $filename);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return $error;
    }

    return null;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '' || $user === '') {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}

$root = p067Pdo($dsn, $user, $password);
$database = 'medisa_pack067_' . substr(bin2hex(random_bytes(4)), 0, 8);
p067SafeDatabase($database, $dsn);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dbDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn);
$pdo = p067Pdo((string) $dbDsn, $user, $password);

try {
    foreach (glob(__DIR__ . '/../../api/migrations/0*.sql') ?: [] as $path) {
        $filename = basename($path);
        if (strcmp($filename, '067_personel_canonical_reference_gate.sql') < 0) {
            p067Apply($pdo, $filename);
        }
    }

    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Uretim', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO bolumler (id, departman_id, ad, durum) VALUES
         (3, 1, 'Üretim', 'AKTIF'),
         (5, 1, 'Üretim Genel', 'AKTIF')"
    );
    $pdo->exec("INSERT INTO birimler (id, bolum_id, ad, durum) VALUES (10, 5, 'Güvenlik', 'AKTIF')");

    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5,
        '067 precondition legacy parent'
    );
    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM birimler WHERE bolum_id = 5 AND durum = 'AKTIF' AND id <> 10")->fetchColumn() === 0,
        '067 precondition no unexpected active child'
    );

    p067Assert(p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql') === null, '067 first apply PASS');
    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 3,
        '067 preserves Güvenlik ID and moves parent'
    );
    p067Assert(
        (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'PASIF',
        '067 passivates unused legacy section'
    );
    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM birimler WHERE ad = 'Güvenlik' AND durum = 'AKTIF'")->fetchColumn() === 1,
        '067 no duplicate active Güvenlik'
    );

    p067Assert(p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql') === null, '067 reapply idempotent');
    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM birimler WHERE id = 10 AND bolum_id = 3")->fetchColumn() === 1,
        '067 idempotent target remains stable'
    );

    $pdo->exec("UPDATE birimler SET bolum_id = 5 WHERE id = 10");
    $pdo->exec("UPDATE bolumler SET durum = 'AKTIF' WHERE id = 5");
    $pdo->exec("INSERT INTO birimler (id, bolum_id, ad, durum) VALUES (11, 5, 'Legacy Child', 'AKTIF')");
    $blocked = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blocked !== null, '067 unsafe active child fails closed');
    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5,
        '067 failed precondition leaves parent unchanged'
    );
    p067Assert(
        (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'AKTIF',
        '067 failed precondition leaves legacy status unchanged'
    );
    $pdo->exec('DELETE FROM birimler WHERE id = 11');
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo 'verify-personel-canonical-reference-migration-067-mysql: OK' . PHP_EOL;
