<?php

declare(strict_types=1);

/**
 * MG-PERSONNEL-BULK-POSTCHECK-DYNAMIC-CONTRACT-001 MariaDB runtime acceptance.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkApplyService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkDryRunService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkPostcheck;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkRowContract;

function pcAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pcRootPdo(): PDO
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
function pcSplitSql(string $sql): array
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

function pcApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (pcSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** @return list<string> */
function pcBaseMigrationFiles(): array
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

function pcBootstrapSchema(PDO $pdo): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();

    foreach (pcBaseMigrationFiles() as $file) {
        pcApply($pdo, $file);
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
        pcApply($pdo, $file);
    }
}

function pcUser(int $id, string $rol): array
{
    return ['id' => $id, 'rol' => $rol, 'username' => 'u' . $id];
}

function pcSeedReferenceData(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users (id, username, rol, durum, ad_soyad, password_hash) VALUES
        (1, 'gm', 'GENEL_YONETICI', 'AKTIF', 'Genel Yonetici', 'x')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (1, 'MEDISA', 'SGK Medisa', 'AKTIF')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, durum) VALUES (1, 'SB1', 'Merkez', 1, 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (1, 'CL1', 'Merkez Lokasyon', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Uretim', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Operator', 'AKTIF'), (2, 'Yeni Gorev', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Mavi Yaka', 'AKTIF')");
}

function pcSeedPersonelInventory(PDO $pdo, int $total, int $active): void
{
    pcAssert($active <= $total, 'seed active count cannot exceed total');
    for ($i = 1; $i <= $total; $i++) {
        $aktif = $i <= $active ? 'AKTIF' : 'PASIF';
        $pdo->exec(
            "INSERT INTO personeller (
                id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
                sube_id, departman_id, gorev_id, personel_tipi_id, sgk_isveren_id, calisma_lokasyonu_id
            ) VALUES (
                {$i}, 'Person', '{$i}', 'PC-{$i}', '2024-01-01', '{$aktif}', 'IC_PERSONEL',
                1, 1, 1, 1, 1, 1
            )"
        );
    }
}

/** @return list<array<string, mixed>> */
function pcCreateRows(int $count, int $startIndex = 1): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $n = $startIndex + $i;
        $rows[] = [
            'mutation_id' => 'pc-create-' . $n,
            'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
            'personel_ref' => 'pc-ref-' . $n,
            'payload' => [
                'eksik_bilgi_ile_olustur' => true,
                'ad' => 'Create',
                'soyad' => 'Person' . $n,
                'ise_giris_tarihi' => '2026-08-01',
                'aktif_durum' => 'AKTIF',
                'calisan_kapsami' => 'IC_PERSONEL',
                'personel_tipi_id' => 1,
            ],
        ];
    }

    return $rows;
}

/** @return list<array<string, mixed>> */
function pcExitRows(array $personelIds, int $startIndex = 1): array
{
    $rows = [];
    foreach ($personelIds as $offset => $personelId) {
        $n = $startIndex + $offset;
        $rows[] = [
            'mutation_id' => 'pc-exit-' . $n,
            'operation_type' => PersonelLifecycleBulkRowContract::OP_EXIT,
            'personel_id' => (int) $personelId,
            'payload' => ['exit_date' => '2026-07-30', 'aciklama' => 'PC dynamic contract exit'],
            'gerekce' => 'PC dynamic contract exit',
        ];
    }

    return $rows;
}

