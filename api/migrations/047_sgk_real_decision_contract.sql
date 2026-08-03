-- S98-R1: Real SGK decision contract additive schema (Approach A).
-- PUANTAJ_EKSIK_GUN dynamic reason on gunluk_puantaj + seal rows.
-- Adds tam_gun_mu on surecler for mazeret SGK day decision.
-- Rich manual SGK code override audit owner (supersede + idempotency).
-- eksik_gun_kodu already nullable since 036 â€” unchanged.
-- kosullar_json already exists since 036 â€” unchanged (app-layer enum contract).
-- Idempotent / MariaDB 10.6+ 11.4 compatible.
-- Production apply NOT in this phase (code artifact only).
-- Down: reverse ALTER ENUM to prior list; DROP new columns/table if unused.
-- 036-046 untouched.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) Canonical ENUM expansion (existing rows preserved).
ALTER TABLE sgk_surec_neden_eslemeleri
  MODIFY COLUMN canonical_surec_turu ENUM(
    'HASTALIK',
    'IS_KAZASI',
    'MESLEK_HASTALIGI',
    'ANALIK',
    'UCRETSIZ_IZIN',
    'YILLIK_IZIN',
    'MAZERET_IZNI',
    'MAZERETSIZ_DEVAMSIZLIK',
    'KISMI_SURELI_CALISMA',
    'KISMI_SURE_DEVAMSIZLIK',
    'PUANTAJ_EKSIK_GUN',
    'DIGER_MANUEL_INCELEME'
  ) NOT NULL;

-- 2) Puantaj: optional typed reason for dynamic SGK code (OLAYDAN_TURET).
-- NULL = unresolved / not applicable. No silent default.
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_puantaj'
    AND COLUMN_NAME = 'sgk_eksik_gun_neden_tipi'
);
SET @sql_add_col := IF(
  @col_exists = 0,
  'ALTER TABLE gunluk_puantaj ADD COLUMN sgk_eksik_gun_neden_tipi ENUM(
      ''ISTIRAHAT'',
      ''KISMI_ISTIHDAM'',
      ''TAM_GUN_DEVAMSIZLIK'',
      ''GENEL_UCRETSIZ_IZIN'',
      ''BILINMIYOR''
    ) NULL AFTER hesap_etkisi',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_add_col FROM @sql_add_col;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

