<?php

declare(strict_types=1);

/**
 * S3E-R1: MariaDB period context + candidate revision semantics integration.
 * php tests/php/S3EQrPuantajPeriodContextMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\Qr\QrPuantajCandidateReadService;

function s3eR1Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3eR1RootPdo(): PDO
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
function s3eR1SplitSql(string $sql): array
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

function s3eR1Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s3eR1SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s3eR1PdoForDb(string $database): PDO
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

function s3eR1Schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            ad VARCHAR(64) NOT NULL,
            soyad VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE puantaj_aylik_muhurleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            revision_no INT NOT NULL DEFAULT 1,
            donem VARCHAR(16) NULL,
            durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
            muhurlenen_kayit_sayisi INT NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NULL,
            parent_muhur_id INT UNSIGNED NULL,
            superseded_by_id INT UNSIGNED NULL,
            source_hash VARCHAR(128) NULL,
            reopen_talep_id INT UNSIGNED NULL,
            UNIQUE KEY uq_muhur_rev (sube_id, yil, ay, revision_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE puantaj_donem_reopen_talepleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            kaynak_muhur_id INT UNSIGNED NOT NULL,
            talep_durumu VARCHAR(32) NOT NULL,
            gerekce TEXT NOT NULL,
            requested_by INT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            rejected_by INT UNSIGNED NULL,
            rejected_at DATETIME NULL,
            rejection_reason TEXT NULL,
            applied_at DATETIME NULL,
            reseal_muhur_id INT UNSIGNED NULL,
            request_hash VARCHAR(128) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE maas_hesaplama_donem_snapshotlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil INT NOT NULL,
            ay INT NOT NULL,
            muhur_id INT UNSIGNED NOT NULL,
            revision_no INT NOT NULL DEFAULT 1,
            state VARCHAR(32) NOT NULL DEFAULT 'OLUSTURULDU',
            source_hash VARCHAR(128) NULL,
            snapshot_hash VARCHAR(128) NULL,
            created_at DATETIME NULL,
            iptal_edildi_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE gunluk_puantaj (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            personel_id INT UNSIGNED NOT NULL,
            tarih DATE NOT NULL,
            state VARCHAR(32) NULL,
            giris_saati VARCHAR(16) NULL,
            cikis_saati VARCHAR(16) NULL,
            kontrol_durumu VARCHAR(32) NULL,
            UNIQUE KEY uq_personel_tarih (personel_id, tarih)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez')");
    $pdo->exec("INSERT INTO users (id, username) VALUES (10, 'u10')");
    $pdo->exec("INSERT INTO personeller (id, sube_id, ad, soyad) VALUES (1, 1, 'A', 'B')");
}

function s3eR1SeedSeal(PDO $pdo, int $muhurId = 1): void
{
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (id, sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, source_hash)
         VALUES
            ({$muhurId}, 1, 2026, 8, 1, '2026-08', 'MUHURLENDI', 1, 10, 'hash-seal')"
    );
}

function s3eR1SeedReopenApproved(PDO $pdo, int $muhurId = 1, int $talepId = 1): void
{
    s3eR1SeedSeal($pdo, $muhurId);
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri
            (id, sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce, requested_by, requested_at, request_hash)
         VALUES
            ({$talepId}, 1, 2026, 8, {$muhurId}, 'ONAYLANDI', 'test reopen', 10, '2026-08-15 10:00:00', 'req-hash-1')"
    );
}

function s3eR1SeedActiveSnapshot(PDO $pdo, int $muhurId = 1, int $snapshotId = 1): void
{
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari
            (id, sube_id, yil, ay, muhur_id, revision_no, state, source_hash, snapshot_hash, created_at)
         VALUES
            ({$snapshotId}, 1, 2026, 8, {$muhurId}, 1, 'OLUSTURULDU', 'src', 'snap', '2026-08-15 11:00:00')"
    );
}

function s3eR1SeedQrPair(PDO $pdo, string $girisUtc, string $cikisUtc, int $baseId = 1): void
{
    $ins = $pdo->prepare(
        'INSERT INTO qr_attendance_events
            (personel_id, user_id, sube_id, event_type, occurred_at_utc,
             qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES
            (:personel_id, 10, 1, :event_type, :occurred_at_utc,
             1, :jti, :occurred_at_utc, :occurred_at_utc, :nonce)'
    );
    $nonce = static function (): string {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x1000, 0x1fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        );
    };
    $ins->execute([
        'personel_id' => 1,
        'event_type' => 'GIRIS',
        'occurred_at_utc' => $girisUtc,
        'jti' => str_repeat('a', 32),
        'nonce' => $nonce(),
    ]);
    $ins->execute([
        'personel_id' => 1,
        'event_type' => 'CIKIS',
        'occurred_at_utc' => $cikisUtc,
        'jti' => str_repeat('b', 32),
        'nonce' => $nonce(),
    ]);
}

/** @return array<string,mixed>|null */
function s3eR1CandidateItem(PDO $pdo, string $date, ?array $canonical = null)
{
    if ($canonical !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO gunluk_puantaj (personel_id, tarih, state, giris_saati, cikis_saati, kontrol_durumu)
             VALUES (1, :tarih, :state, :giris, :cikis, :kontrol)
             ON DUPLICATE KEY UPDATE giris_saati = VALUES(giris_saati), cikis_saati = VALUES(cikis_saati), state = VALUES(state)'
        );
        $stmt->execute([
            'tarih' => $date,
            'state' => $canonical['state'] ?? 'ACIK',
            'giris' => $canonical['giris_saati'],
            'cikis' => $canonical['cikis_saati'],
            'kontrol' => $canonical['kontrol_durumu'] ?? 'BEKLIYOR',
        ]);
    }

    $payload = QrPuantajCandidateReadService::listForPersonel($pdo, 1, 1, $date, $date);
    foreach ($payload['items'] as $item) {
        if (($item['candidate_date'] ?? '') === $date) {
            return $item;
        }
    }

    return null;
}

