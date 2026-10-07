<?php

declare(strict_types=1);

/**
 * MG-PERSONNEL-BULK-APPLY-OWNER-CLOSEOUT-002 MariaDB runtime acceptance.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCompletenessService;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelIncompleteCreateService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkApplyService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkDryRunService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkPostcheck;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkReferenceResolver;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkRowContract;
use Medisa\Api\Services\Personel\PersonelValidationException;

function mgAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function mgRootPdo(): PDO
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
function mgSplitSql(string $sql): array
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

function mgApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (mgSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** @return list<string> */
function mgBaseMigrationFiles(): array
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

function mgBootstrapSchema(PDO $pdo): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();

    foreach (mgBaseMigrationFiles() as $file) {
        mgApply($pdo, $file);
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
        mgApply($pdo, $file);
    }
}

function mgUser(int $id, string $rol): array
{
    return ['id' => $id, 'rol' => $rol, 'username' => 'u' . $id];
}

function mgBindingRows(int $createCount, int $exitCount): array
{
    $rows = [];
    for ($i = 1; $i <= $createCount; $i++) {
        $rows[] = [
            'mutation_id' => 'mg-create-' . $i,
            'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
            'personel_ref' => 'ref-' . $i,
            'payload' => [
                'ad' => 'Test',
                'soyad' => 'Create' . $i,
                'ise_giris_tarihi' => '2026-08-01',
                'aktif_durum' => 'AKTIF',
                'calisan_kapsami' => 'DIS_KAYNAK',
                'eksik_bilgi_ile_olustur' => true,
                'personel_tipi_id' => 1,
            ],
        ];
    }
    for ($j = 1; $j <= $exitCount; $j++) {
        $rows[] = [
            'mutation_id' => 'mg-exit-' . $j,
            'operation_type' => PersonelLifecycleBulkRowContract::OP_EXIT,
            'personel_id' => 900 + $j,
            'payload' => ['exit_date' => '2026-08-31', 'aciklama' => 'MG closeout exit test'],
            'gerekce' => 'MG closeout exit test',
        ];
    }

    return $rows;
}

