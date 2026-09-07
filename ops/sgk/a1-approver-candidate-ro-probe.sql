-- A1 approver candidate RO probe — SELECT ONLY.
-- Forbidden: write DML/DDL. Allowed: SELECT projection below.
-- Candidates: usernames 343, 220, 017, 349. Excluded unless new evidence: 005, 004 (Senay-linked).
-- Preparer reference (not a candidate): sedanurB.
-- Required columns (exact projection):
-- USERNAME, USER_ID, PERSONEL_ID, PERSONEL_NAME, ROLE, AKTIF, SIRKET, SUBE, DEPARTMAN,
-- BOLUM, BIRIM, ACTOR_IDENTITY_STATUS, CURRENT_BOLUM_IDS, CURRENT_USER_SUBELER, MEDISA_FIT
-- Do not select password_hash, tokens, TC, phone, or other excess PII.

SET NAMES utf8mb4;

SELECT
  u.username AS USERNAME,
  u.id AS USER_ID,
  u.personel_id AS PERSONEL_ID,
  CASE
    WHEN p.id IS NULL THEN NULL
    ELSE CONCAT(COALESCE(p.ad, ''), ' ', COALESCE(p.soyad, ''))
  END AS PERSONEL_NAME,
  u.rol AS ROLE,
  u.durum AS AKTIF,
  s.sirket_id AS SIRKET,
  p.sube_id AS SUBE,
  p.departman_id AS DEPARTMAN,
  p.bolum_id AS BOLUM,
  p.birim_id AS BIRIM,
  ai.status AS ACTOR_IDENTITY_STATUS,
  (
    SELECT GROUP_CONCAT(ub.bolum_id ORDER BY ub.bolum_id SEPARATOR ',')
    FROM user_bolumler ub
    WHERE ub.user_id = u.id
  ) AS CURRENT_BOLUM_IDS,
  (
    SELECT GROUP_CONCAT(us.sube_id ORDER BY us.sube_id SEPARATOR ',')
    FROM user_subeler us
    WHERE us.user_id = u.id
  ) AS CURRENT_USER_SUBELER,
  CASE
    WHEN EXISTS (
      SELECT 1
      FROM user_sirketler usk
      INNER JOIN sirketler sk ON sk.id = usk.sirket_id
      WHERE usk.user_id = u.id
        AND (
          sk.kod = 'MEDISA'
          OR sk.ad LIKE 'Medisa%'
        )
    )
    OR EXISTS (
      SELECT 1
      FROM user_subeler us2
      INNER JOIN subeler sb ON sb.id = us2.sube_id
      WHERE us2.user_id = u.id
        AND sb.sirket_id = 1
    )
    THEN 'YES'
    ELSE 'NO'
  END AS MEDISA_FIT
FROM users u
LEFT JOIN personeller p ON p.id = u.personel_id
LEFT JOIN subeler s ON s.id = p.sube_id
LEFT JOIN actor_identities ai ON ai.id = u.actor_identity_id
WHERE u.username IN ('343', '220', '017', '349', 'sedanurB')
ORDER BY FIELD(u.username, 'sedanurB', '343', '220', '017', '349');
