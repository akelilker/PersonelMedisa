<?php

declare(strict_types=1);

/**
 * S87 tip 045 — politika kanit owner migration + service fail-closed gates.
 * Requires MEDISA_TEST_MYSQL_* env. Skips cleanly if unset.
 * php tests/php/SirketPolitikasiKanitOwner045MysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use Medisa\Api\Services\SirketCalismaPolitikasiException;
use Medisa\Api\Services\SirketCalismaPolitikasiService;

function s87p045Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s87p045RootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('SKIP: Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return list<string> */
function s87p045SplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s87p045Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s87p045SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** @return array<int, array<string, mixed>> */
function s87p045FullDegerler(): array
{
    $out = [];
    foreach (SirketCalismaPolitikasiCatalog::requiredCodes() as $code) {
        $meta = SirketCalismaPolitikasiCatalog::meta($code);
        if (($meta['deger_tipi'] ?? '') === 'METIN') {
            $val = $code === 'TATIL_FSC_FM_CAKISMA_HESAP_MODU'
                ? 'YARGITAY_7_5_SAAT_AYRIMI'
                : ($code === 'NORMAL_HASTALIK_ILK_IKI_GUN_ISVEREN_ODEMESI'
                    ? 'HAYIR'
                    : ($code === 'HAFTA_TATILI_GUNLERI' ? '0' : 'GUNLUK_ILAVE'));
            $out[] = ['parametre_kodu' => $code, 'metin_deger' => $val];
        } else {
            $defaults = [
                'NORMAL_AY_GUN_SAYISI' => '30',
                'GUNLUK_CALISMA_SAATI' => '7.5',
                'AYLIK_NORMAL_CALISMA_SAATI' => '225',
                'HAFTALIK_IS_GUNU_SAYISI' => '6',
                'HAFTA_TATILI_CARPANI' => '1.5',
                'FAZLA_MESAI_CARPANI' => '1.5',
                'FAZLA_SURELERLE_CALISMA_CARPANI' => '1.25',
                'UBGT_CARPANI' => '1.0',
                'HAFTALIK_NORMAL_CALISMA_DAKIKA' => '2700',
            ];
            $out[] = ['parametre_kodu' => $code, 'sayisal_deger' => $defaults[$code] ?? '1'];
        }
    }

    return $out;
}

function s87p045SyntheticSha(string $seed): string
{
    return hash('sha256', 'SYNTHETIC_POLICY_EVIDENCE|' . $seed);
}

try {
    $root = s87p045RootPdo();
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SKIP:') === 0) {
        echo $e->getMessage() . PHP_EOL;
        exit(0);
    }
    throw $e;
}

$dbName = 'medisa_s87_045_' . getmypid();
$root->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('USE `' . $dbName . '`');

