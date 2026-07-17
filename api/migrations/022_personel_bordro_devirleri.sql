-- S77-D Personel bordro yasal devir girdileri (kumulatif vergi matrahi)
-- Additive migration; hard delete yok; revision ile guncelleme.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_bordro_devirleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  onceki_kumulatif_gelir_vergisi_matrahi DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  onceki_kumulatif_gelir_vergisi DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  onceki_kumulatif_sgk_matrahi DECIMAL(14,2) NULL,
  devir_kaynagi VARCHAR(80) NOT NULL DEFAULT 'MANUEL',
  aciklama VARCHAR(500) NULL,
  state ENUM('AKTIF', 'IPTAL') NOT NULL DEFAULT 'AKTIF',
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  parent_devir_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  aktif_devir TINYINT(1) AS (
    CASE WHEN state = 'AKTIF' THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbd_personel_donem_revision (personel_id, yil, ay, revision_no),
  UNIQUE KEY uq_pbd_aktif (personel_id, yil, ay, aktif_devir),
  KEY idx_pbd_sube_donem (sube_id, yil, ay),
  KEY idx_pbd_state (state),
  CONSTRAINT fk_pbd_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pbd_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pbd_parent FOREIGN KEY (parent_devir_id) REFERENCES personel_bordro_devirleri (id),
  CONSTRAINT fk_pbd_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_pbd_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_pbd_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_pbd_ay CHECK (ay BETWEEN 1 AND 12),
  CONSTRAINT chk_pbd_matrah_nonneg CHECK (onceki_kumulatif_gelir_vergisi_matrahi >= 0),
  CONSTRAINT chk_pbd_vergi_nonneg CHECK (onceki_kumulatif_gelir_vergisi >= 0),
  CONSTRAINT chk_pbd_sgk_nonneg CHECK (onceki_kumulatif_sgk_matrahi IS NULL OR onceki_kumulatif_sgk_matrahi >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_bordro_devir_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  devir_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'REVISION', 'CANCEL') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbda_idempotency (personel_id, yil, ay, aksiyon, request_hash),
  KEY idx_pbda_devir (devir_id),
  KEY idx_pbda_sube_donem (sube_id, yil, ay, created_at),
  CONSTRAINT fk_pbda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pbda_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pbda_devir FOREIGN KEY (devir_id) REFERENCES personel_bordro_devirleri (id) ON DELETE SET NULL,
  CONSTRAINT fk_pbda_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT chk_pbda_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_pbda_ay CHECK (ay BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
