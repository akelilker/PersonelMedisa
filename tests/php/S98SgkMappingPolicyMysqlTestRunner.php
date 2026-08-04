<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Auth/RolePermissions.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKararPaketiAuthz.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogContracts.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkEslemeKararContract.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogTamlikService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogOnayService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKatalogWriteService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaCatalog.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaImportValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSirketPolitikaWriteService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSurecEslemeImportValidator.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkSurecEslemeWriteService.php';
require_once __DIR__ . '/../../api/src/Http/CsvResponse.php';

use Medisa\Api\Services\Payroll\SgkEslemeKararContract;
use Medisa\Api\Services\Payroll\SgkKararPaketiAuthz;
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
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
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
        $trimmed = ltrim($statement);
        // Native PREPARE/EXECUTE can leave an active result set (esp. SELECT 1 no-op path).
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
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    $pdo->exec("CREATE TABLE users (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        rol ENUM(
            'GENEL_YONETICI',
            'MUHASEBE',
            'BIRIM_AMIRI',
            'BOLUM_YONETICISI',
            'PATRON',
            'AUTH_SMOKE_READONLY'
        ) NOT NULL,
        durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF'
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, kod VARCHAR(32) NOT NULL, ad VARCHAR(120) NOT NULL, durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF') ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE personeller (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE surecler (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        ucretli_mi TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhur_satirlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        muhur_id INT UNSIGNED NOT NULL,
        personel_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        hesap_etkisi VARCHAR(40) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    // Stub for migration 047 (sgk_eksik_gun_neden_tipi AFTER hesap_etkisi).
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        hesap_etkisi VARCHAR(40) NULL,
        UNIQUE KEY uq_gunluk_puantaj_personel_tarih (personel_id, tarih)
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO users (id, username, rol, durum) VALUES
        (1, 'hazirlayan.s98', 'GENEL_YONETICI', 'AKTIF'),
        (2, 'onaylayan.s98', 'GENEL_YONETICI', 'AKTIF'),
        (8, 'unlinked.s98', 'GENEL_YONETICI', 'AKTIF')");
    $pdo->exec("INSERT INTO subeler VALUES (1, 'MRK', 'Merkez', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller VALUES (1, 'Fixture A'), (2, 'Fixture B'), (7, 'Test Personel')");

    applyS98Migration($pdo, '048_sgk_dual_control_actor_roles.sql');
    applyS98Migration($pdo, '048_sgk_dual_control_actor_roles.sql');
    $pdo->exec("INSERT INTO actor_identities
        (id, identity_code, display_name, normalized_name, status, verification_source, personel_id)
        VALUES
        (1, 'TEST_PREPARER_PERSON', 'Test Preparer Person', 'test preparer person', 'VERIFIED', 'HUMAN_CONFIRMED', NULL),
        (2, 'TEST_APPROVER_PERSON', 'Test Approver Person', 'test approver person', 'VERIFIED', 'HUMAN_CONFIRMED', NULL),
        (3, 'TEST_PENDING_PERSON', 'Test Pending Person', 'test pending person', 'PENDING', 'HUMAN_CONFIRMED', NULL),
        (4, 'TEST_REVOKED_PERSON', 'Test Revoked Person', 'test revoked person', 'REVOKED', 'HUMAN_CONFIRMED', NULL)");
    $pdo->exec('UPDATE users SET actor_identity_id = 1 WHERE id = 1');
    $pdo->exec('UPDATE users SET actor_identity_id = 2 WHERE id = 2');
    $col048 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'actor_identity_id'"
    )->fetchColumn();
    $tbl048 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actor_identities'"
    )->fetchColumn();
    s98Assert($col048 === 1 && $tbl048 === 1, 'migration 048 actor_identity applied + idempotent');
    $enum048 = (string) $pdo->query("SHOW COLUMNS FROM users LIKE 'rol'")->fetch(PDO::FETCH_ASSOC)['Type'];
    s98Assert(strpos($enum048, 'IK_BORDRO') !== false && strpos($enum048, 'SGK_KARAR_ONAY_YETKILISI') !== false, 'migration 048 ENUM superset roles');
    $user8 = $pdo->query('SELECT id, actor_identity_id FROM users WHERE id = 8')->fetch(PDO::FETCH_ASSOC);
    s98Assert(is_array($user8) && (int) $user8['id'] === 8 && ($user8['actor_identity_id'] === null || $user8['actor_identity_id'] === ''), 'existing users preserved after 048 without auto-backfill');

    // --- S98 identity fail-closed authz matrix (service-level) ---
    $prepOk = [
        'id' => 1,
        'rol' => 'IK_BORDRO',
        'username' => 'hazirlayan.s98',
        'durum' => 'AKTIF',
        'actor_identity_id' => 1,
        'actor_identity_status' => 'VERIFIED',
        'sube_ids' => [1],
    ];
    $apprOk = [
        'id' => 2,
        'rol' => 'SGK_KARAR_ONAY_YETKILISI',
        'username' => 'onaylayan.s98',
        'durum' => 'AKTIF',
        'actor_identity_id' => 2,
        'actor_identity_status' => 'VERIFIED',
        'sube_ids' => [1],
    ];
    SgkKararPaketiAuthz::assertPrepare($pdo, $prepOk);
    SgkKararPaketiAuthz::assertApprove($pdo, $apprOk);
    SgkKararPaketiAuthz::assertSubeScope($apprOk, 1);
    s98Assert(true, 'linked scoped prepare/approve PASS');

    // Optional personel bridge NULL must not block formal actor
    s98Assert(
        (int) $pdo->query('SELECT COUNT(*) FROM actor_identities WHERE id IN (1,2) AND personel_id IS NULL')->fetchColumn() === 2,
        'verified actor identities work with NULL personel bridge'
    );

    $samePerson = SgkKararPaketiAuthz::denySamePerson($pdo, $apprOk, 1);
    s98Assert(!empty($samePerson['ok']), 'distinct persons dual-control PASS');

    $samePersonDeny = SgkKararPaketiAuthz::denySamePerson($pdo, array_merge($apprOk, ['actor_identity_id' => 1]), 1);
    s98Assert(($samePersonDeny['code'] ?? '') === 'SGK_SAME_ACTOR_IDENTITY_FORBIDDEN', 'same actor identity dual-control denied');

    $self = SgkKararPaketiAuthz::denySelfApproval($prepOk, 1);
    s98Assert(($self['code'] ?? '') === 'SGK_SELF_APPROVAL_FORBIDDEN', 'same user self-approve denied');

    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, ['actor_identity_id' => null]));
        s98Assert(false, 'missing actor identity link should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_LINK_REQUIRED', 'missing actor identity link code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, [
            'actor_identity_id' => 3,
            'actor_identity_status' => 'PENDING',
        ]));
        s98Assert(false, 'pending identity should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_NOT_VERIFIED', 'pending identity code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, [
            'actor_identity_id' => 4,
            'actor_identity_status' => 'REVOKED',
        ]));
        s98Assert(false, 'revoked identity should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_NOT_VERIFIED', 'revoked identity code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, ['username' => 'genel_yonetici']));
        s98Assert(false, 'generic account prepare should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_NOT_READY', 'generic account prepare code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, ['username' => 'smoke.actor']));
        s98Assert(false, 'smoke account prepare should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_NOT_READY', 'smoke account prepare code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, ['durum' => 'PASIF']));
        s98Assert(false, 'inactive account prepare should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_INACTIVE', 'inactive account prepare code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($prepOk, ['id' => 0]));
        s98Assert(false, 'invalid actor id should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_INVALID', 'invalid actor id code');
    }
    try {
        SgkKararPaketiAuthz::assertApprove($pdo, array_merge($prepOk, ['rol' => 'IK_BORDRO']));
        s98Assert(false, 'prepare-only approve should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_APPROVE_FORBIDDEN', 'prepare-only approve code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, array_merge($apprOk, ['rol' => 'SGK_KARAR_ONAY_YETKILISI']));
        s98Assert(false, 'approve-only prepare should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_PREPARE_FORBIDDEN', 'approve-only prepare code');
    }
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, [
            'id' => 9,
            'rol' => 'MUHASEBE',
            'username' => 'muhasebe.ali',
            'durum' => 'AKTIF',
            'actor_identity_id' => 1,
            'actor_identity_status' => 'VERIFIED',
            'sube_ids' => [1],
        ]);
        s98Assert(false, 'MUHASEBE prepare should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_PREPARE_FORBIDDEN', 'MUHASEBE prepare code');
    }
    try {
        SgkKararPaketiAuthz::assertSubeScope(array_merge($prepOk, ['sube_ids' => []]), 1);
        s98Assert(false, 'empty scope should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_SCOPE_NOT_READY', 'empty scope code');
    }
    try {
        SgkKararPaketiAuthz::assertSubeScope(array_merge($prepOk, ['sube_ids' => [2]]), 1);
        s98Assert(false, 'wrong scope should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_SCOPE_FORBIDDEN', 'wrong scope code');
    }

    $prepNoLink = SgkKararPaketiAuthz::denySamePerson($pdo, $apprOk, 99);
    s98Assert(($prepNoLink['code'] ?? '') === 'SGK_PREPARER_ACTOR_IDENTITY_REQUIRED', 'missing preparer actor identity link');

    try {
        SgkKararPaketiAuthz::assertApprove($pdo, [
            'id' => 9,
            'rol' => 'MUHASEBE',
            'username' => 'muhasebe.ali',
            'durum' => 'AKTIF',
            'actor_identity_id' => 1,
            'actor_identity_status' => 'VERIFIED',
            'sube_ids' => [1],
        ]);
        s98Assert(false, 'MUHASEBE approve should deny');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_APPROVE_FORBIDDEN', 'MUHASEBE approve code');
    }

    // Schema-missing fail-closed (drop actor_identity link + table, assert, re-apply 048)
    $pdo->exec('ALTER TABLE users DROP FOREIGN KEY fk_users_actor_identity');
    $pdo->exec('ALTER TABLE users DROP INDEX uq_users_actor_identity_id');
    $pdo->exec('ALTER TABLE users DROP COLUMN actor_identity_id');
    $pdo->exec('DROP TABLE actor_identities');
    s98Assert(SgkKararPaketiAuthz::actorIdentitySchemaSupported($pdo) === false, 'actorIdentitySchemaSupported false after drop');
    try {
        SgkKararPaketiAuthz::assertPrepare($pdo, $prepOk);
        s98Assert(false, 'missing actor identity schema should deny prepare');
    } catch (RuntimeException $e) {
        s98Assert($e->getMessage() === 'SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED', 'missing schema prepare code');
    }
    $schemaDeny = SgkKararPaketiAuthz::denySamePerson($pdo, $apprOk, 1);
    s98Assert(($schemaDeny['code'] ?? '') === 'SGK_ACTOR_IDENTITY_SCHEMA_REQUIRED', 'missing schema same-person deny');
    applyS98Migration($pdo, '048_sgk_dual_control_actor_roles.sql');
    $pdo->exec("INSERT INTO actor_identities
        (id, identity_code, display_name, normalized_name, status, verification_source, personel_id)
        VALUES
        (1, 'TEST_PREPARER_PERSON', 'Test Preparer Person', 'test preparer person', 'VERIFIED', 'HUMAN_CONFIRMED', NULL),
        (2, 'TEST_APPROVER_PERSON', 'Test Approver Person', 'test approver person', 'VERIFIED', 'HUMAN_CONFIRMED', NULL),
        (3, 'TEST_PENDING_PERSON', 'Test Pending Person', 'test pending person', 'PENDING', 'HUMAN_CONFIRMED', NULL),
        (4, 'TEST_REVOKED_PERSON', 'Test Revoked Person', 'test revoked person', 'REVOKED', 'HUMAN_CONFIRMED', NULL)");
    $pdo->exec('UPDATE users SET actor_identity_id = 1 WHERE id = 1');
    $pdo->exec('UPDATE users SET actor_identity_id = 2 WHERE id = 2');
    s98Assert(SgkKararPaketiAuthz::actorIdentitySchemaSupported($pdo) === true, 'actorIdentitySchemaSupported true after re-apply');

    // Unlinked user still preserved after drop/reapply (no auto-backfill)
    $unlinkedAid = $pdo->query('SELECT actor_identity_id FROM users WHERE id = 8')->fetchColumn();
    s98Assert($unlinkedAid === null || $unlinkedAid === false || $unlinkedAid === '', 'unlinked user preserved without actor identity');

    // Duplicate non-null actor_identity_id rejected
    try {
        $pdo->exec('UPDATE users SET actor_identity_id = 1 WHERE id = 2');
        s98Assert(false, 'duplicate actor_identity_id should fail');
    } catch (PDOException $e) {
        s98Assert(true, 'duplicate actor_identity_id unique rejected');
        $pdo->exec('UPDATE users SET actor_identity_id = 2 WHERE id = 2');
    }

    // Orphan FK rejected
    try {
        $pdo->exec('UPDATE users SET actor_identity_id = 99999 WHERE id = 2');
        s98Assert(false, 'orphan actor_identity_id FK should fail');
    } catch (PDOException $e) {
        s98Assert(true, 'orphan actor_identity_id FK rejected');
        $pdo->exec('UPDATE users SET actor_identity_id = 2 WHERE id = 2');
    }

    // Optional personel bridge unique + ON DELETE SET NULL
    $pdo->exec('UPDATE actor_identities SET personel_id = 1 WHERE id = 1');
    try {
        $pdo->exec('UPDATE actor_identities SET personel_id = 1 WHERE id = 2');
        s98Assert(false, 'duplicate personel bridge should fail');
    } catch (PDOException $e) {
        s98Assert(true, 'duplicate personel bridge unique rejected');
    }
    $pdo->exec('DELETE FROM personeller WHERE id = 1');
    $bridgeAfterDelete = $pdo->query('SELECT personel_id FROM actor_identities WHERE id = 1')->fetchColumn();
    s98Assert($bridgeAfterDelete === null || $bridgeAfterDelete === false || $bridgeAfterDelete === '', 'personel delete SET NULL on actor bridge');
    $pdo->exec("INSERT INTO personeller VALUES (1, 'Fixture A')");

    // Parent/child signedness: both INT UNSIGNED
    $aidType = (string) $pdo->query("SHOW COLUMNS FROM actor_identities LIKE 'id'")->fetch(PDO::FETCH_ASSOC)['Type'];
    $uaidType = (string) $pdo->query("SHOW COLUMNS FROM users LIKE 'actor_identity_id'")->fetch(PDO::FETCH_ASSOC)['Type'];
    s98Assert(stripos($aidType, 'int') !== false && stripos($aidType, 'unsigned') !== false, 'actor_identities.id INT UNSIGNED');
    s98Assert(stripos($uaidType, 'int') !== false && stripos($uaidType, 'unsigned') !== false, 'users.actor_identity_id INT UNSIGNED');

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

    foreach (['01', '06', '15', '20', '21'] as $kod) {
        $pdo->exec("INSERT INTO sgk_eksik_gun_kodlari
            (katalog_surum_id, eksik_gun_kodu, resmi_aciklama, gecerlilik_baslangic, gecerlilik_bitis, gecerlilik_tarih_durumu,
             kaynak_manifest_id, belge_zorunlulugu, sifir_gun_sifir_kazanc_kullanilabilir_mi, kismi_sureli_sozlesme_gerekli_mi,
             tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim, aktif_mi)
            VALUES ($parentId, '$kod', 'Test $kod', '2020-01-01', NULL, 'BELIRLENEMEDI',
             $manifestId, 'KOSULLU', 0, 0, 1, 'KOSULLU', 1)");
    }
    s98Assert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = ' . $parentId)->fetchColumn() === 5, 'parent 5 kod (01,06,15,20,21)');

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
            'karar_kurali' => '',
            'kod_secim_modu' => '',
            'eksik_gun_kodu' => '',
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
        'karar_kurali' => 'HER_ZAMAN_DAHIL',
        'kod_secim_modu' => 'KOD_YOK',
        'eksik_gun_kodu' => '',
        'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
    ], [
        'surec_turu' => 'RAPOR',
        'alt_tur' => 'Raporlu_Hastalik',
        'canonical_surec_turu' => 'HASTALIK',
        'karar_kurali' => 'UCRET_MODELINE_GORE',
        'kod_secim_modu' => 'KOD_YOK',
        'eksik_gun_kodu' => '',
        'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
    ]];

    $legacyCompat = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'IZIN',
            'alt_tur' => 'UCRETSIZ_IZIN',
            'canonical_surec_turu' => 'UCRETSIZ_IZIN',
            'eksik_gun_kodu' => '21',
            'prim_gunu_etkisi' => 'DUSUR',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ]],
    ]);
    s98Assert(!empty($legacyCompat['apply_yapilabilir_mi']), 'legacy prim_gunu_etkisi rows still applyable');

    $dry = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'successor_surum_kodu' => 'S98-SUCCESSOR',
        'rows' => $mappingRows,
    ]);
    s98Assert(!empty($dry['apply_yapilabilir_mi']), 'mapping dry-run applyable');
    $eslemeHash = (string) $dry['esleme_payload_hash'];

    $gy1 = [
        'id' => 1,
        'rol' => 'GENEL_YONETICI',
        'username' => 'hazirlayan.s98',
        'durum' => 'AKTIF',
        'actor_identity_id' => 1,
        'actor_identity_status' => 'VERIFIED',
        'sube_ids' => [1],
    ];
    $gy2 = [
        'id' => 2,
        'rol' => 'GENEL_YONETICI',
        'username' => 'onaylayan.s98',
        'durum' => 'AKTIF',
        'actor_identity_id' => 2,
        'actor_identity_status' => 'VERIFIED',
        'sube_ids' => [1],
    ];

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
    s98Assert(($selfApprove['code'] ?? '') === 'SGK_SELF_APPROVAL_FORBIDDEN', 'catalog self-approve code');

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
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'UCRET_MODELINE_GORE'],
    ]);
    s98Assert(!empty($policyDry['import_yapilabilir_mi']), 'policy dry-run UCRET_MODELINE_GORE PASS');
    $politikaHash = (string) $policyDry['politika_hash'];

    $polImport = SgkSirketPolitikaWriteService::import($pdo, $gy1, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-2026',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_15_SONRAKI_AY_14',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'UCRET_MODELINE_GORE'],
        'politika_hash' => $politikaHash,
        'confirmation_text' => SgkSirketPolitikaWriteService::CONFIRMATION_TEXT,
    ]);
    s98Assert(($polImport['http_status'] ?? 0) === 200, 'policy import');

    $polSubmit = SgkSirketPolitikaWriteService::submit($pdo, $gy1, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polSubmit['http_status'] ?? 0) === 200, 'policy submit');

    $polSelf = SgkSirketPolitikaWriteService::approve($pdo, $gy1, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polSelf['http_status'] ?? 0) === 403, 'policy self-approve denied');
    s98Assert(($polSelf['code'] ?? '') === 'SGK_SELF_APPROVAL_FORBIDDEN', 'policy self-approve code');

    $polApprove = SgkSirketPolitikaWriteService::approve($pdo, $gy2, ['sube_id' => 1, 'surum_kodu' => 'POL-2026', 'politika_hash' => $politikaHash]);
    s98Assert(($polApprove['http_status'] ?? 0) === 200, 'policy approved by other user');

    $overlapDry = SgkSirketPolitikaImportValidator::dryRun($pdo, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-OVERLAP',
        'gecerlilik_baslangic' => '2026-01-01',
        'bildirim_donem_tipi' => 'AY_1_SON_GUN',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'UCRET_MODELINE_GORE'],
    ]);
    s98Assert(!empty($overlapDry['overlap_var_mi']), 'overlap detected vs ONAYLANDI policy');

    // --- S98-R1 real decision contract assertions ---

    applyS98Migration($pdo, '047_sgk_real_decision_contract.sql');
    applyS98Migration($pdo, '047_sgk_real_decision_contract.sql');
    $col047 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND COLUMN_NAME = 'sgk_eksik_gun_neden_tipi'"
    )->fetchColumn();
    s98Assert($col047 === 1, 'migration 047 applied + idempotent (sgk_eksik_gun_neden_tipi)');
    $sealCol047 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND COLUMN_NAME = 'sgk_eksik_gun_neden_tipi'"
    )->fetchColumn();
    s98Assert($sealCol047 === 1, 'migration 047 seal sgk_eksik_gun_neden_tipi');
    $tamGun047 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'surecler' AND COLUMN_NAME = 'tam_gun_mu'"
    )->fetchColumn();
    s98Assert($tamGun047 === 1, 'migration 047 surecler.tam_gun_mu');
    $idempotency047 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri' AND COLUMN_NAME = 'idempotency_key'"
    )->fetchColumn();
    s98Assert($idempotency047 === 1, 'migration 047 override idempotency_key');
    $audit047 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'"
    )->fetchColumn();
    s98Assert($audit047 === 1, 'migration 047 audit table present');
    $enum047 = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_surec_neden_eslemeleri' AND COLUMN_NAME = 'canonical_surec_turu'"
    )->fetchColumn();
    s98Assert(strpos($enum047, 'KISMI_SURE_DEVAMSIZLIK') !== false, 'migration 047 enum includes KISMI_SURE_DEVAMSIZLIK');

    $dahilNull = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'IZIN',
            'alt_tur' => 'YILLIK_IZIN',
            'canonical_surec_turu' => 'YILLIK_IZIN',
            'karar_kurali' => 'HER_ZAMAN_DAHIL',
            'kod_secim_modu' => 'KOD_YOK',
            'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ]],
    ]);
    s98Assert(!empty($dahilNull['apply_yapilabilir_mi']), 'DAHIL + NULL code insert PASS');
    $dahilCanon = $dahilNull['canonical_rows'][0] ?? [];
    s98Assert(array_key_exists('eksik_gun_kodu', $dahilCanon) && $dahilCanon['eksik_gun_kodu'] === null, 'DAHIL canonical eksik_gun_kodu null');

    $dusurNull = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'IZIN',
            'alt_tur' => 'UCRETSIZ_IZIN',
            'canonical_surec_turu' => 'UCRETSIZ_IZIN',
            'karar_kurali' => 'HER_ZAMAN_DUSUR',
            'kod_secim_modu' => 'SABIT_KOD',
            'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ]],
    ]);
    s98Assert(empty($dusurNull['apply_yapilabilir_mi']), 'DUSUR + NULL code reject');
    $dusurErrs = $dusurNull['hatali_satirlar'][0]['errors'] ?? [];
    s98Assert(in_array('DUSUR_ICIN_KOD_ZORUNLU', $dusurErrs, true), 'DUSUR null code error DUSUR_ICIN_KOD_ZORUNLU');

    $dahilWithCode = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'IZIN',
            'alt_tur' => 'YILLIK_IZIN',
            'canonical_surec_turu' => 'YILLIK_IZIN',
            'karar_kurali' => 'HER_ZAMAN_DAHIL',
            'kod_secim_modu' => 'KOD_YOK',
            'eksik_gun_kodu' => '01',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ]],
    ]);
    s98Assert(empty($dahilWithCode['apply_yapilabilir_mi']), 'DAHIL + code reject');
    $dahilCodeErrs = $dahilWithCode['hatali_satirlar'][0]['errors'] ?? [];
    s98Assert(in_array('DAHIL_ILE_KOD_CELISKISI', $dahilCodeErrs, true), 'DAHIL+code error DAHIL_ILE_KOD_CELISKISI');

    $fixtureRows = [
        [
            'surec_turu' => 'IZIN', 'alt_tur' => 'YILLIK_IZIN', 'canonical_surec_turu' => 'YILLIK_IZIN',
            'karar_kurali' => 'HER_ZAMAN_DAHIL', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'IZIN', 'alt_tur' => 'MAZERET_IZNI', 'canonical_surec_turu' => 'MAZERET_IZNI',
            'karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'IZIN', 'alt_tur' => 'UCRETSIZ_IZIN', 'canonical_surec_turu' => 'UCRETSIZ_IZIN',
            'karar_kurali' => 'HER_ZAMAN_DUSUR', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '21',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Hastalik', 'canonical_surec_turu' => 'HASTALIK',
            'karar_kurali' => 'UCRET_MODELINE_GORE', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Meslek_Hastaligi', 'canonical_surec_turu' => 'MESLEK_HASTALIGI',
            'karar_kurali' => 'HER_ZAMAN_DUSUR', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '01',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'RAPOR', 'alt_tur' => 'Raporlu_Analik', 'canonical_surec_turu' => 'ANALIK',
            'karar_kurali' => 'HER_ZAMAN_DUSUR', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '01',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'IS_KAZASI', 'alt_tur' => 'IS_KAZASI_BILDIRIMI', 'canonical_surec_turu' => 'IS_KAZASI',
            'karar_kurali' => 'HER_ZAMAN_DUSUR', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '01',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'IZINSIZ_GELMEDI', 'canonical_surec_turu' => 'MAZERETSIZ_DEVAMSIZLIK',
            'karar_kurali' => 'HER_ZAMAN_DUSUR', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '15',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETLI_GEC_GELDI', 'canonical_surec_turu' => 'KISMI_SURE_DEVAMSIZLIK',
            'karar_kurali' => 'HER_ZAMAN_DAHIL', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETSIZ_GEC_GELDI', 'canonical_surec_turu' => 'KISMI_SURE_DEVAMSIZLIK',
            'karar_kurali' => 'HER_ZAMAN_DAHIL', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETLI_ERKEN_CIKTI', 'canonical_surec_turu' => 'KISMI_SURE_DEVAMSIZLIK',
            'karar_kurali' => 'HER_ZAMAN_DAHIL', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'DEVAMSIZLIK', 'alt_tur' => 'MAZERETSIZ_ERKEN_CIKTI', 'canonical_surec_turu' => 'KISMI_SURE_DEVAMSIZLIK',
            'karar_kurali' => 'HER_ZAMAN_DAHIL', 'kod_secim_modu' => 'KOD_YOK', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'PUANTAJ_EKSIK_GUN', 'alt_tur' => '*', 'canonical_surec_turu' => 'PUANTAJ_EKSIK_GUN',
            'karar_kurali' => 'OLAY_NEDENINE_GORE', 'kod_secim_modu' => 'OLAYDAN_TURET', 'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
        [
            'surec_turu' => 'KISMI', 'alt_tur' => '*', 'canonical_surec_turu' => 'KISMI_SURELI_CALISMA',
            'karar_kurali' => 'YAZILI_KISMI_SOZLESME_ZORUNLU', 'kod_secim_modu' => 'SABIT_KOD', 'eksik_gun_kodu' => '06',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ],
    ];
    $fixtureDry = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'successor_surum_kodu' => 'S98-FIXTURE',
        'rows' => $fixtureRows,
    ]);
    s98Assert(!empty($fixtureDry['apply_yapilabilir_mi']), 'fixture-like rows dry-run applyable');
    s98Assert(count($fixtureDry['canonical_rows'] ?? []) === 14, 'fixture canonical row count === 14');
    $expectedFixture = [
        'IZIN|YILLIK_IZIN' => ['YILLIK_IZIN', 'HER_ZAMAN_DAHIL', 'KOD_YOK', null],
        'IZIN|MAZERET_IZNI' => ['MAZERET_IZNI', 'UCRET_KESINTISI_SECIMINE_GORE', 'KOD_YOK', null],
        'IZIN|UCRETSIZ_IZIN' => ['UCRETSIZ_IZIN', 'HER_ZAMAN_DUSUR', 'SABIT_KOD', '21'],
        'RAPOR|Raporlu_Hastalik' => ['HASTALIK', 'UCRET_MODELINE_GORE', 'KOD_YOK', null],
        'RAPOR|Raporlu_Meslek_Hastaligi' => ['MESLEK_HASTALIGI', 'HER_ZAMAN_DUSUR', 'SABIT_KOD', '01'],
        'RAPOR|Raporlu_Analik' => ['ANALIK', 'HER_ZAMAN_DUSUR', 'SABIT_KOD', '01'],
        'IS_KAZASI|IS_KAZASI_BILDIRIMI' => ['IS_KAZASI', 'HER_ZAMAN_DUSUR', 'SABIT_KOD', '01'],
        'DEVAMSIZLIK|IZINSIZ_GELMEDI' => ['MAZERETSIZ_DEVAMSIZLIK', 'HER_ZAMAN_DUSUR', 'SABIT_KOD', '15'],
        'DEVAMSIZLIK|MAZERETLI_GEC_GELDI' => ['KISMI_SURE_DEVAMSIZLIK', 'HER_ZAMAN_DAHIL', 'KOD_YOK', null],
        'DEVAMSIZLIK|MAZERETSIZ_GEC_GELDI' => ['KISMI_SURE_DEVAMSIZLIK', 'HER_ZAMAN_DAHIL', 'KOD_YOK', null],
        'DEVAMSIZLIK|MAZERETLI_ERKEN_CIKTI' => ['KISMI_SURE_DEVAMSIZLIK', 'HER_ZAMAN_DAHIL', 'KOD_YOK', null],
        'DEVAMSIZLIK|MAZERETSIZ_ERKEN_CIKTI' => ['KISMI_SURE_DEVAMSIZLIK', 'HER_ZAMAN_DAHIL', 'KOD_YOK', null],
        'PUANTAJ_EKSIK_GUN|*' => ['PUANTAJ_EKSIK_GUN', 'OLAY_NEDENINE_GORE', 'OLAYDAN_TURET', null],
        'KISMI|*' => ['KISMI_SURELI_CALISMA', 'YAZILI_KISMI_SOZLESME_ZORUNLU', 'SABIT_KOD', '06'],
    ];
    foreach ($fixtureDry['canonical_rows'] ?? [] as $row) {
        $key = (string) ($row['surec_turu'] ?? '') . '|' . (string) ($row['alt_tur'] ?? '');
        s98Assert(isset($expectedFixture[$key]), 'fixture unexpected key ' . $key);
        [$expCanon, $expRule, $expMode, $expCode] = $expectedFixture[$key];
        $gotCode = $row['eksik_gun_kodu'] ?? null;
        if ($gotCode === '') {
            $gotCode = null;
        }
        s98Assert((string) ($row['canonical_surec_turu'] ?? '') === $expCanon, 'fixture canonical ' . $key);
        s98Assert((string) ($row['karar_kurali'] ?? '') === $expRule, 'fixture rule ' . $key);
        s98Assert((string) ($row['kod_secim_modu'] ?? '') === $expMode, 'fixture mode ' . $key);
        s98Assert($gotCode === $expCode, 'fixture code ' . $key);
    }
    s98Assert(count($expectedFixture) === 14, 'expected fixture map has exact 14 keys');

    $policyBadValue = SgkSirketPolitikaImportValidator::dryRun($pdo, [
        'sube_id' => 1,
        'surum_kodu' => 'POL-R1-BAD',
        'gecerlilik_baslangic' => '2027-06-01',
        'bildirim_donem_tipi' => 'AY_1_SON_GUN',
        'degerler' => ['SGK_ODENEK_MAHSUP_MODU' => 'IK_KARARI_BEKLENIYOR'],
    ]);
    s98Assert(empty($policyBadValue['import_yapilabilir_mi']), 'unknown policy value reject');
    $badValueErrs = array_merge(...array_map(
        static fn (array $row): array => $row['errors'] ?? [],
        $policyBadValue['hatali_satirlar'] ?? []
    ));
    s98Assert(in_array('GECERSIZ_POLITIKA_DEGERI:SGK_ODENEK_MAHSUP_MODU', $badValueErrs, true), 'unknown policy value GECERSIZ_POLITIKA_DEGERI');

    s98Assert(SgkEslemeKararContract::roundPartialPrimDays(8.0) === 2, 'roundPartialPrimDays(8)=2');
    s98Assert(SgkEslemeKararContract::roundPartialPrimDays(7.5) === 1, 'roundPartialPrimDays(7.5)=1');
    s98Assert(SgkEslemeKararContract::roundPartialPrimDays(0.0) === 0, 'roundPartialPrimDays(0)=0');
    s98Assert(SgkEslemeKararContract::roundPartialPrimDays(225.0) === 30, 'roundPartialPrimDays(225)=30 cap');

    $wageGunluk = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_MODELINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'HASTALIK',
            'eksik_gun_kodu' => null,
        ],
        ['ucret_modeli' => 'GUNLUK']
    );
    s98Assert($wageGunluk['effect'] === 'DUSUR' && $wageGunluk['code'] === '01', 'resolveRuntime GUNLUK→DUSUR/01');

    $wageMaktu = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_MODELINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'HASTALIK',
            'eksik_gun_kodu' => null,
        ],
        ['ucret_modeli' => 'MAKTU_AYLIK']
    );
    s98Assert($wageMaktu['effect'] === 'DAHIL' && $wageMaktu['code'] === null, 'resolveRuntime MAKTU→DAHIL/null');

    $wageBelirsiz = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_MODELINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'HASTALIK',
            'eksik_gun_kodu' => null,
        ],
        ['ucret_modeli' => 'BELIRSIZ']
    );
    s98Assert(
        $wageBelirsiz['effect'] === 'MANUEL'
        && in_array('UCRET_MODELI_BELIRSIZ', array_column($wageBelirsiz['blockers'], 'code'), true),
        'resolveRuntime BELIRSIZ→blocker'
    );

    $mazUcretli = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'MAZERET_IZNI',
            'ucretli_mi' => true,
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert($mazUcretli['effect'] === 'DAHIL' && $mazUcretli['code'] === null, 'resolveRuntime mazeret ucretli→DAHIL');

    $mazUcretsiz = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'MAZERET_IZNI',
            'ucretli_mi' => false,
            'tam_gun_mu' => true,
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert($mazUcretsiz['effect'] === 'DUSUR' && $mazUcretsiz['code'] === '21', 'resolveRuntime mazeret ucretsiz→DUSUR/21');

    $mazPartial = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'MAZERET_IZNI',
            'ucretli_mi' => false,
            'tam_gun_mu' => false,
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert($mazPartial['effect'] === 'DAHIL' && $mazPartial['code'] === null, 'resolveRuntime mazeret partial→DAHIL');

    $mazTamGunNull = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'MAZERET_IZNI',
            'ucretli_mi' => false,
            'tam_gun_mu' => null,
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert(
        $mazTamGunNull['effect'] === 'MANUEL'
        && in_array('MAZERET_TAM_GUN_KARARI_EKSIK', array_column($mazTamGunNull['blockers'], 'code'), true),
        'resolveRuntime mazeret tam_gun null→blocker'
    );

    $pdo->exec("DELETE FROM sgk_eksik_gun_kodlari WHERE katalog_surum_id = $parentId AND eksik_gun_kodu = '15'");
    $catalogMissing = SgkSurecEslemeImportValidator::dryRun($pdo, [
        'parent_surum_kodu' => 'S98-PARENT',
        'rows' => [[
            'surec_turu' => 'PUANTAJ_EKSIK_GUN',
            'alt_tur' => '*',
            'canonical_surec_turu' => 'PUANTAJ_EKSIK_GUN',
            'karar_kurali' => 'OLAY_NEDENINE_GORE',
            'kod_secim_modu' => 'OLAYDAN_TURET',
            'eksik_gun_kodu' => '',
            'kaynak_referansi' => 'SGK_EK9_APHB_20260722',
        ]],
    ]);
    s98Assert(empty($catalogMissing['apply_yapilabilir_mi']), 'required catalog codes missing blocks apply');
    $catErrs = $catalogMissing['hatali_satirlar'][0]['errors'] ?? [];
    s98Assert(in_array('PARENT_KATALOG_GEREKEN_KOD_YOK', $catErrs, true), 'PARENT_KATALOG_GEREKEN_KOD_YOK');

    $olayManuelReject = SgkEslemeKararContract::normalize([
        'karar_kurali' => 'OLAY_NEDENINE_GORE',
        'kod_secim_modu' => 'YETKILI_MANUEL',
        'eksik_gun_kodu' => '01',
    ]);
    s98Assert(in_array('OLAY_NEDENI_YETKILI_MANUEL_YASAK', $olayManuelReject['errors'], true), 'OLAY rejects YETKILI_MANUEL mapping mode');

    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, hesap_etkisi, sgk_eksik_gun_neden_tipi)
        VALUES (7, '2026-03-01', 'Yevmiye_Kes', 'ISTIRAHAT')
        ON DUPLICATE KEY UPDATE sgk_eksik_gun_neden_tipi = 'ISTIRAHAT'");
    $sealedReason = (string) $pdo->query(
        "SELECT sgk_eksik_gun_neden_tipi FROM gunluk_puantaj WHERE personel_id = 7 AND tarih = '2026-03-01'"
    )->fetchColumn();
    s98Assert($sealedReason === 'ISTIRAHAT', 'gunluk_puantaj sgk_eksik_gun_neden_tipi persistence smoke');

    $mazMissing = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => ['karar_kurali' => 'UCRET_KESINTISI_SECIMINE_GORE', 'kod_secim_modu' => 'KOD_YOK'],
            'canonical_surec_turu' => 'MAZERET_IZNI',
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert(
        $mazMissing['effect'] === 'MANUEL'
        && in_array('MAZERET_UCRET_KARARI_EKSIK', array_column($mazMissing['blockers'], 'code'), true),
        'resolveRuntime mazeret missing→blocker'
    );

    $olayIstirahat = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => [
                'karar_kurali' => 'OLAY_NEDENINE_GORE',
                'kod_secim_modu' => 'OLAYDAN_TURET',
                'olay_neden_kod_haritasi' => [
                    'ISTIRAHAT' => '01',
                    'KISMI_ISTIHDAM' => '06',
                    'TAM_GUN_DEVAMSIZLIK' => '15',
                    'GENEL_UCRETSIZ_IZIN' => '21',
                ],
            ],
            'canonical_surec_turu' => 'PUANTAJ_EKSIK_GUN',
            'sgk_eksik_gun_neden_tipi' => 'ISTIRAHAT',
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert($olayIstirahat['effect'] === 'DUSUR' && $olayIstirahat['code'] === '01', 'resolveRuntime OLAY ISTIRAHAT→01');

    $olayBilinmiyor = SgkEslemeKararContract::resolveRuntime(
        [
            'kosullar_json' => [
                'karar_kurali' => 'OLAY_NEDENINE_GORE',
                'kod_secim_modu' => 'OLAYDAN_TURET',
            ],
            'canonical_surec_turu' => 'PUANTAJ_EKSIK_GUN',
            'sgk_eksik_gun_neden_tipi' => 'BILINMIYOR',
            'eksik_gun_kodu' => null,
        ],
        []
    );
    s98Assert(
        $olayBilinmiyor['effect'] === 'MANUEL'
        && in_array('SGK_OLAY_NEDENI_BELIRSIZ', array_column($olayBilinmiyor['blockers'], 'code'), true),
        'resolveRuntime OLAY BILINMIYOR→blocker'
    );

    $parentAfter = (int) $pdo->query('SELECT COUNT(*) FROM sgk_surec_neden_eslemeleri WHERE katalog_surum_id = ' . $parentId)->fetchColumn();
    s98Assert($parentAfter === 0, 'parent mapping count remains 0 after successor import');

    $persistedKod = $pdo->query(
        "SELECT eksik_gun_kodu FROM sgk_surec_neden_eslemeleri
         WHERE katalog_surum_id = $successorId AND canonical_surec_turu = 'YILLIK_IZIN' LIMIT 1"
    )->fetchColumn();
    s98Assert($persistedKod === null, 'NULL eksik_gun_kodu persisted as SQL NULL not empty string');
    $persistedHastalik = $pdo->query(
        "SELECT eksik_gun_kodu FROM sgk_surec_neden_eslemeleri
         WHERE katalog_surum_id = $successorId AND canonical_surec_turu = 'HASTALIK' LIMIT 1"
    )->fetchColumn();
    s98Assert($persistedHastalik === null, 'KOSULLU UCRET_MODELINE_GORE also persists NULL code');

    echo 'verify-s98-mapping-policy: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
