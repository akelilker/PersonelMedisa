-- S82: Bordro onay durumu, correction projection hash, devir import audit
-- Additive only.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE maas_hesaplama_calistirmalari
  ADD COLUMN bordro_onay_durumu ENUM(
    'HESAPLANDI',
    'MUHASEBE_KONTROLUNDE',
    'ONAY_BEKLIYOR',
    'KESINLESTI',
    'YENIDEN_HESAP_GEREKLI'
  ) NOT NULL DEFAULT 'HESAPLANDI' AFTER state,
  ADD COLUMN correction_projection_hash CHAR(64) NULL AFTER carryover_set_hash,
  ADD COLUMN policy_version_hash CHAR(64) NULL AFTER correction_projection_hash,
  ADD COLUMN muhasebe_kontrol_notu VARCHAR(2000) NULL AFTER warning_count,
  ADD COLUMN muhasebe_kontrol_by INT UNSIGNED NULL AFTER muhasebe_kontrol_notu,
  ADD COLUMN muhasebe_kontrol_at TIMESTAMP NULL AFTER muhasebe_kontrol_by,
  ADD COLUMN kesinlestiren_by INT UNSIGNED NULL AFTER muhasebe_kontrol_at,
  ADD COLUMN kesinlestirme_at TIMESTAMP NULL AFTER kesinlestiren_by,
  ADD KEY idx_mhc_bordro_onay (bordro_onay_durumu),
  ADD CONSTRAINT fk_mhc_muhasebe_kontrol FOREIGN KEY (muhasebe_kontrol_by) REFERENCES users (id),
  ADD CONSTRAINT fk_mhc_kesinlestiren FOREIGN KEY (kesinlestiren_by) REFERENCES users (id);

ALTER TABLE maas_hesaplama_adaylari
  ADD COLUMN correction_projection_json JSON NULL AFTER solver_json,
  ADD COLUMN bordro_onay_durumu ENUM(
    'HESAPLANDI',
    'MUHASEBE_KONTROLUNDE',
    'ONAY_BEKLIYOR',
    'KESINLESTI',
    'YENIDEN_HESAP_GEREKLI'
  ) NOT NULL DEFAULT 'HESAPLANDI' AFTER state;

CREATE TABLE IF NOT EXISTS personel_bordro_devir_importlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  toplam_satir INT UNSIGNED NOT NULL DEFAULT 0,
  basarili_satir INT UNSIGNED NOT NULL DEFAULT 0,
  hatali_satir INT UNSIGNED NOT NULL DEFAULT 0,
  hata_ozeti JSON NULL,
  actor_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pbdi_sube_donem (sube_id, yil, ay),
  CONSTRAINT fk_pbdi_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pbdi_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
