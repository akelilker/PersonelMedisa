<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\SureclerController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Http\ResponseCaptured;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeLinkedRaporAttachmentService;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;
use Medisa\Api\Services\SelfService\PersonelBordroOkumaService;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;
use Medisa\Api\Services\SelfService\PersonelSelfProductException;
use Medisa\Api\Services\SelfService\SelfRequestInboxNotifier;

function pr472Fail(string $msg): void
{
    fwrite(STDERR, "[FAIL] {$msg}\n");
    exit(1);
}

function pr472Pass(string $msg): void
{
    echo "[PASS] {$msg}\n";
}

function pr472Assert(bool $ok, string $msg): void
{
    if (!$ok) {
        pr472Fail($msg);
    }
    pr472Pass($msg);
}

function pr472SetConnection(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function pr472ResetAuth(?array $user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function pr472MakeRequest(string $method, string $path, array $body = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => [],
        'jsonBody' => $body,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/** @return array{status:int, body:array<string,mixed>} */
function pr472CaptureRaporCreate(PDO $pdo, array $user, array $body): array
{
    pr472SetConnection($pdo);
    pr472ResetAuth($user);
    JsonResponse::beginCapture();
    $status = 500;
    try {
        SureclerController::createSelfRapor(pr472MakeRequest('POST', '/me/rapor-talepleri', $body));
        $captured = JsonResponse::capturedResponse();
    } catch (ResponseCaptured $e) {
        $captured = JsonResponse::capturedResponse();
    } finally {
        JsonResponse::endCapture();
    }
    $captured = is_array($captured ?? null) ? $captured : [];
    if (!empty($captured['data'])) {
        $status = 201;
    } elseif (!empty($captured['errors'][0]['code'])) {
        $code = (string) $captured['errors'][0]['code'];
        $status = match ($code) {
            'PERIOD_LOCKED', 'SUREC_DATE_OVERLAP', 'ARCHIVED_PERSONEL_READ_ONLY' => 409,
            'VALIDATION_ERROR' => 422,
            'SELF_SERVICE_PERSONEL_INACTIVE', 'MOBILE_CAPABILITY_PENDING_SCOPE' => 403,
            'INTERNAL_ERROR' => 500,
            default => 422,
        };
    }

    return ['status' => $status, 'body' => $captured];
}

function pr472PdfBody(string $label = 'rapor'): array
{
    $bytes = '%PDF-1.4 ' . $label;
    return [
        'dosya_adi' => 'rapor.pdf',
        'dosya_mime' => 'application/pdf',
        'dosya_icerik_base64' => base64_encode($bytes),
    ];
}

function pr472BootstrapRaporSqlite(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, ad_soyad TEXT, rol TEXT, durum TEXT, personel_id INTEGER
    )');
    $pdo->exec('CREATE TABLE user_birimler (user_id INTEGER, birim_id INTEGER)');
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE departmanlar (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE bolumler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE birimler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE gorevler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE personel_tipleri (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY, ad TEXT, soyad TEXT, sicil_no TEXT, ise_giris_tarihi TEXT, sube_id INTEGER,
        departman_id INTEGER, bolum_id INTEGER, birim_id INTEGER, gorev_id INTEGER, personel_tipi_id INTEGER,
        aktif_durum TEXT, calisan_kapsami TEXT
    )');
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez')");
    $pdo->exec("INSERT INTO birimler (id, ad) VALUES (10, 'Montaj')");
    $pdo->exec("CREATE TABLE surecler (
        id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER, surec_turu TEXT, alt_tur TEXT,
        baslangic_tarihi TEXT, bitis_tarihi TEXT, ucretli_mi INTEGER, tam_gun_mu INTEGER,
        ilk_iki_gun_firma_oder_mi INTEGER, aciklama TEXT, state TEXT DEFAULT 'AKTIF'
    )");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, sube_id INTEGER, yil INTEGER, ay INTEGER, durum TEXT
    )");
    $pdo->exec('CREATE TABLE personel_inbox_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_user_id INTEGER, personel_id INTEGER,
        kind TEXT, title TEXT, body TEXT, payload_json TEXT, related_correction_id INTEGER,
        status TEXT DEFAULT \'ACTIVE\', popup_required INTEGER, popup_consumed_at_utc TEXT,
        reminder_of_notification_id INTEGER, created_at_utc TEXT
    )');
    $pdo->exec('CREATE TABLE personel_belge_dosya_surumleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, surec_id INTEGER, personel_id INTEGER, surum_no INTEGER,
        aktif_mi INTEGER, storage_key TEXT UNIQUE, orijinal_dosya_adi TEXT, mime_type TEXT,
        uzanti TEXT, byte_boyutu INTEGER, sha256 TEXT, yukleyen_kullanici_id INTEGER, created_at TEXT
    )');
    $pdo->exec('CREATE TABLE personel_belge_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT, surec_id INTEGER, personel_id INTEGER, belge_surum_id INTEGER,
        islem_turu TEXT, onceki_metadata_json TEXT, yeni_metadata_json TEXT, yapan_kullanici_id INTEGER,
        gerekce TEXT, dosya_sha256 TEXT, dosya_byte INTEGER, dosya_mime TEXT, created_at TEXT
    )');

    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sicil_no, ise_giris_tarihi, sube_id, birim_id, aktif_durum, calisan_kapsami)
        VALUES (5, 'Ali', 'Veli', 'S5', '2020-01-01', 1, 10, 'AKTIF', 'IC_PERSONEL')");
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sicil_no, ise_giris_tarihi, sube_id, birim_id, aktif_durum, calisan_kapsami)
        VALUES (6, 'Pasif', 'P', 'S6', '2020-01-01', 1, 10, 'PASIF', 'IC_PERSONEL')");
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sicil_no, ise_giris_tarihi, sube_id, birim_id, aktif_durum, calisan_kapsami)
        VALUES (7, 'Dis', 'Kaynak', 'S7', '2020-01-01', 1, 10, 'AKTIF', 'DIS_KAYNAK')");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id)
        VALUES (50, 'personel', 'x', 'Ali Veli', 'PERSONEL', 'AKTIF', 5)");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id)
        VALUES (51, 'pasif', 'x', 'Pasif', 'PERSONEL', 'AKTIF', 6)");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id)
        VALUES (52, 'dis', 'x', 'Dis', 'PERSONEL', 'AKTIF', 7)");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id)
        VALUES (2, 'amir', 'x', 'Birim Amir', 'BIRIM_AMIRI', 'AKTIF', NULL)");
    $pdo->exec('INSERT INTO user_birimler (user_id, birim_id) VALUES (2, 10)');
}

