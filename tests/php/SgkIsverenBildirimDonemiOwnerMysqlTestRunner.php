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
 * SGK reporting-period canonical owner acceptance (A-J).
 *
 * Proves the reporting period is owned by the SGK employer axis
 * (personeller.sgk_isveren_id) and never inferred from the branch, while the
 * legacy branch-scoped management policy and payroll snapshot immutability
 * stay untouched.
 */

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

function periodAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
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

function periodInsertEmployerPeriod(
    PDO $pdo,
    int $sgkIsverenId,
    string $surumKodu,
    string $tip,
    string $baslangic,
    ?string $bitis,
    string $state
): void {
    $onaylayan = $state === 'ONAYLANDI' ? 2 : null;
    $onayZamani = $state === 'ONAYLANDI' ? '2026-01-02 00:00:00' : null;
    $stmt = $pdo->prepare(
        'INSERT INTO sgk_isveren_bildirim_donemi_surumleri (
            sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic, gecerlilik_bitis,
            state, dogrulama_kaynagi, aciklama, onaylayan_id, onay_zamani
         ) VALUES (
            :isveren, :surum, :tip, :baslangic, :bitis, :state, :kaynak, :aciklama, :onaylayan, :onay_zamani
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
        'aciklama' => 'acceptance ' . $surumKodu,
        'onaylayan' => $onaylayan,
        'onay_zamani' => $onayZamani,
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
    $dsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
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
                applyPeriodMigration($pdo, '090_sgk_isveren_bildirim_donemi_owner.sql');
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
        unset($pdo);
        $root->exec("DROP DATABASE IF EXISTS `$database`");
    }
}

