import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), 'utf8');

const migration = read('api/migrations/080_organizasyon_audit_owners.sql');
const auditWriter = read('api/src/Services/Organizasyon/OrganizasyonAuditWriter.php');
const auditContext = read('api/src/Services/Organizasyon/OrganizasyonAuditContext.php');
const organizasyonService = read('api/src/Services/Organizasyon/OrganizasyonService.php');
const branchChangeService = read('api/src/Services/Personel/PersonelKaliciSubeDegisikligiService.php');
const personellerController = read('api/src/Controllers/PersonellerController.php');
const organizasyonController = read('api/src/Controllers/OrganizasyonController.php');
const yonetimController = read('api/src/Controllers/YonetimController.php');
const router = read('api/src/Router.php');

const AUDIT_TABLES = [
  'personel_sube_degisiklik_auditleri',
  'sube_olusturma_auditleri',
  'user_org_scope_auditleri',
];

describe('migration 080: organisation audit tables', () => {
  it('creates the three domain audit tables additively', () => {
    for (const table of AUDIT_TABLES) {
      expect(migration).toContain(`CREATE TABLE IF NOT EXISTS ${table} (`);
    }
    expect(migration).toContain('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    expect(migration).toContain('PACK080_BLOCKER');
  });

  it('writes no business data', () => {
    const statements = migration.replace(/--[^\n]*\n/g, '\n');
    expect(statements).not.toMatch(/\bINSERT\s+INTO\s+(?!.*_auditleri)/i);
    expect(statements).not.toMatch(/\bUPDATE\s+(?:personeller|subeler|users|user_subeler)\b/i);
    expect(statements).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(statements).not.toMatch(/\bDROP\s+TABLE\b/i);
  });

  it('keeps every audit row attributable and indexed', () => {
    expect(migration.match(/actor_user_id INT UNSIGNED NOT NULL/g)).toHaveLength(3);
    expect(migration.match(/request_hash CHAR\(64\) NOT NULL/g)).toHaveLength(3);
    expect(migration.match(/created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP/g)).toHaveLength(3);
    expect(migration).toContain('CONSTRAINT fk_psda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)');
    expect(migration).toContain('CONSTRAINT fk_psda_yeni_sube FOREIGN KEY (yeni_sube_id) REFERENCES subeler (id)');
    expect(migration).toContain('CONSTRAINT fk_soa_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)');
    expect(migration).toContain('CONSTRAINT fk_uosa_target FOREIGN KEY (target_user_id) REFERENCES users (id)');
    expect(migration).toContain('UNIQUE KEY uq_soa_sube (sube_id)');
    expect(migration).toContain('KEY idx_psda_personel_created (personel_id, created_at)');
    expect(migration).toContain('KEY idx_uosa_target_created (target_user_id, created_at)');
    expect(migration).toMatch(/chk_psda_request_hash CHECK \(request_hash REGEXP/);
    expect(migration).toContain('chk_uosa_changed CHECK (onceki_ids <> yeni_ids)');
  });

  it('enforces append-only at the database, with no destroy gate', () => {
    for (const trigger of [
      'trg_psda_no_update',
      'trg_psda_no_delete',
      'trg_soa_no_update',
      'trg_soa_no_delete',
      'trg_uosa_no_update',
      'trg_uosa_no_delete',
    ]) {
      expect(migration).toContain(`DROP TRIGGER IF EXISTS ${trigger};`);
      expect(migration).toContain(`CREATE TRIGGER ${trigger}`);
    }
    expect(migration.match(/SIGNAL SQLSTATE '45000'/g)?.length).toBeGreaterThanOrEqual(6);
    expect(migration).not.toContain('retention_physical_destroy_gates');
  });

  it('carries no personnel or credential PII', () => {
    const ddl = migration.replace(/--[^\n]*\n/g, '\n').toLowerCase();
    for (const column of ['tc_kimlik_no', 'ad_soyad', 'telefon', 'iban', 'password', 'soyad']) {
      expect(ddl).not.toContain(column);
    }
  });
});

describe('organisation audit writer', () => {
  it('stays a domain owner with one table per write path', () => {
    for (const table of AUDIT_TABLES) {
      expect(auditWriter).toContain(table);
    }
    expect(auditWriter).toContain('recordPersonelSubeDegisikligi');
    expect(auditWriter).toContain('recordSubeOlusturma');
    expect(auditWriter).toContain('recordUserOrgScopeChange');
    expect(auditWriter).not.toMatch(/\bfunction\s+recordEvent\b/);
  });

  it('exposes no update or delete path', () => {
    expect(auditWriter).not.toMatch(/UPDATE\s+(?:personel_sube_degisiklik|sube_olusturma|user_org_scope)_auditleri/i);
    expect(auditWriter).not.toMatch(/DELETE\s+FROM\s+\w*_auditleri/i);
  });

  it('fails closed when migration 080 is absent', () => {
    expect(auditWriter).toContain("ORGANIZASYON_AUDIT_SCHEMA_NOT_READY");
    expect(auditWriter).toContain('public static function assertReady');
  });

  it('binds every audit row to an actor and a request fingerprint', () => {
    expect(auditContext).toContain("hash(\n            'sha256'");
    expect(auditContext).toContain('$request->getMethod()');
    expect(auditContext).toContain('$request->getRawBody()');
    expect(auditContext).toMatch(/if \(\$actorUserId <= 0\)/);
  });

  it('canonicalises id sets so before/after rows are comparable', () => {
    expect(auditWriter).toContain('public static function canonicalIdList');
    expect(auditWriter).toContain('sort($normalized);');
    expect(auditWriter).toContain('array_unique');
  });
});

describe('permanent personnel branch change owner', () => {
  it('is the only route that writes personeller.sube_id', () => {
    expect(router).toContain("preg_match('#^/personeller/(\\d+)/kalici-sube-degisikligi$#'");
    expect(router).toContain('PersonellerController::kaliciSubeDegisikligi');
    expect(branchChangeService).toContain('UPDATE personeller SET sube_id = :yeni');
  });

  it('keeps the generic update guard forbidding branch changes', () => {
    expect(personellerController).toContain('private static function assertUpdateSubeScope');
    expect(personellerController).toMatch(
      /\$targetSubeId !== \$currentSubeId\) \{\s*JsonResponse::forbidden\(\);/,
    );
  });

  it('admits only the two unrestricted management roles', () => {
    expect(branchChangeService).toContain(
      "public const ALLOWED_ROLES = ['GENEL_YONETICI', 'SISTEM_YONETICISI'];",
    );
    for (const role of ['BOLUM_YONETICISI', 'MUHASEBE', 'IK_SORUMLUSU']) {
      expect(branchChangeService).not.toContain(role);
    }
  });

  it('requires an expected pre-image, a target and a justification', () => {
    expect(branchChangeService).toContain('beklenen_mevcut_sube_id');
    expect(branchChangeService).toContain('yeni_sube_id');
    expect(branchChangeService).toContain('gerekce');
    expect(branchChangeService).toContain('KALICI_SUBE_DEGISIKLIGI_STALE_PREIMAGE');
  });

  it('locks the row, guards the company match and verifies the readback', () => {
    expect(branchChangeService).toContain('FOR UPDATE');
    expect(branchChangeService).toContain('KALICI_SUBE_DEGISIKLIGI_SGK_SIRKET_MISMATCH');
    expect(branchChangeService).toContain('private static function assertReadback');
    expect(branchChangeService).toContain("foreach (['calisma_lokasyonu_id', 'sgk_isveren_id'] as $preserved)");
  });

  it('writes the audit inside the same transaction and rolls back on failure', () => {
    const body = branchChangeService.slice(
      branchChangeService.indexOf('$pdo->beginTransaction();'),
      branchChangeService.lastIndexOf('$pdo->commit();'),
    );
    expect(body).toContain('recordPersonelSubeDegisikligi');
    expect(body).toContain('self::writeSubeId(');
    expect(branchChangeService).toContain('$pdo->rollBack();');
  });

  it('touches no personnel column other than the branch', () => {
    const updates = branchChangeService.match(/UPDATE personeller SET [^']*/g) ?? [];
    expect(updates.length).toBeGreaterThan(0);
    for (const statement of updates) {
      expect(statement).not.toMatch(/,\s*\w+\s*=/);
    }
  });

  it('leaves the temporary assignment surface untouched', () => {
    expect(personellerController).toContain('PersonelGeciciGorevlendirmeService::create');
    expect(router).toContain("preg_match('#^/personeller/(\\d+)/gecici-gorevlendirmeler$#'");
  });
});

describe('branch creation audit', () => {
  it('keeps createSube the single writer and audits inside its transaction', () => {
    const createSube = organizasyonService.slice(
      organizasyonService.indexOf('public static function createSube'),
      organizasyonService.indexOf('public static function updateSube'),
    );
    expect(createSube).toContain('OrganizasyonAuditWriter::assertReady');
    expect(createSube.indexOf('recordSubeOlusturma')).toBeGreaterThan(
      createSube.indexOf('$pdo->beginTransaction();'),
    );
    expect(createSube.indexOf('recordSubeOlusturma')).toBeLessThan(createSube.indexOf('$pdo->commit();'));
    expect(createSube).toContain('$pdo->rollBack();');
  });

  it('passes an actor context from both branch-create routes', () => {
    expect(organizasyonController).toContain(
      'OrganizasyonService::createSube($pdo, $body, (int) $sirket[\'id\'], $auditContext)',
    );
    expect(organizasyonController).toContain('OrganizasyonAuditContext::fromRequest($request, $user)');
    expect(yonetimController).toContain('OrganizasyonAuditContext::fromRequest($request, $user)');
    expect(router.match(/OrganizasyonService::createSube/g)).toBeNull();
  });

  it('records only organisation values, never derived display names', () => {
    expect(auditWriter).toContain("'kod' => (string) \$entry['kod']");
    expect(auditWriter).toContain('departman_ids_hash');
    expect(auditWriter).not.toContain('tam_ad');
  });
});

describe('user organisation scope audit', () => {
  it('audits each changed axis inside the scope mutation transaction', () => {
    const block = yonetimController.slice(
      yonetimController.indexOf('$scopeAxesTouched = ['),
      yonetimController.indexOf('} catch (PDOException $e) {', yonetimController.indexOf('$scopeAxesTouched = [')),
    );
    expect(block).toContain('OrganizasyonAuditWriter::SCOPE_SUBE');
    expect(block).toContain('OrganizasyonAuditWriter::SCOPE_SIRKET');
    expect(block).toContain('OrganizasyonAuditWriter::SCOPE_SGK_ISVEREN');
    expect(block).toContain('recordUserOrgScopeChange');
    expect(block.indexOf('$pdo->beginTransaction();')).toBeLessThan(block.indexOf('recordUserOrgScopeChange'));
  });

  it('produces no audit row for an unchanged set', () => {
    expect(yonetimController).toContain(
      'OrganizasyonAuditWriter::canonicalIdList($before) === OrganizasyonAuditWriter::canonicalIdList($after)',
    );
    expect(auditWriter).toContain('if ($beforeList === $afterList) {');
  });

  it('keeps the existing replace-scope writers as the only mutation path', () => {
    expect(yonetimController).toContain('self::replaceUserSubeler($pdo, $kullaniciId, $finalSubeIds);');
    expect(yonetimController).toContain('UserOrgAssignmentSchema::replaceUserSirketler');
    expect(yonetimController).toContain('UserOrgAssignmentSchema::replaceUserSgkIsverenler');
  });
});
