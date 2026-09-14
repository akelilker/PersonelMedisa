<?php

declare(strict_types=1);

/**
 * MG-PERSONNEL-BULK-MULTI-AXIS-PREIMAGE-REBASE-001 MariaDB runtime regression.
 *
 * Root cause under test: PersonelLifecycleBulkApplyService::applyPlan MULTI_AXIS ran the
 * basic axis (bagli_amir_id) first and then handed the plan preimage — which still carried
 * the pre-basic value of every overlap field — to the organization axis, so the canonical
 * org owner failed with PERSONEL_ORGANIZASYON_STALE_PREIMAGE and the row rolled back.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkApplyService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkDryRunService;
use Medisa\Api\Services\Personel\PersonelLifecycleBulkRowContract;

function mgRebaseAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function mgRebaseRootPdo(): PDO
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
function mgRebaseSplitSql(string $sql): array
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

function mgRebaseApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (mgRebaseSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** @return list<string> */
function mgRebaseBaseMigrationFiles(): array
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

function mgRebaseBootstrapSchema(PDO $pdo): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();

    foreach (mgRebaseBaseMigrationFiles() as $file) {
        mgRebaseApply($pdo, $file);
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
        mgRebaseApply($pdo, $file);
    }
}

/** @return array<string, mixed>|null */
function mgRebasePersonelSubset(PDO $pdo, int $personelId, string $columns): ?array
{
    $stmt = $pdo->prepare('SELECT ' . $columns . ' FROM personeller WHERE id = :id');
    $stmt->execute(['id' => $personelId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

$deployedSha = str_repeat('a', 40);
$root = mgRebaseRootPdo();
$db = 'medisa_mg_bulk_rebase_' . bin2hex(random_bytes(4));
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
    mgRebaseBootstrapSchema($pdo);

    $pdo->exec("INSERT INTO users (id, username, rol, durum, ad_soyad, password_hash) VALUES
        (1, 'gm', 'GENEL_YONETICI', 'AKTIF', 'Genel Yonetici', 'x'),
        (2, 'amir', 'IK_SORUMLUSU', 'AKTIF', 'Rebase Amir', 'x')");
    $pdo->exec("INSERT INTO sirketler (id, kod, ad, durum) VALUES (1, 'SRK1', 'Medisa', 'AKTIF')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id, durum) VALUES (1, 'MEDISA', 'SGK Medisa', 1, 'AKTIF')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id, durum) VALUES (1, 'SB1', 'Merkez', 1, 1, 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (1, 'CL1', 'Merkez Lokasyon', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Uretim', 'AKTIF')");
    $pdo->exec("INSERT INTO bolumler (id, departman_id, ad, durum) VALUES (1, 1, 'Bilgi Islem', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Operator', 'AKTIF'), (2, 'Yeni Gorev', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Mavi Yaka', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller (
            id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami,
            sube_id, departman_id, gorev_id, personel_tipi_id, sgk_isveren_id, calisma_lokasyonu_id, bagli_amir_id
        ) VALUES
            (160, 'Rebase', 'NullAmir', 'REBASE-160', '2024-01-01', 'AKTIF', 'IC_PERSONEL', 1, 1, 1, 1, 1, 1, NULL),
            (211, 'Rebase', 'EskiAmir', 'REBASE-211', '2024-01-01', 'AKTIF', 'IC_PERSONEL', 1, 1, 1, 1, 1, 1, 1)");

    $gm = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'username' => 'gm'];
    $request = new Request();

    // 1) Plan shape: the basic axis owns bagli_amir_id while the org axis preimage keeps its
    //    pre-basic value — the overlap that used to stall the row.
    $rows = [
        [
            'mutation_id' => 'mg-rebase-160',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 160,
            'gerekce' => 'MG multi axis preimage rebase 160',
            'payload' => ['bagli_amir' => 'Rebase Amir', 'gorev_id' => 2],
        ],
        [
            'mutation_id' => 'mg-rebase-211',
            'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
            'personel_id' => 211,
            'gerekce' => 'MG multi axis preimage rebase 211',
            'payload' => ['bagli_amir' => 'Rebase Amir', 'gorev_id' => 2],
        ],
    ];
    $dry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $rows, null, $deployedSha);
    $plan160 = $dry['satirlar'][0]['mutation_plan'] ?? [];
    $plan211 = $dry['satirlar'][1]['mutation_plan'] ?? [];
    mgRebaseAssert(($dry['can_apply'] ?? false) === true, 'rebase dry-run is can_apply');
    mgRebaseAssert(
        ($dry['satirlar'][0]['durum'] ?? '') === 'READY' && ($dry['satirlar'][1]['durum'] ?? '') === 'READY',
        'rebase rows are READY'
    );
    mgRebaseAssert(
        ($plan160['mode'] ?? '') === 'MULTI_AXIS'
            && ($plan211['mode'] ?? '') === 'MULTI_AXIS'
            && isset($plan160['axes']['basic'], $plan160['axes']['organization']),
        'both rows plan as MULTI_AXIS with basic and organization axes'
    );
    mgRebaseAssert(
        (int) ($plan160['axes']['basic']['payload']['bagli_amir_id'] ?? 0) === 2
            && (int) ($plan211['axes']['basic']['payload']['bagli_amir_id'] ?? 0) === 2,
        'basic axis carries the resolved manager for both rows'
    );
    mgRebaseAssert(
        array_key_exists('bagli_amir_id', $plan160['axes']['organization']['preimage'])
            && $plan160['axes']['organization']['preimage']['bagli_amir_id'] === null
            && (int) $plan211['axes']['organization']['preimage']['bagli_amir_id'] === 1,
        'org axis preimage still carries the pre-basic overlapping bagli_amir_id'
    );
    mgRebaseAssert(
        (int) ($plan211['axes']['organization']['targets']['gorev_id'] ?? 0) === 2,
        'org axis targets carry the tracked field change'
    );

    // 2) Apply: the overlapping preimage is rebased to the post-basic value inside the same
    //    transaction instead of failing with PERSONEL_ORGANIZASYON_STALE_PREIMAGE.
    $beforeAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $ledgerCountBefore = (int) $pdo->query(
        "SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key IN ('mg-rebase-160', 'mg-rebase-211')"
    )->fetchColumn();
    mgRebaseAssert($ledgerCountBefore === 0, 'preimage rebase starts from a clean idempotency ledger');
    $apply = PersonelLifecycleBulkApplyService::apply(
        $pdo,
        $gm,
        $request,
        $rows,
        (string) $dry['dry_run_checksum'],
        (string) $dry['preimage_checksum'],
        $deployedSha
    );
    mgRebaseAssert(
        ($apply['failed_count'] ?? -1) === 0 && ($apply['applied_count'] ?? -1) === 2,
        'overlapping multi-axis rows apply without PERSONEL_ORGANIZASYON_STALE_PREIMAGE'
    );
    mgRebaseAssert(
        ($apply['satir_sonuclari'][0]['durum'] ?? '') === 'APPLIED'
            && ($apply['satir_sonuclari'][1]['durum'] ?? '') === 'APPLIED',
        'both multi-axis rows report APPLIED'
    );
    mgRebaseAssert(
        ($apply['model'] ?? '') === 'INDEPENDENT_ROW_TRANSACTION',
        'multi-axis rows keep independent row transactions (no 160/211 split path)'
    );

    $after160 = mgRebasePersonelSubset($pdo, 160, 'bagli_amir_id, gorev_id, calisan_kapsami');
    $after211 = mgRebasePersonelSubset($pdo, 211, 'bagli_amir_id, gorev_id, calisan_kapsami');
    mgRebaseAssert(
        is_array($after160)
            && (int) $after160['bagli_amir_id'] === 2
            && (int) $after160['gorev_id'] === 2
            && (string) $after160['calisan_kapsami'] === 'IC_PERSONEL',
        'basic axis manager and org axis gorev land together for the NULL-preimage row'
    );
    mgRebaseAssert(
        is_array($after211)
            && (int) $after211['bagli_amir_id'] === 2
            && (int) $after211['gorev_id'] === 2
            && (string) $after211['calisan_kapsami'] === 'IC_PERSONEL',
        'basic axis manager and org axis gorev land together for the existing-manager row'
    );
    $afterAudit = (int) $pdo->query('SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri')->fetchColumn();
    $ledgerAfter = (int) $pdo->query(
        "SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key IN ('mg-rebase-160', 'mg-rebase-211')"
    )->fetchColumn();
    mgRebaseAssert(
        $afterAudit === $beforeAudit + 2 && $ledgerAfter === 2,
        'each row writes exactly one org audit and one idempotency ledger row in one transaction'
    );

    // 3) Fail-closed guard: a real preimage drift after dry-run must still block the row, so
    //    the rebase never turns into a blanket preimage refresh.
    $driftRows = [[
        'mutation_id' => 'mg-rebase-drift-211',
        'operation_type' => PersonelLifecycleBulkRowContract::OP_ORG_UPDATE,
        'personel_id' => 211,
        'gerekce' => 'MG multi axis drift guard 211',
        'payload' => ['bagli_amir' => 'Rebase Amir', 'gorev_id' => 1],
    ]];
    $driftDry = PersonelLifecycleBulkDryRunService::dryRun($pdo, $gm, $request, $driftRows, null, $deployedSha);
    mgRebaseAssert(
        ($driftDry['can_apply'] ?? false) === true
            && (($driftDry['satirlar'][0]['mutation_plan']['mode'] ?? '') === 'MULTI_AXIS'),
        'drift guard row plans as MULTI_AXIS before the external change'
    );
    $pdo->exec('UPDATE personeller SET bolum_id = 1 WHERE id = 211');
    $driftBlocked = false;
    try {
        PersonelLifecycleBulkApplyService::apply(
            $pdo,
            $gm,
            $request,
            $driftRows,
            (string) $driftDry['dry_run_checksum'],
            (string) $driftDry['preimage_checksum'],
            $deployedSha
        );
    } catch (PersonelImportException $e) {
        $driftBlocked = in_array($e->getCodeString(), ['DRY_RUN_STALE', 'PREIMAGE_STALE', 'CANNOT_APPLY'], true);
    }
    mgRebaseAssert($driftBlocked, 'external preimage drift after dry-run stays fail-closed');
    $driftState = mgRebasePersonelSubset($pdo, 211, 'bagli_amir_id, gorev_id');
    $driftLedger = (int) $pdo->query(
        "SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'mg-rebase-drift-211'"
    )->fetchColumn();
    mgRebaseAssert(
        is_array($driftState)
            && (int) $driftState['bagli_amir_id'] === 2
            && (int) $driftState['gorev_id'] === 2
            && $driftLedger === 0,
        'blocked drift row writes neither personel axes nor idempotency ledger'
    );

    echo "verify-mg-personnel-bulk-multi-axis-preimage-rebase-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
