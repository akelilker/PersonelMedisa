<?php

declare(strict_types=1);

/**
 * Pack 3C: disposable MariaDB — remaining physical destruction categories.
 * php tests/php/RetentionPhysicalPack3cMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\LegalHoldService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\PhysicalDestruction\RetentionDestructionHandlerRegistry;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;

/** Real builder semantics (HaftalikKapanisController::buildSnapshotSatir) — not FM-owned. */
const P3C_NOTLAR_COMPLETENESS_SENTINEL =
    '["Eksik haftalik puantaj gunu (5/7); UBGT ve 18 yas alti haftalik uyarilari uretilmedi."]';

const P3C_COMPLIANCE_JSON = '[{"kod":"WEEKLY_COMPLETENESS","seviye":"INFO"}]';

function p3cAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p3cRootPdo(): PDO
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
function p3cSplitSql(string $sql): array
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

function p3cApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p3cSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p3cPdoForDb(string $database): PDO
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
function p3cMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql';
    }));
    sort($files, SORT_STRING);

    return $files;
}

function p3cAssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }
    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: DSN contains karmotor_medisa\n");
        exit(1);
    }
    if (!preg_match('/host=([^;]+)/i', $dsn, $m)) {
        return;
    }
    $host = strtolower(trim($m[1]));
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
}

function p3cFlagOn(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=1');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '1';
}

function p3cFlagOff(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=0');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '0';
}

function p3cNonce(): string
{
    return bin2hex(random_bytes(32));
}

/** @return array{id:int,rol:string} */
function p3cGm(): array
{
    return ['id' => 1, 'rol' => 'GENEL_YONETICI'];
}

function p3cSeedBase(PDO $pdo): void
{
    $hash = password_hash('P3cPack3TestPass-24chars!', PASSWORD_BCRYPT);
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
         (10, '11111111110', 'Aktif', 'Bir', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S010', '2010-01-01', 1, 'AKTIF'),
         (11, '11111111111', 'Aktif', 'Iki', '1990-01-01', '05000000002', 'Acil', '05000000003',
            'S011', '2010-01-01', 1, 'PASIF'),
         (20, '22222222220', 'Diger', 'Personel', '1990-01-01', '05000000004', 'Acil', '05000000005',
            'S020', '2010-01-01', 1, 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
}

/**
 * @return array{kapanis_id:int,satir_id:int,tercih_id:int,olusum_id:int}
 */
function p3cSeedHaftalikGraph(PDO $pdo, string $haftaBaslangic, int $personelId = 10, int $olusumDakika = 300): array
{
    $haftaBitis = date('Y-m-d', strtotime($haftaBaslangic . ' +6 days'));
    $pdo->exec(
        "INSERT INTO haftalik_kapanislar
            (sube_id, hafta_baslangic, hafta_bitis, state, personel_sayisi, snapshot_satir_sayisi, created_by)
         VALUES (1, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI', 1, 1, 1)"
    );
    $kapanisId = (int) $pdo->lastInsertId();
    $notlar = str_replace("'", "''", P3C_NOTLAR_COMPLETENESS_SENTINEL);
    $compliance = str_replace("'", "''", P3C_COMPLIANCE_JSON);
    $pdo->exec(
        "INSERT INTO haftalik_kapanis_satirlari (
            kapanis_id, personel_id, hafta_baslangic, hafta_bitis, state,
            toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika, fazla_surelerle_calisma_dakika,
            tam_hafta_verisi, compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
            hesaplama_zamani, kaynak_gun_sayisi, notlar_json
         ) VALUES (
            {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI',
            3000, 2700, {$olusumDakika}, 0,
            0, '{$compliance}', 1, 0,
            '{$haftaBaslangic} 18:00:00', 5, '{$notlar}'
         )"
    );
    $satirId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO fazla_calisma_odeme_tercihleri (
            snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
            fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, gerekce
         ) VALUES (
            {$satirId}, {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}',
            {$olusumDakika}, 'SERBEST_ZAMAN', '{$haftaBaslangic} 19:00:00', 1, 'synthetic-gerekce'
         )"
    );
    $tercihId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO fazla_calisma_odeme_tercihi_audit (
            tercih_id, snapshot_id, onceki_odeme_tipi, yeni_odeme_tipi,
            secen_kullanici_id, secim_zamani, gerekce
         ) VALUES (
            {$tercihId}, {$satirId}, 'KARAR_BEKLIYOR', 'SERBEST_ZAMAN',
            1, '{$haftaBaslangic} 19:00:00', 'audit-gerekce'
         )"
    );
    $sonKullanim = date('Y-m-d', strtotime($haftaBaslangic . ' +6 months'));
    $pdo->exec(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, son_kullanim_tarihi,
            kaynak_snapshot_id, kaynak_odeme_tercihi_id, created_by
         ) VALUES (
            {$personelId}, 'SERBEST_ZAMAN_OLUSUM', {$olusumDakika}, '{$haftaBaslangic}', '{$sonKullanim}',
            {$satirId}, {$tercihId}, 1
         )"
    );
    $olusumId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO serbest_zaman_aktif_olusumlar (odeme_tercihi_id, olusum_event_id)
         VALUES ({$tercihId}, {$olusumId})"
    );
    ArchiveManifestService::createHaftalikPeriodManifests($pdo, $kapanisId, 1, $haftaBaslangic, 1);

    return [
        'kapanis_id' => $kapanisId,
        'satir_id' => $satirId,
        'tercih_id' => $tercihId,
        'olusum_id' => $olusumId,
    ];
}

