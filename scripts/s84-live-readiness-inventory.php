<?php
/**
 * ONE-SHOT S84 read-only production readiness inventory + entry-package export.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No business-data writes. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S84_INVENTORY_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (
    strpos($tokenExpected, 'REPLACE_') === 0
    || $tokenExpected === 'UNSET_S84_INVENTORY_TOKEN'
    || $tokenProvided === ''
    || !hash_equals($tokenExpected, $tokenProvided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';

$configCandidates = [
    dirname(__DIR__) . '/config.local.php',
    dirname(__DIR__) . '/src/Config/config.local.php',
];
$config = null;
foreach ($configCandidates as $path) {
    if (is_file($path)) {
        $config = require $path;
        break;
    }
}
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit;
}

$host = (string) ($config['db_host'] ?? 'localhost');
$name = (string) ($config['db_name'] ?? '');
$user = (string) ($config['db_user'] ?? '');
$pass = (string) ($config['db_password'] ?? '');
if ($name === '' || $user === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONFIG_INCOMPLETE'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAILED', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function s84_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s84_host_hint_ok(array $identity, string $configHost): bool
{
    return stripos($identity['db_host'], 'zelda.veridyen.com') !== false
        || stripos($identity['db_host'], 'zelda') !== false
        || stripos($configHost, 'zelda.veridyen.com') !== false
        || stripos($configHost, 'zelda') !== false;
}

function s84_assert_production_db(PDO $pdo): ?array
{
    $identity = s84_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        return $identity;
    }

    return null;
}

function s84_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s84_latest_period(PDO $pdo, int $subeId): ?array
{
    if (!s84_table_exists($pdo, 'puantaj_aylik_muhurleri')) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT yil, ay, donem FROM puantaj_aylik_muhurleri
         WHERE sube_id = :sube AND durum = 'MUHURLENDI'
         ORDER BY yil DESC, ay DESC LIMIT 1"
    );
    $stmt->execute(['sube' => $subeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function s84_csv(array $columns, array $rows): string
{
    $out = fopen('php://temp', 'r+');
    if ($out === false) {
        return '';
    }
    fputcsv($out, $columns, ';');
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $line[] = $row[$col] ?? '';
        }
        fputcsv($out, $line, ';');
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);

    return $csv;
}

function s84_legal_etiket(string $code): string
{
    static $map = [
        'ASGARI_UCRET_BRUT' => 'Asgari ücret brüt',
        'ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI' => 'Asgari ücret GV istisna matrahı',
        'ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI' => 'Asgari ücret damga vergisi istisna matrahı',
        'SGK_ISCI_PRIM_ORANI' => 'SGK işçi prim oranı',
        'ISSIZLIK_ISCI_PRIM_ORANI' => 'İşsizlik işçi prim oranı',
        'SGK_GUNLUK_TABAN' => 'SGK günlük taban',
        'SGK_GUNLUK_TAVAN' => 'SGK günlük tavan',
        'DAMGA_VERGISI_ORANI' => 'Damga vergisi oranı',
        'GELIR_VERGISI_DILIM_1_LIMIT' => 'Gelir vergisi dilim 1 limit',
        'GELIR_VERGISI_DILIM_1_ORAN' => 'Gelir vergisi dilim 1 oran',
        'GELIR_VERGISI_DILIM_2_LIMIT' => 'Gelir vergisi dilim 2 limit',
        'GELIR_VERGISI_DILIM_2_ORAN' => 'Gelir vergisi dilim 2 oran',
        'GELIR_VERGISI_DILIM_3_LIMIT' => 'Gelir vergisi dilim 3 limit',
        'GELIR_VERGISI_DILIM_3_ORAN' => 'Gelir vergisi dilim 3 oran',
        'GELIR_VERGISI_DILIM_4_LIMIT' => 'Gelir vergisi dilim 4 limit',
        'GELIR_VERGISI_DILIM_4_ORAN' => 'Gelir vergisi dilim 4 oran',
        'GELIR_VERGISI_DILIM_5_ORAN' => 'Gelir vergisi dilim 5 oran',
        'NORMAL_AY_GUN_SAYISI' => 'Normal ay gün sayısı (mevzuat)',
        'GUNLUK_CALISMA_SAATI' => 'Günlük çalışma saati (mevzuat)',
        'AYLIK_NORMAL_CALISMA_SAATI' => 'Aylık normal çalışma saati (mevzuat)',
        'HAFTALIK_IS_GUNU_SAYISI' => 'Haftalık iş günü sayısı (mevzuat)',
        'FAZLA_MESAI_CARPANI' => 'Fazla mesai çarpanı (mevzuat)',
        'FAZLA_SURELERLE_CALISMA_CARPANI' => 'Fazla sürelerle çalışma çarpanı (mevzuat)',
        'HAFTA_TATILI_CARPANI' => 'Hafta tatili çarpanı (mevzuat)',
        'UBGT_CARPANI' => 'UBGT çarpanı (mevzuat)',
        'HAFTA_TATILI_HESAP_MODU' => 'Hafta tatili hesap modu (mevzuat)',
        'UBGT_HESAP_MODU' => 'UBGT hesap modu (mevzuat)',
    ];

    return $map[$code] ?? $code;
}

if ($action === 'identity') {
    $identity = s84_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $hostOk = s84_host_hint_ok($identity, $host);
    $ok = $dbOk && $hostOk;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'inventory') {
    $bad = s84_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
    $subeId = isset($_GET['sube_id']) ? (int) $_GET['sube_id'] : 1;
    $yil = isset($_GET['yil']) ? (int) $_GET['yil'] : 0;
    $ay = isset($_GET['ay']) ? (int) $_GET['ay'] : 0;
    if ($yil <= 0 || $ay <= 0) {
        $period = s84_latest_period($pdo, $subeId);
        if ($period === null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'S84_SEALED_PERIOD_NOT_FOUND', 'sube_id' => $subeId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $yil = (int) $period['yil'];
        $ay = (int) $period['ay'];
    }

    require_once dirname(__DIR__) . '/src/bootstrap.php';

    $preflight = \Medisa\Api\Services\BordroHazirlikPreflightService::build($pdo, $subeId, $yil, $ay);
    $netMaas = \Medisa\Api\Services\BordroHazirlikPreflightService::listNetMaasEksikleri($pdo, $subeId, $yil, $ay, null);

    $legalCatalog = \Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog::all();
    $policyCatalog = \Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog::all();

    $existingLegal = [];
    if (s84_table_exists($pdo, 'mevzuat_parametreleri')) {
        $rows = $pdo->query(
            "SELECT parametre_kodu, deger_tipi, birim, gecerlilik_baslangic, gecerlilik_bitis, state
             FROM mevzuat_parametreleri WHERE state = 'AKTIF'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $code = (string) $row['parametre_kodu'];
            $existingLegal[$code][] = $row;
        }
    }

    $mevzuatPackage = [];
    foreach ($legalCatalog as $code => $meta) {
        $present = isset($existingLegal[$code]) && count($existingLegal[$code]) > 0;
        $mevzuatPackage[] = [
            'kod' => $code,
            'turkce_ad' => s84_legal_etiket($code),
            'deger_tipi' => (string) $meta['deger_tipi'],
            'birim' => (string) $meta['birim'],
            'zorunlu' => !empty($meta['zorunlu']) ? 'EVET' : 'HAYIR',
            'gecerlilik_baslangic' => '',
            'gecerlilik_bitis' => '',
            'deger' => '',
            'kaynak_aciklama' => '',
            'kaynak_referansi' => '',
            'snapshot_durumu' => $present ? 'KAYIT_VAR_DEGER_KONTROL_GEREKLI' : 'LEGAL_PARAMETER_REQUIRED_MISSING',
            'durum' => $present ? 'BUSINESS_INPUT_VERIFY' : 'BUSINESS_INPUT_REQUIRED',
            'action_link' => '/yonetim-paneli?tab=mevzuat',
        ];
    }

    $policyPackage = [];
    foreach ($policyCatalog as $code => $meta) {
        $policyPackage[] = [
            'kod' => $code,
            'turkce_ad' => (string) $meta['etiket'],
            'deger_tipi' => (string) $meta['deger_tipi'],
            'birim' => (string) $meta['birim'],
            'aciklama' => (string) $meta['aciklama'],
            'deger' => '',
            'durum' => 'BUSINESS_INPUT_REQUIRED',
            'action_link' => '/raporlar?panel=bordro-hazirlik&tab=politika',
        ];
    }

    $netRows = [];
    foreach ($netMaas['items'] as $item) {
        $netRows[] = [
            'sicil' => (string) ($item['sicil_no'] ?? ''),
            'ad_soyad' => (string) ($item['ad_soyad'] ?? ''),
            'sube' => (string) ($item['sube_adi'] ?? ''),
            'departman' => (string) ($item['departman_adi'] ?? ''),
            'gorev' => (string) ($item['gorev_adi'] ?? ''),
            'ise_giris_tarihi' => (string) ($item['ise_giris_tarihi'] ?? ''),
            'net_maas_durumu' => (string) ($item['net_maas_durumu'] ?? ''),
            'action_link' => (string) ($item['action_link'] ?? ''),
            // Explicitly omit money amounts from package for safety in logs/artifacts summary.
            'deger_girisi' => '',
            'durum' => 'BUSINESS_INPUT_REQUIRED',
        ];
    }

    $devirRows = [];
    $stmt = $pdo->prepare(
        "SELECT p.id, p.ad, p.soyad, p.sicil_no, d.ad AS departman_adi
         FROM personeller p
         LEFT JOIN departmanlar d ON d.id = p.departman_id
         WHERE p.sube_id = :sube AND p.aktif_durum = 'AKTIF'
         ORDER BY p.ad ASC, p.soyad ASC"
    );
    $stmt->execute(['sube' => $subeId]);
    $personeller = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $aktifDevir = [];
    if (s84_table_exists($pdo, 'personel_bordro_devirleri') && $ay > 1) {
        $dStmt = $pdo->prepare(
            "SELECT personel_id FROM personel_bordro_devirleri
             WHERE yil = :yil AND ay = :ay AND state = 'AKTIF'"
        );
        $dStmt->execute(['yil' => $yil, 'ay' => $ay]);
        foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $aktifDevir[(int) $row['personel_id']] = true;
        }
    }
    foreach ($personeller as $p) {
        $pid = (int) $p['id'];
        $eksik = $ay > 1 && !isset($aktifDevir[$pid]);
        if (!$eksik) {
            continue;
        }
        $devirRows[] = [
            'sicil_no' => (string) $p['sicil_no'],
            'ad_soyad' => trim((string) $p['ad'] . ' ' . (string) $p['soyad']),
            'yil' => $yil,
            'ay' => $ay,
            'onceki_kumulatif_gelir_vergisi_matrahi' => '',
            'onceki_kumulatif_gelir_vergisi' => '',
            'onceki_kumulatif_sgk_matrahi' => '',
            'aciklama' => 'EKSIK_DEVIR',
            'departman' => (string) ($p['departman_adi'] ?? ''),
            'durum' => 'BUSINESS_INPUT_REQUIRED',
        ];
    }

    $s81Domain = null;
    foreach ($preflight['readiness_domains'] ?? [] as $domain) {
        if (($domain['key'] ?? '') === 's81_final_onay') {
            $s81Domain = $domain;
            break;
        }
    }
    $s81Neden = '';
    foreach ($preflight['items'] ?? [] as $item) {
        if (($item['code'] ?? '') === 'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK') {
            $s81Neden = (string) (($item['metadata']['neden'] ?? '') ?: ($item['kullanici_mesaji'] ?? ''));
            break;
        }
    }
    $gyTableExists = s84_table_exists($pdo, 'genel_yonetici_bildirim_onaylari');
    $nedenKodu = 'ONAY_KAYDI_YOK';
    if (!$gyTableExists) {
        $nedenKodu = 'ONAY_TABLOSU_YOK';
    } elseif (strpos($s81Neden, 'ONAY_TABLOSU_YOK') !== false) {
        $nedenKodu = 'ONAY_TABLOSU_YOK_UNEXPECTED';
    }
    $s81Rows = [[
        'donem' => sprintf('%04d-%02d', $yil, $ay),
        'sube_id' => $subeId,
        'tablo_var_mi' => $gyTableExists ? 'EVET' : 'HAYIR',
        'domain_status' => (string) ($s81Domain['status'] ?? ''),
        'neden_kodu' => $nedenKodu,
        'aciklama' => (string) ($s81Domain['aciklama'] ?? $s81Neden),
        'action_link' => '/bildirimler',
        'durum' => ((string) ($s81Domain['status'] ?? '')) === 'HAZIR' ? 'TAMAM' : 'BUSINESS_INPUT_REQUIRED',
    ]];

    $blockerItems = [];
    foreach ($preflight['items'] ?? [] as $item) {
        if (($item['severity'] ?? '') !== 'BLOCKER') {
            continue;
        }
        $blockerItems[] = [
            'code' => (string) ($item['code'] ?? ''),
            'severity' => 'BLOCKER',
            'message' => (string) ($item['kullanici_mesaji'] ?? $item['message'] ?? ''),
            'etkilenen_personel_sayisi' => (string) ($item['etkilenen_personel_sayisi'] ?? ''),
            'etkilenen_kayit_sayisi' => (string) ($item['etkilenen_kayit_sayisi'] ?? ''),
            'action_link' => (string) ($item['action_link'] ?? ''),
            'record_type' => (string) ($item['record_type'] ?? ''),
            'personel_id' => (string) ($item['personel_id'] ?? ''),
        ];
    }

    $readinessOzetRows = [];
    foreach ($preflight['readiness_domains'] ?? [] as $domain) {
        $readinessOzetRows[] = [
            'domain_key' => (string) ($domain['key'] ?? ''),
            'domain_label' => (string) ($domain['label'] ?? ''),
            'status' => (string) ($domain['status'] ?? ''),
            'eksik_kayit_sayisi' => (string) ($domain['eksik_kayit_sayisi'] ?? '0'),
            'etkilenen_personel_sayisi' => (string) ($domain['etkilenen_personel_sayisi'] ?? '0'),
            'aciklama' => (string) ($domain['aciklama'] ?? ''),
            'action_link' => (string) ($domain['action_link'] ?? ''),
            'blocker_codes' => implode('|', $domain['blocker_codes'] ?? []),
            'eksik_kodlar' => implode('|', $domain['eksik_kodlar'] ?? []),
        ];
    }

    $packages = [
        '01-readiness-ozeti.csv' => s84_csv(
            ['domain_key', 'domain_label', 'status', 'eksik_kayit_sayisi', 'etkilenen_personel_sayisi', 'aciklama', 'action_link', 'blocker_codes', 'eksik_kodlar'],
            $readinessOzetRows
        ),
        '02-eksik-mevzuat-parametreleri.csv' => s84_csv(
            ['kod', 'turkce_ad', 'deger_tipi', 'birim', 'zorunlu', 'gecerlilik_baslangic', 'gecerlilik_bitis', 'deger', 'kaynak_aciklama', 'kaynak_referansi', 'snapshot_durumu', 'durum', 'action_link'],
            $mevzuatPackage
        ),
        '03-sirket-politikasi-zorunlu-kodlari.csv' => s84_csv(
            ['kod', 'turkce_ad', 'deger_tipi', 'birim', 'aciklama', 'deger', 'durum', 'action_link'],
            $policyPackage
        ),
        '04-net-maasi-eksik-personeller.csv' => s84_csv(
            ['sicil', 'ad_soyad', 'sube', 'departman', 'gorev', 'ise_giris_tarihi', 'net_maas_durumu', 'action_link', 'deger_girisi', 'durum'],
            $netRows
        ),
        '05-bordro-devir-sablonu.csv' => s84_csv(
            ['sicil_no', 'ad_soyad', 'yil', 'ay', 'onceki_kumulatif_gelir_vergisi_matrahi', 'onceki_kumulatif_gelir_vergisi', 'onceki_kumulatif_sgk_matrahi', 'aciklama', 'departman', 'durum'],
            $devirRows
        ),
        '06-s81-onay-eksikleri.csv' => s84_csv(
            ['donem', 'sube_id', 'tablo_var_mi', 'domain_status', 'neden_kodu', 'aciklama', 'action_link', 'durum'],
            $s81Rows
        ),
        '00-blocker-envanteri.csv' => s84_csv(
            ['code', 'severity', 'message', 'etkilenen_personel_sayisi', 'etkilenen_kayit_sayisi', 'action_link', 'record_type', 'personel_id'],
            $blockerItems
        ),
    ];

    $snapshot = $preflight['snapshot_preflight']['existing_snapshot'] ?? null;
    $candidateGate = $preflight['candidate_gate'] ?? null;

    echo json_encode([
        'ok' => true,
        'code' => 'S84_READINESS_INVENTORY_READONLY_OK',
        'identity' => s84_identity($pdo),
        'sube_id' => $subeId,
        'yil' => $yil,
        'ay' => $ay,
        'donem' => (string) ($preflight['donem'] ?? sprintf('%04d-%02d', $yil, $ay)),
        'contract_version' => (string) ($preflight['contract_version'] ?? ''),
        'hesaplanabilir_mi' => (bool) ($preflight['hesaplanabilir_mi'] ?? false),
        'blocker_count' => (int) ($preflight['blocker_count'] ?? 0),
        'warning_count' => (int) ($preflight['warning_count'] ?? 0),
        'info_count' => (int) ($preflight['info_count'] ?? 0),
        'snapshot' => [
            'id' => $snapshot['id'] ?? null,
            'snapshot_hash' => $snapshot['snapshot_hash'] ?? ($preflight['snapshot_preflight']['preflight_hash'] ?? null),
            'olusturulabilir_mi' => (bool) ($preflight['snapshot_preflight']['snapshot_olusturulabilir_mi'] ?? false),
        ],
        'policy_summary' => $preflight['policy_summary'] ?? null,
        'candidate_gate' => $candidateGate,
        'readiness_domains' => $preflight['readiness_domains'] ?? [],
        'blocker_items' => $blockerItems,
        'counts' => [
            'net_maas_eksik' => (int) ($netMaas['total'] ?? count($netRows)),
            'devir_eksik' => count($devirRows),
            'mevzuat_katalog' => count($mevzuatPackage),
            'politika_katalog' => count($policyPackage),
            's81_table_exists' => $gyTableExists,
        ],
        'packages' => $packages,
        'business_values_status' => 'BUSINESS_VALUES_AWAITING_AUTHORIZED_ENTRY',
        'candidate_decision' => 'BLOCKED_BUSINESS_DATA_PENDING',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S84_INVENTORY_EXCEPTION',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
