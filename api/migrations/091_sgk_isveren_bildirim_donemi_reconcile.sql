-- 091: one-shot guarded reconciliation from legacy approved branch-period evidence
-- into the canonical SGK-employer factual reporting-period owner created by 090.
--
-- Owner: SGK_ISVEREN (090). Legacy source: sgk_sirket_politika_surumleri (036,
-- owner = sube_id), joined branch -> subeler -> subeler.sgk_isveren_id.
--
-- Only historically effective/approved legacy rows (state = ONAYLANDI) are
-- authoritative for reconciliation. Per SGK employer the migration derives the
-- DISTINCT bildirim_donem_tipi and the effective validity interval from LIVE DB
-- rows at apply time. Nothing is hardcoded here: no employer id, no branch id, no
-- company name, no period choice and no validity date is written into this file.
--
-- The branch is no longer the owner, so a branch without its own branch policy is
-- NOT automatically a blocker as long as its SGK employer has valid consensus
-- evidence from other branches.
--
-- Relevant active SGK employer = an sgk_isverenler row with durum = 'AKTIF' that is
-- referenced by at least one subeler.sgk_isveren_id (the branch axis this
-- reconciliation bridges).
--
-- Fail-closed. Before ANY insert this migration proves A-I and then either inserts
-- every proven employer in one transaction or SIGNALs PACK091_BLOCKER with zero
-- canonical inserts (all-or-nothing, no partial reconciliation):
--   A) the 090 canonical factual table exists with the expected factual schema
--   B) every relevant active SGK employer has >= 1 authoritative legacy effective row
--   C) per employer: DISTINCT effective bildirim_donem_tipi count = exactly 1
--   D) derived period type is a member of the canonical legal enum
--   E) effective date truth is unambiguous (one distinct interval per employer)
--   F) no contradictory overlapping source rows for the same employer
--   G) sube -> sgk_isveren mapping is non-null and valid
--   H) existing canonical factual rows are absent or exactly compatible
--   I) no partial reconciliation is possible
--
-- Idempotent/defensive: an exact replay or an exactly compatible pre-existing
-- canonical truth inserts nothing and stays safe; contradictory pre-existing truth
-- blocks. MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET SESSION group_concat_max_len = 1048576;

START TRANSACTION;

-- A) canonical factual owner (090) must exist with the expected factual schema.
SET @p091_canonical_table := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
);
SET @p091_canonical_columns := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME IN (
      'id', 'sgk_isveren_id', 'surum_kodu', 'bildirim_donem_tipi',
      'gecerlilik_baslangic', 'gecerlilik_bitis', 'state', 'dogrulama_kaynagi',
      'dogrulama_kanit_hash', 'aciklama', 'dogrulayan_id', 'dogrulama_zamani', 'created_at'
    )
);
SET @p091_canonical_state := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME = 'state'
    AND COLUMN_TYPE LIKE '%DOGRULANDI%'
);
SET @p091_canonical_hash := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME = 'dogrulama_kanit_hash'
    AND DATA_TYPE = 'char'
    AND CHARACTER_MAXIMUM_LENGTH = 64
    AND IS_NULLABLE = 'NO'
);
SET @p091_canonical_fk := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME = 'sgk_isveren_id'
    AND REFERENCED_TABLE_NAME = 'sgk_isverenler'
    AND REFERENCED_COLUMN_NAME = 'id'
);
SET @p091_canonical_approval := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME IN ('hazirlayan_id', 'onaylayan_id', 'onay_zamani')
);
SET @p091_schema_bad := IF(
  @p091_canonical_table = 1
    AND @p091_canonical_columns = 13
    AND @p091_canonical_state = 1
    AND @p091_canonical_hash = 1
    AND @p091_canonical_fk = 1
    AND @p091_canonical_approval = 0,
  0,
  1
);

