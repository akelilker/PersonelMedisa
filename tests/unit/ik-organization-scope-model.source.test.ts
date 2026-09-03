import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import {
  ASSIGNABLE_USER_ROLES,
  ORGANIZATION_GLOBAL_READ_ROLES,
  SUBE_ASSIGNMENT_ROLES,
  WRITE_COMPANY_SCOPED_ROLES,
  type AuthSession,
  type UserRole
} from "../../src/types/auth";
import {
  getRolePermissions,
  hasRolePermission,
  isOrganizationGlobalReadRole,
  sessionAllowsSirketWrite,
  sessionAllowsSubeAccess,
  sessionAllowsSubeWrite
} from "../../src/lib/authorization/role-permissions";

const root = process.cwd();
const ORG_SCOPE = readFileSync(resolve(root, "api/src/Scope/OrgScope.php"), "utf8");
const HR_WRITE_SCOPE = readFileSync(resolve(root, "api/src/Scope/HrWriteScope.php"), "utf8");
const AUTH_MIDDLEWARE = readFileSync(resolve(root, "api/src/Auth/AuthMiddleware.php"), "utf8");
const YONETIM = readFileSync(resolve(root, "api/src/Controllers/YonetimController.php"), "utf8");
const PERSONELLER = readFileSync(resolve(root, "api/src/Controllers/PersonellerController.php"), "utf8");
const ROUTER = readFileSync(resolve(root, "api/src/Router.php"), "utf8");
const MIGRATION_081 = readFileSync(resolve(root, "api/migrations/081_ik_personeli_rolu.sql"), "utf8");
const AUDIT_WRITER = readFileSync(
  resolve(root, "api/src/Services/Organizasyon/OrganizasyonAuditWriter.php"),
  "utf8"
);
const IMPORT_DRY_RUN = readFileSync(
  resolve(root, "api/src/Services/Personel/PersonelImportDryRunService.php"),
  "utf8"
);

function sessionFor(
  rol: UserRole,
  options: {
    subeIds?: number[];
    sirketIds?: number[];
    subeList?: { id: number; sirketId: number | null }[];
  } = {}
): AuthSession {
  return {
    token: "t",
    user: {
      id: 1,
      ad_soyad: "Test",
      rol,
      sube_ids: options.subeIds ?? [],
      sirket_ids: options.sirketIds ?? []
    },
    ui_profile: "yonetim",
    active_sube_id: null,
    sube_list: (options.subeList ?? []).map((item) => ({
      id: item.id,
      ad: `Sube ${item.id}`,
      sirket_id: item.sirketId
    }))
  };
}

