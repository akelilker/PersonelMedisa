-- 071: Organizational hierarchy authorization foundations.
-- Adds independent SUBE_YONETICISI role + user↔bolum / user↔birim assignment tables.
-- Schema only. NO user role remaps. NO assignment backfill. NO personnel data writes.
-- Idempotent / MariaDB 10.6+ / PHP 7.4 PDO-compatible (PREPARE one-liners).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 1) Widen users.rol ENUM with SUBE_YONETICISI (keep legacy inventory values).
-- ---------------------------------------------------------------------------
ALTER TABLE users
  MODIFY COLUMN rol ENUM(
    'GENEL_YONETICI',
    'SISTEM_YONETICISI',
    'SUBE_YONETICISI',
    'BOLUM_YONETICISI',
    'BIRIM_AMIRI',
    'IK_SORUMLUSU',
    'MUHASEBE',
    'PERSONEL',
    'AUTH_SMOKE_READONLY',
    'PATRON',
    'IK_BORDRO',
    'SGK_KARAR_ONAY_YETKILISI',
    'IDARI_ISLER'
  ) NOT NULL;

-- ---------------------------------------------------------------------------
-- 2) user_bolumler — BOLUM_YONETICISI assignment (M:N)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_bolumler (
  user_id INT UNSIGNED NOT NULL,
  bolum_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, bolum_id),
  KEY idx_user_bolumler_bolum (bolum_id),
  CONSTRAINT fk_user_bolumler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_user_bolumler_bolum FOREIGN KEY (bolum_id) REFERENCES bolumler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) user_birimler — BIRIM_AMIRI assignment (M:N)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_birimler (
  user_id INT UNSIGNED NOT NULL,
  birim_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, birim_id),
  KEY idx_user_birimler_birim (birim_id),
  CONSTRAINT fk_user_birimler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_user_birimler_birim FOREIGN KEY (birim_id) REFERENCES birimler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
