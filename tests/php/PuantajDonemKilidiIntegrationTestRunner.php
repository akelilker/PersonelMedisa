<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiPuantajMapper.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiManualApplyService.php';
require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';

use Medisa\Api\Services\BildirimPuantajEtkiManualApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use Medisa\Api\Services\PuantajDonemKilidiService;

function integrationPdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 100');

    return $pdo;
}

function createIntegrationSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        UNIQUE (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER, tarih TEXT, state TEXT,
        gun_tipi TEXT, hareket_durumu TEXT, dayanak TEXT, durumu_bildirdi_mi INTEGER,
        durum_bildirim_aciklamasi TEXT, hesap_etkisi TEXT, beklenen_giris_saati TEXT,
        beklenen_cikis_saati TEXT, giris_saati TEXT, cikis_saati TEXT,
        gec_kalma_dakika INTEGER, erken_cikis_dakika INTEGER, gercek_mola_dakika INTEGER,
        hesaplanan_mola_dakika INTEGER, net_calisma_suresi_dakika INTEGER,
        gunluk_brut_sure_dakika INTEGER, hafta_tatili_hak_kazandi_mi INTEGER,
        kontrol_durumu TEXT, kaynak TEXT, aciklama TEXT, muhur_id INTEGER,
        UNIQUE (personel_id, tarih)
    )');
    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INTEGER PRIMARY KEY, personel_id INTEGER, sube_id INTEGER, tarih TEXT, state TEXT,
        etki_turu TEXT, etki_miktari INTEGER, etki_birimi TEXT, conflict_code TEXT,
        bildirim_aciklama TEXT, source_snapshot TEXT, source_hash TEXT,
        uygulama_modu TEXT, manuel_karar_turu TEXT, manuel_karar_miktari INTEGER,
        karar_veren_user_id INTEGER, karar_zamani TEXT, karar_gerekcesi TEXT,
        uygulanan_puantaj_id INTEGER, onceki_puantaj_snapshot TEXT,
        sonraki_puantaj_snapshot TEXT, uygulama_hash TEXT
    )');
}

function seedManualCandidate(PDO $pdo): array
{
    $snapshot = ['gunluk_bildirim_id' => 6, 'personel_id' => 7, 'tarih' => '2026-05-15', 'bildirim_turu' => 'DIGER'];
    $aday = [
        'id' => 4, 'personel_id' => 7, 'sube_id' => 1, 'tarih' => '2026-05-15',
        'state' => 'INCELEME_GEREKLI', 'etki_turu' => 'MANUEL_INCELEME', 'etki_miktari' => null,
        'etki_birimi' => null, 'conflict_code' => 'DIGER_MANUEL_INCELEME',
        'bildirim_aciklama' => 'Kontrollu DIGER bildirimi',
        'source_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_hash' => BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot),
        'uygulama_modu' => 'OTOMATIK', 'manuel_karar_turu' => null, 'manuel_karar_miktari' => null,
        'karar_veren_user_id' => null, 'karar_zamani' => null, 'karar_gerekcesi' => null,
        'uygulanan_puantaj_id' => null, 'onceki_puantaj_snapshot' => null,
        'sonraki_puantaj_snapshot' => null, 'uygulama_hash' => null,
    ];
    $columns = array_keys($aday);
    $stmt = $pdo->prepare('INSERT INTO onayli_bildirim_puantaj_etki_adaylari ('
        . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    $stmt->execute($aday);

    return $aday;
}

function fetchCandidate(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 4')->fetch();
}

function assertTrue(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$path = tempnam(sys_get_temp_dir(), 'medisa-period-lock-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}

try {
    $first = integrationPdo($path);
    createIntegrationSchema($first);
    seedManualCandidate($first);
    $second = integrationPdo($path);

    $first->beginTransaction();
    PuantajDonemKilidiService::acquire($first, 1, 2026, 5);
    $first->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (1, 2026, 5)");

    $blocked = false;
    try {
        $second->beginTransaction();
        PuantajDonemKilidiService::acquire($second, 1, 2026, 5);
    } catch (PDOException $e) {
        $blocked = stripos($e->getMessage(), 'locked') !== false;
        if ($second->inTransaction()) {
            $second->rollBack();
        }
    }
    assertTrue($blocked, 'seal holds shared period lock against apply');
    $first->commit();

    $second->beginTransaction();
    PuantajDonemKilidiService::acquire($second, 1, 2026, 5);
    $sealExists = (int) $second->query('SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE sube_id = 1 AND yil = 2026 AND ay = 5')->fetchColumn();
    $second->rollBack();
    assertTrue($sealExists === 1, 'waiting apply observes committed seal');

    $first->exec('DELETE FROM puantaj_aylik_muhurleri');
    $first->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($first, 1, '2026-05-15');
    $result = BildirimPuantajEtkiManualApplyService::apply(
        $first, fetchCandidate($first), 'INCELEME_GEREKLI', 'GOREVDE_CALISILMIS_GUN', null,
        'Kontrollu operasyon teyidi', 5
    );
    assertTrue(($result['status'] ?? '') === 'success', 'manual apply executes in real PDO transaction');
    $first->rollBack();
    assertTrue((int) $first->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 0
        && fetchCandidate($first)['state'] === 'INCELEME_GEREKLI', 'manual apply rollback removes puantaj and candidate mutation');

    $first->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($first, 1, '2026-05-15');
    $result = BildirimPuantajEtkiManualApplyService::apply(
        $first, fetchCandidate($first), 'INCELEME_GEREKLI', 'GOREVDE_CALISILMIS_GUN', null,
        'Kontrollu operasyon teyidi', 5
    );
    $first->commit();
    assertTrue(($result['status'] ?? '') === 'success'
        && (int) $first->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 1,
        'manual apply commits exactly one puantaj');

    $first->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($first, 1, '2026-05-15');
    $same = BildirimPuantajEtkiManualApplyService::apply(
        $first, fetchCandidate($first), 'INCELEME_GEREKLI', 'GOREVDE_CALISILMIS_GUN', null,
        'Kontrollu operasyon teyidi', 5
    );
    $first->rollBack();
    assertTrue(($same['status'] ?? '') === 'idempotent'
        && (int) $first->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 1,
        'same manual request is idempotent without duplicate puantaj');

    $first->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($first, 1, '2026-05-15');
    $conflict = BildirimPuantajEtkiManualApplyService::apply(
        $first, fetchCandidate($first), 'INCELEME_GEREKLI', 'DEVAMSIZLIK_GUN', null,
        'Farkli kontrollu karar', 5
    );
    $first->rollBack();
    assertTrue(($conflict['status'] ?? '') === 'conflict'
        && ($conflict['code'] ?? '') === 'MANUAL_DECISION_CONFLICT'
        && (int) $first->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 1,
        'different manual request conflicts without mutation');

    echo 'verify-puantaj-donem-kilidi-integration: OK' . PHP_EOL;
} finally {
    unset($first, $second);
    @unlink($path);
}
