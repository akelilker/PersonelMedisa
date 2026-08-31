<?php

declare(strict_types=1);

/**
 * Unified personel search — DB-backed acceptance against a real MariaDB.
 *
 * Everything here is asserted on rows a real database returns for the predicate
 * built by PersonelSearchPredicate, combined with the canonical org/scope
 * predicate exactly the way PersonellerController::list composes them: scope
 * first, search only ever ANDed on top. Turkish case folding and LIKE wildcard
 * escaping are proven by real SQL rather than assumed from collation names.
 *
 * php tests/php/PersonelUnifiedSearchMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Personel\PersonelSearchPredicate;

function pusAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pusPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Mirrors production: named placeholders may not be re-used.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function pusUser(string $rol, array $sube = []): array
{
    return ['id' => 10, 'rol' => $rol, 'sube_ids' => $sube, 'bolum_ids' => [], 'birim_ids' => []];
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '' || stripos($dsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $dsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$db = 'medisa_pus_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = pusPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = pusPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

try {
    // Production-shaped columns: 066 nullable identity + calisan_kapsami.
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL PRIMARY KEY,
            ad VARCHAR(80) NOT NULL,
            soyad VARCHAR(80) NULL,
            tc_kimlik_no VARCHAR(11) NULL,
            sicil_no VARCHAR(32) NOT NULL,
            telefon VARCHAR(32) NULL,
            dogum_tarihi DATE NULL,
            sube_id INT UNSIGNED NULL,
            departman_id INT UNSIGNED NULL,
            personel_tipi_id INT UNSIGNED NULL,
            aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            calisan_kapsami ENUM('IC_PERSONEL','DIS_KAYNAK') NOT NULL DEFAULT 'IC_PERSONEL'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $fixtures = [
        // id,  ad,        soyad,      sicil, tc,            telefon,      sube, kapsam
        [212, 'İlker', 'AKEL', '473', '12345678901', '05551112233', null, 'DIS_KAYNAK'],
        [1, 'Şule', 'ÇAĞLAR', '101', '10000000001', '05320000001', 1, 'IC_PERSONEL'],
        [2, 'Gülşah', 'ÖZTÜRK', '102', '10000000002', '05320000002', 1, 'IC_PERSONEL'],
        [3, 'Irmak', 'IŞIK', '103', '10000000003', '05320000003', 1, 'IC_PERSONEL'],
        [4, 'Ahmet Can', 'ÖZ DEMİR', '104', '10000000004', '05320000004', 1, 'IC_PERSONEL'],
        [5, 'Ayse', 'YILMAZ', '473473', '10000000005', '05320000005', 2, 'IC_PERSONEL'],
        // Same branch as the active one: proves sicil substring matching on >1 row.
        [6, 'Deniz', 'KAYA', '4730', '10000000006', '05320000006', 1, 'IC_PERSONEL'],
        // LIKE wildcard / escape fixtures.
        [50, 'Ali', 'A%B', '150', null, null, 1, 'IC_PERSONEL'],
        [51, 'Umut', 'X_Y', '151', null, null, 1, 'IC_PERSONEL'],
        [52, 'Cem', 'C\\D', '152', null, null, 1, 'IC_PERSONEL'],
        // Branchless IC_PERSONEL: must never become visible through search.
        [99, 'Sube', 'YOK', '199', null, null, null, 'IC_PERSONEL'],
    ];
    $insert = $pdo->prepare(
        'INSERT INTO personeller (id, ad, soyad, sicil_no, tc_kimlik_no, telefon, sube_id, calisan_kapsami)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($fixtures as $row) {
        $insert->execute($row);
    }

    /**
     * Exactly the controller's composition: scope predicate, then search, then
     * the data query / count query / missing-count query over one $whereSql.
     *
     * @return array{ids: list<int>, total: int, missing: int, sql: string}
     */
    $search = static function (
        string $needle,
        array $user,
        $activeSube = 1,
        array $extraWhere = [],
        array $extraParams = [],
        int $limit = 100,
        int $page = 1
    ) use ($pdo): array {
        $where = ['1=1'];
        $params = [];
        OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p', 'org', $pdo);
        foreach ($extraWhere as $clause) {
            $where[] = $clause;
        }
        $params = array_merge($params, $extraParams);

        $normalized = PersonelSearchPredicate::normalize($needle);
        PersonelSearchPredicate::append($where, $params, $normalized, 'p', 'search', $pdo);

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare(
            "SELECT p.id FROM personeller p WHERE $whereSql ORDER BY p.id ASC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        // Same predicate, same params — pagination total.
        $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Same predicate again, the way the missing-info badge counts.
        $missingStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql AND p.telefon IS NULL"
        );
        $missingStmt->execute($params);
        $missing = (int) ($missingStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        return ['ids' => $ids, 'total' => $total, 'missing' => $missing, 'sql' => $whereSql];
    };

    $gy = pusUser('GENEL_YONETICI');

    $expect = static function (string $needle, array $expected, string $label) use ($search, $gy): void {
        $result = $search($needle, $gy, 1);
        pusAssert(
            $result['ids'] === $expected,
            $label . ' — expected [' . implode(',', $expected) . '] got [' . implode(',', $result['ids']) . ']'
        );
        pusAssert(
            $result['total'] === count($result['ids']),
            $label . ' — data/count parity'
        );
    };

    // --- Unified ad+soyad semantics -------------------------------------------
    $expect('İlker Akel', [212], 'combined "İlker Akel" resolves the single person');
    $expect('Akel İlker', [212], 'reverse token order resolves the same person');
    $expect('ilk ak', [212], 'partial tokens across ad and soyad');
    $expect('İLKER AKEL', [212], 'uppercase Turkish input');
    $expect('ilker akel', [212], 'lowercase ASCII input');
    $expect('ILKER AKEL', [212], 'dotted/dotless I variation');
    $expect('  İlker    Akel  ', [212], 'leading/trailing/multiple whitespace is ignored');
    $expect("\tİlker\u{00a0}Akel\n", [212], 'tab / NBSP / newline count as whitespace');

    // Multi-word ad and soyad still behave as one "Ad Soyad" field.
    $expect('ahmet oz demir', [4], 'multi-word ad + soyad matched as one field');
    $expect('demir ahmet', [4], 'multi-word name in reverse order');

    // --- Token AND / field OR -------------------------------------------------
    $expect('Akel 473', [212], 'token AND across fields: name token + sicil token');
    $expect('Akel Yilmaz', [], 'tokens are ANDed, so two different people never match');
    $expect('473', [6, 212], 'sicil token matches sicil_no on every in-scope row that contains it');
    $expect('12345678901', [212], 'T.C. Kimlik No still searchable');
    $expect('05551112233', [212], 'telefon still searchable');
    $expect('5551112', [212], 'partial telefon');

    // --- Turkish case / character folding, proven by the database -------------
    $expect('sule', [1], 'ASCII "sule" finds "Şule"');
    $expect('Şule', [1], 'native "Şule" finds itself');
    $expect('sule caglar', [1], 'Ç/Ğ folded across both name parts');
    $expect('gulsah', [2], 'Ü/Ş folded');
    $expect('ozturk', [2], 'Ö/Ü folded in soyad');
    $expect('isik', [3], 'dotted i input finds "IŞIK"');
    $expect('ısık', [3], 'dotless ı input finds "IŞIK"');
    $expect('IŞIK', [3], 'native uppercase Turkish input');

    // The collation is applied to the search expression only; the schema keeps its own.
    $collationSql = $search('ilker', $gy, 1)['sql'];
    pusAssert(
        strpos($collationSql, 'COLLATE utf8mb4_general_ci') !== false,
        'search expression carries an explicit case-folding collation'
    );
    pusAssert(
        strpos($collationSql, "CONCAT_WS(' ', p.ad, p.soyad)") !== false,
        'ad + soyad are searched as one canonical concatenated expression'
    );
    $schemaCollation = (string) $pdo->query(
        "SELECT COLLATION_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller' AND COLUMN_NAME = 'ad'"
    )->fetchColumn();
    pusAssert(
        $schemaCollation === 'utf8mb4_unicode_ci',
        'no schema/collation migration happened (column still utf8mb4_unicode_ci)'
    );

    // --- LIKE wildcards typed by the user are literal ------------------------
    $expect('%', [50], 'literal % matches only the row that contains %');
    $expect('_', [51], 'literal _ matches only the row that contains _');
    $expect('X_Y', [51], 'underscore inside a token is literal');
    $expect('XaY', [], 'underscore does not act as a single-character wildcard');
    $expect('\\', [52], 'literal backslash is matched, not treated as an escape');
    $expect('%%%%', [], 'a run of wildcards matches nothing rather than everything');

    // --- Empty / whitespace-only / overlong input ----------------------------
    $emptyNeedle = $search('   ', $gy, 1);
    $unfiltered = $search('', $gy, 1);
    pusAssert(
        $emptyNeedle['ids'] === $unfiltered['ids'] && count($unfiltered['ids']) > 1,
        'whitespace-only search behaves like no search at all'
    );
    pusAssert(
        strpos($unfiltered['sql'], 'LIKE') === false,
        'empty search appends no predicate'
    );

    $overlong = $search(str_repeat('a', 400) . ' akel', $gy, 1);
    pusAssert($overlong['total'] === count($overlong['ids']), 'overlong input stays a valid bounded query');
    pusAssert(
        mb_strlen(PersonelSearchPredicate::normalize(str_repeat('ü', 400))) === PersonelSearchPredicate::MAX_LENGTH,
        'overlong input is clamped to MAX_LENGTH without breaking multibyte characters'
    );
    pusAssert(
        count(PersonelSearchPredicate::tokenize('a b c d e f g h i j')) === PersonelSearchPredicate::MAX_TOKENS,
        'token count is bounded'
    );

    // --- Scope + search: allow / deny ----------------------------------------
    $unrestricted = $search('İlker Akel', $gy, 1);
    pusAssert($unrestricted['ids'] === [212], 'unrestricted user in active branch finds branchless DIS_KAYNAK 212');
    pusAssert(
        $search('İlker Akel', pusUser('SISTEM_YONETICISI'), 1)['ids'] === [212],
        'SISTEM_YONETICISI behaves the same'
    );

    foreach (['IK_SORUMLUSU', 'IK_PERSONELI'] as $ikRol) {
        pusAssert(
            $search('İlker Akel', pusUser($ikRol, [1]), 1)['ids'] === [212],
            $ikRol . ' reads every branch, including the branchless DIS_KAYNAK row'
        );
    }

    foreach (['SUBE_YONETICISI', 'MUHASEBE'] as $rol) {
        $restricted = $search('İlker Akel', pusUser($rol, [1]), 1);
        pusAssert($restricted['ids'] === [], $rol . ' cannot reach branchless DIS_KAYNAK through search');
        $byName = $search('Akel', pusUser($rol, [1]), 1);
        pusAssert($byName['ids'] === [], $rol . ' cannot reach it by surname either');
        // Sicil "473" is a substring of an in-branch sicil too: the role keeps the
        // row it is entitled to and still never gets the branchless one.
        $bySicil = $search('473', pusUser($rol, [1]), 1);
        pusAssert(
            $bySicil['ids'] === [6],
            $rol . ' sees only its own branch row for a sicil substring search'
        );
        $headerless = $search('Akel', pusUser($rol, [1]), null);
        pusAssert(
            !in_array(212, $headerless['ids'], true),
            $rol . ' without an active branch still cannot reach it'
        );
    }

    // Cross-branch deny: a branch-1 role never sees branch-2 staff via search.
    pusAssert(
        $search('yilmaz', pusUser('SUBE_YONETICISI', [1]), 1)['ids'] === [],
        'search never crosses the branch boundary'
    );

    // Branchless IC_PERSONEL is never widened by search.
    pusAssert($search('Sube Yok', $gy, 1)['ids'] === [], 'branchless IC_PERSONEL stays invisible in a branch context');

    // --- Search composes with the other filters (AND only) -------------------
    $withKapsam = $search('akel', $gy, 1, ['p.calisan_kapsami = :kapsam'], ['kapsam' => 'DIS_KAYNAK']);
    pusAssert($withKapsam['ids'] === [212], 'search AND calisan_kapsami filter');
    $withIc = $search('akel', $gy, 1, ['p.calisan_kapsami = :kapsam'], ['kapsam' => 'IC_PERSONEL']);
    pusAssert($withIc['ids'] === [], 'search AND IC_PERSONEL filter excludes the DIS_KAYNAK row');
    $archived = $search('akel', $gy, 1, ["p.aktif_durum = 'PASIF'"]);
    pusAssert($archived['ids'] === [], 'search AND aktiflik filter');

    // --- Count / pagination parity ------------------------------------------
    $sicilPage1 = $search('473', $gy, 1, [], [], 1, 1);
    $sicilPage2 = $search('473', $gy, 1, [], [], 1, 2);
    $sicilPage3 = $search('473', $gy, 1, [], [], 1, 3);
    pusAssert($sicilPage1['total'] === 2, 'search total is the same on every page');
    pusAssert($sicilPage1['ids'] === [6], 'page 1 returns the first row only');
    pusAssert(
        count($sicilPage1['ids']) === 1
        && count($sicilPage2['ids']) === 1
        && $sicilPage1['ids'] !== $sicilPage2['ids']
        && $sicilPage3['ids'] === [],
        'paged rows add up to the total with no duplicates and no phantom page'
    );
    pusAssert(
        $sicilPage2['total'] === $sicilPage1['total'] && $sicilPage3['total'] === $sicilPage1['total'],
        'count query and data query use one canonical predicate'
    );
    $missingParity = $search('akel', $gy, 1);
    pusAssert(
        $missingParity['missing'] <= $missingParity['total'],
        'missing-count query reuses the same predicate and stays within the total'
    );

    // Exactly one row for the exact query — no join fan-out duplicates.
    $exact = $search('İlker Akel', $gy, 1);
    pusAssert(count($exact['ids']) === 1 && $exact['ids'][0] === 212, 'exact query returns 212 exactly once');

    // --- Bound parameters only ----------------------------------------------
    $injection = $search("akel' OR '1'='1", $gy, 1);
    pusAssert($injection['ids'] === [], 'quote injection is a literal search token, not SQL');

    $where = ['1=1'];
    $params = [];
    PersonelSearchPredicate::append($where, $params, 'İlker Akel', 'p', 'search', $pdo);
    pusAssert(count($params) === 8, 'every field/token pair is bound as its own placeholder');
    foreach ($params as $value) {
        pusAssert(is_string($value) && strpos($value, '%') === 0, 'each bound value is a LIKE pattern');
    }

    // Controllers must not keep their own copy of the predicate.
    foreach (['PersonellerController', 'ArsivController'] as $controller) {
        $src = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/' . $controller . '.php');
        pusAssert(
            strpos($src, 'LOWER(p.ad) LIKE') === false,
            $controller . ' has no duplicated ad/soyad search block'
        );
        pusAssert(
            strpos($src, 'PersonelSearchPredicate::append') !== false,
            $controller . ' delegates to the canonical search owner'
        );
    }

    echo "verify-personel-unified-search-mysql: OK\n";
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    } catch (Throwable $e) {
        // ignore
    }
}