$root = s3eR1RootPdo();
$db = 's3e_period_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = s3eR1PdoForDb($db);
$date = '2026-08-12';

try {
    s3eR1Schema($pdo);
    s3eR1Apply($pdo, '057_qr_attendance_events.sql');

    // Owner parity: ACIK
    $ctxAcik = PuantajDonemPeriodService::resolveCanonicalWriteContext($pdo, 1, 2026, 8);
    s3eR1Assert($ctxAcik['state'] === PuantajDonemPeriodService::STATE_ACIK, 'ACIK state');
    s3eR1Assert($ctxAcik['period_write_locked'] === false, 'ACIK period_write_locked NO');
    s3eR1Assert($ctxAcik['canonical_write_open'] === true, 'ACIK canonical_write_open YES');
    s3eR1Assert($ctxAcik['canonical_write_block_code'] === null, 'ACIK block code null');

    // SEALED owner context
    s3eR1SeedSeal($pdo);
    $ctxSealed = PuantajDonemPeriodService::resolveCanonicalWriteContext($pdo, 1, 2026, 8);
    s3eR1Assert($ctxSealed['state'] === PuantajDonemPeriodService::STATE_SEALED, 'SEALED state');
    s3eR1Assert($ctxSealed['period_write_locked'] === true, 'SEALED period_write_locked YES');
    s3eR1Assert($ctxSealed['canonical_write_open'] === false, 'SEALED canonical_write_open NO');
    s3eR1Assert($ctxSealed['canonical_write_block_code'] === 'PERIOD_LOCKED', 'SEALED block PERIOD_LOCKED');

    // REOPEN_PENDING
    $pdo->exec('DELETE FROM puantaj_aylik_muhurleri');
    $pdo->exec('DELETE FROM puantaj_donem_reopen_talepleri');
    s3eR1SeedSeal($pdo);
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri
            (id, sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce, requested_by, requested_at, request_hash)
         VALUES (2, 1, 2026, 8, 1, 'ONAY_BEKLIYOR', 'pending', 10, '2026-08-15 10:00:00', 'req-hash-2')"
    );
    $ctxPending = PuantajDonemPeriodService::resolveCanonicalWriteContext($pdo, 1, 2026, 8);
    s3eR1Assert($ctxPending['state'] === PuantajDonemPeriodService::STATE_REOPEN_PENDING, 'REOPEN_PENDING state');
    s3eR1Assert($ctxPending['period_write_locked'] === true, 'REOPEN_PENDING period_write_locked YES');

    // REOPENED no snapshot
    $pdo->exec('DELETE FROM puantaj_donem_reopen_talepleri');
    s3eR1SeedReopenApproved($pdo);
    $ctxReopened = PuantajDonemPeriodService::resolveCanonicalWriteContext($pdo, 1, 2026, 8);
    s3eR1Assert($ctxReopened['state'] === PuantajDonemPeriodService::STATE_REOPENED, 'REOPENED state');
    s3eR1Assert($ctxReopened['period_write_locked'] === false, 'REOPENED period_write_locked NO');
    s3eR1Assert($ctxReopened['canonical_write_open'] === true, 'REOPENED no snapshot canonical_write_open YES');

    // REOPENED active snapshot
    s3eR1SeedActiveSnapshot($pdo);
    $ctxReopenedSnap = PuantajDonemPeriodService::resolveCanonicalWriteContext($pdo, 1, 2026, 8);
    s3eR1Assert($ctxReopenedSnap['canonical_write_open'] === false, 'REOPENED active snapshot canonical_write_open NO');
    s3eR1Assert(
        $ctxReopenedSnap['canonical_write_block_code'] === 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
        'REOPENED active snapshot block code'
    );

    // Candidate integration — reset to SEALED only
    $pdo->exec('DELETE FROM maas_hesaplama_donem_snapshotlari');
    $pdo->exec('DELETE FROM puantaj_donem_reopen_talepleri');
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedSeal($pdo);
    s3eR1SeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    $sealedMatch = s3eR1CandidateItem($pdo, $date, [
        'giris_saati' => '08:00',
        'cikis_saati' => '17:00',
        'state' => 'MUHURLENDI',
    ]);
    s3eR1Assert($sealedMatch !== null, 'SEALED match candidate exists');
    s3eR1Assert(
        ($sealedMatch['comparison_status'] ?? '') === 'MATCHES_CANONICAL_TIME',
        'MariaDB SEALED match comparison'
    );
    s3eR1Assert(($sealedMatch['period']['revision_required'] ?? true) === false, 'MariaDB SEALED match revision_required NO');

    // SEALED diff
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedQrPair($pdo, '2026-08-12 05:07:00.000000', '2026-08-12 14:02:00.000000');
    $sealedDiff = s3eR1CandidateItem($pdo, $date, [
        'giris_saati' => '08:00',
        'cikis_saati' => '17:00',
        'state' => 'MUHURLENDI',
    ]);
    s3eR1Assert(
        ($sealedDiff['comparison_status'] ?? '') === 'PERIOD_REQUIRES_REVISION',
        'MariaDB SEALED diff comparison'
    );
    s3eR1Assert(($sealedDiff['period']['revision_required'] ?? false) === true, 'MariaDB SEALED diff revision_required YES');

    // SEALED no row
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 14:00:00.000000');
    $sealedNoRow = s3eR1CandidateItem($pdo, $date);
    s3eR1Assert(
        ($sealedNoRow['comparison_status'] ?? '') === 'PERIOD_REQUIRES_REVISION',
        'MariaDB SEALED no row comparison'
    );
    s3eR1Assert(($sealedNoRow['period']['revision_required'] ?? false) === true, 'MariaDB SEALED no row revision_required YES');

    // SEALED multiple intervals
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedQrPair($pdo, '2026-08-12 05:00:00.000000', '2026-08-12 09:00:00.000000');
    s3eR1SeedQrPair($pdo, '2026-08-12 10:00:00.000000', '2026-08-12 14:00:00.000000', 3);
    $sealedMulti = s3eR1CandidateItem($pdo, $date);
    s3eR1Assert(
        ($sealedMulti['classification'] ?? '') === 'REVIEW_MULTIPLE_INTERVALS',
        'MariaDB SEALED multi classification'
    );
    s3eR1Assert(($sealedMulti['period']['revision_required'] ?? true) === false, 'MariaDB SEALED multi revision_required NO');

    // REOPENED no snapshot diff
    $pdo->exec('DELETE FROM puantaj_aylik_muhurleri');
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedReopenApproved($pdo);
    s3eR1SeedQrPair($pdo, '2026-08-12 05:07:00.000000', '2026-08-12 14:02:00.000000');
    $reopenedDiff = s3eR1CandidateItem($pdo, $date, [
        'giris_saati' => '08:00',
        'cikis_saati' => '17:00',
        'state' => 'ACIK',
    ]);
    s3eR1Assert(
        ($reopenedDiff['comparison_status'] ?? '') === 'DIFFERS_CANONICAL_TIME',
        'MariaDB REOPENED no snapshot diff comparison'
    );
    s3eR1Assert(($reopenedDiff['period']['revision_required'] ?? true) === false, 'MariaDB REOPENED no snapshot revision_required NO');
    s3eR1Assert(
        ($reopenedDiff['period']['future_action'] ?? null) === 'DIRECT_PUANTAJ_REVIEW',
        'MariaDB REOPENED no snapshot future_action'
    );

    // REOPENED active snapshot diff
    s3eR1SeedActiveSnapshot($pdo);
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM qr_attendance_events');
    s3eR1SeedQrPair($pdo, '2026-08-12 05:07:00.000000', '2026-08-12 14:02:00.000000');
    $reopenedSnapDiff = s3eR1CandidateItem($pdo, $date, [
        'giris_saati' => '08:00',
        'cikis_saati' => '17:00',
        'state' => 'ACIK',
    ]);
    s3eR1Assert(
        ($reopenedSnapDiff['period']['canonical_write_block_code'] ?? '') === 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
        'MariaDB REOPENED active snapshot block code'
    );
    s3eR1Assert(($reopenedSnapDiff['period']['revision_required'] ?? true) === false, 'MariaDB REOPENED active snapshot revision_required NO');
    s3eR1Assert(($reopenedSnapDiff['period']['future_action'] ?? 'x') === null, 'MariaDB REOPENED active snapshot future_action null');

    echo '[OK] S3EQrPuantajPeriodContextMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