$root = mgRootPdo();
$db = 'medisa_mg_bulk_closeout_' . bin2hex(random_bytes(4));
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
    mgBootstrapSchema($pdo);

    $pdo->exec("INSERT INTO users (id, username, rol, durum, ad_soyad, password_hash) VALUES
        (1, 'gm', 'GENEL_YONETICI', 'AKTIF', 'Genel Yonetici', 'x'),
        (2, 'ik', 'IK_SORUMLUSU', 'AKTIF', 'Ik Sorumlusu', 'x')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (1, 'MEDISA', 'SGK Medisa', 'AKTIF')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, durum) VALUES
        (1, 'SB1', 'Merkez', 1, 'AKTIF'),
        (2, 'SB2', 'Hedef', 1, 'AKTIF'),
        (3, 'SB3', 'Pasif Hedef', 1, 'PASIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (1, 'CL1', 'Merkez Lokasyon', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Uretim', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Operator', 'AKTIF'), (2, 'Yeni Gorev', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Mavi Yaka', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller (
            id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
            sube_id, departman_id, gorev_id, personel_tipi_id, sgk_isveren_id, calisma_lokasyonu_id
        ) VALUES (
            100, 'Org', 'Target', 'ORG-100', '2024-01-01', 'AKTIF', 'IC_PERSONEL',
            1, 1, 1, 1, 1, 1
        )");
    for ($e = 1; $e <= 3; $e++) {
        $pdo->exec("INSERT INTO personeller (
                id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
                sube_id, departman_id, gorev_id, personel_tipi_id
            ) VALUES (
                " . (900 + $e) . ", 'Exit', 'Person" . $e . "', 'EXIT-" . $e . "', '2024-01-01', 'AKTIF', 'IC_PERSONEL',
                1, 1, 1, 1
            )");
    }
    $pdo->exec("INSERT INTO personeller (
            id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
            sube_id, departman_id, gorev_id, personel_tipi_id, sgk_isveren_id, calisma_lokasyonu_id
        ) VALUES (
            101, 'Multi', 'Axis', 'MULTI-101', '2024-01-01', 'AKTIF', 'IC_PERSONEL',
            1, 1, 1, 1, 1, 1
        )");

    $gm = mgUser(1, 'GENEL_YONETICI');
    $ik = mgUser(2, 'IK_SORUMLUSU');
    $request = new Request();

    // 1) Strict create still rejects missing IC org fields.
    $strictRejected = false;
    try {
        PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
            'ad' => 'Strict',
            'soyad' => 'Reject',
            'ise_giris_tarihi' => '2026-08-01',
            'aktif_durum' => 'AKTIF',
            'calisan_kapsami' => 'IC_PERSONEL',
            'personel_tipi_id' => 1,
            'sube_id' => 1,
        ]);
    } catch (PersonelValidationException $e) {
        $strictRejected = true;
    }
    mgAssert($strictRejected, 'strict create without intent rejects missing IC org fields');

    // 2) Unauthorized actor cannot incomplete-create.
    $forbidden = false;
    try {
        PersonelIncompleteCreateService::assertAuthorized($ik);
    } catch (PersonelValidationException $e) {
        $forbidden = $e->getCodeString() === PersonelIncompleteCreateService::ERROR_FORBIDDEN;
    }
    mgAssert($forbidden, 'unauthorized actor cannot incomplete-create');

    // 3–4) Authorized incomplete create persists explicit NULLs (no placeholders).
    $canonical = PersonelIncompleteCreateService::normalizePayload([
        'eksik_bilgi_ile_olustur' => true,
        'ad' => 'Eksik',
        'soyad' => 'Kayit',
        'ise_giris_tarihi' => '2026-08-26',
        'aktif_durum' => 'AKTIF',
        'calisan_kapsami' => 'DIS_KAYNAK',
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => 1,
        'sube_id' => null,
        'departman_id' => null,
        'gorev_id' => null,
    ]);
    PersonelCreateService::validateCreateReferences($pdo, $canonical);
    $pdo->beginTransaction();
    $newId = PersonelCreateService::insertPersonel($pdo, $canonical);
    $pdo->commit();
    $row = $pdo->query('SELECT sube_id, departman_id, gorev_id, tc_kimlik_no FROM personeller WHERE id = ' . (int) $newId)->fetch(PDO::FETCH_ASSOC);
    mgAssert(is_array($row), 'incomplete create inserted a row');
    mgAssert($row['sube_id'] === null, 'sube_id remains NULL');
    mgAssert($row['departman_id'] === null, 'departman_id remains NULL');
    mgAssert($row['gorev_id'] === null, 'gorev_id remains NULL');
    mgAssert($row['tc_kimlik_no'] === null, 'tc_kimlik_no remains NULL');

    // 5) External worker does not get SGK Medisa by default.
    $external = PersonelIncompleteCreateService::normalizePayload([
        'eksik_bilgi_ile_olustur' => true,
        'ad' => 'Dis',
        'soyad' => 'Kaynak',
        'ise_giris_tarihi' => '2026-08-01',
        'aktif_durum' => 'AKTIF',
        'calisan_kapsami' => 'DIS_KAYNAK',
        'personel_tipi_id' => 1,
    ]);
    mgAssert(!array_key_exists('sgk_isveren_id', $external) || $external['sgk_isveren_id'] === null, 'external incomplete create keeps SGK NULL');

    // 6) Completeness owner reports missing org fields.
    $completeness = PersonelCompletenessService::evaluate([
        'calisan_kapsami' => 'IC_PERSONEL',
        'sicil_no' => 'X1',
        'ise_giris_tarihi' => '2026-01-01',
        'sube_id' => null,
        'departman_id' => null,
        'gorev_id' => null,
        'pozisyon_id' => null,
        'bagli_amir_id' => null,
        'personel_tipi_id' => 1,
        'tc_kimlik_no' => '10000000146',
        'dogum_tarihi' => '1990-01-01',
        'telefon' => '05000000000',
    ]);
    mgAssert($completeness['is_complete'] === false, 'completeness marks incomplete IC row');
    $labels = implode('|', $completeness['critical_missing_labels']);
    mgAssert(strpos($labels, 'Şube') !== false, 'completeness includes sube gap');
    mgAssert(strpos($labels, 'Pozisyon') !== false, 'completeness includes pozisyon gap');

    // 7–8) Dynamic postcheck contract from live inventory + dry-run analysis.
    $inventory = PersonelLifecycleBulkPostcheck::captureInventory($pdo);
    $bindingRows = mgBindingRows(14, 3);
    $bindingDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $bindingRows, null, str_repeat('c', 40));
    $postcheck = $bindingDry['postcheck'] ?? [];
    mgAssert(
        ($postcheck['baseline_total'] ?? -1) === $inventory['baseline_total'],
        'postcheck baseline total matches live inventory'
    );
    mgAssert(
        ($postcheck['expected_total_after'] ?? -1) === $inventory['baseline_total'] + 14,
        'postcheck total delta from 14 creates'
    );
    mgAssert(
        ($postcheck['expected_active_after'] ?? -1) === $inventory['baseline_active'] + 14 - 3,
        'postcheck active delta from 14 creates and 3 exits'
    );
    mgAssert(count($bindingDry['postcheck_errors'] ?? []) === 0, '14+3 ready plan passes dynamic contract');
    mgAssert(($bindingDry['can_apply'] ?? false) === true, '14+3 ready plan is can_apply');

    $pasifExitRow = [
        'mutation_id' => 'mg-exit-pasif',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_EXIT,
        'personel_id' => 901,
        'payload' => ['exit_date' => '2026-08-31', 'aciklama' => 'blocked pasif exit'],
        'gerekce' => 'blocked pasif exit',
    ];
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = 901");
    $pasifDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$pasifExitRow], null, str_repeat('d', 40));
    mgAssert(
        in_array('EXIT_PREIMAGE_NOT_AKTIF', $pasifDry['satirlar'][0]['hata_kodlari'] ?? [], true),
        'exit on pasif preimage is blocked in dry-run'
    );
    mgAssert(($pasifDry['can_apply'] ?? true) === false, 'pasif exit preimage blocks can_apply');
    $pdo->exec("UPDATE personeller SET aktif_durum = 'AKTIF' WHERE id = 901");

    // 9) Bulk dry-run org row is READY (not 501).
    $orgDryRow = [
        'mutation_id' => 'mg-org-dry-1',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 100,
        'gerekce' => 'MG org dry-run delegation test',
        'payload' => ['gorev_id' => 2],
    ];
    $dry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [$orgDryRow], null, str_repeat('a', 40));
    mgAssert(($dry['satirlar'][0]['durum'] ?? '') === 'READY', 'bulk dry-run org row is READY');
    mgAssert(($dry['satirlar'][0]['mutation_plan']['owner'] ?? '') === 'PersonelOrganizasyonDegisikligiService', 'org dry-run delegates to canonical owner');

    // 10) Bulk apply org update writes audit via canonical owner.
    $deployedSha = str_repeat('b', 40);
    $applyRows = [$orgDryRow];
    $dryForApply = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $applyRows, null, $deployedSha);

    // Stale preimage blocks apply (fail-closed) before any mutation.
    $staleBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            $applyRows,
            (string) $dryForApply['dry_run_checksum'],
            hash('sha256', 'stale-preimage'),
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $staleBlocked = $e->getCodeString() === 'PREIMAGE_STALE';
    }
    mgAssert($staleBlocked, 'stale preimage blocks apply');

    // Bulk apply org update writes audit via canonical owner.
    $beforeAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    PersonelLifecycleBulkApplyService::apply(
        $pdo,
        $gm,
        $request,
        $applyRows,
        (string) $dryForApply['dry_run_checksum'],
        (string) $dryForApply['preimage_checksum'],
        $deployedSha
    );
    $afterGorev = (int) $pdo->query('SELECT gorev_id FROM personeller WHERE id = 100')->fetchColumn();
    $afterAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    mgAssert($afterGorev === 2, 'bulk apply org update changed gorev_id');
    mgAssert($afterAudit === $beforeAudit + 1, 'bulk apply org update wrote audit row');

    // Retry with stale dry-run must not duplicate audit/personel rows.
    $auditAfterFirst = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $ledgerCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'mg-org-dry-1'"
    )->fetchColumn();
    mgAssert($ledgerCount === 1, 'apply writes one idempotency ledger row');
    $retryBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            $applyRows,
            (string) $dryForApply['dry_run_checksum'],
            (string) $dryForApply['preimage_checksum'],
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $retryBlocked = in_array($e->getCodeString(), ['DRY_RUN_STALE', 'CANNOT_APPLY'], true);
    }
    mgAssert($retryBlocked, 'retry with stale dry-run is fail-closed');
    $auditAfterRetryAttempt = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    mgAssert($auditAfterFirst === $auditAfterRetryAttempt, 'retry does not duplicate audit rows');

    // 11–12) One row owns basic + org + branch as one atomic mutation.
    $multiRow = [[
        'mutation_id' => 'mg-multi-axis-101',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 101,
        'gerekce' => 'MG multi axis atomic update',
        'payload' => [
            'bagli_amir' => 'Ik Sorumlusu',
            'calisan_kapsami' => 'DIS_KAYNAK',
            'gorev_id' => 2,
            'yeni_sube_id' => 2,
        ],
    ]];
    $multiDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $multiRow, null, $deployedSha);
    $multiPlan = $multiDry['satirlar'][0]['mutation_plan'] ?? [];
    mgAssert(($multiDry['can_apply'] ?? false) === true, 'multi-axis dry-run is can_apply');
    mgAssert(($multiPlan['mode'] ?? '') === 'MULTI_AXIS', 'multi-axis row has one canonical plan');
    mgAssert(
        isset($multiPlan['axes']['basic'], $multiPlan['axes']['organization'], $multiPlan['axes']['branch']),
        'multi-axis plan contains basic org and branch axes'
    );
    mgAssert(
        (int) ($multiPlan['axes']['basic']['payload']['bagli_amir_id'] ?? 0) === 2,
        'multi-axis dry-run resolves manager name exactly once'
    );
    mgAssert(
        is_array($multiPlan['axes']['organization']['targets'] ?? null)
            && array_key_exists('sgk_isveren_id', $multiPlan['axes']['organization']['targets'])
            && $multiPlan['axes']['organization']['targets']['sgk_isveren_id'] === null,
        'DIS_KAYNAK canonical plan clears SGK through the org owner'
    );
    $beforeMultiAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $beforeBranchAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_sube_degisiklik_auditleri')->fetchColumn();
    $multiApply = PersonelLifecycleBulkApplyService::apply(
        $pdo, $gm, $request, $multiRow,
        (string) $multiDry['dry_run_checksum'], (string) $multiDry['preimage_checksum'], $deployedSha
    );
    $afterMulti = $pdo->query('SELECT sube_id, bagli_amir_id, calisan_kapsami, gorev_id, sgk_isveren_id FROM personeller WHERE id = 101')->fetch(PDO::FETCH_ASSOC);
    mgAssert(($multiApply['failed_count'] ?? -1) === 0, 'multi-axis apply succeeds');
    mgAssert(
        is_array($afterMulti)
            && (int) $afterMulti['sube_id'] === 2
            && (int) $afterMulti['bagli_amir_id'] === 2
            && (string) $afterMulti['calisan_kapsami'] === 'DIS_KAYNAK'
            && (int) $afterMulti['gorev_id'] === 2
            && $afterMulti['sgk_isveren_id'] === null,
        'multi-axis apply uses the dry-run canonical payload without drift'
    );
    mgAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn() === $beforeMultiAudit + 1
            && (int) $pdo->query('SELECT COUNT(*) FROM personel_sube_degisiklik_auditleri')->fetchColumn() === $beforeBranchAudit + 1,
        'multi-axis writes each existing audit owner once'
    );
    mgAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'mg-multi-axis-101'")->fetchColumn() === 1,
        'multi-axis uses one idempotency ledger entry'
    );
    $multiAuditAfterFirst = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $multiReplayBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo, $gm, $request, $multiRow,
            (string) $multiDry['dry_run_checksum'], (string) $multiDry['preimage_checksum'], $deployedSha
        );
    } catch (PersonelImportException $e) {
        $multiReplayBlocked = in_array($e->getCodeString(), ['DRY_RUN_STALE', 'CANNOT_APPLY'], true);
    }
    mgAssert($multiReplayBlocked, 'multi-axis stale retry is fail-closed');
    mgAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn() === $multiAuditAfterFirst,
        'multi-axis stale retry does not duplicate business mutation'
    );

    // An organization-owner error after basic update must roll everything back too.
    $secondOwnerRollbackRow = [[
        'mutation_id' => 'mg-multi-second-owner-100',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 100,
        'gerekce' => 'MG second owner rollback test',
        'payload' => ['bagli_amir_id' => 2, 'gorev_id' => 999],
    ]];
    $secondOwnerDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $secondOwnerRollbackRow, null, $deployedSha);
    $beforeSecondOwner = $pdo->query('SELECT bagli_amir_id, gorev_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    $beforeSecondOwnerAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $secondOwnerApply = PersonelLifecycleBulkApplyService::apply(
        $pdo, $gm, $request, $secondOwnerRollbackRow,
        (string) $secondOwnerDry['dry_run_checksum'], (string) $secondOwnerDry['preimage_checksum'], $deployedSha
    );
    $afterSecondOwner = $pdo->query('SELECT bagli_amir_id, gorev_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    mgAssert(($secondOwnerApply['failed_count'] ?? 0) === 1, 'second owner error fails multi-axis row');
    mgAssert($afterSecondOwner === $beforeSecondOwner, 'second owner error rolls back earlier basic update');
    mgAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn() === $beforeSecondOwnerAudit
            && (int) $pdo->query("SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'mg-multi-second-owner-100'")->fetchColumn() === 0,
        'second owner error rolls back audit and idempotency ledger'
    );

    // A later branch-owner error rolls back the earlier basic/org writes, audits and ledger claim.
    $rollbackRow = [[
        'mutation_id' => 'mg-multi-rollback-100',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 100,
        'gerekce' => 'MG multi axis rollback test',
        'payload' => ['bagli_amir_id' => 2, 'gorev_id' => 1, 'yeni_sube_id' => 3],
    ]];
    $rollbackDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $rollbackRow, null, $deployedSha);
    $beforeRollback = $pdo->query('SELECT sube_id, bagli_amir_id, gorev_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    $rollbackApply = PersonelLifecycleBulkApplyService::apply(
        $pdo, $gm, $request, $rollbackRow,
        (string) $rollbackDry['dry_run_checksum'], (string) $rollbackDry['preimage_checksum'], $deployedSha
    );
    $afterRollback = $pdo->query('SELECT sube_id, bagli_amir_id, gorev_id FROM personeller WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
    mgAssert(($rollbackApply['failed_count'] ?? 0) === 1, 'later branch owner error fails multi-axis row');
    mgAssert($afterRollback === $beforeRollback, 'later owner error rolls back earlier personel updates');
    mgAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'mg-multi-rollback-100'")->fetchColumn() === 0,
        'later owner error rolls back idempotency ledger claim'
    );

    // 13) Independent person rows: failed ref does not block unrelated ref.
    $paired = [
        [
            'mutation_id' => 'mg-fail-create',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
            'personel_ref' => 'bad-ref',
            'payload' => [
                'ad' => 'Bad',
                'soyad' => 'Strict',
                'ise_giris_tarihi' => '2026-08-01',
                'aktif_durum' => 'AKTIF',
                'calisan_kapsami' => 'IC_PERSONEL',
            ],
        ],
        [
            'mutation_id' => 'mg-good-create',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_CREATE,
            'personel_ref' => 'good-ref',
            'payload' => [
                'eksik_bilgi_ile_olustur' => true,
                'ad' => 'Good',
                'soyad' => 'Independent',
                'ise_giris_tarihi' => '2026-08-02',
                'aktif_durum' => 'AKTIF',
                'calisan_kapsami' => 'DIS_KAYNAK',
                'personel_tipi_id' => 1,
            ],
        ],
    ];
    $pairedDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $paired, null, $deployedSha);
    mgAssert(($pairedDry['ozet']['blocked'] ?? 0) >= 1, 'strict missing row is blocked in dry-run');
    mgAssert(($pairedDry['ozet']['ready'] ?? 0) >= 1, 'independent incomplete row stays ready');

    // 14) Manager resolution does not fabricate users.
    $beforeUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $managerId = PersonelLifecycleBulkReferenceResolver::resolveBagliAmirUserId($pdo, 'Halil Senay');
    $afterUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    mgAssert($managerId === null, 'unknown manager name resolves to NULL');
    mgAssert($beforeUsers === $afterUsers, 'manager resolution does not create users');
    $pdo->exec("INSERT INTO users (id, username, rol, durum, ad_soyad, password_hash) VALUES
        (3, 'duplicate-amir-1', 'BIRIM_AMIRI', 'AKTIF', 'Çakışan Amir', 'x'),
        (4, 'duplicate-amir-2', 'BIRIM_AMIRI', 'AKTIF', 'Cakisan Amir', 'x')");
    $beforeManagerAnalysis = [
        'personel' => (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(),
        'org_audit' => (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn(),
        'ledger' => (int) $pdo->query('SELECT COUNT(*) FROM offline_mutation_idempotency')->fetchColumn(),
    ];
    $managerDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [
        [
            'mutation_id' => 'mg-missing-manager',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 100,
            'gerekce' => 'MG missing manager reference',
            'payload' => ['bagli_amir' => 'Halil Senay'],
        ],
        [
            'mutation_id' => 'mg-ambiguous-manager',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 101,
            'gerekce' => 'MG ambiguous manager reference',
            'payload' => ['bagli_amir' => 'Cakisan Amir'],
        ],
        [
            'mutation_id' => 'mg-valid-manager-analysis',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 903,
            'gerekce' => 'MG valid manager analysis row',
            'payload' => ['bagli_amir' => 'Ik Sorumlusu'],
        ],
    ], null, $deployedSha);
    $halilDry = $managerDry['satirlar'][0] ?? [];
    mgAssert(
        ($halilDry['durum'] ?? '') === 'BLOCKED'
            && in_array('MISSING_MANAGER_PERSONNEL_REFERENCE', $halilDry['hata_kodlari'] ?? [], true)
            && ($halilDry['validation_code'] ?? '') === 'MISSING_MANAGER_PERSONNEL_REFERENCE'
            && ($halilDry['validation_field'] ?? '') === 'bagli_amir'
            && array_key_exists('mutation_plan', $halilDry) && $halilDry['mutation_plan'] === null,
        'unresolved manager name blocks dry-run without user creation'
    );
    $ambiguousManagerDry = $managerDry['satirlar'][1] ?? [];
    mgAssert(
        ($ambiguousManagerDry['durum'] ?? '') === 'BLOCKED'
            && ($ambiguousManagerDry['validation_code'] ?? '') === 'AMBIGUOUS_MANAGER_PERSONNEL_REFERENCE'
            && array_key_exists('mutation_plan', $ambiguousManagerDry) && $ambiguousManagerDry['mutation_plan'] === null,
        'ambiguous manager name blocks dry-run without arbitrary selection'
    );
    mgAssert(
        ($managerDry['satirlar'][2]['durum'] ?? '') === 'READY' && ($managerDry['can_apply'] ?? true) === false,
        'blocked manager rows do not prevent other row analysis but block changeset apply'
    );
    mgAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn() === $beforeManagerAnalysis['personel'],
        'blocked manager dry-run does not write business data'
    );
    mgAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn() === $beforeManagerAnalysis['org_audit']
            && (int) $pdo->query('SELECT COUNT(*) FROM offline_mutation_idempotency')->fetchColumn() === $beforeManagerAnalysis['ledger'],
        'blocked manager dry-run writes neither audit nor idempotency ledger'
    );
    $pdo->exec('ALTER TABLE users RENAME COLUMN ad_soyad TO ad_soyad_bozuk');
    $unexpectedEscaped = false;
    try {
        PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, [[
            'mutation_id' => 'mg-unexpected-manager-system-error',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 100,
            'gerekce' => 'MG unexpected manager system error',
            'payload' => ['bagli_amir' => 'Ik Sorumlusu'],
        ]], null, $deployedSha);
    } catch (\PDOException $e) {
        $unexpectedEscaped = true;
    }
    mgAssert($unexpectedEscaped, 'unexpected manager resolver database error escapes dry-run globally');

    // 15) Sensitive TC marker stays out of runner output (this file uses masked placeholder only).
    mgAssert(strpos($labels, '10000000146') === false, 'completeness labels omit raw TC content');

    echo "verify-mg-personnel-bulk-apply-owner-closeout-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
