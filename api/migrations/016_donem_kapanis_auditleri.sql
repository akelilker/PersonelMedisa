-- S76 Donem kapanis auditleri
-- Additive migration; append-only audit trail.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS donem_kapanis_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  action VARCHAR(40) NOT NULL,
  result_state VARCHAR(40) NOT NULL,
  muhur_id INT UNSIGNED NULL,
  blocker_count INT UNSIGNED NOT NULL DEFAULT 0,
  warning_count INT UNSIGNED NOT NULL DEFAULT 0,
  preflight_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  result_hash CHAR(64) NOT NULL,
  preflight_snapshot JSON NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dka_idempotency (sube_id, yil, ay, action, request_hash),
  KEY idx_dka_sube_donem_created (sube_id, yil, ay, created_at),
  CONSTRAINT fk_dka_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_dka_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id),
  CONSTRAINT fk_dka_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_dka_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_dka_ay CHECK (ay BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