describe("İK organisation scope model", () => {
  it("registers IK_PERSONELI as a canonical assignable role", () => {
    expect(ASSIGNABLE_USER_ROLES).toContain("IK_PERSONELI");
    expect(getRolePermissions("IK_PERSONELI").length).toBeGreaterThan(0);
    expect(YONETIM).toContain("'IK_PERSONELI',");
    expect(MIGRATION_081).toContain("''IK_PERSONELI''");
  });

  it("keeps IK_PERSONELI a strict subset of IK_SORUMLUSU without approval authority", () => {
    const owner = new Set(getRolePermissions("IK_SORUMLUSU"));
    const assistant = getRolePermissions("IK_PERSONELI");

    for (const permission of assistant) {
      expect(owner.has(permission)).toBe(true);
    }
    expect(assistant.length).toBeLessThan(owner.size);

    for (const withheld of [
      "puantaj.donem_reseal",
      "sgk_karar_paketi.prepare",
      "sirket_parametreleri.manage",
      "personel_bordro_kapsam.manage",
      "maas_hesaplama.manage"
    ] as const) {
      expect(hasRolePermission("IK_SORUMLUSU", withheld)).toBe(true);
      expect(hasRolePermission("IK_PERSONELI", withheld)).toBe(false);
    }

    // Neither İK role may reach management-only surfaces.
    for (const role of ORGANIZATION_GLOBAL_READ_ROLES) {
      expect(hasRolePermission(role, "yonetim-paneli.manage")).toBe(false);
    }
  });

  it("derives İK visibility from the role instead of assignment rows", () => {
    expect(ORGANIZATION_GLOBAL_READ_ROLES).toEqual(["IK_SORUMLUSU", "IK_PERSONELI"]);
    expect(SUBE_ASSIGNMENT_ROLES).not.toContain("IK_SORUMLUSU");
    expect(SUBE_ASSIGNMENT_ROLES).not.toContain("IK_PERSONELI");

    for (const role of ORGANIZATION_GLOBAL_READ_ROLES) {
      expect(isOrganizationGlobalReadRole(role)).toBe(true);
      // Empty grant, legacy narrow grant and a future branch all read the same.
      expect(sessionAllowsSubeAccess(sessionFor(role), 1)).toBe(true);
      expect(sessionAllowsSubeAccess(sessionFor(role, { subeIds: [1] }), 99)).toBe(true);
    }

    expect(sessionAllowsSubeAccess(sessionFor("SUBE_YONETICISI"), 1)).toBe(false);
  });

  it("mirrors the role-derived read model in the PHP scope owner", () => {
    expect(ORG_SCOPE).toContain(
      "const ORGANIZATION_GLOBAL_READ_ROLES = ['IK_SORUMLUSU', 'IK_PERSONELI'];"
    );
    expect(ORG_SCOPE).toContain("const SUBE_ASSIGNMENT_ROLES = ['SUBE_YONETICISI', 'MUHASEBE'");
    expect(ORG_SCOPE).toContain("public static function isOrganizationGlobalRead(array $user)");
    // Read access returns before the branch-membership rules can narrow it.
    expect(ORG_SCOPE).toContain("HrWriteScope::assertPersonelWritable($user, $request, $personelOrg);");
  });

  it("scopes IK_PERSONELI writes to its granted companies only", () => {
    const scoped = sessionFor("IK_PERSONELI", {
      sirketIds: [1],
      subeList: [
        { id: 10, sirketId: 1 },
        { id: 20, sirketId: 2 },
        { id: 30, sirketId: null }
      ]
    });

    expect(WRITE_COMPANY_SCOPED_ROLES).toEqual(["IK_PERSONELI"]);
    expect(sessionAllowsSirketWrite(scoped, 1)).toBe(true);
    expect(sessionAllowsSirketWrite(scoped, 2)).toBe(false);
    expect(sessionAllowsSubeWrite(scoped, 10)).toBe(true);
    expect(sessionAllowsSubeWrite(scoped, 20)).toBe(false);

    // Fail-closed: a branch whose company cannot be resolved is not writable.
    expect(sessionAllowsSubeWrite(scoped, 30)).toBe(false);
    expect(sessionAllowsSubeWrite(scoped, 40)).toBe(false);
    expect(sessionAllowsSubeWrite(scoped, null)).toBe(false);

    // İK sorumlusu keeps writing everywhere its permissions allow.
    const owner = sessionFor("IK_SORUMLUSU");
    expect(sessionAllowsSubeWrite(owner, 20)).toBe(true);
    expect(sessionAllowsSirketWrite(owner, 2)).toBe(true);
  });

  it("resolves the write branch set from company grants only, per request", () => {
    expect(AUTH_MIDDLEWARE).toContain(
      "'write_sube_ids' => UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, $sirketIds),"
    );
    expect(HR_WRITE_SCOPE).toContain("const COMPANY_WRITE_SCOPED_ROLES = ['IK_PERSONELI'];");
    expect(HR_WRITE_SCOPE).toContain("const FORBIDDEN_CODE = 'IK_YAZMA_KAPSAMI_DISI';");
    // Explicit branch grants are never a write source for a scoped role.
    expect(HR_WRITE_SCOPE).not.toContain("explicit_sube_ids");
  });

  it("denies an unresolvable or out-of-scope write target rather than guessing", () => {
    expect(HR_WRITE_SCOPE).toContain(
      "if ($subeId <= 0 || !in_array($subeId, self::writeSubeIds($user), true)) {"
    );
    expect(HR_WRITE_SCOPE).toContain("self::deny();");
    expect(HR_WRITE_SCOPE).toContain("JsonResponse::error(403, self::FORBIDDEN_CODE, self::FORBIDDEN_MESSAGE);");
    // Reads are never blocked by the write gate.
    expect(HR_WRITE_SCOPE).toContain("const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];");
  });

  it("gates personnel creation and imports on the same write-company owner", () => {
    expect(PERSONELLER).toContain("HrWriteScope::assertSubeWritable($user, $subeId);");
    expect(PERSONELLER).toContain("self::assertWriteRole($user, 'personeller.create');");
    expect(PERSONELLER).toContain("self::assertWriteRole($user, 'personeller.update');");
    expect(IMPORT_DRY_RUN).toContain("HrWriteScope::writeSubeIds($user)");
    expect(IMPORT_DRY_RUN).toContain("PERSONEL_IMPORT_SUBE_SCOPE_IHLALI");
  });

  it("requires an explicit write company before an IK_PERSONELI account can be saved", () => {
    expect(YONETIM).toContain("HrWriteScope::requiresWriteCompanySelection($rol) && count($sirketIds) === 0");
    expect(YONETIM).toContain("Bu rol icin en az bir islem sirketi secilmelidir.");
    // IK_SORUMLUSU no longer needs a branch grant it cannot be narrowed by.
    expect(YONETIM).toContain("if ($rol === 'SUBE_YONETICISI') {");
    expect(YONETIM).toContain("if ($rol === 'MUHASEBE') {");
    expect(YONETIM).toContain(
      "Bu rol icin en az bir sube, sirket veya SGK kapsami zorunludur."
    );
  });

  it("keeps organisation-wide İK visibility free of company-materialised sube_ids", () => {
    expect(AUTH_MIDDLEWARE).toContain("OrgScope::isOrganizationGlobalRead(['rol' => $rol])");
    expect(AUTH_MIDDLEWARE).toContain("? []");
    expect(AUTH_MIDDLEWARE).toContain(
      "'write_sube_ids' => UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, $sirketIds),"
    );
  });
});

