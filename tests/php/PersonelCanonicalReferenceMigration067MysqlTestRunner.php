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

    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Üretim', 'AKTIF')");
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

    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad) VALUES (1, 'TEST', 'Test Şubesi')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, bolum_id, birim_id
        ) VALUES (
            1, '10000000001', 'Test', 'Personel', '1990-01-01', '555',
            'Acil', '555', 'S-1', '2020-01-01',
            1, 1, 3, 10
        )"
    );
    p067Assert(p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql') === null, '067 reapply idempotent');
    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM birimler WHERE id = 10 AND bolum_id = 3")->fetchColumn() === 1,
        '067 idempotent target remains stable'
    );

    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE birim_id = 10")->fetchColumn() === 1,
        '067 canonical state tolerates personnel usage'
    );

    $pdo->exec("UPDATE birimler SET bolum_id = 5 WHERE id = 10");
    $pdo->exec("UPDATE bolumler SET durum = 'PASIF' WHERE id = 5");
    $blockedMixedPasif = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blockedMixedPasif !== null, '067 mixed legacy parent with passive section fails closed');
    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5,
        '067 mixed passive failure leaves parent unchanged'
    );
    p067Assert(
        (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'PASIF',
        '067 mixed passive failure leaves section unchanged'
    );

    $pdo->exec("UPDATE birimler SET bolum_id = 3 WHERE id = 10");
    $pdo->exec("UPDATE bolumler SET durum = 'AKTIF' WHERE id = 5");
    $blockedMixedActive = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blockedMixedActive !== null, '067 canonical parent with active legacy section fails closed');
    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 3,
        '067 mixed active failure leaves parent unchanged'
    );

    $pdo->exec("UPDATE birimler SET bolum_id = 5 WHERE id = 10");
    $pdo->exec("UPDATE personeller SET bolum_id = 5 WHERE id = 1");
    $blockedLegacyUsage = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blockedLegacyUsage !== null, '067 legacy personnel usage fails closed');
    p067Assert(
        (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5
        && (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'AKTIF',
        '067 legacy personnel usage leaves legacy state unchanged'
    );
    $pdo->exec("DELETE FROM personeller WHERE id = 1");
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

    $pdo->exec(
        "CREATE TRIGGER p067_test_first_update_noop
         BEFORE UPDATE ON birimler
         FOR EACH ROW
         SET NEW.bolum_id = OLD.bolum_id"
    );
    try {
        $blockedFirstRowCount = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
        p067Assert($blockedFirstRowCount !== null, '067 first update affected-row failure');
        p067Assert(
            (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5
            && (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'AKTIF',
            '067 first update failure rolls back full transaction'
        );
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS p067_test_first_update_noop');
    }

    $pdo->exec(
        "CREATE TRIGGER p067_test_second_update_noop
         BEFORE UPDATE ON bolumler
         FOR EACH ROW
         SET NEW.durum = OLD.durum"
    );
    try {
        $blockedSecondRowCount = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
        p067Assert($blockedSecondRowCount !== null, '067 second update affected-row failure');
        p067Assert(
            (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5
            && (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'AKTIF',
            '067 second update failure rolls back first update'
        );
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS p067_test_second_update_noop');
    }

    $pdo->exec(
        "CREATE TRIGGER p067_test_readback_corruption
         AFTER UPDATE ON bolumler
         FOR EACH ROW
         UPDATE birimler SET bolum_id = 5 WHERE id = 10"
    );
    try {
        $blockedReadback = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
        p067Assert($blockedReadback !== null, '067 canonical readback failure');
        p067Assert(
            (int) $pdo->query("SELECT bolum_id FROM birimler WHERE id = 10")->fetchColumn() === 5
            && (string) $pdo->query("SELECT durum FROM bolumler WHERE id = 5")->fetchColumn() === 'AKTIF',
            '067 readback failure rolls back full transaction'
        );
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS p067_test_readback_corruption');
    }

    $pdo->exec("UPDATE birimler SET bolum_id = 3 WHERE id = 10");
    $pdo->exec("UPDATE bolumler SET durum = 'PASIF' WHERE id = 5");
    $pdo->exec("UPDATE departmanlar SET ad = 'Yanlış' WHERE id = 1");
    $blockedRoot = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blockedRoot !== null, '067 wrong department root fails closed');
    p067Assert(
        (string) $pdo->query("SELECT ad FROM departmanlar WHERE id = 1")->fetchColumn() === 'Yanlış',
        '067 wrong root failure leaves root unchanged'
    );
    $pdo->exec("UPDATE departmanlar SET ad = 'Üretim' WHERE id = 1");

    $pdo->exec("INSERT INTO birimler (id, bolum_id, ad, durum) VALUES (11, 5, 'Güvenlik', 'AKTIF')");
    $blockedDuplicate = p067RunMigration($pdo, '067_personel_canonical_reference_gate.sql');
    p067Assert($blockedDuplicate !== null, '067 duplicate active Güvenlik fails closed');
    p067Assert(
        (int) $pdo->query("SELECT COUNT(*) FROM birimler WHERE ad = 'Güvenlik' AND durum = 'AKTIF'")->fetchColumn() === 2,
        '067 duplicate failure leaves duplicate rows unchanged'
    );
    $pdo->exec('DELETE FROM birimler WHERE id = 11');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo = null;
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}

echo 'verify-personel-canonical-reference-migration-067-mysql: OK' . PHP_EOL;
