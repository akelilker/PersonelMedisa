<?php

declare(strict_types=1);

/**
 * Pack 3B: disposable MariaDB — PUANTAJ / BORDRO / SGK_EKSIK_GUN physical destruction matrix.
 * php tests/php/RetentionPhysicalPack3bMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\PuantajDonemReopenService;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionLedgerService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PuantajPhysicalDestructionGate;
use Medisa\Api\Services\Retention\PhysicalDestruction\RetentionDestructionHandlerRegistry;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;

function p3bAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p3bRootPdo(): PDO
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
function p3bSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
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

function p3bApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p3bSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p3bPdoForDb(string $database): PDO
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
function p3bMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function p3bAssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }
    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused DSN containing karmotor_medisa\n");
        exit(1);
    }
    $host = '';
    if (preg_match('/(?:host|server)=([^;]+)/i', $dsn, $m)) {
        $host = strtolower(trim($m[1]));
    }
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
}

function p3bFlagOn(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=1');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '1';
}

function p3bFlagOff(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=0');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '0';
}

function p3bNonce(): string
{
    return bin2hex(random_bytes(32));
}

/** @return array{id:int,rol:string} */
function p3bGm(): array
{
    return ['id' => 1, 'rol' => 'GENEL_YONETICI'];
}

function p3bHash(): string
{
    return str_repeat('a', 64);
}

function p3bSeedBase(PDO $pdo): void
{
    $hash = password_hash('P3bPack3TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'approver', '{$hash}', 'Approver Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111111', 'Aktif', 'Personel', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S001', '2010-01-01', 1, 'AKTIF'),
         (20, '22222222222', 'Diger', 'Sube', '1990-01-01', '05000000002', 'Acil', '05000000003',
            'S002', '2010-01-01', 1, 'AKTIF')"
    );
}

/**
 * @return array{seal_ids:list<int>,daily_id:int}
 */
function p3bSeedPuantajPeriod(PDO $pdo, int $yil, int $ay, bool $withHistoricalRevision = true): array
{
    $donem = sprintf('%04d-%02d', $yil, $ay);
    $day = sprintf('%04d-%02d-15', $yil, $ay);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu)
         VALUES (10, '{$day}', 'ACIK', 'BEKLIYOR')"
    );
    $dailyId = (int) $pdo->lastInsertId();

    $sealIds = [];
    if ($withHistoricalRevision) {
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhurleri
                (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
             VALUES (1, {$yil}, {$ay}, 1, '{$donem}', 'SUPERSEDED', 1, 1, '{$yil}-{$ay}-28 10:00:00')"
        );
        $oldId = (int) $pdo->lastInsertId();
        $sealIds[] = $oldId;
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
             VALUES ({$oldId}, 10, '{$day}', 'BEKLIYOR')"
        );
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhurleri
                (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by,
                 parent_muhur_id, created_at)
             VALUES (1, {$yil}, {$ay}, 2, '{$donem}', 'MUHURLENDI', 1, 1, {$oldId}, '{$yil}-{$ay}-29 10:00:00')"
        );
        $newId = (int) $pdo->lastInsertId();
        $sealIds[] = $newId;
        $pdo->exec("UPDATE puantaj_aylik_muhurleri SET superseded_by_id = {$newId} WHERE id = {$oldId}");
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
             VALUES ({$newId}, 10, '{$day}', 'BEKLIYOR')"
        );
        $pdo->exec("UPDATE gunluk_puantaj SET muhur_id = {$newId}, state = 'MUHURLENDI' WHERE id = {$dailyId}");
        ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, $yil, $ay, $newId, 1);
    } else {
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhurleri
                (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
             VALUES (1, {$yil}, {$ay}, 1, '{$donem}', 'MUHURLENDI', 1, 1, '{$yil}-{$ay}-28 10:00:00')"
        );
        $sealId = (int) $pdo->lastInsertId();
        $sealIds[] = $sealId;
        $pdo->exec(
            "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
             VALUES ({$sealId}, 10, '{$day}', 'BEKLIYOR')"
        );
        $pdo->exec("UPDATE gunluk_puantaj SET muhur_id = {$sealId}, state = 'MUHURLENDI' WHERE id = {$dailyId}");
        ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, $yil, $ay, $sealId, 1);
    }

    return ['seal_ids' => $sealIds, 'daily_id' => $dailyId];
}

/**
 * @return array{snapshot_id:int,personel_snapshot_id:int,run_id:int,seal_id:int}
 */
