<?php
/**
 * ONE-SHOT S84-R1 read-only production smoke-seed audit.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No business-data writes. UTF-8 without BOM.
 *
 * Classifies P-0001 / P-0002 candidates by TC + sicil + ise_giris (never name-only).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S84R1_AUDIT_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (
    strpos($tokenExpected, 'REPLACE_') === 0
    || $tokenExpected === 'UNSET_S84R1_AUDIT_TOKEN'
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

/** @var array<string, array{ad:string,soyad:string,tc:string,sicil:string,ise_giris:string,seed_id:int}> */
$SEED_PROFILES = [
    'P-0001' => [
        'ad' => 'Ayse',
        'soyad' => 'Yilmaz',
        'tc' => '11111111111',
        'sicil' => 'P-0001',
        'ise_giris' => '2020-01-15',
        'seed_id' => 1,
    ],
    'P-0002' => [
        'ad' => 'Mehmet',
        'soyad' => 'Demir',
        'tc' => '22222222222',
        'sicil' => 'P-0002',
        'ise_giris' => '2019-06-01',
        'seed_id' => 2,
    ],
];

function s84r1_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s84r1_host_hint_ok(array $identity, string $configHost): bool
{
    return stripos($identity['db_host'], 'zelda.veridyen.com') !== false
        || stripos($identity['db_host'], 'zelda') !== false
        || stripos($configHost, 'zelda.veridyen.com') !== false
        || stripos($configHost, 'zelda') !== false;
}

