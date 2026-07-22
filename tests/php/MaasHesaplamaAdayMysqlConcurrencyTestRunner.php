<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\MaasHesaplamaAdayService as AdaySvc;
use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\MaasHesaplamaSnapshotService as SnapshotSvc;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;
use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;

function mhacPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Isolated MySQL test credentials are required.');
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    return $pdo;
}

function mhacAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @return array<int, string> */
function mhacSplitMigrationStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
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
            $complete = $isGuarded ? (bool) preg_match('/^END\s+IF;$/i', $trimmed) : $endsWithSemicolon;
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

/** @return array{process: resource, pipes: array<int, resource>} */
function mhacSpawnChild(array $args): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not be started.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

function mhacFinishChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    if ($code !== 0) {
        throw new RuntimeException('Child failed: ' . trim($stderr . ' ' . $stdout));
    }
    $lines = preg_split('/\R/', trim((string) $stdout)) ?: [];
    $token = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && stripos($line, 'Warning:') !== 0) {
            $token = $line;
        }
    }

    return $token;
}

function mhacChildMode(array $argv): void
{
    $action = $argv[2] ?? '';
    if ($action !== 'create') {
        throw new RuntimeException('Unknown child action: ' . $action);
    }
    $pdo = mhacPdo();
    $snapshotId = (int) $argv[3];
    $expectedHash = (string) $argv[4];
    $actorId = (int) ($argv[5] ?? 99);
    try {
        $result = AdaySvc::createCalculation($pdo, $snapshotId, $expectedHash, MaasHesaplamaEngine::ENGINE_VERSION, [
            'id' => $actorId,
            'rol' => 'MUHASEBE',
        ]);
        echo ($result['idempotent'] ? 'EXISTING:' : 'CREATED:') . (int) $result['calistirma']['id'] . PHP_EOL;
    } catch (MaasHesaplamaException $e) {
        echo $e->getCodeString() . PHP_EOL;
    }
}

if (($argv[1] ?? '') === '--child') {
    mhacChildMode($argv);
    exit(0);
}

/** @return array<string, array<string, mixed>> */
function mhacMevzuatFixture(): array
{
    $values = [
        'ASGARI_UCRET_BRUT' => '26005.74',
        'ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI' => '20000.00',
        'ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI' => '26005.74',
        'SGK_ISCI_PRIM_ORANI' => '0.14',
        'ISSIZLIK_ISCI_PRIM_ORANI' => '0.01',
        'SGK_GUNLUK_TABAN' => '866.86',
        'SGK_GUNLUK_TAVAN' => '6501.45',
        'DAMGA_VERGISI_ORANI' => '0.00759',
        'GELIR_VERGISI_DILIM_1_LIMIT' => '110000.00',
        'GELIR_VERGISI_DILIM_1_ORAN' => '0.15',
        'GELIR_VERGISI_DILIM_2_LIMIT' => '230000.00',
        'GELIR_VERGISI_DILIM_2_ORAN' => '0.20',
        'GELIR_VERGISI_DILIM_3_LIMIT' => '580000.00',
        'GELIR_VERGISI_DILIM_3_ORAN' => '0.27',
        'GELIR_VERGISI_DILIM_4_LIMIT' => '3000000.00',
        'GELIR_VERGISI_DILIM_4_ORAN' => '0.35',
        'GELIR_VERGISI_DILIM_5_ORAN' => '0.40',
        'NORMAL_AY_GUN_SAYISI' => '30',
        'GUNLUK_CALISMA_SAATI' => '8',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        'HAFTALIK_IS_GUNU_SAYISI' => '5',
        'FAZLA_MESAI_CARPANI' => '1.5',
        'FAZLA_SURELERLE_CALISMA_CARPANI' => '1.25',
        'HAFTA_TATILI_CARPANI' => '1',
        'UBGT_CARPANI' => '1',
        'HAFTA_TATILI_HESAP_MODU' => 'GUNLUK_ILAVE',
        'UBGT_HESAP_MODU' => 'GUNLUK_ILAVE',
    ];
    $fixture = [];
    foreach ($values as $code => $value) {
        $meta = MaasHesaplamaLegalParameterCatalog::meta($code);
        $isMetin = $meta && $meta['deger_tipi'] === 'METIN';
        $fixture[$code] = [
            'parametre_kodu' => $code,
            'sayisal_deger' => $isMetin ? null : $value,
            'metin_deger' => $isMetin ? $value : null,
            'deger_tipi' => $meta ? $meta['deger_tipi'] : 'SAYISAL',
            'birim' => $meta ? $meta['birim'] : null,
        ];
    }

    return $fixture;
}

