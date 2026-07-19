-- S79-D: serbest zaman event store (append-only) + aktif olusum uniqueness guard
-- Additive only. Matches TS SerbestZamanEvent / motor read model.
-- ON DELETE RESTRICT: event history must survive parent hard-delete.
-- CREATE TABLE without IF NOT EXISTS: unexpected/partial existing table fails loudly.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE serbest_zaman_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  event_tipi VARCHAR(32) NOT NULL,
  dakika INT UNSIGNED NULL,
  yeni_dakika INT UNSIGNED NULL,
  event_tarihi DATE NOT NULL,
  son_kullanim_tarihi DATE NULL,
  kaynak_snapshot_id INT UNSIGNED NULL,
  kaynak_odeme_tercihi_id INT UNSIGNED NULL,
  hedef_event_id INT UNSIGNED NULL,
  hedef_event_tipi VARCHAR(32) NULL,
  islem_anahtari VARCHAR(64) NULL,
  aciklama VARCHAR(500) NULL,
  donem_yil SMALLINT UNSIGNED NULL,
  donem_ay TINYINT UNSIGNED NULL,
  donem_kilitli_miydi TINYINT(1) NOT NULL DEFAULT 0,
  iptal_hedef_key INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN event_tipi = 'SERBEST_ZAMAN_IPTAL' THEN hedef_event_id ELSE NULL END
    ) STORED,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sz_personel_islem_anahtari (personel_id, islem_anahtari),
  UNIQUE KEY uq_sz_iptal_hedef (iptal_hedef_key),
  KEY idx_sz_personel_tarih (personel_id, event_tarihi, id),
  KEY idx_sz_event_tipi (event_tipi),
  KEY idx_sz_kaynak_odeme_tercihi (kaynak_odeme_tercihi_id),
  KEY idx_sz_kaynak_snapshot (kaynak_snapshot_id),
  KEY idx_sz_hedef_event (hedef_event_id),
  CONSTRAINT fk_sz_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sz_snapshot FOREIGN KEY (kaynak_snapshot_id) REFERENCES haftalik_kapanis_satirlari (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sz_odeme_tercihi FOREIGN KEY (kaynak_odeme_tercihi_id) REFERENCES fazla_calisma_odeme_tercihleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sz_hedef_event FOREIGN KEY (hedef_event_id) REFERENCES serbest_zaman_events (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sz_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_sz_event_tipi CHECK (
    event_tipi IN (
      'SERBEST_ZAMAN_OLUSUM',
      'SERBEST_ZAMAN_KULLANIM',
      'SERBEST_ZAMAN_DUZELTME',
      'SERBEST_ZAMAN_IPTAL'
    )
  ),
  CONSTRAINT chk_sz_hedef_event_tipi CHECK (
    hedef_event_tipi IS NULL
    OR hedef_event_tipi IN ('SERBEST_ZAMAN_OLUSUM', 'SERBEST_ZAMAN_KULLANIM')
  ),
  CONSTRAINT chk_sz_olusum_fields CHECK (
    event_tipi <> 'SERBEST_ZAMAN_OLUSUM'
    OR (
      dakika IS NOT NULL AND dakika > 0
      AND yeni_dakika IS NULL
      AND son_kullanim_tarihi IS NOT NULL
      AND kaynak_snapshot_id IS NOT NULL
      AND kaynak_odeme_tercihi_id IS NOT NULL
      AND hedef_event_id IS NULL
      AND hedef_event_tipi IS NULL
      AND islem_anahtari IS NULL
    )
  ),
  CONSTRAINT chk_sz_kullanim_fields CHECK (
    event_tipi <> 'SERBEST_ZAMAN_KULLANIM'
    OR (
      dakika IS NOT NULL AND dakika > 0
      AND yeni_dakika IS NULL
      AND son_kullanim_tarihi IS NULL
      AND kaynak_snapshot_id IS NULL
      AND kaynak_odeme_tercihi_id IS NULL
      AND hedef_event_id IS NULL
      AND hedef_event_tipi IS NULL
      AND islem_anahtari IS NOT NULL
    )
  ),
  CONSTRAINT chk_sz_duzeltme_fields CHECK (
    event_tipi <> 'SERBEST_ZAMAN_DUZELTME'
    OR (
      dakika IS NULL
      AND yeni_dakika IS NOT NULL AND yeni_dakika > 0
      AND son_kullanim_tarihi IS NULL
      AND kaynak_snapshot_id IS NULL
      AND kaynak_odeme_tercihi_id IS NULL
      AND hedef_event_id IS NOT NULL
      AND hedef_event_tipi IS NOT NULL
      AND islem_anahtari IS NOT NULL
      AND aciklama IS NOT NULL AND CHAR_LENGTH(TRIM(aciklama)) > 0
    )
  ),
  CONSTRAINT chk_sz_iptal_fields CHECK (
    event_tipi <> 'SERBEST_ZAMAN_IPTAL'
    OR (
      dakika IS NULL
      AND yeni_dakika IS NULL
      AND son_kullanim_tarihi IS NULL
      AND kaynak_snapshot_id IS NULL
      AND kaynak_odeme_tercihi_id IS NULL
      AND hedef_event_id IS NOT NULL
      AND hedef_event_tipi IS NOT NULL
      AND islem_anahtari IS NOT NULL
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE serbest_zaman_aktif_olusumlar (
  odeme_tercihi_id INT UNSIGNED NOT NULL,
  olusum_event_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (odeme_tercihi_id),
  UNIQUE KEY uq_sz_aktif_olusum_event (olusum_event_id),
  CONSTRAINT fk_szao_odeme_tercihi FOREIGN KEY (odeme_tercihi_id) REFERENCES fazla_calisma_odeme_tercihleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_szao_olusum_event FOREIGN KEY (olusum_event_id) REFERENCES serbest_zaman_events (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