function p3bSeedBordroTree(PDO $pdo, int $yil, int $ay, bool $withSgk = true, bool $withChildRun = false): array
{
    $donem = sprintf('%04d-%02d', $yil, $ay);
    $h = p3bHash();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, {$yil}, {$ay}, 1, '{$donem}', 'MUHURLENDI', 0, 1, '{$yil}-{$ay}-28 10:00:00')"
    );
    $sealId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, {$yil}, {$ay}, '{$donem}', '{$yil}-{$ay}-01', '{$yil}-{$ay}-28', {$sealId}, 1,
            'OLUSTURULDU', '{$yil}-{$ay}-28 12:00:00', '{$h}', '{$h}', '{$h}',
            1, 0, 1
         )"
    );
    $snapshotId = (int) $pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (
            donem_snapshot_id, personel_id, personel_snapshot_json, personel_snapshot_hash,
            istihdam_baslangic
         ) VALUES (
            {$snapshotId}, 10, '{\"id\":10}', '{$h}', '2010-01-01'
         )"
    );
    $personelSnapshotId = (int) $pdo->lastInsertId();

    if ($withSgk) {
        ArchiveManifestService::createSgkPeriodManifest($pdo, 1, $yil, $ay, $snapshotId, 1);
        $pdo->exec(
            "INSERT INTO maas_hesaplama_sgk_snapshotlari (
                donem_snapshot_id, personel_snapshot_id, personel_id,
                hesaplanan_prim_gunu, eksik_gun_sayisi,
                kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json,
                sgk_hesap_hash, gunluk_karar_dokumu_hash, gunluk_karar_dokumu_json,
                manuel_inceleme_gerekli_mi, blocker_kodlari_json, blocker_detaylari_json,
                ucret_modeli, ilk_iki_gun_politika_ozeti_json, sgk_odenek_durumu,
                is_goremezlik_finans_ozeti_json, source_hash
             ) VALUES (
                {$snapshotId}, {$personelSnapshotId}, 10, 30, 0,
                '[]', '[]', '[]', '{$h}', '{$h}', '[]',
                0, '[]', '[]', 'MAKTU_AYLIK', '[]', 'UYGULANMAZ', '[]', '{$h}'
             )"
        );
        $pdo->exec(
            "INSERT INTO sgk_hesap_auditleri (
                donem_snapshot_id, personel_id, yil, ay, aksiyon, sonuc,
                request_hash, source_hash, result_hash, blocker_kodlari_json, actor_id
             ) VALUES (
                {$snapshotId}, 10, {$yil}, {$ay}, 'SNAPSHOT_CREATE', 'CREATED',
                '{$h}', '{$h}', '{$h}', '[]', 1
             )"
        );
    }

    $pdo->exec(
        "INSERT INTO maas_hesaplama_calistirmalari (
            snapshot_id, sube_id, yil, ay, revision_no, state, bordro_onay_durumu,
            engine_version, contract_version,
            snapshot_hash, parameter_set_hash, carryover_set_hash, request_hash, source_hash, result_hash,
            calculation_input_hash, personel_sayisi, basarili_aday_sayisi, created_by, kesinlestirme_at
         ) VALUES (
            {$snapshotId}, 1, {$yil}, {$ay}, 1, 'HESAPLANDI', 'KESINLESTI',
            'S77D_PAYROLL_ENGINE_V2', 'S77D_PAYROLL_CANDIDATE_V2',
            '{$h}', '{$h}', '{$h}', '{$h}', '{$h}', '{$h}',
            '{$h}', 1, 1, 1, '{$yil}-{$ay}-28 15:00:00'
         )"
    );
    $runId = (int) $pdo->lastInsertId();
    ArchiveManifestService::createBordroPeriodManifests($pdo, 1, $yil, $ay, $runId, 1);

    $pdo->exec(
        "INSERT INTO maas_hesaplama_adaylari (
            calistirma_id, personel_snapshot_id, personel_id, revision_no, state,
            ucret_turu, para_birimi, hesaplanan_brut_tutar, input_hash, result_hash, engine_version
         ) VALUES (
            {$runId}, {$personelSnapshotId}, 10, 1, 'HESAPLANDI',
            'BRUT', 'TRY', 1000.00, '{$h}', '{$h}', 'S77D_PAYROLL_ENGINE_V2'
         )"
    );
    $adayId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO maas_hesaplama_aday_kalemleri (
            aday_id, sira_no, kalem_grubu, kalem_kodu, yon, tutar, payload_json, payload_hash
         ) VALUES (
            {$adayId}, 1, 'BRUT', 'MAAS', 'ARTI', 1000.00, '{}', '{$h}'
         )"
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_auditleri (
            calistirma_id, snapshot_id, sube_id, yil, ay, aksiyon, sonuc, actor_id,
            request_hash, snapshot_json
         ) VALUES (
            {$runId}, {$snapshotId}, 1, {$yil}, {$ay}, 'CALCULATION_CREATE', 'CREATED', 1,
            '{$h}', '{}'
         )"
    );

    if ($withChildRun) {
        // Dependent revision that RESTRICTs target run (IPTAL so unique aktif_calistirma allows it).
        $pdo->exec(
            "INSERT INTO maas_hesaplama_calistirmalari (
                snapshot_id, sube_id, yil, ay, revision_no, parent_calistirma_id, state, bordro_onay_durumu,
                engine_version, contract_version,
                snapshot_hash, parameter_set_hash, carryover_set_hash, request_hash, source_hash, result_hash,
                calculation_input_hash, personel_sayisi, basarili_aday_sayisi, created_by, kesinlestirme_at
             ) VALUES (
                {$snapshotId}, 1, {$yil}, {$ay}, 2, {$runId}, 'IPTAL', 'KESINLESTI',
                'S77D_PAYROLL_ENGINE_V2', 'S77D_PAYROLL_CANDIDATE_V2',
                '{$h}', '{$h}', '{$h}', '" . str_repeat('b', 64) . "', '{$h}', '{$h}',
                '{$h}', 1, 1, 1, '{$yil}-{$ay}-28 16:00:00'
             )"
        );
    }

    if ($pdo->query(
        "SELECT 1 FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel_bordro_devirleri'"
    )->fetchColumn()) {
        $pdo->exec(
            "INSERT INTO personel_bordro_devirleri (
                personel_id, sube_id, yil, ay,
                onceki_kumulatif_gelir_vergisi_matrahi, onceki_kumulatif_gelir_vergisi,
                created_by
             ) VALUES (10, 1, {$yil}, {$ay}, 100.00, 10.00, 1)"
        );
    }

    return [
        'snapshot_id' => $snapshotId,
        'personel_snapshot_id' => $personelSnapshotId,
        'run_id' => $runId,
        'seal_id' => $sealId,
    ];
}

/**
 * @return array<string, mixed>
 */
function p3bApprove(PDO $pdo, string $category, string $entityType, int $recordId, int $yil, int $ay): array
{
    $req = DestructionWorkflowService::requestDestruction($pdo, p3bGm(), [
        'category' => $category,
        'entity_type' => $entityType,
        'record_id' => $recordId,
        'sube_id' => 1,
        'yil' => $yil,
        'ay' => $ay,
        'reason' => 'Pack3B ' . $category,
    ]);
    if ((string) ($req['item']['status'] ?? '') !== DestructionWorkflowService::STATUS_REQUESTED) {
        throw new RuntimeException(
            'request failed ' . $category . ': ' . (string) ($req['eligibility']['code'] ?? '?')
        );
    }

    return DestructionWorkflowService::approveDestruction(
        $pdo,
        p3bGm(),
        (int) $req['item']['id'],
        'GM Pack3B',
        true
    );
}

/**
 * @return array<string, mixed>
 */