function mhacApplyMigration(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (mhacSplitMigrationStatements($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function mhacJson(array $value): string
{
    return json_encode(SnapshotSvc::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mhacSeedApprovedPolicy(PDO $pdo): void
{
    $values = [
        'NORMAL_AY_GUN_SAYISI' => '30',
        'GUNLUK_CALISMA_SAATI' => '8',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        'HAFTALIK_IS_GUNU_SAYISI' => '5',
        'HAFTA_TATILI_HESAP_MODU' => 'GUNLUK_ILAVE',
        'HAFTA_TATILI_CARPANI' => '1',
        'FAZLA_MESAI_CARPANI' => '1.5',
        'FAZLA_SURELERLE_CALISMA_CARPANI' => '1.25',
        'UBGT_CARPANI' => '1',
        'UBGT_HESAP_MODU' => 'GUNLUK_ILAVE',
        'TATIL_FSC_FM_CAKISMA_HESAP_MODU' => 'YARGITAY_7_5_SAAT_AYRIMI',
    ];
    $pdo->exec(
        "INSERT INTO sirket_calisma_politikalari (
            revision_no, state, gecerlilik_baslangic, gecerlilik_bitis, policy_version_hash,
            hazirlayan_id, onaylayan_id, onay_zamani, created_by
        ) VALUES (
            1, 'ONAYLANDI', '2020-01-01', NULL, '" . str_repeat('c', 64) . "',
            1, 1, '2026-01-01 00:00:00', 1
        )"
    );
    $policyId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'INSERT INTO sirket_calisma_politika_degerleri (
            politika_id, parametre_kodu, deger_tipi, sayisal_deger, metin_deger, birim
        ) VALUES (
            :politika_id, :kod, :tip, :sayisal, :metin, :birim
        )'
    );
    foreach (SirketCalismaPolitikasiCatalog::requiredCodes() as $code) {
        $meta = SirketCalismaPolitikasiCatalog::meta($code);
        $isMetin = $meta && $meta['deger_tipi'] === 'METIN';
        $stmt->execute([
            'politika_id' => $policyId,
            'kod' => $code,
            'tip' => $isMetin ? 'METIN' : 'SAYISAL',
            'sayisal' => $isMetin ? null : $values[$code],
            'metin' => $isMetin ? $values[$code] : null,
            'birim' => $meta['birim'] ?? null,
        ]);
    }
}

function mhacSeedSnapshot(PDO $pdo): int
{
    $pdo->exec("INSERT INTO subeler VALUES (1, 'MRK', 'Merkez')");
    $pdo->exec('INSERT INTO users VALUES (1), (11), (12), (99)');
    mhacSeedApprovedPolicy($pdo);
    $pdo->exec("INSERT INTO personeller (id, tc_kimlik_no, ad, soyad, sicil_no, ise_giris_tarihi, sube_id)
        VALUES (7, '11111111111', 'Test', 'Personel', 'S007', '2020-01-01', 1)");
    $pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
        VALUES (1, 2026, 1, '2026-01', 'MUHURLENDI', 1, 1)");
    $muhurId = (int) $pdo->lastInsertId();

    $personelPayload = [
        'personel_id' => 7,
        'ad_soyad' => 'Test Personel',
        'sube_id' => 1,
        'istihdam_baslangic' => '2020-01-01',
        'istihdam_bitis' => null,
    ];
    $personelHash = SnapshotSvc::hashCanonical($personelPayload);
    $sgkHash = str_repeat('c', 64);

    $ucretPayload = [
        'id' => 1,
        'personel_id' => 7,
        'ucret_tutari' => '30000.00',
        'ucret_turu' => 'NET',
        'para_birimi' => 'TRY',
        'etki_baslangic' => '2026-01-01',
        'etki_bitis' => '2026-01-30',
    ];
    $girdiEntries = [[
        'kaynak_turu' => 'UCRET',
        'kaynak_tablo' => 'personel_ucret_gecmisi',
        'kaynak_id' => 1,
        'sira_no' => 1,
        'payload' => $ucretPayload,
        'payload_hash' => SnapshotSvc::hashCanonical($ucretPayload),
    ]];
    $sira = 0;
    foreach (mhacMevzuatFixture() as $payload) {
        $sira++;
        $girdiEntries[] = [
            'kaynak_turu' => 'MEVZUAT',
            'kaynak_tablo' => 'mevzuat_parametreleri',
            'kaynak_id' => $sira,
            'sira_no' => $sira,
            'payload' => $payload,
            'payload_hash' => SnapshotSvc::hashCanonical($payload),
        ];
    }

    $preflightHash = str_repeat('a', 64);
    $sourceHash = str_repeat('b', 64);
    $snapshotHash = SnapshotSvc::hashCanonical([
        'contract_version' => SnapshotSvc::CONTRACT_VERSION,
        'sube_id' => 1,
        'yil' => 2026,
        'ay' => 1,
        'donem' => '2026-01',
        'muhur_id' => $muhurId,
        'revision_no' => 1,
        'source_hash' => $sourceHash,
        'preflight_hash' => $preflightHash,
        'personel_hashes' => [$personelHash],
        'girdi_hashes' => array_map(static function (array $entry) {
            return $entry['payload_hash'];
        }, $girdiEntries),
        'sgk_hashes' => [$sgkHash],
    ]);

    $stmt = $pdo->prepare("INSERT INTO maas_hesaplama_donem_snapshotlari (
        sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
        state, contract_version, cutoff_at, preflight_hash, source_hash, snapshot_hash,
        personel_sayisi, girdi_sayisi, created_by
    ) VALUES (
        1, 2026, 1, '2026-01', '2026-01-01', '2026-01-31', :muhur_id, 1,
        'OLUSTURULDU', :contract_version, '2026-02-01 10:00:00', :preflight_hash, :source_hash, :snapshot_hash,
        1, :girdi_sayisi, 1
    )");
    $stmt->execute([
        'muhur_id' => $muhurId,
        'contract_version' => SnapshotSvc::CONTRACT_VERSION,
        'preflight_hash' => $preflightHash,
        'source_hash' => $sourceHash,
        'snapshot_hash' => $snapshotHash,
        'girdi_sayisi' => count($girdiEntries),
    ]);
    $snapshotId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO maas_hesaplama_personel_snapshotlari (
        donem_snapshot_id, personel_id, personel_snapshot_json, personel_snapshot_hash,
        istihdam_baslangic, istihdam_bitis, ucret_segment_sayisi
    ) VALUES (:snapshot_id, 7, :payload, :hash, '2020-01-01', NULL, 1)");
    $stmt->execute([
        'snapshot_id' => $snapshotId,
        'payload' => mhacJson($personelPayload),
        'hash' => $personelHash,
    ]);
    $personelSnapshotId = (int) $pdo->lastInsertId();

    $insertGirdi = $pdo->prepare("INSERT INTO maas_hesaplama_girdi_snapshotlari (
        donem_snapshot_id, personel_snapshot_id, kaynak_turu, kaynak_tablo, kaynak_id,
        sira_no, payload_json, payload_hash
    ) VALUES (
        :snapshot_id, :personel_snapshot_id, :kaynak_turu, :kaynak_tablo, :kaynak_id,
        :sira_no, :payload_json, :payload_hash
    )");
    foreach ($girdiEntries as $entry) {
        $insertGirdi->execute([
            'snapshot_id' => $snapshotId,
            'personel_snapshot_id' => $entry['kaynak_turu'] === 'UCRET' ? $personelSnapshotId : null,
            'kaynak_turu' => $entry['kaynak_turu'],
            'kaynak_tablo' => $entry['kaynak_tablo'],
            'kaynak_id' => $entry['kaynak_id'],
            'sira_no' => $entry['sira_no'],
            'payload_json' => mhacJson($entry['payload']),
            'payload_hash' => $entry['payload_hash'],
        ]);
    }

    $manifestHash = str_repeat('d', 64);
    $sourceHashSgk = str_repeat('e', 64);
    $pdo->prepare("INSERT INTO maas_hesaplama_sgk_snapshotlari (
        donem_snapshot_id, personel_snapshot_id, personel_id,
        hesaplanan_prim_gunu, eksik_gun_sayisi,
        kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json,
        katalog_surumu, kaynak_manifest_hash, sgk_hesap_hash,
        gunluk_karar_dokumu_hash, gunluk_karar_dokumu_json,
        manuel_inceleme_gerekli_mi, blocker_kodlari_json, blocker_detaylari_json,
        ucret_modeli, ilk_iki_gun_politika_ozeti_json, sgk_odenek_durumu,
        is_goremezlik_finans_ozeti_json,
        gunluk_alt_sinir, gunluk_ust_sinir, donem_alt_sinir, donem_ust_sinir,
        sinir_mevzuat_surumu, source_hash
    ) VALUES (
        :snapshot_id, :personel_snapshot_id, 7, 30, 0,
        '[]', '[]', '[]', 'TEST-SGK-V1', :manifest_hash, :sgk_hash,
        :daily_hash, '[]', 0, '[]', '[]', 'MAKTU_AYLIK', '[]', 'UYGULANMAZ', '[]',
        866.86, 6501.45, 26005.80, 195043.50, 'TEST-PEK-V1', :source_hash
    )")->execute([
        'snapshot_id' => $snapshotId,
        'personel_snapshot_id' => $personelSnapshotId,
        'manifest_hash' => $manifestHash,
        'sgk_hash' => $sgkHash,
        'daily_hash' => $sgkHash,
        'source_hash' => $sourceHashSgk,
    ]);

    $row = SnapshotSvc::fetchSnapshotRow($pdo, $snapshotId);
    mhacAssert($row !== null && SnapshotSvc::verifySnapshotHash($pdo, $row)['dogrulandi'] === true, 'seed snapshot hash dogrulandi');

    return $snapshotId;
}

$admin = mhacPdo();
$database = 'medisa_s77d_aday_concurrency_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN')));
$pdo = mhacPdo();

