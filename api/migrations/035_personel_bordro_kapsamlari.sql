-- S84-R2: Tarih etkili personel bordro kapsam owner'i
-- Additive only. Hard-delete yok. Seed/demo HARIC yazilmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_bordro_kapsamlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  durum ENUM('DAHIL', 'HARIC') NOT NULL,
  neden_kodu ENUM('DEMO_TEST_VERISI', 'BORDRO_DISI_STATU', 'HARICI_BORDRO', 'DIGER_ONAYLI_NEDEN') NOT NULL,
  aciklama VARCHAR(1000) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  iptal_eden_id INT UNSIGNED NULL,
  iptal_zamani DATETIME NULL,
  iptal_nedeni VARCHAR(500) NULL,
  parent_kapsam_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pbk_personel_gecerlilik (personel_id, gecerlilik_baslangic, gecerlilik_bitis),
  KEY idx_pbk_sube_state (sube_id, state),
  KEY idx_pbk_state_durum (state, durum),
  CONSTRAINT fk_pbk_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pbk_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pbk_parent FOREIGN KEY (parent_kapsam_id) REFERENCES personel_bordro_kapsamlari (id),
  CONSTRAINT fk_pbk_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_pbk_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT fk_pbk_iptal_eden FOREIGN KEY (iptal_eden_id) REFERENCES users (id),
  CONSTRAINT fk_pbk_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_pbk_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_pbk_tarih CHECK (
    gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic
  ),
  CONSTRAINT chk_pbk_aciklama CHECK (CHAR_LENGTH(TRIM(aciklama)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_bordro_kapsam_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kapsam_id INT UNSIGNED NULL,
  personel_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'SUBMIT', 'APPROVE', 'CANCEL', 'DRY_RUN') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pbka_kapsam (kapsam_id, created_at),
  KEY idx_pbka_personel (personel_id, created_at),
  KEY idx_pbka_request_hash (request_hash),
  CONSTRAINT fk_pbka_kapsam FOREIGN KEY (kapsam_id) REFERENCES personel_bordro_kapsamlari (id) ON DELETE SET NULL,
  CONSTRAINT fk_pbka_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE SET NULL,
  CONSTRAINT fk_pbka_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
