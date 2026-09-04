<?php

declare(strict_types=1);

/**
 * Focused atomicity acceptance for PersonelOrganizasyonDegisikligiService:
 * org tracked + bagli_amir + personel_tipi in one transaction; failure rolls all back.
 *
 * php tests/php/PersonelOrganizasyonAtomicSaveMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Personel\PersonelOrganizasyonDegisikligiService;

function poaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function poaPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function poaRequest(): Request
{
    return new Request();
}

function poaContext(int $actorId, string $key): OrganizasyonAuditContext
{
    return new OrganizasyonAuditContext($actorId, hash('sha256', $key), $key);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '' || stripos($dsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $dsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$db = 'medisa_poa_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = poaPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = poaPdo($rootDsn . ';dbname=' . $db);

    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            rol VARCHAR(32) NOT NULL,
            durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE personel_tipleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(80) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE gorevler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(80) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE departmanlar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(80) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad_soyad VARCHAR(160) NOT NULL,
            sube_id INT UNSIGNED NULL,
            departman_id INT UNSIGNED NULL,
            gorev_id INT UNSIGNED NULL,
            bagli_amir_id INT UNSIGNED NULL,
            personel_tipi_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $sql083 = (string) file_get_contents(__DIR__ . '/../../api/migrations/083_personel_organizasyon_degisiklik_auditleri.sql');
    $pdo->exec($sql083);

    $pdo->exec("INSERT INTO users (id, username, rol, durum) VALUES
        (1, 'gm', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'amir', 'BIRIM_AMIRI', 'AKTIF'),
        (9, 'eski', 'BIRIM_AMIRI', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad) VALUES (1, 'Tam Zamanli'), (2, 'Yari Zamanli')");
    $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (1, 'Gorev A'), (2, 'Gorev B')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Dep A')");
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, departman_id, gorev_id, bagli_amir_id, personel_tipi_id)
         VALUES (100, 'Ayşe Yılmaz', 1, 1, 1, 9, 1)"
    );

    $user = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $request = poaRequest();

    // SUCCESS: gorev + amir + tip in one call
    $ok = PersonelOrganizasyonDegisikligiService::apply(
        $pdo,
        $user,
        $request,
        100,
        [
            'preimage' => [
                'gorev_id' => 1,
                'bagli_amir_id' => 9,
                'personel_tipi_id' => 1,
            ],
            'targets' => [
                'gorev_id' => 2,
                'bagli_amir_id' => 2,
                'personel_tipi_id' => 2,
            ],
            'gerekce' => 'Org + amir + tip tek kayit',
        ],
        poaContext(1, 'poa-ok')
    );
    poaAssert($ok['olay_tipi'] === 'GOREV_UNVAN_DEGISIKLIGI', 'combined save resolves olay_tipi from tracked axis');
    poaAssert(
        $ok['degisen_alanlar'] === ['gorev_id', 'bagli_amir_id', 'personel_tipi_id']
            || count(array_diff(['gorev_id', 'bagli_amir_id', 'personel_tipi_id'], $ok['degisen_alanlar'])) === 0,
        'combined save changes org + work info fields'
    );

    $row = $pdo->query('SELECT gorev_id, bagli_amir_id, personel_tipi_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    poaAssert(
        (int) $row['gorev_id'] === 2
            && (int) $row['bagli_amir_id'] === 2
            && (int) $row['personel_tipi_id'] === 2,
        'org + amir + tip single save SUCCESS'
    );
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    poaAssert($auditCount === 1, 'structured audit row written on SUCCESS');

    // FAILURE mid-mutation: invalid amir after org target — nothing persists
    $pdo->exec('UPDATE personeller SET gorev_id = 1, bagli_amir_id = 9, personel_tipi_id = 1 WHERE id = 100');
    $beforeFail = $pdo->query('SELECT gorev_id, bagli_amir_id, personel_tipi_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    $fail = null;
    try {
        PersonelOrganizasyonDegisikligiService::apply(
            $pdo,
            $user,
            $request,
            100,
            [
                'preimage' => [
                    'gorev_id' => 1,
                    'bagli_amir_id' => 9,
                    'personel_tipi_id' => 1,
                ],
                'targets' => [
                    'gorev_id' => 2,
                    'bagli_amir_id' => 99999,
                    'personel_tipi_id' => 2,
                ],
                'gerekce' => 'Invalid amir must roll everything back',
            ],
            poaContext(1, 'poa-fail')
        );
    } catch (OrganizasyonException $e) {
        $fail = $e;
    }
    poaAssert($fail !== null && $fail->field === 'bagli_amir_id', 'invalid amir aborts combined save');
    $afterFail = $pdo->query('SELECT gorev_id, bagli_amir_id, personel_tipi_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    poaAssert(
        (int) $afterFail['gorev_id'] === (int) $beforeFail['gorev_id']
            && (int) $afterFail['bagli_amir_id'] === (int) $beforeFail['bagli_amir_id']
            && (int) $afterFail['personel_tipi_id'] === (int) $beforeFail['personel_tipi_id'],
        'second-stage-style failure leaves no field changed'
    );
    poaAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn() === 1,
        'failed attempt left no extra audit row'
    );

    // Generic PUT still denies tracked org fields
    $blocked = false;
    try {
        PersonelOrganizasyonDegisikligiService::assertNotChangedViaGenericPut(
            ['gorev_id' => 1],
            ['gorev_id' => 2]
        );
    } catch (OrganizasyonException $e) {
        $blocked = $e->errorCode === PersonelOrganizasyonDegisikligiService::ERROR_GENERIC_PUT;
    }
    poaAssert($blocked, 'generic protected-org PUT still DENY');

    // Work-info alone still allowed on generic PUT (bulk basic axis)
    PersonelOrganizasyonDegisikligiService::assertNotChangedViaGenericPut(
        ['bagli_amir_id' => 9, 'gorev_id' => 1],
        ['bagli_amir_id' => 2]
    );
    poaAssert(true, 'work-info-only generic PUT remains allowed for bulk owners');

    echo "verify-personel-organizasyon-atomic-save-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
