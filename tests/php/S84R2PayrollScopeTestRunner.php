<?php

declare(strict_types=1);

/**
 * S84-R2 personel bordro kapsam acceptance runner (SQLite + source asserts).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\PersonelBordroKapsamService;

function s84r2Assert(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$name}\n");
}

function s84r2HasSqlite(): bool
{
    return extension_loaded('pdo_sqlite') || in_array('sqlite', PDO::getAvailableDrivers(), true);
}

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === '--overlap-probe')) {
    if (!s84r2HasSqlite()) {
        fwrite(STDERR, "sqlite driver missing\n");
        exit(2);
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            sube_id INTEGER NOT NULL,
            sicil_no TEXT,
            ad TEXT,
            soyad TEXT,
            aktif_durum TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE personel_bordro_kapsamlari (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id INTEGER NOT NULL,
            sube_id INTEGER NOT NULL,
            durum TEXT NOT NULL,
            neden_kodu TEXT NOT NULL,
            aciklama TEXT NOT NULL,
            gecerlilik_baslangic TEXT NOT NULL,
            gecerlilik_bitis TEXT,
            state TEXT NOT NULL,
            hazirlayan_id INTEGER,
            onaylayan_id INTEGER,
            onay_zamani TEXT,
            iptal_eden_id INTEGER,
            iptal_zamani TEXT,
            iptal_nedeni TEXT,
            parent_kapsam_id INTEGER,
            created_by INTEGER,
            created_at TEXT,
            updated_by INTEGER,
            updated_at TEXT
        )'
    );
    $pdo->exec("INSERT INTO personeller (id, sube_id, sicil_no, ad, soyad, aktif_durum)
                VALUES (1, 1, 'P-001', 'Ayşe', 'Yılmaz', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personel_bordro_kapsamlari (
            personel_id, sube_id, durum, neden_kodu, aciklama,
            gecerlilik_baslangic, gecerlilik_bitis, state
         ) VALUES (
            1, 1, 'HARIC', 'BORDRO_DISI_STATU', 'Mevcut onayli kapsam',
            '2026-01-01', NULL, 'ONAYLANDI'
         )"
    );

    $ref = new ReflectionClass(PersonelBordroKapsamService::class);
    $method = $ref->getMethod('assertNoOverlap');
    $method->setAccessible(true);
    ob_start();
    try {
        $method->invoke(
            null,
            $pdo,
            1,
            [
                'gecerlilik_baslangic' => '2026-03-01',
                'gecerlilik_bitis' => '2026-03-31',
                'durum' => 'HARIC',
            ],
            null
        );
    } finally {
        $output = (string) ob_get_clean();
    }
    if (strpos($output, 'KAPSAM_OVERLAP') === false) {
        fwrite(STDERR, "overlap probe missing KAPSAM_OVERLAP\n");
        exit(2);
    }
    exit(0);
}

s84r2Assert(
    PersonelBordroKapsamService::CONTRACT_VERSION === 'S84R2_PAYROLL_SCOPE_V1',
    'contract version S84R2'
);

$empty1 = PersonelBordroKapsamService::emptyScopeFingerprint();
$empty2 = PersonelBordroKapsamService::emptyScopeFingerprint();
s84r2Assert($empty1 === $empty2 && strlen($empty1) === 64, 'emptyScopeFingerprint stable');

$expectedEmpty = hash('sha256', json_encode([
    'contract' => PersonelBordroKapsamService::CONTRACT_VERSION,
    'rows' => [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
s84r2Assert($empty1 === $expectedEmpty, 'empty scope fingerprint formula');

if (s84r2HasSqlite()) {
    $pdoNoTable = new PDO('sqlite::memory:');
    $pdoNoTable->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $excluded = PersonelBordroKapsamService::listExcludedPersonelIds($pdoNoTable, 1, '2026-03-01', '2026-03-31');
    s84r2Assert($excluded === [], 'listExcludedPersonelIds empty without table');

    $scopeWithoutTable = PersonelBordroKapsamService::scopeFingerprintForPeriod(
        $pdoNoTable,
        1,
        '2026-03-01',
        '2026-03-31'
    );
    s84r2Assert($scopeWithoutTable === $empty1, 'empty scope does not force hash change formula');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            sube_id INTEGER NOT NULL,
            sicil_no TEXT,
            ad TEXT,
            soyad TEXT,
            aktif_durum TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE personel_bordro_kapsamlari (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id INTEGER NOT NULL,
            sube_id INTEGER NOT NULL,
            durum TEXT NOT NULL,
            neden_kodu TEXT NOT NULL,
            aciklama TEXT NOT NULL,
            gecerlilik_baslangic TEXT NOT NULL,
            gecerlilik_bitis TEXT,
            state TEXT NOT NULL,
            hazirlayan_id INTEGER,
            onaylayan_id INTEGER,
            onay_zamani TEXT,
            iptal_eden_id INTEGER,
            iptal_zamani TEXT,
            iptal_nedeni TEXT,
            parent_kapsam_id INTEGER,
            created_by INTEGER,
            created_at TEXT,
            updated_by INTEGER,
            updated_at TEXT
        )'
    );
    $pdo->exec("INSERT INTO personeller (id, sube_id, sicil_no, ad, soyad, aktif_durum)
                VALUES (1, 1, 'P-001', 'Ayşe', 'Yılmaz', 'AKTIF')");

    $dryRun = PersonelBordroKapsamService::dryRun($pdo, [
        'personel_id' => 1,
        'durum' => 'HARIC',
        'neden_kodu' => 'BORDRO_DISI_STATU',
        'aciklama' => 'Dry-run test kapsam',
        'gecerlilik_baslangic' => '2026-03-01',
        'gecerlilik_bitis' => '2026-03-31',
        'yil' => 2026,
        'ay' => 3,
    ], ['id' => 1, 'rol' => 'MUHASEBE']);
    s84r2Assert(($dryRun['write_performed'] ?? true) === false, 'dry-run write_performed false');
    s84r2Assert(isset($dryRun['dry_run_hash']) && strlen((string) $dryRun['dry_run_hash']) === 64, 'dry-run hash present');
    s84r2Assert(($dryRun['effects']['existing_snapshot_unchanged'] ?? false) === true, 'existing snapshot unchanged');
    s84r2Assert(($dryRun['effects']['would_exclude_from_new_snapshot'] ?? false) === true, 'would exclude from new snapshot');
    s84r2Assert(($dryRun['effects']['carryover_blocker_suppressed'] ?? false) === true, 'carryover blocker suppressed');
    s84r2Assert(($dryRun['effects']['candidate_item_excluded'] ?? false) === true, 'candidate item excluded');

    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmd = [PHP_BINARY, __FILE__, '--overlap-probe'];
    $process = proc_open($cmd, $descriptor, $pipes);
    s84r2Assert(is_resource($process), 'overlap probe process started');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    s84r2Assert($exitCode === 0, 'overlap reject (KAPSAM_OVERLAP)');
} else {
    fwrite(STDOUT, "[SKIP] sqlite driver missing — DB-backed scope checks skipped\n");
    $serviceSource = (string) file_get_contents(
        __DIR__ . '/../../api/src/Services/PersonelBordroKapsamService.php'
    );
    s84r2Assert(strpos($serviceSource, 'KAPSAM_OVERLAP') !== false, 'overlap reject present in source');
    s84r2Assert(strpos($serviceSource, "'write_performed' => false") !== false, 'dry-run write_performed false in source');
}

$snapshotSource = (string) file_get_contents(
    __DIR__ . '/../../api/src/Services/MaasHesaplamaSnapshotService.php'
);
s84r2Assert(
    strpos($snapshotSource, 'PAYROLL_SCOPE_EXCLUDED') !== false
        && strpos($snapshotSource, 'resolvePersonnelSet') !== false,
    'HARIC exclude path present in resolvePersonnelSet source'
);

s84r2Assert(
    RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'personel_bordro_kapsam.approve'),
    'GENEL_YONETICI has approve'
);
s84r2Assert(
    RolePermissions::has(['rol' => 'MUHASEBE'], 'personel_bordro_kapsam.manage')
        && !RolePermissions::has(['rol' => 'MUHASEBE'], 'personel_bordro_kapsam.approve'),
    'MUHASEBE has manage not approve'
);
s84r2Assert(
    !RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'personel_bordro_kapsam.view')
        && !RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'personel_bordro_kapsam.manage')
        && !RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'personel_bordro_kapsam.approve'),
    'BIRIM_AMIRI has neither manage nor approve'
);

fwrite(STDOUT, "S84R2 PHP runner OK\n");
