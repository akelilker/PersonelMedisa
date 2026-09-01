<?php

declare(strict_types=1);

/**
 * MG-PERSONNEL-BULK-DRY-RUN-APPLY-PARITY-001 MariaDB runtime acceptance.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkDryRunService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkMutationPlanner;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkReferenceResolver;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkRowContract;

function parityAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function parityRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }
    if (stripos($dsn, 'karmotor_medisa') !== false) {
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
function paritySplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    $inSingle = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if (!$inSingle && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
            continue;
        }
        if (!$inTrigger && !$inSingle && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] !== "'") {
                continue;
            }
            if ($inSingle && $i + 1 < $len && $line[$i + 1] === "'") {
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        }
        if ($inSingle) {
            continue;
        }
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function parityApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (paritySplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** @return list<string> */
function parityBaseMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
            && $name !== '068_sgk_actor_identity_lifecycle_audit.sql'
            && $name !== '069_personel_credential_onboarding.sql'
            && $name !== '071_org_hierarchy_authorization.sql'
            && $name !== '072_org_reference_short_codes.sql'
            && $name !== '073_test_fixture_personel_archive.sql'
            && $name !== '074_qr_attendance_correction_and_inbox.sql'
            && $name !== '075_personel_account_activation.sql'
            && $name !== '077_legacy_role_enum_shrink.sql'
            && $name !== '081_ik_personeli_rolu.sql'
            && $name !== '082_user_erisim_degisiklik_auditleri.sql';
    }));
    sort($files, SORT_STRING);

    return $files;
}

function parityBootstrapSchema(PDO $pdo): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();

    foreach (parityBaseMigrationFiles() as $file) {
        parityApply($pdo, $file);
        if ($file === '065_personel_org_structure.sql') {
            break;
        }
    }
    foreach ([
        '066_personel_calisan_kapsami.sql',
        '070_offline_mutation_idempotency.sql',
        '076_dis_kaynak_gecici_gorevlendirme.sql',
        '078_personel_sicil_sequence.sql',
        '079_sirket_sube_hiyerarsisi.sql',
        '080_organizasyon_audit_owners.sql',
        '083_personel_organizasyon_degisiklik_auditleri.sql',
    ] as $file) {
        parityApply($pdo, $file);
    }
}

function parityUser(int $id, string $rol): array
{
    return ['id' => $id, 'rol' => $rol, 'username' => 'u' . $id];
}