// --- Bordro okudum (sqlite) ---
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE personel_bordro_okumalari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        calistirma_id INTEGER NOT NULL,
        yil INTEGER NOT NULL,
        ay INTEGER NOT NULL,
        okundu_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        okundu_by_user_id INTEGER NOT NULL,
        UNIQUE(personel_id, calistirma_id)
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_calistirmalari (
        id INTEGER PRIMARY KEY, yil INTEGER, ay INTEGER, sube_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_personel_snapshotlari (
        id INTEGER PRIMARY KEY, personel_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE maas_hesaplama_adaylari (
        calistirma_id INTEGER, personel_snapshot_id INTEGER, bordro_onay_durumu TEXT
    )'
);
$pdo->exec('INSERT INTO maas_hesaplama_calistirmalari VALUES (10, 2026, 3, 1)');
$pdo->exec('INSERT INTO maas_hesaplama_personel_snapshotlari VALUES (100, 5)');
$pdo->exec("INSERT INTO maas_hesaplama_adaylari VALUES (10, 100, 'KESINLESTI')");
$pdo->exec("INSERT INTO maas_hesaplama_adaylari VALUES (10, 100, 'TASLAK')");

try {
    PersonelBordroOkumaService::acknowledge($pdo, 5, 99, 10);
    pr472Pass('bordro ack KESINLESTI row');
} catch (Throwable $e) {
    pr472Fail('bordro ack should succeed: ' . $e->getMessage());
}

$first = PersonelBordroOkumaService::acknowledge($pdo, 5, 99, 10);
$second = PersonelBordroOkumaService::acknowledge($pdo, 5, 88, 10);
pr472Assert($first['okundu_at'] === $second['okundu_at'], 'bordro idempotent okundu_at preserved');
pr472Assert((int) $first['okundu_by_user_id'] === 99, 'bordro first ack user kept');

try {
    PersonelBordroOkumaService::acknowledge($pdo, 6, 99, 10);
    pr472Fail('cross-personel bordro ack must fail');
} catch (PersonelSelfProductException $e) {
    pr472Assert($e->getErrorCode() === 'NOT_FOUND', 'cross-personel ack NOT_FOUND');
}

$countStmt = $pdo->query('SELECT COUNT(*) FROM personel_bordro_okumalari');
pr472Assert((int) $countStmt->fetchColumn() === 1, 'single bordro ack row (payroll tables untouched)');

// --- Rapor attachment validation (no file / invalid) ---
try {
    PersonelBelgeLinkedRaporAttachmentService::attachOptionalFile(
        $pdo,
        1,
        1,
        1,
        '2026-01-01',
        '2026-01-02',
        ['dosya_adi' => 'x.pdf']
    );
    pr472Fail('missing base64 must fail');
} catch (PersonelSelfProductException $e) {
    pr472Assert($e->getField() === 'dosya_adi', 'rapor attachment missing fields');
}

try {
    PersonelBelgeLinkedRaporAttachmentService::attachOptionalFile(
        $pdo,
        1,
        1,
        1,
        '2026-01-01',
        '2026-01-05',
        ['dosya_adi' => 'x.pdf', 'dosya_icerik_base64' => '!!!', 'dosya_mime' => 'application/pdf']
    );
    pr472Fail('invalid base64 must fail');
} catch (PersonelSelfProductException $e) {
    pr472Pass('rapor attachment rejects invalid base64');
}

// --- RAPOR createSelfRapor HTTP owner (sqlite capture) ---
$raporPdo = new PDO('sqlite::memory:');
$raporPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
pr472BootstrapRaporSqlite($raporPdo);
$personelUser = ['id' => 50, 'rol' => 'PERSONEL', 'username' => 'personel', 'ad_soyad' => 'Ali Veli'];

$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_pr472_belge_' . bin2hex(random_bytes(4));
mkdir($storageRoot, 0750, true);
putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $storageRoot);
$_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $storageRoot;

