<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimDonemContextService.php';
require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';
require_once __DIR__ . '/../../api/src/Services/MaasHesaplamaException.php';
require_once __DIR__ . '/../../api/src/Services/PersonelBordroKapsamService.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkPrimGunuEngine.php';
require_once __DIR__ . '/../../api/src/Services/SgkPrimGunuService.php';
require_once __DIR__ . '/../../api/src/Services/MaasHesaplamaSnapshotService.php';

use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\MaasHesaplamaSnapshotService as Svc;

function snapPdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 200');

    return $pdo;
}

function createSnapshotSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT)');
    $pdo->exec('CREATE TABLE departmanlar (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE gorevler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE personel_tipleri (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY, tc_kimlik_no TEXT, ad TEXT, soyad TEXT, sicil_no TEXT,
        ise_giris_tarihi TEXT, sube_id INTEGER NOT NULL, departman_id INTEGER, gorev_id INTEGER,
        personel_tipi_id INTEGER, bagli_amir_id INTEGER, aktif_durum TEXT NOT NULL DEFAULT \'AKTIF\',
        ucret_tipi_id INTEGER, maas_tutari REAL, prim_kurali_id INTEGER
    )');
    $pdo->exec('CREATE TABLE surecler (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, surec_turu TEXT NOT NULL, alt_tur TEXT,
        baslangic_tarihi TEXT NOT NULL, bitis_tarihi TEXT, ucretli_mi INTEGER NOT NULL DEFAULT 0,
        ilk_iki_gun_firma_oder_mi INTEGER,
        aciklama TEXT, state TEXT NOT NULL DEFAULT \'AKTIF\',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE personel_ucret_gecmisi (
        id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER NOT NULL, ucret_tutari REAL NOT NULL,
        ucret_turu TEXT NOT NULL, para_birimi TEXT NOT NULL DEFAULT \'TRY\',
        gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT,
        state TEXT NOT NULL DEFAULT \'AKTIF\', kaynak TEXT NOT NULL DEFAULT \'MANUEL\',
        aciklama TEXT, revision_no INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        donem TEXT, durum TEXT NOT NULL DEFAULT \'MUHURLENDI\', muhurlenen_kayit_sayisi INTEGER DEFAULT 0,
        created_by INTEGER, created_at TEXT NOT NULL DEFAULT \'2026-04-01 09:00:00\',
        UNIQUE (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhur_satirlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, muhur_id INTEGER NOT NULL, personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL, gun_tipi TEXT, hareket_durumu TEXT, dayanak TEXT,
        durumu_bildirdi_mi INTEGER, durum_bildirim_aciklamasi TEXT, hesap_etkisi TEXT,
        beklenen_giris_saati TEXT, beklenen_cikis_saati TEXT, giris_saati TEXT, cikis_saati TEXT,
        gec_kalma_dakika INTEGER, erken_cikis_dakika INTEGER, gercek_mola_dakika INTEGER,
        hesaplanan_mola_dakika INTEGER, net_calisma_suresi_dakika INTEGER, gunluk_brut_sure_dakika INTEGER,
        hafta_tatili_hak_kazandi_mi INTEGER, kontrol_durumu TEXT NOT NULL DEFAULT \'BEKLIYOR\',
        kaynak TEXT, aciklama TEXT, created_at TEXT NOT NULL DEFAULT \'2026-04-01 09:00:00\'
    )');
    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, ay TEXT NOT NULL, personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL, bildirim_turu TEXT NOT NULL DEFAULT \'DIGER\', bildirim_alt_tur TEXT,
        etki_turu TEXT NOT NULL DEFAULT \'BILGI\', etki_miktari INTEGER, etki_birimi TEXT,
        state TEXT NOT NULL, conflict_code TEXT, source_hash TEXT, mevcut_puantaj_id INTEGER,
        updated_at TEXT NOT NULL DEFAULT \'2026-04-01 09:00:00\'
    )');
    $pdo->exec('CREATE TABLE bildirim_puantaj_etki_cakisma_cozumleri (
        id INTEGER PRIMARY KEY, aday_id INTEGER NOT NULL, conflict_class TEXT NOT NULL,
        karar_turu TEXT NOT NULL, gerekce TEXT, sonuc_hash TEXT NOT NULL,
        karar_veren_user_id INTEGER NOT NULL, karar_zamani TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE ek_odeme_kesinti (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, donem TEXT NOT NULL,
        kalem_turu TEXT NOT NULL, tutar REAL NOT NULL, gun_sayisi INTEGER, aciklama TEXT,
        state TEXT NOT NULL DEFAULT \'AKTIF\', created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT \'2026-03-20 09:00:00\',
        updated_at TEXT NOT NULL DEFAULT \'2026-03-20 09:00:00\'
    )');
    $pdo->exec('CREATE TABLE mevzuat_parametreleri (
        id INTEGER PRIMARY KEY, parametre_kodu TEXT NOT NULL, deger_tipi TEXT NOT NULL,
        sayisal_deger REAL, metin_deger TEXT, birim TEXT,
        gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT,
        kaynak_referansi TEXT, state TEXT NOT NULL DEFAULT \'AKTIF\', revision_no INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER NOT NULL, yil INTEGER NOT NULL, ay INTEGER NOT NULL,
        donem TEXT NOT NULL, donem_baslangic TEXT NOT NULL, donem_bitis TEXT NOT NULL,
        muhur_id INTEGER NOT NULL, revision_no INTEGER NOT NULL DEFAULT 1, parent_snapshot_id INTEGER,
        state TEXT NOT NULL DEFAULT \'OLUSTURULDU\', contract_version TEXT NOT NULL,
        cutoff_at TEXT NOT NULL, preflight_hash TEXT NOT NULL, source_hash TEXT NOT NULL,
        snapshot_hash TEXT NOT NULL, personel_sayisi INTEGER NOT NULL DEFAULT 0,
        girdi_sayisi INTEGER NOT NULL DEFAULT 0, blocker_count INTEGER NOT NULL DEFAULT 0,
        warning_count INTEGER NOT NULL DEFAULT 0, created_by INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        iptal_edildi_by INTEGER, iptal_edildi_at TEXT, iptal_nedeni TEXT,
        UNIQUE (sube_id, yil, ay, revision_no),
        UNIQUE (muhur_id, revision_no)
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, donem_snapshot_id INTEGER NOT NULL, personel_id INTEGER NOT NULL,
        personel_snapshot_json TEXT NOT NULL, personel_snapshot_hash TEXT NOT NULL,
        istihdam_baslangic TEXT NOT NULL, istihdam_bitis TEXT,
        ucret_segment_sayisi INTEGER NOT NULL DEFAULT 0, puantaj_kayit_sayisi INTEGER NOT NULL DEFAULT 0,
        finans_kalem_sayisi INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (donem_snapshot_id, personel_id)
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_girdi_snapshotlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, donem_snapshot_id INTEGER NOT NULL, personel_snapshot_id INTEGER,
        kaynak_turu TEXT NOT NULL, kaynak_tablo TEXT NOT NULL, kaynak_id INTEGER, kaynak_revision INTEGER,
        etki_baslangic TEXT, etki_bitis TEXT, sira_no INTEGER NOT NULL,
        payload_json TEXT NOT NULL, payload_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (donem_snapshot_id, kaynak_turu, sira_no)
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_snapshot_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, donem_snapshot_id INTEGER, sube_id INTEGER NOT NULL,
        yil INTEGER NOT NULL, ay INTEGER NOT NULL, muhur_id INTEGER,
        aksiyon TEXT NOT NULL, sonuc TEXT NOT NULL, actor_id INTEGER NOT NULL, actor_rol TEXT,
        request_hash TEXT NOT NULL, preflight_hash TEXT NOT NULL, source_hash TEXT, result_hash TEXT NOT NULL,
        blocker_count INTEGER NOT NULL DEFAULT 0, warning_count INTEGER NOT NULL DEFAULT 0,
        snapshot_json TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (sube_id, yil, ay, aksiyon, request_hash)
    )');
    $pdo->exec('CREATE TABLE sgk_eksik_gun_katalog_surumleri (
        id INTEGER PRIMARY KEY, surum_kodu TEXT NOT NULL, gecerlilik_baslangic TEXT NOT NULL,
        gecerlilik_bitis TEXT, tamlik_durumu TEXT NOT NULL, state TEXT NOT NULL,
        manifest_set_hash TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_eksik_gun_kodlari (
        id INTEGER PRIMARY KEY, katalog_surum_id INTEGER NOT NULL, eksik_gun_kodu TEXT NOT NULL,
        resmi_aciklama TEXT NOT NULL, belge_zorunlulugu TEXT NOT NULL,
        sifir_gun_sifir_kazanc_kullanilabilir_mi INTEGER NOT NULL,
        kismi_sureli_sozlesme_gerekli_mi INTEGER NOT NULL,
        tek_basina_kullanilabilir_mi INTEGER NOT NULL,
        diger_nedenlerle_birlikte_kullanim TEXT NOT NULL, aktif_mi INTEGER NOT NULL,
        gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT
    )');
    $pdo->exec('CREATE TABLE sgk_eksik_gun_kod_cakismalari (
        id INTEGER PRIMARY KEY, katalog_surum_id INTEGER NOT NULL, kaynak_kod_set_hash TEXT NOT NULL,
        sonuc_eksik_gun_kodu TEXT NOT NULL, aktif_mi INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_sirket_politika_surumleri (
        id INTEGER PRIMARY KEY, sube_id INTEGER NOT NULL, bildirim_donem_tipi TEXT NOT NULL,
        politika_hash TEXT NOT NULL, gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT,
        state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_sirket_politika_degerleri (
        id INTEGER PRIMARY KEY, politika_surum_id INTEGER NOT NULL, politika_kodu TEXT NOT NULL, deger TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_personel_sigortalilik_surumleri (
        id INTEGER PRIMARY KEY, personel_id INTEGER NOT NULL, sigortalilik_statusu TEXT NOT NULL,
        sozlesme_turu TEXT NOT NULL, bildirim_donem_tipi TEXT NOT NULL,
        gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT, state TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_surec_neden_eslemeleri (
        id INTEGER PRIMARY KEY, katalog_surum_id INTEGER NOT NULL, surec_turu TEXT NOT NULL, alt_tur TEXT,
        canonical_surec_turu TEXT NOT NULL, eksik_gun_kodu TEXT, prim_gunu_etkisi TEXT NOT NULL,
        kosullar_json TEXT, aktif_mi INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_eksik_gun_belgeleri (
        id INTEGER PRIMARY KEY, dogrulama_durumu TEXT NOT NULL, dosya_hash TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE sgk_belge_surec_baglantilari (
        id INTEGER PRIMARY KEY, belge_id INTEGER NOT NULL, surec_id INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE maas_hesaplama_sgk_snapshotlari (
        id INTEGER PRIMARY KEY AUTOINCREMENT, donem_snapshot_id INTEGER NOT NULL,
        personel_snapshot_id INTEGER NOT NULL, personel_id INTEGER NOT NULL,
        hesaplanan_prim_gunu INTEGER, eksik_gun_sayisi INTEGER, eksik_gun_kodu TEXT,
        eksik_gun_aciklamasi TEXT, kaynak_surec_idleri_json TEXT NOT NULL,
        kaynak_puantaj_idleri_json TEXT NOT NULL, kaynak_belge_idleri_json TEXT NOT NULL,
        katalog_surum_id INTEGER, katalog_surumu TEXT, kaynak_manifest_hash TEXT,
        sgk_hesap_hash TEXT NOT NULL, gunluk_karar_dokumu_hash TEXT NOT NULL,
        gunluk_karar_dokumu_json TEXT NOT NULL, manuel_inceleme_gerekli_mi INTEGER NOT NULL,
        blocker_kodlari_json TEXT NOT NULL, blocker_detaylari_json TEXT NOT NULL,
        ucret_modeli TEXT NOT NULL, ilk_iki_gun_politika_ozeti_json TEXT NOT NULL,
        sirket_politika_surum_id INTEGER, sirket_politika_hash TEXT, sgk_odenek_durumu TEXT NOT NULL,
        is_goremezlik_finans_ozeti_json TEXT NOT NULL,
        gunluk_alt_sinir REAL, gunluk_ust_sinir REAL, donem_alt_sinir REAL, donem_ust_sinir REAL,
        sinir_mevzuat_surumu TEXT, source_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (donem_snapshot_id, personel_id)
    )');
    $pdo->exec('CREATE TABLE sgk_hesap_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        donem_snapshot_id INTEGER,
        personel_id INTEGER NOT NULL,
        yil INTEGER NOT NULL,
        ay INTEGER NOT NULL,
        aksiyon TEXT NOT NULL,
        sonuc TEXT NOT NULL,
        request_hash TEXT NOT NULL,
        source_hash TEXT NOT NULL,
        result_hash TEXT NOT NULL,
        blocker_kodlari_json TEXT NOT NULL,
        actor_id INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
}

function resetSnapshotData(PDO $pdo): void
{
    foreach ([
        'sgk_hesap_auditleri', 'maas_hesaplama_sgk_snapshotlari', 'maas_hesaplama_snapshot_auditleri', 'maas_hesaplama_girdi_snapshotlari',
        'maas_hesaplama_personel_snapshotlari', 'maas_hesaplama_donem_snapshotlari',
        'puantaj_donem_kilitleri', 'mevzuat_parametreleri', 'ek_odeme_kesinti',
        'bildirim_puantaj_etki_cakisma_cozumleri', 'onayli_bildirim_puantaj_etki_adaylari',
        'puantaj_aylik_muhur_satirlari', 'puantaj_aylik_muhurleri',
        'sgk_belge_surec_baglantilari', 'sgk_eksik_gun_belgeleri', 'sgk_surec_neden_eslemeleri',
        'sgk_personel_sigortalilik_surumleri', 'sgk_sirket_politika_degerleri',
        'sgk_sirket_politika_surumleri', 'sgk_eksik_gun_kod_cakismalari',
        'sgk_eksik_gun_kodlari', 'sgk_eksik_gun_katalog_surumleri',
        'personel_ucret_gecmisi', 'surecler', 'personeller', 'subeler',
    ] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO personeller (id, tc_kimlik_no, ad, soyad, sicil_no, ise_giris_tarihi, sube_id, aktif_durum, ucret_tipi_id)
        VALUES (7, '11111111111', 'Ali', 'Yilmaz', 'S007', '2020-01-01', 1, 'AKTIF', 1),
               (8, '22222222222', 'Ayse', 'Demir', 'S008', '2020-01-01', 1, 'AKTIF', 1)");
    $manifestHash = str_repeat('a', 64);
    $policyHash = str_repeat('b', 64);
    $pdo->exec("INSERT INTO sgk_eksik_gun_katalog_surumleri
        (id, surum_kodu, gecerlilik_baslangic, tamlik_durumu, state, manifest_set_hash)
        VALUES (1, 'TEST-SGK-V1', '2026-01-01', 'DOGRULANMIS_TAM', 'ONAYLANDI', '$manifestHash')");
    $pdo->exec("INSERT INTO sgk_sirket_politika_surumleri
        (id, sube_id, bildirim_donem_tipi, politika_hash, gecerlilik_baslangic, state)
        VALUES (1, 1, 'AY_1_SON_GUN', '$policyHash', '2026-01-01', 'ONAYLANDI')");
    $pdo->exec("INSERT INTO sgk_personel_sigortalilik_surumleri
        (id, personel_id, sigortalilik_statusu, sozlesme_turu, bildirim_donem_tipi, gecerlilik_baslangic, state)
        VALUES (1, 7, '4A', 'TAM_SURELI', 'SIRKET_POLITIKASINDAN', '2026-01-01', 'ONAYLANDI'),
               (2, 8, '4A', 'TAM_SURELI', 'SIRKET_POLITIKASINDAN', '2026-01-01', 'ONAYLANDI')");
    $pdo->exec("INSERT INTO sgk_surec_neden_eslemeleri
        (id, katalog_surum_id, surec_turu, alt_tur, canonical_surec_turu, prim_gunu_etkisi, aktif_mi)
        VALUES (1, 1, 'IZIN', NULL, 'YILLIK_IZIN', 'DAHIL', 1)");
    $pdo->exec("INSERT INTO mevzuat_parametreleri
        (id, parametre_kodu, deger_tipi, sayisal_deger, birim, gecerlilik_baslangic)
        VALUES (901, 'SGK_GUNLUK_TABAN', 'SAYISAL', 100, 'TRY', '2026-01-01'),
               (902, 'SGK_GUNLUK_TAVAN', 'SAYISAL', 750, 'TRY', '2026-01-01')");
}

function sealPeriod(PDO $pdo, int $subeId = 1, int $yil = 2026, int $ay = 3, int $rowCount = 2): int
{
    $donem = sprintf('%04d-%02d', $yil, $ay);
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $ay, $yil);
    $actualRowCount = $daysInMonth * 2;
    $pdo->prepare('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
        VALUES (:s, :y, :a, :d, \'MUHURLENDI\', :c, 99)')
        ->execute(['s' => $subeId, 'y' => $yil, 'a' => $ay, 'd' => $donem, 'c' => $actualRowCount]);
    $muhurId = (int) $pdo->lastInsertId();
    foreach ([7, 8] as $personelId) {
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $tarih = sprintf('%s-%02d', $donem, $day);
            $pdo->prepare('INSERT INTO puantaj_aylik_muhur_satirlari
                (muhur_id, personel_id, tarih, gun_tipi, kontrol_durumu, kaynak, net_calisma_suresi_dakika)
                VALUES (:m, :p, :t, \'NORMAL\', \'AMIR_KONTROL_ETTI\', \'SISTEM\', 480)')
                ->execute(['m' => $muhurId, 'p' => $personelId, 't' => $tarih]);
        }
    }

    return $muhurId;
}

function seedFullSalaries(PDO $pdo): void
{
    $pdo->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state)
        VALUES (7, 30000, 'NET', '2025-01-01', NULL, 'AKTIF'),
               (8, 28000, 'NET', '2025-01-01', NULL, 'AKTIF')");
}

/** @return array<string, mixed> */
function issueByCode(array $preflight, string $code): ?array
{
    foreach ($preflight['items'] as $item) {
        if ($item['code'] === $code) {
            return $item;
        }
    }

    return null;
}

function snapAssert(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$actor = ['id' => 99, 'rol' => 'MUHASEBE'];
$path = tempnam(sys_get_temp_dir(), 'medisa-mhs-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}

try {
    $pdo = snapPdo($path);
    createSnapshotSchema($pdo);

    // --- Unit: canonical JSON / hash determinism ---
    $a = ['b' => 2, 'a' => ['y' => null, 'x' => 'ü'], 'list' => [3, 1, 2]];
    $b = ['list' => [3, 1, 2], 'a' => ['x' => 'ü', 'y' => null], 'b' => 2];
    snapAssert(Svc::hashCanonical($a) === Svc::hashCanonical($b), 'canonical hash key sirasi bagimsiz');
    snapAssert(Svc::hashCanonical([1, 2]) !== Svc::hashCanonical([2, 1]), 'liste sirasi hash degistirir');
    snapAssert(Svc::hashCanonical(['x' => null]) !== Svc::hashCanonical(['x' => '']), 'null ve bos deger ayrimi korunur');
    snapAssert(strlen(Svc::hashCanonical($a)) === 64, 'hash SHA-256 uzunlugunda');

    // --- Unit: ucret coverage/overlap ---
    $full = Svc::checkSalaryCoverage([
        ['id' => 1, 'gecerlilik_baslangic' => '2026-03-01', 'gecerlilik_bitis' => '2026-03-15'],
        ['id' => 2, 'gecerlilik_baslangic' => '2026-03-16', 'gecerlilik_bitis' => null],
    ], '2026-03-01', '2026-03-31');
    snapAssert(count($full['gaps']) === 0 && count($full['overlaps']) === 0, 'mid-month segment tam coverage');

    $gap = Svc::checkSalaryCoverage([
        ['id' => 1, 'gecerlilik_baslangic' => '2026-03-05', 'gecerlilik_bitis' => null],
    ], '2026-03-01', '2026-03-31');
    snapAssert(count($gap['gaps']) === 1 && $gap['gaps'][0]['bosluk_bitis'] === '2026-03-04', 'baslangic boslugu tespit edilir');

    $overlap = Svc::checkSalaryCoverage([
        ['id' => 1, 'gecerlilik_baslangic' => '2026-03-01', 'gecerlilik_bitis' => '2026-03-20'],
        ['id' => 2, 'gecerlilik_baslangic' => '2026-03-15', 'gecerlilik_bitis' => null],
    ], '2026-03-01', '2026-03-31');
    snapAssert(count($overlap['overlaps']) === 1, 'cakisan segment tespit edilir');

    $tail = Svc::checkSalaryCoverage([
        ['id' => 1, 'gecerlilik_baslangic' => '2026-03-01', 'gecerlilik_bitis' => '2026-03-20'],
    ], '2026-03-01', '2026-03-31');
    snapAssert(count($tail['gaps']) === 1 && $tail['gaps'][0]['bosluk_baslangic'] === '2026-03-21', 'donem sonu boslugu tespit edilir');

    snapAssert(Svc::maskTc('12345678901') === '123******01', 'TC maskesi hassas veriyi gizler');

    // --- Integration: muhursuz preflight ---
    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'PERIOD_NOT_SEALED') !== null, 'muhursuz donem PERIOD_NOT_SEALED blocker');
    snapAssert($preflight['snapshot_olusturulabilir_mi'] === false, 'muhursuz donem snapshot olusturamaz');

    $blockedEx = null;
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, str_repeat('a', 64), $actor);
    } catch (MaasHesaplamaException $e) {
        $blockedEx = $e;
    }
    snapAssert($blockedEx !== null && $blockedEx->getCodeString() === 'PAYROLL_PERIOD_NOT_SEALED' && $blockedEx->getHttpStatus() === 409, 'muhursuz create 409 PAYROLL_PERIOD_NOT_SEALED');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'blocked create snapshot olusturmadi');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'PREFLIGHT_BLOCKED'")->fetchColumn() === 1, 'blocked audit olustu');
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, str_repeat('a', 64), $actor);
    } catch (MaasHesaplamaException $e) {
    }
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'PREFLIGHT_BLOCKED'")->fetchColumn() === 1, 'ayni istek duplicate blocked audit uretmedi');

    // --- Integration: salary missing / coverage gap ---
    resetSnapshotData($pdo);
    sealPeriod($pdo);
    $pdo->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state)
        VALUES (7, 30000, 'NET', '2025-01-01', NULL, 'AKTIF')");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    $missing = issueByCode($preflight, 'SALARY_MISSING');
    snapAssert($missing !== null && (int) $missing['personel_id'] === 8, 'ucreti olmayan personel SALARY_MISSING blocker');
    $createEx = null;
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, (string) $preflight['preflight_hash'], $actor);
    } catch (MaasHesaplamaException $e) {
        $createEx = $e;
    }
    snapAssert($createEx !== null && $createEx->getCodeString() === 'PAYROLL_PREFLIGHT_BLOCKED', 'salary missing create PAYROLL_PREFLIGHT_BLOCKED');

    $pdo->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state)
        VALUES (8, 28000, 'NET', '2026-03-10', NULL, 'AKTIF')");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'SALARY_COVERAGE_GAP') !== null, 'gec baslayan segment SALARY_COVERAGE_GAP blocker');

    // --- Integration: legacy fallback + mid-month change ---
    resetSnapshotData($pdo);
    sealPeriod($pdo);
    $pdo->exec("UPDATE personeller SET maas_tutari = 26000 WHERE id = 8");
    $pdo->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state)
        VALUES (7, 30000, 'NET', '2025-01-01', '2026-03-15', 'AKTIF'),
               (7, 32000, 'NET', '2026-03-16', NULL, 'AKTIF')");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert((int) $preflight['blocker_count'] === 0, 'mid-month degisiklik + legacy fallback blocker uretmez');
    $legacy = issueByCode($preflight, 'LEGACY_SALARY_FALLBACK_USED');
    snapAssert($legacy !== null && (int) $legacy['personel_id'] === 8, 'legacy fallback warning uretildi');
    $midMonthSegments = null;
    foreach ($preflight['personel_summary'] as $summaryRow) {
        if ((int) $summaryRow['personel_id'] === 7) {
            $midMonthSegments = (int) $summaryRow['ucret_segment_sayisi'];
        }
    }
    snapAssert($midMonthSegments === 2, 'mid-month iki segment cozumlendi');
    snapAssert(issueByCode($preflight, 'LEGAL_PARAMETER_SET_EMPTY') === null, 'SGK PEK mevzuat seti owner fixture ile dolu');

    // --- Integration: puantaj count mismatch + unresolved candidate ---
    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    $muhurId = sealPeriod($pdo);
    $pdo->exec("UPDATE puantaj_aylik_muhurleri SET muhurlenen_kayit_sayisi = 5 WHERE id = $muhurId");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'PUANTAJ_SOURCE_INCONSISTENT') !== null, 'muhur satir sayisi uyusmazligi blocker');

    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    sealPeriod($pdo);
    $pdo->exec("INSERT INTO onayli_bildirim_puantaj_etki_adaylari (id, sube_id, ay, personel_id, tarih, state)
        VALUES (61, 1, '2026-03', 7, '2026-03-04', 'HAZIR')");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'UNRESOLVED_IMPACT_CANDIDATE') !== null, 'HAZIR aday blocker uretti');
    $pdo->exec("UPDATE onayli_bildirim_puantaj_etki_adaylari SET state = 'INCELEME_GEREKLI', conflict_code = 'X' WHERE id = 61");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'FINANCE_CONFLICT_UNRESOLVED') !== null, 'conflict kodlu aday FINANCE_CONFLICT_UNRESOLVED blocker');

    // --- Integration: basarili snapshot + idempotency + stale + source changed ---
    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    $muhurId = sealPeriod($pdo);
    $pdo->exec("UPDATE onayli_bildirim_puantaj_etki_adaylari SET state = 'UYGULANDI' WHERE 1=0");
    $pdo->exec("INSERT INTO onayli_bildirim_puantaj_etki_adaylari (id, sube_id, ay, personel_id, tarih, state, etki_turu)
        VALUES (62, 1, '2026-03', 7, '2026-03-04', 'UYGULANDI', 'GEC_KALDI_DAKIKA'),
               (63, 1, '2026-03', 8, '2026-03-05', 'YOK_SAYILDI', 'BILGI')");
    $pdo->exec("INSERT INTO bildirim_puantaj_etki_cakisma_cozumleri
        (id, aday_id, conflict_class, karar_turu, gerekce, sonuc_hash, karar_veren_user_id, karar_zamani)
        VALUES (5, 63, 'DIGER', 'YOK_SAY', 'test', '" . str_repeat('e', 64) . "', 99, '2026-03-30 10:00:00')");
    $pdo->exec("INSERT INTO ek_odeme_kesinti (id, personel_id, donem, kalem_turu, tutar, created_at, updated_at)
        VALUES (31, 7, '2026-03', 'PRIM', 1500, '2026-03-20 09:00:00', '2026-03-20 09:00:00')");
    $pdo->exec("INSERT INTO mevzuat_parametreleri (id, parametre_kodu, deger_tipi, sayisal_deger, gecerlilik_baslangic)
        VALUES (11, 'TEST_PARAM', 'SAYISAL', 1.5, '2026-01-01')");
    $pdo->exec("INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, ucretli_mi)
        VALUES (7, 'IZIN', '2026-03-10', '2026-03-12', 1)");

    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert((int) $preflight['blocker_count'] === 0, 'temiz fixture blocker icermez');
    snapAssert($preflight['snapshot_olusturulabilir_mi'] === true, 'temiz fixture snapshot olusturulabilir');

    $staleEx = null;
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, str_repeat('0', 64), $actor);
    } catch (MaasHesaplamaException $e) {
        $staleEx = $e;
    }
    snapAssert($staleEx !== null && $staleEx->getCodeString() === 'PAYROLL_PREFLIGHT_STALE' && $staleEx->getHttpStatus() === 409, 'stale preflight hash 409 PAYROLL_PREFLIGHT_STALE');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'stale create snapshot olusturmadi');

    $created = Svc::createSnapshot($pdo, 1, 2026, 3, (string) $preflight['preflight_hash'], $actor);
    snapAssert($created['idempotent'] === false, 'ilk create idempotent degil');
    $snapshotId = (int) $created['snapshot']['id'];
    snapAssert((int) $created['snapshot']['personel_sayisi'] === 2, 'snapshot personel sayisi dogru');
    snapAssert((int) $created['snapshot']['revision_no'] === 1, 'ilk snapshot revision 1');
    snapAssert((string) $created['snapshot']['source_hash'] === (string) $preflight['source_hash'], 'snapshot source hash preflight ile ayni');
    $girdiCount = (int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId")->fetchColumn();
    snapAssert($girdiCount === (int) $created['snapshot']['girdi_sayisi'], 'girdi sayisi tutarli');
    $turler = $pdo->query("SELECT DISTINCT kaynak_turu FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId ORDER BY kaynak_turu")->fetchAll(PDO::FETCH_COLUMN);
    snapAssert($turler === ['ETKI_ADAYI', 'FINANS', 'IZIN', 'MEVZUAT', 'MUHUR', 'PUANTAJ', 'UCRET'], 'tum kaynak turleri kopyalandi');
    $detail = Svc::getSnapshotDetail($pdo, $snapshotId);
    snapAssert($detail !== null && $detail['hash_dogrulama']['dogrulandi'] === true, 'snapshot hash yeniden hesaplanip dogrulandi');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'SNAPSHOT_CREATE' AND sonuc = 'CREATED'")->fetchColumn() === 1, 'success audit olustu');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_hesap_auditleri WHERE aksiyon = 'SNAPSHOT_CREATE' AND sonuc = 'CREATED'")->fetchColumn() === 2, 'her personel icin immutable SGK snapshot audit olustu');

    $repeat = Svc::createSnapshot($pdo, 1, 2026, 3, (string) Svc::buildPreflight($pdo, 1, 2026, 3)['preflight_hash'], $actor);
    snapAssert($repeat['idempotent'] === true && (int) $repeat['snapshot']['id'] === $snapshotId, 'ayni kaynak seti idempotent ayni snapshot');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 1, 'duplicate snapshot yok');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId")->fetchColumn() === $girdiCount, 'duplicate child row yok');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'SNAPSHOT_CREATE' AND sonuc = 'CREATED'")->fetchColumn() === 1, 'duplicate success audit yok');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_hesap_auditleri WHERE aksiyon = 'SNAPSHOT_CREATE'")->fetchColumn() === 2, 'idempotent tekrar duplicate SGK audit uretmedi');

    // Immutability: canli kaynak degisiklikleri snapshot'i etkilemez
    $hashBefore = (string) $pdo->query("SELECT snapshot_hash FROM maas_hesaplama_donem_snapshotlari WHERE id = $snapshotId")->fetchColumn();
    $payloadBefore = (string) $pdo->query("SELECT payload_json FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId AND kaynak_turu = 'UCRET' ORDER BY sira_no LIMIT 1")->fetchColumn();
    $pdo->exec("UPDATE personel_ucret_gecmisi SET ucret_tutari = 99999 WHERE personel_id = 7");
    $pdo->exec("UPDATE personeller SET ad = 'Degisti' WHERE id = 7");
    $pdo->exec("UPDATE mevzuat_parametreleri SET sayisal_deger = 9.9 WHERE id = 11");
    $pdo->exec("INSERT INTO ek_odeme_kesinti (id, personel_id, donem, kalem_turu, tutar, created_at, updated_at)
        VALUES (32, 8, '2026-03', 'KESINTI', -500, '2026-04-02 09:00:00', '2026-04-02 09:00:00')");
    $hashAfter = (string) $pdo->query("SELECT snapshot_hash FROM maas_hesaplama_donem_snapshotlari WHERE id = $snapshotId")->fetchColumn();
    $payloadAfter = (string) $pdo->query("SELECT payload_json FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId AND kaynak_turu = 'UCRET' ORDER BY sira_no LIMIT 1")->fetchColumn();
    snapAssert($hashBefore === $hashAfter && $payloadBefore === $payloadAfter, 'canli kaynak degisikligi snapshot payload/hash degistirmedi');
    $detail = Svc::getSnapshotDetail($pdo, $snapshotId);
    snapAssert($detail['hash_dogrulama']['dogrulandi'] === true, 'kaynak degisikligi sonrasi hash hala dogrulaniyor');

    // Source changed: aktif snapshot varken degisen kaynak 409
    $freshPreflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($freshPreflight, 'EXISTING_ACTIVE_SNAPSHOT_SOURCE_CHANGED') !== null, 'kaynak degisince preflight source-changed blocker uretir');
    $sourceChangedEx = null;
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, (string) $freshPreflight['preflight_hash'], $actor);
    } catch (MaasHesaplamaException $e) {
        $sourceChangedEx = $e;
    }
    snapAssert($sourceChangedEx !== null && $sourceChangedEx->getCodeString() === 'PAYROLL_SNAPSHOT_SOURCE_CHANGED', 'degisen kaynak 409 PAYROLL_SNAPSHOT_SOURCE_CHANGED');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 1, 'source changed sessiz revision uretmedi');

    // Cancel + idempotent cancel + revision
    $cancelValidationEx = null;
    try {
        Svc::cancelSnapshot($pdo, $snapshotId, '', $actor);
    } catch (MaasHesaplamaException $e) {
        $cancelValidationEx = $e;
    }
    snapAssert($cancelValidationEx !== null && $cancelValidationEx->getHttpStatus() === 400, 'neden olmadan iptal 400');

    $childHashesBefore = $pdo->query("SELECT payload_hash FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $cancelled = Svc::cancelSnapshot($pdo, $snapshotId, 'Kaynak degisti, revision gerekli', $actor);
    snapAssert($cancelled['idempotent'] === false && (string) $cancelled['snapshot']['state'] === 'IPTAL', 'snapshot iptal edildi');
    $cancelRepeat = Svc::cancelSnapshot($pdo, $snapshotId, 'Kaynak degisti, revision gerekli', $actor);
    snapAssert($cancelRepeat['idempotent'] === true, 'ayni iptal tekrari idempotent');
    $childHashesAfter = $pdo->query("SELECT payload_hash FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    snapAssert($childHashesBefore === $childHashesAfter, 'cancel sonrasi child payload degismedi');
    snapAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'SNAPSHOT_CANCEL'")->fetchColumn() === 1, 'cancel audit idempotent tek satir');

    $revPreflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert((int) $revPreflight['blocker_count'] === 0, 'iptal sonrasi preflight tekrar temiz');
    $revision = Svc::createSnapshot($pdo, 1, 2026, 3, (string) $revPreflight['preflight_hash'], $actor);
    snapAssert((int) $revision['snapshot']['revision_no'] === 2, 'revision_no artti');
    snapAssert((int) $revision['snapshot']['parent_snapshot_id'] === $snapshotId, 'parent_snapshot_id onceki snapshot');
    snapAssert((string) $revision['snapshot']['source_hash'] !== $hashBefore, 'revision yeni kaynak setini yansitir');

    // Muhur sonrasi finans kaydi warning'i (32 id'li kayit 2026-04-02'de olusturuldu)
    snapAssert(issueByCode($revPreflight, 'FINANCE_RECORD_CREATED_AFTER_SEAL') !== null, 'muhur sonrasi finans kaydi warning uretti');

    // --- Rollback: girdi insert hatasi partial snapshot birakmaz ---
    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    sealPeriod($pdo);
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    $pdo->exec('ALTER TABLE maas_hesaplama_girdi_snapshotlari RENAME TO maas_hesaplama_girdi_snapshotlari_x');
    $rollbackEx = null;
    try {
        Svc::createSnapshot($pdo, 1, 2026, 3, (string) $preflight['preflight_hash'], $actor);
    } catch (Throwable $e) {
        $rollbackEx = $e;
    }
    $pdo->exec('ALTER TABLE maas_hesaplama_girdi_snapshotlari_x RENAME TO maas_hesaplama_girdi_snapshotlari');
    snapAssert($rollbackEx !== null, 'girdi insert hatasi exception firlatti');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'rollback sonrasi root satiri yok');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_personel_snapshotlari')->fetchColumn() === 0, 'rollback sonrasi partial personel satiri yok');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_sgk_snapshotlari')->fetchColumn() === 0, 'rollback sonrasi SGK snapshot satiri yok');
    snapAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_hesap_auditleri')->fetchColumn() === 0, 'rollback sonrasi SGK audit satiri yok');

    // --- Personel kumesi: donem ici giris/cikis ---
    resetSnapshotData($pdo);
    seedFullSalaries($pdo);
    sealPeriod($pdo);
    $pdo->exec("UPDATE personeller SET ise_giris_tarihi = '2026-03-10' WHERE id = 8");
    $pdo->exec("INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, state)
        VALUES (7, 'ISTEN_AYRILMA', '2026-03-20', 'AKTIF')");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    snapAssert(issueByCode($preflight, 'PERSONNEL_ENTRY_WITHIN_PERIOD') !== null, 'donem ici giris warning');
    snapAssert(issueByCode($preflight, 'PERSONNEL_EXIT_WITHIN_PERIOD') !== null, 'donem ici cikis warning');
    $kesisim = null;
    foreach ($preflight['personel_summary'] as $summaryRow) {
        if ((int) $summaryRow['personel_id'] === 7) {
            $kesisim = $summaryRow;
        }
    }
    snapAssert($kesisim !== null && $kesisim['istihdam_bitis'] === '2026-03-20', 'cikis kesisim araligini kisaltti');

    // Pasif personel gecmis donemden dusmez
    $pdo->exec("UPDATE personeller SET aktif_durum = 'PASIF' WHERE id = 7");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 3);
    $stillThere = false;
    foreach ($preflight['personel_summary'] as $summaryRow) {
        if ((int) $summaryRow['personel_id'] === 7) {
            $stillThere = true;
        }
    }
    snapAssert($stillThere, 'pasif personel gecmis donem kumesinde kalir');

    echo 'verify-maas-hesaplama-snapshot: OK' . PHP_EOL;
} finally {
    unset($pdo);
    @unlink($path);
}
