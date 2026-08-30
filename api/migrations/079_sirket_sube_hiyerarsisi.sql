-- 079: canonical company -> SGK employer / branch -> work location hierarchy.
--
-- Scope (additive, schema only):
--   1) sirketler                     — the legal company / organisation set.
--   2) subeler.sirket_id             — a physical branch belongs to one company.
--   3) sgk_isverenler.sirket_id      — a payroll/SGK employer belongs to one company.
--   4) calisma_lokasyonlari.sube_id  — a work location belongs to one branch.
--   5) user_sirketler                — company-wide authorization scope.
--   6) user_sgk_isverenler           — SGK/payroll-wide authorization scope.
--
-- The four axes stay independent on purpose. personeller keeps sube_id,
-- sgk_isveren_id and calisma_lokasyonu_id as separate columns and deliberately
-- gains NO sirket_id: the company of a person is derived from the branch, never
-- stored twice. A payroll employer is not a branch and a branch is not a
-- processing centre, so none of these may be inferred from another.
--
-- NO DATA WRITES. This migration inserts, updates and deletes nothing:
--   no company seed, no branch mapping, no branch rename, no user scope copy,
--   no personnel move, no tam_ad column, no monthly-closing change, no backfill.
-- Every existing id, row and value survives byte-identical.
--
-- All new relation columns are nullable so legacy rows stay valid without a
-- backfill; production mapping is a separate, approved operation. Because the
-- rows are not mapped yet, the final (sirket_id, ad) branch-name hardening is
-- intentionally NOT applied here — it belongs to a later migration that runs
-- after the mapping. Short-branch-name uniqueness inside a company is enforced
-- by the domain/API layer in the meantime.
--
-- Foreign keys never destroy data: user links cascade with the user (the row is
-- an assignment, not a record), every organisation reference is RESTRICT so a
-- company or branch with dependents cannot be deleted out from under its rows.
--
-- Fail-closed: aborts when an owner table is missing, when a pre-existing column
-- has an incompatible type/nullability, when an existing foreign key points at
-- the wrong target, when a foreign `sirketler` table with a different shape is
-- already present, or when the post-DDL readback does not show the intended
-- structure. A compatible partial state resumes; an incompatible one never
-- reports success.
-- Idempotent: every step is guarded by information_schema, so a second run and a
-- resume after an interrupted run are both no-ops.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible (PREPARE one-liners, no routines).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Structural guard — every owner table this migration extends must exist.
-- ---------------------------------------------------------------------------
SET @p079_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'subeler', 'sgk_isverenler', 'calisma_lokasyonlari')
);
SET @p079_sql := IF(
  @p079_tables <> 4,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: organisation owner tables missing''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 2) Drift guard — a relation column that already exists must be exactly the
--    shape this migration would have created. A NOT NULL, signed or wrongly
--    typed column would silently change write semantics, so it aborts instead.
-- ---------------------------------------------------------------------------
SET @p079_bad_cols := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND (
      (TABLE_NAME = 'subeler' AND COLUMN_NAME = 'sirket_id')
      OR (TABLE_NAME = 'sgk_isverenler' AND COLUMN_NAME = 'sirket_id')
      OR (TABLE_NAME = 'calisma_lokasyonlari' AND COLUMN_NAME = 'sube_id')
    )
    AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'YES')
);
SET @p079_sql := IF(
  @p079_bad_cols <> 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: incompatible hierarchy column already present''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 3) Drift guard — an existing foreign key on one of the relation columns must
--    already point at the canonical parent. A same-named column wired to a
--    different table is a conflicting model, not a partial run.
-- ---------------------------------------------------------------------------
SET @p079_bad_fk := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME IS NOT NULL
    AND (
      (TABLE_NAME = 'subeler' AND COLUMN_NAME = 'sirket_id' AND REFERENCED_TABLE_NAME <> 'sirketler')
      OR (TABLE_NAME = 'sgk_isverenler' AND COLUMN_NAME = 'sirket_id' AND REFERENCED_TABLE_NAME <> 'sirketler')
      OR (TABLE_NAME = 'calisma_lokasyonlari' AND COLUMN_NAME = 'sube_id' AND REFERENCED_TABLE_NAME <> 'subeler')
    )
);
SET @p079_sql := IF(
  @p079_bad_fk <> 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: hierarchy foreign key points at the wrong parent''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 4) sirketler — the root of the hierarchy. `kod` is the stable technical
--    identity (immutable at the API layer); names are labels, never keys.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sirketler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kod VARCHAR(32) NOT NULL,
  ad VARCHAR(191) NOT NULL,
  durum ENUM('AKTIF', 'PASIF') NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sirketler_kod (kod),
  UNIQUE KEY uq_sirketler_ad (ad),
  KEY idx_sirketler_durum (durum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A pre-existing table under this name that is not the canonical company table
-- would make every FK below reference the wrong entity.
SET @p079_sirket_shape := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sirketler'
    AND COLUMN_NAME IN ('id', 'kod', 'ad', 'durum')
);
SET @p079_sirket_uq := (
  SELECT COUNT(DISTINCT INDEX_NAME)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sirketler'
    AND NON_UNIQUE = 0
    AND INDEX_NAME IN ('uq_sirketler_kod', 'uq_sirketler_ad')
);
SET @p079_sql := IF(
  @p079_sirket_shape <> 4 OR @p079_sirket_uq <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: incompatible sirketler table already present''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 5) subeler.sirket_id — the branch's owning company. Nullable: unmapped legacy