try {
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, kod VARCHAR(32), ad VARCHAR(120)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL PRIMARY KEY, tc_kimlik_no CHAR(11), ad VARCHAR(80), soyad VARCHAR(80),
        sicil_no VARCHAR(32), ise_giris_tarihi DATE, sube_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE surecler (
        id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL, donem CHAR(7) NOT NULL,
        durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI', muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    foreach ([
        '014_puantaj_donem_kilitleri.sql',
        '020_maas_hesaplama_snapshotlari.sql',
        '021_maas_hesaplama_snapshot_guvenlik_indexleri.sql',
        '022_personel_bordro_devirleri.sql',
        '023_maas_hesaplama_adaylari.sql',
        '024_maas_hesaplama_aday_guvenlik_indexleri.sql',
        '033_sirket_calisma_politikalari.sql',
        '034_bordro_onay_ve_projection.sql',
        '036_sgk_prim_gunu_owner.sql',
    ] as $file) {
        mhacApplyMigration($pdo, $file);
    }

    $snapshotId = mhacSeedSnapshot($pdo);
    $preflight = AdaySvc::buildCalculationPreflight($pdo, $snapshotId);
    mhacAssert($preflight['hesaplanabilir_mi'] === true && (int) $preflight['blocker_count'] === 0, 'hesaplama preflight temiz');
    $expectedHash = (string) $preflight['calculation_input_hash'];

    $childA = mhacSpawnChild(['create', (string) $snapshotId, $expectedHash, '11']);
    $childB = mhacSpawnChild(['create', (string) $snapshotId, $expectedHash, '12']);
    $tokens = [mhacFinishChild($childA), mhacFinishChild($childB)];
    $createdCount = count(array_filter($tokens, static fn (string $t) => strpos($t, 'CREATED:') === 0));
    $existingCount = count(array_filter($tokens, static fn (string $t) => strpos($t, 'EXISTING:') === 0));
    mhacAssert($createdCount === 1 && $existingCount === 1, 'paralel hesaplama tek aktif calistirma + idempotent sonuc (' . implode(' / ', $tokens) . ')');
    $ids = array_map(static fn (string $t) => (int) substr($t, strpos($t, ':') + 1), $tokens);
    mhacAssert($ids[0] === $ids[1], 'paralel hesaplama ayni calistirma id dondurdu');
    mhacAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE snapshot_id = $snapshotId AND state = 'HESAPLANDI'")->fetchColumn() === 1, 'tam olarak bir aktif calistirma var');
    mhacAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_adaylari')->fetchColumn() === 1, 'duplicate aday satiri yok');

    $adayId = (int) $pdo->query('SELECT id FROM maas_hesaplama_adaylari LIMIT 1')->fetchColumn();
    $kalemId = (int) $pdo->query('SELECT id FROM maas_hesaplama_aday_kalemleri LIMIT 1')->fetchColumn();
    $adayUpdateBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_adaylari SET net_odenecek = 1 WHERE id = $adayId");
    } catch (PDOException $e) {
        $adayUpdateBlocked = strpos($e->getMessage(), 'PAYROLL_CALCULATION_IMMUTABLE') !== false;
    }
    mhacAssert($adayUpdateBlocked, 'aday UPDATE immutable trigger ile reddedildi');
    $kalemDeleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM maas_hesaplama_aday_kalemleri WHERE id = $kalemId");
    } catch (PDOException $e) {
        $kalemDeleteBlocked = strpos($e->getMessage(), 'PAYROLL_CALCULATION_IMMUTABLE') !== false;
    }
    mhacAssert($kalemDeleteBlocked, 'aday kalem DELETE immutable trigger ile reddedildi');

    echo 'verify-maas-hesaplama-aday-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
