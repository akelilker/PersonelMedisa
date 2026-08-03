<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogContracts.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogTamlikService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogOnayService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogWriteService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaCatalog.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaImportValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaWriteService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSurecEslemeImportValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSurecEslemeWriteService.php';
require_once __DIR__ . '/../../api/src/Http/CsvResponse.php';

use Medisa\Api\Services\Payroll\SgkKatalogOnayService;
use Medisa\Api\Services\Payroll\SgkKatalogWriteService;
use Medisa\Api\Services\Payroll\SgkSirketPolitikaImportValidator;
use Medisa\Api\Services\Payroll\SgkSirketPolitikaWriteService;
use Medisa\Api\Services\Payroll\SgkSurecEslemeImportValidator;
use Medisa\Api\Services\Payroll\SgkSurecEslemeWriteService;

function s98MigrationPdo(): PDO
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
    ]);
}

/** @return array<int, string> */
function splitS98Migration(string $sql): array
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

function applyS98Migration(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (splitS98Migration($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function s98Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$root = s98MigrationPdo();
$database = 'medisa_s98_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, kod VARCHAR(32) NOT NULL, ad VARCHAR(120) NOT NULL, durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF') ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE personeller (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE surecler (id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users VALUES (1), (2)');
    $pdo->exec("INSERT INTO subeler VALUES (1, 'MRK', 'Merkez', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller VALUES (7, 'Test Personel')");

    applyS98Migration($pdo, '036_sgk_prim_gunu_owner.sql');
    applyS98Migration($pdo, '037_sgk_resmi_kaynak_manifesti_v1.sql');
    applyS98Migration($pdo, '040_sgk_mevzuat_canonical_schema.sql');
    applyS98Migration($pdo, '042_sgk_resmi_kaynakli_kisitli_katalog.sql');

    $manifestId = (int) $pdo->query("SELECT id FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EK9_APHB_20260722'")->fetchColumn();
    s98Assert($manifestId > 0, 'manifest seed mevcut');

    $parentHash = hash('sha256', 's98-parent-catalog');
    $pdo->exec("INSERT INTO sgk_eksik_gun_katalog_surumleri
        (surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, tamlik_durumu, state, manifest_set_hash, aciklama,
         hazirlayan_id, katalog_payload_hash, onaylayan_id, onay_zamani,
         resmi_kaynaklar_incelendi_mi, belirsiz_tarihler_uydurulmadi_mi, kisitli_kullanim_kabul_edildi_mi)
        VALUES ('S98-PARENT', '2020-01-01', NULL, 'RESMI_KAYNAKLI_KISITLI', 'ONAYLANDI', '" . str_repeat('a', 64) . "',
                'parent', 1, '$parentHash', 2, '2026-01-01 00:00:00', 1, 1, 1)");
    $parentId = (int) $pdo->query("SELECT id FROM sgk_eksik_gun_katalog_surumleri WHERE surum_kodu = 'S98-PARENT'")->fetchColumn();

    foreach (['01', '15', '20'] as $kod) {
        $pdo->exec("INSERT INTO sgk_eksik_gun_kodlari
            (katalog_surum_id, eksik_gun_kodu, resmi_aciklama, gecerlilik_baslangic, gecerlilik_bitis, gecerlilik_tarih_durumu,
             kaynak_manifest_id, belge_zorunlulugu, sifir_gun_sifir_kazanc_kullanilabilir_mi, kismi_sureli_sozlesme_gerekli_mi,
             tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim, aktif_mi)
            VALUES ($parentId, '$kod', 'Test $kod', '2020-01-01', NULL, 'BELIRLENEMEDI',
             $manifestId, 'KOSULLU', 0, 0, 1, 'KOSULLU', 1)");
    }
    s98Assert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = ' . $parentId)->fetchColumn() === 3, 'parent 3 kod');

    $inventory = SgkSurecEslemeImportValidator::rawSurecInventory();
    s98Assert(count($inventory) >= 13, 'raw surec inventory');

    $template = SgkSurecEslemeImportValidator::buildTemplateExport();
    s98Assert($template['sha256'] === hash('sha256', $template['body']), 'esleme sablon SHA256');

    $dryEmpty = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'IZIN',
            'alt_tur' => 'YILLIK_IZIN',
            'canonical_surec_turu' => '',
            'eksik_gun_kodu' => '',
            'prim_gunu_etkisi' => '',
            'kaynak_referansi' => '',
        ]],
    ]);
    s98Assert(empty($dryEmpty['apply_yapilabilir_mi']), 'dry-run incomplete decision not applyable');
    s98Assert((int) ($dryEmpty['decision_pending_count'] ?? 0) === 1, 'decision pending count');

    $beforeParentMappings = (int) $pdo->query('SELECT COUNT(*) FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = ' . $parentId)->fetchColumn();
    s98Assert($beforeParentMappings === 0, 'parent mapping count 0 before import');

    $mappingRows = [[
        'surec_turu' => 'IZIN',
        'alt_tur' => 'YILLIK_IZIN',
        'canonical_surec_turu' => 'YILLIK_IZIN',
        'eksik_gun_kodu' => '01',
        'prim_gunu_etkisi' => 'DUSUR',
        'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
    ], [
        'surec_turu' => 'RAPOR',
        'alt_tur' => 'Raporlu_Hastalik',
        'canonical_surec_turu' => 'HASTALIK',
        'eksik_gun_kodu' => '15',
        'prim_gunu_etkisi' => 'KOSULLU',
        'cozulmus_prim_gunu_etkisi' => 'DUSUR',
        'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
    ]];

    $dry = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'successor_surum_kodu' => 'S98-SUCCESSOR',
        'rows' => $mappingRows,
    ]);
    s98Assert(!empty($dry['apply_yapilabilir_mi']), 'mapping dry-run applyable');
    $eslemeHash = (string) $dry['esleme_payload_hash'];

    $gy1 = ['id' => 1, 'rol' => 'GENEL_YONETICI'];
    $gy2 = ['id' => 2, 'rol' => 'GENEL_YONETICI'];

    $import = SgkSurecEslemeWriteService::import($pdo, $gy1, [
        'parent_surum_kodu' => 'S98-PARENT',
        'successor_surum_kodu' => 'S98-SUCCESSOR',
        'rows' => $mappingRows,
        'esleme_payload_hash' => $eslemeHash,
        'confirmation_text' => SgkSurecEslemeWriteService::CONFIRMATION_TEXT,
    ]);
    s98Assert(($import['http_status'] ?? 0) === 200, 'mapping import 200');
    $successorId = (int) ($import['surum_id'] ?? 0);
    s98Assert($successorId > 0, 'successor id');

    $afterParentMappings = (int) $pdo->query('SELECT COUNT(*) FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = ' . $parentId)->fetchColumn();
    s98Assert($afterParentMappings === 0, 'parent mapping immutability');
    $parentState = (string) $pdo->query("SELECT state FROM sgk_eksik_gun_katalog_surumleri WHERE id = $parentId")->fetchColumn();
    s98Assert($parentState === 'ONAYLANDI', 'parent state unchanged');
    s98Assert((int) $pdo->query('SELECT COUNT(*) FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = ' . $successorId)->fetchColumn() === 2, 'successor 2 mapping');

    $submit = SgkKatalogWriteService::submit($pdo, $gy1, ['surum_kodu' => 'S98-SUCCESSOR']);
    s98Assert(($submit['http_status'] ?? 0) === 200, 'successor submit');

    $selfApprove = SgkKatalogWriteService::approve($pdo, $gy1, [
        'surum_kodu' => 'S98-SUCCESSOR',
        'resmi_kaynaklar_incelendi_mi' => true,
        'belirsiz_tarihler_uydurulmadi_mi' => true,
        'kisitli_kullanim_kabul_edildi_mi' => true,
    ]);
    s98Assert(($selfApprove['http_status'] ?? 0) === 403, 'catalog self-approve denied');

    $transition = SgkKatalogOnayService::validateTransition([
        'current_state' => 'ONAY_BEKLIYOR',
        'action' => 'APPROVE',
        'actor_id' => 1,
        'hazirlayan_id' => 1,
        'tamlik' => ['tamlik_durumu' => 'RESMI_KAYNAKLI_KISITLI', 'onaylanabilir_mi' => true],
        'resmi_kaynaklar_incelendi_mi' => true,
        'belirsiz_tarihler_uydurulmadi_mi' => true,
        'kisitli_kullanim_kabul_edildi_mi' => true,
    ]);
    s98Assert(in_array('SELF_APPROVAL', $transition['blocker_kodlari'] ?? [], true), 'onay service SELF_APPROVAL blocker');

    $approve = SgkKatalogWriteService::approve($pdo, $gy2, [
        'surum_kodu' => 'S98-SUCCESSOR',
        'resmi_kaynaklar_incelendi_mi' => true,
        'belirsiz_tarihler_uydurulmadi_mi' => true,
        'kisitli_kullanim_kabul_edildi_mi' => true,
    ]);
    s98Assert(($approve['http_status'] ?? 0) === 200, 'successor approved by other user');

    $unknownPolicy = SgkSirketPolitikaImportValidator::dryRun($pdo, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-BAD',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_1_SON_GUN',
        'degerler' => ['UNKNOWN_CODE' => 'X', 'SGK_ODENEK_MAHSUP_MODU' => ''],
    ]);
    s98Assert(empty($unknownPolicy['import_yapilabilir_mi']), 'unknown policy code rejected');

    $policyDry = SgkSirketPolitikaImportValidator::dryRun($pdo, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-2026',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'IK_KARARI_BEKLENIYOR'],
    ]);
    s98Assert(!empty($policyDry['import_yapilabilir_mi']), 'policy dry-run ok');
    $politikaHash = (string) $policyDry['politika_hash'];

    $polImport = SgkSirketPolitikaWriteService::import($pdo, $gy1, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-2026',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'IK_KARARI_BEKLENIYOR'],
        'politika_hash' => $politikaHash,
        'confirmation_text' => SgkSirketPolitikaWriteService::CONFIRMATION_TEXT,
    ]);
    s98Assert(($polImport['http_status'] ?? 0) === 200, 'policy import');

    $polSubmit = SgkSirketPolitikaWriteService::submit($pdo, $gy1, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polSubmit['http_status'] ?? 0) === 200, 'policy submit');

    $polSelf = SgkSirketPolitikaWriteService::approve($pdo, $gy1, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polSelf['http_status'] ?? 0) === 403, 'policy self-approve denied');

    $polApprove = SgkSirketPolitikaWriteService::approve($pdo, $gy2, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polApprove['http_status'] ?? 0) === 200, 'policy approved by other user');

    $overlapDry = SgkSirketPolitikaImportValidator::dryRun($pdo, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-OVERLAP',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_1_SON_GUN',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'BASKA'],
    ]);
    s98Assert(!empty($overlapDry['overlap_var_mi']), 'overlap detected vs ONAYLANDI policy');

    echo 'verify-s98-mapping-policy: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