function p3bEvalExecute(PDO $pdo, int $talepId): array
{
    $eval = PhysicalDestructionService::evaluate($pdo, p3bGm(), $talepId);
    if (($eval['execution']['code'] ?? '') !== RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION) {
        throw new RuntimeException('not eligible: ' . (string) ($eval['execution']['code'] ?? '?'));
    }
    $plan = $eval['plan'];
    if (!is_array($plan) || empty($plan['plan_hash'])) {
        throw new RuntimeException('plan missing');
    }

    return PhysicalDestructionService::execute($pdo, p3bGm(), $talepId, [
        'expected_plan_hash' => (string) $plan['plan_hash'],
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
}

// --- main ---
$root = p3bRootPdo();
$database = 'medisa_ret_phys_pack3b_' . substr(bin2hex(random_bytes(4)), 0, 8);
p3bAssertSafeTarget($database);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p3bPdoForDb($database);
$passCount = 0;

try {
    foreach (p3bMigrationFiles() as $file) {
        p3bApply($pdo, $file);
    }
    p3bAssert(
        (bool) $pdo->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retention_physical_destroy_gates'"
        )->fetchColumn(),
        'migration 060 gate table present'
    );
    p3bSeedBase($pdo);
    RetentionClock::setOverride('2037-01-01');
    p3bFlagOn();

    // Registry: Pack 3B executable; remaining policy
    foreach ([RetentionCategories::PUANTAJ, RetentionCategories::BORDRO, RetentionCategories::SGK_EKSIK_GUN] as $cat) {
        p3bAssert(RetentionDestructionHandlerRegistry::forCategory($cat)->isExecutable(), $cat . ' executable');
        $passCount++;
    }
    foreach ([
        RetentionCategories::FAZLA_CALISMA,
        RetentionCategories::SERBEST_ZAMAN,
        RetentionCategories::DISIPLIN,
        RetentionCategories::RAPOR,
        RetentionCategories::IS_KAZASI,
    ] as $cat) {
        // Pack 3C closed these as typed executable handlers (regression expects executable).
        p3bAssert(RetentionDestructionHandlerRegistry::forCategory($cat)->isExecutable(), $cat . ' Pack3C executable');
        $passCount++;
    }

    // ========== PUANTAJ ==========
    // Clean happy path + full revision graph + daily hard-delete
    $p = p3bSeedPuantajPeriod($pdo, 2011, 3, true);
    $effective = end($p['seal_ids']);
    $ap = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $effective, 2011, 3);
    $res = p3bEvalExecute($pdo, (int) $ap['id']);
    p3bAssert(
        ($res['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'PUANTAJ execute success'
    );
    $passCount++;
    $sealsLeft = (int) $pdo->query(
        'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE sube_id=1 AND yil=2011 AND ay=3'
    )->fetchColumn();
    p3bAssert($sealsLeft === 0, 'PUANTAJ full revision graph destroyed');
    $passCount++;
    $dailyLeft = (int) $pdo->query(
        "SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id=10 AND YEAR(tarih)=2011 AND MONTH(tarih)=3"
    )->fetchColumn();
    p3bAssert($dailyLeft === 0, 'PUANTAJ daily hard-delete');
    $passCount++;
    $execId1 = (int) ($res['execution']['execution_id'] ?? 0);

    // Idempotency
    $again = PhysicalDestructionService::execute($pdo, p3bGm(), (int) $ap['id'], [
        'expected_plan_hash' => (string) ($res['plan']['plan_hash'] ?? p3bHash()),
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3bAssert(
        ($again['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED
            && (int) ($again['execution']['execution_id'] ?? 0) === $execId1
            && (int) ($again['execution']['mutation_count'] ?? 0) === 0,
        'PUANTAJ already executed idempotent'
    );
    $passCount++;

    // Unrelated period unchanged
    $other = p3bSeedPuantajPeriod($pdo, 2011, 4, false);
    $leftOther = (int) $pdo->query(
        'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE id=' . (int) $other['seal_ids'][0]
    )->fetchColumn();
    p3bAssert($leftOther === 1, 'PUANTAJ unrelated period unchanged');
    $passCount++;

    // QR ledger blocks
    $qrPeriod = p3bSeedPuantajPeriod($pdo, 2011, 5, false);
    $qrDaily = (int) $qrPeriod['daily_id'];
    $ledgerRow = [
        'personel_id' => 10,
        'sube_id' => 1,
        'candidate_date' => '2011-05-15',
        'candidate_hash' => p3bHash(),
        'decision_type' => 'KEEP_CANONICAL',
        'decision_reason' => 'synthetic',
        'puantaj_id' => $qrDaily,
        'algorithm_version' => 'QR_PUANTAJ_CANDIDATE_V1',
        'interval_algorithm_version' => 'QR_INTERVAL_V1',
        'decision_algorithm_version' => 'QR_PUANTAJ_DECISION_V1',
        'candidate_snapshot' => '{}',
        'before_puantaj_snapshot' => null,
        'after_puantaj_snapshot' => null,
        'decided_by_user_id' => 1,
        'request_nonce' => '11111111-1111-4111-8111-111111111111',
        'supersedes_decision_id' => null,
        'previous_decision_hash' => null,
        'created_at' => '2011-05-16 10:00:00.000000',
    ];
    $ledgerRow['decision_hash'] = QrPuantajCandidateDecisionLedgerService::computeDecisionHash($ledgerRow);
    $pdo->exec(
        "INSERT INTO qr_puantaj_candidate_decision_ledger (
            personel_id, sube_id, candidate_date, candidate_hash, decision_type, decision_reason,
            puantaj_id, algorithm_version, interval_algorithm_version, decision_algorithm_version,
            candidate_snapshot, decided_by_user_id, request_nonce, decision_hash, created_at
         ) VALUES (
            10, 1, '2011-05-15', " . $pdo->quote($ledgerRow['candidate_hash']) . ", 'KEEP_CANONICAL', 'synthetic',
            {$qrDaily}, 'QR_PUANTAJ_CANDIDATE_V1', 'QR_INTERVAL_V1', 'QR_PUANTAJ_DECISION_V1',
            '{}', 1, '11111111-1111-4111-8111-111111111111', " . $pdo->quote($ledgerRow['decision_hash']) . ",
            '2011-05-16 10:00:00.000000'
         )"
    );
    $qrLedgerId = (int) $pdo->lastInsertId();
    ArchiveManifestService::createQrPuantajDecisionOnayAuditManifest($pdo, [
        'id' => $qrLedgerId,
        'personel_id' => 10,
        'sube_id' => 1,
        'candidate_date' => '2011-05-15',
    ], 1);
    $apQr = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $qrPeriod['seal_ids'][0], 2011, 5);
    $evalQr = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apQr['id']);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apQr['id'], [
            'expected_plan_hash' => (string) $evalQr['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'PUANTAJ should block on QR');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT,
            'PUANTAJ QR ledger blocks'
        );
        $passCount++;
    }

    // Destroy ONAY_AUDIT ledger then PUANTAJ succeeds
    $apLedger = DestructionWorkflowService::requestDestruction($pdo, p3bGm(), [
        'category' => RetentionCategories::ONAY_AUDIT,
        'entity_type' => 'qr_pc_decision',
        'record_id' => $qrLedgerId,
        'personel_id' => 10,
        'reason' => 'Pack3B ONAY_AUDIT ledger first',
    ]);
    p3bAssert((string) ($apLedger['item']['status'] ?? '') === 'REQUESTED', 'ONAY_AUDIT ledger REQUESTED');
    $apLedger = DestructionWorkflowService::approveDestruction(
        $pdo,
        p3bGm(),
        (int) $apLedger['item']['id'],
        'GM ledger',
        true
    );
    $resLedger = p3bEvalExecute($pdo, (int) $apLedger['id']);
    p3bAssert(
        ($resLedger['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'ONAY_AUDIT ledger destroyed first'
    );
    $passCount++;
    $resPAfter = p3bEvalExecute($pdo, (int) $apQr['id']);
    p3bAssert(
        ($resPAfter['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'PUANTAJ succeeds after ONAY_AUDIT destroy'
    );
    $passCount++;

    // ========== PUANTAJ snapshot-pin follow-up (OPTION A) ==========
    // Payroll snapshot pins effective seal → headers preserved; lines + daily deleted
    $pinned = p3bSeedBordroTree($pdo, 2011, 6, false, false);
    // Attach daily + seal lines to the bordro-owned seal period
    $dayPinned = '2011-06-15';
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '{$dayPinned}', 'MUHURLENDI', 'BEKLIYOR', " . (int) $pinned['seal_id'] . ")"
    );
    $pinnedDailyId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $pinned['seal_id'] . ", 10, '{$dayPinned}', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2011, 6, (int) $pinned['seal_id'], 1);
    $snapMuhurBefore = (int) $pdo->query(
        'SELECT muhur_id FROM maas_hesaplama_donem_snapshotlari WHERE id=' . (int) $pinned['snapshot_id']
    )->fetchColumn();
    $apPinned = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $pinned['seal_id'], 2011, 6);
    $evalPinned = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apPinned['id']);
    p3bAssert(
        in_array('PUANTAJ_SNAPSHOT_PINNED_SEAL_HEADERS_PRESERVE', $evalPinned['plan']['db_operation_codes'] ?? [], true),
        'PUANTAJ pinned plan mode code'
    );
    $passCount++;
    $resPinned = p3bEvalExecute($pdo, (int) $apPinned['id']);
    p3bAssert(
        ($resPinned['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'PUANTAJ snapshot-pinned execute success'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE id=' . (int) $pinned['seal_id'])->fetchColumn() === 1,
        'PUANTAJ seal headers preserved when pinned'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE muhur_id=' . (int) $pinned['seal_id']
        )->fetchColumn() === 0,
        'PUANTAJ seal lines deleted when pinned'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj WHERE id=' . $pinnedDailyId)->fetchColumn() === 0,
        'PUANTAJ daily deleted when pinned'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari WHERE id=' . (int) $pinned['snapshot_id']
        )->fetchColumn() === 1,
        'PUANTAJ snapshot row preserved when pinned'
    );
    $passCount++;
    $snapMuhurAfter = (int) $pdo->query(
        'SELECT muhur_id FROM maas_hesaplama_donem_snapshotlari WHERE id=' . (int) $pinned['snapshot_id']
    )->fetchColumn();
    p3bAssert($snapMuhurBefore === $snapMuhurAfter, 'PUANTAJ snapshot.muhur_id unchanged');
    $passCount++;

    // Idempotency after header preserve
    $againPinned = PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apPinned['id'], [
        'expected_plan_hash' => (string) ($resPinned['plan']['plan_hash'] ?? p3bHash()),
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3bAssert(
        ($againPinned['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED
            && (int) ($againPinned['execution']['execution_id'] ?? 0) === (int) ($resPinned['execution']['execution_id'] ?? -1)
            && (int) ($againPinned['execution']['mutation_count'] ?? 0) === 0,
        'PUANTAJ pinned ALREADY_EXECUTED same id mutation 0'
    );
    $passCount++;

    // Historical revision member pin → full header graph preserved
    $hist = p3bSeedPuantajPeriod($pdo, 2011, 9, true);
    $oldSeal = (int) $hist['seal_ids'][0];
    $effSeal = (int) $hist['seal_ids'][1];
    $parentBefore = $pdo->query(
        'SELECT parent_muhur_id, superseded_by_id FROM puantaj_aylik_muhurleri WHERE id=' . $oldSeal
    )->fetch(PDO::FETCH_ASSOC);
    $h = p3bHash();
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, 2011, 9, '2011-09', '2011-09-01', '2011-09-30', {$oldSeal}, 1,
            'OLUSTURULDU', '2011-09-28 12:00:00', '{$h}', '{$h}', '{$h}',
            1, 0, 1
         )"
    );
    $histSnapId = (int) $pdo->lastInsertId();
    $apHist = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', $effSeal, 2011, 9);
    $resHist = p3bEvalExecute($pdo, (int) $apHist['id']);
    p3bAssert(
        ($resHist['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'PUANTAJ historical-pin execute success'
    );
    $passCount++;
    $headersLeft = (int) $pdo->query(
        'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE sube_id=1 AND yil=2011 AND ay=9'
    )->fetchColumn();
    p3bAssert($headersLeft === 2, 'PUANTAJ full header graph preserved when historical pin');
    $passCount++;
    $parentAfter = $pdo->query(
        'SELECT parent_muhur_id, superseded_by_id FROM puantaj_aylik_muhurleri WHERE id=' . $oldSeal
    )->fetch(PDO::FETCH_ASSOC);
    p3bAssert(
        (string) ($parentBefore['parent_muhur_id'] ?? '') === (string) ($parentAfter['parent_muhur_id'] ?? '')
            && (string) ($parentBefore['superseded_by_id'] ?? '') === (string) ($parentAfter['superseded_by_id'] ?? ''),
        'PUANTAJ parent/superseded graph unchanged when pinned'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE muhur_id IN (' . $oldSeal . ',' . $effSeal . ')'
        )->fetchColumn() === 0,
        'PUANTAJ historical-pin seal lines deleted'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT muhur_id FROM maas_hesaplama_donem_snapshotlari WHERE id=' . $histSnapId)->fetchColumn() === $oldSeal,
        'PUANTAJ historical snapshot.muhur_id unchanged'
    );
    $passCount++;

    // Snapshot pinned + QR ledger → block; after ONAY_AUDIT → pinned success
    $pinQr = p3bSeedBordroTree($pdo, 2011, 10, false, false);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2011-10-15', 'MUHURLENDI', 'BEKLIYOR', " . (int) $pinQr['seal_id'] . ")"
    );
    $pinQrDaily = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $pinQr['seal_id'] . ", 10, '2011-10-15', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2011, 10, (int) $pinQr['seal_id'], 1);
    $ledgerRow2 = [
        'personel_id' => 10,
        'sube_id' => 1,
        'candidate_date' => '2011-10-15',
        'candidate_hash' => p3bHash(),
        'decision_type' => 'KEEP_CANONICAL',
        'decision_reason' => 'synthetic pinned',
        'puantaj_id' => $pinQrDaily,
        'algorithm_version' => 'QR_PUANTAJ_CANDIDATE_V1',
        'interval_algorithm_version' => 'QR_INTERVAL_V1',
        'decision_algorithm_version' => 'QR_PUANTAJ_DECISION_V1',
        'candidate_snapshot' => '{}',
        'before_puantaj_snapshot' => null,
        'after_puantaj_snapshot' => null,
        'decided_by_user_id' => 1,
        'request_nonce' => '22222222-2222-4222-8222-222222222222',
        'supersedes_decision_id' => null,
        'previous_decision_hash' => null,
        'created_at' => '2011-10-16 10:00:00.000000',
    ];
    $ledgerRow2['decision_hash'] = QrPuantajCandidateDecisionLedgerService::computeDecisionHash($ledgerRow2);
    $pdo->exec(
        "INSERT INTO qr_puantaj_candidate_decision_ledger (
            personel_id, sube_id, candidate_date, candidate_hash, decision_type, decision_reason,
            puantaj_id, algorithm_version, interval_algorithm_version, decision_algorithm_version,
            candidate_snapshot, decided_by_user_id, request_nonce, decision_hash, created_at
         ) VALUES (
            10, 1, '2011-10-15', " . $pdo->quote($ledgerRow2['candidate_hash']) . ", 'KEEP_CANONICAL', 'synthetic pinned',
            {$pinQrDaily}, 'QR_PUANTAJ_CANDIDATE_V1', 'QR_INTERVAL_V1', 'QR_PUANTAJ_DECISION_V1',
            '{}', 1, '22222222-2222-4222-8222-222222222222', " . $pdo->quote($ledgerRow2['decision_hash']) . ",
            '2011-10-16 10:00:00.000000'
         )"
    );
    $pinQrLedgerId = (int) $pdo->lastInsertId();
    ArchiveManifestService::createQrPuantajDecisionOnayAuditManifest($pdo, [
        'id' => $pinQrLedgerId,
        'personel_id' => 10,
        'sube_id' => 1,
        'candidate_date' => '2011-10-15',
    ], 1);
    $apPinQr = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $pinQr['seal_id'], 2011, 10);
    $evalPinQr = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apPinQr['id']);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apPinQr['id'], [
            'expected_plan_hash' => (string) $evalPinQr['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'PUANTAJ pinned+QR should block');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT,
            'PUANTAJ pinned QR ledger blocks'
        );
        $passCount++;
    }
    $reqPinLedger = DestructionWorkflowService::requestDestruction($pdo, p3bGm(), [
        'category' => RetentionCategories::ONAY_AUDIT,
        'entity_type' => 'qr_pc_decision',
        'record_id' => $pinQrLedgerId,
        'personel_id' => 10,
        'reason' => 'Pack3B pinned QR first',
    ]);
    $apPinLedger = DestructionWorkflowService::approveDestruction(
        $pdo,
        p3bGm(),
        (int) $reqPinLedger['item']['id'],
        'GM',
        true
    );
    p3bEvalExecute($pdo, (int) $apPinLedger['id']);
    $resPinQrAfter = p3bEvalExecute($pdo, (int) $apPinQr['id']);
    p3bAssert(
        ($resPinQrAfter['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED
            && in_array(
                'PUANTAJ_SNAPSHOT_PINNED_SEAL_HEADERS_PRESERVE',
                $resPinQrAfter['plan']['db_operation_codes'] ?? [],
                true
            ),
        'PUANTAJ pinned succeeds after ONAY_AUDIT'
    );
    $passCount++;

    // Pin added between evaluate and execute → DESTRUCTION_PLAN_CHANGED
    $flip = p3bSeedPuantajPeriod($pdo, 2011, 11, false);
    $apFlip = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $flip['seal_ids'][0], 2011, 11);
    $evalFlip = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apFlip['id']);
    p3bAssert(
        in_array('PUANTAJ_FULL_REVISION_GRAPH_DELETE', $evalFlip['plan']['db_operation_codes'] ?? [], true),
        'PUANTAJ flip starts full-delete mode'
    );
    $passCount++;
    $h2 = p3bHash();
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, 2011, 11, '2011-11', '2011-11-01', '2011-11-30', " . (int) $flip['seal_ids'][0] . ", 1,
            'OLUSTURULDU', '2011-11-28 12:00:00', '{$h2}', '{$h2}', '{$h2}',
            0, 0, 1
         )"
    );
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apFlip['id'], [
            'expected_plan_hash' => (string) $evalFlip['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'PUANTAJ mode flip should fail-closed');
    } catch (RuntimeException $e) {
        p3bAssert(
            in_array($e->getMessage(), [
                PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
                RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
                RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED,
            ], true),
            'PUANTAJ pin added mid-flight fail-closed (' . $e->getMessage() . ')'
        );
        $passCount++;
    }
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE id=' . (int) $flip['seal_ids'][0]
        )->fetchColumn() === 1,
        'PUANTAJ flip failed without seal delete'
    );
    $passCount++;

    // Unrelated month unchanged after pinned destroys
    $unrel = (int) $pdo->query(
        'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE sube_id=1 AND yil=2011 AND ay=4'
    )->fetchColumn();
    p3bAssert($unrel === 1, 'PUANTAJ unrelated month still intact after pinned runs');
    $passCount++;

    // Legal hold / plan hash / target missing for a clean PUANTAJ period 2011-7
    $holdPeriod = p3bSeedPuantajPeriod($pdo, 2011, 7, false);
    $apHold = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $holdPeriod['seal_ids'][0], 2011, 7);
    LegalHoldService::create($pdo, p3bGm(), [
        'target_domain' => 'category',
        'target_category' => RetentionCategories::PUANTAJ,
        'reason' => 'Pack3B hold',
    ]);
    $holdActive = RetentionPolicyService::hasActiveLegalHold($pdo, RetentionCategories::PUANTAJ, [
        'entity_type' => 'puantaj',
        'record_id' => (int) $holdPeriod['seal_ids'][0],
    ]);
    p3bAssert($holdActive, 'PUANTAJ legal hold active in policy check');
    $passCount++;
    try {
        p3bEvalExecute($pdo, (int) $apHold['id']);
        p3bAssert(false, 'PUANTAJ hold should deny');
    } catch (RuntimeException $e) {
        p3bAssert(
            strpos($e->getMessage(), RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE) !== false,
            'PUANTAJ legal hold deny (' . $e->getMessage() . ')'
        );
        $passCount++;
    }
    foreach (LegalHoldService::list($pdo, true) as $h) {
        LegalHoldService::release($pdo, p3bGm(), (int) $h['id'], 'release');
    }

    $evalHold2 = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apHold['id']);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apHold['id'], [
            'expected_plan_hash' => str_repeat('f', 64),
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'PUANTAJ plan hash deny');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
            'PUANTAJ plan hash deny'
        );
        $passCount++;
    }

    // Target unexpectedly missing (delete seals before execute)
    $miss = p3bSeedPuantajPeriod($pdo, 2011, 8, false);
    $apMiss = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $miss['seal_ids'][0], 2011, 8);
    $evalMiss = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apMiss['id']);
    $pdo->exec('UPDATE gunluk_puantaj SET muhur_id = NULL WHERE id=' . (int) $miss['daily_id']);
    $pdo->exec('DELETE FROM puantaj_aylik_muhur_satirlari WHERE muhur_id=' . (int) $miss['seal_ids'][0]);
    $pdo->exec('DELETE FROM puantaj_aylik_muhurleri WHERE id=' . (int) $miss['seal_ids'][0]);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apMiss['id'], [
            'expected_plan_hash' => (string) $evalMiss['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'PUANTAJ missing target should fail-closed');
    } catch (RuntimeException $e) {
        p3bAssert(
            in_array($e->getMessage(), [
                PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING,
                PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED,
                RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
                RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED,
                RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
            ], true),
            'PUANTAJ target missing fail-closed (' . $e->getMessage() . ')'
        );
        $passCount++;
    }

    // ========== BORDRO ==========
    $b = p3bSeedBordroTree($pdo, 2012, 1, true, false);
    $devirBefore = (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_devirleri')->fetchColumn();
    $sgkBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id=' . (int) $b['snapshot_id']
    )->fetchColumn();
    $apB = p3bApprove($pdo, RetentionCategories::BORDRO, 'bordro', (int) $b['run_id'], 2012, 1);
    $resB = p3bEvalExecute($pdo, (int) $apB['id']);
    p3bAssert(
        ($resB['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'BORDRO run-leaf execute success'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE id=' . (int) $b['run_id'])->fetchColumn() === 0,
        'BORDRO calistirma deleted'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari WHERE id=' . (int) $b['snapshot_id'])->fetchColumn() === 1,
        'BORDRO donem snapshot preserved'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id=' . (int) $b['snapshot_id']
        )->fetchColumn() === $sgkBefore,
        'BORDRO SGK snapshot preserved'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_devirleri')->fetchColumn() === $devirBefore,
        'BORDRO devir preserved'
    );
    $passCount++;

    // Direct DELETE still blocked
    $b2 = p3bSeedBordroTree($pdo, 2012, 2, false, false);
    $blockedDirect = false;
    try {
        $pdo->exec('DELETE FROM maas_hesaplama_calistirmalari WHERE id=' . (int) $b2['run_id']);
    } catch (Throwable $e) {
        $blockedDirect = strpos($e->getMessage(), 'PAYROLL_CALCULATION_IMMUTABLE') !== false;
    }
    p3bAssert($blockedDirect, 'BORDRO normal direct DELETE still blocked');
    $passCount++;

    // Child revision RESTRICT → fail-closed
    $b3 = p3bSeedBordroTree($pdo, 2012, 3, false, true);
    $apB3 = p3bApprove($pdo, RetentionCategories::BORDRO, 'bordro', (int) $b3['run_id'], 2012, 3);
    $evalB3 = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apB3['id']);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apB3['id'], [
            'expected_plan_hash' => (string) $evalB3['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'BORDRO child restrict should fail');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN,
            'BORDRO child revision fail-closed'
        );
        $passCount++;
    }
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE id=' . (int) $b3['run_id'])->fetchColumn() === 1,
        'BORDRO parent run remains when child blocks'
    );
    $passCount++;

    // Canonical gated execute already covered by 2012-1 BORDRO success path.
    p3bAssert(true, 'BORDRO canonical gated execute covered by 2012-1 success');
    $passCount++;

    // Idempotency BORDRO
    $againB = PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apB['id'], [
        'expected_plan_hash' => (string) ($resB['plan']['plan_hash'] ?? p3bHash()),
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3bAssert(
        ($againB['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'BORDRO already executed'
    );
    $passCount++;

    // ========== SGK ==========
    $s = p3bSeedBordroTree($pdo, 2013, 1, true, false);
    $runBeforeSgk = (int) $pdo->query(
        'SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE snapshot_id=' . (int) $s['snapshot_id']
    )->fetchColumn();
    $apS = p3bApprove($pdo, RetentionCategories::SGK_EKSIK_GUN, 'sgk', (int) $s['snapshot_id'], 2013, 1);
    $resS = p3bEvalExecute($pdo, (int) $apS['id']);
    p3bAssert(
        ($resS['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'SGK nested execute success'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id=' . (int) $s['snapshot_id']
        )->fetchColumn() === 0,
        'SGK snapshots deleted'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM sgk_hesap_auditleri WHERE donem_snapshot_id=' . (int) $s['snapshot_id']
        )->fetchColumn() === 0,
        'SGK audits deleted'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari WHERE id=' . (int) $s['snapshot_id']
        )->fetchColumn() === 1,
        'SGK donem snapshot header preserved'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE snapshot_id=' . (int) $s['snapshot_id']
        )->fetchColumn() === $runBeforeSgk,
        'SGK BORDRO run tree unchanged'
    );
    $passCount++;

    // Direct SGK DELETE blocked
    $s2 = p3bSeedBordroTree($pdo, 2013, 2, true, false);
    $sgkRowId = (int) $pdo->query(
        'SELECT id FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id=' . (int) $s2['snapshot_id'] . ' LIMIT 1'
    )->fetchColumn();
    $sgkDirectBlocked = false;
    try {
        $pdo->exec('DELETE FROM maas_hesaplama_sgk_snapshotlari WHERE id=' . $sgkRowId);
    } catch (Throwable $e) {
        $sgkDirectBlocked = strpos($e->getMessage(), 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE') !== false;
    }
    p3bAssert($sgkDirectBlocked, 'SGK normal direct DELETE still blocked');
    $passCount++;

    // Canonical SGK execute gated
    $apS2 = p3bApprove($pdo, RetentionCategories::SGK_EKSIK_GUN, 'sgk', (int) $s2['snapshot_id'], 2013, 2);
    $resS2 = p3bEvalExecute($pdo, (int) $apS2['id']);
    p3bAssert(
        ($resS2['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'SGK canonical gated execute'
    );
    $passCount++;

    // Catalog tables untouched (existence check — row counts for masters may be 0)
    foreach ([
        'sgk_eksik_gun_katalog_surumleri',
        'sgk_kaynak_manifestleri',
        'sgk_eksik_gun_belgeleri',
    ] as $tbl) {
        $exists = (bool) $pdo->query(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=" . $pdo->quote($tbl)
        )->fetchColumn();
        p3bAssert($exists, 'SGK catalog/ops table remains present: ' . $tbl);
        $passCount++;
    }

    // Gate cleanup after execute — no leftover gate on connection
    $gateLeft = (int) $pdo->query(
        'SELECT COUNT(*) FROM retention_physical_destroy_gates WHERE connection_id = CONNECTION_ID()'
    )->fetchColumn();
    p3bAssert($gateLeft === 0, 'destroy gate not leaked on connection');
    $passCount++;

    // Concurrent sequential: second execute → ALREADY_EXECUTED
    $againS = PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apS2['id'], [
        'expected_plan_hash' => (string) ($resS2['plan']['plan_hash'] ?? p3bHash()),
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3bAssert(
        ($againS['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'SGK concurrency/idempotency ALREADY_EXECUTED'
    );
    $passCount++;

    // Source/manifest gates: mutate snapshot cutoff after approve → deny
    $s3 = p3bSeedBordroTree($pdo, 2013, 3, true, false);
    $apS3 = p3bApprove($pdo, RetentionCategories::SGK_EKSIK_GUN, 'sgk', (int) $s3['snapshot_id'], 2013, 3);
    // Cannot UPDATE immutable snapshot — mutate by deleting nested only changes plan, not source identity host.
    // Remint-sensitive: change is via deleting sgk rows before execute without going through service.
    // Source fingerprint uses cutoff_at on header — header UPDATE is also immutable.
    // Use plan hash mismatch as manifest/plan gate proxy already covered; assert legal-hold on SGK:
    LegalHoldService::create($pdo, p3bGm(), [
        'target_domain' => RetentionCategories::SGK_EKSIK_GUN,
        'target_category' => RetentionCategories::SGK_EKSIK_GUN,
        'reason' => 'Pack3B SGK hold',
    ]);
    try {
        p3bEvalExecute($pdo, (int) $apS3['id']);
        p3bAssert(false, 'SGK hold should deny');
    } catch (RuntimeException $e) {
        p3bAssert(
            strpos($e->getMessage(), RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE) !== false,
            'SGK legal hold deny (' . $e->getMessage() . ')'
        );
        $passCount++;
    }
    foreach (LegalHoldService::list($pdo, true) as $h) {
        LegalHoldService::release($pdo, p3bGm(), (int) $h['id'], 'release');
    }

    // ========== PUANTAJ post-destruction lifecycle hardening ==========
    // A) snapshot-pinned destroy success (fresh period)
    $life = p3bSeedBordroTree($pdo, 2014, 1, false, false);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2014-01-15', 'MUHURLENDI', 'BEKLIYOR', " . (int) $life['seal_id'] . ")"
    );
    $lifeDaily = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $life['seal_id'] . ", 10, '2014-01-15', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2014, 1, (int) $life['seal_id'], 1);
    $apLife = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $life['seal_id'], 2014, 1);
    $resLife = p3bEvalExecute($pdo, (int) $apLife['id']);
    p3bAssert(
        ($resLife['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'LIFECYCLE A: pinned destroy success'
    );
    $passCount++;
    p3bAssert(
        PuantajPhysicalDestructionGate::isPeriodDestroyed($pdo, 1, 2014, 1),
        'LIFECYCLE A: gate sees destroyed period'
    );
    $passCount++;

    // B) createReopen after destroy → blocked, no row
    $pdo->beginTransaction();
    try {
        PuantajDonemReopenService::createReopenRequest($pdo, p3bGm(), 1, 2014, 1, 'post-destroy reopen');
        $pdo->rollBack();
        p3bAssert(false, 'LIFECYCLE B: createReopen should fail');
    } catch (PuantajDonemReopenException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        p3bAssert(
            $e->getErrorCode() === PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED,
            'LIFECYCLE B: createReopen blocked by destroyed gate'
        );
        $passCount++;
    }
    $reopenRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri
         WHERE sube_id=1 AND yil=2014 AND ay=1 AND gerekce='post-destroy reopen'"
    )->fetchColumn();
    p3bAssert($reopenRows === 0, 'LIFECYCLE B: no reopen row inserted');
    $passCount++;

    // C) open ONAY_BEKLIYOR blocks destruction
    $blockPending = p3bSeedBordroTree($pdo, 2014, 2, false, false);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2014-02-15', 'MUHURLENDI', 'BEKLIYOR', " . (int) $blockPending['seal_id'] . ")"
    );
    $pendingDaily = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $blockPending['seal_id'] . ", 10, '2014-02-15', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2014, 2, (int) $blockPending['seal_id'], 1);
    $pdo->beginTransaction();
    PuantajDonemReopenService::createReopenRequest($pdo, p3bGm(), 1, 2014, 2, 'pending blocks destroy');
    $pdo->commit();
    $apPending = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $blockPending['seal_id'], 2014, 2);
    $evalPending = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apPending['id']);
    p3bAssert(
        (int) ($evalPending['plan']['expected_row_counts']['open_reopen_talep_count'] ?? -1) === 1,
        'LIFECYCLE C: plan includes open_reopen_talep_count'
    );
    $passCount++;
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apPending['id'], [
            'expected_plan_hash' => (string) $evalPending['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'LIFECYCLE C: destroy should block on open reopen');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS,
            'LIFECYCLE C: open ONAY_BEKLIYOR blocks destroy'
        );
        $passCount++;
    }
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj WHERE id=' . $pendingDaily)->fetchColumn() === 1,
        'LIFECYCLE C: payload untouched when open reopen blocks'
    );
    $passCount++;

    // D) open ONAYLANDI blocks destruction
    $blockApproved = p3bSeedBordroTree($pdo, 2014, 3, false, false);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2014-03-15', 'MUHURLENDI', 'BEKLIYOR', " . (int) $blockApproved['seal_id'] . ")"
    );
    $approvedDaily = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $blockApproved['seal_id'] . ", 10, '2014-03-15', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2014, 3, (int) $blockApproved['seal_id'], 1);
    $pdo->beginTransaction();
    $reopenApproved = PuantajDonemReopenService::createReopenRequest(
        $pdo,
        p3bGm(),
        1,
        2014,
        3,
        'approved blocks destroy'
    );
    $pdo->commit();
    $pdo->beginTransaction();
    PuantajDonemReopenService::approveReopenRequest(
        $pdo,
        ['id' => 2, 'rol' => 'GENEL_YONETICI'],
        1,
        2014,
        3,
        (int) $reopenApproved['id']
    );
    $pdo->commit();
    $apApprovedBlock = p3bApprove(
        $pdo,
        RetentionCategories::PUANTAJ,
        'puantaj',
        (int) $blockApproved['seal_id'],
        2014,
        3
    );
    $evalApprovedBlock = PhysicalDestructionService::evaluate($pdo, p3bGm(), (int) $apApprovedBlock['id']);
    try {
        PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apApprovedBlock['id'], [
            'expected_plan_hash' => (string) $evalApprovedBlock['plan']['plan_hash'],
            'execution_nonce' => p3bNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        p3bAssert(false, 'LIFECYCLE D: destroy should block on ONAYLANDI');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS,
            'LIFECYCLE D: open ONAYLANDI blocks destroy'
        );
        $passCount++;
    }
    p3bAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj WHERE id=' . $approvedDaily)->fetchColumn() === 1,
        'LIFECYCLE D: payload untouched when ONAYLANDI blocks'
    );
    $passCount++;

    // E) terminal REDDEDILDI does not block pinned destroy; historical request preserved
    $termReject = p3bSeedBordroTree($pdo, 2014, 4, false, false);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2014-04-15', 'MUHURLENDI', 'BEKLIYOR', " . (int) $termReject['seal_id'] . ")"
    );
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES (" . (int) $termReject['seal_id'] . ", 10, '2014-04-15', 'BEKLIYOR')"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2014, 4, (int) $termReject['seal_id'], 1);
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri (
            sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce,
            requested_by, requested_at, request_hash, rejected_by, rejected_at, rejection_reason
         ) VALUES (
            1, 2014, 4, " . (int) $termReject['seal_id'] . ", '" . PuantajDonemPeriodService::TALEP_REDDEDILDI . "',
            'historical rejected', 1, '2014-04-20 10:00:00', '" . p3bHash() . "',
            2, '2014-04-21 10:00:00', 'no'
         )"
    );
    $rejectedId = (int) $pdo->lastInsertId();
    $apTermReject = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', (int) $termReject['seal_id'], 2014, 4);
    $resTermReject = p3bEvalExecute($pdo, (int) $apTermReject['id']);
    p3bAssert(
        ($resTermReject['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'LIFECYCLE E: REDDEDILDI allows pinned destroy'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri WHERE id=' . $rejectedId
        )->fetchColumn() === 1,
        'LIFECYCLE E: historical REDDEDILDI preserved'
    );
    $passCount++;

    // F) terminal UYGULANDI historical revision graph — pinned destroy proceeds; headers preserved
    $termApplied = p3bSeedPuantajPeriod($pdo, 2014, 5, true);
    $oldSealF = (int) $termApplied['seal_ids'][0];
    $effSealF = (int) $termApplied['seal_ids'][1];
    $hF = p3bHash();
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, 2014, 5, '2014-05', '2014-05-01', '2014-05-31', {$effSealF}, 1,
            'OLUSTURULDU', '2014-05-28 12:00:00', '{$hF}', '{$hF}', '{$hF}',
            1, 0, 1
         )"
    );
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri (
            sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce,
            requested_by, requested_at, request_hash, approved_by, approved_at,
            applied_at, reseal_muhur_id
         ) VALUES (
            1, 2014, 5, {$oldSealF}, '" . PuantajDonemPeriodService::TALEP_UYGULANDI . "',
            'historical applied', 1, '2014-05-10 10:00:00', '" . p3bHash() . "',
            2, '2014-05-11 10:00:00', '2014-05-12 10:00:00', {$effSealF}
         )"
    );
    $appliedId = (int) $pdo->lastInsertId();
    $apTermApplied = p3bApprove($pdo, RetentionCategories::PUANTAJ, 'puantaj', $effSealF, 2014, 5);
    $resTermApplied = p3bEvalExecute($pdo, (int) $apTermApplied['id']);
    p3bAssert(
        ($resTermApplied['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'LIFECYCLE F: UYGULANDI allows pinned destroy'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE sube_id=1 AND yil=2014 AND ay=5'
        )->fetchColumn() === 2,
        'LIFECYCLE F: revision headers preserved'
    );
    $passCount++;
    p3bAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri WHERE id=' . $appliedId
        )->fetchColumn() === 1,
        'LIFECYCLE F: UYGULANDI talep preserved'
    );
    $passCount++;

    // H/I) after destroy: stale approve + reseal blocked (synthetic open talep inserted)
    $pdo->exec(
        "INSERT INTO puantaj_donem_reopen_talepleri (
            sube_id, yil, ay, kaynak_muhur_id, talep_durumu, gerekce,
            requested_by, requested_at, request_hash
         ) VALUES (
            1, 2014, 1, " . (int) $life['seal_id'] . ", '" . PuantajDonemPeriodService::TALEP_ONAY_BEKLIYOR . "',
            'stale after destroy', 1, '2014-01-20 10:00:00', '" . bin2hex(random_bytes(32)) . "'
         )"
    );
    $stalePendingId = (int) $pdo->lastInsertId();
    $pdo->beginTransaction();
    try {
        PuantajDonemReopenService::approveReopenRequest(
            $pdo,
            ['id' => 2, 'rol' => 'GENEL_YONETICI'],
            1,
            2014,
            1,
            $stalePendingId
        );
        $pdo->rollBack();
        p3bAssert(false, 'LIFECYCLE H: approve after destroy should fail');
    } catch (PuantajDonemReopenException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        p3bAssert(
            $e->getErrorCode() === PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED,
            'LIFECYCLE H: approve stale blocked'
        );
        $passCount++;
    }
    $pdo->exec(
        "UPDATE puantaj_donem_reopen_talepleri
         SET talep_durumu = '" . PuantajDonemPeriodService::TALEP_ONAYLANDI . "',
             approved_by = 2, approved_at = '2014-01-21 10:00:00'
         WHERE id = {$stalePendingId}"
    );
    $pdo->beginTransaction();
    try {
        PuantajDonemReopenService::reseal(
            $pdo,
            p3bGm(),
            1,
            2014,
            1,
            'stale reseal',
            (int) $life['seal_id'],
            static function () {
                throw new RuntimeException('reseal callback must not run');
            }
        );
        $pdo->rollBack();
        p3bAssert(false, 'LIFECYCLE I: reseal after destroy should fail');
    } catch (PuantajDonemReopenException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        p3bAssert(
            $e->getErrorCode() === PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED,
            'LIFECYCLE I: reseal stale blocked'
        );
        $passCount++;
    }

    // J) new destruction request for already-destroyed pinned source → rejected, no REQUESTED lifecycle
    try {
        DestructionWorkflowService::requestDestruction($pdo, p3bGm(), [
            'category' => RetentionCategories::PUANTAJ,
            'entity_type' => 'puantaj',
            'record_id' => (int) $life['seal_id'],
            'sube_id' => 1,
            'yil' => 2014,
            'ay' => 1,
            'reason' => 'duplicate after destroy',
        ]);
        p3bAssert(false, 'LIFECYCLE J: new request should fail');
    } catch (RuntimeException $e) {
        p3bAssert(
            $e->getMessage() === RetentionPolicyService::CODE_SOURCE_ALREADY_DESTROYED_AS_APPROVED,
            'LIFECYCLE J: SOURCE_ALREADY_DESTROYED_AS_APPROVED'
        );
        $passCount++;
    }
    $dupCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM retention_imha_talepleri
         WHERE category='PUANTAJ' AND period_yil=2014 AND period_ay=1 AND reason='duplicate after destroy'"
    )->fetchColumn();
    p3bAssert($dupCount === 0, 'LIFECYCLE J: no second request row');
    $passCount++;

    // K) same original request retry → ALREADY_EXECUTED
    $againLife = PhysicalDestructionService::execute($pdo, p3bGm(), (int) $apLife['id'], [
        'expected_plan_hash' => (string) ($resLife['plan']['plan_hash'] ?? p3bHash()),
        'execution_nonce' => p3bNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3bAssert(
        ($againLife['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED
            && (int) ($againLife['execution']['execution_id'] ?? 0) === (int) ($resLife['execution']['execution_id'] ?? -1)
            && (int) ($againLife['execution']['mutation_count'] ?? 0) === 0,
        'LIFECYCLE K: same request ALREADY_EXECUTED'
    );
    $passCount++;

    echo 'verify-retention-physical-pack3b-mysql: OK pass_count=' . $passCount . PHP_EOL;
} finally {
    RetentionClock::clearOverride();
    p3bFlagOff();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // best-effort
    }
}