-- Evidence staging: only approved legacy rows mapped to their SGK employer.
DROP TEMPORARY TABLE IF EXISTS tmp091_legacy;
CREATE TEMPORARY TABLE tmp091_legacy (
  politika_surum_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  sgk_isveren_id INT UNSIGNED NULL,
  bildirim_donem_tipi VARCHAR(32) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  politika_hash CHAR(64) NOT NULL,
  PRIMARY KEY (politika_surum_id)
) ENGINE=InnoDB;

INSERT INTO tmp091_legacy (
  politika_surum_id, sube_id, sgk_isveren_id, bildirim_donem_tipi,
  gecerlilik_baslangic, gecerlilik_bitis, politika_hash
)
SELECT
  p.id, p.sube_id, s.sgk_isveren_id, p.bildirim_donem_tipi,
  p.gecerlilik_baslangic, p.gecerlilik_bitis, p.politika_hash
FROM sgk_sirket_politika_surumleri p
LEFT JOIN subeler s ON s.id = p.sube_id
WHERE p.state = 'ONAYLANDI';

-- Relevant active SGK employers: active employers on the branch axis.
DROP TEMPORARY TABLE IF EXISTS tmp091_employers;
CREATE TEMPORARY TABLE tmp091_employers (
  sgk_isveren_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (sgk_isveren_id)
) ENGINE=InnoDB;

INSERT INTO tmp091_employers (sgk_isveren_id)
SELECT DISTINCT e.id
FROM sgk_isverenler e
JOIN subeler s ON s.sgk_isveren_id = e.id
WHERE e.durum = 'AKTIF';

-- Derived canonical facts: one row per employer with consensus evidence.
DROP TEMPORARY TABLE IF EXISTS tmp091_derived;
CREATE TEMPORARY TABLE tmp091_derived (
  sgk_isveren_id INT UNSIGNED NOT NULL,
  surum_kodu VARCHAR(80) NOT NULL,
  bildirim_donem_tipi VARCHAR(32) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  kaynak_satir_sayisi INT UNSIGNED NOT NULL,
  kanit_hash CHAR(64) NOT NULL,
  PRIMARY KEY (sgk_isveren_id)
) ENGINE=InnoDB;

INSERT INTO tmp091_derived (
  sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic,
  gecerlilik_bitis, kaynak_satir_sayisi, kanit_hash
)
SELECT
  l.sgk_isveren_id,
  CONCAT('SGK-ISVEREN-', LPAD(l.sgk_isveren_id, 10, '0'), '-LEGACY-CONSENSUS'),
  MIN(l.bildirim_donem_tipi),
  MIN(l.gecerlilik_baslangic),
  MAX(l.gecerlilik_bitis),
  COUNT(*),
  SHA2(CONCAT_WS('|',
    l.sgk_isveren_id,
    MIN(l.bildirim_donem_tipi),
    DATE_FORMAT(MIN(l.gecerlilik_baslangic), '%Y-%m-%d'),
    COALESCE(DATE_FORMAT(MAX(l.gecerlilik_bitis), '%Y-%m-%d'), 'NULL'),
    GROUP_CONCAT(CONCAT(
      l.politika_surum_id, ':', l.sube_id, ':', l.politika_hash, ':',
      l.bildirim_donem_tipi, ':', DATE_FORMAT(l.gecerlilik_baslangic, '%Y-%m-%d'), ':',
      COALESCE(DATE_FORMAT(l.gecerlilik_bitis, '%Y-%m-%d'), 'NULL')
    ) ORDER BY l.politika_surum_id SEPARATOR ',')
  ), 256)
FROM tmp091_legacy l
WHERE l.sgk_isveren_id IS NOT NULL
GROUP BY l.sgk_isveren_id;

