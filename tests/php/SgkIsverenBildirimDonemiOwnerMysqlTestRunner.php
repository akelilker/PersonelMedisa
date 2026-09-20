<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';
require_once __DIR__ . '/../../api/src/Services/SgkPrimGunuService.php';

use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\ResponseCaptured;
use Medisa\Api\Services\Payroll\SgkIsverenBildirimDonemiReadService;
use Medisa\Api\Services\Payroll\SgkSirketPolitikaReadService;
use Medisa\Api\Services\SgkPrimGunuService;

/**
 * SGK employer reporting-period canonical owner acceptance (A-P).
 *
 * Proves the reporting period is a FACTUAL employer configuration owned by the SGK
 * employer axis (personeller.sgk_isveren_id), never inferred from the branch, never
 * an approval workflow, while the legacy branch-scoped management policy and payroll
 * snapshot immutability stay untouched. Migration 091 reconciliation is proven
 * fail-closed and all-or-nothing per guard A-I.
 */

const PERIOD_MIGRATION_090 = '090_sgk_isveren_bildirim_donemi_owner.sql';
const PERIOD_MIGRATION_091 = '091_sgk_isveren_bildirim_donemi_reconcile.sql';
const PERIOD_LEGACY_CONSENSUS_KAYNAK = 'LEGACY_APPROVED_BRANCH_POLICY_CONSENSUS';

function periodPdo(): PDO
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

function periodPdoFor(string $database): PDO
{
    $dsn = (string) preg_replace(
        '/dbname=[^;]+/',
        'dbname=' . $database,
        getenv('MEDISA_TEST_MYSQL_DSN') ?: ''
    );

    return new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return array<int, string> */
function splitPeriodMigration(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) !== ';') {
            continue;
        }
        $statements[] = trim($buffer);
        $buffer = '';
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function applyPeriodMigration(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (splitPeriodMigration($sql) as $statement) {
        $trimmed = ltrim($statement);
        if (preg_match('/^EXECUTE\b/i', $trimmed) === 1) {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->fetchAll();
                $result->closeCursor();
            }
            continue;
        }
        $pdo->exec($statement);
    }
}

/**
 * Applies a migration exactly like MigrationRunner::applyOne does: one whole-file
 * exec wrapped in a transaction, tolerating an inner COMMIT.
 */
function applyPeriodMigrationWholeFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    $pdo->beginTransaction();
    $pdo->exec((string) $sql);
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
    $pdo->commit();
}

function periodAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function periodOk(): void
{
    echo 'verify-sgk-isveren-bildirim-donemi-owner-mysql: OK' . PHP_EOL;
}

/**
 * @param array<int, array<string, mixed>> $personeller keyed by personel id
 * @return array<string, mixed>
 */
function periodResolution(int $subeId, array $personeller): array
{
    return [
        'donem' => '2026-03',
        'donem_baslangic' => '2026-03-01',
        'donem_bitis' => '2026-03-31',
        'sube_id' => $subeId,
        'personeller' => $personeller,
        'izinler' => [],
        'attendance' => ['rows' => []],
        'legal' => [],
    ];
}

/** @return array<string, mixed> */
function periodPersonel(int $id, string $ad, ?int $sgkIsverenId): array
{
    return [
        'id' => $id,
        'ad_soyad' => $ad,
        'ucret_tipi_id' => 1,
        'istihdam_baslangic' => '2020-01-01',
        'sgk_isveren_id' => $sgkIsverenId,
    ];
}

/** @return list<string> */
function periodBlockerCodes(array $resolution, int $personelId): array
{
    $result = $resolution['results_by_personel'][$personelId] ?? [];

    return array_map('strval', $result['blocker_kodlari'] ?? []);
}

/**
 * Canonical FACTUAL employer reporting period row. There is no approval workflow on
 * this owner: state is DOGRULANMADI / DOGRULANDI / IPTAL and only DOGRULANDI is
 * effective. dogrulama_zamani is the factual verification timestamp.
 */
function periodInsertEmployerPeriod(
    PDO $pdo,
    int $sgkIsverenId,
    string $surumKodu,
    string $tip,
    string $baslangic,
    ?string $bitis,
    string $state
): void {
    $dogrulayan = $state === 'DOGRULANDI' ? 2 : null;
    $dogrulama = $state === 'DOGRULANDI' ? '2026-01-02 00:00:00' : null;
    $stmt = $pdo->prepare(
        'INSERT INTO sgk_isveren_bildirim_donemi_surumleri (
            sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic, gecerlilik_bitis,
            state, dogrulama_kaynagi, dogrulama_kanit_hash, aciklama, dogrulayan_id, dogrulama_zamani
         ) VALUES (
            :isveren, :surum, :tip, :baslangic, :bitis, :state, :kaynak, :kanit, :aciklama, :dogrulayan, :dogrulama
         )'
    );
    $stmt->execute([
        'isveren' => $sgkIsverenId,
        'surum' => $surumKodu,
        'tip' => $tip,
        'baslangic' => $baslangic,
        'bitis' => $bitis,
        'state' => $state,
        'kaynak' => 'EXPLICIT_EMPLOYER_PERIOD',
        'kanit' => str_repeat('d', 64),
        'aciklama' => 'acceptance ' . $surumKodu,
        'dogrulayan' => $dogrulayan,
        'dogrulama' => $dogrulama,
    ]);
}

/**
 * Runs migration 090 against its own disposable database so a drifted
 * pre-existing table can be observed before the migration runs.
 *
 * @return array{blocked: bool, message: string|null, table: bool, columns: int}
 */