try {
    $root->exec('CREATE TABLE users (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      ad_soyad VARCHAR(120) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $root->exec("INSERT INTO users (id, ad_soyad) VALUES (1, 'Hazirlayan'), (2, 'Onaylayan')");

    s87p045Apply($root, '033_sirket_calisma_politikalari.sql');

    // Seed legacy approved policy BEFORE 045 (no evidence columns yet)
    $root->exec("INSERT INTO sirket_calisma_politikalari (
        revision_no, state, gecerlilik_baslangic, gecerlilik_bitis, aciklama,
        policy_version_hash, hazirlayan_id, onaylayan_id, onay_zamani, created_by, updated_by
      ) VALUES (
        1, 'ONAYLANDI', '2026-08-01', NULL, 'Legacy August policy',
        '01b4ad1ba53b5f61200fd418ff4a3d86d52f987c1a77ca4348d42f5f6eb15735',
        1, 1, '2026-07-23 18:15:30', 1, 1
      )");
    $legacyId = (int) $root->lastInsertId();
    $legacyHashBefore = (string) $root->query("SELECT policy_version_hash FROM sirket_calisma_politikalari WHERE id = {$legacyId}")->fetchColumn();
    $legacyCountBefore = (int) $root->query('SELECT COUNT(*) FROM sirket_calisma_politikalari')->fetchColumn();
    $legacyStateBefore = (string) $root->query("SELECT state FROM sirket_calisma_politikalari WHERE id = {$legacyId}")->fetchColumn();
    $legacyBasBefore = (string) $root->query("SELECT gecerlilik_baslangic FROM sirket_calisma_politikalari WHERE id = {$legacyId}")->fetchColumn();

    s87p045Apply($root, '045_sirket_politikasi_kanit_owner.sql');
    s87p045Apply($root, '045_sirket_politikasi_kanit_owner.sql'); // idempotent

    $cols = $root->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sirket_calisma_politikalari'
           AND COLUMN_NAME IN ('belge_id','belge_sha256')"
    )->fetchAll();
    $byName = [];
    foreach ($cols as $c) {
        $byName[$c['COLUMN_NAME']] = $c;
    }
    s87p045Assert(isset($byName['belge_id']) && strpos($byName['belge_id']['COLUMN_TYPE'], 'varchar(160)') !== false, 'belge_id column type');
    s87p045Assert(isset($byName['belge_sha256']) && $byName['belge_sha256']['COLUMN_TYPE'] === 'char(64)', 'belge_sha256 column type');
    s87p045Assert($byName['belge_id']['IS_NULLABLE'] === 'YES' && $byName['belge_sha256']['IS_NULLABLE'] === 'YES', 'evidence columns nullable');

    $idx = (int) $root->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sirket_calisma_politikalari'
           AND INDEX_NAME = 'idx_scp_belge_id'"
    )->fetchColumn();
    s87p045Assert($idx > 0, 'idx_scp_belge_id exists');

    $chkPair = (int) $root->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sirket_calisma_politikalari'
           AND CONSTRAINT_NAME = 'chk_scp_belge_pair' AND CONSTRAINT_TYPE = 'CHECK'"
    )->fetchColumn();
    $chkSha = (int) $root->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'sirket_calisma_politikalari'
           AND CONSTRAINT_NAME = 'chk_scp_belge_sha256' AND CONSTRAINT_TYPE = 'CHECK'"
    )->fetchColumn();
    s87p045Assert($chkPair === 1 && $chkSha === 1, 'CHECK constraints present');

    // Pair CHECK
    $pairBlocked = false;
    try {
        $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = 'ONLY_ID' WHERE id = {$legacyId}");
    } catch (PDOException $e) {
        $pairBlocked = true;
    }
    s87p045Assert($pairBlocked, 'pair CHECK rejects single-field');

    // SHA CHECK
    $shaBlocked = false;
    try {
        $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = 'X', belge_sha256 = 'NOT_A_HASH' WHERE id = {$legacyId}");
    } catch (PDOException $e) {
        $shaBlocked = true;
    }
    s87p045Assert($shaBlocked, 'SHA CHECK rejects non-hex');

    $legacyAfter = $root->query("SELECT policy_version_hash, state, gecerlilik_baslangic, belge_id, belge_sha256 FROM sirket_calisma_politikalari WHERE id = {$legacyId}")->fetch();
    s87p045Assert((string) $legacyAfter['policy_version_hash'] === $legacyHashBefore, 'legacy policy_version_hash unchanged');
    s87p045Assert((string) $legacyAfter['state'] === $legacyStateBefore, 'legacy state unchanged');
    s87p045Assert((string) $legacyAfter['gecerlilik_baslangic'] === $legacyBasBefore, 'legacy effective start unchanged');
    s87p045Assert($legacyAfter['belge_id'] === null && $legacyAfter['belge_sha256'] === null, 'legacy evidence remains NULL (no backfill)');
    s87p045Assert((int) $root->query('SELECT COUNT(*) FROM sirket_calisma_politikalari')->fetchColumn() === $legacyCountBefore, 'row count unchanged by migration');

    $legacyMapped = SirketCalismaPolitikasiService::getPolitikaDetail($root, $legacyId);
    s87p045Assert($legacyMapped['evidence_status'] === 'LEGACY_MISSING', 'legacy approved => LEGACY_MISSING');

    $resolved = SirketCalismaPolitikasiService::resolveApprovedForPeriod($root, '2026-08-01', '2026-08-31');
    s87p045Assert($resolved['politika'] !== null && (int) $resolved['politika']['id'] === $legacyId, 'legacy resolveApprovedForPeriod still works');
    s87p045Assert($resolved['politika']['evidence_status'] === 'LEGACY_MISSING', 'resolved legacy evidence_status');

    $actor1 = ['id' => 1, 'rol' => 'MUHASEBE'];
    $actor2 = ['id' => 2, 'rol' => 'GENEL_YONETICI'];
    $degerler = s87p045FullDegerler();

    // Create without evidence
    $draft = SirketCalismaPolitikasiService::createDraft($root, [
        'gecerlilik_baslangic' => '2026-09-01',
        'aciklama' => 'Draft without evidence',
        'degerler' => $degerler,
    ], $actor1, hash('sha256', 'create-no-ev'));
    s87p045Assert($draft['evidence_status'] === 'MISSING', 'new draft without evidence => MISSING');
    s87p045Assert($draft['evidence_status'] !== 'LEGACY_MISSING', 'new draft not LEGACY_MISSING');

    // Incomplete evidence
    $incomplete = false;
    try {
        SirketCalismaPolitikasiService::updateDraft($root, $draft['id'], [
            'gecerlilik_baslangic' => '2026-09-01',
            'belge_id' => 'SYNTH-FORM91-ONLY-ID',
            'degerler' => $degerler,
        ], $actor1, hash('sha256', 'upd-incomplete'));
    } catch (SirketCalismaPolitikasiException $e) {
        $incomplete = $e->getErrorCode() === 'POLICY_EVIDENCE_INCOMPLETE';
    }
    s87p045Assert($incomplete, 'single-field evidence rejected');

    // Invalid hash
    $badHash = false;
    try {
        SirketCalismaPolitikasiService::updateDraft($root, $draft['id'], [
            'gecerlilik_baslangic' => '2026-09-01',
            'belge_id' => 'SYNTH-FORM91-A',
            'belge_sha256' => 'TBD',
            'degerler' => $degerler,
        ], $actor1, hash('sha256', 'upd-tbd'));
    } catch (SirketCalismaPolitikasiException $e) {
        $badHash = $e->getErrorCode() === 'POLICY_EVIDENCE_HASH_INVALID';
    }
    s87p045Assert($badHash, 'placeholder hash rejected');

    $zeroHash = false;
    try {
        SirketCalismaPolitikasiService::updateDraft($root, $draft['id'], [
            'gecerlilik_baslangic' => '2026-09-01',
            'belge_id' => 'SYNTH-FORM91-A',
            'belge_sha256' => str_repeat('0', 64),
            'degerler' => $degerler,
        ], $actor1, hash('sha256', 'upd-zero'));
    } catch (SirketCalismaPolitikasiException $e) {
        $zeroHash = $e->getErrorCode() === 'POLICY_EVIDENCE_HASH_INVALID';
    }
    s87p045Assert($zeroHash, 'zero hash rejected');

    $hashBeforeEvidence = (string) $draft['policy_version_hash'];
    $shaUpper = strtoupper(s87p045SyntheticSha('draft-1'));
    $updated = SirketCalismaPolitikasiService::updateDraft($root, $draft['id'], [
        'gecerlilik_baslangic' => '2026-09-01',
        'belge_id' => 'SYNTH-FORM91-A',
        'belge_sha256' => $shaUpper,
        'degerler' => $degerler,
    ], $actor1, hash('sha256', 'upd-ok'));
    s87p045Assert($updated['belge_sha256'] === strtolower($shaUpper), 'uppercase SHA normalized to lowercase');
    s87p045Assert($updated['evidence_status'] === 'PRESENT_VALID', 'valid evidence => PRESENT_VALID');
    s87p045Assert((string) $updated['policy_version_hash'] === $hashBeforeEvidence, 'policy_version_hash unchanged by evidence');

    $audit = $root->query(
        "SELECT sonraki_snapshot FROM sirket_calisma_politika_auditleri
         WHERE politika_id = {$draft['id']} AND aksiyon = 'UPDATE' ORDER BY id DESC LIMIT 1"
    )->fetch();
    $snap = json_decode((string) $audit['sonraki_snapshot'], true);
    s87p045Assert(is_array($snap) && ($snap['belge_id'] ?? null) === 'SYNTH-FORM91-A', 'audit snapshot includes belge_id');
    s87p045Assert(($snap['belge_sha256'] ?? null) === strtolower($shaUpper), 'audit snapshot includes belge_sha256');

    // Submit without evidence: clear via SQL then fail-closed
    $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = NULL, belge_sha256 = NULL WHERE id = {$draft['id']}");
    $submitMissing = false;
    try {
        SirketCalismaPolitikasiService::submitForApproval($root, $draft['id'], $actor1, hash('sha256', 'submit-miss'));
    } catch (SirketCalismaPolitikasiException $e) {
        $submitMissing = $e->getErrorCode() === 'POLICY_EVIDENCE_REQUIRED';
    }
    s87p045Assert($submitMissing, 'submit without evidence => POLICY_EVIDENCE_REQUIRED');
    $stateStill = (string) $root->query("SELECT state FROM sirket_calisma_politikalari WHERE id = {$draft['id']}")->fetchColumn();
    s87p045Assert($stateStill === 'TASLAK', 'failed submit does not change state');

    // Restore evidence and submit OK
    $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = 'SYNTH-FORM91-A', belge_sha256 = '" . strtolower($shaUpper) . "' WHERE id = {$draft['id']}");
    $submitted = SirketCalismaPolitikasiService::submitForApproval($root, $draft['id'], $actor1, hash('sha256', 'submit-ok'));
    s87p045Assert($submitted['state'] === 'ONAY_BEKLIYOR', 'submit with evidence PASS');

    // Self-approval forbidden
    $selfBlocked = false;
    try {
        SirketCalismaPolitikasiService::approve($root, $draft['id'], $actor1, hash('sha256', 'approve-self'));
    } catch (SirketCalismaPolitikasiException $e) {
        $selfBlocked = $e->getErrorCode() === 'POLICY_SELF_APPROVAL_FORBIDDEN';
    }
    s87p045Assert($selfBlocked, 'self approval forbidden');

    // Strip evidence before approve (simulate race)
    $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = NULL, belge_sha256 = NULL WHERE id = {$draft['id']}");
    $approveMissing = false;
    try {
        SirketCalismaPolitikasiService::approve($root, $draft['id'], $actor2, hash('sha256', 'approve-miss'));
    } catch (SirketCalismaPolitikasiException $e) {
        $approveMissing = $e->getErrorCode() === 'POLICY_EVIDENCE_REQUIRED';
    }
    s87p045Assert($approveMissing, 'approve re-checks evidence in transaction');
    $root->exec("UPDATE sirket_calisma_politikalari SET belge_id = 'SYNTH-FORM91-A', belge_sha256 = '" . strtolower($shaUpper) . "' WHERE id = {$draft['id']}");

    $approved = SirketCalismaPolitikasiService::approve($root, $draft['id'], $actor2, hash('sha256', 'approve-ok'));
    s87p045Assert($approved['state'] === 'ONAYLANDI' && $approved['evidence_status'] === 'PRESENT_VALID', 'approve different user PASS');
    $approveAudit = $root->query(
        "SELECT sonraki_snapshot FROM sirket_calisma_politika_auditleri
         WHERE politika_id = {$draft['id']} AND aksiyon = 'APPROVE' ORDER BY id DESC LIMIT 1"
    )->fetch();
    $approveSnap = json_decode((string) $approveAudit['sonraki_snapshot'], true);
    s87p045Assert(($approveSnap['belge_id'] ?? null) === 'SYNTH-FORM91-A', 'approve audit has belge_id');
    s87p045Assert(($approveSnap['belge_sha256'] ?? null) === strtolower($shaUpper), 'approve audit has belge_sha256');

    $ozet = SirketCalismaPolitikasiService::getKararOzeti($root, $draft['id'], null);
    s87p045Assert(($ozet['evidence_ready_for_approval'] ?? false) === true, 'karar ozeti evidence_ready_for_approval');
    s87p045Assert(($ozet['belge_id'] ?? null) === 'SYNTH-FORM91-A', 'karar ozeti belge_id');

    // Approved immutability: updateDraft rejects non-TASLAK
    $immutable = false;
    try {
        SirketCalismaPolitikasiService::updateDraft($root, $draft['id'], [
            'gecerlilik_baslangic' => '2026-09-01',
            'belge_id' => 'CHANGED',
            'belge_sha256' => s87p045SyntheticSha('changed'),
            'degerler' => $degerler,
        ], $actor1, hash('sha256', 'upd-approved'));
    } catch (SirketCalismaPolitikasiException $e) {
        $immutable = $e->getErrorCode() === 'POLICY_NOT_EDITABLE';
    }
    s87p045Assert($immutable, 'approved evidence immutable via updateDraft');

    // Hash invariant: same values different evidence => same hash
    $d3 = SirketCalismaPolitikasiService::createDraft($root, [
        'gecerlilik_baslangic' => '2027-01-01',
        'belge_id' => 'SYNTH-A',
        'belge_sha256' => s87p045SyntheticSha('A'),
        'degerler' => $degerler,
    ], $actor1, hash('sha256', 'd3'));
    $hashA = (string) $d3['policy_version_hash'];
    $d3b = SirketCalismaPolitikasiService::updateDraft($root, $d3['id'], [
        'gecerlilik_baslangic' => '2027-01-01',
        'belge_id' => 'SYNTH-B',
        'belge_sha256' => s87p045SyntheticSha('B'),
        'degerler' => $degerler,
    ], $actor1, hash('sha256', 'd3b'));
    s87p045Assert((string) $d3b['policy_version_hash'] === $hashA, 'same values + different evidence => same policy_version_hash');

    echo 'SirketPolitikasiKanitOwner045MysqlTestRunner: ALL PASS' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
}