$ok = pr472CaptureRaporCreate($raporPdo, $personelUser, array_merge([
    'baslangic_tarihi' => '2026-09-10',
    'bitis_tarihi' => '2026-09-12',
    'aciklama' => 'hastalik',
], pr472PdfBody('ok')));
pr472Assert($ok['status'] === 201, 'RAPOR create success 201');
pr472Assert(($ok['body']['data']['surec_turu'] ?? '') === 'RAPOR', 'RAPOR surec_turu');
pr472Assert(($ok['body']['data']['alt_tur'] ?? '') === 'Raporlu_Hastalik', 'Raporlu_Hastalik alt_tur');
$surecId = (int) ($ok['body']['data']['id'] ?? 0);
pr472Assert($surecId > 0, 'RAPOR surec id');
$isKazasi = (int) $raporPdo->query("SELECT COUNT(*) FROM surecler WHERE surec_turu = 'IS_KAZASI'")->fetchColumn();
pr472Assert($isKazasi === 0, 'IS_KAZASI not created');
$belgeSurecId = (int) ($ok['body']['data']['belge_surec_id'] ?? 0);
pr472Assert($belgeSurecId > 0, 'belge surec linked');
$versionCount = (int) $raporPdo->query('SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $belgeSurecId)->fetchColumn();
pr472Assert($versionCount === 1, 'belge version row');
$auditCount = (int) $raporPdo->query("SELECT COUNT(*) FROM personel_belge_auditleri WHERE islem_turu = 'CREATED'")->fetchColumn();
pr472Assert($auditCount >= 1, 'AUDIT_CREATED row');

$invalidDate = pr472CaptureRaporCreate($raporPdo, $personelUser, [
    'baslangic_tarihi' => 'not-a-date',
    'bitis_tarihi' => '2026-09-12',
]);
pr472Assert($invalidDate['status'] === 422, 'invalid date reject');

$endBefore = pr472CaptureRaporCreate($raporPdo, $personelUser, [
    'baslangic_tarihi' => '2026-09-20',
    'bitis_tarihi' => '2026-09-15',
]);
pr472Assert($endBefore['status'] === 422, 'bitis before baslangic reject');

$raporPdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, durum) VALUES (1, 2026, 8, 'MUHURLENDI')");
$locked = pr472CaptureRaporCreate($raporPdo, $personelUser, [
    'baslangic_tarihi' => '2026-08-05',
    'bitis_tarihi' => '2026-08-06',
]);
pr472Assert($locked['status'] === 409, 'period locked reject');
pr472Assert(($locked['body']['errors'][0]['code'] ?? '') === 'PERIOD_LOCKED', 'PERIOD_LOCKED code');

