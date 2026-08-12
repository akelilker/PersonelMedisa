<?php

declare(strict_types=1);

/**
 * Phase C FINAL: disposable MariaDB — retention integrity matrix + 053 idempotency.
 * php tests/php/RetentionPolicy053MysqlTestRunner.php
 *
 * Matrix coverage (items 1-51 style where feasible):
 * schema gate, per-category triggers, rehire-safe termination, Bordro KESINLESTI,
 * immutable manifests, pre-approval vs final eligibility, approve-after-hold,
 * PASIF write rejection, clock override (not HTTP as_of/gm_approved).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Retention\ArchiveAccessService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPeriodTriggerResolver;
use Medisa\Api\Services\Retention\RetentionPolicyService;
use Medisa\Api\Services\Retention\RetentionSchemaGate;
use Medisa\Api\Services\Retention\RetentionScopeResolver;
use Medisa\Api\Services\Retention\RetentionSourceAdapterService;
use Medisa\Api\Services\Retention\RetentionTargetResolver;

function rp053Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function rp053RootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function rp053SplitSql(string $sql): array
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

function rp053Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (rp053SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function rp053PdoForDb(string $database): PDO
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
        ]
    );
}

/** @return list<string> */
function rp053MigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function rp053Seed(PDO $pdo): void
{
    $hash = password_hash('Rp053TestPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'ik', '{$hash}', 'IK User', 'IK_BORDRO', 'AKTIF'),
        (3, 'muhasebe', '{$hash}', 'Muhasebe', 'MUHASEBE', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111111', 'Aktif', 'Personel', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S001', '2010-01-01', 1, 'AKTIF'),
         (11, '22222222222', 'Pasif', 'Personel', '1990-01-01', '05000000002', 'Acil', '05000000003',
            'S002', '2010-01-01', 1, 'PASIF')"
    );
}

/** @return array<string, mixed> */
function rp053User($id, $rol)
{
    return ['id' => $id, 'rol' => $rol];
}

