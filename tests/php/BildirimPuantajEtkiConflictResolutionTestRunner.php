<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiPuantajMapper.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiManualApplyService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiConflictClassificationService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiConflictResolutionService.php';

use Medisa\Api\Services\BildirimPuantajEtkiConflictClassificationService;
use Medisa\Api\Services\BildirimPuantajEtkiConflictResolutionService;
use Medisa\Api\Services\BildirimPuantajEtkiDecisionPolicy;
use Medisa\Api\Services\BildirimPuantajEtkiManualApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use Medisa\Api\Services\BildirimPuantajEtkiPuantajMapper;

function conflictBaseAday(array $overrides = []): array
{
    $snapshot = [
        'gunluk_bildirim_id' => 103,
        'personel_id' => 1,
        'tarih' => '2026-06-04',
        'bildirim_turu' => 'GELMEDI',
    ];

    return array_merge([
        'id' => 3,
        'personel_id' => 1,
        'sube_id' => 1,
        'tarih' => '2026-06-04',
        'state' => 'INCELEME_GEREKLI',
        'etki_turu' => 'DEVAMSIZLIK_GUN',
        'etki_miktari' => null,
        'etki_birimi' => null,
        'conflict_code' => 'MEVCUT_PUANTAJ_VAR',
        'bildirim_aciklama' => 'Gelmedi bildirimi',
        'source_snapshot' => $snapshot,
        'source_hash' => BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot),
        'uygulama_modu' => 'OTOMATIK',
        'uygulanan_puantaj_id' => null,
    ], $overrides);
}

function conflictBasePuantaj(array $overrides = []): array
{
    return array_merge([
        'id' => 55,
        'personel_id' => 1,
        'tarih' => '2026-06-04',
        'state' => 'ACIK',
        'gun_tipi' => null,
        'hareket_durumu' => 'Geldi',
        'dayanak' => 'Yok_Izinsiz',
        'durumu_bildirdi_mi' => 0,
        'durum_bildirim_aciklamasi' => null,
        'hesap_etkisi' => 'Tam_Yevmiye_Ver',
        'beklenen_giris_saati' => '08:00',
        'beklenen_cikis_saati' => '17:00',
        'giris_saati' => '08:30',
        'cikis_saati' => '17:30',
        'gec_kalma_dakika' => null,
        'erken_cikis_dakika' => null,
        'gercek_mola_dakika' => 60,
        'hesaplanan_mola_dakika' => 60,
        'net_calisma_suresi_dakika' => 480,
        'gunluk_brut_sure_dakika' => 540,
        'hafta_tatili_hak_kazandi_mi' => 0,
        'kontrol_durumu' => 'BEKLIYOR',
        'kaynak' => 'MANUEL',
        'aciklama' => null,
        'muhur_id' => null,
        'updated_at' => '2026-06-10 08:00:00',
    ], $overrides);
}

function conflictPdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function createConflictSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INTEGER PRIMARY KEY, personel_id INTEGER, sube_id INTEGER, tarih TEXT, state TEXT,
        etki_turu TEXT, etki_miktari INTEGER, etki_birimi TEXT, conflict_code TEXT,
        bildirim_aciklama TEXT, source_snapshot TEXT, source_hash TEXT,
        uygulama_modu TEXT, karar_veren_user_id INTEGER, karar_zamani TEXT, karar_gerekcesi TEXT,
        uygulanan_puantaj_id INTEGER, onceki_puantaj_snapshot TEXT,
        sonraki_puantaj_snapshot TEXT, uygulama_hash TEXT
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY, personel_id INTEGER, tarih TEXT, state TEXT,
        gun_tipi TEXT, hareket_durumu TEXT, dayanak TEXT, durumu_bildirdi_mi INTEGER,
        durum_bildirim_aciklamasi TEXT, hesap_etkisi TEXT, beklenen_giris_saati TEXT,
        beklenen_cikis_saati TEXT, giris_saati TEXT, cikis_saati TEXT,
        gec_kalma_dakika INTEGER, erken_cikis_dakika INTEGER, gercek_mola_dakika INTEGER,
        hesaplanan_mola_dakika INTEGER, net_calisma_suresi_dakika INTEGER,
        gunluk_brut_sure_dakika INTEGER, hafta_tatili_hak_kazandi_mi INTEGER,
        kontrol_durumu TEXT, kaynak TEXT, aciklama TEXT, muhur_id INTEGER, updated_at TEXT,
        UNIQUE (personel_id, tarih)
    )');
    $pdo->exec('CREATE TABLE bildirim_puantaj_etki_cakisma_cozumleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, aday_id INTEGER UNIQUE, puantaj_id INTEGER,
        sube_id INTEGER, personel_id INTEGER, tarih TEXT, conflict_class TEXT, karar_turu TEXT,
        gerekce TEXT, expected_puantaj_hash TEXT, request_hash TEXT, onceki_snapshot TEXT,
        sonraki_snapshot TEXT, snapshot_schema TEXT, sonuc_hash TEXT,
        karar_veren_user_id INTEGER, karar_zamani TEXT
    )');
}

function seedConflictFixture(PDO $pdo, array $aday, array $puantaj): void
{
    $adayRow = $aday;
    $adayRow['source_snapshot'] = json_encode($aday['source_snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $columns = array_keys($adayRow);
    $stmt = $pdo->prepare('INSERT INTO onayli_bildirim_puantaj_etki_adaylari (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    $stmt->execute($adayRow);

    $pColumns = array_keys($puantaj);
    $pStmt = $pdo->prepare('INSERT INTO gunluk_puantaj (' . implode(', ', $pColumns) . ') VALUES (:' . implode(', :', $pColumns) . ')');
    $pStmt->execute($puantaj);
}

function assertConflict(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$scenarios = [
    ['name' => 'resolve conflict permission constant', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::permissionForAction(
            BildirimPuantajEtkiDecisionPolicy::ACTION_RESOLVE_CONFLICT
        ) === 'puantaj.bildirim_etki.resolve_conflict';
    }],
    ['name' => 'INCELEME_GEREKLI conflict resolve allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isConflictResolveAllowed('INCELEME_GEREKLI') === true;
    }],
    ['name' => 'class A same aday puantaj', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(['state' => 'UYGULANDI', 'uygulanan_puantaj_id' => 55]),
            conflictBasePuantaj()
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_A;
    }],
    ['name' => 'class B other aday source', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['kaynak' => BildirimPuantajEtkiPuantajMapper::KAYNAK])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_B;
    }],
    ['name' => 'class C manual source', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['kaynak' => 'MANUEL'])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_C;
    }],
    ['name' => 'class D resmi surec dayanak', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['dayanak' => 'Yillik_Izin', 'hareket_durumu' => 'Gelmedi'])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_D
            && BildirimPuantajEtkiConflictClassificationService::isReviseAllowed($result['class'], 'ADAY_ETKISIYLE_REVIZE_ET') === false;
    }],
    ['name' => 'class E sealed puantaj', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['state' => 'MUHURLENDI', 'muhur_id' => 1])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_E;
    }],
    ['name' => 'class F amir kontrol', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['kontrol_durumu' => 'AMIR_KONTROL_ETTI'])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_F;
    }],
    ['name' => 'class G legacy kaynak', 'fn' => function () {
        $result = BildirimPuantajEtkiConflictClassificationService::classify(
            conflictBaseAday(),
            conflictBasePuantaj(['kaynak' => null])
        );

        return $result['class'] === BildirimPuantajEtkiConflictClassificationService::CLASS_G;
    }],
    ['name' => 'request hash deterministic', 'fn' => function () {
        $first = BildirimPuantajEtkiConflictResolutionService::computeRequestHash(
            3,
            'INCELEME_GEREKLI',
            'MEVCUT_PUANTAJI_KORU',
            'Mevcut puantaj kaydi dogrulandi ve korunmasina karar verildi.',
            55,
            'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd'
        );
        $second = BildirimPuantajEtkiConflictResolutionService::computeRequestHash(
            3,
            'INCELEME_GEREKLI',
            'MEVCUT_PUANTAJI_KORU',
            'Mevcut puantaj kaydi dogrulandi ve korunmasina karar verildi.',
            55,
            'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd'
        );

        return $first === $second && strlen($first) === 64;
    }],
    ['name' => 'request hash changes on karar', 'fn' => function () {
        $keep = BildirimPuantajEtkiConflictResolutionService::computeRequestHash(
            3,
            'INCELEME_GEREKLI',
            'MEVCUT_PUANTAJI_KORU',
            'Mevcut puantaj kaydi dogrulandi ve korunmasina karar verildi.',
            55,
            'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd'
        );
        $revise = BildirimPuantajEtkiConflictResolutionService::computeRequestHash(
            3,
            'INCELEME_GEREKLI',
            'ADAY_ETKISIYLE_REVIZE_ET',
            'Mevcut puantaj kaydi dogrulandi ve korunmasina karar verildi.',
            55,
            'abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd'
        );

        return $keep !== $revise;
    }],
    ['name' => 'current puantaj hash includes updated_at', 'fn' => function () {
        $row = conflictBasePuantaj();
        $hash = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($row);
        $row['updated_at'] = '2026-06-11 08:00:00';
        $changed = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($row);

        return strlen($hash) === 64 && $hash !== $changed;
    }],
    ['name' => 'revize preserves protected time fields', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::buildRevizeUpdateValues(
            conflictBaseAday(),
            conflictBasePuantaj()
        );
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $values = $result['values'];

        return $values['giris_saati'] === '08:30'
            && $values['cikis_saati'] === '17:30'
            && $values['beklenen_giris_saati'] === '08:00'
            && $values['kontrol_durumu'] === 'BEKLIYOR'
            && $values['kaynak'] === BildirimPuantajEtkiPuantajMapper::KAYNAK_REVIZYON
            && $values['net_calisma_suresi_dakika'] === null;
    }],
    ['name' => 'source integrity required', 'fn' => function () {
        $aday = conflictBaseAday(['source_hash' => 'broken']);
        $integrity = BildirimPuantajEtkiManualApplyService::verifySourceIntegrity($aday);

        return ($integrity['ok'] ?? false) === false;
    }],
];

