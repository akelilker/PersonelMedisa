-- S3C: append-only QR raw attendance events (GIRIS/CIKIS evidence).
-- Additive only. No seed / production backfill.
-- Stateless QR display tokens are NOT stored here.
-- MariaDB 10.6 / 11.4. PHP 7.4 runtime uyumlu schema.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS qr_attendance_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  event_type ENUM('GIRIS', 'CIKIS') NOT NULL,
  occurred_at_utc DATETIME(6) NOT NULL,
  qr_version TINYINT UNSIGNED NOT NULL,
  qr_jti CHAR(32) NOT NULL,
  qr_issued_at_utc DATETIME(6) NOT NULL,
  qr_expires_at_utc DATETIME(6) NOT NULL,
  request_nonce CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qr_att_user_nonce (user_id, request_nonce),
  UNIQUE KEY uq_qr_att_user_jti_type (user_id, qr_jti, event_type),
  KEY idx_qr_att_personel_occurred (personel_id, occurred_at_utc, id),
  KEY idx_qr_att_user_occurred (user_id, occurred_at_utc, id),
  KEY idx_qr_att_sube_occurred (sube_id, occurred_at_utc, id),
  CONSTRAINT fk_qr_att_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_att_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qr_att_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
