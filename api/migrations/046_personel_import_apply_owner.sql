-- S97-B: Personel toplu import apply owner (idempotency + scrubbed audit).
-- Additive only. Seed personel yok. Ham CSV/TC kolonu yok.
-- Bagimsiz TC ozet kolonu yok.
-- Production'da otomatik calistirilmaz. MariaDB 10.6 uyumlu.
-- Down migration yazilmaz (destructive rollback yok).
-- CLAIMED yalniz transaction-ici in-flight durumdur; commit oncesi crash rollback ile temizlenir.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key VARCHAR(128) NOT NULL,
  source_sha256 CHAR(64) NOT NULL,
  manifest_hash CHAR(64) NOT NULL,
  schema_version VARCHAR(32) NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  actor_rol VARCHAR(64) NOT NULL,
  active_sube_id INT UNSIGNED NULL,
  status ENUM('CLAIMED', 'COMPLETED', 'BASARISIZ') NOT NULL,
  toplam_satir INT UNSIGNED NOT NULL DEFAULT 0,
  gecerli_satir INT UNSIGNED NOT NULL DEFAULT 0,
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_personel_ids_json JSON NULL,
  error_code VARCHAR(80) NULL,
  started_at DATETIME(3) NOT NULL,
  finished_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pir_idempotency_key (idempotency_key),
  KEY idx_pir_actor_started (actor_id, started_at),
  KEY idx_pir_status_started (status, started_at),
  CONSTRAINT chk_pir_source_sha256 CHECK (source_sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_pir_manifest_hash CHECK (manifest_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_import_run_satirlari (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_run_id BIGINT UNSIGNED NOT NULL,
  satir_no INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  sicil_no VARCHAR(32) NOT NULL,
  tc_kimlik_no_masked VARCHAR(32) NOT NULL,
  row_hash CHAR(64) NOT NULL,
  ad VARCHAR(80) NOT NULL,
  soyad VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pirs_run_satir (import_run_id, satir_no),
  KEY idx_pirs_personel_id (personel_id),
  CONSTRAINT chk_pirs_row_hash CHECK (row_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT fk_pirs_import_run
    FOREIGN KEY (import_run_id) REFERENCES personel_import_runs (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
