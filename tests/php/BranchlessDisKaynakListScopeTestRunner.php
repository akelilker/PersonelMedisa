<?php

declare(strict_types=1);

/**
 * DB-backed scope test for the branchless DIS_KAYNAK list visibility.
 *
 * Runs the canonical predicate built by OrgScope::appendPersonelOrgFilter as real
 * SQL against a real (in-memory SQLite) personeller table, so the assertions are
 * about rows the database actually returns rather than about SQL text. The same
 * predicate is executed as both the data query and the COUNT query, which is how
 * PersonellerController::liste uses it.
 *
 * Exit 0 = PASS.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Scope\OrgScope;

function bdFail($msg)
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function bdOk($msg)
{
    echo "OK: {$msg}\n";
}

function bdUser($rol, array $sube = [], array $bolum = [], array $birim = [])
{
    return [
        'id' => 10,
        'rol' => $rol,
        'sube_ids' => $sube,
        'bolum_ids' => $bolum,
        'birim_ids' => $birim,
    ];
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE personeller (
        id INTEGER PRIMARY KEY,
        ad TEXT,
        soyad TEXT,
        tc_kimlik_no TEXT NULL,
        sicil_no TEXT,
        sube_id INTEGER NULL,
        bolum_id INTEGER NULL,
        birim_id INTEGER NULL,
        departman_id INTEGER NULL,
        personel_tipi_id INTEGER NULL,
        aktif_durum TEXT,
        calisan_kapsami TEXT,
        ise_giris_tarihi TEXT NULL
    )'
);

$rows = [
    // Branch 1 permanent staff.
    [1, 'Ayse', 'YILMAZ', 1, 'IC_PERSONEL'],
    [2, 'Mehmet', 'DEMIR', 1, 'IC_PERSONEL'],
    // Branch 2 permanent staff — must never appear in a branch 1 context.
    [3, 'Bora', 'BAYAZIT', 2, 'IC_PERSONEL'],
    // Branch-bound DIS_KAYNAK: stays subject to the normal branch filter.
    [4, 'Bagli', 'DIS', 2, 'DIS_KAYNAK'],
    // The record this change is about: branchless DIS_KAYNAK (migration 076).
    [212, 'İlker', 'AKEL', null, 'DIS_KAYNAK'],
    // Branchless IC_PERSONEL: incomplete data, must stay invisible everywhere.
    [99, 'Sube', 'YOK', null, 'IC_PERSONEL'],
];
$insert = $pdo->prepare(
    'INSERT INTO personeller (id, ad, soyad, sube_id, calisan_kapsami, aktif_durum, sicil_no)
     VALUES (?, ?, ?, ?, ?, \'AKTIF\', ?)'
);
foreach ($rows as $r) {
    $insert->execute([$r[0], $r[1], $r[2], $r[3], $r[4], (string) $r[0]]);
}

/**
 * @return array{ids: array<int, int>, total: int}
 */
function bdVisible(PDO $pdo, array $user, $activeSube, array $extraWhere = [], array $extraParams = [])
{
    $where = ['1=1'];
    $params = [];
    OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p', 'org', $pdo);
    foreach ($extraWhere as $clause) {
        $where[] = $clause;
    }
    $params = array_merge($params, $extraParams);
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT p.id FROM personeller p WHERE $whereSql ORDER BY p.id");
    $stmt->execute($params);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    // Exactly what the controller does for pagination.
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM personeller p WHERE $whereSql");
    $countStmt->execute($params);
    $total = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    return ['ids' => $ids, 'total' => $total];
}

function bdAssertIds(array $result, array $expected, $label)
{
    if ($result['ids'] !== $expected) {
        bdFail($label . ': expected [' . implode(',', $expected) . '] got [' . implode(',', $result['ids']) . ']');
    }
    if ($result['total'] !== count($expected)) {
        bdFail($label . ': count/pagination parity broken, total=' . $result['total'] . ' rows=' . count($expected));
    }
    bdOk($label);
}

$gy = bdUser('GENEL_YONETICI');
$sistem = bdUser('SISTEM_YONETICISI');

// --- Unrestricted user, active branch selected ---
bdAssertIds(bdVisible($pdo, $gy, 1), [1, 2, 212], 'unrestricted + branch1 sees branch1 staff + branchless DIS_KAYNAK');
bdAssertIds(bdVisible($pdo, $gy, 2), [3, 4, 212], 'unrestricted + branch2 sees branch2 staff (incl. bound DIS) + branchless DIS_KAYNAK');
bdAssertIds(bdVisible($pdo, $sistem, 1), [1, 2, 212], 'SISTEM_YONETICISI + branch1 behaves the same');