--    branches keep working and the read model falls back to the raw name.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subeler'
     AND COLUMN_NAME = 'sirket_id') = 0,
  'ALTER TABLE subeler ADD COLUMN sirket_id INT UNSIGNED NULL AFTER kod',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subeler'
     AND INDEX_NAME = 'idx_subeler_sirket') = 0,
  'ALTER TABLE subeler ADD KEY idx_subeler_sirket (sirket_id)',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subeler'
     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
     AND CONSTRAINT_NAME = 'fk_subeler_sirket') = 0,
  'ALTER TABLE subeler
     ADD CONSTRAINT fk_subeler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 6) sgk_isverenler.sirket_id — the payroll employer's owning company. This is a
--    sibling of the branch axis, not a parent and not a child of it.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isverenler'
     AND COLUMN_NAME = 'sirket_id') = 0,
  'ALTER TABLE sgk_isverenler ADD COLUMN sirket_id INT UNSIGNED NULL AFTER kod',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isverenler'
     AND INDEX_NAME = 'idx_sgk_isverenler_sirket') = 0,
  'ALTER TABLE sgk_isverenler ADD KEY idx_sgk_isverenler_sirket (sirket_id)',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_isverenler'
     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
     AND CONSTRAINT_NAME = 'fk_sgk_isverenler_sirket') = 0,
  'ALTER TABLE sgk_isverenler
     ADD CONSTRAINT fk_sgk_isverenler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 7) calisma_lokasyonlari.sube_id — the physical work location sits under a
--    branch. Nullable, and a null location never blocks branch management.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calisma_lokasyonlari'
     AND COLUMN_NAME = 'sube_id') = 0,
  'ALTER TABLE calisma_lokasyonlari ADD COLUMN sube_id INT UNSIGNED NULL AFTER kod',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calisma_lokasyonlari'
     AND INDEX_NAME = 'idx_calisma_lokasyonlari_sube') = 0,
  'ALTER TABLE calisma_lokasyonlari ADD KEY idx_calisma_lokasyonlari_sube (sube_id)',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calisma_lokasyonlari'
     AND CONSTRAINT_TYPE = 'FOREIGN KEY'
     AND CONSTRAINT_NAME = 'fk_calisma_lokasyonlari_sube') = 0,
  'ALTER TABLE calisma_lokasyonlari
     ADD CONSTRAINT fk_calisma_lokasyonlari_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 8) user_sirketler — company-wide authorization scope. Deliberately a scope
--    table, not a materialised branch list: a user scoped to a company sees
--    branches added to that company later without any assignment rewrite.
--    Left empty here; granting scope is an application operation.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sirketler (
  user_id INT UNSIGNED NOT NULL,
  sirket_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, sirket_id),
  KEY idx_user_sirketler_sirket (sirket_id),
  CONSTRAINT fk_user_sirketler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_user_sirketler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 9) user_sgk_isverenler — payroll-employer authorization scope, resolved on the
--    personeller.sgk_isveren_id axis and never inferred from a physical branch.
--    This is authorization scope; personel_bordro_kapsamlari is payroll data.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sgk_isverenler (
  user_id INT UNSIGNED NOT NULL,
  sgk_isveren_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, sgk_isveren_id),
  KEY idx_user_sgk_isverenler_sgk (sgk_isveren_id),
  CONSTRAINT fk_user_sgk_isverenler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT fk_user_sgk_isverenler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 10) Readback assert — the three relation columns, the two scope tables and
--     every foreign key must be in place before this migration reports success.
-- ---------------------------------------------------------------------------
SET @p079_rel_cols := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND (
      (TABLE_NAME = 'subeler' AND COLUMN_NAME = 'sirket_id')
      OR (TABLE_NAME = 'sgk_isverenler' AND COLUMN_NAME = 'sirket_id')
      OR (TABLE_NAME = 'calisma_lokasyonlari' AND COLUMN_NAME = 'sube_id')
    )
    AND DATA_TYPE = 'int'
    AND COLUMN_TYPE LIKE '%unsigned%'
    AND IS_NULLABLE = 'YES'
);
SET @p079_scope_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('sirketler', 'user_sirketler', 'user_sgk_isverenler')
);
SET @p079_fks := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    AND CONSTRAINT_NAME IN (
      'fk_subeler_sirket',
      'fk_sgk_isverenler_sirket',
      'fk_calisma_lokasyonlari_sube',
      'fk_user_sirketler_user',
      'fk_user_sirketler_sirket',
      'fk_user_sgk_isverenler_user',
      'fk_user_sgk_isverenler_sgk'
    )
);
SET @p079_sql := IF(
  @p079_rel_cols <> 3 OR @p079_scope_tables <> 3 OR @p079_fks <> 7,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: sirket-sube hierarchy readback failed''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

COMMIT;