describe("login account removal owner", () => {
  it("exposes an audited DELETE owner for user accounts", () => {
    expect(ROUTER).toContain("YonetimController::kullaniciErisimKaldir($this->request, $matches[1]);");
    expect(YONETIM).toContain("public static function kullaniciErisimKaldir(Request $request, $kullaniciId)");
    expect(YONETIM).toContain(
      "OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::USER_ACCESS_REVOKE_TABLE);"
    );
    expect(YONETIM).toContain("OrganizasyonAuditWriter::recordUserAccessRevoke(");
  });

  it("revokes access without deleting the account or its personnel record", () => {
    expect(YONETIM).toContain("'durum' => 'PASIF',");
    expect(YONETIM).toContain("PasswordHasher::hash(bin2hex(random_bytes(32)))");
    expect(YONETIM).toContain("self::revokePendingActivationInvitations($pdo, $kullaniciId);");
    expect(YONETIM).toContain("UserPersonelBindingService::applyBinding($pdo, $kullaniciId, null, $actorUserId);");
    // The personnel row itself is never written by this owner.
    expect(YONETIM).not.toMatch(/DELETE FROM personeller/);
    expect(YONETIM).not.toMatch(/DELETE FROM users/);
  });

  it("rolls back the business mutation when the audit write fails", () => {
    const start = YONETIM.indexOf("public static function kullaniciErisimKaldir");
    const body = YONETIM.slice(start, YONETIM.indexOf("private static function revokePendingActivationInvitations"));
    expect(body).toContain("$pdo->beginTransaction();");
    expect(body).toContain("$pdo->rollBack();");
    // Audit rows are written inside the same transaction as the revocation.
    expect(body.indexOf("recordUserAccessRevoke")).toBeGreaterThan(body.indexOf("$pdo->beginTransaction();"));
    expect(body.indexOf("recordUserAccessRevoke")).toBeLessThan(body.indexOf("$pdo->commit();"));
  });

  it("is safe to repeat and refuses self-removal", () => {
    expect(YONETIM).toContain("SELF_ACCESS_REMOVAL_FORBIDDEN");
    expect(YONETIM).toContain("$alreadyRevoked = $oncekiDurum !== 'AKTIF'");
  });
});

describe("migration 081", () => {
  it("adds the role additively and leaves 080 untouched", () => {
    expect(MIGRATION_081).toContain("PACK081_BLOCKER: users.rol enum column missing");
    expect(MIGRATION_081).toContain("PACK081_BLOCKER: canonical role enum readback failed");
    expect(MIGRATION_081).toContain("PACK081_BLOCKER: role truncation detected");
    for (const preserved of [
      "GENEL_YONETICI",
      "SISTEM_YONETICISI",
      "SUBE_YONETICISI",
      "BOLUM_YONETICISI",
      "BIRIM_AMIRI",
      "IK_SORUMLUSU",
      "MUHASEBE",
      "PERSONEL",
      "AUTH_SMOKE_READONLY"
    ]) {
      expect(MIGRATION_081).toContain(`''${preserved}''`);
    }
    expect(MIGRATION_081).not.toMatch(/\bUPDATE\s+users\s+SET\s+rol\b/i);
    expect(MIGRATION_081).not.toMatch(/\bpersoneller\b/);
    expect(MIGRATION_081).not.toMatch(/\bDROP TABLE\b/);
  });

  it("creates an append-only revocation audit owner", () => {
    expect(MIGRATION_081).toContain("CREATE TABLE IF NOT EXISTS user_erisim_kaldirma_auditleri");
    expect(MIGRATION_081).toContain("CONSTRAINT chk_ueka_request_hash CHECK (CHAR_LENGTH(request_hash) = 64)");
    expect(MIGRATION_081).toContain("CONSTRAINT chk_ueka_durum CHECK (yeni_durum = 'PASIF')");
    expect(MIGRATION_081).toContain("ON DELETE RESTRICT");
    expect(MIGRATION_081).toContain("trg_ueka_no_update");
    expect(MIGRATION_081).toContain("trg_ueka_no_delete");
    expect(MIGRATION_081).toContain("ORG_AUDIT_IMMUTABLE");
    expect(AUDIT_WRITER).toContain(
      "public const USER_ACCESS_REVOKE_TABLE = 'user_erisim_kaldirma_auditleri';"
    );
  });
});