function periodGuardRun(PDO $root, ?string $preExistingDdl, int $applyCount = 1): array
{
    $database = 'medisa_sgk_guard_' . bin2hex(random_bytes(5));
    $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = periodPdoFor($database);
    try {
        $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(80) NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE sgk_isverenler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(64) NULL, ad VARCHAR(191) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(120) NOT NULL) ENGINE=InnoDB');
        if ($preExistingDdl !== null) {
            $pdo->exec($preExistingDdl);
        }
        $blocked = false;
        $message = null;
        for ($i = 0; $i < $applyCount; $i++) {
            try {
                applyPeriodMigration($pdo, PERIOD_MIGRATION_090);
            } catch (PDOException $e) {
                $blocked = true;
                $message = $e->getMessage();
                break;
            }
        }
        $table = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'")->fetchColumn() === 1;
        $columns = $table
            ? (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'")->fetchColumn()
            : 0;

        return ['blocked' => $blocked, 'message' => $message, 'table' => $table, 'columns' => $columns];
    } finally {
        periodRelease($pdo);
        $root->exec("DROP DATABASE IF EXISTS `$database`");
    }
}

/**
 * 091 reconciliation scenario on its own disposable database.
 *
 * @param list<array{id:int,kod:string,ad:string,durum:string}> $employers
 * @param list<array{id:int,ad:string,sgk_isveren_id:int|null}> $branches
 * @param list<array{sube_id:int,surum_kodu:string,tip:string,baslangic:string,bitis:string|null,state:string}> $policies
 * @param list<array<string,mixed>> $preExistingCanonical
 * @return array{blocked:bool,message:string|null,rows:list<array<string,mixed>>,row_count:int,read:array<int,array<string,mixed>>,first_apply_rows:list<array<string,mixed>>}
 */
function periodReconcileRun(
    PDO $root,
    array $employers,
    array $branches,
    array $policies,
    array $preExistingCanonical = [],
    int $applyCount = 1,
    string $mode = 'split'
): array {
    $database = 'medisa_sgk_reconcile_' . bin2hex(random_bytes(5));
    $root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = periodPdoFor($database);
    try {
        $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(80) NULL) ENGINE=InnoDB');
        $pdo->exec("CREATE TABLE sgk_isverenler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(64) NULL, ad VARCHAR(191) NOT NULL, durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF') ENGINE=InnoDB");
        $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(120) NOT NULL, sgk_isveren_id INT UNSIGNED NULL) ENGINE=InnoDB');
        $pdo->exec("CREATE TABLE sgk_sirket_politika_surumleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            surum_kodu VARCHAR(80) NOT NULL,
            gecerlilik_baslangic DATE NOT NULL,
            gecerlilik_bitis DATE NULL,
            bildirim_donem_tipi ENUM('AY_1_SON_GUN','AY_15_SONRAKI_AY_14') NOT NULL,
            state ENUM('TASLAK','ONAY_BEKLIYOR','ONAYLANDI','IPTAL') NOT NULL DEFAULT 'TASLAK',
            politika_hash CHAR(64) NOT NULL,
            aciklama VARCHAR(1000) NOT NULL,
            hazirlayan_id INT UNSIGNED NULL,
            onaylayan_id INT UNSIGNED NULL,
            onay_zamani DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        if ($mode === 'whole') {
            applyPeriodMigrationWholeFile($pdo, PERIOD_MIGRATION_090);
        } else {
            applyPeriodMigration($pdo, PERIOD_MIGRATION_090);
        }

        $pdo->exec("INSERT INTO users (id, ad) VALUES (2, 'Verifier')");
        foreach ($employers as $employer) {
            $stmt = $pdo->prepare('INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (:id, :kod, :ad, :durum)');
            $stmt->execute(['id' => $employer['id'], 'kod' => $employer['kod'], 'ad' => $employer['ad'], 'durum' => $employer['durum']]);
        }
        foreach ($branches as $branch) {
            $stmt = $pdo->prepare('INSERT INTO subeler (id, ad, sgk_isveren_id) VALUES (:id, :ad, :sgk)');
            $stmt->execute(['id' => $branch['id'], 'ad' => $branch['ad'], 'sgk' => $branch['sgk_isveren_id']]);
        }
        $policyHash = str_repeat('a', 64);
        foreach ($policies as $policy) {
            $stmt = $pdo->prepare(
                'INSERT INTO sgk_sirket_politika_surumleri
                    (sube_id, surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, bildirim_donem_tipi, state, politika_hash, aciklama)
                 VALUES (:sube, :surum, :baslangic, :bitis, :tip, :state, :hash, :aciklama)'
            );
            $stmt->execute([
                'sube' => $policy['sube_id'],
                'surum' => $policy['surum_kodu'],
                'baslangic' => $policy['baslangic'],
                'bitis' => $policy['bitis'],
                'tip' => $policy['tip'],
                'state' => $policy['state'],
                'hash' => $policyHash,
                'aciklama' => 'legacy ' . $policy['surum_kodu'],
            ]);
        }
        foreach ($preExistingCanonical as $row) {
            $stmt = $pdo->prepare(
                'INSERT INTO sgk_isveren_bildirim_donemi_surumleri
                    (sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic, gecerlilik_bitis,
                     state, dogrulama_kaynagi, dogrulama_kanit_hash, aciklama, dogrulayan_id, dogrulama_zamani)
                 VALUES (:isveren, :surum, :tip, :baslangic, :bitis, :state, :kaynak, :kanit, :aciklama, NULL, :dogrulama)'
            );
            $stmt->execute([
                'isveren' => $row['sgk_isveren_id'],
                'surum' => $row['surum_kodu'],
                'tip' => $row['bildirim_donem_tipi'],
                'baslangic' => $row['gecerlilik_baslangic'],
                'bitis' => $row['gecerlilik_bitis'],
                'state' => $row['state'],
                'kaynak' => $row['dogrulama_kaynagi'],
                'kanit' => $row['dogrulama_kanit_hash'],
                'aciklama' => $row['aciklama'] ?? 'pre-existing canonical row',
                'dogrulama' => $row['dogrulama_zamani'] ?? '2026-01-05 00:00:00',
            ]);
        }

        $blocked = false;
        $message = null;
        $firstApplyRows = [];
        for ($i = 0; $i < $applyCount; $i++) {
            try {
                if ($mode === 'whole') {
                    applyPeriodMigrationWholeFile($pdo, PERIOD_MIGRATION_091);
                } else {
                    applyPeriodMigration($pdo, PERIOD_MIGRATION_091);
                }
            } catch (PDOException $e) {
                $blocked = true;
                $message = $e->getMessage();
                break;
            }
            if ($i === 0) {
                $firstApplyRows = periodCanonicalRows($pdo);
            }
        }

        $rows = periodCanonicalRows($pdo);
        $read = [];
        foreach ($employers as $employer) {
            $read[(int) $employer['id']] = SgkIsverenBildirimDonemiReadService::resolveForPeriod(
                $pdo,
                (int) $employer['id'],
                '2026-03-01',
                '2026-03-31'
            );
        }
        $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM sgk_isveren_bildirim_donemi_surumleri')->fetchColumn();

        return [
            'blocked' => $blocked,
            'message' => $message,
            'rows' => $rows,
            'row_count' => $rowCount,
            'read' => $read,
            'first_apply_rows' => $firstApplyRows,
        ];
    } finally {
        periodRelease($pdo);
        $root->exec("DROP DATABASE IF EXISTS `$database`");
    }
}