/**
 * Schema 029: KULLANIM must have NULL snapshot/tercih/hedef + non-null islem_anahtari.
 */
function p3cInsertKullanim(PDO $pdo, int $personelId, int $dakika, string $eventTarihi, string $anahtar): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, islem_anahtari, created_by
         ) VALUES (
            :pid, 'SERBEST_ZAMAN_KULLANIM', :dakika, :tarih, :anahtar, 1
         )"
    );
    $stmt->execute([
        'pid' => $personelId,
        'dakika' => $dakika,
        'tarih' => $eventTarihi,
        'anahtar' => $anahtar,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Canonical bakiye (global pool): Σ OLUSUM − Σ KULLANIM (IPTAL of either skipped).
 *
 * @return array{toplam_hak:int,kullanilan:int,kalan:int}
 */
function p3cSzBalance(PDO $pdo, int $personelId): array
{
    $events = $pdo->prepare(
        'SELECT id, event_tipi, dakika, yeni_dakika, hedef_event_id, hedef_event_tipi
         FROM serbest_zaman_events WHERE personel_id = :pid ORDER BY id ASC'
    );
    $events->execute(['pid' => $personelId]);
    $rows = $events->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $iptal = [];
    foreach ($rows as $e) {
        if (($e['event_tipi'] ?? '') === 'SERBEST_ZAMAN_IPTAL' && (int) ($e['hedef_event_id'] ?? 0) > 0) {
            $iptal[(int) $e['hedef_event_id']] = true;
        }
    }
    $overrides = [];
    foreach ($rows as $e) {
        if (($e['event_tipi'] ?? '') === 'SERBEST_ZAMAN_DUZELTME'
            && (int) ($e['hedef_event_id'] ?? 0) > 0
            && !isset($iptal[(int) $e['id']])
        ) {
            $overrides[(int) $e['hedef_event_id']] = (int) $e['yeni_dakika'];
        }
    }
    $toplam = 0;
    $kullanilan = 0;
    foreach ($rows as $e) {
        $eid = (int) ($e['id'] ?? 0);
        if (isset($iptal[$eid])) {
            continue;
        }
        $tip = (string) ($e['event_tipi'] ?? '');
        $dakika = isset($overrides[$eid]) ? $overrides[$eid] : (int) ($e['dakika'] ?? 0);
        if ($tip === 'SERBEST_ZAMAN_OLUSUM') {
            $toplam += $dakika;
        } elseif ($tip === 'SERBEST_ZAMAN_KULLANIM') {
            $kullanilan += $dakika;
        }
    }

    return [
        'toplam_hak' => $toplam,
        'kullanilan' => $kullanilan,
        'kalan' => max($toplam - $kullanilan, 0),
    ];
}

/**
 * Attach PERSONEL_BELGE file/meta to an arbitrary surec (038 FK → surecler.id).
 *
 * @return array{surum_id:int,storage_key:string}
 */
function p3cAttachBelge(PDO $pdo, int $surecId, int $personelId, string $label): array
{
    $fileMeta = PersonelBelgeStorageService::writeNewVersion('%PDF-1.4 pack3c-' . $label, 'pdf');
    $insV = $pdo->prepare(
        'INSERT INTO personel_belge_dosya_surumleri
            (surec_id, personel_id, surum_no, aktif_mi, storage_key, orijinal_dosya_adi,
             mime_type, uzanti, byte_boyutu, sha256, yukleyen_kullanici_id)
         VALUES
            (:sid, :pid, 1, 1, :key, :name, :mime, :ext, :bytes, :sha, 1)'
    );
    $insV->execute([
        'sid' => $surecId,
        'pid' => $personelId,
        'key' => $fileMeta['storage_key'],
        'name' => $label . '.pdf',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
        'bytes' => $fileMeta['byte_boyutu'],
        'sha' => $fileMeta['sha256'],
    ]);
    $surumId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO personel_belge_auditleri
            (surec_id, personel_id, belge_surum_id, islem_turu, yapan_kullanici_id)
         VALUES (:sid, :pid, :vid, \'CREATED\', 1)'
    )->execute(['sid' => $surecId, 'pid' => $personelId, 'vid' => $surumId]);

    return ['surum_id' => $surumId, 'storage_key' => (string) $fileMeta['storage_key']];
}

/**
 * Clear PERSONEL_BELGE-owned leaf rows for a surec (no cascade of the parent surec).
 * Mirrors PersonelBelgeDestructionHandler leaf order without requiring surec_turu=BELGE.
 */
function p3cClearBelgeLeaves(PDO $pdo, int $surecId): void
{
    $keys = $pdo->prepare(
        'SELECT storage_key FROM personel_belge_dosya_surumleri WHERE surec_id = :sid'
    );
    $keys->execute(['sid' => $surecId]);
    while ($key = $keys->fetchColumn()) {
        $key = trim((string) $key);
        if ($key === '') {
            continue;
        }
        try {
            PersonelBelgeStorageService::deleteKey($key);
        } catch (Throwable $e) {
            // absent file ok for cleanup
        }
    }
    $pdo->prepare('DELETE FROM personel_belge_auditleri WHERE surec_id = :sid')->execute(['sid' => $surecId]);
    $pdo->prepare('DELETE FROM personel_belge_dosya_surumleri WHERE surec_id = :sid')->execute(['sid' => $surecId]);
}

/**
 * @return array<string, mixed>
 */
function p3cApprove(PDO $pdo, string $category, string $entityType, int $recordId, array $extra = []): array
{
    $payload = array_merge([
        'category' => $category,
        'entity_type' => $entityType,
        'record_id' => $recordId,
        'reason' => 'Pack3C ' . $category,
    ], $extra);
    $req = DestructionWorkflowService::requestDestruction($pdo, p3cGm(), $payload);
    if ((string) ($req['item']['status'] ?? '') !== DestructionWorkflowService::STATUS_REQUESTED) {
        throw new RuntimeException(
            'request failed ' . $category . ': ' . (string) ($req['eligibility']['code'] ?? '?')
        );
    }

    return DestructionWorkflowService::approveDestruction(
        $pdo,
        p3cGm(),
        (int) $req['item']['id'],
        'GM Pack3C',
        true
    );
}

/**
 * @return array<string, mixed>
 */
function p3cEvalExecute(PDO $pdo, int $talepId): array
{
    $eval = PhysicalDestructionService::evaluate($pdo, p3cGm(), $talepId);
    if (($eval['execution']['code'] ?? '') !== RetentionPolicyService::CODE_APPROVED_FOR_DESTRUCTION) {
        throw new RuntimeException('not eligible: ' . (string) ($eval['execution']['code'] ?? '?'));
    }
    $plan = $eval['plan'];
    if (!is_array($plan) || empty($plan['plan_hash'])) {
        throw new RuntimeException('plan missing');
    }

    return PhysicalDestructionService::execute($pdo, p3cGm(), $talepId, [
        'expected_plan_hash' => (string) $plan['plan_hash'],
        'execution_nonce' => p3cNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
}

function p3cJsonHasPii(array $payload): bool
{
    $json = json_encode($payload);
    if ($json === false) {
        return true;
    }
    foreach (['11111111110', 'synthetic-gerekce', 'audit-gerekce', 'Aktif', '05000000000'] as $needle) {
        if (stripos($json, $needle) !== false) {
            return true;
        }
    }

    return false;
}

// --- main ---
$root = p3cRootPdo();
$database = 'medisa_ret_phys_pack3c_' . substr(bin2hex(random_bytes(4)), 0, 8);
p3cAssertSafeTarget($database);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p3cPdoForDb($database);

$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_p3c_belge_' . bin2hex(random_bytes(4));
if (!mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
    throw new RuntimeException('storage root create failed');
}
putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $storageRoot);
$_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $storageRoot;

try {
    foreach (p3cMigrationFiles() as $file) {
        p3cApply($pdo, $file);
    }
    p3cSeedBase($pdo);
    RetentionClock::setOverride(new DateTimeImmutable('2037-01-01'));
    p3cFlagOn();

    foreach (RetentionCategories::all() as $cat) {
        $h = RetentionDestructionHandlerRegistry::forCategory($cat);
        p3cAssert($h->isExecutable() === true, 'registry executable ' . $cat);
    }

    // ---------- SERBEST A: OLUSUM only, no KULLANIM → destroy allowed ----------
    $weekA = p3cSeedHaftalikGraph($pdo, '2010-01-04', 10);
    $weekB = p3cSeedHaftalikGraph($pdo, '2010-01-11', 20); // isolation personel+period

    // Snapshot shared weekly fields before FAZLA (after SERBEST) for exact-preserve asserts
    $satirABeforeFm = $pdo->query(
        'SELECT notlar_json, toplam_net_dakika, normal_calisma_dakika, tam_hafta_verisi,
                compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi, kaynak_gun_sayisi,
                fazla_calisma_dakika, fazla_surelerle_calisma_dakika
         FROM haftalik_kapanis_satirlari WHERE id = ' . (int) $weekA['satir_id']
    )->fetch(PDO::FETCH_ASSOC);

    // FAZLA blocked while SERBEST remains
    $apFazlaEarly = p3cApprove(
        $pdo,
        RetentionCategories::FAZLA_CALISMA,
        'haftalik_kapanis',
        (int) $weekA['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-01-04', 'haftalik_kapanis_id' => (int) $weekA['kapanis_id']]
    );
    try {
        p3cEvalExecute($pdo, (int) $apFazlaEarly['id']);
        p3cAssert(false, 'FAZLA should gate on SERBEST');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN,
            'FAZLA DEPENDENT while SERBEST remains'
        );
    }
    $szEventsBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekA['olusum_id']
    )->fetchColumn();
    p3cAssert($szEventsBefore === 1, 'SERBEST event untouched after FAZLA block');

    // SERBEST happy path — only week A, no KULLANIM
    $apSz = p3cApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekA['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-01-04', 'haftalik_kapanis_id' => (int) $weekA['kapanis_id']]
    );
    $exSz = p3cEvalExecute($pdo, (int) $apSz['id']);
    p3cAssert(
        ($exSz['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'SERBEST_OLUSUM_ONLY_DESTROY_ALLOWED'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE kaynak_snapshot_id = ' . (int) $weekA['satir_id']
        )->fetchColumn() === 0,
        'SERBEST week A events deleted'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE kaynak_snapshot_id = ' . (int) $weekB['satir_id']
        )->fetchColumn() === 1,
        'SERBEST week B / other personel preserved'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . (int) $weekA['kapanis_id']
        )->fetchColumn() === 1,
        'shared kapanis preserved after SERBEST'
    );

    $evalSz2 = PhysicalDestructionService::evaluate($pdo, p3cGm(), (int) $apSz['id']);
    p3cAssert(
        ($evalSz2['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'SERBEST ALREADY_EXECUTED evaluate'
    );
    $retrySz = PhysicalDestructionService::execute($pdo, p3cGm(), (int) $apSz['id'], [
        'expected_plan_hash' => (string) $evalSz2['execution']['plan_hash'],
        'execution_nonce' => p3cNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    p3cAssert(
        ($retrySz['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_ALREADY_EXECUTED,
        'SERBEST ALREADY_EXECUTED execute'
    );
    p3cAssert(
        (int) ($retrySz['execution']['mutation_count'] ?? 0) === 0,
        'SERBEST retry mutation_count=0'
    );

    // ---------- FAZLA: FM fields zero; shared weekly notes/compliance EXACT SAME ----------
    $exFc = p3cEvalExecute($pdo, (int) $apFazlaEarly['id']);
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE kapanis_id = ' . (int) $weekA['kapanis_id']
        )->fetchColumn() === 0,
        'FAZLA tercih deleted'
    );
    $satirA = $pdo->query(
        'SELECT notlar_json, toplam_net_dakika, normal_calisma_dakika, tam_hafta_verisi,
                compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi, kaynak_gun_sayisi,
                fazla_calisma_dakika, fazla_surelerle_calisma_dakika
         FROM haftalik_kapanis_satirlari WHERE id = ' . (int) $weekA['satir_id']
    )->fetch(PDO::FETCH_ASSOC);
    p3cAssert(is_array($satirA) && is_array($satirABeforeFm), 'FAZLA satır rows readable');
    p3cAssert(
        (int) $satirA['fazla_calisma_dakika'] === 0
            && (int) $satirA['fazla_surelerle_calisma_dakika'] === 0,
        'FAZLA FM fields zeroed'
    );
    p3cAssert(
        (string) $satirA['notlar_json'] === (string) $satirABeforeFm['notlar_json']
            && (string) $satirA['notlar_json'] === P3C_NOTLAR_COMPLETENESS_SENTINEL,
        'FAZLA_SHARED_NOTES_PRESERVED'
    );
    p3cAssert(
        (int) $satirA['toplam_net_dakika'] === (int) $satirABeforeFm['toplam_net_dakika']
            && (int) $satirA['normal_calisma_dakika'] === (int) $satirABeforeFm['normal_calisma_dakika']
            && (int) $satirA['tam_hafta_verisi'] === (int) $satirABeforeFm['tam_hafta_verisi']
            && (string) $satirA['compliance_uyarilari_json'] === (string) $satirABeforeFm['compliance_uyarilari_json']
            && (int) $satirA['compliance_uyari_sayisi'] === (int) $satirABeforeFm['compliance_uyari_sayisi']
            && (int) $satirA['kritik_uyari_var_mi'] === (int) $satirABeforeFm['kritik_uyari_var_mi']
            && (int) $satirA['kaynak_gun_sayisi'] === (int) $satirABeforeFm['kaynak_gun_sayisi'],
        'FAZLA shared weekly fields EXACT SAME'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT fazla_calisma_dakika FROM haftalik_kapanis_satirlari WHERE id = ' . (int) $weekB['satir_id']
        )->fetchColumn() === 300,
        'FAZLA other period/personel FM preserved'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM retention_imha_executionlari WHERE imha_talep_id = ' . (int) $apFazlaEarly['id']
        )->fetchColumn() === 1,
        'FAZLA execution evidence'
    );

    // ---------- SERBEST B: OLUSUM A+B + unallocated KULLANIM → A destroy BLOCKED ----------
    $weekC = p3cSeedHaftalikGraph($pdo, '2010-01-18', 10, 300); // OLUSUM A
    $weekD = p3cSeedHaftalikGraph($pdo, '2010-01-25', 10, 300); // OLUSUM B same personel
    $kullanimId = p3cInsertKullanim($pdo, 10, 300, '2010-02-01', 'sz-p3c-kullanim-10');
    $balBefore = p3cSzBalance($pdo, 10);
    p3cAssert($balBefore['kalan'] === 300, 'SERBEST balance before block kalan=300');
    $olusumCCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekC['olusum_id']
    )->fetchColumn();
    $olusumDCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekD['olusum_id']
    )->fetchColumn();
    $apSzBlock = p3cApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekC['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-01-18', 'haftalik_kapanis_id' => (int) $weekC['kapanis_id']]
    );
    try {
        p3cEvalExecute($pdo, (int) $apSzBlock['id']);
        p3cAssert(false, 'SERBEST should block on unallocated KULLANIM');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED,
            'SERBEST_UNALLOCATED_USAGE_BLOCK'
        );
    }
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekC['olusum_id']
        )->fetchColumn() === $olusumCCount
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekD['olusum_id']
            )->fetchColumn() === $olusumDCount
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . $kullanimId
            )->fetchColumn() === 1,
        'SERBEST A/B/KULLANIM untouched on block'
    );
    $balAfter = p3cSzBalance($pdo, 10);
    p3cAssert(
        $balAfter['toplam_hak'] === $balBefore['toplam_hak']
            && $balAfter['kullanilan'] === $balBefore['kullanilan']
            && $balAfter['kalan'] === $balBefore['kalan'],
        'SERBEST_BALANCE_UNCHANGED_ON_BLOCK'
    );

    // ---------- SERBEST C: KULLANIM correction/iptal chain → blocked ----------
    $weekE = p3cSeedHaftalikGraph($pdo, '2010-02-01', 11, 300);
    $kullanim11 = p3cInsertKullanim($pdo, 11, 120, '2010-02-05', 'sz-p3c-kullanim-11');
    $pdo->exec(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, yeni_dakika, event_tarihi, hedef_event_id, hedef_event_tipi,
            islem_anahtari, aciklama, created_by
         ) VALUES (
            11, 'SERBEST_ZAMAN_DUZELTME', 90, '2010-02-06', {$kullanim11}, 'SERBEST_ZAMAN_KULLANIM',
            'sz-p3c-duzeltme-11', 'correction chain', 1
         )"
    );
    $apSzCorr = p3cApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekE['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-02-01', 'haftalik_kapanis_id' => (int) $weekE['kapanis_id']]
    );
    try {
        p3cEvalExecute($pdo, (int) $apSzCorr['id']);
        p3cAssert(false, 'SERBEST should block on KULLANIM correction chain');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED,
            'SERBEST KULLANIM correction/iptal chain blocked'
        );
    }
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekE['olusum_id']
        )->fetchColumn() === 1,
        'SERBEST correction-chain OLUSUM untouched'
    );

    // ---------- SERBEST D/E: other personel KULLANIM does not block target; isolation ----------
    // Destroy weekB SERBEST (personel 20, no KULLANIM) while personel 10 still has unallocated KULLANIM.
    $apSzOther = p3cApprove(
        $pdo,
        RetentionCategories::SERBEST_ZAMAN,
        'haftalik_kapanis',
        (int) $weekB['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-01-11', 'haftalik_kapanis_id' => (int) $weekB['kapanis_id']]
    );
    $exSzOther = p3cEvalExecute($pdo, (int) $apSzOther['id']);
    p3cAssert(
        ($exSzOther['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'SERBEST other-personel KULLANIM does not block target'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . (int) $weekC['olusum_id']
        )->fetchColumn() === 1
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM serbest_zaman_events WHERE id = ' . $kullanimId
            )->fetchColumn() === 1,
        'SERBEST other period/personel isolation preserved'
    );

    // ---------- DISIPLIN × OLAY × SAVUNMA ----------
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, 11, 1);
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state, aciklama)
         VALUES (11, 'DISIPLIN', '2015-05-01', 'AKTIF', 'disc-note')"
    );
    $disiplinSurecId = (int) $pdo->lastInsertId();
    $hash = hash('sha256', 'p3c-disiplin-vaka-source');
    $pdo->exec(
        "INSERT INTO disiplin_vakalar (
            surec_id, personel_id, sube_id, tarih, ay, olay_turu, lifecycle_state,
            raw_dakika, source_identity, source_hash,
            savunma_konu, savunma_yer
         ) VALUES (
            {$disiplinSurecId}, 11, 1, '2015-05-01', '2015-05', 'GEC_KALMA', 'INCELEME_ADAYI',
            15, 'src:p3c:1', '{$hash}',
            'savunma-konu', 'savunma-yer'
         )"
    );
    $vakaId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO disiplin_vaka_auditleri (disiplin_vaka_id, action, actor_user_id, detail_json)
         VALUES ({$vakaId}, 'CREATE', 1, '{\"x\":1}')"
    );
    ArchiveManifestService::createTerminationScopedManifests($pdo, 11, 1);

    $apDisEarly = p3cApprove(
        $pdo,
        RetentionCategories::DISIPLIN,
        'surec',
        $disiplinSurecId,
        ['personel_id' => 11]
    );
    try {
        p3cEvalExecute($pdo, (int) $apDisEarly['id']);
        p3cAssert(false, 'DISIPLIN should gate on OLAY/SAVUNMA');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN,
            'DISIPLIN DEPENDENT before OLAY/SAVUNMA'
        );
    }

    $apOlay = p3cApprove($pdo, RetentionCategories::OLAY, 'disiplin_vaka', $vakaId, ['personel_id' => 11]);
    p3cEvalExecute($pdo, (int) $apOlay['id']);
    $apSav = p3cApprove($pdo, RetentionCategories::SAVUNMA, 'disiplin_vaka', $vakaId, ['personel_id' => 11]);
    p3cEvalExecute($pdo, (int) $apSav['id']);

    p3cEvalExecute($pdo, (int) $apDisEarly['id']);
    p3cAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM disiplin_vakalar WHERE id = ' . $vakaId)->fetchColumn() === 0,
        'DISIPLIN vaka deleted after gates'
    );
    p3cAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE id = ' . $disiplinSurecId)->fetchColumn() === 0,
        'DISIPLIN surec deleted'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM retention_imha_talepleri WHERE id = ' . (int) $apDisEarly['id']
        )->fetchColumn() === 1,
        'DISIPLIN request evidence retained'
    );

    // ---------- RAPOR / IS_KAZASI + PERSONEL_BELGE gate ----------
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'RAPOR', '2015-04-01', 'AKTIF')"
    );
    $raporId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (11, 'IS_KAZASI', '2015-03-01', 'AKTIF')"
    );
    $kazasiId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (20, 'RAPOR', '2015-04-01', 'AKTIF')"
    );
    $raporOtherId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
         VALUES (20, 'ISTEN_AYRILMA', '2015-06-01', 'AKTIF')"
    );
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = 20");
    ArchiveManifestService::createTerminationScopedManifests($pdo, 11, 1);
    ArchiveManifestService::createPersonelLifecycleManifests($pdo, 20, 1);
    ArchiveManifestService::createTerminationScopedManifests($pdo, 20, 1);

    $belgeRapor = p3cAttachBelge($pdo, $raporId, 11, 'rapor-attach');
    $belgeKazasi = p3cAttachBelge($pdo, $kazasiId, 11, 'kazasi-attach');
    $belgeOther = p3cAttachBelge($pdo, $raporOtherId, 20, 'other-rapor');

    $apRaporBlocked = p3cApprove($pdo, RetentionCategories::RAPOR, 'surec', $raporId, ['personel_id' => 11]);
    try {
        p3cEvalExecute($pdo, (int) $apRaporBlocked['id']);
        p3cAssert(false, 'RAPOR should gate on PERSONEL_BELGE');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PERSONEL_BELGE_REMAINS,
            'RAPOR_PERSONEL_BELGE_GATE'
        );
    }
    p3cAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE id = {$raporId}")->fetchColumn() === 1,
        'RAPOR surec remains after belge gate'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $raporId
        )->fetchColumn() === 1
            && (int) $pdo->query(
                'SELECT COUNT(*) FROM personel_belge_auditleri WHERE surec_id = ' . $raporId
            )->fetchColumn() === 1,
        'RAPOR belge file/meta remains'
    );

    $apKazBlocked = p3cApprove($pdo, RetentionCategories::IS_KAZASI, 'surec', $kazasiId, ['personel_id' => 11]);
    try {
        p3cEvalExecute($pdo, (int) $apKazBlocked['id']);
        p3cAssert(false, 'IS_KAZASI should gate on PERSONEL_BELGE');
    } catch (RuntimeException $e) {
        p3cAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PERSONEL_BELGE_REMAINS,
            'IS_KAZASI_PERSONEL_BELGE_GATE'
        );
    }

    // Cross-personel belge on 20 must not block clearing path for 11 after 11's belge removed.
    // PERSONEL_BELGE-owned leaves cleared first (no RAPOR/IS_KAZASI cascade).
    p3cClearBelgeLeaves($pdo, $raporId);
    p3cClearBelgeLeaves($pdo, $kazasiId);
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $raporOtherId
        )->fetchColumn() === 1,
        'cross-personel belge intact'
    );

    p3cEvalExecute($pdo, (int) $apRaporBlocked['id']);
    p3cAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE id = {$raporId}")->fetchColumn() === 0,
        'RAPOR surec deleted after PERSONEL_BELGE cleared'
    );
    p3cAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE id = {$kazasiId}")->fetchColumn() === 1,
        'IS_KAZASI not deleted by RAPOR'
    );
    p3cAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE id = {$raporOtherId}")->fetchColumn() === 1,
        'RAPOR other personel preserved'
    );

    p3cEvalExecute($pdo, (int) $apKazBlocked['id']);
    p3cAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE id = {$kazasiId}")->fetchColumn() === 0,
        'IS_KAZASI surec deleted after PERSONEL_BELGE cleared'
    );
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $raporOtherId
        )->fetchColumn() === 1,
        'cross-personel belge still present (does not block target)'
    );
    unset($belgeRapor, $belgeKazasi, $belgeOther);

    // ---------- Generic ONAY_AUDIT ----------
    // NO_PHYSICAL_ROWS ≠ parent destroyed; evidence closes virtual ONAY_AUDIT obligation only.
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, 2010, 3, 1, '2010-03', 'MUHURLENDI', 0, 1, '2010-03-28 10:00:00')"
    );
    $sealId = (int) $pdo->lastInsertId();
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, 2010, 3, $sealId, 1);
    $apOnay = p3cApprove(
        $pdo,
        RetentionCategories::ONAY_AUDIT,
        'puantaj',
        $sealId,
        ['sube_id' => 1, 'yil' => 2010, 'ay' => 3, 'parent_category' => RetentionCategories::PUANTAJ]
    );
    $exOnay = p3cEvalExecute($pdo, (int) $apOnay['id']);
    p3cAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE id = ' . $sealId)->fetchColumn() === 1,
        'generic ONAY_AUDIT NO_PHYSICAL_ROWS preserves parent seal'
    );
    $execRow = $pdo->query(
        'SELECT result_summary_json FROM retention_imha_executionlari WHERE imha_talep_id = ' . (int) $apOnay['id']
    )->fetch(PDO::FETCH_ASSOC);
    p3cAssert(is_array($execRow), 'generic ONAY_AUDIT evidence row');
    p3cAssert(!p3cJsonHasPii($execRow), 'generic ONAY_AUDIT evidence PII-free');
    $summary = json_decode((string) ($execRow['result_summary_json'] ?? ''), true);
    p3cAssert(
        is_array($summary) && (int) ($summary['parent_overlay_no_physical_rows'] ?? 0) === 1,
        'generic ONAY_AUDIT NO_PHYSICAL_ROWS ≠ parent destroyed'
    );

    // Unknown ONAY_AUDIT entity fail-closed at plan
    $unknownHandler = RetentionDestructionHandlerRegistry::forCategory(RetentionCategories::ONAY_AUDIT);
    $unknownPlan = $unknownHandler->plan($pdo, [
        'entity_type' => 'mystery_audit',
        'record_id' => 1,
    ], ['entity_type' => 'mystery_audit', 'record_id' => 1]);
    p3cAssert(
        !empty($unknownPlan['policy_blocker']),
        'unknown ONAY_AUDIT policy_blocker'
    );

    // Legal hold blocks FAZLA on week B (SERBEST already destroyed above)
    $apFcHold = p3cApprove(
        $pdo,
        RetentionCategories::FAZLA_CALISMA,
        'haftalik_kapanis',
        (int) $weekB['kapanis_id'],
        ['sube_id' => 1, 'hafta_baslangic' => '2010-01-11', 'haftalik_kapanis_id' => (int) $weekB['kapanis_id']]
    );
    LegalHoldService::create($pdo, p3cGm(), [
        'target_domain' => 'category',
        'target_category' => RetentionCategories::FAZLA_CALISMA,
        'reason' => 'Pack3C hold',
    ]);
    try {
        p3cEvalExecute($pdo, (int) $apFcHold['id']);
        p3cAssert(false, 'legal hold should block');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        p3cAssert(
            $msg === RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE
                || $msg === 'not eligible: ' . RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
            'FAZLA legal hold block'
        );
    }
    p3cAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE kapanis_id = ' . (int) $weekB['kapanis_id']
        )->fetchColumn() === 1,
        'FAZLA week B tercih intact under hold'
    );

    // Feature flag OFF
    p3cFlagOff();
    p3cAssert(PhysicalDestructionService::isEnabled() === false, 'flag default-off path');

    echo "verify-retention-physical-pack3c-mysql: OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    RetentionClock::clearOverride();
    p3cFlagOff();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