$root = periodPdo();
$database = 'medisa_sgk_period_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ad VARCHAR(120) NOT NULL, sgk_isveren_id INT UNSIGNED NULL) ENGINE=InnoDB');
    $pdo->exec(
        'CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ad VARCHAR(80) NOT NULL,
            soyad VARCHAR(80) NULL,
            tc_kimlik_no VARCHAR(11) NULL,
            dogum_tarihi DATE NULL,
            telefon VARCHAR(20) NULL,
            calisan_kapsami VARCHAR(16) NOT NULL DEFAULT \'IC_PERSONEL\',
            sgk_isveren_id INT UNSIGNED NULL
         ) ENGINE=InnoDB'
    );
    $pdo->exec('CREATE TABLE sgk_isverenler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, kod VARCHAR(64) NULL, ad VARCHAR(191) NOT NULL, durum VARCHAR(16) NOT NULL DEFAULT \'AKTIF\') ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE surecler (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO users (id, ad) VALUES (1, 'Hazirlayan'), (2, 'Onaylayan')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad) VALUES (1, 'MEDISA', 'Medisa'), (2, 'KARYAPI', 'Karyapi'), (3, 'DIGER', 'Diger Isveren')");
    $pdo->exec("INSERT INTO subeler (id, ad, sgk_isveren_id) VALUES (1, 'Fabrika', 1), (2, 'Giresun', 1), (3, 'Izmir', 1), (4, 'Sakarya', 2)");
    $pdo->exec('INSERT INTO maas_hesaplama_donem_snapshotlari (id) VALUES (10)');
    $pdo->exec('INSERT INTO maas_hesaplama_personel_snapshotlari (id) VALUES (20)');
    $pdo->exec(
        "INSERT INTO personeller (id, ad, sgk_isveren_id) VALUES
            (101, 'Fabrika Personel', 1),
            (102, 'Giresun Personel', 1),
            (103, 'Izmir Personel', 1),
            (104, 'Karyapi Personel', 2),
            (105, 'Dizin Personel', 1),
            (106, 'Isverensiz Donem Personel', 3),
            (107, 'Legacy Isverensiz Personel', NULL)"
    );

    // 036 is the legacy branch-scoped SGK owner + immutable snapshot schema.
    applyPeriodMigration($pdo, '036_sgk_prim_gunu_owner.sql');
    // 090 is the additive employer-scoped reporting-period owner.
    applyPeriodMigration($pdo, '090_sgk_isveren_bildirim_donemi_owner.sql');
    applyPeriodMigration($pdo, '090_sgk_isveren_bildirim_donemi_owner.sql');

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

    // --- 090 fail-closed schema guard: disposable DB per scenario A-F ---
    $guardCanonicalColumns = "
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        sgk_isveren_id INT UNSIGNED NOT NULL,
        surum_kodu VARCHAR(80) NOT NULL,
        bildirim_donem_tipi ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14') NOT NULL,
        gecerlilik_baslangic DATE NOT NULL,
        gecerlilik_bitis DATE NULL,
        state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
        dogrulama_kaynagi VARCHAR(64) NOT NULL DEFAULT 'EXPLICIT_EMPLOYER_PERIOD',
        aciklama VARCHAR(1000) NOT NULL,
        hazirlayan_id INT UNSIGNED NULL,
        onaylayan_id INT UNSIGNED NULL,
        onay_zamani DATETIME NULL,
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
    periodAssert($guardA['blocked'] === false && $guardA['table'] === true && $guardA['columns'] === 13, 'MIG-A clean apply PASS');
    $guardB = periodGuardRun($root, null, 2);
    periodAssert($guardB['blocked'] === false && $guardB['table'] === true && $guardB['columns'] === 13, 'MIG-B second apply PASS');
    $guardC = periodGuardRun($root, $guardDdl($guardCanonicalColumns, $guardEmployerFk), 1);
    periodAssert($guardC['blocked'] === false && $guardC['table'] === true, 'MIG-C pre-existing canonical table PASS');

    $partialColumns = (string) preg_replace('/^\s*sgk_isveren_id INT UNSIGNED NOT NULL,\s*$/m', '', $guardCanonicalColumns);
    $guardD = periodGuardRun($root, $guardDdl($partialColumns), 1);
    periodAssert($guardBlocked($guardD) && $guardD['table'] === true, 'MIG-D partial table missing sgk_isveren_id BLOCKED');

    $wrongEnumColumns = str_replace("ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14')", "ENUM('AY_1_SON_GUN')", $guardCanonicalColumns);
    $guardE = periodGuardRun($root, $guardDdl($wrongEnumColumns, $guardEmployerFk), 1);
    periodAssert($guardBlocked($guardE), 'MIG-E wrong bildirim_donem_tipi enum BLOCKED');

    $guardF = periodGuardRun($root, $guardDdl($guardCanonicalColumns), 1);
    periodAssert($guardBlocked($guardF), 'MIG-F missing employer FK BLOCKED');

    $guardF2 = periodGuardRun(
        $root,
        $guardDdl($guardCanonicalColumns, ', CONSTRAINT fk_sgk_ibds_isveren FOREIGN KEY (sgk_isveren_id) REFERENCES subeler (id)'),
        1
    );
    periodAssert($guardBlocked($guardF2), 'MIG-F2 wrong employer FK BLOCKED');

    // Legacy personnel-status reporting-period override. It must never become the
    // runtime reporting period now that the employer axis owns it.
    $pdo->exec(
        "INSERT INTO sgk_personel_sigortalilik_surumleri (
            personel_id, sigortalilik_statusu, sozlesme_turu, bildirim_donem_tipi,
            gecerlilik_baslangic, gecerlilik_bitis, state, aciklama, onaylayan_id, onay_zamani
         ) VALUES (107, '4A', 'TAM_SURELI', 'AY_15_SONRAKI_AY_14', '2024-01-01', NULL,
            'ONAYLANDI', 'legacy personnel period override', 2, '2026-01-02 00:00:00')"
    );

    // Employer reporting-period truth (explicit, verified). Draft rows are excluded.
    periodInsertEmployerPeriod($pdo, 1, 'MEDISA-2026', 'AY_1_SON_GUN', '2024-01-01', null, 'ONAYLANDI');
    periodInsertEmployerPeriod($pdo, 1, 'MEDISA-2026-TASLAK', 'AY_15_SONRAKI_AY_14', '2024-01-01', null, 'TASLAK');
    periodInsertEmployerPeriod($pdo, 2, 'KARYAPI-2026', 'AY_15_SONRAKI_AY_14', '2024-01-01', null, 'ONAYLANDI');

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
        101 => periodPersonel(101, 'Fabrika Personel', 1),
        102 => periodPersonel(102, 'Giresun Personel', 1),
    ]));
    $cozumA1 = $resAll['bildirim_donem_cozumlemesi'][101] ?? [];
    $cozumA2 = $resAll['bildirim_donem_cozumlemesi'][102] ?? [];
    periodAssert(($cozumA1['kaynak'] ?? '') === 'SGK_ISVEREN' && ($cozumA2['kaynak'] ?? '') === 'SGK_ISVEREN', 'A ayni employer iki branch SGK_ISVEREN eksenini kullanir');
    periodAssert(($cozumA1['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN' && ($cozumA2['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'A iki branch ayni employer donemini gorur');
    periodAssert(($cozumA1['sgk_isveren_id'] ?? null) === 1 && ($cozumA2['sgk_isveren_id'] ?? null) === 1, 'A employer id tek owner');

    // B) new third branch, same employer -> no new reporting-period config required.
    $resB = SgkPrimGunuService::calculateResolution($pdo, periodResolution(3, [
        103 => periodPersonel(103, 'Izmir Personel', 1),
    ]));
    periodAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_sirket_politika_surumleri WHERE sube_id = 3')->fetchColumn() === 0, 'B yeni branch icin SGK policy row yok');
    periodAssert(($resB['bildirim_donem_cozumlemesi'][103]['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'B yeni branch employer donemini otomatik kullanir');

    // C) different employers may legally have different reporting periods.
    $employer2 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 2, '2026-03-01', '2026-03-31');
    $employer1 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert($employer1['state'] === SgkIsverenBildirimDonemiReadService::STATE_APPROVED && $employer1['bildirim_donem_tipi'] === 'AY_1_SON_GUN', 'C employer 1 AY_1_SON_GUN');
    periodAssert($employer2['state'] === SgkIsverenBildirimDonemiReadService::STATE_APPROVED && $employer2['bildirim_donem_tipi'] === 'AY_15_SONRAKI_AY_14', 'C employer 2 AY_15_SONRAKI_AY_14');

    // E) no employer period -> fail closed.
    $employer3 = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 3, '2026-03-01', '2026-03-31');
    periodAssert($employer3['state'] === SgkIsverenBildirimDonemiReadService::STATE_NO_PERIOD, 'E donem yoksa NO_PERIOD');
    $resE = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        106 => periodPersonel(106, 'Isverensiz Donem Personel', 3),
    ]));
    periodAssert(in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK, periodBlockerCodes($resE, 106), true), 'E employer donemi yoksa fail-closed blocker');

    // D) same employer + conflicting effective rows -> fail closed.
    periodInsertEmployerPeriod($pdo, 2, 'KARYAPI-2026-CAKISMA', 'AY_1_SON_GUN', '2026-01-01', null, 'ONAYLANDI');
    $employer2Conflict = SgkIsverenBildirimDonemiReadService::resolveForPeriod($pdo, 2, '2026-03-01', '2026-03-31');
    periodAssert($employer2Conflict['state'] === SgkIsverenBildirimDonemiReadService::STATE_CONFLICT, 'D cakisan employer donemi CONFLICT');
    $resD = SgkPrimGunuService::calculateResolution($pdo, periodResolution(4, [
        104 => periodPersonel(104, 'Karyapi Personel', 2),
    ]));
    periodAssert(in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_CAKISMA, periodBlockerCodes($resD, 104), true), 'D cakisma fail-closed blocker');
    $pdo->exec("DELETE FROM sgk_isveren_bildirim_donemi_surumleri WHERE surum_kodu = 'KARYAPI-2026-CAKISMA'");

    // F) legacy branch policy says a different period -> employer canonical wins.
    $branchPolicy = SgkSirketPolitikaReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert($branchPolicy['state'] === SgkSirketPolitikaReadService::STATE_APPROVED && $branchPolicy['politika']['bildirim_donem_tipi'] === 'AY_15_SONRAKI_AY_14', 'F legacy branch policy farkli donem soyler');
    periodAssert(($cozumA1['bildirim_donem_tipi'] ?? null) === 'AY_1_SON_GUN', 'F employer canonical donem legacy branch policy override edilemez');

    // G) personeller.sgk_isveren_id is the source; branch default is not.
    $resG = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        104 => periodPersonel(104, 'Karyapi Personel', 2),
    ]));
    $cozumG = $resG['bildirim_donem_cozumlemesi'][104] ?? [];
    periodAssert(($cozumG['kaynak'] ?? '') === 'SGK_ISVEREN' && ($cozumG['sgk_isveren_id'] ?? null) === 2, 'G kaynak personel sgk_isveren_id');
    periodAssert(($cozumG['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14', 'G branch default donemi kullanilmaz');

    // H) management policy behavior unchanged (reporting-period ownership separated only).
    periodAssert(($branchPolicy['degerler']['SGK_ODENEK_MAHSUP_MODU'] ?? null) === 'UCRET_MODELINE_GORE', 'H SGK_ODENEK_MAHSUP_MODU branch policy sahipliginde korunur');
    $resH = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        101 => periodPersonel(101, 'Fabrika Personel', 1),
    ]));
    periodAssert(($resH['company_policy']['politika']['id'] ?? null) === $branchPolicyId, 'H branch management policy runtime sonucunda korunur');
    periodAssert(($resH['company_policy']['degerler']['SGK_ODENEK_MAHSUP_MODU'] ?? null) === 'UCRET_MODELINE_GORE', 'H management policy degeri degismedi');

    // I) historical payroll snapshot immutability unchanged.
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
    periodAssert($immutable, 'I historical SGK snapshot immutable kalir');

    // J) DIS_KAYNAK financial exclusion unchanged.
    $pdo->exec("UPDATE personeller SET calisan_kapsami = 'DIS_KAYNAK' WHERE id = 105");
    JsonResponse::beginCapture();
    $financialExclusion = false;
    try {
        SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
            105 => periodPersonel(105, 'Dizin Personel', 1),
        ]));
    } catch (ResponseCaptured $captured) {
        $payload = JsonResponse::capturedResponse();
        $financialExclusion = ($payload['errors'][0]['code'] ?? '') === 'PERSONEL_FINANSAL_KAPSAM_DISI';
    } finally {
        JsonResponse::endCapture();
    }
    periodAssert($financialExclusion, 'J DIS_KAYNAK finansal kapsam disi kalir');

    // K) Missing employer identity -> NO_PERIOD. Legacy branch policy and legacy
    // personnel status carry AY_15_SONRAKI_AY_14 but are never used as the runtime
    // reporting period; payroll fails closed with SGK_ISVEREN_MISSING semantics.
    $legacyPolicy = SgkSirketPolitikaReadService::resolveForPeriod($pdo, 1, '2026-03-01', '2026-03-31');
    periodAssert(
        ($legacyPolicy['politika']['bildirim_donem_tipi'] ?? null) === 'AY_15_SONRAKI_AY_14',
        'K legacy branch policy AY_15 hala mevcut (ama runtime donem degil)'
    );
    $resK = SgkPrimGunuService::calculateResolution($pdo, periodResolution(1, [
        107 => periodPersonel(107, 'Legacy Isverensiz Personel', null),
    ]));
    $cozumK = $resK['bildirim_donem_cozumlemesi'][107] ?? [];
    periodAssert(
        ($cozumK['kaynak'] ?? '') === 'SGK_ISVEREN_MISSING' && array_key_exists('sgk_isveren_id', $cozumK) && $cozumK['sgk_isveren_id'] === null,
        'K employer yok -> kaynak SGK_ISVEREN_MISSING ve employer id null'
    );
    periodAssert(
        ($cozumK['state'] ?? '') === SgkIsverenBildirimDonemiReadService::STATE_NO_PERIOD,
        'K employer yok -> state NO_PERIOD'
    );
    periodAssert(
        array_key_exists('bildirim_donem_tipi', $cozumK) && $cozumK['bildirim_donem_tipi'] === null,
        'K legacy AY_15_SONRAKI_AY_14 runtime reporting period olarak kullanilmaz'
    );
    periodAssert(
        in_array(SgkPrimGunuService::BLOCKER_ISVEREN_BILDIRIM_DONEMI_YOK, periodBlockerCodes($resK, 107), true),
        'K employer yok -> fail-closed SGK blocker'
    );
    periodAssert(
        array_key_exists('hesaplanan_prim_gunu', $resK['results_by_personel'][107] ?? [])
            && $resK['results_by_personel'][107]['hesaplanan_prim_gunu'] === null,
        'K prim gunu uretilmez (fail-closed)'
    );
    periodAssert(
        in_array('SGK_PRIM_GUNU_HESAPLANAMADI', periodBlockerCodes($resK, 107), true),
        'K engine girdisi legacy AY_15 donemi almaz (null donem blocker)'
    );

    echo 'verify-sgk-isveren-bildirim-donemi-owner-mysql: OK' . PHP_EOL;
} finally {
    unset($pdo);
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
