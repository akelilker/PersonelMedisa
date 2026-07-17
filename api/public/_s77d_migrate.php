<?php
/**
 * TEMPORARY S77-D schema migrate endpoint. Remove after live migrate.
 * Requires GENEL_YONETICI Bearer JWT. UTF-8 without BOM.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$request = new Request();
$user = AuthMiddleware::authenticate($request, true);
if (($user['rol'] ?? '') !== 'GENEL_YONETICI') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'migrate';

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAILED', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function s77d_count(PDO $pdo, string $table): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

function s77d_inventory(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = $row[0];
    }
    sort($tables);
    $snap = $pdo->query('SELECT id, sube_id, yil, ay, state, revision_no, snapshot_hash, personel_sayisi, girdi_sayisi
        FROM maas_hesaplama_donem_snapshotlari WHERE id = 1')->fetch();
    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_mh%'
         ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'db' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'table_count' => count($tables),
        'tables' => $tables,
        'counts' => [
            'personeller' => s77d_count($pdo, 'personeller'),
            'maas_hesaplama_donem_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_donem_snapshotlari'),
            'maas_hesaplama_personel_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_personel_snapshotlari'),
            'maas_hesaplama_girdi_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_girdi_snapshotlari'),
            'maas_hesaplama_snapshot_auditleri' => s77d_count($pdo, 'maas_hesaplama_snapshot_auditleri'),
            'personel_bordro_devirleri' => s77d_count($pdo, 'personel_bordro_devirleri'),
            'maas_hesaplama_calistirmalari' => s77d_count($pdo, 'maas_hesaplama_calistirmalari'),
            'maas_hesaplama_adaylari' => s77d_count($pdo, 'maas_hesaplama_adaylari'),
            'maas_hesaplama_aday_kalemleri' => s77d_count($pdo, 'maas_hesaplama_aday_kalemleri'),
            'maas_hesaplama_auditleri' => s77d_count($pdo, 'maas_hesaplama_auditleri'),
            'mevzuat_parametreleri' => s77d_count($pdo, 'mevzuat_parametreleri'),
        ],
        'snapshot_1' => $snap ?: null,
        'triggers' => $triggers,
    ];
}

function s77d_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s77d_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}


if ($action === 'inventory') {
    echo json_encode(['ok' => true, 'inventory' => s77d_inventory($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $stamp = gmdate('Ymd-His');
    $backupName = '_s77d_pre_migration_' . $stamp . '.sql';
    $backupPath = sys_get_temp_dir() . '/' . $backupName;
    $payload = "-- S77-D inventory backup\n" . json_encode(s77d_inventory($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($backupPath, $payload);
    echo json_encode([
        'ok' => true,
        'backup' => [
            'method' => 'inventory_json',
            'file' => $backupName,
            'bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'path' => $backupPath,
        ],
        'inventory' => s77d_inventory($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$embeddedMigrations = [
    "022_personel_bordro_devirleri.sql" => "-- S77-D Personel bordro yasal devir girdileri (kumulatif vergi matrahi)\n-- Additive migration; hard delete yok; revision ile guncelleme.\n\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\n\nCREATE TABLE IF NOT EXISTS personel_bordro_devirleri (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  personel_id INT UNSIGNED NOT NULL,\n  sube_id INT UNSIGNED NOT NULL,\n  yil SMALLINT UNSIGNED NOT NULL,\n  ay TINYINT UNSIGNED NOT NULL,\n  onceki_kumulatif_gelir_vergisi_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  onceki_kumulatif_gelir_vergisi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  onceki_kumulatif_sgk_matrahi DECIMAL(14,2) NULL,\n  devir_kaynagi VARCHAR(80) NOT NULL DEFAULT 'MANUEL',\n  aciklama VARCHAR(500) NULL,\n  state ENUM('AKTIF', 'IPTAL') NOT NULL DEFAULT 'AKTIF',\n  revision_no INT UNSIGNED NOT NULL DEFAULT 1,\n  parent_devir_id INT UNSIGNED NULL,\n  created_by INT UNSIGNED NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_by INT UNSIGNED NULL,\n  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  aktif_devir TINYINT(1) AS (\n    CASE WHEN state = 'AKTIF' THEN 1 ELSE NULL END\n  ) STORED,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_pbd_personel_donem_revision (personel_id, yil, ay, revision_no),\n  UNIQUE KEY uq_pbd_aktif (personel_id, yil, ay, aktif_devir),\n  KEY idx_pbd_sube_donem (sube_id, yil, ay),\n  KEY idx_pbd_state (state),\n  CONSTRAINT fk_pbd_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),\n  CONSTRAINT fk_pbd_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),\n  CONSTRAINT fk_pbd_parent FOREIGN KEY (parent_devir_id) REFERENCES personel_bordro_devirleri (id),\n  CONSTRAINT fk_pbd_created_by FOREIGN KEY (created_by) REFERENCES users (id),\n  CONSTRAINT fk_pbd_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),\n  CONSTRAINT chk_pbd_yil CHECK (yil BETWEEN 2000 AND 2100),\n  CONSTRAINT chk_pbd_ay CHECK (ay BETWEEN 1 AND 12),\n  CONSTRAINT chk_pbd_matrah_nonneg CHECK (onceki_kumulatif_gelir_vergisi_matrahi >= 0),\n  CONSTRAINT chk_pbd_vergi_nonneg CHECK (onceki_kumulatif_gelir_vergisi >= 0),\n  CONSTRAINT chk_pbd_sgk_nonneg CHECK (onceki_kumulatif_sgk_matrahi IS NULL OR onceki_kumulatif_sgk_matrahi >= 0)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\nCREATE TABLE IF NOT EXISTS personel_bordro_devir_auditleri (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  personel_id INT UNSIGNED NOT NULL,\n  sube_id INT UNSIGNED NOT NULL,\n  yil SMALLINT UNSIGNED NOT NULL,\n  ay TINYINT UNSIGNED NOT NULL,\n  devir_id INT UNSIGNED NULL,\n  aksiyon ENUM('CREATE', 'REVISION', 'CANCEL') NOT NULL,\n  onceki_snapshot JSON NULL,\n  sonraki_snapshot JSON NULL,\n  actor_id INT UNSIGNED NULL,\n  actor_rol VARCHAR(40) NULL,\n  request_hash CHAR(64) NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_pbda_idempotency (personel_id, yil, ay, aksiyon, request_hash),\n  KEY idx_pbda_devir (devir_id),\n  KEY idx_pbda_sube_donem (sube_id, yil, ay, created_at),\n  CONSTRAINT fk_pbda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),\n  CONSTRAINT fk_pbda_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),\n  CONSTRAINT fk_pbda_devir FOREIGN KEY (devir_id) REFERENCES personel_bordro_devirleri (id) ON DELETE SET NULL,\n  CONSTRAINT fk_pbda_actor FOREIGN KEY (actor_id) REFERENCES users (id),\n  CONSTRAINT chk_pbda_yil CHECK (yil BETWEEN 2000 AND 2100),\n  CONSTRAINT chk_pbda_ay CHECK (ay BETWEEN 1 AND 12)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n",
    "023_maas_hesaplama_adaylari.sql" => "-- S77-D Maas hesaplama calistirma, aday, kalem ve audit tablolari\n-- Additive; hesap sonucu overwrite edilmez; revision yeni satir uretir.\n\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\n\nCREATE TABLE IF NOT EXISTS maas_hesaplama_calistirmalari (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  snapshot_id INT UNSIGNED NOT NULL,\n  sube_id INT UNSIGNED NOT NULL,\n  yil SMALLINT UNSIGNED NOT NULL,\n  ay TINYINT UNSIGNED NOT NULL,\n  revision_no INT UNSIGNED NOT NULL DEFAULT 1,\n  parent_calistirma_id INT UNSIGNED NULL,\n  state ENUM('HESAPLANDI', 'KISMI_HATA', 'IPTAL') NOT NULL DEFAULT 'HESAPLANDI',\n  engine_version VARCHAR(48) NOT NULL,\n  contract_version VARCHAR(48) NOT NULL DEFAULT 'S77D_PAYROLL_CANDIDATE_V1',\n  snapshot_hash CHAR(64) NOT NULL,\n  parameter_set_hash CHAR(64) NOT NULL,\n  carryover_set_hash CHAR(64) NOT NULL,\n  request_hash CHAR(64) NOT NULL,\n  source_hash CHAR(64) NOT NULL,\n  result_hash CHAR(64) NOT NULL,\n  calculation_input_hash CHAR(64) NOT NULL,\n  personel_sayisi INT UNSIGNED NOT NULL DEFAULT 0,\n  basarili_aday_sayisi INT UNSIGNED NOT NULL DEFAULT 0,\n  hatali_aday_sayisi INT UNSIGNED NOT NULL DEFAULT 0,\n  blocker_count INT UNSIGNED NOT NULL DEFAULT 0,\n  warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n  created_by INT UNSIGNED NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  iptal_edildi_by INT UNSIGNED NULL,\n  iptal_edildi_at TIMESTAMP NULL,\n  iptal_nedeni VARCHAR(500) NULL,\n  aktif_calistirma TINYINT(1) AS (\n    CASE WHEN state = 'HESAPLANDI' THEN 1 ELSE NULL END\n  ) STORED,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_mhc_snapshot_revision (snapshot_id, revision_no),\n  UNIQUE KEY uq_mhc_aktif (snapshot_id, aktif_calistirma),\n  UNIQUE KEY uq_mhc_source_hash_aktif (snapshot_id, source_hash, aktif_calistirma),\n  KEY idx_mhc_sube_donem (sube_id, yil, ay),\n  KEY idx_mhc_input_hash (calculation_input_hash),\n  KEY idx_mhc_result_hash (result_hash),\n  CONSTRAINT fk_mhc_snapshot FOREIGN KEY (snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),\n  CONSTRAINT fk_mhc_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),\n  CONSTRAINT fk_mhc_parent FOREIGN KEY (parent_calistirma_id) REFERENCES maas_hesaplama_calistirmalari (id),\n  CONSTRAINT fk_mhc_created_by FOREIGN KEY (created_by) REFERENCES users (id),\n  CONSTRAINT fk_mhc_iptal_by FOREIGN KEY (iptal_edildi_by) REFERENCES users (id),\n  CONSTRAINT chk_mhc_yil CHECK (yil BETWEEN 2000 AND 2100),\n  CONSTRAINT chk_mhc_ay CHECK (ay BETWEEN 1 AND 12)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\nCREATE TABLE IF NOT EXISTS maas_hesaplama_adaylari (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  calistirma_id INT UNSIGNED NOT NULL,\n  personel_snapshot_id INT UNSIGNED NOT NULL,\n  personel_id INT UNSIGNED NOT NULL,\n  revision_no INT UNSIGNED NOT NULL DEFAULT 1,\n  state ENUM('HESAPLANDI', 'HESAP_HATASI', 'IPTAL') NOT NULL DEFAULT 'HESAPLANDI',\n  ucret_turu ENUM('BRUT', 'NET') NOT NULL,\n  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',\n  hedef_net_tutar DECIMAL(14,2) NULL,\n  sozlesme_brut_tutar DECIMAL(14,2) NULL,\n  hesaplanan_brut_tutar DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  sgk_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  gelir_vergisi_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  damga_vergisi_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  sgk_isci_primi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  issizlik_isci_primi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  gelir_vergisi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  damga_vergisi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  toplam_ek_odeme DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  toplam_kesinti DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  net_odenecek DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  sonraki_kumulatif_vergi_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  input_hash CHAR(64) NOT NULL,\n  result_hash CHAR(64) NOT NULL,\n  engine_version VARCHAR(48) NOT NULL,\n  carryover_json JSON NULL,\n  solver_json JSON NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_mha_calistirma_personel (calistirma_id, personel_id),\n  KEY idx_mha_personel_snapshot (personel_snapshot_id),\n  KEY idx_mha_personel (personel_id),\n  KEY idx_mha_result_hash (result_hash),\n  CONSTRAINT fk_mha_calistirma FOREIGN KEY (calistirma_id) REFERENCES maas_hesaplama_calistirmalari (id),\n  CONSTRAINT fk_mha_personel_snapshot FOREIGN KEY (personel_snapshot_id) REFERENCES maas_hesaplama_personel_snapshotlari (id),\n  CONSTRAINT fk_mha_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\nCREATE TABLE IF NOT EXISTS maas_hesaplama_aday_kalemleri (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  aday_id INT UNSIGNED NOT NULL,\n  sira_no INT UNSIGNED NOT NULL,\n  kalem_grubu VARCHAR(32) NOT NULL,\n  kalem_kodu VARCHAR(64) NOT NULL,\n  yon ENUM('ARTI', 'EKSI', 'BILGI') NOT NULL,\n  miktar DECIMAL(18,6) NULL,\n  birim VARCHAR(16) NULL,\n  oran DECIMAL(18,6) NULL,\n  matrah DECIMAL(14,2) NULL,\n  tutar DECIMAL(14,2) NOT NULL DEFAULT 0.00,\n  kaynak_turu VARCHAR(32) NULL,\n  kaynak_id INT UNSIGNED NULL,\n  aciklama VARCHAR(500) NULL,\n  payload_json JSON NOT NULL,\n  payload_hash CHAR(64) NOT NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_mhak_aday_sira (aday_id, sira_no),\n  KEY idx_mhak_kod (kalem_kodu),\n  KEY idx_mhak_payload_hash (payload_hash),\n  CONSTRAINT fk_mhak_aday FOREIGN KEY (aday_id) REFERENCES maas_hesaplama_adaylari (id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\nCREATE TABLE IF NOT EXISTS maas_hesaplama_auditleri (\n  id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n  calistirma_id INT UNSIGNED NULL,\n  snapshot_id INT UNSIGNED NULL,\n  sube_id INT UNSIGNED NOT NULL,\n  yil SMALLINT UNSIGNED NOT NULL,\n  ay TINYINT UNSIGNED NOT NULL,\n  aksiyon ENUM(\n    'PREFLIGHT_BLOCKED',\n    'CALCULATION_CREATE',\n    'CALCULATION_IDEMPOTENT',\n    'CALCULATION_CANCEL',\n    'CALCULATION_REVISION',\n    'CALCULATION_FAILED'\n  ) NOT NULL,\n  sonuc ENUM('BLOCKED', 'CREATED', 'EXISTING', 'CANCELLED', 'CONFLICT', 'FAILED') NOT NULL,\n  actor_id INT UNSIGNED NOT NULL,\n  actor_rol VARCHAR(40) NULL,\n  request_hash CHAR(64) NOT NULL,\n  calculation_input_hash CHAR(64) NULL,\n  source_hash CHAR(64) NULL,\n  result_hash CHAR(64) NULL,\n  blocker_count INT UNSIGNED NOT NULL DEFAULT 0,\n  warning_count INT UNSIGNED NOT NULL DEFAULT 0,\n  snapshot_json JSON NOT NULL,\n  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  UNIQUE KEY uq_mhaud_idempotency (sube_id, yil, ay, aksiyon, request_hash),\n  KEY idx_mhaud_calistirma (calistirma_id),\n  KEY idx_mhaud_snapshot (snapshot_id),\n  KEY idx_mhaud_sube_donem (sube_id, yil, ay, created_at),\n  CONSTRAINT fk_mhaud_calistirma FOREIGN KEY (calistirma_id) REFERENCES maas_hesaplama_calistirmalari (id),\n  CONSTRAINT fk_mhaud_snapshot FOREIGN KEY (snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),\n  CONSTRAINT fk_mhaud_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),\n  CONSTRAINT fk_mhaud_actor FOREIGN KEY (actor_id) REFERENCES users (id),\n  CONSTRAINT chk_mhaud_yil CHECK (yil BETWEEN 2000 AND 2100),\n  CONSTRAINT chk_mhaud_ay CHECK (ay BETWEEN 1 AND 12)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n",
    "024_maas_hesaplama_aday_guvenlik_indexleri.sql" => "-- S77-D Maas hesaplama aday immutability trigger ve query indexleri\n-- Child tablolarda UPDATE/DELETE yasak; root yalniz HESAPLANDI -> IPTAL.\n\nSET NAMES utf8mb4;\nSET time_zone = '+00:00';\n\nALTER TABLE maas_hesaplama_calistirmalari\n  ADD KEY idx_mhc_engine_state (engine_version, state);\n\nALTER TABLE maas_hesaplama_adaylari\n  ADD KEY idx_mha_calistirma_state (calistirma_id, state);\n\nALTER TABLE maas_hesaplama_aday_kalemleri\n  ADD KEY idx_mhak_aday_grup (aday_id, kalem_grubu);\n\nDROP TRIGGER IF EXISTS trg_mha_no_update;\nCREATE TRIGGER trg_mha_no_update\nBEFORE UPDATE ON maas_hesaplama_adaylari\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: aday satiri guncellenemez';\n\nDROP TRIGGER IF EXISTS trg_mha_no_delete;\nCREATE TRIGGER trg_mha_no_delete\nBEFORE DELETE ON maas_hesaplama_adaylari\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: aday satiri silinemez';\n\nDROP TRIGGER IF EXISTS trg_mhak_no_update;\nCREATE TRIGGER trg_mhak_no_update\nBEFORE UPDATE ON maas_hesaplama_aday_kalemleri\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: kalem satiri guncellenemez';\n\nDROP TRIGGER IF EXISTS trg_mhak_no_delete;\nCREATE TRIGGER trg_mhak_no_delete\nBEFORE DELETE ON maas_hesaplama_aday_kalemleri\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: kalem satiri silinemez';\n\nDROP TRIGGER IF EXISTS trg_mhc_no_delete;\nCREATE TRIGGER trg_mhc_no_delete\nBEFORE DELETE ON maas_hesaplama_calistirmalari\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: calistirma satiri silinemez';\n\nDROP TRIGGER IF EXISTS trg_mhc_guarded_update;\nCREATE TRIGGER trg_mhc_guarded_update\nBEFORE UPDATE ON maas_hesaplama_calistirmalari\nFOR EACH ROW\nIF NOT (OLD.state = 'HESAPLANDI' AND NEW.state = 'IPTAL')\n   OR NOT (NEW.snapshot_id <=> OLD.snapshot_id)\n   OR NOT (NEW.sube_id <=> OLD.sube_id)\n   OR NOT (NEW.yil <=> OLD.yil)\n   OR NOT (NEW.ay <=> OLD.ay)\n   OR NOT (NEW.revision_no <=> OLD.revision_no)\n   OR NOT (NEW.parent_calistirma_id <=> OLD.parent_calistirma_id)\n   OR NOT (NEW.engine_version <=> OLD.engine_version)\n   OR NOT (NEW.contract_version <=> OLD.contract_version)\n   OR NOT (NEW.snapshot_hash <=> OLD.snapshot_hash)\n   OR NOT (NEW.parameter_set_hash <=> OLD.parameter_set_hash)\n   OR NOT (NEW.carryover_set_hash <=> OLD.carryover_set_hash)\n   OR NOT (NEW.request_hash <=> OLD.request_hash)\n   OR NOT (NEW.source_hash <=> OLD.source_hash)\n   OR NOT (NEW.result_hash <=> OLD.result_hash)\n   OR NOT (NEW.calculation_input_hash <=> OLD.calculation_input_hash)\n   OR NOT (NEW.personel_sayisi <=> OLD.personel_sayisi)\n   OR NOT (NEW.basarili_aday_sayisi <=> OLD.basarili_aday_sayisi)\n   OR NOT (NEW.hatali_aday_sayisi <=> OLD.hatali_aday_sayisi)\n   OR NOT (NEW.blocker_count <=> OLD.blocker_count)\n   OR NOT (NEW.warning_count <=> OLD.warning_count)\n   OR NOT (NEW.created_by <=> OLD.created_by)\n   OR NOT (NEW.created_at <=> OLD.created_at)\nTHEN\n  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: yalniz HESAPLANDI -> IPTAL gecisi yapilabilir';\nEND IF;\n\nDROP TRIGGER IF EXISTS trg_mhaud_no_update;\nCREATE TRIGGER trg_mhaud_no_update\nBEFORE UPDATE ON maas_hesaplama_auditleri\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: hesap audit satiri guncellenemez';\n\nDROP TRIGGER IF EXISTS trg_mhaud_no_delete;\nCREATE TRIGGER trg_mhaud_no_delete\nBEFORE DELETE ON maas_hesaplama_auditleri\nFOR EACH ROW\nSIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: hesap audit satiri silinemez';\n",
];
$applied = [];
$before = s77d_inventory($pdo);
$protected = $before['snapshot_1'];

$pdo->exec('SET NAMES utf8mb4');
$pdo->exec("SET time_zone = '+00:00'");

foreach ($embeddedMigrations as $file => $sql) {
    foreach (s77d_split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }
    $applied[] = [
        'file' => $file,
        'sha256' => hash('sha256', $sql),
        'bytes' => strlen($sql),
    ];
}

$after = s77d_inventory($pdo);
$ok =
    s77d_table_exists($pdo, 'personel_bordro_devirleri')
    && s77d_table_exists($pdo, 'maas_hesaplama_calistirmalari')
    && s77d_table_exists($pdo, 'maas_hesaplama_adaylari')
    && s77d_table_exists($pdo, 'maas_hesaplama_aday_kalemleri')
    && s77d_table_exists($pdo, 'maas_hesaplama_auditleri')
    && is_array($after['snapshot_1'])
    && is_array($protected)
    && (string) $after['snapshot_1']['snapshot_hash'] === (string) $protected['snapshot_hash']
    && (int) $after['snapshot_1']['personel_sayisi'] === (int) $protected['personel_sayisi']
    && (int) $after['snapshot_1']['girdi_sayisi'] === (int) $protected['girdi_sayisi']
    && (string) $after['snapshot_1']['state'] === 'OLUSTURULDU';

echo json_encode([
    'ok' => $ok,
    'code' => $ok ? 'S77_D_SCHEMA_FIRST_LIVE_OK' : 'S77_D_SCHEMA_FIRST_LIVE_FAILED',
    'applied' => $applied,
    'before' => $before,
    'after' => $after,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
