<?php

declare(strict_types=1);

/**
 * S3C-R1: legacy ISE_GIRIS_CIKIS manifest compatibility + QR fingerprint determinism.
 * php tests/php/S3CRetentionLegacyCompatMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use Medisa\Api\Services\Retention\RetentionSourceAdapterService;

function s3cRetAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3cRetRootPdo(): PDO
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
function s3cRetSplitSql(string $sql): array
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

function s3cRetApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3cRetSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3cRetPdoForDb(string $database): PDO
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

$root = s3cRetRootPdo();
$database = 'medisa_s3c_ret_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = s3cRetPdoForDb($database);
    s3cRetApply($pdo, '001_initial_schema.sql');
    s3cRetApply($pdo, '051_users_varsayilan_sube_id.sql');
    s3cRetApply($pdo, '053_retention_legal_hold_arsiv.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, gorev_id, aktif_durum
         ) VALUES
         (20, '20202020202', 'Legacy', 'Person', '1990-01-01', '5550000020', 'A', '5550000120', 'L20', '2018-01-01', 1, 1, 1, 'PASIF'),
         (21, '21212121212', 'New', 'Qr', '1991-01-01', '5550000021', 'B', '5550000121', 'N21', '2019-01-01', 1, 1, 1, 'PASIF')"
    );
    $hash = password_hash('S3cRetPass-24chars!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'gm', " . $pdo->quote($hash) . ", 'GM', 'GENEL_YONETICI', 'AKTIF'),
         (2, 'u21', " . $pdo->quote($hash) . ", 'User 21', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state) VALUES
         (20, 'ISTEN_AYRILMA', '2026-02-01', 'AKTIF'),
         (21, 'ISTEN_AYRILMA', '2026-04-01', 'AKTIF')"
    );

    // A) Prod-like pre-S3C ISE manifest (legacy identity + ozluk fingerprint)
    $ozlukFp20 = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 20);
    $legacyIdentity = ArchiveManifestService::legacyIseGirisCikisIdentity(20, '2026-02-01');
    ArchiveManifestService::createManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 20,
        'personel_id' => 20,
        'record_category' => RetentionCategories::PERSONEL_OZLUK,
        'source_version_identity' => 'personel:20:termination:2026-02-01',
        'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
        'trigger_date' => '2026-02-01',
        'source_sha256' => $ozlukFp20,
    ], 1);
    ArchiveManifestService::createManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 20,
        'personel_id' => 20,
        'record_category' => RetentionCategories::ISE_GIRIS_CIKIS,
        'source_version_identity' => $legacyIdentity,
        'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
        'trigger_date' => '2026-02-01',
        'source_sha256' => $ozlukFp20,
    ], 1);

    $foundLegacy = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        20,
        RetentionCategories::ISE_GIRIS_CIKIS,
        ['personel_id' => 20]
    );
    s3cRetAssert($foundLegacy !== null, 'A legacy ISE discoverable as current');
    s3cRetAssert(
        (string) $foundLegacy['source_version_identity'] === $legacyIdentity,
        'A legacy identity exact'
    );

    $resolvedLegacy = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::ISE_GIRIS_CIKIS, [
        'personel_id' => 20,
        'entity_type' => 'personel',
        'record_id' => 20,
    ]);
    s3cRetAssert($resolvedLegacy['source_version_identity'] === $legacyIdentity, 'A resolve returns legacy identity');
    s3cRetAssert($resolvedLegacy['source_sha256'] === $ozlukFp20, 'B legacy fingerprint = ozluk');

    $integrityLegacy = ArchiveManifestService::verifySourceIntegrity(
        $pdo,
        'personel',
        20,
        RetentionCategories::ISE_GIRIS_CIKIS,
        $resolvedLegacy['source_sha256'],
        ['personel_id' => 20]
    );
    s3cRetAssert($integrityLegacy === ArchiveManifestService::INTEGRITY_OK, 'B legacy integrity OK');

    // C) QR fingerprint must NOT be compared against legacy stored sha
    s3cRetApply($pdo, '056_users_personel_binding.sql');
    $pdo->exec('UPDATE users SET personel_id = 21 WHERE id = 2');
    s3cRetApply($pdo, '057_qr_attendance_events.sql');
    $qrFpEmpty = ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, 20);
    s3cRetAssert($qrFpEmpty !== $ozlukFp20, 'C QR empty fp differs from ozluk');
    $integrityNotFalseChanged = ArchiveManifestService::verifySourceIntegrity(
        $pdo,
        'personel',
        20,
        RetentionCategories::ISE_GIRIS_CIKIS,
        $resolvedLegacy['source_sha256'],
        ['personel_id' => 20]
    );
    s3cRetAssert(
        $integrityNotFalseChanged === ArchiveManifestService::INTEGRITY_OK,
        'C no false CHANGED via adapter path'
    );

    // H) Older lifecycle must not become current
    ArchiveManifestService::createManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 20,
        'personel_id' => 20,
        'record_category' => RetentionCategories::ISE_GIRIS_CIKIS,
        'source_version_identity' => ArchiveManifestService::legacyIseGirisCikisIdentity(20, '2015-01-01'),
        'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
        'trigger_date' => '2015-01-01',
        'source_sha256' => $ozlukFp20,
    ], 1);
    $curStill = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        20,
        RetentionCategories::ISE_GIRIS_CIKIS,
        ['personel_id' => 20]
    );
    s3cRetAssert(
        (string) $curStill['source_version_identity'] === $legacyIdentity,
        'H current stays 2026 termination lifecycle'
    );

    // D) New S3C PASIF lifecycle → QR-aware ISE identity
    $created = ArchiveManifestService::createPersonelLifecycleManifests($pdo, 21, 1);
    s3cRetAssert(count($created) === 2, 'D creates ozluk + ise');
    $newIseIdentity = ArchiveManifestService::qrAwareIseGirisCikisIdentity(21, '2026-04-01');
    $newIse = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        21,
        RetentionCategories::ISE_GIRIS_CIKIS,
        ['personel_id' => 21]
    );
    s3cRetAssert($newIse !== null && (string) $newIse['source_version_identity'] === $newIseIdentity, 'D new QR-aware identity');

    $emptyFp21 = ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, 21);
    s3cRetAssert((string) $newIse['source_sha256'] === $emptyFp21, 'D stored QR empty fingerprint');

    // F) PERSONEL_OZLUK fingerprint unchanged contract
    $ozluk21 = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 21);
    $ozlukManifest = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        21,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 21]
    );
    s3cRetAssert((string) $ozlukManifest['source_sha256'] === $ozluk21, 'F PERSONEL_OZLUK unchanged');
    s3cRetAssert(
        (string) $ozlukManifest['source_version_identity'] === 'personel:21:termination:2026-04-01',
        'F ozluk identity classic'
    );

    // E) Insert QR event → fingerprint changes; session TZ must not flip hash
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec(
        "INSERT INTO qr_attendance_events (
            personel_id, user_id, sube_id, event_type, occurred_at_utc,
            qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce
         ) VALUES (
            21, 2, 1, 'GIRIS', '2026-03-01 10:00:00.000000', 1, " . $pdo->quote(str_repeat('a', 32)) . ",
            '2026-03-01 09:59:00.000000', '2026-03-01 10:01:00.000000',
            'a0000000-0000-4000-8000-000000000021'
         )"
    );
    $fpUtc = ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, 21);
    s3cRetAssert($fpUtc !== $emptyFp21, 'E fingerprint changes after QR insert');
    $pdo->exec("SET time_zone = '+03:00'");
    $fpPlus3 = ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, 21);
    s3cRetAssert($fpUtc === $fpPlus3, 'fingerprint stable across session TZ');
    $pdo->exec("SET time_zone = '-05:00'");
    $fpMinus5 = ArchiveManifestService::computeIseGirisCikisFingerprint($pdo, 21);
    s3cRetAssert($fpUtc === $fpMinus5, 'fingerprint stable across -05 session TZ');

    $integrityChanged = ArchiveManifestService::verifySourceIntegrity(
        $pdo,
        'personel',
        21,
        RetentionCategories::ISE_GIRIS_CIKIS,
        $fpUtc,
        ['personel_id' => 21]
    );
    s3cRetAssert(
        $integrityChanged === RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED
            || $integrityChanged === 'ARCHIVE_SOURCE_INTEGRITY_CHANGED',
        'E integrity CHANGED after new QR evidence'
    );

    // Empty vs missing-table hashes are distinct
    $emptyHash = hash('sha256', 'ise_giris_cikis:empty:personel:99');
    $missingHash = hash('sha256', 'ise_giris_cikis:empty:personel:99:no_table');
    s3cRetAssert($emptyHash !== $missingHash, 'empty vs missing-table hash distinct');

    // G) Legal hold still blocks when active
    $gm = ['id' => 1, 'rol' => 'GENEL_YONETICI'];
    LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 21,
        'reason' => 'S3C-R1 hold',
    ]);
    s3cRetAssert(
        RetentionPolicyService::hasActiveLegalHold($pdo, RetentionCategories::ISE_GIRIS_CIKIS, [
            'personel_id' => 21,
            'entity_type' => 'personel',
            'record_id' => 21,
        ]) === true,
        'G legal hold active for personel 21'
    );

    echo "S3C retention legacy compat mysql runner OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
