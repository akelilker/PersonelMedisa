-- S77-B Tarih bazli mevzuat parametreleri ve audit kayitlari
-- Bilerek gercek mevzuat degeri seed edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS mevzuat_parametreleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parametre_kodu VARCHAR(80) NOT NULL,
  deger_tipi ENUM('SAYISAL', 'METIN') NOT NULL,
  sayisal_deger DECIMAL(18,6) NULL,
  metin_deger VARCHAR(255) NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  birim VARCHAR(32) NULL,
  aciklama VARCHAR(500) NULL,
  kaynak_referansi VARCHAR(255) NULL,
  state ENUM('AKTIF', 'IPTAL') NOT NULL DEFAULT 'AKTIF',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  open_ended_aktif TINYINT(1) AS (
    CASE WHEN state = 'AKTIF' AND gecerlilik_bitis IS NULL THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  KEY idx_mp_kod_baslangic (parametre_kodu, gecerlilik_baslangic),
  KEY idx_mp_kod_state (parametre_kodu, state),
  UNIQUE KEY uq_mp_open_ended_aktif (parametre_kodu, open_ended_aktif),
  UNIQUE KEY uq_mp_kod_baslangic_state (parametre_kodu, gecerlilik_baslangic, state),
  CONSTRAINT fk_mp_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_mp_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_mp_deger_tipi CHECK (
    (deger_tipi = 'SAYISAL' AND sayisal_deger IS NOT NULL AND metin_deger IS NULL)
    OR
    (deger_tipi = 'METIN' AND metin_deger IS NOT NULL AND sayisal_deger IS NULL)
  ),
  CONSTRAINT chk_mp_tarih_araligi CHECK (
    gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mevzuat_parametre_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parametre_kodu VARCHAR(80) NOT NULL,
  parametre_kaydi_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'UPDATE', 'CLOSE', 'CANCEL') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mpa_kod_created (parametre_kodu, created_at),
  KEY idx_mpa_parametre_kaydi (parametre_kaydi_id),
  KEY idx_mpa_request_hash (request_hash),
  CONSTRAINT fk_mpa_parametre_kaydi FOREIGN KEY (parametre_kaydi_id) REFERENCES mevzuat_parametreleri (id) ON DELETE SET NULL,
  CONSTRAINT fk_mpa_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