foreach ($scenarios as $scenario) {
    assertConflict((bool) $scenario['fn'](), $scenario['name']);
}

$path = tempnam(sys_get_temp_dir(), 'medisa-conflict-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}

try {
    $pdo = conflictPdo($path);
    createConflictSchema($pdo);
    $aday = conflictBaseAday();
    $puantaj = conflictBasePuantaj();
    seedConflictFixture($pdo, $aday, $puantaj);
    $hash = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($puantaj);
    $gerekce = 'Mevcut puantaj kaydi dogrulandi ve korunmasina karar verildi.';

    $pdo->beginTransaction();
    $lockedAday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $lockedPuantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $keep = BildirimPuantajEtkiConflictResolutionService::resolve(
        $pdo,
        $lockedAday,
        $lockedPuantaj,
        'INCELEME_GEREKLI',
        'MEVCUT_PUANTAJI_KORU',
        $gerekce,
        55,
        $hash,
        5
    );
    $pdo->commit();

    assertConflict(($keep['status'] ?? '') === 'success', 'keep decision succeeds');
    assertConflict(($keep['aday']['state'] ?? '') === 'YOK_SAYILDI', 'keep sets YOK_SAYILDI');
    assertConflict(($keep['aday']['uygulama_modu'] ?? '') === 'CAKISMA_COZUM', 'keep sets CAKISMA_COZUM');
    assertConflict($keep['aday']['uygulanan_puantaj_id'] === null, 'keep leaves uygulanan_puantaj_id null');
    assertConflict((int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn() === 1, 'keep inserts audit row');

    $pdo->beginTransaction();
    $lockedAday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $lockedPuantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $idem = BildirimPuantajEtkiConflictResolutionService::resolve(
        $pdo,
        $lockedAday,
        $lockedPuantaj,
        'INCELEME_GEREKLI',
        'MEVCUT_PUANTAJI_KORU',
        $gerekce,
        55,
        $hash,
        5
    );
    $pdo->rollBack();

    assertConflict(($idem['status'] ?? '') === 'idempotent', 'same request is idempotent');
    assertConflict((int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn() === 1, 'idempotent keeps single audit row');

    $pdo->beginTransaction();
    $lockedAday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $lockedPuantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $conflict = BildirimPuantajEtkiConflictResolutionService::resolve(
        $pdo,
        $lockedAday,
        $lockedPuantaj,
        'INCELEME_GEREKLI',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $gerekce,
        55,
        $hash,
        5
    );
    $pdo->rollBack();

    assertConflict(($conflict['status'] ?? '') === 'conflict', 'different karar conflicts');
    assertConflict(($conflict['code'] ?? '') === 'REVISION_DECISION_CONFLICT', 'different karar returns REVISION_DECISION_CONFLICT');

    $pdo->exec('DELETE FROM bildirim_puantaj_etki_cakisma_cozumleri');
    $pdo->exec("UPDATE onayli_bildirim_puantaj_etki_adaylari SET state = 'INCELEME_GEREKLI', uygulama_modu = 'OTOMATIK', uygulanan_puantaj_id = NULL, karar_gerekcesi = NULL");
    $pdo->exec("UPDATE gunluk_puantaj SET dayanak = 'Yillik_Izin', hareket_durumu = 'Gelmedi'");

    $pdo->beginTransaction();
    $lockedAday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $lockedPuantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $protected = BildirimPuantajEtkiConflictResolutionService::resolve(
        $pdo,
        $lockedAday,
        $lockedPuantaj,
        'INCELEME_GEREKLI',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $gerekce,
        55,
        BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($lockedPuantaj),
        5
    );
    $pdo->rollBack();

    assertConflict(($protected['code'] ?? '') === 'PUANTAJ_SOURCE_PROTECTED', 'resmi surec revise blocked');

    $pdo->exec("UPDATE gunluk_puantaj SET dayanak = 'Yok_Izinsiz', hareket_durumu = 'Geldi', kaynak = 'MANUEL', state = 'ACIK', muhur_id = NULL, updated_at = '2026-06-10 08:00:00'");
    $pdo->exec("UPDATE onayli_bildirim_puantaj_etki_adaylari SET state = 'INCELEME_GEREKLI'");

    $pdo->beginTransaction();
    $lockedAday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $lockedPuantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $reviseHash = BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($lockedPuantaj);
    $revise = BildirimPuantajEtkiConflictResolutionService::resolve(
        $pdo,
        $lockedAday,
        $lockedPuantaj,
        'INCELEME_GEREKLI',
        'ADAY_ETKISIYLE_REVIZE_ET',
        'Aday etkisiyle mevcut puantaj kaydi kontrollu revize edildi.',
        55,
        $reviseHash,
        5
    );
    $pdo->commit();

    assertConflict(($revise['status'] ?? '') === 'success', 'revise decision succeeds');
    assertConflict(($revise['aday']['state'] ?? '') === 'UYGULANDI', 'revise sets UYGULANDI');
    assertConflict((int) ($revise['aday']['uygulanan_puantaj_id'] ?? 0) === 55, 'revise keeps same puantaj id');
    $after = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    assertConflict(($after['hareket_durumu'] ?? '') === 'Gelmedi', 'revise updates hareket_durumu');
    assertConflict((int) ($after['id'] ?? 0) === 55, 'revise does not create new puantaj row');
} finally {
    @unlink($path);
}

echo 'verify-bildirim-puantaj-etki-conflict-resolution: OK' . PHP_EOL;