function s84r1_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s84r1_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function s84r1_count_by_personel(PDO $pdo, string $table, string $column, int $personelId): ?int
{
    if (!s84r1_table_exists($pdo, $table) || !s84r1_column_exists($pdo, $table, $column)) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :pid");
        $stmt->execute(['pid' => $personelId]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function s84r1_mask_tc(string $tc): array
{
    $digits = preg_replace('/\D+/', '', $tc) ?? '';
    $pattern = 'OTHER';
    if ($digits === '11111111111') {
        $pattern = 'SEED_ALL_ONES';
    } elseif ($digits === '22222222222') {
        $pattern = 'SEED_ALL_TWOS';
    } elseif ($digits !== '' && preg_match('/^(\d)\1{10}$/', $digits)) {
        $pattern = 'REPEATED_DIGIT';
    }
    $last4 = strlen($digits) >= 4 ? substr($digits, -4) : '';

    return [
        'tc_pattern' => $pattern,
        'tc_last4' => $last4,
        'tc_len' => strlen($digits),
        // Full TC never returned for non-seed patterns.
        'tc_seed_exact' => in_array($pattern, ['SEED_ALL_ONES', 'SEED_ALL_TWOS'], true) ? $digits : null,
    ];
}

/**
 * Canonical classification: TC + sicil + ise_giris. Name is corroboration only.
 *
 * @param array{ad:string,soyad:string,tc:string,sicil:string,ise_giris:string,seed_id:int} $seed
 */
function s84r1_classify(array $row, array $seed): array
{
    $tc = (string) ($row['tc_kimlik_no'] ?? '');
    $sicil = (string) ($row['sicil_no'] ?? '');
    $iseGiris = (string) ($row['ise_giris_tarihi'] ?? '');
    $ad = trim((string) ($row['ad'] ?? ''));
    $soyad = trim((string) ($row['soyad'] ?? ''));

    $tcOk = $tc === $seed['tc'];
    $sicilOk = $sicil === $seed['sicil'];
    $girisOk = $iseGiris === $seed['ise_giris'];
    $idOk = (int) ($row['id'] ?? 0) === (int) $seed['seed_id'];
    $nameOk = (mb_strtolower($ad) === mb_strtolower($seed['ad'])
            || in_array(mb_strtolower($ad), ['ayse', 'ayşe'], true))
        && (mb_strtolower($soyad) === mb_strtolower($seed['soyad']));

    $evidence = [
        'tc_match_seed' => $tcOk,
        'sicil_match_seed' => $sicilOk,
        'ise_giris_match_seed' => $girisOk,
        'id_match_seed' => $idOk,
        'name_corroboration' => $nameOk,
        'name_alone_not_used_for_real_match' => true,
    ];

    if ($tcOk && $sicilOk && $girisOk) {
        return [
            'classification' => 'DEMO_CONFIRMED',
            'reason' => 'TC+sicil+ise_giris seed template ile birebir eslesiyor',
            'evidence' => $evidence,
        ];
    }

    if ($sicilOk && !$tcOk) {
        return [
            'classification' => 'REVIEW_REQUIRED',
            'reason' => 'Sicil seed ile ayni fakat TC seed fake degil; isimle gercek eslestirme yapilmadi',
            'evidence' => $evidence,
        ];
    }

    if ($tcOk && !$sicilOk) {
        return [
            'classification' => 'REVIEW_REQUIRED',
            'reason' => 'TC seed fake ile ayni fakat sicil farkli',
            'evidence' => $evidence,
        ];
    }

    // REAL_PERSON_MATCH_CONFIRMED requires authorized external payroll file proof — not available in this audit.
    return [
        'classification' => 'REVIEW_REQUIRED',
        'reason' => 'Seed kanonik eslesmesi tam degil; gercek personel eslesmesi icin yetkili bordro/dosya kaniti yok',
        'evidence' => $evidence,
    ];
}

function s84r1_csv(array $columns, array $rows): string
{
    $out = fopen('php://temp', 'r+');
    if ($out === false) {
        return '';
    }
    fputcsv($out, $columns, ';');
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $col) {
            $val = $row[$col] ?? '';
            if (is_bool($val)) {
                $val = $val ? 'EVET' : 'HAYIR';
            } elseif (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            $line[] = $val;
        }
        fputcsv($out, $line, ';');
    }
    rewind($out);
    $csv = stream_get_contents($out) ?: '';
    fclose($out);

    return $csv;
}

if ($action === 'identity') {
    $identity = s84r1_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $hostOk = s84r1_host_hint_ok($identity, $host);
    $ok = $dbOk && $hostOk;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'audit') {
    try {
        $identity = s84r1_identity($pdo);
        if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $relationSpecs = [
            ['key' => 'personel_ucret_gecmisi', 'table' => 'personel_ucret_gecmisi', 'column' => 'personel_id'],
            ['key' => 'personel_ucret_auditleri', 'table' => 'personel_ucret_auditleri', 'column' => 'personel_id'],
            ['key' => 'gunluk_puantaj', 'table' => 'gunluk_puantaj', 'column' => 'personel_id'],
            ['key' => 'puantaj_aylik_muhur_satirlari', 'table' => 'puantaj_aylik_muhur_satirlari', 'column' => 'personel_id'],
            ['key' => 'surecler', 'table' => 'surecler', 'column' => 'personel_id'],
            ['key' => 'ek_odeme_kesinti', 'table' => 'ek_odeme_kesinti', 'column' => 'personel_id'],
            ['key' => 'aylik_ozet_satirlari', 'table' => 'aylik_ozet_satirlari', 'column' => 'personel_id'],
            ['key' => 'personel_bordro_devirleri', 'table' => 'personel_bordro_devirleri', 'column' => 'personel_id'],
            ['key' => 'personel_bordro_devir_auditleri', 'table' => 'personel_bordro_devir_auditleri', 'column' => 'personel_id'],
            ['key' => 'maas_hesaplama_personel_snapshotlari', 'table' => 'maas_hesaplama_personel_snapshotlari', 'column' => 'personel_id'],
            ['key' => 'maas_hesaplama_adaylari', 'table' => 'maas_hesaplama_adaylari', 'column' => 'personel_id'],
            ['key' => 'onayli_bildirim_puantaj_etki_adaylari', 'table' => 'onayli_bildirim_puantaj_etki_adaylari', 'column' => 'personel_id'],
            ['key' => 'fazla_calisma_odeme_tercihleri', 'table' => 'fazla_calisma_odeme_tercihleri', 'column' => 'personel_id'],
            ['key' => 'zimmetler', 'table' => 'zimmetler', 'column' => 'personel_id'],
            ['key' => 'belge_kayitlari', 'table' => 'belge_kayitlari', 'column' => 'personel_id'],
        ];

        $candidates = [];
        if (!s84r1_table_exists($pdo, 'personeller')) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'code' => 'PERSONELLER_TABLE_MISSING'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Find by seed sicil OR seed TC OR seed id OR seed name+soyad (name used only to discover candidates, not to classify REAL).
        $stmt = $pdo->query(
            "SELECT p.id, p.tc_kimlik_no, p.ad, p.soyad, p.sicil_no, p.ise_giris_tarihi, p.aktif_durum,
                    p.sube_id, p.departman_id, p.maas_tutari, p.created_at, p.updated_at,
                    s.ad AS sube_adi, d.ad AS departman_adi
             FROM personeller p
             LEFT JOIN subeler s ON s.id = p.sube_id
             LEFT JOIN departmanlar d ON d.id = p.departman_id
             WHERE p.sicil_no IN ('P-0001','P-0002')
                OR p.tc_kimlik_no IN ('11111111111','22222222222')
                OR p.id IN (1, 2)
                OR (LOWER(p.ad) IN ('ayse','ayşe') AND LOWER(p.soyad) = 'yilmaz')
                OR (LOWER(p.ad) = 'mehmet' AND LOWER(p.soyad) = 'demir')
             ORDER BY p.id ASC"
        );
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $personRows = [];
        $relationRows = [];
        $snapshotRows = [];
        $muhurRows = [];

        foreach ($found as $row) {
            $pid = (int) $row['id'];
            $sicil = (string) $row['sicil_no'];
            $seedKey = null;
            if (isset($SEED_PROFILES[$sicil])) {
                $seedKey = $sicil;
            } elseif ((string) $row['tc_kimlik_no'] === '11111111111') {
                $seedKey = 'P-0001';
            } elseif ((string) $row['tc_kimlik_no'] === '22222222222') {
                $seedKey = 'P-0002';
            } elseif ($pid === 1) {
                $seedKey = 'P-0001';
            } elseif ($pid === 2) {
                $seedKey = 'P-0002';
            }

            $seed = $seedKey !== null ? $SEED_PROFILES[$seedKey] : [
                'ad' => '',
                'soyad' => '',
                'tc' => '__none__',
                'sicil' => '__none__',
                'ise_giris' => '__none__',
                'seed_id' => -1,
            ];
            $classified = s84r1_classify($row, $seed);
            $tcMask = s84r1_mask_tc((string) $row['tc_kimlik_no']);

            $relations = [];
            foreach ($relationSpecs as $spec) {
                $cnt = s84r1_count_by_personel($pdo, $spec['table'], $spec['column'], $pid);
                $relations[$spec['key']] = $cnt;
                $relationRows[] = [
                    'personel_id' => $pid,
                    'sicil_no' => $sicil,
                    'classification' => $classified['classification'],
                    'relation' => $spec['key'],
                    'table_exists' => s84r1_table_exists($pdo, $spec['table']) ? 'EVET' : 'HAYIR',
                    'kayit_sayisi' => $cnt === null ? '' : (string) $cnt,
                ];
            }

            // Snapshot links
            $snapshotLinks = [];
            if (s84r1_table_exists($pdo, 'maas_hesaplama_personel_snapshotlari')
                && s84r1_table_exists($pdo, 'maas_hesaplama_donem_snapshotlari')
            ) {
                $sStmt = $pdo->prepare(
                    'SELECT ds.id AS snapshot_id, ds.yil, ds.ay, ds.revision_no, ds.state, ds.snapshot_hash,
                            ds.sube_id, ps.personel_id
                     FROM maas_hesaplama_personel_snapshotlari ps
                     INNER JOIN maas_hesaplama_donem_snapshotlari ds ON ds.id = ps.donem_snapshot_id
                     WHERE ps.personel_id = :pid
                     ORDER BY ds.yil DESC, ds.ay DESC, ds.revision_no DESC'
                );
                $sStmt->execute(['pid' => $pid]);
                foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $snap) {
                    $snapshotLinks[] = $snap;
                    $snapshotRows[] = [
                        'personel_id' => $pid,
                        'sicil_no' => $sicil,
                        'classification' => $classified['classification'],
                        'snapshot_id' => (string) $snap['snapshot_id'],
                        'yil' => (string) $snap['yil'],
                        'ay' => (string) $snap['ay'],
                        'revision_no' => (string) $snap['revision_no'],
                        'state' => (string) $snap['state'],
                        'snapshot_hash' => (string) $snap['snapshot_hash'],
                        'sube_id' => (string) $snap['sube_id'],
                    ];
                }
            }

            // Muhur links
            $personMuhurs = [];
            if (s84r1_table_exists($pdo, 'puantaj_aylik_muhur_satirlari')
                && s84r1_table_exists($pdo, 'puantaj_aylik_muhurleri')
            ) {
                $mStmt = $pdo->prepare(
                    'SELECT m.id AS muhur_id, m.yil, m.ay, m.donem, m.durum, m.sube_id, COUNT(*) AS satir_sayisi
                     FROM puantaj_aylik_muhur_satirlari s
                     INNER JOIN puantaj_aylik_muhurleri m ON m.id = s.muhur_id
                     WHERE s.personel_id = :pid
                     GROUP BY m.id, m.yil, m.ay, m.donem, m.durum, m.sube_id
                     ORDER BY m.yil DESC, m.ay DESC'
                );
                $mStmt->execute(['pid' => $pid]);
                foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $muhur) {
                    $personMuhurs[] = $muhur;
                    $muhurRows[] = [
                        'personel_id' => $pid,
                        'sicil_no' => $sicil,
                        'classification' => $classified['classification'],
                        'muhur_id' => (string) $muhur['muhur_id'],
                        'yil' => (string) $muhur['yil'],
                        'ay' => (string) $muhur['ay'],
                        'donem' => (string) $muhur['donem'],
                        'durum' => (string) $muhur['durum'],
                        'sube_id' => (string) $muhur['sube_id'],
                        'satir_sayisi' => (string) $muhur['satir_sayisi'],
                    ];
                }
            }

            // S81 chain: GY onaylari are by sube/ay, not personel — report period presence for sealed months
            $s81Notes = [];
            if (s84r1_table_exists($pdo, 'genel_yonetici_bildirim_onaylari')) {
                foreach ($personMuhurs as $mr) {
                    $donem = sprintf('%04d-%02d', (int) $mr['yil'], (int) $mr['ay']);
                    $gy = $pdo->prepare(
                        "SELECT COUNT(*) FROM genel_yonetici_bildirim_onaylari
                         WHERE sube_id = :sube AND ay = :ay AND state = 'TAMAMLANDI'"
                    );
                    $gy->execute(['sube' => (int) $mr['sube_id'], 'ay' => $donem]);
                    $s81Notes[] = [
                        'donem' => $donem,
                        'sube_id' => (int) $mr['sube_id'],
                        'gy_tamamlandi_count' => (int) $gy->fetchColumn(),
                    ];
                }
            }

            $personRows[] = [
                'personel_id' => $pid,
                'sicil_no' => $sicil,
                'ad_soyad' => trim((string) $row['ad'] . ' ' . (string) $row['soyad']),
                'aktif_durum' => (string) ($row['aktif_durum'] ?? ''),
                'sube_id' => (string) ($row['sube_id'] ?? ''),
                'sube_adi' => (string) ($row['sube_adi'] ?? ''),
                'departman_adi' => (string) ($row['departman_adi'] ?? ''),
                'ise_giris_tarihi' => (string) ($row['ise_giris_tarihi'] ?? ''),
                'tc_pattern' => $tcMask['tc_pattern'],
                'tc_last4' => $tcMask['tc_last4'],
                'seed_profile' => $seedKey ?? '',
                'classification' => $classified['classification'],
                'classification_reason' => $classified['reason'],
                'tc_match_seed' => $classified['evidence']['tc_match_seed'] ? 'EVET' : 'HAYIR',
                'sicil_match_seed' => $classified['evidence']['sicil_match_seed'] ? 'EVET' : 'HAYIR',
                'ise_giris_match_seed' => $classified['evidence']['ise_giris_match_seed'] ? 'EVET' : 'HAYIR',
                'id_match_seed' => $classified['evidence']['id_match_seed'] ? 'EVET' : 'HAYIR',
                'name_corroboration' => $classified['evidence']['name_corroboration'] ? 'EVET' : 'HAYIR',
                'maas_tutari_present' => ($row['maas_tutari'] !== null && (string) $row['maas_tutari'] !== '') ? 'EVET' : 'HAYIR',
                'relation_total' => array_sum(array_map(static function ($v) {
                    return is_int($v) ? $v : 0;
                }, $relations)),
                'snapshot_link_count' => count($snapshotLinks),
                's81_notes_json' => json_encode($s81Notes, JSON_UNESCAPED_UNICODE),
            ];

            $candidates[] = [
                'personel_id' => $pid,
                'sicil_no' => $sicil,
                'ad_soyad' => trim((string) $row['ad'] . ' ' . (string) $row['soyad']),
                'aktif_durum' => (string) ($row['aktif_durum'] ?? ''),
                'sube_id' => (int) ($row['sube_id'] ?? 0),
                'sube_adi' => (string) ($row['sube_adi'] ?? ''),
                'departman_adi' => (string) ($row['departman_adi'] ?? ''),
                'ise_giris_tarihi' => (string) ($row['ise_giris_tarihi'] ?? ''),
                'tc_mask' => $tcMask,
                'seed_profile' => $seedKey,
                'classification' => $classified['classification'],
                'classification_reason' => $classified['reason'],
                'evidence' => $classified['evidence'],
                'relations' => $relations,
                'snapshots' => $snapshotLinks,
                's81_period_notes' => $s81Notes,
                // Explicit: never classify REAL without authorized external file proof in this read-only pass.
                'real_person_match_confirmed' => false,
            ];
        }

        // Owner capability notes (read-only product facts)
        $ownerNotes = [
            'snapshot_cancel_owner' => 'MaasHesaplamaSnapshotService::cancelSnapshot',
            'snapshot_revision_path' => 'cancel + createSnapshot (explicit revision_no); silent overwrite yok',
            'personnel_set_owner' => 'MaasHesaplamaSnapshotService::resolvePersonnelSet',
            'personnel_set_uses_aktif_durum' => false,
            'personnel_set_uses_employment_and_sealed_lines' => true,
            'demo_exclusion_owner_exists' => false,
            'forbidden' => [
                'candidate_query_exclude_P-0001',
                'mutate_snapshot_1_personnel_set',
                'overwrite_personel_id_1_with_real_person',
                'hard_delete_referenced_demo_rows',
            ],
            'seed_source' => 'api/seeds/001_smoke_seed.example.sql',
        ];

        $packages = [
            '01-smoke-seed-personel-siniflandirma.csv' => s84r1_csv(
                [
                    'personel_id', 'sicil_no', 'ad_soyad', 'aktif_durum', 'sube_id', 'sube_adi', 'departman_adi',
                    'ise_giris_tarihi', 'tc_pattern', 'tc_last4', 'seed_profile', 'classification', 'classification_reason',
                    'tc_match_seed', 'sicil_match_seed', 'ise_giris_match_seed', 'id_match_seed', 'name_corroboration',
                    'maas_tutari_present', 'relation_total', 'snapshot_link_count', 's81_notes_json',
                ],
                $personRows
            ),
            '02-smoke-seed-iliski-sayilari.csv' => s84r1_csv(
                ['personel_id', 'sicil_no', 'classification', 'relation', 'table_exists', 'kayit_sayisi'],
                $relationRows
            ),
            '03-smoke-seed-snapshot-baglantilari.csv' => s84r1_csv(
                ['personel_id', 'sicil_no', 'classification', 'snapshot_id', 'yil', 'ay', 'revision_no', 'state', 'snapshot_hash', 'sube_id'],
                $snapshotRows
            ),
            '04-smoke-seed-muhur-baglantilari.csv' => s84r1_csv(
                ['personel_id', 'sicil_no', 'classification', 'muhur_id', 'yil', 'ay', 'donem', 'durum', 'sube_id', 'satir_sayisi'],
                $muhurRows
            ),
        ];

        $demoCount = 0;
        $reviewCount = 0;
        $realCount = 0;
        foreach ($candidates as $c) {
            if ($c['classification'] === 'DEMO_CONFIRMED') {
                $demoCount++;
            } elseif ($c['classification'] === 'REAL_PERSON_MATCH_CONFIRMED') {
                $realCount++;
            } else {
                $reviewCount++;
            }
        }

        echo json_encode([
            'ok' => true,
            'code' => 'S84R1_SMOKE_SEED_AUDIT_READONLY_OK',
            'phase' => 'S84-R1',
            's84_status_unchanged' => 'S84_REAL_PAYROLL_INPUT_PACKAGE_READY / BUSINESS_VALUES_AWAITING_AUTHORIZED_ENTRY',
            'write_performed' => false,
            'identity' => $identity,
            'candidates' => $candidates,
            'counts' => [
                'candidate_rows' => count($candidates),
                'DEMO_CONFIRMED' => $demoCount,
                'REAL_PERSON_MATCH_CONFIRMED' => $realCount,
                'REVIEW_REQUIRED' => $reviewCount,
            ],
            'owner_notes' => $ownerNotes,
            'packages' => $packages,
            'next_gate' => 'NO_PRODUCTION_WRITE_UNTIL_REAL_PERSON_PROOF',
            'real_person_selection' => null,
            'candidate_decision' => 'NOT_STARTED_READONLY_AUDIT_ONLY',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S84R1_AUDIT_EXCEPTION',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