$root = pcRootPdo();
$db = 'medisa_mg_postcheck_dyn_' . bin2hex(random_bytes(4));
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
    pcBootstrapSchema($pdo);
    pcSeedReferenceData($pdo);
    $gm = pcUser(1, 'GENEL_YONETICI');
    $request = new Request();
    $deployedSha = str_repeat('e', 40);

    // Scenario A: 139/135 + 14 create + 3 exit -> 153/146 PASS
    pcSeedPersonelInventory($pdo, 139, 135);
    $inventoryA = PersonelLifecycleBulkPostcheck::captureInventory($pdo);
    pcAssert($inventoryA['baseline_total'] === 139, 'scenario A baseline total 139');
    pcAssert($inventoryA['baseline_active'] === 135, 'scenario A baseline active 135');
    $rowsA = array_merge(pcCreateRows(14), pcExitRows([133, 134, 135]));
    $dryA = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $rowsA, null, $deployedSha);
    pcAssert(($dryA['postcheck']['expected_total_after'] ?? -1) === 153, 'scenario A expected total 153');
    pcAssert(($dryA['postcheck']['expected_active_after'] ?? -1) === 146, 'scenario A expected active 146');
    pcAssert(count($dryA['postcheck_errors'] ?? []) === 0, 'scenario A postcheck passes');
    pcAssert(($dryA['can_apply'] ?? false) === true, 'scenario A can_apply true');

    // Scenario B: 153/146 + 0 create + 2 exit -> 153/144 PASS
    for ($i = 140; $i <= 153; $i++) {
        $pdo->exec(
            "INSERT INTO personeller (
                id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
                sube_id, departman_id, gorev_id, personel_tipi_id, sgk_isveren_id, calisma_lokasyonu_id
            ) VALUES (
                {$i}, 'Added', '{$i}', 'PC-{$i}', '2026-08-01', 'AKTIF', 'IC_PERSONEL',
                1, 1, 1, 1, 1, 1
            )"
        );
    }
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id IN (133, 134, 135)");
    $inventoryB = PersonelLifecycleBulkPostcheck::captureInventory($pdo);
    pcAssert($inventoryB['baseline_total'] === 153, 'scenario B baseline total 153');
    pcAssert($inventoryB['baseline_active'] === 146, 'scenario B baseline active 146');
    $rowsB = pcExitRows([131, 132], 100);
    $dryB = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $rowsB, null, $deployedSha);
    pcAssert(($dryB['postcheck']['create_count'] ?? -1) === 0, 'scenario B has zero creates');
    pcAssert(($dryB['postcheck']['exit_count'] ?? -1) === 2, 'scenario B has two exits');
    pcAssert(($dryB['postcheck']['expected_total_after'] ?? -1) === 153, 'scenario B expected total 153');
    pcAssert(($dryB['postcheck']['expected_active_after'] ?? -1) === 144, 'scenario B expected active 144');
    pcAssert(count($dryB['postcheck_errors'] ?? []) === 0, 'scenario B postcheck passes');
    pcAssert(($dryB['can_apply'] ?? false) === true, 'scenario B can_apply true');

    // Scenario C: org-only change leaves counts unchanged PASS
    $beforeTotal = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    $beforeActive = (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE aktif_durum = 'AKTIF'")->fetchColumn();
    $orgRow = [
        'mutation_id' => 'pc-org-neutral',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 1,
        'gerekce' => 'PC org-only postcheck',
        'payload' => ['gorev_id' => 2],
    ];
    $dryOrg = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$orgRow], null, $deployedSha);
    pcAssert(($dryOrg['postcheck']['total_delta'] ?? -1) === 0, 'org-only plan has zero total delta');
    pcAssert(($dryOrg['postcheck']['active_delta'] ?? -1) === 0, 'org-only plan has zero active delta');
    pcAssert(count($dryOrg['postcheck_errors'] ?? []) === 0, 'org-only postcheck passes');
    pcAssert(($dryOrg['can_apply'] ?? false) === true, 'org-only can_apply true');
    pcAssert(
        $beforeTotal === (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(),
        'org-only dry-run leaves total unchanged'
    );
    pcAssert(
        $beforeActive === (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE aktif_durum = 'AKTIF'")->fetchColumn(),
        'org-only dry-run leaves active unchanged'
    );

    // Scenario D: stale fingerprint / checksum drift blocks apply without writes
    $beforeAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $staleBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            [$orgRow],
            (string) $dryOrg['dry_run_checksum'],
            hash('sha256', 'stale-fingerprint'),
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $staleBlocked = $e->getCodeString() === 'PREIMAGE_STALE';
    }
    pcAssert($staleBlocked, 'stale fingerprint blocks apply');
    pcAssert(
        $beforeAudit === (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn(),
        'stale fingerprint apply writes no audit'
    );

    $driftBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            [$orgRow],
            hash('sha256', 'checksum-drift'),
            (string) $dryOrg['preimage_checksum'],
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $driftBlocked = $e->getCodeString() === 'DRY_RUN_STALE';
    }
    pcAssert($driftBlocked, 'checksum drift blocks apply');

    // Scenario E: exit on pasif preimage blocks can_apply
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = 132");
    $pasifExit = pcExitRows([132], 200);
    $dryPasif = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $pasifExit, null, $deployedSha);
    pcAssert(
        in_array('EXIT_PREIMAGE_NOT_AKTIF', $dryPasif['satirlar'][0]['hata_kodlari'] ?? [], true),
        'pasif exit preimage blocked in dry-run'
    );
    pcAssert(($dryPasif['can_apply'] ?? true) === false, 'pasif exit blocks can_apply');

    // Scenario F: tampered analysis row fails validateContract (fail-closed)
    $inventoryF = PersonelLifecycleBulkPostcheck::captureInventory($pdo);
    $tampered = $dryB['satirlar'];
    $tampered[0]['preimage_aktif_durum'] = 'PASIF';
    $tamperedErrors = PersonelLifecycleBulkPostcheck::validateContract($inventoryF, $tampered);
    pcAssert(
        in_array('POSTCHECK_EXIT_PREIMAGE_NOT_AKTIF', $tamperedErrors, true),
        'tampered exit preimage fails validateContract'
    );

    // Scenario G: duplicate replay does not duplicate writes
    PersonelLifecycleBulkApplyService::apply(
        $pdo,
        $gm,
        $request,
        [$orgRow],
        (string) $dryOrg['dry_run_checksum'],
        (string) $dryOrg['preimage_checksum'],
        $deployedSha
    );
    $auditAfterFirst = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $retryBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            [$orgRow],
            (string) $dryOrg['dry_run_checksum'],
            (string) $dryOrg['preimage_checksum'],
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $retryBlocked = $e->getCodeString() === 'CANNOT_APPLY' || $e->getCodeString() === 'DRY_RUN_STALE';
    }
    pcAssert($retryBlocked, 'duplicate replay blocked on second apply');
    pcAssert(
        $auditAfterFirst === (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn(),
        'duplicate replay writes no extra audit'
    );

    // Scenario H: inventory drift between dry-run and apply invalidates checksum
    $driftRow = [
        'mutation_id' => 'pc-drift-create',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
        'personel_ref' => 'pc-drift-ref',
        'payload' => [
            'eksik_bilgi_ile_olustur' => true,
            'ad' => 'Drift',
            'soyad' => 'Create',
            'ise_giris_tarihi' => '2026-08-01',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => 'IC_PERSONEL',
            'personel_tipi_id' => 1,
        ],
    ];
    $dryDrift = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$driftRow], null, $deployedSha);
    $beforeDriftTotal = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    $pdo->exec(
        "INSERT INTO personeller (
            id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
            sube_id, departman_id, gorev_id, personel_tipi_id
        ) VALUES (
            9999, 'Inventory', 'Drift', 'PC-9999', '2026-08-01', 'AKTIF', 'IC_PERSONEL',
            1, 1, 1, 1
        )"
    );
    $inventoryDriftBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            [$driftRow],
            (string) $dryDrift['dry_run_checksum'],
            (string) $dryDrift['preimage_checksum'],
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $inventoryDriftBlocked = in_array($e->getCodeString(), ['DRY_RUN_STALE', 'PREIMAGE_STALE', 'CANNOT_APPLY'], true);
    }
    pcAssert($inventoryDriftBlocked, 'inventory drift blocks apply with stale checksum');
    pcAssert(
        $beforeDriftTotal + 1 === (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(),
        'inventory drift apply did not insert planned create'
    );

    echo "verify-mg-personnel-bulk-postcheck-dynamic-contract-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
