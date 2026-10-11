<?php

declare(strict_types=1);

/**
 * MG_PERSONEL_AUTO_SICIL_001 — disposable MariaDB acceptance for automatic sicil allocation.
 * php tests/php/PersonelAutoSicilMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\OfflineMutationIdempotencyService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelSicilAllocator;

function autoSicilAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function autoSicilPdoFromEnv(?string $dsnOverride = null): PDO
{
    $dsn = $dsnOverride ?? (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required (MEDISA_TEST_MYSQL_*).');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    return $pdo;
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function autoSicilPayload(array $overrides = []): array
{
    return array_merge([
        'tc_kimlik_no' => '10000000001',
        'ad' => 'Test',
        'soyad' => 'Personel',
        'dogum_tarihi' => '1990-01-01',
        'telefon' => '05550000001',
        'acil_durum_kisi' => 'Acil Kisi',
        'acil_durum_telefon' => '05550000009',
        'sicil_no' => null,
        'ise_giris_tarihi' => '2026-01-01',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => null,
        'aktif_durum' => 'AKTIF',
        'calisan_kapsami' => 'DIS_KAYNAK',
        'dogum_yeri' => null,
        'kan_grubu' => null,
        'bagli_amir_id' => null,
        'ucret_tipi_id' => null,
        'maas_tutari' => null,
        'prim_kurali_id' => null,
    ], $overrides);
}

/** Create one personel in its own transaction; returns the persisted sicil. */
function autoSicilCreate(PDO $pdo, array $overrides = []): string
{
    $pdo->beginTransaction();
    $id = PersonelCreateService::insertPersonel($pdo, autoSicilPayload($overrides));
    $pdo->commit();

    $stmt = $pdo->prepare('SELECT sicil_no FROM personeller WHERE id = :id');
    $stmt->execute(['id' => $id]);

    return (string) $stmt->fetchColumn();
}

/**
 * Child worker: holds an allocation open so the parent can prove the row lock serializes
 * two independent DB connections.
 */
