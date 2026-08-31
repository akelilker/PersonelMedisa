<?php

declare(strict_types=1);

/**
 * MG-ORG-AUDITED-BRANCH-CHANGE-001 — migration 080 runtime acceptance for the
 * three organisation audit owners, against a real MariaDB.
 *
 * Everything runs on a disposable database created and dropped by this runner.
 *
 * php tests/php/OrganizasyonAuditOwnersMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Personel\PersonelKaliciSubeDegisikligiService;

function auditAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function auditPdo(string $dsn): PDO
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

/** @return array{status:int, code:string}|null */
function auditFailure(callable $callable): ?array
{
    try {
        $callable();

        return null;
    } catch (OrganizasyonException $exception) {
        return ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
}

function auditContextFor(int $actorUserId, string $seed): OrganizasyonAuditContext
{
    return new OrganizasyonAuditContext($actorUserId, hash('sha256', $seed));
}

function auditScalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

/** @return array<string, mixed>|null */
function auditRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function auditResetCaches(): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();
    UserOrgAssignmentSchema::resetCache();
}

function auditBaseSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            rol VARCHAR(40) NOT NULL DEFAULT 'PERSONEL',
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE sgk_isverenler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_sgk_isverenler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(120) NOT NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_subeler_kod (kod),
            CONSTRAINT fk_subeler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE calisma_lokasyonlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_calisma_lokasyonlari_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE departmanlar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(120) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE sube_departmanlar (
            sube_id INT UNSIGNED NOT NULL,
            departman_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (sube_id, departman_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad_soyad VARCHAR(160) NOT NULL,
            sube_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE user_subeler (
            user_id INT UNSIGNED NOT NULL,
            sube_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function auditApplyMigration(PDO $pdo, string $file): void
{
    $sql = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    $pdo->exec($sql);
    auditResetCaches();
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

$db = 'medisa_orgaudit_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = auditPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = auditPdo($rootDsn . ';dbname=' . $db);
    auditBaseSchema($pdo);

    // =====================================================================
    // D) Migration / runtime
    // =====================================================================
    auditResetCaches();
    auditAssert(
        !OrganizasyonAuditWriter::isReady($pdo, OrganizasyonAuditWriter::PERSONEL_SUBE_TABLE),
        'audit writer reports not-ready before migration 080'
    );

    auditApplyMigration($pdo, '079_sirket_sube_hiyerarsisi.sql');
    auditApplyMigration($pdo, '080_organizasyon_audit_owners.sql');
    auditAssert(OrganizasyonSchema::isSchemaReady($pdo), 'migration 079 up');

    $auditTables = [
        'personel_sube_degisiklik_auditleri',
        'sube_olusturma_auditleri',
        'user_org_scope_auditleri',
    ];
    foreach ($auditTables as $table) {
        $exists = (int) auditScalar(
            $pdo,
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            ['t' => $table]
        );
        auditAssert($exists === 1, 'migration 080 up: ' . $table);
    }

    // Re-running the migration must be a no-op, not a failure.
    auditApplyMigration($pdo, '080_organizasyon_audit_owners.sql');
    auditAssert(true, 'migration 080 is idempotent on re-run');

    $fkCount = (int) auditScalar(
        $pdo,
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
           AND TABLE_NAME IN (\'personel_sube_degisiklik_auditleri\', \'sube_olusturma_auditleri\', \'user_org_scope_auditleri\')'
    );
    auditAssert($fkCount === 8, 'audit foreign keys present (got ' . $fkCount . ')');

    foreach ([
        'personel_sube_degisiklik_auditleri' => 'idx_psda_personel_created',
        'sube_olusturma_auditleri' => 'uq_soa_sube',
        'user_org_scope_auditleri' => 'idx_uosa_target_created',
    ] as $table => $index) {
        $found = (int) auditScalar(
            $pdo,
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i',
            ['t' => $table, 'i' => $index]
        );
        auditAssert($found > 0, 'index ' . $index . ' present');
    }

    // ---------------------------------------------------------------- fixtures
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (1, 'gm', 'GENEL_YONETICI')");
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (2, 'sistem', 'SISTEM_YONETICISI')");
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (3, 'bolum', 'BOLUM_YONETICISI')");
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (4, 'muhasebe', 'MUHASEBE')");
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (5, 'ik', 'IK_SORUMLUSU')");

    $pdo->exec("INSERT INTO sirketler (id, kod, ad, durum) VALUES (1, 'MDS', 'Medisa', 'AKTIF')");
    $pdo->exec("INSERT INTO sirketler (id, kod, ad, durum) VALUES (2, 'SNY', 'Senay', 'AKTIF')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (1, 'MDS-SGK', 'Medisa SGK', 'AKTIF')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (2, 'SNY-SGK', 'Senay SGK', 'AKTIF')");
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 1 WHERE id = 1');
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 2 WHERE id = 2');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum, sgk_isveren_id) VALUES (1, 'MDS-FAB', 'Fabrika', 'AKTIF', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum, sgk_isveren_id) VALUES (2, 'MDS-IZM', 'Izmir', 'AKTIF', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum, sgk_isveren_id) VALUES (3, 'MDS-PAS', 'Pasif', 'PASIF', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum, sgk_isveren_id) VALUES (4, 'SNY-MRK', 'Merkez', 'AKTIF', 2)");
    $pdo->exec('UPDATE subeler SET sirket_id = 1 WHERE id IN (1, 2, 3)');
    $pdo->exec('UPDATE subeler SET sirket_id = 2 WHERE id = 4');

    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (9, 'KRB', 'Karabuk', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Uretim'), (2, 'Idari')");
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id, calisma_lokasyonu_id)
         VALUES (100, 'Test Kaydi', 1, 1, 9)"
    );

    $gm = ['id' => 1, 'rol' => 'GENEL_YONETICI'];
    $sistem = ['id' => 2, 'rol' => 'SISTEM_YONETICISI'];

    $movePayload = [
        'beklenen_mevcut_sube_id' => 1,
        'yeni_sube_id' => 2,
        'gerekce' => 'Yeni sube acilisi kapsaminda kalici gorevlendirme.',
    ];

    // =====================================================================
    // A) Permanent personnel branch change
    // =====================================================================
    foreach ([
        ['id' => 3, 'rol' => 'BOLUM_YONETICISI'],
        ['id' => 4, 'rol' => 'MUHASEBE'],
        ['id' => 5, 'rol' => 'IK_SORUMLUSU'],
        ['id' => 5, 'rol' => 'PERSONEL'],
    ] as $denied) {
        $failure = auditFailure(function () use ($pdo, $denied, $movePayload) {
            PersonelKaliciSubeDegisikligiService::apply(
                $pdo,
                $denied,
                100,
                $movePayload,
                auditContextFor((int) $denied['id'], 'role-' . $denied['rol'])
            );
        });
        auditAssert(
            $failure !== null && $failure['status'] === 403,
            'role ' . $denied['rol'] . ' cannot permanently move a person'
        );
    }
    auditAssert(
        (int) auditScalar($pdo, 'SELECT sube_id FROM personeller WHERE id = 100') === 1,
        'denied roles left the branch untouched'
    );

    $stale = auditFailure(function () use ($pdo, $gm) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            ['beklenen_mevcut_sube_id' => 4, 'yeni_sube_id' => 2, 'gerekce' => 'Hatali on goruntu denemesi.'],
            auditContextFor(1, 'stale')
        );
    });
    auditAssert(
        $stale !== null && $stale['status'] === 409
            && $stale['code'] === PersonelKaliciSubeDegisikligiService::ERROR_STALE,
        'a stale expected branch is refused'
    );

    $missing = auditFailure(function () use ($pdo, $gm) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            ['beklenen_mevcut_sube_id' => 1, 'yeni_sube_id' => 9999, 'gerekce' => 'Olmayan sube denemesi.'],
            auditContextFor(1, 'missing')
        );
    });
    auditAssert(
        $missing !== null && $missing['code'] === PersonelKaliciSubeDegisikligiService::ERROR_TARGET,
        'a missing target branch is refused'
    );

    $inactive = auditFailure(function () use ($pdo, $gm) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            ['beklenen_mevcut_sube_id' => 1, 'yeni_sube_id' => 3, 'gerekce' => 'Pasif sube denemesi.'],
            auditContextFor(1, 'inactive')
        );
    });
    auditAssert(
        $inactive !== null && $inactive['status'] === 409
            && $inactive['code'] === PersonelKaliciSubeDegisikligiService::ERROR_TARGET,
        'an inactive target branch is refused'
    );

    $mismatch = auditFailure(function () use ($pdo, $gm) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            ['beklenen_mevcut_sube_id' => 1, 'yeni_sube_id' => 4, 'gerekce' => 'Farkli sirket denemesi.'],
            auditContextFor(1, 'mismatch')
        );
    });
    auditAssert(
        $mismatch !== null && $mismatch['status'] === 409
            && $mismatch['code'] === PersonelKaliciSubeDegisikligiService::ERROR_SGK_MISMATCH,
        'an SGK employer / target company mismatch is refused'
    );

    $shortGerekce = auditFailure(function () use ($pdo, $gm) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            ['beklenen_mevcut_sube_id' => 1, 'yeni_sube_id' => 2, 'gerekce' => 'kisa'],
            auditContextFor(1, 'gerekce')
        );
    });
    auditAssert($shortGerekce !== null && $shortGerekce['status'] === 400, 'a missing justification is refused');

    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM personel_sube_degisiklik_auditleri') === 0,
        'no refused attempt left an audit row'
    );

    // Audit failure must roll the personnel write back: an actor id with no
    // users row makes the audit INSERT violate its foreign key.
    $auditFkFailure = null;
    try {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            ['id' => 4242, 'rol' => 'GENEL_YONETICI'],
            100,
            $movePayload,
            auditContextFor(4242, 'ghost-actor')
        );
    } catch (\Throwable $e) {
        $auditFkFailure = $e;
    }
    auditAssert($auditFkFailure !== null, 'an unwritable audit row aborts the move');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT sube_id FROM personeller WHERE id = 100') === 1,
        'audit failure rolled the branch change back'
    );

    $result = PersonelKaliciSubeDegisikligiService::apply(
        $pdo,
        $gm,
        100,
        $movePayload,
        auditContextFor(1, 'move-1')
    );
    auditAssert($result['yeni_sube_id'] === 2 && $result['onceki_sube_id'] === 1, 'authorised move succeeds');

    $after = auditRow($pdo, 'SELECT sube_id, sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = 100');
    auditAssert((int) $after['sube_id'] === 2, 'sube_id moved to the target branch');
    auditAssert((int) $after['sgk_isveren_id'] === 1, 'sgk_isveren_id preserved');
    auditAssert((int) $after['calisma_lokasyonu_id'] === 9, 'calisma_lokasyonu_id preserved');

    $audit = auditRow($pdo, 'SELECT * FROM personel_sube_degisiklik_auditleri ORDER BY id DESC LIMIT 1');
    auditAssert((int) $audit['personel_id'] === 100, 'audit names the person');
    auditAssert((int) $audit['onceki_sube_id'] === 1, 'audit records the exact before branch');
    auditAssert((int) $audit['yeni_sube_id'] === 2, 'audit records the exact after branch');
    auditAssert((int) $audit['korunan_calisma_lokasyonu_id'] === 9, 'audit records the preserved work location');
    auditAssert((int) $audit['korunan_sgk_isveren_id'] === 1, 'audit records the preserved SGK employer');
    auditAssert((int) $audit['actor_user_id'] === 1, 'audit records the actor');
    auditAssert($audit['gerekce'] === $movePayload['gerekce'], 'audit records the justification');
    auditAssert(preg_match('/^[0-9a-f]{64}$/', (string) $audit['request_hash']) === 1, 'audit records a request hash');
    foreach (['ad_soyad', 'tc_kimlik_no', 'telefon'] as $pii) {
        auditAssert(!array_key_exists($pii, $audit), 'audit carries no ' . $pii);
    }

    // A replay of the same request is refused by the pre-image, not applied twice.
    $replay = auditFailure(function () use ($pdo, $gm, $movePayload) {
        PersonelKaliciSubeDegisikligiService::apply(
            $pdo,
            $gm,
            100,
            $movePayload,
            auditContextFor(1, 'move-1')
        );
    });
    auditAssert(
        $replay !== null && $replay['code'] === PersonelKaliciSubeDegisikligiService::ERROR_STALE,
        'a retried move is refused as stale rather than reapplied'
    );
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM personel_sube_degisiklik_auditleri') === 1,
        'a retry produced no duplicate audit row'
    );

    // ---------------------------------------------------- audit immutability
    $updateBlocked = false;
    try {
        $pdo->exec('UPDATE personel_sube_degisiklik_auditleri SET yeni_sube_id = 1 WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $e) {
        $updateBlocked = true;
    }
    auditAssert($updateBlocked, 'personnel branch audit rows cannot be updated');

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM personel_sube_degisiklik_auditleri WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $e) {
        $deleteBlocked = true;
    }
    auditAssert($deleteBlocked, 'personnel branch audit rows cannot be deleted');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM personel_sube_degisiklik_auditleri') === 1,
        'the audit row survived both attempts'
    );

    // =====================================================================
    // B) Branch creation audit
    // =====================================================================
    $created = OrganizasyonService::createSube(
        $pdo,
        ['kod' => 'MDS-SAK', 'ad' => 'Sakarya', 'durum' => 'AKTIF', 'sgk_isveren_id' => 1, 'departman_ids' => [2, 1]],
        1,
        auditContextFor(1, 'create-1')
    );
    $createAudit = auditRow($pdo, 'SELECT * FROM sube_olusturma_auditleri ORDER BY id DESC LIMIT 1');
    auditAssert((int) $createAudit['sube_id'] === (int) $created['id'], 'branch create audit names the branch');
    auditAssert((int) $createAudit['sirket_id'] === 1, 'branch create audit records the company');
    auditAssert($createAudit['kod'] === 'MDS-SAK', 'branch create audit records the code');
    auditAssert($createAudit['ad'] === 'Sakarya', 'branch create audit records the short name');
    auditAssert($createAudit['durum'] === 'AKTIF', 'branch create audit records the status');
    auditAssert((int) $createAudit['sgk_isveren_id'] === 1, 'branch create audit records the SGK employer');
    auditAssert($createAudit['departman_ids'] === '1,2', 'branch create audit records a canonical department list');
    auditAssert(
        $createAudit['departman_ids_hash'] === hash('sha256', '1,2'),
        'branch create audit records the department hash'
    );
    auditAssert(!array_key_exists('tam_ad', $createAudit), 'branch create audit stores no derived display name');
    auditAssert($created['tam_ad'] === 'Medisa Sakarya', 'the derived display name is unchanged by auditing');

    $duplicateKod = auditFailure(function () use ($pdo) {
        OrganizasyonService::createSube(
            $pdo,
            ['kod' => 'MDS-SAK', 'ad' => 'Baska', 'durum' => 'AKTIF', 'departman_ids' => []],
            1,
            auditContextFor(1, 'create-dup')
        );
    });
    auditAssert($duplicateKod !== null && $duplicateKod['status'] === 409, 'a duplicate branch code still conflicts');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM sube_olusturma_auditleri') === 1,
        'a rejected branch create left no audit row'
    );

    $subeCountBefore = (int) auditScalar($pdo, 'SELECT COUNT(*) FROM subeler');
    $mapCountBefore = (int) auditScalar($pdo, 'SELECT COUNT(*) FROM sube_departmanlar');
    $createAuditFailure = null;
    try {
        OrganizasyonService::createSube(
            $pdo,
            ['kod' => 'MDS-GHOST', 'ad' => 'Hayalet', 'durum' => 'AKTIF', 'departman_ids' => [1]],
            1,
            auditContextFor(4242, 'create-ghost')
        );
    } catch (\Throwable $e) {
        $createAuditFailure = $e;
    }
    auditAssert($createAuditFailure !== null, 'an unwritable audit row aborts branch creation');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM subeler') === $subeCountBefore,
        'audit failure rolled the branch insert back'
    );
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM sube_departmanlar') === $mapCountBefore,
        'audit failure rolled the department writes back'
    );

    // The legacy flat route reaches the same owner with a null company.
    $flat = OrganizasyonService::createSube(
        $pdo,
        ['kod' => 'FLAT-1', 'ad' => 'Duz Kayit', 'durum' => 'AKTIF', 'departman_ids' => []],
        null,
        auditContextFor(2, 'create-flat')
    );
    $flatAudit = auditRow(
        $pdo,
        'SELECT * FROM sube_olusturma_auditleri WHERE sube_id = :id',
        ['id' => (int) $flat['id']]
    );
    auditAssert($flatAudit !== null, 'the legacy flat branch route audits through the same owner');
    auditAssert($flatAudit['sirket_id'] === null, 'an unmapped branch audits a null company');
    auditAssert((int) $flatAudit['actor_user_id'] === 2, 'the flat route records its own actor');

    $soaUpdateBlocked = false;
    try {
        $pdo->exec('UPDATE sube_olusturma_auditleri SET ad = \'Degistirildi\' WHERE id = ' . (int) $flatAudit['id']);
    } catch (\Throwable $e) {
        $soaUpdateBlocked = true;
    }
    auditAssert($soaUpdateBlocked, 'branch create audit rows cannot be updated');

    // =====================================================================
    // C) User organisation scope audit
    // =====================================================================
    $scopeContext = auditContextFor(1, 'scope-1');
    $pdo->beginTransaction();
    $scopeAuditId = OrganizasyonAuditWriter::recordUserOrgScopeChange(
        $pdo,
        3,
        OrganizasyonAuditWriter::SCOPE_SUBE,
        [2, 1],
        [1, 2, 4],
        $scopeContext,
        'Sube yetkisi genisletildi.'
    );
    $pdo->commit();
    auditAssert(is_int($scopeAuditId), 'a changed branch scope produces an audit row');

    $scopeAudit = auditRow($pdo, 'SELECT * FROM user_org_scope_auditleri WHERE id = :id', ['id' => $scopeAuditId]);
    auditAssert((int) $scopeAudit['target_user_id'] === 3, 'scope audit names the target user');
    auditAssert($scopeAudit['scope_turu'] === 'SUBE', 'scope audit names the axis');
    auditAssert($scopeAudit['onceki_ids'] === '1,2', 'scope audit records the exact sorted before set');
    auditAssert($scopeAudit['yeni_ids'] === '1,2,4', 'scope audit records the exact sorted after set');
    auditAssert((int) $scopeAudit['actor_user_id'] === 1, 'scope audit records the actor');

    $unchanged = OrganizasyonAuditWriter::recordUserOrgScopeChange(
        $pdo,
        3,
        OrganizasyonAuditWriter::SCOPE_SUBE,
        [1, 2, 4],
        [4, 2, 1],
        $scopeContext
    );
    auditAssert($unchanged === null, 'a resent identical scope set produces no audit row');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM user_org_scope_auditleri') === 1,
        'the unchanged request added no row'
    );

    $emptyScopeId = null;
    $pdo->beginTransaction();
    $emptyScopeId = OrganizasyonAuditWriter::recordUserOrgScopeChange(
        $pdo,
        3,
        OrganizasyonAuditWriter::SCOPE_SIRKET,
        [],
        [1],
        $scopeContext
    );
    $pdo->commit();
    $emptyScopeAudit = auditRow($pdo, 'SELECT * FROM user_org_scope_auditleri WHERE id = :id', ['id' => $emptyScopeId]);
    auditAssert($emptyScopeAudit['onceki_ids'] === '', 'an empty before set is recorded as an empty list');
    auditAssert($emptyScopeAudit['yeni_ids'] === '1', 'granting the first company is recorded');

    // A failing scope audit must take the scope mutation with it.
    $scopeRollbackFailed = false;
    $pdo->beginTransaction();
    try {
        $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (3, 4)');
        OrganizasyonAuditWriter::recordUserOrgScopeChange(
            $pdo,
            3,
            OrganizasyonAuditWriter::SCOPE_SUBE,
            [1, 2],
            [1, 2, 4],
            auditContextFor(4242, 'scope-ghost')
        );
        $pdo->commit();
    } catch (\Throwable $e) {
        $scopeRollbackFailed = true;
        $pdo->rollBack();
    }
    auditAssert($scopeRollbackFailed, 'an unwritable scope audit aborts the transaction');
    auditAssert(
        (int) auditScalar($pdo, 'SELECT COUNT(*) FROM user_subeler WHERE user_id = 3') === 0,
        'audit failure rolled the scope mutation back'
    );

    $uosaDeleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM user_org_scope_auditleri WHERE id = ' . (int) $scopeAuditId);
    } catch (\Throwable $e) {
        $uosaDeleteBlocked = true;
    }
    auditAssert($uosaDeleteBlocked, 'scope audit rows cannot be deleted');

    echo "verify-organizasyon-audit-owners-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
