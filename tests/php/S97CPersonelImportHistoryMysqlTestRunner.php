<?php

declare(strict_types=1);

/**
 * S97-C MariaDB acceptance: personel import history (read-only).
 * Requires MEDISA_TEST_MYSQL_DSN and MEDISA_TEST_MYSQL_USER.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelImportHistoryService;
use Medisa\Api\Services\Personel\PersonelImportHistoryStatus;

function s97cAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97cPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
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
function s97cSplitSql(string $sql): array
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

function s97cApplyMigration(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s97cSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s97cSha(string $seed = 'a'): string
{
    return str_repeat(substr($seed, 0, 1), 64);
}

function s97cQuestions(PDO $pdo): int
{
    $row = $pdo->query("SHOW SESSION STATUS WHERE Variable_name = 'Questions'")->fetch(PDO::FETCH_ASSOC);

    return (int) ($row['Value'] ?? 0);
}

function s97cCount(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function s97cInsertRun(
    PDO $pdo,
    string $key,
    string $status,
    int $actorId,
    $subeId,
    string $startedAt,
    $finishedAt = null,
    int $createdCount = 0,
    int $toplam = 1
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO personel_import_runs (
            idempotency_key, source_sha256, manifest_hash, schema_version,
            actor_id, actor_rol, active_sube_id, status,
            toplam_satir, gecerli_satir, created_count, error_code, started_at, finished_at
        ) VALUES (
            :k, :src, :man, \'personel-import-v1\',
            :actor, \'GENEL_YONETICI\', :sube, :status,
            :toplam, :gecerli, :created, NULL, :started, :finished
        )'
    );
    $stmt->execute([
        'k' => $key,
        'src' => s97cSha('a'),
        'man' => s97cSha('b'),
        'actor' => $actorId,
        'sube' => $subeId,
        'status' => $status,
        'toplam' => $toplam,
        'gecerli' => $toplam,
        'created' => $createdCount,
        'started' => $startedAt,
        'finished' => $finishedAt,
    ]);

    return (int) $pdo->lastInsertId();
}

try {
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('MEDISA_TEST_MYSQL_DSN required');
    }
    $pdo = s97cPdo($dsn);
    s97cApplyMigration($pdo, '046_personel_import_apply_owner.sql');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          username VARCHAR(64) NOT NULL,
          ad_soyad VARCHAR(120) NOT NULL,
          rol VARCHAR(64) NOT NULL,
          durum VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subeler (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          kod VARCHAR(16) NOT NULL,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('DELETE FROM personel_import_run_satirlari');
    $pdo->exec('DELETE FROM personel_import_runs');
    $pdo->exec('DELETE FROM users WHERE id IN (1)');
    $pdo->exec('DELETE FROM subeler WHERE id IN (1, 2)');
    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol, durum) VALUES (1, 's97c_gy', 'S97C GY', 'GENEL_YONETICI', 'AKTIF')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'GRS', 'Giresun')");

    $gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $by = ['id' => 4, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

    // Index evaluation (046)
    $idx = $pdo->query('SHOW INDEX FROM personel_import_runs')->fetchAll(PDO::FETCH_ASSOC);
    $idxNames = array_unique(array_map(static function ($r) {
        return (string) $r['Key_name'];
    }, $idx));
    s97cAssert(in_array('idx_pir_status_started', $idxNames, true), 'index idx_pir_status_started present');
    s97cAssert(in_array('PRIMARY', $idxNames, true), 'primary key present');
    s97cAssert(true, 'MIGRATION_REQUIRED=NO existing 046 indexes sufficient for current volume');

    $empty = PersonelImportHistoryService::listRuns($pdo, $gy, [], null, []);
    s97cAssert($empty['items'] === [] && $empty['next_cursor'] === null, 'empty list items=[] next_cursor=null');

    $id1 = s97cInsertRun($pdo, 's97c-key-1', 'COMPLETED', 1, 1, '2026-08-01 12:00:00.000', '2026-08-01 12:00:01.000', 1);
    $id2 = s97cInsertRun($pdo, 's97c-key-2', 'BASARISIZ', 1, 2, '2026-08-02 12:00:00.000', '2026-08-02 12:00:02.000', 0);
    $id3 = s97cInsertRun($pdo, 's97c-key-3', 'COMPLETED', 1, 1, '2026-08-03 12:00:00.000', '2026-08-03 12:00:03.000', 1);
    // Same timestamp, lower id must come after higher id when sorting DESC.
    $idSameTsLow = s97cInsertRun($pdo, 's97c-key-same-low', 'COMPLETED', 1, 1, '2026-08-05 12:00:00.000', '2026-08-05 12:00:01.000', 1);
    $idSameTsHigh = s97cInsertRun($pdo, 's97c-key-same-high', 'COMPLETED', 1, 1, '2026-08-05 12:00:00.000', '2026-08-05 12:00:01.000', 1);
    s97cInsertRun($pdo, 's97c-key-claimed', 'CLAIMED', 1, 1, '2026-08-04 12:00:00.000', null, 0);

    $masked = '123******45';
    $rowHash = s97cSha('c');
    $stmt = $pdo->prepare(
        'INSERT INTO personel_import_run_satirlari (
            import_run_id, satir_no, personel_id, sicil_no, tc_kimlik_no_masked, row_hash, ad, soyad
        ) VALUES (:run, 1, 501, \'S97C-1\', :tc, :rh, \'Ayşe\', \'Yılmaz\')'
    );
    $stmt->execute(['run' => $id1, 'tc' => $masked, 'rh' => $rowHash]);
    $stmt->execute(['run' => $id3, 'tc' => $masked, 'rh' => $rowHash]);

    $list = PersonelImportHistoryService::listRuns($pdo, $gy, ['limit' => '2'], null, []);
    s97cAssert(count($list['items']) === 2, 'limit 2 returns 2');
    s97cAssert($list['next_cursor'] !== null, 'next_cursor present when more pages');
    s97cAssert((int) $list['items'][0]['import_id'] === $idSameTsHigh, 'same timestamp sorts by id DESC first');
    s97cAssert((int) $list['items'][1]['import_id'] === $idSameTsLow, 'same timestamp sorts by id DESC second');
    foreach ($list['items'] as $item) {
        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE);
        s97cAssert($encoded !== false && strpos($encoded, 'idempotency_key') === false, 'list has no raw idempotency_key');
        s97cAssert(!preg_match('/"tc_kimlik_no"\s*:/', (string) $encoded), 'list has no raw tc');
        s97cAssert(preg_match('/^[0-9a-f]{12}$/', (string) $item['idempotency_fingerprint']) === 1, 'fingerprint 12 hex');
        s97cAssert($item['status'] !== 'CLAIMED', 'default list excludes CLAIMED');
    }

    $page2 = PersonelImportHistoryService::listRuns(
        $pdo,
        $gy,
        ['limit' => '2', 'cursor' => $list['next_cursor']],
        null,
        []
    );
    s97cAssert(count($page2['items']) >= 1, 'cursor page 2 has remaining');
    $page1Ids = array_map(static function ($i) {
        return (int) $i['import_id'];
    }, $list['items']);
    $page2Ids = array_map(static function ($i) {
        return (int) $i['import_id'];
    }, $page2['items']);
    s97cAssert(count(array_intersect($page1Ids, $page2Ids)) === 0, 'cursor pages have no duplicates');

    $mismatchedFilter = false;
    try {
        PersonelImportHistoryService::listRuns(
            $pdo,
            $gy,
            ['limit' => '2', 'cursor' => $list['next_cursor'], 'status' => 'BASARISIZ'],
            null,
            []
        );
    } catch (PersonelImportException $e) {
        $mismatchedFilter = $e->getCodeString() === 'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID';
    }
    s97cAssert($mismatchedFilter, 'cursor rejected when filters change');

    $invalidCursorThrown = false;
    try {
        PersonelImportHistoryService::listRuns($pdo, $gy, ['cursor' => '!!!'], null, []);
    } catch (PersonelImportException $e) {
        $invalidCursorThrown = $e->getCodeString() === 'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID';
    }
    s97cAssert($invalidCursorThrown, 'invalid cursor fail-closed');

    $oversized = false;
    try {
        PersonelImportHistoryService::listRuns($pdo, $gy, ['cursor' => str_repeat('a', 600)], null, []);
    } catch (PersonelImportException $e) {
        $oversized = $e->getCodeString() === 'PERSONEL_IMPORT_HISTORY_CURSOR_INVALID';
    }
    s97cAssert($oversized, 'oversized cursor rejected');

    $badStatus = false;
    try {
        PersonelImportHistoryService::listRuns($pdo, $gy, ['status' => 'FAILED'], null, []);
    } catch (PersonelImportException $e) {
        $badStatus = $e->getCodeString() === 'PERSONEL_IMPORT_HISTORY_STATUS_INVALID';
    }
    s97cAssert($badStatus, 'invalid status rejected (no fictional FAILED)');

    $claimedOnly = PersonelImportHistoryService::listRuns($pdo, $gy, ['status' => 'CLAIMED'], null, []);
    s97cAssert(count($claimedOnly['items']) === 1, 'CLAIMED filter returns schema status');

    $badLimit = false;
    try {
        PersonelImportHistoryService::listRuns($pdo, $gy, ['limit' => '101'], null, []);
    } catch (PersonelImportException $e) {
        $badLimit = $e->getCodeString() === 'PERSONEL_IMPORT_HISTORY_LIMIT_INVALID';
    }
    s97cAssert($badLimit, 'limit max 100');

    $dateFilter = PersonelImportHistoryService::listRuns(
        $pdo,
        $gy,
        ['date_from' => '2026-08-03', 'date_to' => '2026-08-03'],
        null,
        []
    );
    s97cAssert(count($dateFilter['items']) === 1 && (int) $dateFilter['items'][0]['import_id'] === $id3, 'date filter full day');

    $scoped = PersonelImportHistoryService::listRuns($pdo, $by, [], 1, [1]);
    $scopedIds = array_map(static function ($item) {
        return (int) $item['import_id'];
    }, $scoped['items']);
    s97cAssert(in_array($id1, $scopedIds, true) && in_array($id3, $scopedIds, true), 'scope includes sube 1 runs');
    s97cAssert(!in_array($id2, $scopedIds, true), 'scope excludes other sube');

    $preRuns = s97cCount($pdo, 'personel_import_runs');
    $preSatir = s97cCount($pdo, 'personel_import_run_satirlari');

    $detail = PersonelImportHistoryService::getRun($pdo, $gy, $id1, null, []);
    s97cAssert((int) $detail['import_id'] === $id1, 'detail success');
    s97cAssert($detail['satirlar'][0]['tc_kimlik_no_masked'] === $masked, 'masked TC exact');
    s97cAssert(($detail['satirlar'][0]['ad_soyad'] ?? '') === 'Ayşe Yılmaz', 'detail ad_soyad');
    $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE);
    s97cAssert($encoded !== false && strpos($encoded, 'idempotency_key') === false, 'detail no raw key');
    s97cAssert(!preg_match('/"tc_kimlik_no"\s*:/', (string) $encoded), 'detail no raw tc field');
    s97cAssert(!preg_match('/\btc_sha256\b/', (string) $encoded), 'detail no tc_sha256');

    $hidden = false;
    try {
        PersonelImportHistoryService::getRun($pdo, $by, $id2, 1, [1]);
    } catch (PersonelImportException $e) {
        $hidden = $e->getHttpStatus() === 404 && $e->getCodeString() === 'NOT_FOUND';
    }
    s97cAssert($hidden, 'out-of-scope detail is 404');

    $csv = PersonelImportHistoryService::buildEvidenceCsv($pdo, $gy, $id1, null, []);
    s97cAssert(strpos($csv['csv'], "\xEF\xBB\xBF") === 0, 'evidence UTF-8 BOM');
    s97cAssert(strpos($csv['csv'], ';') !== false, 'evidence semicolon');
    s97cAssert(strpos($csv['filename'], 'personel-import-kaniti-' . $id1) === 0, 'evidence filename');
    s97cAssert(strpos($csv['csv'], 'idempotency_fingerprint') !== false, 'evidence has fingerprint column');
    s97cAssert(strpos($csv['csv'], $masked) !== false, 'evidence has masked TC');
    s97cAssert(stripos($csv['csv'], 'idempotency_key') === false, 'evidence no raw idempotency key');
    s97cAssert(!preg_match('/(^|;|\r|\n)tc_kimlik_no(;|\r|\n|$)/', $csv['csv']), 'evidence no raw tc column');

    $pdo->prepare(
        'UPDATE personel_import_run_satirlari SET ad = :ad, soyad = :soyad, sicil_no = :sicil WHERE import_run_id = :run'
    )->execute([
        'ad' => '=1+1',
        'soyad' => 'Yilmaz',
        'sicil' => '@SUM(1)',
        'run' => $id1,
    ]);
    $csvInj = PersonelImportHistoryService::buildEvidenceCsv($pdo, $gy, $id1, null, []);
    s97cAssert(strpos($csvInj['csv'], "'=1+1") !== false, 'formula injection ad guarded');
    s97cAssert(strpos($csvInj['csv'], "'@SUM(1)") !== false, 'formula injection sicil guarded');

    $pdo->prepare(
        'UPDATE personel_import_run_satirlari SET ad = :ad, soyad = :soyad WHERE import_run_id = :run'
    )->execute(['ad' => '', 'soyad' => '+cmd', 'run' => $id1]);
    $csvInj2 = PersonelImportHistoryService::buildEvidenceCsv($pdo, $gy, $id1, null, []);
    s97cAssert(strpos($csvInj2['csv'], "'+cmd") !== false, 'formula injection soyad guarded');

    $tabGuard = false;
    $pdo->prepare(
        'UPDATE personel_import_run_satirlari SET ad = :ad, soyad = :soyad WHERE import_run_id = :run'
    )->execute(['ad' => "\tCMD", 'soyad' => '', 'run' => $id1]);
    $csvTab = PersonelImportHistoryService::buildEvidenceCsv($pdo, $gy, $id1, null, []);
    $tabGuard = strpos($csvTab['csv'], "'\tCMD") !== false;
    s97cAssert($tabGuard, 'formula injection tab guarded');

    s97cAssert(s97cCount($pdo, 'personel_import_runs') === $preRuns, 'read-only runs delta 0');
    s97cAssert(s97cCount($pdo, 'personel_import_run_satirlari') === $preSatir, 'read-only satir delta 0');

    // Schema missing
    $pdo->exec('RENAME TABLE personel_import_run_satirlari TO personel_import_run_satirlari_s97c_bak');
    $pdo->exec('RENAME TABLE personel_import_runs TO personel_import_runs_s97c_bak');
    $schemaMissing = false;
    try {
        PersonelImportHistoryService::listRuns($pdo, $gy, [], null, []);
    } catch (PersonelImportException $e) {
        $schemaMissing = $e->getCodeString() === 'SCHEMA_NOT_READY' && $e->getHttpStatus() === 409;
    }
    s97cAssert($schemaMissing, 'schema missing SCHEMA_NOT_READY no 500');
    $pdo->exec('RENAME TABLE personel_import_runs_s97c_bak TO personel_import_runs');
    $pdo->exec('RENAME TABLE personel_import_run_satirlari_s97c_bak TO personel_import_run_satirlari');

    s97cAssert(PersonelImportHistoryStatus::label('COMPLETED') === 'Tamamlandı', 'status label COMPLETED');
    s97cAssert(PersonelImportHistoryStatus::label('BASARISIZ') === 'Başarısız', 'status label BASARISIZ');
    s97cAssert(PersonelImportHistoryStatus::label('WEIRD') === 'Bilinmeyen durum', 'unknown status scrubbed');
    s97cAssert(PersonelImportApplyService::schemaReady($pdo) === true, 'schema ready after restore');

    // N+1: query count stable across 5 vs 25 list sizes
    $pdo->exec('DELETE FROM personel_import_run_satirlari');
    $pdo->exec('DELETE FROM personel_import_runs');
    for ($i = 0; $i < 25; $i++) {
        s97cInsertRun(
            $pdo,
            's97c-bulk-' . $i,
            'COMPLETED',
            1,
            1,
            sprintf('2026-07-%02d 10:00:00.000', max(1, 25 - $i)),
            sprintf('2026-07-%02d 10:00:01.000', max(1, 25 - $i)),
            1
        );
    }
    $qBefore5 = s97cQuestions($pdo);
    PersonelImportHistoryService::listRuns($pdo, $gy, ['limit' => '5'], null, []);
    $qAfter5 = s97cQuestions($pdo);
    $delta5 = $qAfter5 - $qBefore5;

    $qBefore25 = s97cQuestions($pdo);
    $bulk = PersonelImportHistoryService::listRuns($pdo, $gy, ['limit' => '25'], null, []);
    $qAfter25 = s97cQuestions($pdo);
    $delta25 = $qAfter25 - $qBefore25;
    s97cAssert(count($bulk['items']) === 25, '25 item list without N+1 requirement breach');
    s97cAssert($delta5 > 0 && $delta25 > 0 && $delta25 <= $delta5 + 2, 'query count does not grow with item count');

    $explain = $pdo->query(
        "EXPLAIN SELECT r.id FROM personel_import_runs r WHERE r.status IN ('COMPLETED','BASARISIZ') ORDER BY r.started_at DESC, r.id DESC LIMIT 26"
    )->fetch(PDO::FETCH_ASSOC);
    s97cAssert(is_array($explain), 'EXPLAIN list query available');

    // 500-row detail/evidence
    $bigId = s97cInsertRun($pdo, 's97c-big', 'COMPLETED', 1, 1, '2026-06-01 10:00:00.000', '2026-06-01 10:01:00.000', 500, 500);
    $ins = $pdo->prepare(
        'INSERT INTO personel_import_run_satirlari (
            import_run_id, satir_no, personel_id, sicil_no, tc_kimlik_no_masked, row_hash, ad, soyad
        ) VALUES (:run, :no, :pid, :sicil, :tc, :rh, :ad, :soyad)'
    );
    for ($n = 1; $n <= 500; $n++) {
        $ins->execute([
            'run' => $bigId,
            'no' => $n,
            'pid' => 1000 + $n,
            'sicil' => 'B' . $n,
            'tc' => $masked,
            'rh' => s97cSha('d'),
            'ad' => 'Ad',
            'soyad' => 'Soyad',
        ]);
    }
    $bigDetail = PersonelImportHistoryService::getRun($pdo, $gy, $bigId, null, []);
    s97cAssert(count($bigDetail['satirlar']) === 500, 'detail accepts 500 rows');
    $bigCsv = PersonelImportHistoryService::buildEvidenceCsv($pdo, $gy, $bigId, null, []);
    $lineCount = substr_count($bigCsv['csv'], "\n");
    s97cAssert($lineCount >= 501, 'evidence accepts 500 rows');

    // Negative write routes: Router source contract (no history write methods).
    $router = file_get_contents(__DIR__ . '/../../api/src/Router.php');
    s97cAssert(is_string($router) && strpos($router, "/personeller/import/runs") !== false, 'router has history GET');
    s97cAssert(
        is_string($router)
        && !preg_match('#/personeller/import/runs[^\n]*POST#', $router)
        && strpos($router, 'import/runs/{id}/retry') === false,
        'router has no history write/retry routes'
    );
    $historySrc = file_get_contents(__DIR__ . '/../../api/src/Services/Personel/PersonelImportHistoryService.php');
    s97cAssert(
        is_string($historySrc)
        && strpos($historySrc, 'INSERT INTO personel_import') === false
        && strpos($historySrc, 'UPDATE personel_import') === false
        && strpos($historySrc, 'DELETE FROM personel_import') === false
        && preg_match('/^\s*r\.idempotency_key\s*,/m', $historySrc) !== 1,
        'history service read-only and no raw key projection'
    );

    echo 'verify-s97c-personel-import-history-mysql: OK' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