$root = parityRootPdo();
$db = 'medisa_mg_bulk_parity_' . bin2hex(random_bytes(4));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', getenv('MEDISA_TEST_MYSQL_DSN') ?: '') ?: (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
$root = new PDO(
    $rootDsn,
    getenv('MEDISA_TEST_MYSQL_USER') ?: '',
    getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = new PDO(
    $rootDsn . ';dbname=' . $db,
    getenv('MEDISA_TEST_MYSQL_USER') ?: '',
    getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

try {
    parityBootstrapSchema($pdo);

    $pdo->exec("INSERT INTO users (id, username, rol, durum, ad_soyad, password_hash) VALUES
        (1, 'gm', 'GENEL_YONETICI', 'AKTIF', 'Genel Yonetici', 'x')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (1, 'MEDISA', 'SGK Medisa', 'AKTIF')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, durum) VALUES (1, 'SB1', 'Merkez', 1, 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (1, 'CL1', 'Merkez Lokasyon', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Uretim', 'AKTIF'), (2, 'Depo A', 'AKTIF'), (3, 'Depo B', 'AKTIF')");
    $pdo->exec("INSERT INTO bolumler (id, ad, departman_id, durum) VALUES
        (10, 'Panel Atolyesi', 1, 'AKTIF'),
        (11, 'Depo', 2, 'AKTIF'),
        (12, 'Depo', 3, 'AKTIF')");
    $pdo->exec("INSERT INTO birimler (id, ad, bolum_id, durum) VALUES (20, 'Makine', 10, 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Operator', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Mavi Yaka', 'AKTIF')");

    $gm = parityUser(1, 'GENEL_YONETICI');
    $request = new Request();
    $deployedSha = str_repeat('c', 40);

    $badRow = [
        'mutation_id' => 'mg-create-bad-hierarchy',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
        'personel_ref' => 'bad-hierarchy',
        'payload' => [
            'eksik_bilgi_ile_olustur' => true,
            'ad' => 'Bad',
            'soyad' => 'Hierarchy',
            'ise_giris_tarihi' => '2026-08-01',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => 'IC_PERSONEL',
            'personel_tipi_id' => 1,
            'bolum_id' => 999,
        ],
    ];
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    $badDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$badRow], null, $deployedSha);
    parityAssert(($badDry['satirlar'][0]['durum'] ?? '') === 'BLOCKED', 'invalid bolum without departman is blocked in dry-run');
    parityAssert(in_array('VALIDATION_ERROR', $badDry['satirlar'][0]['hata_kodlari'] ?? [], true), 'invalid hierarchy uses VALIDATION_ERROR');
    parityAssert(($badDry['satirlar'][0]['validation_field'] ?? '') === 'bolum_id', 'invalid hierarchy reports bolum_id field');
    parityAssert($beforeCount === (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(), 'dry-run blocked row leaves personel count unchanged');

    $goodRow = [
        'mutation_id' => 'mg-create-inferred-departman',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
        'personel_ref' => 'inferred-departman',
        'payload' => [
            'eksik_bilgi_ile_olustur' => true,
            'ad' => 'Enes',
            'soyad' => 'Parity',
            'ise_giris_tarihi' => '2026-08-01',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => 'IC_PERSONEL',
            'personel_tipi_id' => 1,
            'sube_id' => 1,
            'bolum_id' => 10,
            'birim_id' => 20,
        ],
    ];
    $goodDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$goodRow], null, $deployedSha);
    $planPayload = $goodDry['satirlar'][0]['mutation_plan']['payload'] ?? [];
    parityAssert(($goodDry['satirlar'][0]['durum'] ?? '') === 'READY', 'inferred departman appears in dry-run create plan');
    parityAssert((int) ($planPayload['departman_id'] ?? 0) === 1, 'dry-run plan carries inferred departman_id=1');
    parityAssert(($goodDry['satirlar'][0]['mutation_plan']['canonical_ready'] ?? false) === true, 'dry-run and apply share canonical_ready create payload');

    parityAssert(($goodDry['can_apply'] ?? true) === false, 'single create row does not satisfy binding postcheck');

    $pdo->beginTransaction();
    $createdId = PersonelCreateService::insertPersonel($pdo, $planPayload);
    $pdo->commit();
    parityAssert($createdId > 0, 'canonical create payload from dry-run plan inserts successfully');
    $created = $pdo->query('SELECT departman_id, bolum_id FROM personeller WHERE id = ' . (int) $createdId)->fetch(PDO::FETCH_ASSOC);
    parityAssert((int) ($created['departman_id'] ?? 0) === 1, 'applied row persists inferred departman');
    parityAssert((int) ($created['bolum_id'] ?? 0) === 10, 'applied row keeps bolum');

    $plannedAgain = PersonelLifecycleBulkMutationPlanner::planCreatePayload($pdo, $gm, $goodRow['payload']);
    parityAssert((int) ($plannedAgain['payload']['departman_id'] ?? 0) === 1, 'idempotent replay preserves single create row');
    parityAssert($beforeCount + 1 === (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(), 'single create row after replay');

    $turkishId = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName($pdo, 'departmanlar', 'Üretim');
    parityAssert($turkishId === 1, 'turkish departman name resolves to canonical row');

    $ambiguous = PersonelLifecycleBulkReferenceResolver::resolveActiveIdByName($pdo, 'bolumler', 'Depo');
    parityAssert($ambiguous === null, 'ambiguous reference name resolves to null');

    $plannerBlocked = false;
    try {
        PersonelLifecycleBulkMutationPlanner::planCreatePayload($pdo, $gm, [
            'eksik_bilgi_ile_olustur' => true,
            'ad' => 'Planner',
            'soyad' => 'Blocked',
            'ise_giris_tarihi' => '2026-08-01',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => 'IC_PERSONEL',
            'personel_tipi_id' => 1,
            'bolum_id' => 10,
            'departman_id' => 2,
        ]);
    } catch (\Throwable $e) {
        $plannerBlocked = PersonelLifecycleBulkMutationPlanner::dryRunErrorCode($e) === 'VALIDATION_ERROR';
    }
    parityAssert($plannerBlocked, 'planner and dry-run share hierarchy validation result');

    echo "verify-mg-personnel-bulk-dry-run-apply-parity-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