-- 3) Seal rows mirror puantaj eksik gun reason (Approach A).
SET @seal_col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari'
    AND COLUMN_NAME = 'sgk_eksik_gun_neden_tipi'
);
SET @sql_add_seal_col := IF(
  @seal_col_exists = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD COLUMN sgk_eksik_gun_neden_tipi ENUM(
      ''ISTIRAHAT'',
      ''KISMI_ISTIHDAM'',
      ''TAM_GUN_DEVAMSIZLIK'',
      ''GENEL_UCRETSIZ_IZIN'',
      ''BILINMIYOR''
    ) NULL AFTER hesap_etkisi',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_add_seal_col FROM @sql_add_seal_col;
EXECUTE stmt_add_seal_col;
DEALLOCATE PREPARE stmt_add_seal_col;

-- 4) Mazeret tam-gun decision (NULL = unresolved; no default business value).
SET @tam_gun_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'surecler'
    AND COLUMN_NAME = 'tam_gun_mu'
);
SET @sql_add_tam_gun := IF(
  @tam_gun_exists = 0,
  'ALTER TABLE surecler ADD COLUMN tam_gun_mu TINYINT(1) NULL AFTER ucretli_mi',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_add_tam_gun FROM @sql_add_tam_gun;
EXECUTE stmt_add_tam_gun;
DEALLOCATE PREPARE stmt_add_tam_gun;

-- 5) Manual SGK code override audit (authorized + justified + documented).
CREATE TABLE IF NOT EXISTS sgk_manuel_kod_override_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM('SUREC','GUNLUK_PUANTAJ') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  onceki_eksik_gun_kodu VARCHAR(8) NULL,
  yeni_eksik_gun_kodu VARCHAR(8) NOT NULL,
  gerekce VARCHAR(1000) NOT NULL,
  belge_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  state ENUM('AKTIF','SUPERSEDED') NOT NULL DEFAULT 'AKTIF',
  supersedes_id INT UNSIGNED NULL,
  aktif_hedef_anahtari VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_mkoa_idempotency (idempotency_key),
  UNIQUE KEY uq_sgk_mkoa_aktif_hedef (aktif_hedef_anahtari),
  KEY idx_sgk_mkoa_personel_tarih (personel_id, tarih),
  KEY idx_sgk_mkoa_target (target_type, target_id),
  CONSTRAINT fk_sgk_mkoa_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_mkoa_belge FOREIGN KEY (belge_id) REFERENCES sgk_eksik_gun_belgeleri (id),
  CONSTRAINT fk_sgk_mkoa_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_mkoa_supersedes FOREIGN KEY (supersedes_id) REFERENCES sgk_manuel_kod_override_auditleri (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade path: prior 047 simple table (surec_id-only) â†’ richer schema.
SET @mkoa_target_type_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'target_type'
);
SET @sql_mkoa_target_type := IF(
  @mkoa_target_type_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN target_type ENUM(''SUREC'',''GUNLUK_PUANTAJ'') NULL AFTER id',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_target_type FROM @sql_mkoa_target_type;
EXECUTE stmt_mkoa_target_type;
DEALLOCATE PREPARE stmt_mkoa_target_type;

SET @mkoa_target_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'target_id'
);
SET @sql_mkoa_target_id := IF(
  @mkoa_target_id_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN target_id INT UNSIGNED NULL AFTER target_type',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_target_id FROM @sql_mkoa_target_id;
EXECUTE stmt_mkoa_target_id;
DEALLOCATE PREPARE stmt_mkoa_target_id;

-- Backfill legacy surec_id rows when upgrading.
SET @mkoa_surec_id_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'surec_id'
);
SET @sql_mkoa_backfill := IF(
  @mkoa_surec_id_exists = 1,
  'UPDATE sgk_manuel_kod_override_auditleri
     SET target_type = ''SUREC'', target_id = surec_id
   WHERE target_type IS NULL AND surec_id IS NOT NULL',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_backfill FROM @sql_mkoa_backfill;
EXECUTE stmt_mkoa_backfill;
DEALLOCATE PREPARE stmt_mkoa_backfill;

SET @mkoa_idempotency_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'idempotency_key'
);
SET @sql_mkoa_idempotency := IF(
  @mkoa_idempotency_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN idempotency_key VARCHAR(128) NULL AFTER actor_id',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_idempotency FROM @sql_mkoa_idempotency;
EXECUTE stmt_mkoa_idempotency;
DEALLOCATE PREPARE stmt_mkoa_idempotency;

SET @mkoa_payload_hash_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'payload_hash'
);
SET @sql_mkoa_payload_hash := IF(
  @mkoa_payload_hash_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN payload_hash CHAR(64) NULL AFTER idempotency_key',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_payload_hash FROM @sql_mkoa_payload_hash;
EXECUTE stmt_mkoa_payload_hash;
DEALLOCATE PREPARE stmt_mkoa_payload_hash;

SET @mkoa_state_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'state'
);
SET @sql_mkoa_state := IF(
  @mkoa_state_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN state ENUM(''AKTIF'',''SUPERSEDED'') NOT NULL DEFAULT ''AKTIF'' AFTER payload_hash',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_state FROM @sql_mkoa_state;
EXECUTE stmt_mkoa_state;
DEALLOCATE PREPARE stmt_mkoa_state;

SET @mkoa_supersedes_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'supersedes_id'
);
SET @sql_mkoa_supersedes := IF(
  @mkoa_supersedes_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN supersedes_id INT UNSIGNED NULL AFTER state',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_supersedes FROM @sql_mkoa_supersedes;
EXECUTE stmt_mkoa_supersedes;
DEALLOCATE PREPARE stmt_mkoa_supersedes;

SET @mkoa_aktif_key_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_manuel_kod_override_auditleri'
    AND COLUMN_NAME = 'aktif_hedef_anahtari'
);
SET @sql_mkoa_aktif_key := IF(
  @mkoa_aktif_key_exists = 0,
  'ALTER TABLE sgk_manuel_kod_override_auditleri ADD COLUMN aktif_hedef_anahtari VARCHAR(64) NULL AFTER supersedes_id',
  'SET @_s98r1_noop = 1'
);
PREPARE stmt_mkoa_aktif_key FROM @sql_mkoa_aktif_key;
EXECUTE stmt_mkoa_aktif_key;
DEALLOCATE PREPARE stmt_mkoa_aktif_key;
