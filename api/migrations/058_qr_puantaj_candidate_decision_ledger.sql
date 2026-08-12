-- S3F: append-only QR puantaj candidate human decision ledger (review / keep / apply audit).
-- Additive only. No seed / production backfill.
-- No UPDATE/DELETE business API. MariaDB 10.6 / 11.4. PHP 7.4 runtime uyumlu schema.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS qr_puantaj_candidate_decision_ledger (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  candidate_date DATE NOT NULL,
  candidate_hash CHAR(64) NOT NULL,
  decision_type ENUM('APPLY_EXISTING', 'KEEP_CANONICAL', 'REOPEN_REVIEW') NOT NULL,
  decision_reason VARCHAR(1000) NOT NULL,
  puantaj_id INT UNSIGNED NULL,
  algorithm_version VARCHAR(64) NOT NULL,
  interval_algorithm_version VARCHAR(64) NOT NULL,
  decision_algorithm_version VARCHAR(64) NOT NULL,
  candidate_snapshot JSON NOT NULL,
  before_puantaj_snapshot JSON NULL,
  after_puantaj_snapshot JSON NULL,
  decided_by_user_id INT UNSIGNED NOT NULL,
  request_nonce CHAR(36) NOT NULL,
  supersedes_decision_id INT UNSIGNED NULL,
  previous_decision_hash CHAR(64) NULL,
  decision_hash CHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_pc_decision_user_nonce (decided_by_user_id, request_nonce),
  KEY idx_qr_pc_decision_personel_date (personel_id, candidate_date, id),
  KEY idx_qr_pc_decision_hash (candidate_hash, id),
  KEY idx_qr_pc_decision_sube_date (sube_id, candidate_date, id),
  CONSTRAINT fk_qr_pc_decision_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_pc_decision_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_pc_decision_puantaj FOREIGN KEY (puantaj_id) REFERENCES gunluk_puantaj (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_pc_decision_user FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_pc_decision_supersedes FOREIGN KEY (supersedes_decision_id) REFERENCES qr_puantaj_candidate_decision_ledger (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