-- Snapshot of the existing canonical truth for the relevant employers (guard H/J).
DROP TEMPORARY TABLE IF EXISTS tmp091_existing;
CREATE TEMPORARY TABLE tmp091_existing (
  sgk_isveren_id INT UNSIGNED NOT NULL,
  surum_kodu VARCHAR(80) NOT NULL,
  bildirim_donem_tipi VARCHAR(32) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  state VARCHAR(32) NOT NULL,
  dogrulama_kaynagi VARCHAR(64) NOT NULL,
  dogrulama_kanit_hash CHAR(64) NOT NULL,
  PRIMARY KEY (sgk_isveren_id, surum_kodu)
) ENGINE=InnoDB;

INSERT INTO tmp091_existing (
  sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic,
  gecerlilik_bitis, state, dogrulama_kaynagi, dogrulama_kanit_hash
)
SELECT
  c.sgk_isveren_id, c.surum_kodu, c.bildirim_donem_tipi, c.gecerlilik_baslangic,
  c.gecerlilik_bitis, c.state, c.dogrulama_kaynagi, c.dogrulama_kanit_hash
FROM sgk_isveren_bildirim_donemi_surumleri c
JOIN tmp091_employers e ON e.sgk_isveren_id = c.sgk_isveren_id;

-- D) canonical legal period enum, read from the canonical owner (never hardcoded).
SET @p091_tip_enum := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME = 'bildirim_donem_tipi'
  LIMIT 1
);

-- B) every relevant active employer must have authoritative legacy evidence.
SET @p091_missing_source := (
  SELECT COUNT(*)
  FROM tmp091_employers e
  WHERE NOT EXISTS (
    SELECT 1 FROM tmp091_legacy l WHERE l.sgk_isveren_id = e.sgk_isveren_id
  )
);

-- C) exactly one distinct effective period type per employer.
SET @p091_tip_drift := (
  SELECT COUNT(*)
  FROM (
    SELECT l.sgk_isveren_id
    FROM tmp091_legacy l
    JOIN tmp091_employers e ON e.sgk_isveren_id = l.sgk_isveren_id
    GROUP BY l.sgk_isveren_id
    HAVING COUNT(DISTINCT l.bildirim_donem_tipi) <> 1
  ) drift
);

