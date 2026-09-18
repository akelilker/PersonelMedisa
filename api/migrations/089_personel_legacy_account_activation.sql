-- 089: PERSONEL canonical hesap modeli hizalamasi (legacy hesap devralma).
--
-- Canonical PERSONEL hesap modeli (owner'lar: 075 + PersonelAccountOnboardingService +
-- LoginController activation guard):
--   personel kendi sifresini yalniz tek kullanimlik secure aktivasyon baglantisiyla belirler.
--
-- Bu migration, hic aktive edilmemis legacy PERSONEL hesaplarini ayni modele alir:
--   activation_required = 1, must_change_password = 1
--
-- Hedef cohort (yalniz bu):
--   rol = 'PERSONEL' AND activation_required = 0 AND activated_at_utc IS NULL
--
-- Dokunulmayan alanlar: username, personel_id binding, rol, durum, password_hash,
--   sube/bolum/birim/sirket/sgk atamalari, personel kayitlari.
-- Dokunulmayan kayitlar: PERSONEL disi tum users; aktivasyon bekleyen hesaplar
--   (activation_required = 1) ve bir kez aktive edilmis hesaplar (activated_at_utc NOT NULL).
-- Davet uretmez; sifre uretmez, sifirlamaz, hicbir credential (plaintext veya hash) yazmaz.
-- Idempotent: ayni kosulda tekrar calistirildiginda hedef satir kalmaz (0 row affected).
-- Login tarafi activation_required = 1 icin sifre dogrulamasindan ONCE fail-closed oldugu
-- icin legacy password_hash gecersiz kilinmaz, hash rewrite yapilmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Fail-closed: canonical activation owner'lari (075) yoksa migration uygulanmaz.
SET @p089_cols := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME IN ('activation_required', 'must_change_password', 'activated_at_utc', 'personel_id')
);
SET @p089_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'personel_account_onboarding_audit')
);
SET @p089_sql := IF(
  @p089_cols = 4 AND @p089_tables = 2,
  'DO 0',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK089_BLOCKER: canonical activation owners missing'''
);
PREPARE p089_stmt FROM @p089_sql; EXECUTE p089_stmt; DEALLOCATE PREPARE p089_stmt;

-- Denetim izi: donusum oncesi hedef cohort. Secret yok; tekrar calistirmada cogalmaz.
INSERT INTO personel_account_onboarding_audit
  (event_type, user_id, personel_id, actor_user_id, invitation_id, detail_json, created_at_utc)
SELECT
  'LEGACY_ACCOUNT_ACTIVATION_TAKEOVER',
  u.id,
  CASE
    WHEN u.personel_id IS NOT NULL
      AND u.personel_id > 0
      AND EXISTS (SELECT 1 FROM personeller p WHERE p.id = u.personel_id)
    THEN u.personel_id
    ELSE NULL
  END,
  NULL,
  NULL,
  CONCAT(
    '{"source":"089_personel_legacy_account_activation.sql","before":{"activation_required":',
    u.activation_required,
    ',"must_change_password":',
    u.must_change_password,
    '}}'
  ),
  UTC_TIMESTAMP()
FROM users u
WHERE u.rol = 'PERSONEL'
  AND u.activation_required = 0
  AND u.activated_at_utc IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM personel_account_onboarding_audit a
    WHERE a.event_type = 'LEGACY_ACCOUNT_ACTIVATION_TAKEOVER'
      AND a.user_id = u.id
  );

-- Canonical hedef state: legacy PERSONEL hesaplari aktivasyon bekleyen duruma alinir.
UPDATE users
   SET activation_required = 1,
       must_change_password = 1
 WHERE rol = 'PERSONEL'
   AND activation_required = 0
   AND activated_at_utc IS NULL;