// A branch with no staff of its own still shows the central branchless pool.
bdAssertIds(bdVisible($pdo, $gy, 7), [212], 'unrestricted + empty branch still sees branchless DIS_KAYNAK');

// --- No active branch header: unchanged behaviour, everything in view ---
bdAssertIds(bdVisible($pdo, $gy, null), [1, 2, 3, 4, 99, 212], 'unrestricted without active branch is unfiltered');

// --- Restricted roles gain nothing ---
foreach (['SUBE_YONETICISI', 'IK_SORUMLUSU', 'MUHASEBE'] as $rol) {
    $u = bdUser($rol, [1]);
    bdAssertIds(bdVisible($pdo, $u, 1), [1, 2], $rol . ' scoped to branch1 does not see branchless DIS_KAYNAK');
    $res = bdVisible($pdo, $u, null);
    if (in_array(212, $res['ids'], true)) {
        bdFail($rol . ' without active branch must not see the branchless DIS_KAYNAK record');
    }
    bdOk($rol . ' without active branch still excludes branchless DIS_KAYNAK');
}

// Cross-branch deny is intact. The active-branch header is already clamped to the
// allowed set by SubeScope::resolveScope, so the predicate proves the deny on the
// headerless path: a branch1 role sees only branch1 rows out of the whole table.
$sube1 = bdUser('SUBE_YONETICISI', [1]);
bdAssertIds(bdVisible($pdo, $sube1, null), [1, 2], 'SUBE_YONETICISI[1] sees no other branch and no branchless record');

// Unit-scoped roles stay denied (no unit membership rows here).
foreach (['BOLUM_YONETICISI', 'BIRIM_AMIRI'] as $rol) {
    $res = bdVisible($pdo, bdUser($rol), 1);
    bdAssertIds($res, [], $rol . ' without assignment is denied everything');
}

// --- Branchless IC_PERSONEL is never widened ---
foreach ([1, 2, 7] as $branch) {
    $res = bdVisible($pdo, $gy, $branch);
    if (in_array(99, $res['ids'], true)) {
        bdFail('branchless IC_PERSONEL leaked into branch ' . $branch);
    }
}
bdOk('branchless IC_PERSONEL never appears in a branch context');

// --- Branch-bound DIS_KAYNAK still obeys the branch filter ---
$branch1 = bdVisible($pdo, $gy, 1);
if (in_array(4, $branch1['ids'], true)) {
    bdFail('branch2-bound DIS_KAYNAK leaked into branch 1');
}
bdOk('branch-bound DIS_KAYNAK stays in its own branch');

// --- Other filters compose with the widened predicate ---
$searched = bdVisible(
    $pdo,
    $gy,
    1,
    ['(LOWER(p.ad) LIKE :s_ad OR LOWER(p.soyad) LIKE :s_soyad)'],
    ['s_ad' => '%akel%', 's_soyad' => '%akel%']
);
bdAssertIds($searched, [212], 'search finds the branchless DIS_KAYNAK record inside a branch context');

$kapsamFiltered = bdVisible(
    $pdo,
    $gy,
    1,
    ['p.calisan_kapsami = :kapsam'],
    ['kapsam' => 'DIS_KAYNAK']
);
bdAssertIds($kapsamFiltered, [212], 'calisan_kapsami=DIS_KAYNAK filter returns only the branchless record in branch1');

$icFiltered = bdVisible(
    $pdo,
    $gy,
    1,
    ['p.calisan_kapsami = :kapsam'],
    ['kapsam' => 'IC_PERSONEL']
);
bdAssertIds($icFiltered, [1, 2], 'calisan_kapsami=IC_PERSONEL filter is unaffected');

$archived = bdVisible($pdo, $gy, 1, ["p.aktif_durum = 'PASIF'"]);
bdAssertIds($archived, [], 'aktiflik filter still clamps the widened predicate');

// --- Pagination parity across pages ---
$all = bdVisible($pdo, $gy, 1);
$stmt = $pdo->prepare(
    'SELECT p.id FROM personeller p WHERE ' . '1=1 AND ((p.sube_id = 1) OR (p.sube_id IS NULL AND p.calisan_kapsami = \'DIS_KAYNAK\'))'
    . ' ORDER BY p.id LIMIT 2 OFFSET 2'
);
$stmt->execute();
$page2 = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
if ($all['total'] !== 3 || $page2 !== [212]) {
    bdFail('pagination over the widened predicate is inconsistent: total=' . $all['total'] . ' page2=[' . implode(',', $page2) . ']');
}
bdOk('pagination over the widened predicate is consistent with the total');

echo "BRANCHLESS_DIS_KAYNAK_LIST_SCOPE=PASS\n";