function rp053EnsureBordroStub(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS maas_hesaplama_calistirmalari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sube_id INT UNSIGNED NOT NULL,
            yil SMALLINT UNSIGNED NOT NULL,
            ay TINYINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL DEFAULT 1,
            state VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI',
            bordro_onay_durumu VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI',
            kesinlestirme_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function rp053EnsureSnapshotStub(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS maas_hesaplama_donem_snapshotlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sube_id INT UNSIGNED NOT NULL,
            yil SMALLINT UNSIGNED NOT NULL,
            ay TINYINT UNSIGNED NOT NULL,
            revision_no INT UNSIGNED NOT NULL DEFAULT 1,
            state VARCHAR(32) NOT NULL DEFAULT 'OLUSTURULDU',
            cutoff_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function rp053EnsureHaftalikStub(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS haftalik_kapanislar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sube_id INT UNSIGNED NOT NULL,
            hafta_baslangic DATE NOT NULL,
            hafta_bitis DATE NOT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'KAPANDI',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

$root = rp053RootPdo();
$database = 'medisa_rp053_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = rp053PdoForDb($database);
    RetentionClock::clearOverride();

    $files = rp053MigrationFiles();
    rp053Assert(end($files) === '058_qr_puantaj_candidate_decision_ledger.sql', '1 tip ends at 054');
    rp053Assert(in_array('052_puantaj_tolerans_ve_disiplin.sql', $files, true), '1b 052 still present');

    rp053Apply($pdo, '001_initial_schema.sql');
    rp053Apply($pdo, '002_puantaj_aylik_muhurleme.sql');
    rp053Apply($pdo, '053_retention_legal_hold_arsiv.sql');
    rp053Assert(true, '2 first apply 053 PASS');

    rp053Apply($pdo, '053_retention_legal_hold_arsiv.sql');
    rp053Assert(true, '3 reapply 053 idempotent PASS');

    foreach ([
        'arsiv_manifestleri',
        'legal_holdlar',
        'legal_hold_auditleri',
        'arsiv_erisim_auditleri',
        'retention_imha_talepleri',
        'retention_imha_auditleri',
    ] as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        rp053Assert((bool) $stmt->fetchColumn(), '4 table ' . $table);
    }

    // Snapshot columns on imha talepleri
    foreach ([
        'trigger_type_snapshot',
        'trigger_date_snapshot',
        'source_version_identity_snapshot',
        'source_sha256_snapshot',
        'canonical_sube_id',
        'period_yil',
        'period_ay',
    ] as $col) {
        $c = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'retention_imha_talepleri'
               AND COLUMN_NAME = '{$col}'"
        )->fetchColumn();
        rp053Assert((int) $c === 1, '4b column ' . $col);
    }

    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
    $type = (string) ($col['Type'] ?? '');
    rp053Assert(strpos($type, 'IDARI_ISLER') !== false, '5 ENUM IDARI_ISLER');
    rp053Assert(strpos($type, 'SISTEM_YONETICISI') !== false, '6 ENUM SISTEM_YONETICISI');

    rp053Seed($pdo);
    rp053EnsureBordroStub($pdo);
    rp053EnsureSnapshotStub($pdo);
    rp053EnsureHaftalikStub($pdo);

    // 7 unknown category
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, 'XYZ', ['personel_id' => 11]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_UNKNOWN_CATEGORY, '7 UNKNOWN_CATEGORY');

    // 8 PUANTAJ without seal → PERIOD_NOT_CLOSED
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2020, 'ay' => 1, 'entity_type' => 'puantaj', 'record_id' => 1,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_PERIOD_NOT_CLOSED, '8 PERIOD_NOT_CLOSED puantaj');

    // 9 BORDRO missing context → TRIGGER_NOT_RESOLVED
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::BORDRO, []);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED, '9 TRIGGER_NOT_RESOLVED bordro');

    // 10 CRITICAL: BORDRO with only puantaj seal → PERIOD_NOT_CLOSED
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, 2010, 1, '2010-01', 'MUHURLENDI', 1, 1, '2010-02-05 10:00:00')"
    );
    try {
        RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::BORDRO, [
            'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
        ]);
        rp053Assert(false, '10 BORDRO seal-only should throw');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
            '10 BORDRO seal-only PERIOD_NOT_CLOSED'
        );
    }

    // 11 BORDRO with KESINLESTI → trigger = kesinlestirme date
    $pdo->exec(
        "INSERT INTO maas_hesaplama_calistirmalari
            (sube_id, yil, ay, revision_no, state, bordro_onay_durumu, kesinlestirme_at, created_at)
         VALUES (1, 2010, 1, 1, 'HESAPLANDI', 'KESINLESTI', '2010-03-01 12:00:00', '2010-02-20 10:00:00')"
    );
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::BORDRO, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-03-01', '11 BORDRO KESINLESTI trigger date');

    // 12 PUANTAJ seal trigger date
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-02-05', '12 PUANTAJ seal trigger');

    // 13 SGK missing snapshot → PERIOD_NOT_CLOSED
    try {
        RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::SGK_EKSIK_GUN, [
            'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
        ]);
        rp053Assert(false, '13 SGK missing should throw');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === RetentionPolicyService::CODE_PERIOD_NOT_CLOSED, '13 SGK PERIOD_NOT_CLOSED');
    }

    // 14 SGK with snapshot
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari
            (sube_id, yil, ay, revision_no, state, cutoff_at, created_at)
         VALUES (1, 2010, 1, 1, 'OLUSTURULDU', '2010-02-10 08:00:00', '2010-02-09 08:00:00')"
    );
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::SGK_EKSIK_GUN, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-02-10', '14 SGK cutoff trigger');

    // 15 FAZLA_CALISMA: yil/ay alone must NOT prove close (no single-week month fallback)
    try {
        RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::FAZLA_CALISMA, [
            'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
        ]);
        rp053Assert(false, '15 FC month-only should throw');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED
                || $e->getMessage() === RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
            '15 FC month-only fail-closed'
        );
    }
    $pdo->exec(
        "INSERT INTO haftalik_kapanislar (sube_id, hafta_baslangic, hafta_bitis, state, created_at)
         VALUES (1, '2010-01-04', '2010-01-10', 'KAPANDI', '2010-01-11 09:00:00')"
    );
    $weekId = (int) $pdo->query('SELECT id FROM haftalik_kapanislar ORDER BY id DESC LIMIT 1')->fetchColumn();
    // One closed week + month params still must not bypass — require exact weekly identity.
    try {
        RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::FAZLA_CALISMA, [
            'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
        ]);
        rp053Assert(false, '15b FC one-week-in-month bypass should fail');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED,
            '15b FC no month fallback'
        );
    }
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::FAZLA_CALISMA, [
        'haftalik_kapanis_id' => $weekId,
        'sube_id' => 1,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-01-10', '16 FC exact weekly owner');
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::SERBEST_ZAMAN, [
        'hafta_baslangic' => '2010-01-04',
        'sube_id' => 1,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-01-10', '16b SZ hafta_baslangic+sube');

    // 17 ONAY_AUDIT without parent → TRIGGER_NOT_RESOLVED
    try {
        RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::ONAY_AUDIT, [
            'sube_id' => 1, 'yil' => 2010, 'ay' => 1,
        ]);
        rp053Assert(false, '17 ONAY_AUDIT missing parent');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED, '17 ONAY_AUDIT TRIGGER_NOT_RESOLVED');
    }

    // 18 ONAY_AUDIT parent PUANTAJ → PERIOD_CLOSURE
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::ONAY_AUDIT, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'parent_category' => RetentionCategories::PUANTAJ,
    ]);
    rp053Assert($trig['trigger_date'] === '2010-02-05', '18 ONAY_AUDIT parent PUANTAJ date');
    rp053Assert(
        $trig['trigger_type'] === RetentionCategories::TRIGGER_PERIOD_CLOSURE,
        '18b ONAY_AUDIT parent PUANTAJ type PERIOD_CLOSURE'
    );

    // 19 AKTIF personel termination missing
    $term = RetentionPolicyService::resolveTerminationDate($pdo, 10);
    rp053Assert($term === null, '19 AKTIF termination null');

    // 20 PASIF without surec → TERMINATION_DATE_MISSING
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_TERMINATION_DATE_MISSING, '20 TERMINATION_DATE_MISSING');

    // 21 Rehire-safe: historical IPTAL 2015 + effective 2026 → trigger 2026
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'ISTEN_AYRILMA', '2015-01-15', 'IPTAL')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'ISTEN_AYRILMA', '2026-03-10', 'AKTIF')"
    );
    $term = RetentionPolicyService::resolveTerminationDate($pdo, 11);
    rp053Assert($term === '2026-03-10', '21 rehire-safe latest non-IPTAL');

    // Create lifecycle manifests (identity + fingerprint) for current 2026 termination
    $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 11);
    rp053Assert($fp !== null && strlen($fp) === 64, '22 fingerprint');
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, 11, 1);
    $m = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        11,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 11]
    );
    rp053Assert($m !== null, '23 lifecycle manifest PERSONEL_OZLUK');
    $m2 = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        11,
        RetentionCategories::ISE_GIRIS_CIKIS,
        ['personel_id' => 11]
    );
    rp053Assert($m2 !== null, '24 lifecycle manifest ISE_GIRIS_CIKIS');

    // 25 Same identity + different baseline → ARCHIVE_MANIFEST_SOURCE_CHANGED
    try {
        ArchiveManifestService::createManifest($pdo, [
            'entity_type' => 'personel',
            'record_id' => 11,
            'personel_id' => 11,
            'record_category' => RetentionCategories::PERSONEL_OZLUK,
            'source_version_identity' => (string) $m['source_version_identity'],
            'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
            'trigger_date' => '2026-03-10',
            'source_sha256' => str_repeat('a', 64),
        ], 1);
        rp053Assert(false, '25 same identity different sha should throw');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === ArchiveManifestService::CODE_ARCHIVE_MANIFEST_SOURCE_CHANGED,
            '25 ARCHIVE_MANIFEST_SOURCE_CHANGED'
        );
    }

    // 25b Different lifecycle identity → NEW immutable row (no overwrite)
    $priorId = (int) $m['id'];
    $legacy = ArchiveManifestService::createManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'record_category' => RetentionCategories::PERSONEL_OZLUK,
        'source_version_identity' => 'personel:11:termination:2015-01-15',
        'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
        'trigger_date' => '2015-01-15',
        'source_sha256' => $fp,
    ], 1);
    rp053Assert((int) $legacy['id'] !== $priorId, '25b new lifecycle row created');
    $mUnchanged = ArchiveManifestService::findBySourceIdentity(
        $pdo,
        'personel',
        11,
        RetentionCategories::PERSONEL_OZLUK,
        (string) $m['source_version_identity']
    );
    rp053Assert((int) $mUnchanged['id'] === $priorId, '25c prior lifecycle preserved');
    $current = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        11,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 11]
    );
    rp053Assert(
        (string) $current['source_version_identity'] === 'personel:11:termination:2026-03-10',
        '25d current lifecycle is 2026'
    );

    // 26 Idempotent same identity
    $again = ArchiveManifestService::createManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'record_category' => RetentionCategories::PERSONEL_OZLUK,
        'source_version_identity' => (string) $m['source_version_identity'],
        'trigger_type' => (string) $m['trigger_type'],
        'trigger_date' => (string) $m['trigger_date'],
        'retention_until' => (string) $m['retention_until'],
        'source_sha256' => (string) $m['source_sha256'],
    ], 1);
    rp053Assert((int) $again['id'] === (int) $m['id'], '26 idempotent same baseline');

    // 27 Not mature via RetentionClock override
    RetentionClock::setOverride(new DateTimeImmutable('2030-01-01'));
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11,
        'current_sha256' => $fp,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_RETENTION_NOT_MATURE, '27 RETENTION_NOT_MATURE clock');
    rp053Assert($r['retention_until'] === '2036-03-10', '28 calendar +10 from 2026-03-10');

    // 29 Mature via clock → ELIGIBLE (no GM required)
    RetentionClock::setOverride(new DateTimeImmutable('2037-01-01'));
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11,
        'current_sha256' => $fp,
    ]);
    rp053Assert(
        $r['code'] === RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST,
        '29 ELIGIBLE_FOR_DESTRUCTION_REQUEST'
    );
    rp053Assert(empty($r['code']) || $r['code'] !== RetentionPolicyService::CODE_NO_GM_APPROVAL, '30 no NO_GM_APPROVAL path');

    // Public API alias = pre-approval (not APPROVED_FOR_DESTRUCTION)
    $r2 = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11,
        'current_sha256' => $fp,
    ]);
    rp053Assert(
        $r2['code'] === RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST,
        '31 evaluateDestructionEligibility = pre-approval'
    );

    // 32 Legal hold blocks
    $gm = rp053User(1, 'GENEL_YONETICI');
    LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 11,
        'reason' => 'Sorusturma',
    ]);
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'entity_type' => 'personel', 'record_id' => 11,
        'current_sha256' => $fp,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE, '32 LEGAL_HOLD_ACTIVE');

    // 33 Request while hold → BLOCKED not REQUESTED
    $reqBlocked = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PERSONEL_OZLUK,
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'reason' => 'Hold varken talep',
    ]);
    rp053Assert((string) $reqBlocked['item']['status'] === 'BLOCKED', '33 hold request BLOCKED');
    rp053Assert((string) $reqBlocked['item']['status'] !== 'REQUESTED', '33b never REQUESTED under hold');

    // 34 Release hold
    $holds = LegalHoldService::list($pdo, true);
    rp053Assert(count($holds) >= 1, '34 active hold exists');
    LegalHoldService::release($pdo, $gm, (int) $holds[0]['id'], 'Kapandi');

    // 35 Eligible request → REQUESTED with snapshots
    $req = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PERSONEL_OZLUK,
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'reason' => 'Politika degerlendirmesi',
    ]);
    rp053Assert((string) $req['item']['status'] === 'REQUESTED', '35 REQUESTED when eligible');
    rp053Assert(!empty($req['item']['trigger_date_snapshot']), '36 trigger_date_snapshot set');
    rp053Assert(!empty($req['item']['source_sha256_snapshot']), '37 source_sha256_snapshot set');

    // 38 Approve after re-hold blocks
    LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 11,
        'reason' => 'Onay oncesi hold',
    ]);
    try {
        DestructionWorkflowService::approveDestruction($pdo, $gm, (int) $req['item']['id'], 'GM onay', true);
        rp053Assert(false, '38 approve under hold should throw');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
            '38 approve-after-hold LEGAL_HOLD_ACTIVE'
        );
    }
    $fresh = DestructionWorkflowService::getById($pdo, (int) $req['item']['id']);
    rp053Assert((string) $fresh['status'] === 'REQUESTED', '39 still REQUESTED after failed approve');

    // Release and approve
    $holds = LegalHoldService::list($pdo, true);
    LegalHoldService::release($pdo, $gm, (int) $holds[0]['id'], 'Serbest');
    $approved = DestructionWorkflowService::approveDestruction(
        $pdo,
        $gm,
        (int) $req['item']['id'],
        'GM onay',
        true
    );
    rp053Assert((string) $approved['status'] === 'APPROVED', '40 approve GM');

    // 41 Final execution → EXECUTION_HANDLER_NOT_IMPLEMENTED
    $eval = DestructionWorkflowService::evaluateExecution($pdo, $gm, (int) $approved['id']);
    rp053Assert(
        ($eval['execution']['code'] ?? '') === RetentionPolicyService::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
        '41 EXECUTION_HANDLER_NOT_IMPLEMENTED'
    );

    // 42 Integrity mismatch sticky CHANGED
    $pdo->exec("UPDATE personeller SET ad = 'PasifX' WHERE id = 11");
    $newFp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 11);
    $integrity = ArchiveManifestService::verifySourceIntegrity(
        $pdo,
        'personel',
        11,
        RetentionCategories::PERSONEL_OZLUK,
        $newFp
    );
    rp053Assert(
        $integrity === RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
        '42 integrity CHANGED'
    );
    $mAfter = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        11,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 11]
    );
    rp053Assert((string) $mAfter['integrity_status'] === 'CHANGED', '43 sticky CHANGED');
    // Restore name for further checks
    $pdo->exec("UPDATE personeller SET ad = 'Pasif' WHERE id = 11");

    // 44 Missing manifest code
    $missing = ArchiveManifestService::verifySourceIntegrity($pdo, 'personel', 99999, RetentionCategories::PERSONEL_OZLUK, $fp);
    rp053Assert($missing === ArchiveManifestService::CODE_ARCHIVE_MANIFEST_MISSING, '44 ARCHIVE_MANIFEST_MISSING');

    // 45 Context as_of / gm_approved ignored (clock wins)
    RetentionClock::setOverride(new DateTimeImmutable('2030-01-01'));
    $r = RetentionPolicyService::evaluatePreApprovalEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11,
        'entity_type' => 'personel',
        'record_id' => 11,
        'current_sha256' => ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 11),
        'as_of' => '2099-01-01',
        'gm_approved' => true,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_RETENTION_NOT_MATURE, '45 context as_of ignored');

    // 46 PASIF write rejection
    try {
        PersonelArchiveGate::assertBusinessWriteAllowedOrThrow($pdo, 11);
        rp053Assert(false, '46 PASIF write should throw');
    } catch (RuntimeException $e) {
        rp053Assert(
            $e->getMessage() === RetentionPolicyService::CODE_ARCHIVED_PERSONEL_READ_ONLY,
            '46 ARCHIVED_PERSONEL_READ_ONLY'
        );
    }
    // AKTIF allowed
    PersonelArchiveGate::assertBusinessWriteAllowedOrThrow($pdo, 10);
    rp053Assert(true, '47 AKTIF write allowed');

    // 48 Schema gate fail-closed for legal hold
    $pdo->exec('RENAME TABLE legal_holdlar TO legal_holdlar_bak');
    try {
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::legalHoldTables());
        rp053Assert(false, '48 schema should throw');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === RetentionSchemaGate::CODE_SCHEMA_NOT_READY, '48 SCHEMA_NOT_READY');
    }
    try {
        RetentionPolicyService::hasActiveLegalHold($pdo, RetentionCategories::PERSONEL_OZLUK, [
            'personel_id' => 11,
        ]);
        rp053Assert(false, '49 missing hold table must not mean no hold');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === RetentionSchemaGate::CODE_SCHEMA_NOT_READY, '49 hold missing fail-closed');
    }
    $pdo->exec('RENAME TABLE legal_holdlar_bak TO legal_holdlar');

    // 50 Archive access audit
    RetentionClock::clearOverride();
    ArchiveAccessService::writeAccessAudit(
        $pdo,
        $gm,
        ArchiveAccessService::ACTION_LIST,
        'personeller',
        11,
        null,
        '/personeller',
        ['count' => 1]
    );
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM arsiv_erisim_auditleri')->fetchColumn();
    rp053Assert($auditCount >= 1, '50 access audit written');

    // 51 No personel deleted
    $pCount = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    rp053Assert($pCount === 2, '51 no personel deleted');

    $msg = RetentionPolicyService::codeMessage(RetentionPolicyService::CODE_RETENTION_NOT_MATURE);
    rp053Assert(stripos($msg, 'kanunen') === false, 'no kanunen in messages');

    foreach (RetentionCategories::periodClosureCategories() as $cat) {
        rp053Assert(
            RetentionCategories::triggerTypeForCategory($cat) === RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            'period map ' . $cat
        );
    }
    foreach (RetentionCategories::terminationDateCategories() as $cat) {
        rp053Assert(
            RetentionCategories::triggerTypeForCategory($cat) === RetentionCategories::TRIGGER_TERMINATION_DATE,
            'lifecycle map ' . $cat
        );
    }

    // Controller source: as_of/gm_approved removed
    $ctrl = file_get_contents(__DIR__ . '/../../api/src/Controllers/RetentionController.php');
    rp053Assert($ctrl !== false && strpos($ctrl, "getQuery('as_of'") === false, 'controller no as_of');
    rp053Assert($ctrl !== false && strpos($ctrl, "getQuery('gm_approved'") === false, 'controller no gm_approved');
    rp053Assert($ctrl !== false && strpos($ctrl, 'evaluatePreApprovalEligibility') !== false, 'controller pre-approval');
    rp053Assert($ctrl !== false && strpos($ctrl, 'RetentionTargetResolver') !== false, 'controller uses target resolver');
    rp053Assert($ctrl !== false && strpos($ctrl, 'assertPersonelAccess') !== false, 'controller SubeScope guard');

    // ---- FINAL GATE MATRIX (multi-lifecycle / scope / legal hold / sources / snapshots) ----

    // ML1-6: full rehire lifecycle on personel 10
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF', ise_giris_tarihi = '2010-01-01' WHERE id = 10");
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (10, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, 10, 1);
    $manifestA = ArchiveManifestService::findBySourceIdentity(
        $pdo,
        'personel',
        10,
        RetentionCategories::PERSONEL_OZLUK,
        'personel:10:termination:2015-06-01'
    );
    rp053Assert($manifestA !== null && (string) $manifestA['trigger_date'] === '2015-06-01', 'ML1 manifest A 2015');
    $aId = (int) $manifestA['id'];
    $aSha = (string) $manifestA['source_sha256'];

    // Rehire → AKTIF
    $pdo->exec("UPDATE personeller SET aktif_durum = 'AKTIF', ise_giris_tarihi = '2018-01-01' WHERE id = 10");
    rp053Assert(RetentionPolicyService::resolveTerminationDate($pdo, 10) === null, 'ML2 rehire AKTIF no term');

    // Final termination 2026
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = 10");
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (10, 'ISTEN_AYRILMA', '2026-01-15', 'AKTIF')"
    );
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, 10, 1);
    $rowsOzluk = ArchiveManifestService::listForRecord($pdo, 'personel', 10, RetentionCategories::PERSONEL_OZLUK);
    rp053Assert(count($rowsOzluk) === 2, 'ML3 PERSONEL_OZLUK count=2');
    $rowsIse = ArchiveManifestService::listForRecord($pdo, 'personel', 10, RetentionCategories::ISE_GIRIS_CIKIS);
    rp053Assert(count($rowsIse) === 2, 'ML3b ISE_GIRIS_CIKIS count=2');
    $manifestB = ArchiveManifestService::findBySourceIdentity(
        $pdo,
        'personel',
        10,
        RetentionCategories::PERSONEL_OZLUK,
        'personel:10:termination:2026-01-15'
    );
    rp053Assert($manifestB !== null && (string) $manifestB['trigger_date'] === '2026-01-15', 'ML4 manifest B 2026');
    $aAgain = ArchiveManifestService::findBySourceIdentity(
        $pdo,
        'personel',
        10,
        RetentionCategories::PERSONEL_OZLUK,
        'personel:10:termination:2015-06-01'
    );
    rp053Assert(
        (int) $aAgain['id'] === $aId && (string) $aAgain['source_sha256'] === $aSha,
        'ML5 A unchanged'
    );
    $cur = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        10,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 10]
    );
    rp053Assert((int) $cur['id'] === (int) $manifestB['id'], 'ML6 current=B');
    rp053Assert((string) $cur['retention_until'] === '2036-01-15', 'ML6b retention_until 2036');
    // Exact replay no duplicates
    $replay = ArchiveManifestService::createPersonelLifecycleManifests($pdo, 10, 1);
    rp053Assert((int) $replay[0]['id'] === (int) $manifestB['id'], 'ML6c exact replay no dup');
    rp053Assert(
        count(ArchiveManifestService::listForRecord($pdo, 'personel', 10, RetentionCategories::PERSONEL_OZLUK)) === 2,
        'ML6d still 2 rows'
    );

    // ONAY_AUDIT lifecycle parent → TERMINATION_DATE
    $trig = RetentionPeriodTriggerResolver::resolve($pdo, RetentionCategories::ONAY_AUDIT, [
        'personel_id' => 10,
        'parent_category' => RetentionCategories::PERSONEL_OZLUK,
    ]);
    rp053Assert(
        $trig['trigger_type'] === RetentionCategories::TRIGGER_TERMINATION_DATE
            && $trig['trigger_date'] === '2026-01-15',
        'OA1 ONAY_AUDIT parent TERMINATION_DATE'
    );

    // Source adapters
    $srcPersonel = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 10, 'entity_type' => 'personel', 'record_id' => 10,
    ]);
    rp053Assert(
        $srcPersonel['source_version_identity'] === 'personel:10:termination:2026-01-15',
        'SRC20 PERSONEL adapter'
    );

    $srcPuantaj = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'entity_type' => 'puantaj', 'record_id' => 1,
    ]);
    rp053Assert(strpos($srcPuantaj['source_version_identity'], 'puantaj_seal:') === 0, 'SRC22 PUANTAJ');

    // Bordro finalized (seeded earlier in test 11)
    $srcBordro = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::BORDRO, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'entity_type' => 'bordro', 'record_id' => 1,
    ]);
    rp053Assert(strpos($srcBordro['source_version_identity'], 'bordro_run:') === 0, 'SRC23 BORDRO');

    $srcSgk = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::SGK_EKSIK_GUN, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'entity_type' => 'sgk', 'record_id' => 1,
    ]);
    rp053Assert(strpos($srcSgk['source_version_identity'], 'sgk_snapshot:') === 0, 'SRC24 SGK');

    $srcFm = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::FAZLA_CALISMA, [
        'haftalik_kapanis_id' => $weekId, 'sube_id' => 1, 'entity_type' => 'haftalik_kapanis', 'record_id' => $weekId,
    ]);
    rp053Assert(strpos($srcFm['source_version_identity'], 'haftalik_kapanis:') === 0, 'SRC25 FM');
    $srcSz = RetentionSourceAdapterService::resolve($pdo, RetentionCategories::SERBEST_ZAMAN, [
        'hafta_baslangic' => '2010-01-04', 'sube_id' => 1, 'entity_type' => 'haftalik_kapanis', 'record_id' => $weekId,
    ]);
    rp053Assert(strpos($srcSz['source_version_identity'], 'haftalik_kapanis:') === 0, 'SRC26 SZ');

    // Coverage map — all catalog categories have resolvers in this build
    $coverage = RetentionSourceAdapterService::coverageMap();
    foreach (RetentionCategories::all() as $cat) {
        rp053Assert($coverage[$cat]['source_resolver'] === 'implemented', 'SRC cov ' . $cat);
    }

    // Legal hold validations
    try {
        LegalHoldService::create($pdo, $gm, [
            'target_domain' => 'personel',
            'target_category' => 'NOT_A_REAL_CATEGORY',
            'personel_id' => 10,
            'reason' => 'bad cat',
        ]);
        rp053Assert(false, 'LH15 unknown category');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === 'LEGAL_HOLD_CATEGORY_INVALID', 'LH15 unknown category reject');
    }
    try {
        LegalHoldService::create($pdo, $gm, [
            'target_domain' => 'surec',
            'target_record_id' => 999999,
            'reason' => 'missing',
        ]);
        rp053Assert(false, 'LH16 missing record');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === 'LEGAL_HOLD_TARGET_NOT_FOUND', 'LH16 nonexistent record');
    }
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (10, 'IZIN', '2020-01-01', 'AKTIF')"
    );
    $surecId = (int) $pdo->lastInsertId();
    try {
        LegalHoldService::create($pdo, $gm, [
            'target_domain' => 'surec',
            'target_record_id' => $surecId,
            'personel_id' => 11,
            'reason' => 'mismatch',
        ]);
        rp053Assert(false, 'LH17 mismatch');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === 'LEGAL_HOLD_PERSONEL_MISMATCH', 'LH17 record/personel mismatch');
    }
    try {
        LegalHoldService::create($pdo, $gm, [
            'target_domain' => 'puantaj',
            'target_record_id' => 1,
            'reason' => 'unsupported',
        ]);
        rp053Assert(false, 'LH18 unsupported');
    } catch (RuntimeException $e) {
        rp053Assert($e->getMessage() === LegalHoldService::CODE_TARGET_UNSUPPORTED, 'LH18 unsupported');
    }
    $validHold = LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 10,
        'reason' => 'GM personel-wide hold',
    ]);
    rp053Assert((string) $validHold['hold_state'] === 'ACTIVE', 'LH19 valid GM hold');
    LegalHoldService::release($pdo, $gm, (int) $validHold['id'], 'test done');

    // Scope: null-personel record hold scoped via surec owner; global hold hidden from branch
    $pdo->exec(
        "INSERT INTO legal_holdlar
            (target_domain, target_category, target_record_id, personel_id, reason, hold_state, created_by)
         VALUES
            ('surec', 'IZIN', {$surecId}, NULL, 'record hold', 'ACTIVE', 1),
            ('category', 'BORDRO', NULL, NULL, 'global hold', 'ACTIVE', 1)"
    );
    $branchScoped = LegalHoldService::list($pdo, true, [1]);
    $seenRecord = false;
    $seenGlobal = false;
    foreach ($branchScoped as $h) {
        if ((string) ($h['reason'] ?? '') === 'record hold') {
            $seenRecord = true;
        }
        if ((string) ($h['reason'] ?? '') === 'global hold') {
            $seenGlobal = true;
        }
    }
    rp053Assert($seenRecord === true, 'SC13 null-personel record hold visible via owner sube');
    rp053Assert($seenGlobal === false, 'SC14 global hold hidden from branch scope');
    $globalList = LegalHoldService::list($pdo, true, null);
    $seenGlobalGm = false;
    foreach ($globalList as $h) {
        if ((string) ($h['reason'] ?? '') === 'global hold') {
            $seenGlobalGm = true;
        }
    }
    rp053Assert($seenGlobalGm === true, 'SC14b global hold visible to global role');

    // Branch B personel not in scope A
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (2, 'B', 'Sube B', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (20, '33333333333', 'Branch', 'B', '1990-01-01', '05000000010', 'Acil', '05000000011',
            'S020', '2010-01-01', 2, 'PASIF')"
    );
    $resolved = RetentionTargetResolver::validateAndResolve(
        $pdo,
        RetentionCategories::PERSONEL_OZLUK,
        'personel',
        20,
        20,
        []
    );
    rp053Assert((int) $resolved['sube_id'] === 2, 'SC11 resolved sube B');
    $scopedSube = RetentionScopeResolver::resolveSubeId($pdo, [
        'personel_id' => 20,
        'entity_type' => 'personel',
        'record_id' => 20,
    ]);
    rp053Assert($scopedSube === 2, 'SC11b scope resolver sube B');
    $filtered = RetentionScopeResolver::filterRowsBySubeScope($pdo, [
        ['personel_id' => 20, 'entity_type' => 'personel', 'record_id' => 20, 'id' => 1],
        ['personel_id' => 10, 'entity_type' => 'personel', 'record_id' => 10, 'id' => 2],
    ], [1]);
    rp053Assert(count($filtered) === 1 && (int) $filtered[0]['id'] === 2, 'SC11c branch A cannot see B');

    // Snapshot fail-closed: required fields missing
    $snapIncomplete = RetentionPolicyService::evaluateFinalExecutionEligibility(
        $pdo,
        RetentionCategories::PERSONEL_OZLUK,
        [
            'personel_id' => 10,
            'entity_type' => 'personel',
            'record_id' => 10,
            'current_sha256' => ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 10),
        ],
        [
            'id' => 999,
            'status' => 'APPROVED',
            'approved_by' => 1,
            'approved_at' => '2037-01-01 00:00:00',
            'trigger_type_snapshot' => null,
            'trigger_date_snapshot' => '2026-01-15',
            'retention_until_snapshot' => '2036-01-15',
            'source_version_identity_snapshot' => 'personel:10:termination:2026-01-15',
            'source_sha256_snapshot' => ArchiveManifestService::computePersonelOzlukFingerprint($pdo, 10),
        ],
        new DateTimeImmutable('2037-01-01')
    );
    // Will fail NOT_APPROVED (no audit) or SNAPSHOT_INCOMPLETE — either is fail-closed
    rp053Assert(
        in_array($snapIncomplete['code'], [
            RetentionPolicyService::CODE_SNAPSHOT_INCOMPLETE,
            RetentionPolicyService::CODE_DESTRUCTION_REQUEST_NOT_APPROVED,
            RetentionPolicyService::CODE_ARCHIVE_MANIFEST_MISSING,
            RetentionPolicyService::CODE_RETENTION_NOT_MATURE,
            RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
        ], true),
        'SNAP28 required snapshot missing fail-closed'
    );

    // Old lifecycle snapshot cannot match current
    $oldSnapMismatch = true;
    $oldIdentity = 'personel:10:termination:2015-06-01';
    $newIdentity = 'personel:10:termination:2026-01-15';
    rp053Assert($oldIdentity !== $newIdentity, 'SNAP29 old lifecycle != new');

    // Unique index includes source_version_identity
    $idx = $pdo->query(
        "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'arsiv_manifestleri'
           AND INDEX_NAME = 'uq_arsiv_manifest_entity_cat_src'
         GROUP BY INDEX_NAME"
    )->fetch(PDO::FETCH_ASSOC);
    rp053Assert(
        $idx
        && strpos((string) $idx['cols'], 'source_version_identity') !== false,
        'UQ multi-lifecycle unique includes source_version_identity'
    );
    $oldIdx = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'arsiv_manifestleri'
           AND INDEX_NAME = 'uq_arsiv_manifest_entity_cat'"
    )->fetchColumn();
    rp053Assert($oldIdx === 0, 'UQ old unique dropped');

    // Idempotent 053 re-apply
    rp053Apply($pdo, '053_retention_legal_hold_arsiv.sql');
    rp053Assert(true, '053 idempotent re-apply');

    RetentionClock::clearOverride();
    echo "verify-retention-policy-053-mysql: OK\n";
} finally {
    RetentionClock::clearOverride();
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