/**
 * A guard SIGNAL aborts migration 091 in the middle of its own transaction, so the
 * scenario connection can still hold an open transaction (and therefore metadata
 * locks). Roll it back before the disposable database is dropped.
 */
function periodRelease(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        // already closed / rolled back
    }
    try {
        $pdo->exec('ROLLBACK');
    } catch (Throwable $e) {
        // no transaction open
    }
}

/** @return list<array<string,mixed>> */
function periodCanonicalRows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic, gecerlilik_bitis,
                state, dogrulama_kaynagi, dogrulama_kanit_hash, aciklama, dogrulayan_id, dogrulama_zamani
         FROM sgk_isveren_bildirim_donemi_surumleri
         ORDER BY sgk_isveren_id, id'
    )->fetchAll();
}

/** @param list<array<string,mixed>> $rows */
function periodRowFor(array $rows, int $sgkIsverenId): ?array
{
    foreach ($rows as $row) {
        if ((int) $row['sgk_isveren_id'] === $sgkIsverenId) {
            return $row;
        }
    }

    return null;
}

function periodBlockedOn(array $result, string $marker = 'PACK091_BLOCKER'): bool
{
    return $result['blocked'] === true && strpos((string) $result['message'], $marker) !== false;
}

// ---------------------------------------------------------------------------
// Scenario fixtures (derived ONLY from live rows; nothing employer-specific is
// written into the migration itself)
// ---------------------------------------------------------------------------

$employersBase = [
    ['id' => 1, 'kod' => 'EMP1', 'ad' => 'Isveren Bir', 'durum' => 'AKTIF'],
    ['id' => 2, 'kod' => 'EMP2', 'ad' => 'Isveren Iki', 'durum' => 'AKTIF'],
];
$branchesBase = [
    ['id' => 1, 'ad' => 'Sube Bir', 'sgk_isveren_id' => 1],
    ['id' => 2, 'ad' => 'Sube Iki', 'sgk_isveren_id' => 1],
    ['id' => 3, 'ad' => 'Sube Uc', 'sgk_isveren_id' => 1],
    ['id' => 4, 'ad' => 'Sube Dort', 'sgk_isveren_id' => 2],
];
$policiesBase = [
    ['sube_id' => 1, 'surum_kodu' => 'B1', 'tip' => 'AY_1_SON_GUN', 'baslangic' => '2024-01-01', 'bitis' => null, 'state' => 'ONAYLANDI'],
    ['sube_id' => 2, 'surum_kodu' => 'B2', 'tip' => 'AY_1_SON_GUN', 'baslangic' => '2024-01-01', 'bitis' => null, 'state' => 'ONAYLANDI'],
    ['sube_id' => 3, 'surum_kodu' => 'B3-TASLAK', 'tip' => 'AY_15_SONRAKI_AY_14', 'baslangic' => '2024-01-01', 'bitis' => null, 'state' => 'TASLAK'],
    ['sube_id' => 4, 'surum_kodu' => 'B4', 'tip' => 'AY_15_SONRAKI_AY_14', 'baslangic' => '2024-01-01', 'bitis' => null, 'state' => 'ONAYLANDI'],
];

/** Excludes ignored because of an unapproved state at the given index. */
function periodPolicyWith(array $policies, int $index, array $overrides): array
{
    $policies[$index] = array_merge($policies[$index], $overrides);

    return $policies;
}