if (($argv[1] ?? '') === '--child') {
    $pdo = autoSicilPdoFromEnv((string) ($argv[3] ?? ''));
    $action = (string) ($argv[2] ?? '');

    try {
        if ($action === 'hold-allocate') {
            $signal = (string) ($argv[4] ?? '');
            $holdMs = (int) ($argv[5] ?? 800);
            $tc = (string) ($argv[6] ?? '19000000001');
            $pdo->beginTransaction();
            $sicil = PersonelSicilAllocator::allocateInTransaction($pdo);
            if ($signal !== '') {
                file_put_contents($signal, 'ready');
            }
            usleep(max(50, $holdMs) * 1000);
            PersonelCreateService::insertPersonel(
                $pdo,
                autoSicilPayload(['sicil_no' => $sicil, 'tc_kimlik_no' => $tc])
            );
            $pdo->commit();
            echo 'OK:' . $sicil . PHP_EOL;
            exit(0);
        }
        fwrite(STDERR, 'Unknown child action: ' . $action . PHP_EOL);
        exit(2);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

/** @return list<string> */
function autoSicilSplitSql(string $sql): array
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

function autoSicilApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (autoSicilSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function autoSicilDsnForDb(string $database): string
{
    return (string) preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));
}

/** @param list<string> $args @return array{process: resource, pipes: array<int, resource>} */
function autoSicilSpawnChild(array $args, string $dsn): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows' && !extension_loaded('pdo_mysql')) {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=php_pdo_mysql.dll';
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['MEDISA_TEST_MYSQL_DSN'] = $dsn;
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not start.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array{process: resource, pipes: array<int, resource>} $child */
function autoSicilFinishChild(array $child): string
{
    $stdout = trim((string) stream_get_contents($child['pipes'][1]));
    $stderr = trim((string) stream_get_contents($child['pipes'][2]));
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $status = proc_close($child['process']);
    if ($status !== 0) {
        throw new RuntimeException('Child failed (status=' . $status . '): ' . $stderr);
    }

    return $stdout;
}

$root = autoSicilPdoFromEnv();
$database = 'medisa_auto_sicil_078_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = autoSicilDsnForDb($database);
    $pdo = autoSicilPdoFromEnv($dsn);

    // Focused slice: base schema + sicil UNIQUE owner + idempotency owner + this pack.
    foreach ([
        '001_initial_schema.sql',
        '066_personel_calisan_kapsami.sql',
        '070_offline_mutation_idempotency.sql',
        '078_personel_sicil_sequence.sql',
    ] as $migration) {
        autoSicilApply($pdo, $migration);
    }
    $chain = array_values(array_filter(scandir(__DIR__ . '/../../api/migrations') ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($chain, SORT_STRING);
    autoSicilAssert(
        end($chain) === '100_user_yetki_istisnalari.sql',
        '100 canonical migration tip'
    );
    autoSicilAssert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller'
               AND INDEX_NAME = 'uq_personeller_sicil'"
        )->fetchColumn() > 0,
        'unique sicil constraint son savunma olarak duruyor'
    );

    autoSicilApply($pdo, '078_personel_sicil_sequence.sql');
    autoSicilAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_sicil_sequence')->fetchColumn() === 1,
        '078 tekrar apply idempotent (tek satir)'
    );

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");

    // --- Empty table → first sicil is 001 (three-digit floor) ---
    $first = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000001']);
    autoSicilAssert($first === '001', 'bos tabloda ilk otomatik sicil 001');

    // --- Continues the existing numeric series, keeping leading zeros ---
    $second = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000002']);
    autoSicilAssert($second === '002', 'sonraki otomatik sicil 002');

    // --- Non-numeric legacy sicil is ignored by the counter and left untouched ---
    $legacy = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000003', 'sicil_no' => 'MED-LEGACY']);
    autoSicilAssert($legacy === 'MED-LEGACY', 'legacy non-numeric sicil korunuyor');
    $afterLegacy = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000004']);
    autoSicilAssert($afterLegacy === '003', 'non-numeric sicil numeric sayaci bozmuyor');

    // --- Occupied candidate is skipped (explicit 004 blocks the natural next value) ---
    autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000005', 'sicil_no' => '004']);
    $skipped = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000006']);
    autoSicilAssert($skipped === '005', 'dolu aday sicil atlanip sonraki veriliyor');

    // --- PASIF personel sicil is never handed out again ---
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE sicil_no = '005'");
    $afterPasif = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000007']);
    autoSicilAssert($afterPasif === '006', 'PASIF personel sicili yeniden kullanilmiyor');

    // --- Deleting the highest personel must not move the counter backwards ---
    $pdo->exec("DELETE FROM personeller WHERE sicil_no = '006'");
    $afterDelete = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000008']);
    autoSicilAssert($afterDelete === '007', 'silinen personel sicili geri donmuyor');

    // --- Explicit higher numeric sicil lifts the floor ---
    autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000009', 'sicil_no' => '382']);
    $afterExplicit = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000010']);
    autoSicilAssert($afterExplicit === '383', 'explicit yuksek sicil sayac zeminini tasiyor');

    // --- 999 → 1000 widening stays natural ---
    autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000011', 'sicil_no' => '999']);
    $afterNineNineNine = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000012']);
    autoSicilAssert($afterNineNineNine === '1000', '999 sonrasi dogal olarak 1000');

    // --- Explicit duplicate sicil still raises the unique-constraint duplicate path ---
    $duplicateDetected = false;
    try {
        autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000013', 'sicil_no' => '382']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $duplicateDetected = PersonelCreateService::isDuplicateSicilException($e);
    }
    autoSicilAssert($duplicateDetected, 'explicit duplicate sicil DUPLICATE_SICIL_NO davranisini koruyor');

    // --- Rollback leaves neither a half personel nor a broken counter ---
    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    $pdo->beginTransaction();
    PersonelCreateService::insertPersonel($pdo, autoSicilPayload(['tc_kimlik_no' => '10000000014']));
    $pdo->rollBack();
    $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    autoSicilAssert($countBefore === $countAfter, 'rollback yarim personel birakmiyor');
    $afterRollback = autoSicilCreate($pdo, ['tc_kimlik_no' => '10000000015']);
    autoSicilAssert(
        $afterRollback === '1001'
            && (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE sicil_no = '1001'")->fetchColumn() === 1,
        'rollback sonrasi sayac tutarli'
    );

    // --- Concurrency: two connections can never take the same candidate ---
    $signal = sys_get_temp_dir() . '/medisa-auto-sicil-' . bin2hex(random_bytes(4)) . '.signal';
    @unlink($signal);
    $child = autoSicilSpawnChild(['hold-allocate', $dsn, $signal, '900', '19000000001'], $dsn);
    $waitStarted = microtime(true);
    while (!file_exists($signal) && (microtime(true) - $waitStarted) < 10.0) {
        usleep(20000);
    }
    autoSicilAssert(file_exists($signal), 'child tahsis kilidini aldi');
    $parentSicil = autoSicilCreate($pdo, ['tc_kimlik_no' => '19000000002']);
    $childOut = autoSicilFinishChild($child);
    @unlink($signal);
    $childSicil = substr(trim($childOut), 3);
    autoSicilAssert($childSicil !== '' && $childSicil !== $parentSicil, 'concurrent create ayni sicili alamiyor');
    autoSicilAssert(
        (int) $pdo->query('SELECT COUNT(DISTINCT sicil_no) FROM personeller')->fetchColumn()
            === (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(),
        'tum siciller benzersiz kaldi'
    );

    // --- Idempotent replay must not mint a second sicil ---
    $idemKey = 'auto-sicil-replay-001';
    $scope = 'personeller.create';
    $hash = OfflineMutationIdempotencyService::hashPayload([
        'op' => $scope,
        'payload' => autoSicilPayload(['tc_kimlik_no' => '19000000003']),
    ]);

    $pdo->beginTransaction();
    OfflineMutationIdempotencyService::claimInTransaction($pdo, 1, $scope, $idemKey, $hash);
    $replayPersonelId = PersonelCreateService::insertPersonel(
        $pdo,
        autoSicilPayload(['tc_kimlik_no' => '19000000003'])
    );
    OfflineMutationIdempotencyService::completeInTransaction(
        $pdo,
        1,
        $scope,
        $idemKey,
        201,
        'personel',
        $replayPersonelId,
        null
    );
    $pdo->commit();

    $sequenceAfterFirst = (int) $pdo->query('SELECT next_value FROM personel_sicil_sequence WHERE id = 1')->fetchColumn();
    $personelCountAfterFirst = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();

    $replay = OfflineMutationIdempotencyService::findCompletedReplay($pdo, 1, $scope, $idemKey, $hash);
    autoSicilAssert(
        is_array($replay) && (int) $replay['result_entity_id'] === $replayPersonelId,
        'ayni idempotency key ilk personeli donduruyor'
    );
    autoSicilAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn() === $personelCountAfterFirst
            && (int) $pdo->query('SELECT next_value FROM personel_sicil_sequence WHERE id = 1')->fetchColumn()
                === $sequenceAfterFirst,
        'replay ikinci sicil uretmiyor'
    );

    // --- Contract guards shared with the pure runner ---
    $created = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'tc_kimlik_no' => '19000000004',
        'ad' => 'Sicilsiz',
        'soyad' => 'Kayit',
        'dogum_tarihi' => '2002-01-01',
        'telefon' => '05550000009',
        'ise_giris_tarihi' => '2026-08-12',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => 1,
        'aktif_durum' => 'AKTIF',
        'calisan_kapsami' => 'DIS_KAYNAK',
    ]);
    autoSicilAssert($created['sicil_no'] === null, 'interactive create sicilsiz payload kabul ediyor');

    $importResult = PersonelCanonicalValidator::validateImportAnaVeriRow([
        'tc_kimlik_no' => '19000000005',
        'ad' => 'Import',
        'soyad' => 'Satir',
        'dogum_tarihi' => '2000-01-01',
        'telefon' => '05550000010',
        'ise_giris_tarihi' => '2026-01-01',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => 1,
    ]);
    $importFields = array_column($importResult['errors'], 'field');
    autoSicilAssert(in_array('sicil_no', $importFields, true), 'import sicilsiz satiri reddediyor');

    echo 'verify-personel-auto-sicil-mysql: OK' . PHP_EOL;
} finally {
    // Release the worker connection first; an open transaction would block DROP on MDL.
    if (isset($pdo) && $pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo = null;
    }
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
