<?php

declare(strict_types=1);

/**
 * Phase C: disposable MariaDB — retention matrix + 053 idempotency.
 * php tests/php/RetentionPolicy053MysqlTestRunner.php
 *
 * Matrix coverage (items 1-22, 35-37 style):
 * categories, period/termination triggers, fail-closed codes, legal hold,
 * GM approval, integrity, execute-not-implemented, no auto-delete.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Retention\ArchiveAccessService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionPolicyService;

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

$root = rp053RootPdo();
$database = 'medisa_rp053_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = rp053PdoForDb($database);
    $files = rp053MigrationFiles();
    rp053Assert(end($files) === '053_retention_legal_hold_arsiv.sql', '1 tip ends at 053');
    rp053Assert(in_array('052_puantaj_tolerans_ve_disiplin.sql', $files, true), '1b 052 still present');

    // Minimal schema + 053 (full 001..053 chain has DELIMITER/trigger split issues in PDO apply).
    rp053Apply($pdo, '001_initial_schema.sql');
    rp053Apply($pdo, '002_puantaj_aylik_muhurleme.sql');
    rp053Apply($pdo, '053_retention_legal_hold_arsiv.sql');
    rp053Assert(true, '2 first apply 053 PASS');

    // Idempotent re-apply of 053
    rp053Apply($pdo, '053_retention_legal_hold_arsiv.sql');
    rp053Assert(true, '3 reapply 053 idempotent PASS');

    // Tables exist
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

    // Roles in ENUM
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'rol'")->fetch(PDO::FETCH_ASSOC);
    $type = (string) ($col['Type'] ?? '');
    rp053Assert(strpos($type, 'IDARI_ISLER') !== false, '5 ENUM IDARI_ISLER');
    rp053Assert(strpos($type, 'SISTEM_YONETICISI') !== false, '6 ENUM SISTEM_YONETICISI');

    rp053Seed($pdo);

    // 7 unknown category
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, 'XYZ', ['personel_id' => 11]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_UNKNOWN_CATEGORY, '7 UNKNOWN_CATEGORY');

    // 8 period category without seal → PERIOD_NOT_CLOSED
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2020, 'ay' => 1, 'personel_id' => 10,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_PERIOD_NOT_CLOSED, '8 PERIOD_NOT_CLOSED');

    // 9 period missing context → TRIGGER_NOT_RESOLVED
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::BORDRO, []);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED, '9 TRIGGER_NOT_RESOLVED period');

    // Insert seal for 2010-01
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, 2010, 1, '2010-01', 'MUHURLENDI', 1, 1, '2010-02-05 10:00:00')"
    );

    // 10 period closed but not mature (as_of early)
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'as_of' => '2015-01-01', 'gm_approved' => true,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_RETENTION_NOT_MATURE, '10 RETENTION_NOT_MATURE period');
    rp053Assert($r['retention_until'] === '2020-02-05', '11 calendar +10 from seal date');

    // 12 period mature + no GM → NO_GM_APPROVAL
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'as_of' => '2021-01-01',
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_NO_GM_APPROVAL, '12 NO_GM_APPROVAL');

    // 13 period mature + GM → APPROVED_FOR_DESTRUCTION
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'as_of' => '2021-01-01', 'gm_approved' => true,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION, '13 APPROVED_FOR_DESTRUCTION');

    // 14 execute always EXECUTION_HANDLER_NOT_IMPLEMENTED
    $exec = RetentionPolicyService::executeDestruction($pdo, RetentionCategories::PUANTAJ, [
        'sube_id' => 1, 'yil' => 2010, 'ay' => 1, 'as_of' => '2021-01-01', 'gm_approved' => true,
    ]);
    rp053Assert(
        $exec['code'] === RetentionPolicyService::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
        '14 EXECUTION_HANDLER_NOT_IMPLEMENTED'
    );

    // 15 termination missing
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_TERMINATION_DATE_MISSING, '15 TERMINATION_DATE_MISSING');

    // Add ISTEN_AYRILMA
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'ISTEN_AYRILMA', '2012-03-10', 'AKTIF')"
    );

    // 16 termination not mature
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'as_of' => '2018-01-01', 'gm_approved' => true,
        'entity_type' => 'personel', 'record_id' => 11,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_RETENTION_NOT_MATURE, '16 RETENTION_NOT_MATURE lifecycle');
    rp053Assert($r['retention_until'] === '2022-03-10', '17 calendar +10 termination');

    // 18 legal hold blocks
    $gm = rp053User(1, 'GENEL_YONETICI');
    LegalHoldService::create($pdo, $gm, [
        'target_domain' => 'personel',
        'personel_id' => 11,
        'reason' => 'Sorusturma',
    ]);
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'as_of' => '2025-01-01', 'gm_approved' => true,
        'entity_type' => 'personel', 'record_id' => 11,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE, '18 LEGAL_HOLD_ACTIVE');

    // 19 release hold then mature+gm approve path
    $holds = LegalHoldService::list($pdo, true);
    rp053Assert(count($holds) === 1, '19 active hold exists');
    LegalHoldService::release($pdo, $gm, (int) $holds[0]['id'], 'Kapandi');
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'as_of' => '2025-01-01', 'gm_approved' => true,
        'entity_type' => 'personel', 'record_id' => 11,
    ]);
    rp053Assert($r['code'] === RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION, '20 after release APPROVED');

    // 21 manifest integrity mismatch
    ArchiveManifestService::upsertManifest($pdo, [
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'record_category' => RetentionCategories::PERSONEL_OZLUK,
        'source_version_identity' => 'v1',
        'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
        'trigger_date' => '2012-03-10',
        'source_sha256' => str_repeat('a', 64),
    ], 1);
    $r = RetentionPolicyService::evaluateDestructionEligibility($pdo, RetentionCategories::PERSONEL_OZLUK, [
        'personel_id' => 11, 'as_of' => '2025-01-01', 'gm_approved' => true,
        'entity_type' => 'personel', 'record_id' => 11,
        'check_integrity' => true,
        'current_sha256' => str_repeat('b', 64),
    ]);
    rp053Assert(
        $r['code'] === RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
        '21 ARCHIVE_SOURCE_INTEGRITY_CHANGED'
    );

    // 22 destruction workflow request + approve + evaluate
    $req = DestructionWorkflowService::requestDestruction($pdo, $gm, [
        'category' => RetentionCategories::PERSONEL_OZLUK,
        'entity_type' => 'personel',
        'record_id' => 11,
        'personel_id' => 11,
        'reason' => 'Politika degerlendirmesi',
        'as_of' => '2025-01-01',
    ]);
    rp053Assert(isset($req['item']['id']), '22 request created');
    $approved = DestructionWorkflowService::approveDestruction(
        $pdo,
        $gm,
        (int) $req['item']['id'],
        'GM onay',
        true
    );
    rp053Assert((string) $approved['status'] === 'APPROVED', '35 approve GM');
    $eval = DestructionWorkflowService::evaluateExecution($pdo, $gm, (int) $approved['id']);
    rp053Assert(
        ($eval['execution']['code'] ?? '') === RetentionPolicyService::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED,
        '36 evaluate execution not implemented'
    );

    // 37 archive access audit append-only LIST/VIEW
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
    rp053Assert($auditCount >= 1, '37 access audit written');

    // No generic personeller delete happened
    $pCount = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    rp053Assert($pCount === 2, 'no personel deleted');

    // Policy note never statutory in service messages
    $msg = RetentionPolicyService::codeMessage(RetentionPolicyService::CODE_RETENTION_NOT_MATURE);
    rp053Assert(stripos($msg, 'kanunen') === false, 'no kanunen in messages');
    rp053Assert(stripos(RetentionCategories::POLICY_NOTE, 'kanunen') === false, 'no kanunen in policy note');

    // Category maps
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

    echo "verify-retention-policy-053-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
