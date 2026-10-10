-- 100: Kullanıcı bazlı yetki istisnaları (ALLOW / DENY) + değiştirilemez audit.
--
-- Dinamik yetki planı v3 — P2 (şema + okuma). Bu migration yalnız tablo kurar;
-- veri yazmaz, mevcut kullanıcıların etkin izinlerini değiştirmez (boş tablo =
-- bugünkü rol matrisi). Yazma uçları P3'tedir.
--
--  * user_yetki_istisnalari: kayıt silinmez, yalnız iptal edilir (iptal_* alanları
--    bir kez doldurulur; çekirdek alanlar hiçbir zaman güncellenmez).
--  * user_yetki_auditleri: append-only (099 deseni: BEFORE UPDATE / DELETE SIGNAL).
--  * Zaman alanları UTC'dir. gecerlilik_bitis NULL = kalıcı.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_yetki_istisnalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  permission VARCHAR(96) NOT NULL,
  etki ENUM('ALLOW', 'DENY') NOT NULL,
  sube_id INT UNSIGNED NULL,
  gecerlilik_baslangic DATETIME NOT NULL,
  gecerlilik_bitis DATETIME NULL,
  veren_user_id INT UNSIGNED NOT NULL,
  veren_actor_identity_id INT UNSIGNED NULL,
  hedef_rol_snapshot VARCHAR(64) NOT NULL,
  gerekce VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  iptal_edildi_at DATETIME NULL,
  iptal_eden_user_id INT UNSIGNED NULL,
  iptal_nedeni ENUM('MANUEL', 'ROL_DEGISTI', 'SURE_DOLDU') NULL,
  PRIMARY KEY (id),
  KEY idx_uyi_user_aktif (user_id, iptal_edildi_at),
  KEY idx_uyi_permission (permission),
  CONSTRAINT fk_uyi_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uyi_veren FOREIGN KEY (veren_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uyi_iptal_eden FOREIGN KEY (iptal_eden_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uyi_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uyi_veren_kimlik FOREIGN KEY (veren_actor_identity_id) REFERENCES actor_identities (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_uyi_sure CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis > gecerlilik_baslangic),
  CONSTRAINT chk_uyi_gerekce CHECK (CHAR_LENGTH(gerekce) > 0),
  CONSTRAINT chk_uyi_permission CHECK (CHAR_LENGTH(permission) > 0),
  CONSTRAINT chk_uyi_iptal CHECK (
    (iptal_edildi_at IS NULL AND iptal_eden_user_id IS NULL AND iptal_nedeni IS NULL)
    OR (iptal_edildi_at IS NOT NULL AND iptal_nedeni IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kayıt silinmez; yalnız bir kez iptal edilir. Çekirdek alanlar değişmez.
DROP TRIGGER IF EXISTS trg_uyi_no_delete;
CREATE TRIGGER trg_uyi_no_delete
BEFORE DELETE ON user_yetki_istisnalari
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'YETKI_ISTISNA_IMMUTABLE: yetki istisnasi silinemez, iptal edilir';

DROP TRIGGER IF EXISTS trg_uyi_only_revoke;
CREATE TRIGGER trg_uyi_only_revoke
BEFORE UPDATE ON user_yetki_istisnalari
FOR EACH ROW
IF OLD.iptal_edildi_at IS NOT NULL
   OR NEW.iptal_edildi_at IS NULL
   OR NEW.iptal_nedeni IS NULL
   OR NOT (NEW.id <=> OLD.id)
   OR NOT (NEW.user_id <=> OLD.user_id)
   OR NOT (NEW.permission <=> OLD.permission)
   OR NOT (NEW.etki <=> OLD.etki)
   OR NOT (NEW.sube_id <=> OLD.sube_id)
   OR NOT (NEW.gecerlilik_baslangic <=> OLD.gecerlilik_baslangic)
   OR NOT (NEW.gecerlilik_bitis <=> OLD.gecerlilik_bitis)
   OR NOT (NEW.veren_user_id <=> OLD.veren_user_id)
   OR NOT (NEW.veren_actor_identity_id <=> OLD.veren_actor_identity_id)
   OR NOT (NEW.hedef_rol_snapshot <=> OLD.hedef_rol_snapshot)
   OR NOT (NEW.gerekce <=> OLD.gerekce)
   OR NOT (NEW.created_at <=> OLD.created_at)
THEN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'YETKI_ISTISNA_IMMUTABLE: istisna yalniz bir kez iptal edilebilir';
END IF;

CREATE TABLE IF NOT EXISTS user_yetki_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  aksiyon ENUM('VER', 'KALDIR', 'ROL_DEGISTI_IPTAL', 'PROFIL_DEGISTI', 'REDDEDILDI') NOT NULL,
  aktor_user_id INT UNSIGNED NOT NULL,
  aktor_actor_identity_id INT UNSIGNED NULL,
  hedef_user_id INT UNSIGNED NOT NULL,
  hedef_rol VARCHAR(64) NOT NULL,
  istisna_id INT UNSIGNED NULL,
  permission VARCHAR(96) NULL,
  etki ENUM('ALLOW', 'DENY') NULL,
  sube_id INT UNSIGNED NULL,
  gecerlilik_baslangic DATETIME NULL,
  gecerlilik_bitis DATETIME NULL,
  onceki_json JSON NULL,
  sonraki_json JSON NULL,
  gerekce VARCHAR(500) NULL,
  uyari_kodlari VARCHAR(255) NULL,
  request_id VARCHAR(64) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_uya_hedef (hedef_user_id, created_at),
  KEY idx_uya_aktor (aktor_user_id, created_at),
  CONSTRAINT fk_uya_aktor FOREIGN KEY (aktor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uya_hedef FOREIGN KEY (hedef_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uya_istisna FOREIGN KEY (istisna_id) REFERENCES user_yetki_istisnalari (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_uya_aktor_kimlik FOREIGN KEY (aktor_actor_identity_id) REFERENCES actor_identities (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_uya_no_update;
CREATE TRIGGER trg_uya_no_update
BEFORE UPDATE ON user_yetki_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'YETKI_AUDIT_IMMUTABLE: yetki audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_uya_no_delete;
CREATE TRIGGER trg_uya_no_delete
BEFORE DELETE ON user_yetki_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'YETKI_AUDIT_IMMUTABLE: yetki audit satiri silinemez';