$root = periodPdo();
$database = 'medisa_sgk_period_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = periodPdoFor($database);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(120) NOT NULL, sgk_isveren_id INT UNSIGNED NULL) ENGINE=InnoDB');
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(80) NOT NULL,
            soyad VARCHAR(80) NULL,
            tc_kimlik_no VARCHAR(11) NULL,
            dogum_tarihi DATE NULL,
            telefon VARCHAR(20) NULL,
            calisan_kapsami VARCHAR(16) NOT NULL DEFAULT 'IC_PERSONEL',
            sgk_isveren_id INT UNSIGNED NULL
         ) ENGINE=InnoDB"
    );
    $pdo->exec("CREATE TABLE sgk_isverenler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(64) NULL, ad VARCHAR(191) NOT NULL, durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF') ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE surecler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO users (id, ad) VALUES (1, 'Dogrulayan'), (2, 'Dogrulayan 2')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad) VALUES (1, 'EMP1', 'Isveren Bir'), (2, 'EMP2', 'Isveren Iki'), (3, 'EMP3', 'Isveren Uc')");
    $pdo->exec("INSERT INTO subeler (id, ad, sgk_isveren_id) VALUES (1, 'Sube Bir', 1), (2, 'Sube Iki', 1), (3, 'Sube Uc', 1), (4, 'Sube Dort', 2)");
    $pdo->exec('INSERT INTO maas_hesaplama_donem_snapshotlari (id) VALUES (10)');
    $pdo->exec('INSERT INTO maas_hesaplama_personel_snapshotlari (id) VALUES (20)');
    $pdo->exec(
        "INSERT INTO personeller (id, ad, sgk_isveren_id) VALUES
            (101, 'Bir Personel', 1),
            (102, 'Bir Personel 2', 1),
            (103, 'Iki Personel', 2),
            (104, 'Dizin Personel', 1),
            (107, 'Isverensiz Personel', NULL)"
    );

    // 036 is the legacy branch-scoped SGK owner + immutable snapshot schema.
    applyPeriodMigration($pdo, '036_sgk_prim_gunu_owner.sql');
    // 090 is the additive employer-scoped FACTUAL reporting-period owner.
    applyPeriodMigration($pdo, PERIOD_MIGRATION_090);
    applyPeriodMigration($pdo, PERIOD_MIGRATION_090);

    periodAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'")->fetchColumn() === 1,
        '090 additive tablo mevcut ve ikinci apply idempotent'
    );
    periodAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM sgk_isveren_bildirim_donemi_surumleri')->fetchColumn() === 0,
        '090 production veri seed etmedi'
    );
    periodAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_sirket_politika_surumleri' AND COLUMN_NAME = 'bildirim_donem_tipi'")->fetchColumn() === 1,
        '036 legacy branch policy owner destructive degismedi'
    );

    // --- P) 090 fail-closed canonical factual schema guard (disposable DB per scenario) ---
    $guardCanonicalColumns = "
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sgk_isveren_id INT UNSIGNED NOT NULL,
        surum_kodu VARCHAR(80) NOT NULL,
        bildirim_donem_tipi ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14') NOT NULL,
        gecerlilik_baslangic DATE NOT NULL,
        gecerlilik_bitis DATE NULL,
        state ENUM('DOGRULANMADI', 'DOGRULANDI', 'IPTAL') NOT NULL DEFAULT 'DOGRULANMADI',
        dogrulama_kaynagi VARCHAR(64) NOT NULL,
        dogrulama_kanit_hash CHAR(64) NOT NULL,
        aciklama VARCHAR(1000) NOT NULL,
        dogrulayan_id INT UNSIGNED NULL,
        dogrulama_zamani DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)";
    $guardEmployerFk = ', CONSTRAINT fk_sgk_ibds_isveren FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id)';
    $guardDdl = static function (string $columns, string $extra = ''): string {
        return 'CREATE TABLE sgk_isveren_bildirim_donemi_surumleri (' . $columns . $extra
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    };
    $guardBlocked = static function (array $result): bool {
        return $result['blocked'] === true && strpos((string) $result['message'], 'PACK090_BLOCKER') !== false;
    };

    $guardA = periodGuardRun($root, null, 1);
    periodAssert($guardA['blocked'] === false && $guardA['table'] === true && $guardA['columns'] === 13, 'P MIG-A clean factual 090 apply PASS');
    $guardB = periodGuardRun($root, null, 2);
    periodAssert($guardB['blocked'] === false && $guardB['table'] === true && $guardB['columns'] === 13, 'P MIG-B second apply PASS');
    $guardC = periodGuardRun($root, $guardDdl($guardCanonicalColumns, $guardEmployerFk), 1);
    periodAssert($guardC['blocked'] === false && $guardC['table'] === true, 'P MIG-C pre-existing canonical factual table PASS');

    $partialColumns = (string) preg_replace('/^\s*sgk_isveren_id INT UNSIGNED NOT NULL,\s*$/m', '', $guardCanonicalColumns);
    $guardD = periodGuardRun($root, $guardDdl($partialColumns), 1);
    periodAssert($guardBlocked($guardD) && $guardD['table'] === true, 'P MIG-D partial table missing sgk_isveren_id BLOCKED');

    $wrongEnumColumns = str_replace("ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14')", "ENUM('AY_1_SON_GUN')", $guardCanonicalColumns);
    $guardE = periodGuardRun($root, $guardDdl($wrongEnumColumns, $guardEmployerFk), 1);
    periodAssert($guardBlocked($guardE), 'P MIG-E wrong bildirim_donem_tipi enum BLOCKED');

    $guardF = periodGuardRun($root, $guardDdl($guardCanonicalColumns), 1);
    periodAssert($guardBlocked($guardF), 'P MIG-F missing employer FK BLOCKED');

    $guardF2 = periodGuardRun(
        $root,
        $guardDdl($guardCanonicalColumns, ', CONSTRAINT fk_sgk_ibds_isveren FOREIGN KEY (sgk_isveren_id) REFERENCES subeler (id)'),
        1
    );
    periodAssert($guardBlocked($guardF2), 'P MIG-F2 wrong employer FK BLOCKED');

    $approvalColumns = str_replace(
        '        dogrulama_kaynagi VARCHAR(64) NOT NULL,',
        "        dogrulama_kaynagi VARCHAR(64) NOT NULL,\n        hazirlayan_id INT UNSIGNED NULL,\n        onaylayan_id INT UNSIGNED NULL,",
        $guardCanonicalColumns
    );
    $guardG = periodGuardRun($root, $guardDdl($approvalColumns), 1);
    periodAssert($guardBlocked($guardG), 'P MIG-G leftover approval-workflow column BLOCKED');

    $wrongStateColumns = str_replace(
        ["ENUM('DOGRULANMADI', 'DOGRULANDI', 'IPTAL')", "DEFAULT 'DOGRULANMADI'"],
        ["ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL')", "DEFAULT 'TASLAK'"],
        $guardCanonicalColumns
    );
    $guardH = periodGuardRun($root, $guardDdl($wrongStateColumns, $guardEmployerFk), 1);
    periodAssert($guardBlocked($guardH), 'P MIG-H approval-shaped state enum BLOCKED');

    // -----------------------------------------------------------------------
    // 091 reconciliation — consensus and happy path (A, B, C, D)
    // -----------------------------------------------------------------------
    $base = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase);
    periodAssert($base['blocked'] === false, 'D consensus reconciliation bloklanmadi');
    periodAssert($base['row_count'] === 2, 'A ayni employer icin tek canonical satir (branch basina ayri satir yok)');
    $rowEmp1 = periodRowFor($base['rows'], 1);
    $rowEmp2 = periodRowFor($base['rows'], 2);
    periodAssert($rowEmp1 !== null && $rowEmp2 !== null, 'D her proven employer icin canonical factual satir uretildi');
    periodAssert(($rowEmp1['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'D emp1 consensus AY_1_SON_GUN dogrulandi');
    periodAssert(($rowEmp2['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14', 'C farkli employer farkli legal donem kullanabilir');
    periodAssert(($rowEmp1['state'] ?? null) === 'DOGRULANDI', 'D canonical factual state DOGRULANDI');
    periodAssert(($rowEmp1['dogrulama_kaynagi'] ?? null) === PERIOD_LEGACY_CONSENSUS_KAYNAK, 'D dogrulama_kaynagi legacy consensus');
    periodAssert(
        preg_match('/^[0-9a-f]{64}$/', (string) ($rowEmp1['dogrulama_kanit_hash'] ?? '')) === 1,
        'D dogrulama_kanit_hash deterministic canonicalized source evidence'
    );
    periodAssert($rowEmp1['dogrulayan_id'] === null, 'K dogrulayan_id NULL (actor uydurulmaz)');
    periodAssert(
        ($base['read'][1]['state'] ?? '') === SgkIsverenBildirimDonemiReadService::STATE_DOGRULANDI
            && ($base['read'][1]['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN',
        'D reconcile edilmis employer dogrulandi olarak efektif'
    );
    periodAssert(
        strpos((string) ($rowEmp1['aciklama'] ?? ''), '2 authoritative legacy source row(s)') !== false,
        'B ayni employer consensus iki legacy branch satirindan turetildi (branch 3 false gap uretmez)'
    );

    // -----------------------------------------------------------------------
    // E) conflicting period values -> BLOCK / zero insert
    // -----------------------------------------------------------------------
    $conflict = periodReconcileRun(
        $root,
        $employersBase,
        $branchesBase,
        periodPolicyWith($policiesBase, 1, ['tip' => 'AY_15_SONRAKI_AY_14'])
    );
    periodAssert(periodBlockedOn($conflict) && $conflict['row_count'] === 0, 'E celisen bildirim_donem_tipi BLOCK / zero insert');

    // -----------------------------------------------------------------------
    // F) missing employer source -> BLOCK / zero insert
    // -----------------------------------------------------------------------
    $employersMissing = $employersBase;
    $employersMissing[] = ['id' => 3, 'kod' => 'EMP3', 'ad' => 'Isveren Uc', 'durum' => 'AKTIF'];
    $branchesMissing = $branchesBase;
    $branchesMissing[] = ['id' => 5, 'ad' => 'Sube Bes', 'sgk_isveren_id' => 3];
    $missingSource = periodReconcileRun($root, $employersMissing, $branchesMissing, $policiesBase);
    periodAssert(periodBlockedOn($missingSource) && $missingSource['row_count'] === 0, 'F legacy kaynagi olmayan aktif employer BLOCK / zero insert');

    // -----------------------------------------------------------------------
    // G) ambiguous validity interval -> BLOCK / zero insert
    // -----------------------------------------------------------------------
    $ambiguous = periodReconcileRun(
        $root,
        $employersBase,
        $branchesBase,
        periodPolicyWith($policiesBase, 1, ['baslangic' => '2024-06-01'])
    );
    periodAssert(periodBlockedOn($ambiguous) && $ambiguous['row_count'] === 0, 'G belirsiz gecerlilik araligi BLOCK / zero insert');

    // -----------------------------------------------------------------------
    // H) bad branch -> employer mapping -> BLOCK / zero insert
    // -----------------------------------------------------------------------
    $branchesOrphan = $branchesBase;
    $branchesOrphan[3] = ['id' => 4, 'ad' => 'Sube Dort', 'sgk_isveren_id' => 999];
    $badMapping = periodReconcileRun(
        $root,
        [['id' => 1, 'kod' => 'EMP1', 'ad' => 'Isveren Bir', 'durum' => 'AKTIF']],
        $branchesOrphan,
        $policiesBase
    );
    periodAssert(periodBlockedOn($badMapping) && $badMapping['row_count'] === 0, 'H gecersiz sube -> employer mapping BLOCK / zero insert');

    $branchesNull = $branchesBase;
    $branchesNull[3] = ['id' => 4, 'ad' => 'Sube Dort', 'sgk_isveren_id' => null];
    $nullMapping = periodReconcileRun(
        $root,
        [['id' => 1, 'kod' => 'EMP1', 'ad' => 'Isveren Bir', 'durum' => 'AKTIF']],
        $branchesNull,
        $policiesBase
    );
    periodAssert(periodBlockedOn($nullMapping) && $nullMapping['row_count'] === 0, 'H NULL employer mapping BLOCK / zero insert');

    // -----------------------------------------------------------------------
    // I) pre-existing contradictory canonical row -> BLOCK / zero insert
    // -----------------------------------------------------------------------
    $contradictory = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase, [[
        'sgk_isveren_id' => 1,
        'surum_kodu' => 'MANUAL-PERIOD-2024',
        'bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14',
        'gecerlilik_baslangic' => '2024-01-01',
        'gecerlilik_bitis' => null,
        'state' => 'DOGRULANDI',
        'dogrulama_kaynagi' => 'MANUAL_VERIFY',
        'dogrulama_kanit_hash' => str_repeat('e', 64),
    ]]);
    periodAssert(periodBlockedOn($contradictory) && $contradictory['row_count'] === 1, 'I celisen mevcut canonical truth BLOCK / zero yeni insert');

    // -----------------------------------------------------------------------
    // J) exact replay / exact compatible pre-existing truth -> safe, no duplicate
    // -----------------------------------------------------------------------
    $replay = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase, [], 2);
    periodAssert($replay['blocked'] === false && $replay['row_count'] === 2, 'J exact replay guvenli ve duplicate uretmez');
    periodAssert(
        ($replay['rows'][0]['id'] ?? null) === ($replay['first_apply_rows'][0]['id'] ?? null)
            && ($replay['rows'][0]['dogrulama_zamani'] ?? null) === ($replay['first_apply_rows'][0]['dogrulama_zamani'] ?? null),
        'J replay mevcut canonical truth satirini degistirmez'
    );

    $exactPreExisting = [];
    foreach ($base['rows'] as $row) {
        $exactPreExisting[] = [
            'sgk_isveren_id' => (int) $row['sgk_isveren_id'],
            'surum_kodu' => (string) $row['surum_kodu'],
            'bildirim_donem_tipi' => (string) $row['bildirim_donem_tipi'],
            'gecerlilik_baslangic' => (string) $row['gecerlilik_baslangic'],
            'gecerlilik_bitis' => $row['gecerlilik_bitis'],
            'state' => (string) $row['state'],
            'dogrulama_kaynagi' => (string) $row['dogrulama_kaynagi'],
            'dogrulama_kanit_hash' => (string) $row['dogrulama_kanit_hash'],
        ];
    }
    $compatible = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase, $exactPreExisting);
    periodAssert($compatible['blocked'] === false && $compatible['row_count'] === 2, 'J exact compatible mevcut truth guvenli / duplicate yok');

    // -----------------------------------------------------------------------
    // Runner parity: 091 must behave identically through the whole-file
    // MigrationRunner path (single multi-statement exec with inner COMMIT).
    // -----------------------------------------------------------------------
    $whole = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase, [], 1, 'whole');
    periodAssert($whole['blocked'] === false && $whole['row_count'] === 2, 'P 091 whole-file runner path PASS');
    $wholeReplay = periodReconcileRun($root, $employersBase, $branchesBase, $policiesBase, [], 2, 'whole');
    periodAssert($wholeReplay['blocked'] === false && $wholeReplay['row_count'] === 2, 'P 091 whole-file replay PASS');

    // -----------------------------------------------------------------------
    // K) factual state only — no approval workflow semantics anywhere
    // -----------------------------------------------------------------------
    $migration090 = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . PERIOD_MIGRATION_090);
    $migration091 = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . PERIOD_MIGRATION_091);
    $readService = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/SgkIsverenBildirimDonemiReadService.php');
    foreach ([$migration090, $readService] as $source) {
        periodAssert(
            strpos($source, 'ONAYLANDI') === false
                && strpos($source, 'ONAY_BEKLIYOR') === false
                && strpos($source, 'TASLAK') === false,
            'K yeni canonical owner approval state semantigi icermez'
        );
    }
    periodAssert(
        strpos($migration090, "ENUM('DOGRULANMADI', 'DOGRULANDI', 'IPTAL')") !== false,
        'K canonical factual state enum DOGRULANMADI / DOGRULANDI / IPTAL'
    );
    periodAssert(
        strpos($migration090, "'hazirlayan_id', 'onaylayan_id', 'onay_zamani'") !== false,
        'K 090 leftover approval kolonlarini fail-closed reddeder'
    );
    periodAssert(
        strpos($readService, 'STATE_DOGRULANDI') !== false && strpos($readService, "state = 'DOGRULANDI'") !== false,
        'K read owner yalnizca DOGRULANDI satirlarini efektif sayar'
    );
    $canonicalInsertAt = strpos($migration091, 'INSERT INTO sgk_isveren_bildirim_donemi_surumleri');
    $canonicalInsert = $canonicalInsertAt === false ? '' : substr($migration091, $canonicalInsertAt);
    periodAssert(
        $canonicalInsert !== ''
            && strpos($canonicalInsert, 'hazirlayan_id') === false
            && strpos($canonicalInsert, 'onaylayan_id') === false
            && strpos($canonicalInsert, 'onay_zamani') === false
            && strpos($canonicalInsert, "'DOGRULANDI'") !== false
            && strpos($canonicalInsert, 'UTC_TIMESTAMP()') !== false,
        'K 091 canonical insert approval kolonu eklemez, factual DOGRULANDI yazar'
    );
    periodAssert(
        strpos($migration091, "WHERE p.state = 'ONAYLANDI'") !== false
            && substr_count($migration091, "'ONAYLANDI'") === 1
            && strpos($migration091, "'TASLAK'") === false
            && strpos($migration091, "'ONAY_BEKLIYOR'") === false,
        'K 091 legacy ONAYLANDI yalnizca legacy kaynak filtresi olarak kullanir'
    );
    periodAssert(
        strpos($migration091, "@p091_canonical_approval") !== false
            && strpos($migration091, "COLUMN_NAME IN ('hazirlayan_id', 'onaylayan_id', 'onay_zamani')") !== false,
        'K 091 approval kolonlarini yalnizca canonical schema guard icinde reddeder'
    );
    periodAssert(
        strpos($migration091, 'AY_1_SON_GUN') === false
            && strpos($migration091, 'AY_15_SONRAKI_AY_14') === false
            && strpos($migration091, "COLUMN_NAME = 'bildirim_donem_tipi'") !== false,
        'K 091 legal donem secimini hardcode etmez, canonical enum uzerinden dogrular'
    );
    periodAssert(
        preg_match('/sgk_isveren_id\s*=\s*\d+/', $migration091) === 0
            && preg_match('/sube_id\s*=\s*\d+/', $migration091) === 0
            && stripos($migration091, 'Karyap') === false
            && stripos($migration091, 'Medisa') === false
            && stripos($migration091, 'enay') === false,
        'K 091 hardcoded employer/branch/sirket varsayimi icermez'
    );
    periodAssert(
        preg_match('/\b(19|20)\d{2}-\d{2}-\d{2}\b/', $migration091) === 0,
        'K 091 hardcoded gecerlilik tarihi icermez'
    );
    periodAssert(
        strpos($migration091, PERIOD_LEGACY_CONSENSUS_KAYNAK) !== false,
        'K 091 factual dogrulama kaynagi legacy consensus'
    );

    // Legacy personnel-status reporting-period override. It must never become the
    // runtime reporting period now that the employer axis owns it.
    $pdo->exec(
        "INSERT INTO sgk_personel_sigortalilik_surumleri (
            personel_id, sigortalilik_statusu, sozlesme_turu, bildirim_donem_tipi,
            gecerlilik_baslangic, gecerlilik_bitis, state, aciklama, onaylayan_id, onay_zamani
         ) VALUES (107, '4A', 'TAM_SURELI', 'AY_15_SONRAKI_AY_14', '2024-01-01', NULL,
            'ONAYLANDI', 'legacy personnel period override', 2, '2026-01-02 00:00:00')"
    );

    // Employer reporting-period truth (explicit, FACTUAL). Unverified rows are excluded.
    periodInsertEmployerPeriod($pdo, 1, 'EMP1-2026', 'AY_1_SON_GUN', '2024-01-01', null, 'DOGRULANDI');
    periodInsertEmployerPeriod($pdo, 1, 'EMP1-2026-DOGRULANMADI', 'AY_15_SONRAKI_AY_14', '2024-01-01', null, 'DOGRULANMADI');
    periodInsertEmployerPeriod($pdo, 2, 'EMP2-2026', 'AY_15_SONRAKI_AY_14', '2024-01-01', null, 'DOGRULANDI');

    // Legacy branch-scoped management policy for branch 1 (different period + policy value).
    $policyHash = str_repeat('a', 64);
    $pdo->exec(
        "INSERT INTO sgk_sirket_politika_surumleri (
            sube_id, surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, bildirim_donem_tipi,
            state, politika_hash, aciklama, onaylayan_id, onay_zamani
         ) VALUES (1, 'BRANCH1-2026', '2024-01-01', NULL, 'AY_15_SONRAKI_AY_14',
            'ONAYLANDI', '$policyHash', 'legacy branch policy', 2, '2026-01-02 00:00:00')"
    );
    $branchPolicyId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO sgk_sirket_politika_degerleri (politika_surum_id, politika_kodu, deger_turu, deger)
         VALUES ($branchPolicyId, 'SGK_ODENEK_MAHSUP_MODU', 'ENUM', 'UCRET_MODELINE_GORE')"
    );

    // A) same employer, two branches -> one employer owner, same reporting period.
    $resAll = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        101 => periodPersonel(101, 'Bir Personel', 1),
        102 => periodPersonel(102, 'Bir Personel 2', 1),
    ]));
    $cozumA1 = $resAll['bildirim_donem_cozumlemesi'][101] ?? [];
    $cozumA2 = $resAll['bildirim_donem_cozumlemesi'][102] ?? [];
    periodAssert(($cozumA1['kaynak'] ?? '') === 'SGK_ISVEREN' && ($cozumA2['kaynak'] ?? '') === 'SGK_ISVEREN', 'A ayni employer iki branch SGK_ISVEREN eksenini kullanir');
    periodAssert(($cozumA1['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN' && ($cozumA2['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'A iki branch ayni employer donemini gorur');
    periodAssert(($cozumA1['sgk_isveren_id'] ?? null) === 1 && ($cozumA2['sgk_isveren_id'] ?? null) === 1, 'A employer id tek owner');

    // B) new third branch, same employer -> no new reporting-period config required.
    $resB = SgkPrimGunuService::calculateResolution($pdo, periodResolution(3, [
        103 => periodPersonel(103, 'Iki Personel', 2),
    ]));
    periodAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_sirket_politika_surumleri WHERE sube_id = 3')->fetchColumn() === 0, 'B yeni branch icin SGK policy row yok');
    periodAssert(($resB['bildirim_donem_cozumlemesi'][103]['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14', 'B yeni branch employer donemini otomatik kullanir');

    // C) different employers may legally have different reporting periods.
    $employer2 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 2, '2026-03-01', '2026-03-31');
    $employer1 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert($employer1['state'] === SgkIsverenBildirimDonemiReadService::STATE_DOGRULANDI && $employer1['bildirim_donem_tipi'] === 'AY_1_SON_GUN', 'C employer 1 AY_1_SON_GUN');
    periodAssert($employer2['state'] === SgkIsverenBildirimDonemiReadService::STATE_DOGRULANDI && $employer2['bildirim_donem_tipi'] === 'AY_15_SONRAKI_AY_14', 'C employer 2 AY_15_SONRAKI_AY_14');

    // K) unverified (DOGRULANMADI) rows are never effective, and no CONFLICT is
    // fabricated from them.
    periodAssert($employer1['state'] === SgkIsverenBildirimDonemiReadService::STATE_DOGRULANDI, 'K DOGRULANMADI satir efektif degil (CONFLICT uretmez)');
    periodAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM sgk_isveren_bildirim_donemi_surumleri WHERE state = 'DOGRULANMADI'")->fetchColumn() === 1,
        'K DOGRULANMADI satir fiziksel olarak mevcut ama runtime disi'
    );

    // O) no employer period -> fail closed.
    $employer3 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 3, '2026-03-01', '2026-03-31');
    periodAssert($employer3['state'] === SgkIsverenBildirimDonemiReadService::STATE_NO_PERIOD, 'O employer donemi yoksa NO_PERIOD fail-closed');
    $resO = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        101 => periodPersonel(101, 'Bir Personel 1', 3),
    ]));
    periodAssert(in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK, periodBlockerCodes($resO, 101), true), 'O employer donemi yoksa fail-closed blocker');

    // O) overlapping effective employer rows -> fail closed with CONFLICT.
    periodInsertEmployerPeriod($pdo, 2, 'EMP2-2026-CAKISMA', 'AY_1_SON_GUN', '2026-01-01', null, 'DOGRULANDI');
    $employer2Conflict = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 2, '2026-03-01', '2026-03-31');
    periodAssert($employer2Conflict['state'] === SgkIsverenBildirimDonemiReadService::STATE_CONFLICT, 'O cakisan employer donemi CONFLICT');
    $resConflict = SgkPrimGunuService::calculateResolution($pdo, periodResolution(4, [
        103 => periodPersonel(103, 'Iki Personel', 2),
    ]));
    periodAssert(in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_CAKISMA, periodBlockerCodes($resConflict, 103), true), 'O cakisma fail-closed blocker');
    $pdo->exec("DELETE FROM sgk_isveren_bildirim_donemi_surumleri WHERE surum_kodu = 'EMP2-2026-CAKISMA'");

    // Legacy branch policy says a different period -> employer canonical wins.
    $branchPolicy = SgkSirketPolitikaReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert($branchPolicy['state'] === SgkSirketPolitikaReadService::STATE_APPROVED && $branchPolicy['politika']['bildirim_donem_tipi'] === 'AY_15_SONRAKI_AY_14', 'B legacy branch policy farkli donem soyler');
    periodAssert(($cozumA1['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'B employer canonical donem legacy branch policy override edilemez');

    // M) personeller.sgk_isveren_id is the source; branch default is not.
    $resM = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        103 => periodPersonel(103, 'Iki Personel', 2),
    ]));
    $cozumM = $resM['bildirim_donem_cozumlemesi'][103] ?? [];
    periodAssert(($cozumM['kaynak'] ?? '') === 'SGK_ISVEREN' && ($cozumM['sgk_isveren_id'] ?? null) === 2, 'M kaynak personel sgk_isveren_id');
    periodAssert(($cozumM['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14', 'M branch default donemi kullanilmaz');

    // L) management policy behavior unchanged (reporting-period ownership separated only).
    periodAssert(($branchPolicy['degerler']['SGK_ODENEK_MAHSUP_MODU'] ?? null) === 'UCRET_MODELINE_GORE', 'L SGK_ODENEK_MAHSUP_MODU branch policy sahipliginde korunur');
    $resL = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        101 => periodPersonel(101, 'Bir Personel', 1),
    ]));
    periodAssert(($resL['company_policy']['politika']['id'] ?? null) === $branchPolicyId, 'L branch management policy runtime sonucunda korunur');
    periodAssert(($resL['company_policy']['degerler']['SGK_ODENEK_MAHSUP_MODU'] ?? null) === 'UCRET_MODELINE_GORE', 'L management policy degeri degismedi');

    // N) historical payroll snapshot immutability unchanged.
    $snapshotHash = str_repeat('c', 64);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_sgk_snapshotlari (
            donem_snapshot_id, personel_snapshot_id, personel_id,
            hesaplanan_prim_gunu, eksik_gun_sayisi,
            kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json,
            sgk_hesap_hash, gunluk_karar_dokumu_hash, gunluk_karar_dokumu_json,
            manuel_inceleme_gerekli_mi, blocker_kodlari_json, blocker_detaylari_json,
            ucret_modeli, ilk_iki_gun_politika_ozeti_json, sgk_odenek_durumu,
            is_goremezlik_finans_ozeti_json, source_hash
         ) VALUES (10, 20, 101, 30, 0, '[]', '[]', '[]', '$snapshotHash', '$snapshotHash', '[]', 0, '[]', '[]',
            'MAKTU_AYLIK', '[]', 'UYGULANMAZ', '[]', '$snapshotHash')"
    );
    $immutable = false;
    try {
        $pdo->exec('UPDATE maas_hesaplama_sgk_snapshotlari SET hesaplanan_prim_gunu = 29 WHERE id = 1');
    } catch (PDOException $e) {
        $immutable = strpos($e->getMessage(), 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE') !== false;
    }
    periodAssert($immutable, 'N historical SGK snapshot immutable kalir');

    // N) DIS_KAYNAK financial exclusion unchanged.
    $pdo->exec("UPDATE personeller SET calisan_kapsami = 'DIS_KAYNAK' WHERE id = 104");
    JsonResponse::beginCapture();
    $financialExclusion = false;
    try {
        SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
            104 => periodPersonel(104, 'Dizin Personel', 1),
        ]));
    } catch (ResponseCaptured $captured) {
        $payload = JsonResponse::capturedResponse();
        $financialExclusion = ($payload['errors'][0]['code'] ?? '') === 'PERSONEL_FINANSAL_KAPSAM_DISI';
    } finally {
        JsonResponse::endCapture();
    }
    periodAssert($financialExclusion, 'N DIS_KAYNAK finansal kapsam disi kalir');

    // O) Missing employer identity -> NO_PERIOD. Legacy branch policy and legacy
    // personnel status carry AY_15_SONRAKI_AY_14 but are never used as the runtime
    // reporting period; payroll fails closed with SGK_ISVEREN_MISSING semantics.
    $legacyPolicy = SgkSirketPolitikaReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert(
        ($legacyPolicy['politika']['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14',
        'O legacy branch policy AY_15 hala mevcut (ama runtime donem degil)'
    );
    $resO2 = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        107 => periodPersonel(107, 'Isverensiz Personel', null),
    ]));
    $cozumO2 = $resO2['bildirim_donem_cozumlemesi'][107] ?? [];
    periodAssert(
        ($cozumO2['kaynak'] ?? '') === 'SGK_ISVEREN_MISSING' && array_key_exists('sgk_isveren_id', $cozumO2) && $cozumO2['sgk_isveren_id'] === null,
        'O employer yok -> kaynak SGK_ISVEREN_MISSING ve employer id null'
    );
    periodAssert(
        ($cozumO2['state'] ?? '') === SgkIsverenBildirimDonemiReadService::STATE_NO_PERIOD,
        'O employer yok -> state NO_PERIOD'
    );
    periodAssert(
        array_key_exists('bildirim_donem_tipi', $cozumO2) && $cozumO2['bildirim_donem_tipi'] === null,
        'O legacy AY_15_SONRAKI_AY_14 runtime reporting period olarak kullanilmaz'
    );
    periodAssert(
        in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK, periodBlockerCodes($resO2, 107), true),
        'O employer yok -> fail-closed SGK blocker'
    );
    periodAssert(
        array_key_exists('hesaplanan_prim_gunu', $resO2['results_by_personel'][107] ?? [])
            && $resO2['results_by_personel'][107]['hesaplanan_prim_gunu'] === null,
        'O prim gunu uretilmez (fail-closed)'
    );
    periodAssert(
        in_array('SGK_PRIM_GUNU_HESAPLANAMADI', periodBlockerCodes($resO2, 107), true),
        'O engine girdisi legacy AY_15 donemi almaz (null donem blocker)'
    );

    periodOk();
} finally {
    unset($pdo);
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
