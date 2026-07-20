-- S82: Sirket calisma politikasi (mevzuattan ayri, onayli)
-- Additive only. Gercek politika degeri seed edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sirket_calisma_politikalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  parent_politika_id INT UNSIGNED NULL,
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  aciklama VARCHAR(1000) NULL,
  policy_version_hash CHAR(64) NULL,
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  iptal_eden_id INT UNSIGNED NULL,
  iptal_zamani DATETIME NULL,
  iptal_nedeni VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  aktif_onayli TINYINT(1) AS (
    CASE WHEN state = 'ONAYLANDI' AND gecerlilik_bitis IS NULL THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  KEY idx_scp_state (state),
  KEY idx_scp_gecerlilik (gecerlilik_baslangic, gecerlilik_bitis),
  UNIQUE KEY uq_scp_aktif_onayli (aktif_onayli),
  CONSTRAINT fk_scp_parent FOREIGN KEY (parent_politika_id) REFERENCES sirket_calisma_politikalari (id),
  CONSTRAINT fk_scp_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_scp_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT fk_scp_iptal_eden FOREIGN KEY (iptal_eden_id) REFERENCES users (id),
  CONSTRAINT fk_scp_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_scp_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_scp_tarih CHECK (
    gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sirket_calisma_politika_degerleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  politika_id INT UNSIGNED NOT NULL,
  parametre_kodu VARCHAR(80) NOT NULL,
  deger_tipi ENUM('SAYISAL', 'METIN') NOT NULL,
  sayisal_deger DECIMAL(18,6) NULL,
  metin_deger VARCHAR(255) NULL,
  birim VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scpd_politika_kod (politika_id, parametre_kodu),
  KEY idx_scpd_kod (parametre_kodu),
  CONSTRAINT fk_scpd_politika FOREIGN KEY (politika_id) REFERENCES sirket_calisma_politikalari (id),
  CONSTRAINT chk_scpd_deger CHECK (
    (deger_tipi = 'SAYISAL' AND sayisal_deger IS NOT NULL AND metin_deger IS NULL)
    OR
    (deger_tipi = 'METIN' AND metin_deger IS NOT NULL AND sayisal_deger IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sirket_calisma_politika_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  politika_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'UPDATE', 'SUBMIT', 'APPROVE', 'CANCEL', 'REVISION') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_scpa_politika (politika_id, created_at),
  KEY idx_scpa_request_hash (request_hash),
  CONSTRAINT fk_scpa_politika FOREIGN KEY (politika_id) REFERENCES sirket_calisma_politikalari (id) ON DELETE SET NULL,
  CONSTRAINT fk_scpa_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