$raporPdo->exec("INSERT INTO surecler (personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, ucretli_mi, state)
    VALUES (5, 'RAPOR', 'Raporlu_Hastalik', '2026-09-11', '2026-09-11', 0, 'AKTIF')");
$overlap = pr472CaptureRaporCreate($raporPdo, $personelUser, [
    'baslangic_tarihi' => '2026-09-11',
    'bitis_tarihi' => '2026-09-11',
]);
pr472Assert($overlap['status'] === 409, 'overlap reject');

$pasifUser = ['id' => 51, 'rol' => 'PERSONEL', 'username' => 'pasif', 'ad_soyad' => 'Pasif'];
$inactive = pr472CaptureRaporCreate($raporPdo, $pasifUser, [
    'baslangic_tarihi' => '2026-09-25',
    'bitis_tarihi' => '2026-09-25',
]);
pr472Assert($inactive['status'] === 403, 'inactive personel reject');

$disUser = ['id' => 52, 'rol' => 'PERSONEL', 'username' => 'dis', 'ad_soyad' => 'Dis'];
$disDenied = pr472CaptureRaporCreate($raporPdo, $disUser, [
    'baslangic_tarihi' => '2026-09-26',
    'bitis_tarihi' => '2026-09-26',
]);
pr472Assert($disDenied['status'] === 403, 'DIS_KAYNAK izin_write reject');

putenv('MEDISA_TEST_SELF_NOTIFY_THROW=1');
$beforeNotify = (int) $raporPdo->query('SELECT COUNT(*) FROM surecler')->fetchColumn();
$notifyFail = pr472CaptureRaporCreate($raporPdo, $personelUser, [
    'baslangic_tarihi' => '2026-10-01',
    'bitis_tarihi' => '2026-10-02',
]);
putenv('MEDISA_TEST_SELF_NOTIFY_THROW');
pr472Assert($notifyFail['status'] === 201, 'notifier exception still 201');
$afterNotify = (int) $raporPdo->query('SELECT COUNT(*) FROM surecler')->fetchColumn();
pr472Assert($afterNotify === $beforeNotify + 1, 'notifier failure does not rollback surec');

// Orphan storage cleanup on belge audit failure
$orphanPdo = new PDO('sqlite::memory:');
$orphanPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
pr472BootstrapRaporSqlite($orphanPdo);
$orphanPdo->exec('CREATE TRIGGER pr472_fail_audit BEFORE INSERT ON personel_belge_auditleri
    BEGIN SELECT RAISE(ABORT, \'PR472_FAIL_AUDIT\'); END');
$orphanRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_pr472_orphan_' . bin2hex(random_bytes(4));
mkdir($orphanRoot, 0750, true);
putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $orphanRoot);
$_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $orphanRoot;
$beforeOrphan = pr472CaptureRaporCreate($orphanPdo, $personelUser, array_merge([
    'baslangic_tarihi' => '2026-11-01',
    'bitis_tarihi' => '2026-11-02',
], pr472PdfBody('orphan')));
pr472Assert($beforeOrphan['status'] !== 201, 'audit failure blocks create');
$diskFiles = array_values(array_filter(scandir($orphanRoot) ?: [], static fn ($f) => $f !== '.' && $f !== '..'));
pr472Assert($diskFiles === [], 'orphan storage file cleaned after rollback');

// Self-request inbox dedupe
$dedupePdo = new PDO('sqlite::memory:');
$dedupePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
pr472BootstrapRaporSqlite($dedupePdo);
$ctx = ['personel_id' => 5, 'ad_soyad' => 'Ali Veli', 'birim_id' => 10];
$row = ['id' => 900, 'baslangic_tarihi' => '2026-12-01', 'bitis_tarihi' => '2026-12-02'];
SelfRequestInboxNotifier::notifyRaporRequest($dedupePdo, $ctx, $row);
SelfRequestInboxNotifier::notifyRaporRequest($dedupePdo, $ctx, $row);
$inboxCount = (int) $dedupePdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE kind = 'SELF_RAPOR_REQUEST'")->fetchColumn();
pr472Assert($inboxCount === 1, 'SELF_RAPOR_REQUEST dedupe single inbox row');

@array_map(static fn ($f) => @unlink($storageRoot . DIRECTORY_SEPARATOR . $f), glob($storageRoot . '/*') ?: []);
@rmdir($storageRoot);
@rmdir($orphanRoot);

pr472Pass('POST_PR472_SELF_SERVICE_BEHAVIOR=OK');
