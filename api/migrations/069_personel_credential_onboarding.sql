-- 069: PERSONEL credential onboarding — forced password change flag on users.
-- Safe additive column; default 0 preserves existing accounts.

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
  AFTER durum;