-- D) derived period type must be a member of the canonical legal enum.
SET @p091_bad_tip := (
  SELECT COUNT(*)
  FROM tmp091_derived d
  WHERE @p091_tip_enum IS NULL
     OR @p091_tip_enum NOT LIKE CONCAT('%''', d.bildirim_donem_tipi, '''%')
);

-- E) effective validity interval must be unambiguous per employer.
SET @p091_interval_drift := (
  SELECT COUNT(*)
  FROM (
    SELECT l.sgk_isveren_id
    FROM tmp091_legacy l
    JOIN tmp091_employers e ON e.sgk_isveren_id = l.sgk_isveren_id
    GROUP BY l.sgk_isveren_id
    HAVING COUNT(DISTINCT CONCAT(
      DATE_FORMAT(l.gecerlilik_baslangic, '%Y-%m-%d'), '/',
      COALESCE(DATE_FORMAT(l.gecerlilik_bitis, '%Y-%m-%d'), 'NULL')
    )) <> 1
  ) drift
);

-- F) overlapping source rows for the same employer must not contradict.
SET @p091_overlap_conflict := (
  SELECT COUNT(*)
  FROM tmp091_legacy a
  JOIN tmp091_legacy b
    ON a.sgk_isveren_id = b.sgk_isveren_id
   AND a.politika_surum_id < b.politika_surum_id
   AND a.gecerlilik_baslangic <= COALESCE(b.gecerlilik_bitis, '9999-12-31')
   AND b.gecerlilik_baslangic <= COALESCE(a.gecerlilik_bitis, '9999-12-31')
  WHERE (a.bildirim_donem_tipi <> b.bildirim_donem_tipi
      OR a.gecerlilik_baslangic <> b.gecerlilik_baslangic
      OR NOT (a.gecerlilik_bitis <=> b.gecerlilik_bitis))
);

-- G) branch -> SGK employer mapping must be non-null and valid.
SET @p091_mapping_bad := (
  SELECT COUNT(*)
  FROM tmp091_legacy l
  LEFT JOIN sgk_isverenler e ON e.id = l.sgk_isveren_id
  WHERE l.sgk_isveren_id IS NULL OR e.id IS NULL
);
SET @p091_branch_orphan := (
  SELECT COUNT(*)
  FROM subeler s
  LEFT JOIN sgk_isverenler e ON e.id = s.sgk_isveren_id
  WHERE s.sgk_isveren_id IS NOT NULL AND e.id IS NULL
);

-- H) existing canonical factual rows must be absent or exactly compatible.
SET @p091_existing_incompatible := (
  SELECT COUNT(*)
  FROM tmp091_existing x
  JOIN tmp091_derived d ON d.sgk_isveren_id = x.sgk_isveren_id
  WHERE NOT (
    x.surum_kodu = d.surum_kodu
    AND x.state = 'DOGRULANDI'
    AND x.bildirim_donem_tipi = d.bildirim_donem_tipi
    AND x.gecerlilik_baslangic <=> d.gecerlilik_baslangic
    AND x.gecerlilik_bitis <=> d.gecerlilik_bitis
    AND x.dogrulama_kaynagi = 'LEGACY_APPROVED_BRANCH_POLICY_CONSENSUS'
    AND x.dogrulama_kanit_hash = d.kanit_hash
  )
);

-- I) every relevant employer must yield exactly one derived row (no partial truth).
SET @p091_partial := IF(
  (SELECT COUNT(*) FROM tmp091_employers) = (SELECT COUNT(*) FROM tmp091_derived),
  0,
  1
);

SET @p091_blocker_count := @p091_schema_bad
  + @p091_missing_source
  + @p091_tip_drift
  + @p091_bad_tip
  + @p091_interval_drift
  + @p091_overlap_conflict
  + @p091_mapping_bad
  + @p091_branch_orphan
  + @p091_existing_incompatible
  + @p091_partial;
SET @p091_sql := IF(
  @p091_blocker_count > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK091_BLOCKER: SGK employer reporting-period reconciliation guard failed; zero canonical inserts''',
  'DO 0'
);
PREPARE p091_stmt FROM @p091_sql; EXECUTE p091_stmt; DEALLOCATE PREPARE p091_stmt;

-- Reconciliation insert: canonical factual employer truth, verified from live
-- legacy evidence. No fabricated actor: dogrulayan_id stays NULL because no real
-- factual verifier identity is canonically available for these derived rows.
INSERT INTO sgk_isveren_bildirim_donemi_surumleri (
  sgk_isveren_id, surum_kodu, bildirim_donem_tipi, gecerlilik_baslangic,
  gecerlilik_bitis, state, dogrulama_kaynagi, dogrulama_kanit_hash, aciklama,
  dogrulayan_id, dogrulama_zamani
)
SELECT
  d.sgk_isveren_id,
  d.surum_kodu,
  d.bildirim_donem_tipi,
  d.gecerlilik_baslangic,
  d.gecerlilik_bitis,
  'DOGRULANDI',
  'LEGACY_APPROVED_BRANCH_POLICY_CONSENSUS',
  d.kanit_hash,
  CONCAT(
    'Legacy approved branch policy consensus reconciliation (091): ',
    d.kaynak_satir_sayisi,
    ' authoritative legacy source row(s)'
  ),
  NULL,
  UTC_TIMESTAMP()
FROM tmp091_derived d
WHERE NOT EXISTS (
  SELECT 1 FROM tmp091_existing x WHERE x.sgk_isveren_id = d.sgk_isveren_id
);

DROP TEMPORARY TABLE IF EXISTS tmp091_existing;
DROP TEMPORARY TABLE IF EXISTS tmp091_derived;
DROP TEMPORARY TABLE IF EXISTS tmp091_employers;
DROP TEMPORARY TABLE IF EXISTS tmp091_legacy;

COMMIT;
